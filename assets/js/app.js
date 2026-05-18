(() => {
  const configNode = document.getElementById('app-config');
  if (!configNode) return;

  const config = JSON.parse(configNode.textContent);
  const state = {
    data: null,
    charts: new Map(),
    search: '',
    activeTab: 'details',
    fleetExpanded: true,
    sidebarCollapsed: localStorage.getItem('vmange-sidebar') === 'collapsed',
    expandedVmKey: '',
    liveHistory: new Map(),
  };

  const root = document.getElementById('view-root');
  const title = document.getElementById('page-title');
  const search = document.getElementById('global-search');
  const modal = document.getElementById('confirm-modal');
  const enrollModal = document.getElementById('enroll-modal');
  const actionModal = document.getElementById('action-modal');
  const toastRegion = document.getElementById('toast-region');
  const shell = document.querySelector('.app-shell');
  const helpButton = document.getElementById('help-button');

  const palette = {
    blue: '#0b89e8',
    green: '#25a85a',
    amber: '#ffb020',
    orange: '#ff7a1a',
    red: '#e83e24',
    teal: '#00a99d',
  };

  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
  })[char]);

  const pct = (used, total) => {
    const n = Number(total) > 0 ? (Number(used) / Number(total)) * 100 : 0;
    return Math.max(0, Math.min(100, Math.round(n)));
  };

  const bytes = (value) => {
    const num = Number(value || 0);
    if (num >= 1024 ** 3) return `${(num / 1024 ** 3).toFixed(1)} GB`;
    if (num >= 1024 ** 2) return `${(num / 1024 ** 2).toFixed(1)} MB`;
    if (num >= 1024) return `${(num / 1024).toFixed(1)} KB`;
    return `${num} B`;
  };

  const mem = (value) => `${Number(value || 0).toLocaleString()} MB`;
  const uptime = (seconds) => {
    const total = Number(seconds || 0);
    if (total <= 0) return '-';
    const days = Math.floor(total / 86400);
    const hours = Math.floor((total % 86400) / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    if (days > 0) return `${days}d ${hours}h`;
    if (hours > 0) return `${hours}h ${minutes}m`;
    return `${minutes}m`;
  };

  function normalizeContainer(container = {}) {
    const raw = container.raw || {};
    const name = container.name || container.names || container.Names || raw.name || raw.names || raw.Names || container.id || container.ID || raw.id || raw.ID || '-';
    const image = container.image || container.Image || raw.image || raw.Image || '-';
    const statusText = container.status || container.Status || raw.status || raw.Status || '';
    const ports = container.ports || container.Ports || raw.ports || raw.Ports || '-';
    let stateValue = String(container.state || container.State || raw.state || raw.State || '').toLowerCase();
    if (!stateValue) {
      const lowered = String(statusText).toLowerCase();
      if (lowered.includes('paused')) stateValue = 'paused';
      else if (lowered.includes('up')) stateValue = 'running';
      else if (lowered.includes('restart')) stateValue = 'restarting';
      else if (lowered) stateValue = 'stopped';
      else stateValue = 'unknown';
    }
    return {
      ...container,
      id: container.id || container.ID || raw.id || raw.ID || '',
      name,
      image,
      status: statusText || stateValue,
      state: stateValue,
      ports,
    };
  }

  function normalizeImage(image = {}) {
    const raw = image.raw || {};
    return {
      ...image,
      repository: image.repository || image.Repository || raw.repository || raw.Repository || '-',
      tag: image.tag || image.Tag || raw.tag || raw.Tag || '-',
      id: image.id || image.ID || image.ImageID || raw.id || raw.ID || raw.ImageID || '',
      size: image.size || image.Size || raw.size || raw.Size || '-',
    };
  }

  function parseRunningSample(sample) {
    const result = { names: new Set(), uuids: new Set() };
    const text = String(sample || '');
    text.split(/\r?\n/).forEach((line) => {
      const nameMatch = line.match(/"([^"]+)"/);
      if (nameMatch?.[1]) result.names.add(nameMatch[1]);
      const uuidMatch = line.match(/\{([^}]+)\}/);
      if (uuidMatch?.[1] && uuidMatch[1] !== 'metrics-json') result.uuids.add(uuidMatch[1].toLowerCase());
    });
    return result;
  }

  function hostRuntimeMaps(host) {
    const metrics = host?.metrics || {};
    const debug = host?.agent_debug || {};
    const names = new Set();
    const uuids = new Set();

    (Array.isArray(metrics.running_vm_names) ? metrics.running_vm_names : []).forEach((name) => {
      if (name) names.add(String(name));
    });
    (Array.isArray(metrics.running_vm_uuids) ? metrics.running_vm_uuids : []).forEach((uuid) => {
      if (uuid) uuids.add(String(uuid).toLowerCase());
    });

    const parsed = parseRunningSample(debug.running_vms_sample);
    parsed.names.forEach((name) => names.add(name));
    parsed.uuids.forEach((uuid) => uuids.add(uuid));

    return { names, uuids };
  }

  function vmRuntimeInfo(host, vm) {
    const status = String(vm.status || vm.state || 'unknown').toLowerCase();
    const uuid = String(vm.uuid || '').toLowerCase();
    const runtime = hostRuntimeMaps(host);

    if (status === 'paused') {
      return { status: 'paused', source: vm.runtime_source || 'server' };
    }
    if (vm.running === true || status === 'running') {
      return { status: 'running', source: vm.runtime_source || 'server' };
    }
    if (vm.name && runtime.names.has(vm.name)) {
      return { status: 'running', source: 'client_running_vm_names' };
    }
    if (uuid && runtime.uuids.has(uuid)) {
      return { status: 'running', source: 'client_running_vm_uuids' };
    }
    return { status: status === 'poweroff' ? 'stopped' : status, source: vm.runtime_source || 'server' };
  }

  function resolvedSummary() {
    const hosts = state.data?.hosts || [];
    let vms = 0;
    let running = 0;
    let containers = 0;
    let online = 0;
    let alerts = 0;
    hosts.forEach((host) => {
      if (host.online) online += 1;
      const metrics = host.metrics || {};
      const hostAlert = !host.online
        || Number(metrics.cpu || 0) >= 90
        || (Number(metrics.disk_total_mb || 0) > 0 && (Number(metrics.disk_used_mb || 0) / Number(metrics.disk_total_mb || 1)) >= 0.9);
      if (hostAlert) alerts += 1;
      (host.vms || []).forEach((vm) => {
        vms += 1;
        if (vmRuntimeInfo(host, vm).status === 'running') running += 1;
      });
      containers += (host.containers || []).length;
    });
    alerts = Number(state.data?.alarms?.active ?? alerts);
    return { hosts: hosts.length, online, vms, running, containers, alerts };
  }

  function toast(message, type = 'info') {
    const node = document.createElement('div');
    node.className = `toast ${type}`;
    node.textContent = message;
    toastRegion.appendChild(node);
    setTimeout(() => node.remove(), 4200);
  }

  function setTheme(theme) {
    document.documentElement.dataset.theme = theme;
    localStorage.setItem('vmange-theme', theme);
  }

  function currentView() {
    const hash = (location.hash || '#overview').slice(1);
    if (hash.startsWith('host/')) return { name: 'host', host: decodeURIComponent(hash.slice(5)) };
    if (hash.startsWith('docs/')) return { name: 'docs', doc: decodeURIComponent(hash.slice(5)) };
    return { name: hash || 'overview' };
  }

  function filteredHosts() {
    const term = state.search.trim().toLowerCase();
    const hosts = state.data?.hosts || [];
    if (!term) return hosts;
    return hosts.filter((host) => {
      const haystack = [
        host.hostname,
        ...host.vms.map((vm) => `${vm.name} ${vm.state} ${vm.os}`),
        ...host.containers.map((container) => {
          const item = normalizeContainer(container);
          return `${item.name || ''} ${item.image || ''} ${item.status || ''} ${item.ports || ''}`;
        }),
      ].join(' ').toLowerCase();
      return haystack.includes(term);
    });
  }

  async function fetchDashboard(showLoading = false) {
    if (showLoading) root.innerHTML = '<div class="loading-panel">Loading dashboard...</div>';
    const response = await fetch('index.php?ajax=dashboard', { headers: { Accept: 'application/json' } });
    const payload = await response.json();
    if (!payload.ok) throw new Error(payload.error || 'Unable to load dashboard');
    recordLiveHistory(payload);
    state.data = payload;
    Object.assign(config, {
      csrf: payload.csrf,
      gatewayUrl: payload.gatewayUrl || config.gatewayUrl || '',
      terminalGatewayEnabled: Boolean(payload.terminalGatewayEnabled),
      terminalGatewayUrl: payload.terminalGatewayUrl || config.terminalGatewayUrl || '',
      docsEnabled: Boolean(payload.docsEnabled),
    });
    const alarmLink = document.getElementById('alarm-link');
    if (alarmLink) {
      const unread = Number(payload.alarms?.unread || 0);
      alarmLink.dataset.count = unread > 0 ? String(unread) : '';
      alarmLink.classList.toggle('has-alerts', unread > 0);
      alarmLink.title = unread > 0 ? `${unread} active alarm${unread === 1 ? '' : 's'}` : 'Open alarms';
    }
    render();
  }

  function postAction(endpoint, body) {
    const form = new FormData();
    Object.entries(body).forEach(([key, value]) => form.append(key, value ?? ''));
    form.append('csrf', config.csrf);
    return fetch(`index.php?ajax=${endpoint}`, { method: 'POST', body: form }).then((res) => res.json());
  }

  function confirmDanger(copy) {
    if (!modal?.showModal) return Promise.resolve(window.confirm(copy));
    document.getElementById('confirm-copy').textContent = copy;
    modal.showModal();
    return new Promise((resolve) => {
      modal.addEventListener('close', () => resolve(modal.returnValue === 'confirm'), { once: true });
    });
  }

  async function queueCommand(hostname, actionName, target, payload = '') {
    const dangerous = [
      'poweroff', 'reset', 'snapshot_restore', 'snapshot_delete', 'vm_delete',
      'vm_set_resources', 'vm_set_boot_order', 'vm_set_description', 'vm_set_autostart',
      'vm_attach_iso', 'vm_detach_iso', 'vm_attach_disk', 'vm_create_disk', 'vm_resize_disk',
      'vm_set_network', 'vm_cable_connected', 'vm_export', 'vm_import',
      'vm_create', 'vm_disable_vrde',
      'container_kill', 'container_remove', 'image_remove', 'compose_down', 'compose_deploy', 'dockerfile_deploy',
      'host_install_virtualbox', 'host_install_docker', 'agent_restart', 'host_reboot', 'script_run', 'terminal_exec', 'agent_uninstall',
    ].includes(actionName);
    let confirmed = false;
    if (dangerous) {
      confirmed = await confirmDanger(`Queue ${actionName.replaceAll('_', ' ')} for ${target} on ${hostname}?`);
      if (!confirmed) return;
    }
    if (actionName === 'host_reboot') {
      const typed = window.prompt(`Type ${hostname} to confirm reboot`);
      if (typed !== hostname) {
        toast('Hostname confirmation did not match. Reboot was not queued.', 'error');
        return;
      }
    }

    const result = await postAction('command', {
      hostname,
      action_name: actionName,
      target,
      payload,
      confirm: dangerous ? 'true' : 'false',
    });
    if (!result.ok) {
      toast(result.error || 'Action failed', 'error');
      return;
    }
    const message = actionName === 'agent_upgrade'
      ? 'Agent upgrade queued. The host will install it on the next heartbeat.'
      : (result.message || 'Action queued');
    toast(message, 'success');
    if (actionName === 'vm_enable_vrde') {
      try {
        const data = payload ? JSON.parse(payload) : {};
        const port = Number(data.port || 3389);
        const host = (state.data.hosts || []).find((item) => item.hostname === hostname);
        const ip = Array.isArray(host?.metrics?.ips) && host.metrics.ips.length
          ? String(host.metrics.ips[0]).split(':').slice(1).join(':')
          : hostname;
        toast(`VRDE console requested. Connect with RDP to ${ip}:${port}`, 'info');
      } catch {
      }
    }
    await fetchDashboard();
  }

  function wireDialogDismiss(dialog, { backdrop = true } = {}) {
    if (!dialog) return;
    dialog.querySelectorAll('[data-dialog-close]').forEach((button) => {
      button.addEventListener('click', () => dialog.close('cancel'));
    });
    if (backdrop) {
      dialog.addEventListener('click', (event) => {
        const rect = dialog.getBoundingClientRect();
        const inside = event.clientX >= rect.left && event.clientX <= rect.right && event.clientY >= rect.top && event.clientY <= rect.bottom;
        if (!inside) dialog.close('cancel');
      });
    }
  }

  function commandStatusMessage(row) {
    const status = String(row?.status || '').toLowerCase();
    const exitCode = row?.exit_code !== null && row?.exit_code !== undefined && row?.exit_code !== '' ? `Exit ${row.exit_code}. ` : '';
    const diagnostics = typeof row?.diagnostics_json === 'string' && row.diagnostics_json.trim() !== '' ? row.diagnostics_json : '';
    if (status === 'done') return row.result || row.stdout || `${exitCode}Action completed successfully.`;
    if (status === 'failed') return `${exitCode}${row.stderr || row.result || row.stdout || diagnostics || 'Action failed. No extra output was returned.'}`;
    if (status === 'running') return 'The agent has claimed this action and is still working on it.';
    if (status === 'pending') return 'Queued and waiting for the next host heartbeat.';
    if (status === 'sent') return 'Sent to the agent and waiting for completion.';
    if (status === 'expired') return 'The action aged out before a final result was received.';
    return row.result || 'No additional status detail is available.';
  }

  function statusBadge(status, message = '') {
    const cleanStatus = String(status || 'unknown');
    const detail = message || cleanStatus;
    return `<span class="badge status-help ${esc(cleanStatus)}" tabindex="0" title="${esc(detail)}" data-status-detail="${esc(detail)}">${esc(cleanStatus)}<button class="status-info" type="button" aria-label="Status detail" title="${esc(detail)}" data-status-detail="${esc(detail)}">i</button></span>`;
  }

  function metricPoint(host, label = '') {
    const metrics = host.metrics || {};
    return {
      time: label || metrics.created_at || host.last_seen || new Date().toLocaleTimeString(),
      cpu: Number(metrics.cpu || 0),
      load1: Number(metrics.load1 || 0),
      ram: pct(metrics.ram_used_mb, metrics.ram_total_mb),
      swap: pct(metrics.swap_used_mb, metrics.swap_total_mb),
      rx: Number(metrics.rx_bytes || 0),
      tx: Number(metrics.tx_bytes || 0),
    };
  }

  function recordLiveHistory(payload) {
    (payload.hosts || []).forEach((host) => {
      const point = metricPoint(host);
      const key = host.hostname;
      const rows = state.liveHistory.get(key) || [];
      const last = rows[rows.length - 1];
      const signature = [point.time, point.cpu, point.load1, point.ram, point.swap, point.rx, point.tx].join('|');
      if (!last || last.signature !== signature) {
        rows.push({ ...point, signature });
        if (rows.length > 80) rows.splice(0, rows.length - 80);
        state.liveHistory.set(key, rows);
      }
    });
  }

  function graphHistory(host) {
    const serverRows = Array.isArray(host.history) ? host.history : [];
    if (serverRows.length >= 2) return serverRows;
    const rows = state.liveHistory.get(host.hostname) || [];
    if (rows.length >= 2) return rows;
    const current = metricPoint(host, 'now');
    return [
      { ...current, time: '-60s' },
      current,
    ];
  }

  function metricCards() {
    const summary = resolvedSummary();
    return `
      <section class="metric-grid">
        ${card('Hosts', `${summary.online}/${summary.hosts}`, 'Online hosts')}
        ${card('Virtual machines', summary.vms, `${summary.running} running`)}
        ${card('Containers', summary.containers, 'Reported by agents')}
        ${card('Alerts', summary.alerts, 'Offline or saturated resources')}
      </section>`;
  }

  function card(label, value, hint) {
    return `<article class="metric-card"><span>${esc(label)}</span><strong>${esc(value)}</strong><span>${esc(hint)}</span></article>`;
  }

  function gauge(label, value, color = palette.blue) {
    const clean = Math.max(0, Math.min(100, Math.round(Number(value || 0))));
    return `
      <div>
        <div class="gauge" style="--value:${clean};--gauge-color:${color}"><strong>${clean}%</strong></div>
        <div class="gauge-label">${esc(label)}</div>
      </div>`;
  }

  function meter(label, used, total) {
    const value = pct(used, total);
    return `
      <div>
        <div class="meter"><span style="--value:${value}"></span></div>
        <div class="meter-label">${esc(label)} / ${mem(used)} / ${mem(total)}</div>
      </div>`;
  }

  function hostCard(host, showMissingCapabilities = false) {
    const metrics = host.metrics || {};
    const caps = host.capabilities || {};
    const ramPct = pct(metrics.ram_used_mb, metrics.ram_total_mb);
    const diskPct = pct(metrics.disk_used_mb, metrics.disk_total_mb);
    const ips = Array.isArray(metrics.ips) ? metrics.ips : [];
    return `
      <article class="panel host-card" data-open-host="${esc(host.hostname)}">
        <div class="host-title">
          <div>
            <h3>${esc(host.hostname)}</h3>
            <p class="muted">Last seen ${esc(host.last_seen || 'never')}</p>
            <p class="muted">Uptime ${esc(uptime(caps.uptime_seconds || metrics.uptime_seconds))}</p>
            ${ips.length ? `<p class="muted">IPs ${ips.map(esc).join(', ')}</p>` : ''}
          </div>
          <div class="actions">
            <span class="badge ${host.online ? 'online' : 'offline'}">${host.online ? 'Online' : 'Offline'}</span>
            ${caps.has_virtualbox || showMissingCapabilities ? `<span class="badge ${caps.has_virtualbox ? 'online' : 'offline'}" title="VirtualBox">${caps.has_virtualbox ? 'VBox' : 'No VBox'}</span>` : ''}
            ${caps.has_docker || showMissingCapabilities ? `<span class="badge ${caps.has_docker ? 'online' : 'offline'}" title="Docker">${caps.has_docker ? 'Docker' : 'No Docker'}</span>` : ''}
            ${caps.has_compose || showMissingCapabilities ? `<span class="badge ${caps.has_compose ? 'online' : 'pending'}" title="Compose">${caps.has_compose ? 'Compose' : 'No Compose'}</span>` : ''}
            ${state.data.role === 'admin' ? `<button class="btn ghost" type="button" data-delete-host="${esc(host.hostname)}">Delete</button>` : ''}
          </div>
        </div>
        <div class="gauge-row">
          ${gauge('CPU', metrics.cpu || 0, palette.blue)}
          ${gauge('Load', Math.min(100, Number(metrics.load1 || 0) * 25), palette.amber)}
          ${gauge('Disk', diskPct, diskPct > 88 ? palette.red : palette.green)}
        </div>
        <div class="meter-grid">
          ${meter('RAM', metrics.ram_used_mb, metrics.ram_total_mb)}
          ${meter('Swap', metrics.swap_used_mb, metrics.swap_total_mb)}
          ${meter('Disk', metrics.disk_used_mb, metrics.disk_total_mb)}
        </div>
        <p class="muted">${host.vms.length} VMs / ${host.containers.length} containers</p>
      </article>`;
  }

  function renderOverview() {
    title.textContent = 'Overview';
    const hosts = filteredHosts();
    const hasFleetMetrics = hosts.some((host) => Number(host.metrics?.ram_total_mb || 0) > 0 || Number(host.metrics?.cpu || 0) > 0);
    root.innerHTML = `
      ${metricCards()}
      <section class="dashboard-grid">
        <div class="panel">
          <div class="panel-head">
            <h2>Host health overview</h2>
            <div class="actions">
              <button class="btn primary" data-add-host>Add new host</button>
              <span class="badge">${hosts.length} hosts</span>
            </div>
          </div>
          <div class="host-grid">${hosts.map((host) => hostCard(host, false)).join('') || empty('No hosts match the current search.')}</div>
        </div>
        <div class="panel fleet-panel ${state.fleetExpanded ? 'expanded' : 'collapsed'}">
          <div class="panel-head">
            <h2>Fleet CPU / RAM</h2>
            <div class="actions">
              <button class="btn ghost" id="fleet-toggle">${state.fleetExpanded ? 'Collapse' : 'Expand'}</button>
              <span class="badge">${hasFleetMetrics ? 'Live' : 'Waiting for metrics'}</span>
            </div>
          </div>
          ${state.fleetExpanded ? `<div class="chart-frame">${hasFleetMetrics ? '<canvas id="fleet-chart" height="260"></canvas>' : empty('No CPU/RAM metrics yet. Add a host and wait for the first heartbeat.')}</div>` : ''}
        </div>
      </section>
      ${commandsPanel(10, true)}`;
    bindOpenHost();
    bindAddHost();
    bindDeleteHosts();
    document.getElementById('fleet-toggle')?.addEventListener('click', () => {
      state.fleetExpanded = !state.fleetExpanded;
      renderOverview();
    });
    if (state.fleetExpanded && hasFleetMetrics) drawFleetChart(hosts);
    bindStatusInfo();
  }

  function renderHosts() {
    title.textContent = 'Hosts';
    const hosts = filteredHosts();
    root.innerHTML = `
      ${metricCards()}
      <section class="panel">
        <div class="panel-head">
          <h2>Managed hosts</h2>
          <div class="actions">
            <input data-page-search type="search" value="${esc(state.search)}" placeholder="Search hosts" aria-label="Search hosts">
            <button class="btn primary" data-add-host>Add new host</button>
          </div>
        </div>
      </section>
      <section class="host-grid">${hosts.map((host) => hostCard(host, true)).join('') || empty('No hosts available yet.')}</section>`;
    bindOpenHost();
    bindAddHost();
    bindDeleteHosts();
    bindPageSearch();
  }

  function vmActions(host, vm) {
    if (!state.data.canManage) return '';
    const status = vmRuntimeStatus(host, vm);
    const identity = vmIdentity(vm);
    const primary = status === 'running'
      ? ['stop', 'Stop', 'warn']
      : status === 'paused'
        ? ['resume', 'Resume', 'primary']
        : ['start', 'Start', 'success'];
    const primaryButton = buttonHtml(host.hostname, vm.name, primary[0], primary[1], primary[2], identity);
    return `<div class="vm-action-row">
      ${primaryButton}
      <button class="btn ghost" data-vm-expand="${esc(vm.name)}" data-host="${esc(host.hostname)}">${state.expandedVmKey === `${host.hostname}:${vm.name}` ? 'Hide details' : 'Details'}</button>
    </div>`;
  }

  function vmActionGroups(host, vm) {
    const status = vmRuntimeStatus(host, vm);
    const identity = vmIdentity(vm);
    return [
      ['Power', [
        status === 'running' ? ['pause', 'Pause', 'warn'] : null,
        status === 'paused' ? ['resume', 'Resume', 'primary'] : null,
        ['restart', 'Restart', 'primary'],
        ['reset', 'Reset', 'danger'],
        ['poweroff', 'Force poweroff', 'danger'],
        ['refresh_inventory', 'Refresh inventory', 'ghost'],
      ]],
      ['Snapshots', [
        ['snapshot_create', 'Create snapshot', 'primary', { ...identity, name: `vmange-${new Date().toISOString().slice(0, 19).replaceAll(':', '')}` }],
        ['snapshot_restore', 'Restore snapshot', 'ghost', { ...identity, snapshot: '' }],
        ['snapshot_delete', 'Delete snapshot', 'danger', { ...identity, snapshot: '' }],
      ]],
      ['Media', [
        ['vm_attach_iso', 'Attach ISO', 'ghost', { ...identity, controller: vm.storage?.[0]?.name || 'IDE', port: 1, device: 0, path: '/path/to/installer.iso' }],
        ['vm_detach_iso', 'Detach ISO', 'ghost', { ...identity, controller: vm.storage?.[0]?.name || 'IDE', port: 1, device: 0 }],
      ]],
      ['Network', [
        ['vm_set_network', 'Edit adapter', 'ghost', { ...identity, adapter: 1, mode: 'nat' }],
        ['vm_cable_connected', 'Cable state', 'ghost', { ...identity, adapter: 1, connected: true }],
      ]],
      ['Settings', [
        ['vm_set_resources', 'CPU / RAM', 'ghost', { ...identity, cpu: vm.cpu || 1, ram_mb: vm.ram_mb || vm.memory || 1024, vram_mb: vm.vram_mb || vm.vram || 16 }],
        ['vm_set_boot_order', 'Boot order', 'ghost', { ...identity, boot1: vm.boot_order?.[0] || 'dvd', boot2: vm.boot_order?.[1] || 'disk', boot3: vm.boot_order?.[2] || 'none', boot4: vm.boot_order?.[3] || 'none' }],
        ['vm_set_description', 'Description', 'ghost', { ...identity, description: vm.description || '' }],
        ['vm_set_autostart', 'Autostart', 'ghost', { ...identity, enabled: String(vm.autostart || '').toLowerCase() === 'on' }],
      ]],
      ['Console', [
        ['console_guide', 'Open console guide', 'ghost'],
        ['vm_enable_vrde', 'Enable VRDE', 'primary', { ...identity, port: Number(vm.vrde_port || 3389) || 3389 }],
        ['vm_screenshot', 'Capture screenshot', 'ghost', { ...identity, path: `/var/lib/vmange/screenshots/${vm.name}.png` }],
      ]],
      ['Export / Danger', [
        ['vm_clone', 'Clone', 'ghost', { ...identity, name: `${vm.name}-clone` }],
        ['vm_export', 'Export OVA', 'ghost', { ...identity, path: `/var/lib/vmange/exports/${vm.name}.ova` }],
        ['vm_delete', 'Delete VM', 'danger', { ...identity, delete_files: true }],
      ]],
    ];
  }

  function vmIdentity(vm) {
    return { vm_uuid: vm.uuid || '', vm_name: vm.name || '' };
  }

  function vmRuntimeStatus(host, vm) {
    return vmRuntimeInfo(host, vm).status;
  }

  function buttons(hostname, target, items) {
    return `<div class="actions">${items.map(([action, label, tone, payload]) => `<button class="btn ${tone}" data-command="${esc(action)}" data-host="${esc(hostname)}" data-target="${esc(target)}" ${payload ? `data-payload="${esc(JSON.stringify(payload))}"` : ''}>${esc(label)}</button>`).join('')}</div>`;
  }

  function buttonHtml(hostname, target, action, label, tone = 'ghost', payload = null) {
    if (action === 'console_guide') {
      return `<button class="btn ${esc(tone)}" data-console-guide="${esc(target)}" data-host="${esc(hostname)}">${esc(label)}</button>`;
    }
    return `<button class="btn ${esc(tone)}" data-command="${esc(action)}" data-host="${esc(hostname)}" data-target="${esc(target)}" ${payload ? `data-payload="${esc(JSON.stringify(payload))}"` : ''}>${esc(label)}</button>`;
  }

  function vmActionSections(host, vm) {
    return vmActionGroups(host, vm).map(([label, items]) => {
      const visibleItems = items.filter(Boolean);
      if (!visibleItems.length) return '';
      return `<section class="vm-action-section"><h3>${esc(label)}</h3><div class="actions">${visibleItems.map(([action, itemLabel, tone, payload]) => buttonHtml(host.hostname, vm.name, action, itemLabel, tone, payload || vmIdentity(vm))).join('')}</div></section>`;
    }).join('');
  }

  function renderVMs(hostFilter = null) {
    title.textContent = hostFilter ? `${hostFilter.hostname} virtual machines` : 'Virtual Machines';
    const hosts = hostFilter ? [hostFilter] : filteredHosts();
    const rows = hosts.flatMap((host) => host.vms.map((vm) => ({ host, vm })));
    root.innerHTML = `
      <section class="table-panel">
        <div class="table-head">
          <h2>Resource list</h2>
          <div class="actions">
            <input data-page-search type="search" value="${esc(state.search)}" placeholder="Search VMs" aria-label="Search VMs">
            <button class="icon-btn" type="button" data-page-refresh title="Refresh Virtual Machines" aria-label="Refresh Virtual Machines">&#8635;</button>
            ${state.data.canManage ? `<button class="btn primary" data-command="vm_create" data-host="${esc(hosts[0]?.hostname || '')}" data-target="new-vm" data-payload="${esc(JSON.stringify({ __host: hosts[0]?.hostname || '', name: 'new-vm', ostype: 'Ubuntu_64', cpu: 2, ram_mb: 2048, vram_mb: 32, disk_size_mb: 20480, disk_path: '', controller: 'SATA', iso_path: '', network_mode: 'nat', start: false, unattended: false, hostname: 'vm-new', username: 'admin', password: '', full_name: 'VM Admin', ssh_key: '', timezone: 'UTC', locale: 'en_US' }))}">Create VM</button>` : ''}
            <span class="badge">${rows.length} VMs</span>
          </div>
        </div>
        <div class="table-wrap">
          <table class="responsive-table vm-table">
            <thead><tr><th>VM</th><th>Host</th><th>Status</th><th>OS</th><th>CPU</th><th>RAM</th><th>VRAM</th><th>Actions</th></tr></thead>
            <tbody>
              ${rows.map(({ host, vm }) => vmRows(host, vm)).join('') || tableEmpty(8)}
            </tbody>
          </table>
        </div>
      </section>`;
    bindCommands();
    bindPageSearch();
    bindPageRefresh();
  }

  function vmRows(host, vm) {
    const key = `${host.hostname}:${vm.name}`;
    const runtime = vmRuntimeInfo(host, vm);
    const expanded = state.expandedVmKey === key;
    return `
      <tr class="vm-summary-row ${expanded ? 'expanded' : ''}">
        <td data-label="VM">${esc(vm.name)}</td>
        <td data-label="Host">${esc(host.hostname)}</td>
        <td data-label="Status">${statusBadge(vmRuntimeStatus(host, vm), runtime.source)}</td>
        <td data-label="OS">${esc(vm.os)}</td>
        <td data-label="CPU">${esc(vm.cpu)}</td>
        <td data-label="RAM">${mem(vm.memory)}</td>
        <td data-label="VRAM">${mem(vm.vram)}</td>
        <td data-label="Actions">${vmActions(host, vm)}</td>
      </tr>
      ${expanded ? `<tr class="vm-detail-row"><td colspan="8">${vmDetailPanel(host, vm)}</td></tr>` : ''}`;
  }

  function vmDetailPanel(host, vm) {
    const status = vmRuntimeInfo(host, vm);
    const consoleHost = Array.isArray(host.metrics?.ips) && host.metrics.ips.length ? String(host.metrics.ips[0]).split(':').pop() : host.hostname;
    return `
      <div class="vm-inline-detail">
        <section><h3>Summary</h3><dl><dt>UUID</dt><dd>${esc(vm.uuid || '-')}</dd><dt>Status</dt><dd>${esc(status.status)} via ${esc(status.source)}</dd><dt>OS</dt><dd>${esc(vm.os || '-')}</dd><dt>Session</dt><dd>${esc(vm.session_state || '-')}</dd></dl></section>
        <section><h3>Snapshots</h3>${(vm.snapshots || []).map((snap) => `<p>${esc(snap.name || '-')} <span class="muted">${esc(snap.uuid || '')}</span></p>`).join('') || '<p class="muted">No snapshots reported.</p>'}</section>
        <section><h3>Storage</h3>${(vm.storage || []).map((item) => `<p>${esc(item.name || '-')} ${esc(item.type || '')} ${esc(item.path || '')}</p>`).join('') || '<p class="muted">No storage details reported.</p>'}</section>
        <section><h3>Network</h3>${(vm.nics || []).map((nic) => `<p>Adapter ${esc(nic.adapter)}: ${esc(nic.mode || '-')} ${esc(nic.bridge || '')}</p>`).join('') || '<p class="muted">No network adapters reported.</p>'}</section>
        <section><h3>Settings</h3><div class="actions">${buttonHtml(host.hostname, vm.name, 'vm_set_resources', 'CPU / RAM', 'ghost', { ...vmIdentity(vm), cpu: vm.cpu || 1, ram_mb: vm.ram_mb || vm.memory || 1024, vram_mb: vm.vram_mb || vm.vram || 16 })}${buttonHtml(host.hostname, vm.name, 'vm_set_boot_order', 'Boot order', 'ghost', { ...vmIdentity(vm), boot1: vm.boot_order?.[0] || 'dvd', boot2: vm.boot_order?.[1] || 'disk', boot3: vm.boot_order?.[2] || 'none', boot4: vm.boot_order?.[3] || 'none' })}</div></section>
        <section><h3>Console</h3><p class="muted">VRDE ${esc(String(vm.vrde_enabled || 'off'))} on ${esc(consoleHost)}:${esc(vm.vrde_port || 3389)}</p><div class="actions"><button class="btn ghost" data-console-guide="${esc(vm.name)}" data-host="${esc(host.hostname)}">Open console guide</button>${buttonHtml(host.hostname, vm.name, 'vm_screenshot', 'Capture screenshot', 'ghost', { ...vmIdentity(vm), path: `/var/lib/vmange/screenshots/${vm.name}.png` })}</div></section>
        <section><h3>Logs</h3><div class="actions">${buttonHtml(host.hostname, vm.name, 'vm_logs_list', 'List logs', 'ghost', vmIdentity(vm))}${buttonHtml(host.hostname, vm.name, 'vm_log_tail', 'Tail VBox.log', 'ghost', { ...vmIdentity(vm), file: 'VBox.log', lines: 200 })}</div></section>
        ${vmActionSections(host, vm)}
      </div>`;
  }

  function containerActions(host, container) {
    if (!state.data.canManage) return '';
    const item = normalizeContainer(container);
    const name = item.name || item.id || '';
    const running = item.state === 'running' || String(item.status || '').toLowerCase().includes('up');
    const paused = item.state === 'paused' || String(item.status || '').toLowerCase().includes('paused');
    const items = paused
      ? [['container_unpause', 'Unpause', 'primary'], ['container_stop', 'Stop', 'warn'], ['container_kill', 'Kill', 'danger'], ['logs_tail', 'Logs', 'ghost'], ['container_remove', 'Delete', 'danger']]
      : running
        ? [['container_stop', 'Stop', 'warn'], ['container_restart', 'Restart', 'primary'], ['container_pause', 'Pause', 'ghost'], ['container_kill', 'Kill', 'danger'], ['logs_tail', 'Logs', 'ghost'], ['container_remove', 'Delete', 'danger']]
        : [['container_start', 'Start', 'success'], ['container_restart', 'Restart', 'primary'], ['logs_tail', 'Logs', 'ghost'], ['container_remove', 'Delete', 'danger']];
    return buttons(host.hostname, name, items);
  }

  function imageActions(host, image) {
    if (!state.data.canManage) return '';
    const item = normalizeImage(image);
    const target = item.repository && item.tag ? `${item.repository}:${item.tag}` : (item.id || '');
    if (!target) return '';
    return buttons(host.hostname, target, [['image_pull', 'Pull', 'ghost'], ['image_remove', 'Delete', 'danger']]);
  }

  function renderContainers(hostFilter = null) {
    title.textContent = hostFilter ? `${hostFilter.hostname} containers` : 'Containers';
    const hosts = hostFilter ? [hostFilter] : filteredHosts();
    const rows = hosts.flatMap((host) => host.containers.map((container) => ({ host, container: normalizeContainer(container) })));
    const imageRows = hosts.flatMap((host) => (host.images || []).map((image) => ({ host, image: normalizeImage(image) })));
    root.innerHTML = `
      <section class="table-panel">
        <div class="table-head">
          <h2>Container resources</h2>
          <div class="actions">
            <input data-page-search type="search" value="${esc(state.search)}" placeholder="Search containers" aria-label="Search containers">
            <button class="icon-btn" type="button" data-page-refresh title="Refresh Containers" aria-label="Refresh Containers">&#8635;</button>
            <span class="badge">${rows.length} containers</span>
          </div>
        </div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Name</th><th>Host</th><th>Image</th><th>Status</th><th>Ports</th><th>Actions</th></tr></thead>
            <tbody>
              ${rows.map(({ host, container }) => `
                <tr>
                  <td>${esc(container.name || container.id || '-')}</td>
                  <td>${esc(host.hostname)}</td>
                  <td>${esc(container.image || '-')}</td>
                  <td><span class="badge ${container.state === 'running' ? 'running' : (container.state === 'paused' ? 'warn' : 'stopped')}">${esc(container.status || container.state || 'unknown')}</span></td>
                  <td>${esc(container.ports || '-')}</td>
                  <td>${containerActions(host, container)}</td>
                </tr>`).join('') || tableEmpty(6)}
            </tbody>
          </table>
        </div>
      </section>
      <section class="table-panel">
        <div class="table-head"><h2>Docker images</h2><span class="badge">${imageRows.length} images</span></div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Repository</th><th>Tag</th><th>Host</th><th>ID</th><th>Size</th><th>Actions</th></tr></thead>
            <tbody>
              ${imageRows.map(({ host, image }) => `
                <tr>
                  <td>${esc(image.repository || '-')}</td>
                  <td>${esc(image.tag || '-')}</td>
                  <td>${esc(host.hostname)}</td>
                  <td>${esc(image.id || '-')}</td>
                  <td>${esc(image.size || '-')}</td>
                  <td>${imageActions(host, image)}</td>
                </tr>`).join('') || tableEmpty(6)}
            </tbody>
          </table>
        </div>
      </section>`;
    bindCommands();
    bindPageSearch();
    bindPageRefresh();
  }

  function renderCompose(hostFilter = null) {
    title.textContent = 'Compose';
    const hosts = hostFilter ? [hostFilter] : filteredHosts();
    const firstHost = hosts[0]?.hostname || '';
    const savedStacks = (state.data.stacks || []).filter((stack) => hosts.some((host) => host.hostname === stack.hostname));
    const projects = hosts.flatMap((host) => host.compose.map((project) => ({ host, project })));
    const liveProjects = new Map(projects.map(({ host, project }) => [`${host.hostname}:${project.name || project.project || '.'}`, project]));
    const savedKeys = new Set(savedStacks.map((stack) => `${stack.hostname}:${stack.project}`));
    const unsavedProjects = projects.filter(({ host, project }) => !savedKeys.has(`${host.hostname}:${project.name || project.project || '.'}`));
    root.innerHTML = `
      <section class="table-panel">
        <div class="table-head"><h2>Saved stacks</h2><span class="badge">${savedStacks.length} stacks</span></div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Project</th><th>Host</th><th>Status</th><th>Updated</th><th>Actions</th></tr></thead>
            <tbody>
              ${savedStacks.map((stack) => {
                const live = liveProjects.get(`${stack.hostname}:${stack.project}`) || {};
                const status = live.status || stack.status || 'saved';
                return `<tr><td>${esc(stack.project)}</td><td>${esc(stack.hostname)}</td><td><span class="badge ${String(status).includes('running') || status === 'deployed' ? 'running' : 'pending'}">${esc(status)}</span></td><td>${esc(stack.updated_at || stack.created_at || '-')}</td><td>${buttons(stack.hostname, stack.project, [['compose_up', 'Start', 'success'], ['compose_restart', 'Restart', 'primary'], ['compose_pull', 'Pull', 'ghost'], ['compose_down', 'Stop', 'danger']])}<div class="actions"><button class="btn primary" data-stack-deploy="${esc(stack.project)}" data-host="${esc(stack.hostname)}">Deploy saved</button><button class="btn ghost" data-stack-edit="${esc(stack.id)}">Modify</button><button class="btn danger" data-stack-delete="${esc(stack.project)}" data-host="${esc(stack.hostname)}">Delete</button></div></td></tr>`;
              }).join('') || tableEmpty(5)}
            </tbody>
          </table>
        </div>
      </section>
      <section class="table-panel">
        <div class="table-head"><h2>Detected Compose projects</h2><span class="badge">${unsavedProjects.length} unsaved projects</span></div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Project</th><th>Host</th><th>Status</th><th>Config files</th><th>Actions</th></tr></thead>
            <tbody>
              ${unsavedProjects.map(({ host, project }) => `
                <tr>
                  <td>${esc(project.name || '-')}</td>
                  <td>${esc(host.hostname)}</td>
                  <td><span class="badge ${String(project.status || '').includes('running') ? 'running' : 'pending'}">${esc(project.status || '-')}</span></td>
                  <td>${esc(project.configFiles || project.config_files || '-')}</td>
                  <td>${buttons(host.hostname, project.name || '.', [['compose_up', 'Up', 'success'], ['compose_restart', 'Restart', 'primary'], ['compose_pull', 'Pull', 'ghost'], ['compose_down', 'Down', 'danger']])}</td>
                </tr>`).join('') || tableEmpty(5)}
            </tbody>
          </table>
        </div>
      </section>
      <section class="editor-panel panel">
        <div class="panel-head">
          <h2>compose.yml editor</h2>
          <select id="compose-host" aria-label="Host for compose deployment">${hosts.map((host) => `<option value="${esc(host.hostname)}">${esc(host.hostname)}</option>`).join('')}</select>
        </div>
        <input id="compose-project" placeholder="Project name" value="${esc(firstHost ? 'vmange-project' : '')}" aria-label="Compose project name">
        <textarea id="compose-editor" class="code-editor" spellcheck="false">services:
  app:
    image: nginx:alpine
    ports:
      - "8080:80"
</textarea>
        <div class="actions"><button class="btn ghost" id="save-compose">Save stack</button><button class="btn primary" id="deploy-compose">Deploy compose.yml</button></div>
      </section>
      <section class="editor-panel panel">
        <div class="panel-head">
          <h2>Dockerfile editor</h2>
          <select id="dockerfile-host" aria-label="Host for Dockerfile build">${hosts.map((host) => `<option value="${esc(host.hostname)}">${esc(host.hostname)}</option>`).join('')}</select>
        </div>
        <input id="dockerfile-image" placeholder="Image name" value="vmange-custom" aria-label="Docker image name">
        <textarea id="dockerfile-editor" class="code-editor" spellcheck="false">FROM nginx:alpine
COPY . /usr/share/nginx/html
</textarea>
        <div class="actions"><button class="btn primary" id="deploy-dockerfile">Build Dockerfile</button></div>
      </section>`;
    bindCommands();
    bindStacks();
    bindPageSearch();
    document.querySelectorAll('[data-stack-edit]').forEach((button) => {
      button.addEventListener('click', () => {
        const stack = (state.data.stacks || []).find((item) => String(item.id) === button.dataset.stackEdit);
        if (!stack) return;
        document.getElementById('compose-host').value = stack.hostname;
        document.getElementById('compose-project').value = stack.project;
        document.getElementById('compose-editor').value = stack.compose_yaml || '';
        toast('Stack loaded for editing', 'success');
      });
    });
    document.getElementById('save-compose')?.addEventListener('click', async () => {
      const result = await postAction('stack-save', {
        hostname: document.getElementById('compose-host').value,
        project: document.getElementById('compose-project').value || 'compose-project',
        compose_yaml: document.getElementById('compose-editor').value,
      });
      if (!result.ok) return toast(result.error || 'Could not save stack', 'error');
      toast(result.message || 'Stack saved', 'success');
      await fetchDashboard();
    });
    document.getElementById('deploy-compose')?.addEventListener('click', () => {
      const host = document.getElementById('compose-host').value;
      const project = document.getElementById('compose-project').value || 'compose-project';
      const payload = document.getElementById('compose-editor').value;
      chooseComposeDeployMode(host, project, payload);
    });
    document.getElementById('deploy-dockerfile')?.addEventListener('click', () => {
      const host = document.getElementById('dockerfile-host').value;
      const image = document.getElementById('dockerfile-image').value || 'vmange-custom';
      const payload = document.getElementById('dockerfile-editor').value;
      queueCommand(host, 'dockerfile_deploy', image, payload);
    });
  }

  async function chooseComposeDeployMode(hostname, project, composeYaml) {
    const choice = await choiceDialog(
      'Deploy compose.yml',
      `Do you want to save ${project} for reuse before deployment?`,
      [
        ['save-deploy', 'Save and deploy', 'primary'],
        ['deploy-once', 'Deploy once', 'ghost'],
      ],
    );
    if (!choice) return;
    if (choice === 'save-deploy') {
      const saved = await postAction('stack-save', { hostname, project, compose_yaml: composeYaml });
      if (!saved.ok) return toast(saved.error || 'Could not save stack', 'error');
      const deployed = await postAction('stack-deploy', { hostname, project, confirm: 'true' });
      if (!deployed.ok) return toast(deployed.error || 'Could not deploy stack', 'error');
      toast('Stack saved and deployment queued', 'success');
      await fetchDashboard();
      return;
    }
    await queueCommand(hostname, 'compose_deploy', project, composeYaml);
  }

  function renderHost(hostname) {
    const host = state.data.hosts.find((item) => item.hostname === hostname);
    if (!host) {
      title.textContent = 'Host not found';
      root.innerHTML = empty('That host is not currently registered.');
      return;
    }
    title.textContent = host.hostname;
    const metrics = host.metrics || {};
    const latestAgent = state.data.agentVersion || config.agentVersion || '';
    const hostAgent = metrics.agent_version || '';
    const agentNeedsUpgrade = latestAgent && hostAgent && hostAgent !== latestAgent;
    const agentCommandPending = (state.data.commands || []).some((command) => (
      command.hostname === host.hostname
      && command.action === 'agent_upgrade'
      && ['pending', 'sent'].includes(command.status)
    ));
    const caps = host.capabilities || {};
    const virtualBoxControl = caps.has_virtualbox
      ? '<span class="badge online">VirtualBox installed</span>'
      : `<button class="btn ghost" type="button" data-command="host_install_virtualbox" data-host="${esc(host.hostname)}" data-target="virtualbox">Install/repair VirtualBox</button>`;
    const dockerControl = caps.has_docker
      ? '<span class="badge online">Docker installed</span>'
      : `<button class="btn ghost" type="button" data-command="host_install_docker" data-host="${esc(host.hostname)}" data-target="docker">Install/repair Docker</button>`;
    const composeControl = caps.has_compose
      ? '<span class="badge online">Compose installed</span>'
      : '<span class="badge pending">Compose missing</span>';
    const hostTools = state.data.role === 'admin' ? `
      <section class="panel host-tools-panel">
        <div class="panel-head host-detail-actions">
          <h2>Host tools</h2>
          <div class="actions">
            <span class="badge ${agentNeedsUpgrade ? 'warn' : 'online'}">Agent ${esc(hostAgent || 'unknown')}${latestAgent ? ` / latest ${esc(latestAgent)}` : ''}</span>
            <button class="btn ghost" type="button" data-command="host_refresh_inventory" data-host="${esc(host.hostname)}" data-target="host">Refresh inventory</button>
            <button class="btn ghost" type="button" data-rename-host="${esc(host.hostname)}">Rename host</button>
            <button class="btn ghost" type="button" data-command="agent_restart" data-host="${esc(host.hostname)}" data-target="vmange-agent">Restart agent</button>
            ${virtualBoxControl}
            ${dockerControl}
            ${composeControl}
            <button class="btn warn" type="button" data-command="host_reboot" data-host="${esc(host.hostname)}" data-target="host">Reboot host</button>
            <button class="btn ghost" type="button" data-wol-open="${esc(host.hostname)}">Wake-on-LAN</button>
            <button class="btn primary" type="button" data-command="agent_upgrade" data-host="${esc(host.hostname)}" data-target="vmange-agent" ${agentCommandPending ? 'disabled' : ''}>${agentCommandPending ? 'Upgrade queued' : (agentNeedsUpgrade ? 'Upgrade agent' : 'Reinstall agent')}</button>
            <button class="btn danger" type="button" data-delete-host="${esc(host.hostname)}">Delete host</button>
          </div>
        </div>
      </section>` : '';
    root.innerHTML = `
      ${metricCards()}
      ${hostTools}
      <section class="dashboard-grid">
        <div class="panel">
          <div class="panel-head"><h2>Time-series metrics</h2><span class="badge ${host.online ? 'online' : 'offline'}">${host.online ? 'Online' : 'Offline'}</span></div>
          <div class="chart-frame host-chart-frame">
            <canvas id="host-chart" height="260"></canvas>
          </div>
        </div>
        <div class="panel">
          <div class="panel-head"><h2>CPU / load gauges</h2><span class="badge">${esc(host.last_seen || 'never')}</span></div>
          <div class="gauge-row">
            ${gauge('CPU', metrics.cpu || 0, palette.blue)}
            ${gauge('Load', Math.min(100, Number(metrics.load1 || 0) * 25), palette.amber)}
            ${gauge('Disk', pct(metrics.disk_used_mb, metrics.disk_total_mb), palette.green)}
          </div>
          <div class="meter-grid">
            ${meter('RAM', metrics.ram_used_mb, metrics.ram_total_mb)}
            ${meter('Swap', metrics.swap_used_mb, metrics.swap_total_mb)}
            ${meter('Disk', metrics.disk_used_mb, metrics.disk_total_mb)}
          </div>
          <p class="muted">RX ${bytes(metrics.rx_bytes)} / TX ${bytes(metrics.tx_bytes)}</p>
        </div>
      </section>
      <section class="panel">
        <div class="tabs">
          ${['details', 'vms', 'snapshots', 'storage', 'network', 'settings', 'console', 'containers', 'images', 'terminal', 'scripts', 'logs'].map((tab) => `<button class="tab ${state.activeTab === tab ? 'active' : ''}" data-tab="${tab}">${tab}</button>`).join('')}
        </div>
        <div id="host-tab"></div>
      </section>`;
    drawHostChart(host);
    bindDeleteHosts();
    bindRenameHosts();
    bindCommands();
    bindWolButtons();
    bindHostTabs(host);
  }

  function bindHostTabs(host) {
    document.querySelectorAll('[data-tab]').forEach((button) => {
      button.addEventListener('click', () => {
        state.activeTab = button.dataset.tab;
        renderHost(host.hostname);
      });
    });
    const body = document.getElementById('host-tab');
    if (state.activeTab === 'vms') {
      body.innerHTML = `
        <div class="table-wrap">
          <table class="responsive-table vm-table">
            <thead><tr><th>VM</th><th>Status</th><th>OS</th><th>CPU</th><th>RAM</th><th>VRAM</th><th>Actions</th></tr></thead>
            <tbody>
              ${host.vms.map((vm) => vmRows(host, vm).replace('colspan="8"', 'colspan="7"').replace(`<td data-label="Host">${esc(host.hostname)}</td>`, '')).join('') || tableEmpty(7)}
            </tbody>
          </table>
        </div>`;
      bindCommands();
      return;
    }
    if (state.activeTab === 'containers') {
      const containers = host.containers.map((container) => normalizeContainer(container));
      body.innerHTML = `
        <div class="table-wrap">
          <table>
            <thead><tr><th>Name</th><th>Image</th><th>Status</th><th>Ports</th><th>ID</th><th>Actions</th></tr></thead>
            <tbody>
              ${containers.map((container) => `<tr><td>${esc(container.name || container.id || '-')}</td><td>${esc(container.image || '-')}</td><td><span class="badge ${container.state === 'running' ? 'running' : (container.state === 'paused' ? 'warn' : 'stopped')}">${esc(container.status || container.state || 'unknown')}</span></td><td>${esc(container.ports || '-')}</td><td>${esc(container.id || '-')}</td><td>${containerActions(host, container)}</td></tr>`).join('') || tableEmpty(6)}
            </tbody>
          </table>
        </div>`;
      bindCommands();
      return;
    }
    if (state.activeTab === 'images') {
      const images = (host.images || []).map((image) => normalizeImage(image));
      body.innerHTML = `
        <div class="table-wrap">
          <table>
            <thead><tr><th>Repository</th><th>Tag</th><th>ID</th><th>Size</th><th>Actions</th></tr></thead>
            <tbody>
              ${images.map((image) => `<tr><td>${esc(image.repository || '-')}</td><td>${esc(image.tag || '-')}</td><td>${esc(image.id || '-')}</td><td>${esc(image.size || '-')}</td><td>${imageActions(host, image)}</td></tr>`).join('') || tableEmpty(5)}
            </tbody>
          </table>
        </div>`;
      bindCommands();
      return;
    }
    if (state.activeTab === 'snapshots') {
      const rows = host.vms.flatMap((vm) => (vm.snapshots || []).map((snap) => ({ vm, snap })));
      body.innerHTML = `
        <div class="table-wrap"><table>
          <thead><tr><th>VM</th><th>Snapshot</th><th>UUID</th><th>Actions</th></tr></thead>
          <tbody>${rows.map(({ vm, snap }) => `<tr><td>${esc(vm.name)}</td><td>${esc(snap.name || '-')}</td><td>${esc(snap.uuid || '-')}</td><td>${buttons(host.hostname, vm.name, [['snapshot_restore', 'Restore', 'danger', { snapshot: snap.name || snap.uuid }], ['snapshot_delete', 'Delete', 'danger', { snapshot: snap.name || snap.uuid }]])}</td></tr>`).join('') || tableEmpty(4)}</tbody>
        </table></div>`;
      bindCommands();
      return;
    }
    if (state.activeTab === 'storage') {
      const rows = host.vms.flatMap((vm) => (vm.storage || []).map((disk) => ({ vm, disk })));
      body.innerHTML = `
        <div class="table-wrap"><table>
          <thead><tr><th>VM</th><th>Controller</th><th>Type</th><th>Path</th><th>Actions</th></tr></thead>
          <tbody>${rows.map(({ vm, disk }) => `<tr><td>${esc(vm.name)}</td><td>${esc(disk.name || '-')}</td><td>${esc(disk.type || '-')}</td><td>${esc(disk.path || '-')}</td><td>${buttons(host.hostname, vm.name, [['vm_attach_iso', 'Attach ISO', 'ghost', { controller: disk.name || 'IDE', port: Number(disk.port || 1), device: Number(disk.device || 0), path: '/path/to/installer.iso' }], ['vm_detach_iso', 'Detach ISO', 'danger', { controller: disk.name || 'IDE', port: Number(disk.port || 1), device: Number(disk.device || 0) }]])}</td></tr>`).join('') || tableEmpty(5)}</tbody>
        </table></div>`;
      bindCommands();
      return;
    }
    if (state.activeTab === 'network') {
      const rows = host.vms.flatMap((vm) => (vm.nics || []).map((nic) => ({ vm, nic })));
      body.innerHTML = `
        <div class="table-wrap"><table>
          <thead><tr><th>VM</th><th>Adapter</th><th>Mode</th><th>Cable</th><th>Bridge</th><th>Actions</th></tr></thead>
          <tbody>${rows.map(({ vm, nic }) => `<tr><td>${esc(vm.name)}</td><td>${esc(nic.adapter)}</td><td>${esc(nic.mode || '-')}</td><td>${esc(nic.cable || '-')}</td><td>${esc(nic.bridge || '-')}</td><td>${buttons(host.hostname, vm.name, [['vm_set_network', 'Edit', 'ghost', { adapter: Number(nic.adapter || 1), mode: nic.mode || 'nat', bridge: nic.bridge || '' }], ['vm_cable_connected', 'Cable', 'ghost', { adapter: Number(nic.adapter || 1), connected: nic.cable !== 'on' }]])}</td></tr>`).join('') || tableEmpty(6)}</tbody>
        </table></div>`;
      bindCommands();
      return;
    }
    if (state.activeTab === 'settings') {
      body.innerHTML = `
        <div class="table-wrap"><table>
          <thead><tr><th>VM</th><th>UUID</th><th>Boot</th><th>Description</th><th>Actions</th></tr></thead>
          <tbody>${host.vms.map((vm) => `<tr><td>${esc(vm.name)}</td><td>${esc(vm.uuid || '-')}</td><td>${esc((vm.boot_order || []).join(', ') || '-')}</td><td>${esc(vm.description || '-')}</td><td>${buttons(host.hostname, vm.name, [['vm_set_boot_order', 'Boot', 'ghost', { boot1: vm.boot_order?.[0] || 'dvd', boot2: vm.boot_order?.[1] || 'disk', boot3: vm.boot_order?.[2] || 'none', boot4: vm.boot_order?.[3] || 'none' }], ['vm_set_description', 'Description', 'ghost', { description: vm.description || '' }], ['vm_set_autostart', 'Autostart', 'ghost', { enabled: vm.autostart !== 'on' }]])}</td></tr>`).join('') || tableEmpty(5)}</tbody>
        </table></div>`;
      bindCommands();
      return;
    }
    if (state.activeTab === 'console') {
      body.innerHTML = `
        <div class="table-wrap">
          <table>
            <thead><tr><th>VM</th><th>VRDE</th><th>Port</th><th>Connect</th><th>Actions</th></tr></thead>
            <tbody>
              ${host.vms.map((vm) => {
                const vrdeOn = String(vm.vrde_enabled || '').toLowerCase();
                const isOn = vrdeOn === 'on' || vrdeOn === 'true' || vrdeOn === '1';
                const ip = Array.isArray(host.metrics?.ips) && host.metrics.ips.length ? String(host.metrics.ips[0]).split(':').slice(1).join(':') : host.hostname;
                const port = vm.vrde_port || 3389;
                const connect = isOn ? `${ip}:${port}` : '-';
                const vrdeActions = isOn
                  ? [['vm_disable_vrde', 'Disable VRDE', 'warn'], ['vm_screenshot', 'Refresh screenshot', 'ghost', { path: `/var/lib/vmange/screenshots/${vm.name}.png` }]]
                  : [['vm_enable_vrde', 'Enable VRDE', 'primary', { port: Number(port) || 3389 }], ['vm_screenshot', 'Capture screenshot', 'ghost', { path: `/var/lib/vmange/screenshots/${vm.name}.png` }]];
                return `<tr>
                  <td>${esc(vm.name)}</td>
                  <td><span class="badge ${isOn ? 'online' : 'stopped'}">${isOn ? 'enabled' : 'disabled'}</span></td>
                  <td>${esc(String(port || '-'))}</td>
                  <td>${esc(connect)}</td>
                  <td>${buttons(host.hostname, vm.name, vrdeActions)}</td>
                </tr>`;
              }).join('') || tableEmpty(5)}
            </tbody>
          </table>
        </div>
        <p class="muted">Shared hosting mode: browser streaming console is disabled. Use VRDE connection details above or capture VM screenshots.</p>`;
      bindCommands();
      return;
    }
    if (state.activeTab === 'logs') {
      const lines = state.data.commands.filter((cmd) => cmd.hostname === host.hostname).map((cmd) => `[${cmd.created_at}] ${cmd.status} ${cmd.action} ${cmd.vmname}${cmd.result ? `\n${cmd.result}` : ''}`);
      body.innerHTML = `<textarea class="log-viewer" readonly>${esc(lines.join('\n') || 'No command logs for this host yet.')}</textarea>`;
      return;
    }
    if (state.activeTab === 'terminal') {
      const terminalRows = (state.data.commands || [])
        .filter((cmd) => cmd.hostname === host.hostname && cmd.action === 'terminal_exec')
        .slice(0, 20);
      const gatewayBlock = config.terminalGatewayEnabled && config.terminalGatewayUrl
        ? `<p class="muted">WeTTY-compatible gateway is configured for real browser terminal mode. <a href="${esc(config.terminalGatewayUrl)}" target="_blank" rel="noreferrer">Open terminal gateway</a>.</p>`
        : '<p class="muted">Audited host-command terminal. Each command is queued through the agent, recorded, and returned with its exit status.</p>';
      body.innerHTML = `
        <div class="terminal-shell">
          <div class="terminal-toolbar">
            <strong>${esc(host.hostname)}</strong>
            <span class="badge">audited shell</span>
          </div>
          <div class="terminal-history" aria-live="polite">
            ${terminalRows.map((cmd) => `
              <article class="terminal-entry">
                <header><span>${esc(cmd.created_at || '-')}</span>${statusBadge(cmd.status || 'pending', commandStatusMessage(cmd))}</header>
                <pre>$ ${esc(cmd.payload || cmd.vmname || 'command')}
${esc(cmd.result || cmd.stdout || cmd.stderr || '')}</pre>
              </article>`).join('') || '<p class="muted">No terminal commands yet.</p>'}
          </div>
          <label class="terminal-input-row">
            <span>$</span>
            <input id="terminal-command" spellcheck="false" value="uptime" aria-label="Admin terminal command">
            <button class="btn primary" id="run-terminal-command">Run</button>
          </label>
          ${gatewayBlock}
        </div>`;
      document.getElementById('run-terminal-command')?.addEventListener('click', () => {
        queueCommand(host.hostname, 'terminal_exec', 'host-terminal', document.getElementById('terminal-command').value);
      });
      document.getElementById('terminal-command')?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
          event.preventDefault();
          queueCommand(host.hostname, 'terminal_exec', 'host-terminal', document.getElementById('terminal-command').value);
        }
      });
      bindStatusInfo();
      return;
    }
    if (state.activeTab === 'scripts') {
      const scripts = state.data.scripts || [];
      const runs = (state.data.scriptRuns || []).filter((run) => run.hostname === host.hostname);
      body.innerHTML = `
        <div class="table-wrap"><table>
          <thead><tr><th>Script</th><th>Description</th><th>Updated</th><th>Actions</th></tr></thead>
          <tbody>${scripts.map((script) => `<tr><td>${esc(script.name)}</td><td>${esc(script.description || '-')}</td><td>${esc(script.updated_at || '-')}</td><td><button class="btn primary" data-run-script="${esc(script.id)}" data-host="${esc(host.hostname)}">Run</button></td></tr>`).join('') || tableEmpty(4)}</tbody>
        </table></div>
        <div class="table-wrap"><table>
          <thead><tr><th>Time</th><th>Script</th><th>Status</th><th>Output</th></tr></thead>
          <tbody>${runs.map((run) => `<tr><td>${esc(run.created_at || '-')}</td><td>${esc(run.script_name || run.script_id)}</td><td>${statusBadge(run.status || 'pending', run.result || commandStatusMessage(run))}</td><td>${esc(run.result || '-')}</td></tr>`).join('') || tableEmpty(4)}</tbody>
        </table></div>`;
      bindScriptRunButtons();
      bindStatusInfo();
      return;
    }
    body.innerHTML = `
      <div class="metric-grid">
        ${card('Hostname', host.hostname, 'Agent identity')}
        ${card('Last seen', host.last_seen || 'never', 'Polling heartbeat')}
        ${card('Server IPs', Array.isArray(host.metrics?.ips) && host.metrics.ips.length ? host.metrics.ips.join(', ') : '-', 'Reported by agent')}
        ${card('Agent', host.metrics?.agent_version || '-', 'Metrics collector')}
        ${card('VBoxManage', host.metrics?.vboxmanage_bin || '-', 'Detected command')}
        ${card('VMs', host.vms.length, 'VirtualBox inventory')}
        ${card('Running VMs', host.vms.filter((vm) => vmRuntimeStatus(host, vm) === 'running').length, 'Resolved from live runtime data')}
        ${card('Containers', host.containers.length, 'Docker inventory')}
        ${card('Container images', (host.images || []).length, 'Docker image cache')}
        ${card('Kernel', host.metrics?.kernel || '-', 'Host OS runtime')}
        ${card('WOL MAC', host.wol?.mac || host.wol?.reported_mac || '-', host.wol?.relay_host ? `Relay ${host.wol.relay_host}` : 'Wake-on-LAN profile')}
        ${card('Detected NICs', Array.isArray(host.wol?.interfaces) ? host.wol.interfaces.length : 0, 'Reported by agent')}
        ${card('Metrics bytes', host.agent_debug?.metrics_bytes ?? '-', host.agent_debug?.metrics_valid ? 'Valid payload' : 'No valid metrics payload')}
      </div>`;
  }

  function commandsPanel(limit = null, withAuditLink = false, withRefresh = false) {
    const rows = limit ? state.data.commands.slice(0, limit) : state.data.commands;
    return `
      <section class="table-panel">
        <div class="table-head"><h2>Recent operations</h2><div class="actions">${withRefresh ? '<button class="icon-btn" type="button" data-page-refresh title="Refresh Operations" aria-label="Refresh Operations">&#8635;</button>' : ''}${withAuditLink ? '<a class="btn ghost" href="#audit">Full audit</a>' : ''}<span class="badge">${rows.length} / ${state.data.commands.length} events</span></div></div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Time</th><th>Host</th><th>Action</th><th>Target</th><th>Status</th></tr></thead>
            <tbody>
              ${rows.map((cmd) => `<tr><td>${esc(cmd.created_at)}</td><td>${esc(cmd.hostname)}</td><td>${esc(cmd.action)}</td><td>${esc(cmd.vmname)}</td><td>${statusBadge(cmd.status, commandStatusMessage(cmd))}</td></tr>`).join('') || tableEmpty(5)}
            </tbody>
          </table>
        </div>
      </section>`;
  }

  function renderAudit() {
    title.textContent = 'Audit';
    root.innerHTML = `
      ${commandsPanel(null, false, true)}
      <section class="table-panel">
        <div class="table-head"><h2>Audit logs</h2><div class="actions"><button class="icon-btn" type="button" data-page-refresh title="Refresh Audit" aria-label="Refresh Audit">&#8635;</button><span class="badge">${state.data.audit.length} entries</span></div></div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Target</th><th>IP</th><th>Details</th></tr></thead>
            <tbody>
              ${state.data.audit.map((row) => `<tr><td>${esc(row.created_at)}</td><td>${esc(row.username)}</td><td>${esc(row.action)}</td><td>${esc(row.target)}</td><td>${esc(row.ip_address)}</td><td>${esc(row.details)}</td></tr>`).join('') || tableEmpty(6)}
            </tbody>
          </table>
        </div>
      </section>`;
    bindPageRefresh();
    bindStatusInfo();
  }

  function bindPageRefresh() {
    document.querySelectorAll('[data-page-refresh]').forEach((button) => {
      button.addEventListener('click', async () => {
        button.disabled = true;
        try {
          await fetchDashboard(true);
          toast('Dashboard refreshed', 'success');
        } catch (error) {
          toast(error.message || 'Refresh failed', 'error');
        } finally {
          button.disabled = false;
        }
      });
    });
  }

  function renderSettings() {
    title.textContent = 'Settings';
    const hosts = state.data.hosts;
    const users = state.data.users || [];
    root.innerHTML = `
      <section class="panel">
        <div class="panel-head"><h2>Settings</h2><span class="badge">${esc(state.data.role)}</span></div>
        <div class="metric-grid">
          ${card('Role', state.data.role, 'RBAC permission level')}
          ${card('CSRF', 'Enabled', 'All dashboard writes')}
          ${card('Hosts', hosts.length, 'Managed inventory')}
          ${card('Agent API', 'Token protected', 'Per-host enrollment')}
        </div>
      </section>
      <section class="editor-panel panel">
        <div class="panel-head">
          <h2>Host management</h2>
          <select id="token-host">${hosts.map((host) => `<option value="${esc(host.hostname)}">${esc(host.hostname)}</option>`).join('')}</select>
        </div>
        <div class="actions">
          <button class="btn primary" data-add-host>Add new host</button>
          <button class="btn primary" id="rotate-token">Rotate token</button>
        </div>
        <textarea id="settings-output" class="code-editor mini-code" readonly placeholder="Rotated token appears here."></textarea>
      </section>
      <section class="table-panel">
        <div class="table-head"><h2>User management</h2><span class="badge">${users.length} users</span></div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>User</th><th>Role</th><th>Created</th><th>Actions</th></tr></thead>
            <tbody>
              ${users.map((user) => `<tr><td>${esc(user.username)}</td><td><span class="badge">${esc(user.role)}</span></td><td>${esc(user.created_at || '-')}</td><td><button class="btn danger" data-delete-user="${esc(user.username)}">Delete</button></td></tr>`).join('') || tableEmpty(4)}
            </tbody>
          </table>
        </div>
      </section>
      <section class="panel">
        <div class="panel-head"><h2>Add or update user</h2></div>
        <div class="form-grid">
          <input id="user-name" placeholder="Username" aria-label="Username">
          <select id="user-role" aria-label="Role"><option value="admin">Admin</option><option value="operator">Operator</option><option value="viewer">Viewer</option></select>
          <input id="user-password" type="password" placeholder="Password, min 14 chars" aria-label="Password">
          <button class="btn primary" id="save-user">Save user</button>
        </div>
      </section>
      <section class="panel">
        <div class="panel-head"><h2>Mail configuration</h2><span class="badge">Alerts</span></div>
        <div class="form-grid">
          <input id="mail-from" placeholder="From email" value="${esc(state.data.mailSettings?.mail_from || '')}">
          <input id="smtp-host" placeholder="SMTP host" value="${esc(state.data.mailSettings?.smtp_host || '')}">
          <input id="smtp-port" placeholder="SMTP port" value="${esc(state.data.mailSettings?.smtp_port || '587')}">
          <input id="smtp-user" placeholder="SMTP username" value="${esc(state.data.mailSettings?.smtp_username || '')}">
          <input id="smtp-pass" type="password" placeholder="SMTP password">
          <select id="smtp-encryption"><option value="tls" ${state.data.mailSettings?.smtp_encryption === 'tls' ? 'selected' : ''}>TLS</option><option value="ssl" ${state.data.mailSettings?.smtp_encryption === 'ssl' ? 'selected' : ''}>SSL</option><option value="">None</option></select>
          <input id="imap-host" placeholder="IMAP host" value="${esc(state.data.mailSettings?.imap_host || '')}">
          <input id="imap-port" placeholder="IMAP port" value="${esc(state.data.mailSettings?.imap_port || '993')}">
          <input id="imap-user" placeholder="IMAP username" value="${esc(state.data.mailSettings?.imap_username || '')}">
          <input id="imap-pass" type="password" placeholder="IMAP password">
          <select id="imap-encryption"><option value="ssl" ${state.data.mailSettings?.imap_encryption === 'ssl' ? 'selected' : ''}>SSL</option><option value="tls" ${state.data.mailSettings?.imap_encryption === 'tls' ? 'selected' : ''}>TLS</option><option value="">None</option></select>
          <button class="btn primary" id="save-mail">Save mail settings</button>
        </div>
      </section>`;
    document.getElementById('rotate-token')?.addEventListener('click', async () => {
      const hostname = document.getElementById('token-host').value;
      const result = await postAction('rotate-token', { hostname });
      if (!result.ok) return toast(result.error || 'Token rotation failed', 'error');
      document.getElementById('settings-output').value = result.token;
      toast('Token rotated. Store it now; it will not be shown again.', 'success');
    });
    document.getElementById('save-user')?.addEventListener('click', async () => {
      const result = await postAction('user-save', {
        username: document.getElementById('user-name').value.trim(),
        role: document.getElementById('user-role').value,
        password: document.getElementById('user-password').value,
      });
      if (!result.ok) return toast(result.error || 'Could not save user', 'error');
      toast(result.message || 'User saved', 'success');
      await fetchDashboard();
    });
    document.querySelectorAll('[data-delete-user]').forEach((button) => {
      button.addEventListener('click', async () => {
        const username = button.dataset.deleteUser;
        if (!(await confirmDanger(`Delete user ${username}?`))) return;
        const result = await postAction('user-delete', { username, confirm: 'true' });
        if (!result.ok) return toast(result.error || 'Could not delete user', 'error');
        toast(result.message || 'User deleted', 'success');
        await fetchDashboard();
      });
    });
    document.getElementById('save-mail')?.addEventListener('click', async () => {
      const result = await postAction('mail-save', {
        mail_from: document.getElementById('mail-from').value,
        smtp_host: document.getElementById('smtp-host').value,
        smtp_port: document.getElementById('smtp-port').value,
        smtp_username: document.getElementById('smtp-user').value,
        smtp_password: document.getElementById('smtp-pass').value,
        smtp_encryption: document.getElementById('smtp-encryption').value,
        imap_host: document.getElementById('imap-host').value,
        imap_port: document.getElementById('imap-port').value,
        imap_username: document.getElementById('imap-user').value,
        imap_password: document.getElementById('imap-pass').value,
        imap_encryption: document.getElementById('imap-encryption').value,
      });
      if (!result.ok) return toast(result.error || 'Could not save mail settings', 'error');
      toast(result.message || 'Mail settings saved', 'success');
    });
    bindAddHost();
  }

  function renderAlarms() {
    title.textContent = 'Alarms';
    const alarms = state.data.alarms || { rules: [], events: [], active: 0, unread: 0 };
    root.innerHTML = `
      <section class="metric-grid">
        ${card('Active alarms', alarms.active || 0, 'Currently firing')}
        ${card('Unread alarms', alarms.unread || 0, 'Need acknowledgement')}
        ${card('Rules', alarms.rules?.length || 0, 'Monitoring policies')}
      </section>
      <section class="editor-panel panel">
        <div class="panel-head"><h2>Alarm rule</h2><span class="badge">Admin only</span></div>
        <div class="form-grid">
          <input id="alarm-name" placeholder="Rule name">
          <select id="alarm-metric"><option value="cpu">CPU %</option><option value="memory">Memory %</option><option value="disk">Disk %</option><option value="offline">Offline host</option></select>
          <select id="alarm-operator"><option value=">=">&gt;=</option><option value=">">&gt;</option><option value="<=">&lt;=</option><option value="<">&lt;</option><option value="=">=</option></select>
          <input id="alarm-threshold" type="number" value="90" min="0" step="0.1">
          <input id="alarm-email" placeholder="Notify email">
          <input id="alarm-cooldown" type="number" value="15" min="1">
          <button class="btn primary" id="save-alarm-rule">Save rule</button>
        </div>
      </section>
      <section class="table-panel">
        <div class="table-head"><h2>Rules</h2><span class="badge">${alarms.rules?.length || 0}</span></div>
        <div class="table-wrap"><table><thead><tr><th>Name</th><th>Metric</th><th>Condition</th><th>Email</th><th>Status</th></tr></thead><tbody>
          ${(alarms.rules || []).map((rule) => `<tr><td>${esc(rule.name)}</td><td>${esc(rule.metric)}</td><td>${esc(rule.operator)} ${esc(rule.threshold)}</td><td>${esc(rule.notify_email || '-')}</td><td>${esc(Number(rule.enabled) ? 'enabled' : 'disabled')}</td></tr>`).join('') || tableEmpty(5)}
        </tbody></table></div>
      </section>
      <section class="table-panel">
        <div class="table-head"><h2>Alarm history</h2><span class="badge">${alarms.events?.length || 0}</span></div>
        <div class="table-wrap"><table><thead><tr><th>Opened</th><th>Host</th><th>Rule</th><th>Value</th><th>Status</th><th>Action</th></tr></thead><tbody>
          ${(alarms.events || []).map((event) => `<tr><td>${esc(event.opened_at)}</td><td>${esc(event.hostname)}</td><td>${esc(event.rule_name || '-')}</td><td>${esc(event.metric_value)}</td><td>${statusBadge(event.status || 'active', event.message || '')}</td><td>${event.status === 'active' ? `<button class="btn ghost" data-ack-alarm="${esc(event.id)}">Acknowledge</button>` : '-'}</td></tr>`).join('') || tableEmpty(6)}
        </tbody></table></div>
      </section>`;
    document.getElementById('save-alarm-rule')?.addEventListener('click', async () => {
      const result = await postAction('alarm-save', {
        name: document.getElementById('alarm-name').value,
        metric: document.getElementById('alarm-metric').value,
        operator: document.getElementById('alarm-operator').value,
        threshold: document.getElementById('alarm-threshold').value,
        notify_email: document.getElementById('alarm-email').value,
        cooldown_minutes: document.getElementById('alarm-cooldown').value,
        enabled: '1',
      });
      if (!result.ok) return toast(result.error || 'Could not save alarm rule', 'error');
      toast(result.message || 'Alarm rule saved', 'success');
      await fetchDashboard();
    });
    document.querySelectorAll('[data-ack-alarm]').forEach((button) => button.addEventListener('click', async () => {
      const result = await postAction('alarm-ack', { id: button.dataset.ackAlarm });
      if (!result.ok) return toast(result.error || 'Could not acknowledge alarm', 'error');
      toast(result.message || 'Alarm acknowledged', 'success');
      await fetchDashboard();
    }));
    bindStatusInfo();
  }

  function renderScripts() {
    title.textContent = 'Scripts';
    const scripts = state.data.scripts || [];
    const hosts = state.data.hosts || [];
    const runs = state.data.scriptRuns || [];
    root.innerHTML = `
      <section class="editor-panel panel">
        <div class="panel-head"><h2>Saved script editor</h2><span class="badge">Admin only</span></div>
        <input id="script-name" placeholder="Script name" aria-label="Script name">
        <input id="script-description" placeholder="Description" aria-label="Script description">
        <textarea id="script-body" class="code-editor" rows="10" spellcheck="false">#!/usr/bin/env bash
set -euo pipefail
hostname
uptime
</textarea>
        <div class="actions"><button class="btn primary" id="save-script">Save script</button></div>
      </section>
      <section class="table-panel">
        <div class="table-head"><h2>Saved scripts</h2><span class="badge">${scripts.length} scripts</span></div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Name</th><th>Description</th><th>Updated</th><th>Run on hosts</th><th>Actions</th></tr></thead>
            <tbody>
              ${scripts.map((script) => `<tr>
                <td>${esc(script.name)}</td>
                <td>${esc(script.description || '-')}</td>
                <td>${esc(script.updated_at || '-')}</td>
                <td>${hosts.map((host) => `<label class="checkbox-chip"><input type="checkbox" data-script-host="${esc(script.id)}" value="${esc(host.hostname)}"> ${esc(host.hostname)}</label>`).join('') || '-'}</td>
                <td><div class="actions"><button class="btn ghost" data-edit-script="${esc(script.id)}">Edit</button><button class="btn primary" data-run-script="${esc(script.id)}">Run</button><button class="btn danger" data-delete-script="${esc(script.id)}">Delete</button></div></td>
              </tr>`).join('') || tableEmpty(5)}
            </tbody>
          </table>
        </div>
      </section>
      <section class="table-panel">
        <div class="table-head"><h2>Script runs</h2><span class="badge">${runs.length} runs</span></div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Time</th><th>Host</th><th>Script</th><th>Status</th><th>Output</th></tr></thead>
            <tbody>${runs.map((run) => `<tr><td>${esc(run.created_at || '-')}</td><td>${esc(run.hostname || '-')}</td><td>${esc(run.script_name || run.script_id)}</td><td>${statusBadge(run.status || 'pending', run.result || commandStatusMessage(run))}</td><td>${esc(run.result || '-')}</td></tr>`).join('') || tableEmpty(5)}</tbody>
          </table>
        </div>
      </section>`;
    document.getElementById('save-script')?.addEventListener('click', saveScriptFromEditor);
    document.querySelectorAll('[data-edit-script]').forEach((button) => {
      button.addEventListener('click', () => {
        const script = scripts.find((item) => String(item.id) === button.dataset.editScript);
        if (!script) return;
        document.getElementById('script-name').value = script.name || '';
        document.getElementById('script-description').value = script.description || '';
        document.getElementById('script-body').value = script.body || '';
        toast('Script loaded for editing', 'success');
      });
    });
    bindScriptRunButtons();
    document.querySelectorAll('[data-delete-script]').forEach((button) => {
      button.addEventListener('click', async () => {
        if (!(await confirmDanger('Delete this saved script?'))) return;
        const result = await postAction('script-delete', { id: button.dataset.deleteScript, confirm: 'true' });
        if (!result.ok) return toast(result.error || 'Could not delete script', 'error');
        toast(result.message || 'Script deleted', 'success');
        await fetchDashboard();
      });
    });
  }

  function docFor(slug) {
    const docs = state.data.docs || [];
    return docs.find((doc) => doc.slug === slug) || docs[0] || { title: 'Docs', body: '' };
  }

  function markdownLite(text) {
    return esc(text)
      .replace(/^### (.*)$/gm, '<h3>$1</h3>')
      .replace(/^## (.*)$/gm, '<h2>$1</h2>')
      .replace(/^# (.*)$/gm, '<h2>$1</h2>')
      .replace(/`([^`]+)`/g, '<code>$1</code>')
      .replace(/\n- /g, '\n<br>- ')
      .replace(/\n\n/g, '</p><p>');
  }

  function helpSlugForView(view = currentView()) {
    if (view.name === 'host' || view.name === 'hosts') return 'hosts';
    if (view.name === 'vms') return 'virtual-machines';
    if (view.name === 'containers' || view.name === 'compose') return 'containers-compose';
    if (view.name === 'scripts' || view.name === 'audit') return 'audit-logs';
    if (view.name === 'alarms') return 'alarms-notifications';
    if (view.name === 'settings') return 'security';
    return 'getting-started';
  }

  function renderDocs(slug = '') {
    title.textContent = 'Docs';
    const docs = state.data.docs || [];
    const active = docFor(slug || docs[0]?.slug || 'installation');
    root.innerHTML = `
      <section class="docs-layout">
        <aside class="docs-nav">
          ${docs.map((doc) => `<a class="${doc.slug === active.slug ? 'active' : ''}" href="#docs/${esc(doc.slug)}">${esc(doc.title)}</a>`).join('')}
        </aside>
        <article class="panel doc-content">
          <h2>${esc(active.title)}</h2>
          <p>${markdownLite(active.body || 'Documentation is not available in this package.')}</p>
        </article>
      </section>`;
    bindStatusInfo();
  }

  function renderAbout() {
    title.textContent = 'About VMange';
    root.innerHTML = `
      <section class="panel about-panel">
        <h2>VMange v1.7</h2>
        <p>VMange is a free and open-source infrastructure management dashboard for Linux hosts, VirtualBox, Docker, Compose stacks, scripts, terminal access, and monitoring alarms.</p>
        <div class="stats-grid">
          <div class="stat-card"><span>VirtualBox</span><strong>Power, snapshots, storage, network, VRDE, screenshots</strong></div>
          <div class="stat-card"><span>Docker</span><strong>Containers, images, Compose stacks, logs</strong></div>
          <div class="stat-card"><span>Security</span><strong>CSRF, RBAC, host tokens, allowlisted commands, audit logs</strong></div>
        </div>
        <p>Author: <a href="https://ahmed-sami.me/" target="_blank" rel="noreferrer">Ahmed Sami Abdelhamed</a>, cloud and infrastructure engineer with experience in system administration, cloud operations, virtualization, Linux, Docker, and service reliability.</p>
        <p>VMange is released under the Apache-2.0 license. Contributions, ideas, and bug reports are welcome.</p>
      </section>`;
  }

  function enhanceResponsiveTables() {
    document.querySelectorAll('table').forEach((table) => {
      table.classList.add('responsive-table');
      const headers = [...table.querySelectorAll('thead th')].map((cell) => cell.textContent.trim());
      table.querySelectorAll('tbody tr').forEach((row) => {
        [...row.children].forEach((cell, index) => {
          if (!cell.dataset.label && headers[index]) {
            cell.dataset.label = headers[index];
          }
        });
      });
    });
    bindStatusInfo();
  }

  function render() {
    if (!state.data) return;
    queueMicrotask(enhanceResponsiveTables);
    const view = currentView();
    document.querySelectorAll('[data-nav]').forEach((link) => link.classList.toggle('active', link.dataset.nav === view.name));
    if (view.name === 'overview') return renderOverview();
    if (view.name === 'hosts') return renderHosts();
    if (view.name === 'vms') return renderVMs();
    if (view.name === 'containers') return renderContainers();
    if (view.name === 'compose') return renderCompose();
    if (view.name === 'scripts') return renderScripts();
    if (view.name === 'audit') return renderAudit();
    if (view.name === 'alarms') return renderAlarms();
    if (view.name === 'settings') return renderSettings();
    if (view.name === 'docs') return renderDocs(view.doc);
    if (view.name === 'about') return renderAbout();
    if (view.name === 'host') return renderHost(view.host);
    renderOverview();
  }

  function bindOpenHost() {
    document.querySelectorAll('[data-open-host]').forEach((node) => {
      node.addEventListener('click', () => {
        location.hash = `host/${encodeURIComponent(node.dataset.openHost)}`;
      });
    });
  }

  function bindPageSearch() {
    document.querySelectorAll('[data-page-search]').forEach((input) => {
      input.addEventListener('input', () => {
        state.search = input.value;
        if (search) search.value = input.value;
        render();
      });
    });
  }

  async function saveScriptFromEditor() {
    const fallbackName = `script-${new Date().toISOString().replace(/[-:TZ.]/g, '').slice(0, 14)}`;
    const result = await postAction('script-save', {
      name: document.getElementById('script-name')?.value.trim() || fallbackName,
      description: document.getElementById('script-description')?.value.trim() || '',
      body: document.getElementById('script-body')?.value || '',
    });
    if (!result.ok) return toast(result.error || 'Could not save script', 'error');
    toast(result.message || 'Script saved', 'success');
    await fetchDashboard();
  }

  function bindScriptRunButtons() {
    document.querySelectorAll('[data-run-script]').forEach((button) => {
      button.addEventListener('click', async () => {
        const scriptId = button.dataset.runScript;
        const directHost = button.dataset.host;
        const selected = directHost
          ? [directHost]
          : Array.from(document.querySelectorAll('[data-script-host]:checked')).filter((input) => input.dataset.scriptHost === scriptId).map((input) => input.value);
        if (!selected.length) return toast('Select at least one host', 'error');
        if (!(await confirmDanger(`Run script on ${selected.join(', ')}?`))) return;
        const result = await postAction('script-run', { script_id: scriptId, hosts: JSON.stringify(selected), confirm: 'true' });
        if (!result.ok) return toast(result.error || 'Could not queue script', 'error');
        toast(result.message || 'Script queued', 'success');
        await fetchDashboard();
      });
    });
  }

  function bindStacks() {
    document.querySelectorAll('[data-stack-deploy]').forEach((button) => {
      button.addEventListener('click', async () => {
        if (!(await confirmDanger(`Deploy saved stack ${button.dataset.stackDeploy} on ${button.dataset.host}?`))) return;
        const result = await postAction('stack-deploy', {
          hostname: button.dataset.host,
          project: button.dataset.stackDeploy,
          confirm: 'true',
        });
        if (!result.ok) return toast(result.error || 'Could not deploy stack', 'error');
        toast(result.message || 'Stack deployment queued', 'success');
        await fetchDashboard();
      });
    });
    document.querySelectorAll('[data-stack-delete]').forEach((button) => {
      button.addEventListener('click', async () => {
        if (!(await confirmDanger(`Delete saved stack ${button.dataset.stackDelete}?`))) return;
        const result = await postAction('stack-delete', {
          hostname: button.dataset.host,
          project: button.dataset.stackDelete,
          confirm: 'true',
        });
        if (!result.ok) return toast(result.error || 'Could not delete stack', 'error');
        toast(result.message || 'Stack deleted', 'success');
        await fetchDashboard();
      });
    });
  }

  function bindAddHost() {
    document.querySelectorAll('[data-add-host]').forEach((button) => {
      button.addEventListener('click', () => openEnrollModal());
    });
  }

  function bindDeleteHosts() {
    document.querySelectorAll('[data-delete-host]').forEach((button) => {
      button.addEventListener('click', async (event) => {
        event.stopPropagation();
        const hostname = button.dataset.deleteHost;
        if (!(await confirmDanger(`Delete host ${hostname} from the dashboard?`))) return;
        const result = await postAction('host-delete', { hostname, confirm: 'true' });
        if (!result.ok) return toast(result.error || 'Could not delete host', 'error');
        toast(result.message || 'Host deleted', 'success');
        await fetchDashboard();
      });
    });
  }

  async function renameHost(hostname) {
    const nextName = window.prompt(`Rename host ${hostname} to:`, hostname);
    if (!nextName) return;
    const trimmed = nextName.trim();
    if (!trimmed || trimmed === hostname) return;
    const result = await postAction('host-rename', { hostname, new_hostname: trimmed });
    if (!result.ok) return toast(result.error || 'Could not rename host', 'error');
    toast(result.message || 'Host renamed', 'success');
    await fetchDashboard();
    location.hash = `host/${encodeURIComponent(result.hostname || trimmed)}`;
  }

  function bindRenameHosts() {
    document.querySelectorAll('[data-rename-host]').forEach((button) => {
      button.addEventListener('click', () => renameHost(button.dataset.renameHost));
    });
  }

  function openEnrollModal() {
    if (!enrollModal?.showModal) {
      const hostname = window.prompt('Host name');
      if (hostname) createEnrollment(hostname);
      return;
    }
    const input = document.getElementById('enroll-hostname');
    const output = document.getElementById('enroll-output');
    input.value = '';
    output.hidden = true;
    output.innerHTML = '';
    enrollModal.showModal();
    setTimeout(() => input.focus(), 20);
  }

  async function createEnrollment(hostname) {
    const createButton = document.getElementById('enroll-create');
    if (createButton) {
      createButton.disabled = true;
      createButton.textContent = 'Generating...';
    }
    try {
      const result = await postAction('enroll-host', { hostname });
      if (!result.ok) {
        toast(result.error || 'Could not create host enrollment', 'error');
        return;
      }
      renderEnrollmentOutput(result);
      toast('Host enrollment created', 'success');
      await fetchDashboard();
    } finally {
      if (createButton) {
        createButton.disabled = false;
        createButton.textContent = 'Generate token and commands';
      }
    }
  }

  function renderEnrollmentOutput(result) {
    const output = document.getElementById('enroll-output');
    if (!output) return;
    output.hidden = false;
    output.innerHTML = `
      <div class="alert">
        <strong>${esc(result.hostname)}</strong> was added to the dashboard. Run the command on that PC. The token is already included and the installer will run without asking questions.
        ${result.tokenMode === 'legacy' ? '<br>This server is using the legacy shared agent token because the per-host token table is not available yet.' : ''}
      </div>
      <ol class="steps-list">
        <li>Copy the command below.</li>
        <li>Run it locally on the host machine.</li>
        <li>The installer will test the connection immediately.</li>
        <li>Return to the dashboard. The host should turn online and then VMs and containers will appear.</li>
      </ol>
      <label class="field-block">
        <span>Host token</span>
        <textarea id="copy-token" class="code-editor mini-code" readonly>${esc(result.token)}</textarea>
      </label>
      <label class="field-block">
        <span>Download script</span>
        <textarea id="copy-download" class="code-editor mini-code" readonly>curl -fsSL "${esc(result.installerUrl)}" -o vmange-install.sh</textarea>
      </label>
      <label class="field-block">
        <span>Make executable and run</span>
        <textarea id="copy-run" class="code-editor mini-code" readonly>${esc(result.downloadCommand.replace(/^curl -fsSL "[^"]+" -o vmange-install\.sh && /, ''))}</textarea>
      </label>
      <label class="field-block">
        <span>Run as one command</span>
        <textarea id="copy-full" class="code-editor mini-code" readonly>${esc(result.downloadCommand)}</textarea>
      </label>
      <label class="field-block">
        <span>Connection check</span>
        <textarea class="code-editor mini-code" readonly>If install succeeds, the script prints "Connection check: success", shows the API URL, and stores host config in /etc/vmange/agent.env.</textarea>
      </label>
      <div class="actions">
        <button type="button" class="btn ghost" data-copy-from="copy-token">Copy token</button>
        <button type="button" class="btn ghost" data-copy-from="copy-download">Copy download</button>
        <button type="button" class="btn ghost" data-copy-from="copy-run">Copy run command</button>
        <button type="button" class="btn ghost" data-copy-from="copy-full">Copy full command</button>
        <button type="button" class="btn primary" data-close-enroll>Done</button>
      </div>`;
    output.querySelectorAll('[data-copy-from]').forEach((button) => {
      button.addEventListener('click', () => {
        const source = document.getElementById(button.dataset.copyFrom);
        copyText(source?.value || source?.textContent || '');
      });
    });
    output.querySelector('[data-close-enroll]')?.addEventListener('click', () => enrollModal?.close());
  }

  async function copyText(text) {
    try {
      await navigator.clipboard.writeText(text);
      toast('Copied', 'success');
    } catch (error) {
      toast('Copy failed. Select the text manually.', 'error');
    }
  }

  function parsePayload(value) {
    if (!value) return {};
    try {
      const parsed = JSON.parse(value);
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch {
      return {};
    }
  }

  function actionSchema(button) {
    const payload = parsePayload(button.dataset.payload || '');
    const action = button.dataset.command;
    const vmName = button.dataset.target || 'resource';
    const schemas = {
      vm_create: {
        title: 'Create virtual machine',
        note: 'Creates the VM on the selected host. Disk and ISO paths are host-local paths.',
        fields: [
          { name: '__host', label: 'Target host', type: 'select', value: payload.__host || button?.dataset?.host || (state.data.hosts[0]?.hostname || ''), required: true, options: (state.data.hosts || []).map((host) => [host.hostname, host.hostname]) },
          { name: 'name', label: 'VM name', type: 'text', required: true, value: payload.name || 'new-vm' },
          { name: 'ostype', label: 'OS type', type: 'text', value: payload.ostype || 'Ubuntu_64' },
          { name: 'cpu', label: 'vCPUs', type: 'number', min: 1, step: 1, required: true, value: payload.cpu ?? 2 },
          { name: 'ram_mb', label: 'RAM (MB)', type: 'number', min: 128, step: 128, required: true, value: payload.ram_mb ?? 2048 },
          { name: 'vram_mb', label: 'VRAM (MB)', type: 'number', min: 1, step: 1, value: payload.vram_mb ?? 32 },
          { name: 'disk_size_mb', label: 'Disk size (MB)', type: 'number', min: 1024, step: 1024, value: payload.disk_size_mb ?? 20480 },
          { name: 'disk_path', label: 'Disk path', type: 'text', value: payload.disk_path || '', full: true },
          { name: 'controller', label: 'Controller', type: 'select', value: payload.controller || 'SATA', options: [['SATA', 'SATA'], ['IDE', 'IDE'], ['SCSI', 'SCSI']] },
          { name: 'iso_path', label: 'ISO path', type: 'text', value: payload.iso_path || '', full: true },
          { name: 'network_mode', label: 'Network mode', type: 'select', value: payload.network_mode || 'nat', options: [['nat', 'NAT'], ['bridged', 'Bridged'], ['hostonly', 'Host-only'], ['intnet', 'Internal']] },
          { name: 'unattended', label: 'Ubuntu unattended install', type: 'checkbox', checked: Boolean(payload.unattended) },
          { name: 'hostname', label: 'Guest hostname', type: 'text', value: payload.hostname || 'vm-new' },
          { name: 'username', label: 'Guest username', type: 'text', value: payload.username || 'admin' },
          { name: 'password', label: 'Guest password', type: 'password', value: payload.password || '' },
          { name: 'full_name', label: 'Full name', type: 'text', value: payload.full_name || 'VM Admin' },
          { name: 'ssh_key', label: 'SSH public key (optional)', type: 'textarea', value: payload.ssh_key || '', full: true },
          { name: 'timezone', label: 'Timezone', type: 'text', value: payload.timezone || 'UTC' },
          { name: 'locale', label: 'Locale', type: 'text', value: payload.locale || 'en_US' },
          { name: 'start', label: 'Start after create', type: 'checkbox', checked: Boolean(payload.start) },
        ],
      },
      vm_enable_vrde: {
        title: `Enable VRDE console for ${vmName}`,
        note: 'Shared hosting shows RDP/VRDE connection details; browser console needs a standalone gateway.',
        fields: [{ name: 'port', label: 'VRDE port', type: 'number', min: 1024, step: 1, value: payload.port ?? 3389 }],
      },
      vm_screenshot: {
        title: `Capture screenshot for ${vmName}`,
        fields: [{ name: 'path', label: 'Host PNG path', type: 'text', value: payload.path || `/var/lib/vmange/screenshots/${vmName}.png`, full: true }],
      },
      vm_log_tail: {
        title: `Tail VirtualBox log for ${vmName}`,
        fields: [
          { name: 'file', label: 'Log file', type: 'text', value: payload.file || 'VBox.log' },
          { name: 'lines', label: 'Lines', type: 'number', min: 20, step: 20, value: payload.lines ?? 200 },
        ],
      },
      snapshot_create: {
        title: `Create snapshot for ${vmName}`,
        fields: [{ name: 'name', label: 'Snapshot name', type: 'text', required: true, value: payload.name || '' }],
      },
      snapshot_restore: {
        title: `Restore snapshot for ${vmName}`,
        note: 'This replaces the current VM state with the selected snapshot.',
        fields: [{ name: 'snapshot', label: 'Snapshot', type: 'text', required: true, value: payload.snapshot || '' }],
      },
      snapshot_delete: {
        title: `Delete snapshot for ${vmName}`,
        note: 'Delete the selected snapshot permanently.',
        fields: [{ name: 'snapshot', label: 'Snapshot', type: 'text', required: true, value: payload.snapshot || '' }],
      },
      vm_clone: {
        title: `Clone ${vmName}`,
        fields: [
          { name: 'name', label: 'New VM name', type: 'text', required: true, value: payload.name || `${vmName}-clone` },
          { name: 'mode', label: 'Clone mode', type: 'select', value: payload.mode || 'machine', options: [['machine', 'Machine'], ['all', 'Machine + disks']] },
        ],
      },
      vm_set_resources: {
        title: `Set CPU / RAM for ${vmName}`,
        note: 'Change CPU, memory, and video memory without editing raw JSON.',
        fields: [
          { name: 'cpu', label: 'vCPUs', type: 'number', min: 1, step: 1, required: true, value: payload.cpu ?? 1 },
          { name: 'ram_mb', label: 'RAM (MB)', type: 'number', min: 128, step: 128, required: true, value: payload.ram_mb ?? 1024 },
          { name: 'vram_mb', label: 'Video RAM (MB)', type: 'number', min: 1, step: 1, required: true, value: payload.vram_mb ?? 16 },
        ],
      },
      vm_attach_iso: {
        title: `Attach ISO to ${vmName}`,
        fields: [
          { name: 'controller', label: 'Controller', type: 'text', required: true, value: payload.controller || 'IDE' },
          { name: 'port', label: 'Port', type: 'number', min: 0, step: 1, required: true, value: payload.port ?? 1 },
          { name: 'device', label: 'Device', type: 'number', min: 0, step: 1, required: true, value: payload.device ?? 0 },
          { name: 'path', label: 'ISO path', type: 'text', required: true, value: payload.path || '' , full: true},
        ],
      },
      vm_detach_iso: {
        title: `Detach ISO from ${vmName}`,
        fields: [
          { name: 'controller', label: 'Controller', type: 'text', required: true, value: payload.controller || 'IDE' },
          { name: 'port', label: 'Port', type: 'number', min: 0, step: 1, required: true, value: payload.port ?? 1 },
          { name: 'device', label: 'Device', type: 'number', min: 0, step: 1, required: true, value: payload.device ?? 0 },
        ],
      },
      vm_set_network: {
        title: `Edit network for ${vmName}`,
        fields: [
          { name: 'adapter', label: 'Adapter', type: 'number', min: 1, step: 1, required: true, value: payload.adapter ?? 1 },
          { name: 'mode', label: 'Mode', type: 'select', value: payload.mode || 'nat', options: [['nat', 'NAT'], ['bridged', 'Bridged'], ['hostonly', 'Host-only'], ['intnet', 'Internal']] },
          { name: 'bridge', label: 'Bridge interface', type: 'text', value: payload.bridge || '', full: true },
          { name: 'hostonly', label: 'Host-only network', type: 'text', value: payload.hostonly || '', full: true },
          { name: 'intnet', label: 'Internal network', type: 'text', value: payload.intnet || '', full: true },
        ],
      },
      vm_cable_connected: {
        title: `Set cable state for ${vmName}`,
        fields: [
          { name: 'adapter', label: 'Adapter', type: 'number', min: 1, step: 1, required: true, value: payload.adapter ?? 1 },
          { name: 'connected', label: 'Cable connected', type: 'checkbox', checked: Boolean(payload.connected) },
        ],
      },
      vm_export: {
        title: `Export ${vmName}`,
        fields: [{ name: 'path', label: 'Export path', type: 'text', required: true, value: payload.path || '' , full: true}],
      },
      vm_set_boot_order: {
        title: `Boot order for ${vmName}`,
        fields: [
          { name: 'boot1', label: 'Boot 1', type: 'select', value: payload.boot1 || 'dvd', options: [['dvd', 'DVD'], ['disk', 'Disk'], ['net', 'Network'], ['none', 'None']] },
          { name: 'boot2', label: 'Boot 2', type: 'select', value: payload.boot2 || 'disk', options: [['dvd', 'DVD'], ['disk', 'Disk'], ['net', 'Network'], ['none', 'None']] },
          { name: 'boot3', label: 'Boot 3', type: 'select', value: payload.boot3 || 'none', options: [['dvd', 'DVD'], ['disk', 'Disk'], ['net', 'Network'], ['none', 'None']] },
          { name: 'boot4', label: 'Boot 4', type: 'select', value: payload.boot4 || 'none', options: [['dvd', 'DVD'], ['disk', 'Disk'], ['net', 'Network'], ['none', 'None']] },
        ],
      },
      vm_set_description: {
        title: `Description for ${vmName}`,
        fields: [{ name: 'description', label: 'Description', type: 'textarea', value: payload.description || '', full: true }],
      },
      vm_set_autostart: {
        title: `Autostart for ${vmName}`,
        fields: [{ name: 'enabled', label: 'Enable autostart', type: 'checkbox', checked: Boolean(payload.enabled) }],
      },
    };
    const schema = schemas[action] || null;
    if (schema) schema.basePayload = payload;
    return schema;
  }

  function renderActionField(field) {
    const cls = field.full ? 'field-block full' : 'field-block';
    if (field.type === 'select') {
      return `<label class="${cls}"><span>${esc(field.label)}</span><select name="${esc(field.name)}">${(field.options || []).map(([value, label]) => `<option value="${esc(value)}" ${String(field.value) === String(value) ? 'selected' : ''}>${esc(label)}</option>`).join('')}</select></label>`;
    }
    if (field.type === 'textarea') {
      return `<label class="${cls}"><span>${esc(field.label)}</span><textarea name="${esc(field.name)}" rows="4">${esc(field.value || '')}</textarea></label>`;
    }
    if (field.type === 'checkbox') {
      return `<label class="${cls}"><span>${esc(field.label)}</span><span class="checkbox-row"><input type="checkbox" name="${esc(field.name)}" ${field.checked ? 'checked' : ''}><strong>${field.checked ? 'Enabled' : 'Disabled'}</strong></span></label>`;
    }
    return `<label class="${cls}"><span>${esc(field.label)}</span><input type="${esc(field.type || 'text')}" name="${esc(field.name)}" value="${esc(field.value ?? '')}" ${field.min !== undefined ? `min="${esc(field.min)}"` : ''} ${field.step !== undefined ? `step="${esc(field.step)}"` : ''} ${field.required ? 'required' : ''}></label>`;
  }

  function collectActionPayload(form, schema) {
    const payload = {};
    schema.fields.forEach((field) => {
      const input = form.elements.namedItem(field.name);
      if (!input) return;
      if (field.type === 'checkbox') {
        payload[field.name] = Boolean(input.checked);
      } else if (field.type === 'number') {
        payload[field.name] = Number(input.value || 0);
      } else {
        payload[field.name] = input.value;
      }
    });
    ['vm_uuid', 'vm_name'].forEach((key) => {
      if (schema.basePayload && schema.basePayload[key] !== undefined && payload[key] === undefined) {
        payload[key] = schema.basePayload[key];
      }
    });
    return JSON.stringify(payload);
  }

  function openActionModal(button) {
    const schema = actionSchema(button);
    if (!schema || !actionModal?.showModal) return Promise.resolve(button.dataset.payload || '');
    const form = document.getElementById('action-form');
    const titleNode = document.getElementById('action-modal-title');
    const copyNode = document.getElementById('action-modal-copy');
    const fieldsNode = document.getElementById('action-modal-fields');
    const noteNode = document.getElementById('action-modal-note');
    titleNode.textContent = schema.title;
    copyNode.textContent = `Review the settings for ${button.dataset.command.replaceAll('_', ' ')} on ${button.dataset.target}.`;
    fieldsNode.innerHTML = schema.fields.map(renderActionField).join('');
    noteNode.hidden = !schema.note;
    noteNode.textContent = schema.note || '';
    actionModal.showModal();
    return new Promise((resolve) => {
      actionModal.addEventListener('close', () => {
        if (actionModal.returnValue !== 'submit') {
          resolve(null);
          return;
        }
        resolve(collectActionPayload(form, schema));
      }, { once: true });
    });
  }

  function hostByName(hostname) {
    return (state.data.hosts || []).find((host) => host.hostname === hostname) || null;
  }

  function vmByName(host, vmName) {
    return (host?.vms || []).find((vm) => vm.name === vmName) || null;
  }

  function infoDialog(titleText, html) {
    const dialog = document.createElement('dialog');
    dialog.className = 'modal wide-modal info-modal';
    dialog.innerHTML = `<form method="dialog"><button class="modal-close" type="button" data-dialog-close aria-label="Close dialog">x</button><h2>${esc(titleText)}</h2><div class="info-body">${html}</div><div class="modal-actions"><button class="btn ghost" value="cancel">Close</button></div></form>`;
    document.body.appendChild(dialog);
    wireDialogDismiss(dialog);
    dialog.addEventListener('close', () => dialog.remove(), { once: true });
    dialog.showModal();
  }

  function choiceDialog(titleText, copy, choices) {
    const dialog = document.createElement('dialog');
    dialog.className = 'modal wide-modal info-modal';
    dialog.innerHTML = `<form method="dialog"><button class="modal-close" type="button" data-dialog-close aria-label="Close dialog">x</button><h2>${esc(titleText)}</h2><p class="muted">${esc(copy)}</p><div class="modal-actions">${choices.map(([value, label, tone]) => `<button class="btn ${esc(tone)}" value="${esc(value)}">${esc(label)}</button>`).join('')}<button class="btn ghost" value="cancel">Cancel</button></div></form>`;
    document.body.appendChild(dialog);
    wireDialogDismiss(dialog);
    dialog.showModal();
    return new Promise((resolve) => {
      dialog.addEventListener('close', () => {
        const value = dialog.returnValue;
        dialog.remove();
        resolve(value && value !== 'cancel' ? value : null);
      }, { once: true });
    });
  }

  function openWolDialog(targetHostname = '') {
    const hosts = state.data.hosts || [];
    const target = hostByName(targetHostname) || hosts[0] || null;
    const onlineHosts = hosts.filter((host) => host.online);
    if (!target) return toast('No host is available for Wake-on-LAN.', 'error');
    const wol = target.wol || {};
    const reportedInterfaces = Array.isArray(wol.interfaces) ? wol.interfaces : [];
    const dialog = document.createElement('dialog');
    dialog.className = 'modal wide-modal info-modal';
    dialog.innerHTML = `
      <form method="dialog" id="wol-form">
        <button class="modal-close" type="button" data-dialog-close aria-label="Close dialog">x</button>
        <h2>Wake-on-LAN</h2>
        <div class="modal-form-grid">
          <label class="field-block"><span>Target host</span><select name="target_host">${hosts.map((host) => `<option value="${esc(host.hostname)}" ${host.hostname === target.hostname ? 'selected' : ''}>${esc(host.hostname)}</option>`).join('')}</select></label>
          <label class="field-block"><span>Relay host</span><select name="relay_host">${onlineHosts.map((host) => `<option value="${esc(host.hostname)}" ${host.hostname === (wol.relay_host || '') ? 'selected' : ''}>${esc(host.hostname)}</option>`).join('')}</select></label>
          <label class="field-block"><span>MAC address</span><input name="mac" value="${esc(wol.mac || wol.reported_mac || '')}" placeholder="aa:bb:cc:dd:ee:ff" required></label>
          <label class="field-block"><span>Broadcast address</span><input name="broadcast" value="${esc(wol.broadcast || '255.255.255.255')}" required></label>
          <label class="field-block"><span>UDP port</span><input name="port" type="number" min="1" max="65535" value="${esc(wol.port || 9)}" required></label>
        </div>
        <p class="muted">The selected online relay host sends the wake packet for the target host. Agent-reported preferred MAC: ${esc(wol.reported_mac || '-')}.</p>
        ${reportedInterfaces.length ? `<div class="inline-note"><strong>Detected interfaces</strong><p>${reportedInterfaces.map((item) => `${esc(item.name || '-')}: ${esc(item.mac || '-')}${item.physical ? ' (physical)' : ''}`).join('<br>')}</p></div>` : ''}
        <div class="modal-actions">
          <button class="btn ghost" value="cancel">Cancel</button>
          <button class="btn ghost" id="save-wol-profile" type="button">Save profile</button>
          <button class="btn primary" id="send-wol" type="button">Send WOL</button>
        </div>
      </form>`;
    document.body.appendChild(dialog);
    wireDialogDismiss(dialog);
    dialog.addEventListener('close', () => dialog.remove(), { once: true });
    dialog.showModal();
    const form = dialog.querySelector('#wol-form');
    const readValues = () => {
      const values = Object.fromEntries(new FormData(form).entries());
      values.hostname = values.target_host;
      return values;
    };
    dialog.querySelector('#save-wol-profile')?.addEventListener('click', async () => {
      const values = readValues();
      const result = await postAction('wol-save', values);
      if (!result.ok) return toast(result.error || 'Could not save Wake-on-LAN profile', 'error');
      toast(result.message || 'Wake-on-LAN profile saved', 'success');
      await fetchDashboard();
    });
    dialog.querySelector('#send-wol')?.addEventListener('click', async () => {
      const values = readValues();
      if (!values.relay_host) return toast('Choose an online relay host first.', 'error');
      const save = await postAction('wol-save', values);
      if (!save.ok) return toast(save.error || 'Could not save Wake-on-LAN profile', 'error');
      await queueCommand(values.relay_host, 'host_wol_send', values.target_host, JSON.stringify({
        target_host: values.target_host,
        mac: values.mac,
        broadcast: values.broadcast,
        port: Number(values.port || 9),
      }));
      dialog.close();
    });
  }

  function bindWolButtons() {
    document.querySelectorAll('[data-wol-open]').forEach((button) => {
      button.addEventListener('click', () => openWolDialog(button.dataset.wolOpen));
    });
  }

  function vmLatestCommand(hostname, vmName, actions = []) {
    return (state.data.commands || []).find((cmd) => cmd.hostname === hostname && cmd.vmname === vmName && actions.includes(cmd.action));
  }

  function openConsoleGuide(hostname, vmName) {
    const host = hostByName(hostname);
    const vm = vmByName(host, vmName);
    if (!host || !vm) return toast('VM details are no longer available. Refresh the page.', 'error');
    const ips = Array.isArray(host.metrics?.ips) ? host.metrics.ips : [];
    const primaryIp = ips[0] ? String(ips[0]).split(':').pop() : host.hostname;
    const port = vm.vrde_port || 3389;
    const gateway = config.gatewayUrl || '';
    const screenshot = vmLatestCommand(hostname, vmName, ['vm_screenshot']);
    infoDialog(`Console for ${vmName}`, `
      <div class="guide-grid">
        <div class="guide-card">
          <h3>Shared hosting console</h3>
          <p>VMange can enable VirtualBox VRDE and capture screenshots from shared hosting. Live browser console needs a separate gateway service.</p>
          <dl>
            <dt>VRDE</dt><dd>${esc(String(vm.vrde_enabled || 'off'))}</dd>
            <dt>Port</dt><dd>${esc(port)}</dd>
            <dt>Host IPs</dt><dd>${esc(ips.join(', ') || '-')}</dd>
          </dl>
          <pre>mstsc /v:${esc(primaryIp)}:${esc(port)}
xfreerdp /v:${esc(primaryIp)}:${esc(port)}</pre>
        </div>
        <div class="guide-card">
          <h3>Browser gateway</h3>
          ${gateway ? `<p>Gateway configured: <a href="${esc(gateway)}" target="_blank" rel="noreferrer">${esc(gateway)}</a></p>` : '<p>No gateway is configured. Use VRDE/RDP now, or deploy the optional Docker gateway later.</p>'}
          <p>Latest screenshot: ${screenshot ? esc(screenshot.result || screenshot.stdout || screenshot.status) : 'not captured yet'}</p>
        </div>
      </div>
      <div class="actions">
        ${buttonHtml(hostname, vmName, 'vm_enable_vrde', 'Enable VRDE', 'primary', { ...vmIdentity(vm), port: Number(port) || 3389 })}
        ${buttonHtml(hostname, vmName, 'vm_screenshot', 'Capture screenshot', 'ghost', { ...vmIdentity(vm), path: `/var/lib/vmange/screenshots/${vmName}.png` })}
      </div>`);
    bindCommands();
  }

  function openVmDetail(hostname, vmName) {
    const host = hostByName(hostname);
    const vm = vmByName(host, vmName);
    if (!host || !vm) return toast('VM details are no longer available. Refresh the page.', 'error');
    const status = vmRuntimeInfo(host, vm);
    infoDialog(`${vmName} details`, `
      <div class="vm-detail-tabs">
        <section><h3>Summary</h3><p>Status: <strong>${esc(status.status)}</strong> (${esc(status.source)})</p><p>UUID: ${esc(vm.uuid || '-')}</p><p>OS: ${esc(vm.os || '-')}</p><p>Session: ${esc(vm.session_state || '-')}</p></section>
        <section><h3>Power</h3><div class="actions">${vmActions(host, vm)}</div></section>
        <section><h3>Snapshots</h3>${(vm.snapshots || []).map((snap) => `<p>${esc(snap.name || '-')} <span class="muted">${esc(snap.uuid || '')}</span></p>`).join('') || '<p class="muted">No snapshots reported.</p>'}</section>
        <section><h3>Storage</h3>${(vm.storage || []).map((item) => `<p>${esc(item.name || '-')} ${esc(item.type || '')} ${esc(item.path || '')}</p>`).join('') || '<p class="muted">No storage details reported.</p>'}</section>
        <section><h3>Network</h3>${(vm.nics || []).map((nic) => `<p>Adapter ${esc(nic.adapter)}: ${esc(nic.mode || '-')} ${esc(nic.bridge || '')}</p>`).join('') || '<p class="muted">No network adapters reported.</p>'}</section>
        <section><h3>Logs</h3><div class="actions">${buttonHtml(hostname, vmName, 'vm_logs_list', 'List logs', 'ghost', vmIdentity(vm))}${buttonHtml(hostname, vmName, 'vm_log_tail', 'Tail VBox.log', 'ghost', { ...vmIdentity(vm), file: 'VBox.log', lines: 200 })}</div></section>
        <section><h3>Console</h3><div class="actions"><button class="btn ghost" data-console-guide="${esc(vmName)}" data-host="${esc(hostname)}">Open console guide</button></div></section>
      </div>`);
    bindCommands();
  }

  function bindVmExtras() {
    document.querySelectorAll('[data-console-guide]').forEach((button) => {
      button.addEventListener('click', () => openConsoleGuide(button.dataset.host, button.dataset.consoleGuide));
    });
    document.querySelectorAll('[data-vm-expand]').forEach((button) => {
      button.addEventListener('click', () => {
        const key = `${button.dataset.host}:${button.dataset.vmExpand}`;
        state.expandedVmKey = state.expandedVmKey === key ? '' : key;
        render();
      });
    });
  }

  function bindStatusInfo() {
    document.querySelectorAll('[data-status-detail]').forEach((button) => {
      button.addEventListener('click', (event) => {
        event.stopPropagation();
        toast(button.dataset.statusDetail || 'No additional detail is available.', 'info');
      });
    });
  }

  function bindCommands() {
    document.querySelectorAll('[data-command]').forEach((button) => {
      button.addEventListener('click', async () => {
        let queueHost = button.dataset.host;
        let payload = button.dataset.payload || '';
        if (payload) {
          const modalPayload = await openActionModal(button);
          if (modalPayload === null) return;
          payload = modalPayload;
        }
        if (button.dataset.command === 'vm_create' && payload) {
          try {
            const parsed = JSON.parse(payload);
            if (parsed && parsed.__host) {
              queueHost = String(parsed.__host);
              delete parsed.__host;
              payload = JSON.stringify(parsed);
            }
          } catch {}
        }
        queueCommand(queueHost, button.dataset.command, button.dataset.target, payload);
      });
    });
    bindVmExtras();
    bindStatusInfo();
  }

  function empty(message) {
    return `<div class="empty-state">${esc(message)}</div>`;
  }

  function tableEmpty(cols) {
    return `<tr><td colspan="${cols}"><div class="empty-state">No records to display.</div></td></tr>`;
  }

  function drawFleetChart(hosts) {
    const ctx = document.getElementById('fleet-chart');
    if (!ctx || !window.Chart) return;
    chart(ctx, {
      type: 'bar',
      data: {
        labels: hosts.map((host) => host.hostname),
        datasets: [
          { label: 'CPU %', data: hosts.map((host) => host.metrics?.cpu || 0), backgroundColor: palette.blue },
          { label: 'RAM %', data: hosts.map((host) => pct(host.metrics?.ram_used_mb, host.metrics?.ram_total_mb)), backgroundColor: palette.green },
          { label: 'Swap %', data: hosts.map((host) => pct(host.metrics?.swap_used_mb, host.metrics?.swap_total_mb)), backgroundColor: palette.amber },
          { label: 'Disk %', data: hosts.map((host) => pct(host.metrics?.disk_used_mb, host.metrics?.disk_total_mb)), backgroundColor: palette.orange },
        ],
      },
      options: chartOptions(),
    });
  }

  function drawHostChart(host) {
    const ctx = document.getElementById('host-chart');
    if (!ctx || !window.Chart) return;
    const history = graphHistory(host);
    chart(ctx, {
      type: 'line',
      data: {
        labels: history.map((row) => row.time),
        datasets: [
          { label: 'CPU %', data: history.map((row) => row.cpu), borderColor: palette.blue, backgroundColor: 'rgba(11,137,232,.12)', tension: .35 },
          { label: 'RAM %', data: history.map((row) => row.ram), borderColor: palette.green, backgroundColor: 'rgba(37,168,90,.12)', tension: .35 },
          { label: 'Swap %', data: history.map((row) => row.swap || 0), borderColor: palette.amber, backgroundColor: 'rgba(255,176,32,.12)', tension: .35 },
          { label: 'RX MB', data: history.map((row) => Math.round((row.rx || 0) / 1024 / 1024)), borderColor: palette.teal, tension: .35 },
          { label: 'TX MB', data: history.map((row) => Math.round((row.tx || 0) / 1024 / 1024)), borderColor: palette.orange, tension: .35 },
        ],
      },
      options: chartOptions(),
    });
  }

  function applySidebarState() {
    shell?.classList.toggle('sidebar-collapsed', state.sidebarCollapsed);
    const button = document.getElementById('sidebar-toggle');
    if (button) {
      button.textContent = state.sidebarCollapsed ? '>' : '<';
      button.setAttribute('aria-label', state.sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar');
      button.title = state.sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar';
    }
  }

  function setMobileNav(open) {
    shell?.classList.toggle('mobile-nav-open', Boolean(open));
  }

  function chart(canvas, config) {
    const id = canvas.id;
    if (state.charts.has(id)) state.charts.get(id).destroy();
    state.charts.set(id, new Chart(canvas, config));
  }

  function chartOptions() {
    return {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { labels: { color: getComputedStyle(document.documentElement).getPropertyValue('--muted') } } },
      scales: {
        x: { ticks: { color: getComputedStyle(document.documentElement).getPropertyValue('--muted') }, grid: { color: 'rgba(147,169,189,.12)' } },
        y: { ticks: { color: getComputedStyle(document.documentElement).getPropertyValue('--muted') }, grid: { color: 'rgba(147,169,189,.12)' }, beginAtZero: true },
      },
    };
  }

  document.getElementById('theme-toggle')?.addEventListener('click', () => {
    setTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark');
    render();
  });

  document.getElementById('sidebar-toggle')?.addEventListener('click', () => {
    state.sidebarCollapsed = !state.sidebarCollapsed;
    localStorage.setItem('vmange-sidebar', state.sidebarCollapsed ? 'collapsed' : 'expanded');
    applySidebarState();
    setTimeout(() => render(), 180);
  });

  document.getElementById('mobile-nav-toggle')?.addEventListener('click', () => setMobileNav(true));
  document.getElementById('nav-backdrop')?.addEventListener('click', () => setMobileNav(false));
  document.querySelectorAll('.sidebar nav a').forEach((link) => link.addEventListener('click', () => setMobileNav(false)));

  helpButton?.addEventListener('click', () => {
    window.location.href = `docs.php?page=${encodeURIComponent(helpSlugForView())}`;
  });

  search?.addEventListener('input', () => {
    state.search = search.value;
    render();
  });

  const enrollInput = document.getElementById('enroll-hostname');
  document.getElementById('enroll-create')?.addEventListener('click', (event) => {
    event.preventDefault();
    if (!enrollInput.reportValidity()) return;
    createEnrollment(enrollInput.value.trim());
  });

  enrollInput?.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    if (!enrollInput.reportValidity()) return;
    createEnrollment(enrollInput.value.trim());
  });

  document.getElementById('enroll-cancel')?.addEventListener('click', () => {
    enrollModal?.close();
  });

  wireDialogDismiss(modal, { backdrop: false });
  wireDialogDismiss(enrollModal);
  wireDialogDismiss(actionModal);

  window.addEventListener('hashchange', render);
  setTheme(localStorage.getItem('vmange-theme') || 'dark');
  applySidebarState();
  fetchDashboard(true).catch((error) => {
    root.innerHTML = empty(error.message);
    toast(error.message, 'error');
  });
  setInterval(() => fetchDashboard().catch(() => {}), 15000);
})();
