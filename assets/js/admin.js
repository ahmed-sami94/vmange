(() => {
  const root = document.getElementById('admin-content');
  const message = document.getElementById('admin-message');
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const field = (name, label, type='text', value='') => `<label>${esc(label)}<input name="${esc(name)}" type="${type}" value="${esc(value)}" required></label>`;
  let model, dashboard, importDigest='';
  function notify(text, failed=false) { message.textContent = text; message.className = failed ? 'notice failed' : 'notice'; }
  async function request(action, values={}, raw=false) {
    const form = values instanceof FormData ? values : new FormData();
    if (!(values instanceof FormData)) Object.entries(values).forEach(([key,value]) => form.set(key,value));
    form.set('csrf',csrf);
    const response = await fetch(`index.php?ajax=${action}`, {method:'POST',body:form});
    if (raw && response.ok) return response.blob();
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.error || 'Request failed');
    return result;
  }
  async function refresh() {
    model = await request('management-status');
    const response = await fetch('index.php?ajax=dashboard');
    if (!response.ok) throw new Error('Dashboard data unavailable');
    dashboard = await response.json();
    render();
  }
  const hostOptions = () => (dashboard.hosts || []).map(host => `<label class="check"><input type="checkbox" name="host" value="${esc(host.hostname)}">${esc(host.hostname)} <span class="muted">${esc(host.metrics?.agent_version || 'unknown')}</span></label>`).join('');
  const table = (headers, rows) => `<div class="table-wrap"><table class="responsive-table"><thead><tr>${headers.map(h=>`<th>${esc(h)}</th>`).join('')}</tr></thead><tbody>${rows.length ? rows.map(cells=>`<tr>${cells.map((cell,i)=>`<td data-label="${esc(headers[i])}">${cell}</td>`).join('')}</tr>`).join('') : `<tr><td colspan="${headers.length}">No records</td></tr>`}</tbody></table></div>`;
  function render() {
    const tab = location.hash.slice(1) || 'security';
    document.querySelectorAll('.admin-tabs a').forEach(link => link.setAttribute('aria-current', String(link.hash === '#'+tab)));
    if (tab === 'backups') {
      root.innerHTML = `<h1>Configuration portability</h1><div class="admin-columns"><section><h2>Export</h2><form id="export-form"><label>Format<select name="mode"><option value="redacted">Redacted JSON</option><option value="encrypted">Encrypted backup</option></select></label><label>Backup passphrase<input name="passphrase" type="password" minlength="16" autocomplete="new-password"></label><label class="check"><input name="include_secrets" type="checkbox" value="1">Include integration credentials (encrypted only)</label><p class="muted">Redacted exports omit script and Compose bodies. Accounts, host credentials, command queues, metrics and release artifacts are never exported.</p><button class="btn primary">Download backup</button></form></section><section><h2>Import</h2><form id="import-form"><label>Backup file<input name="backup" type="file" accept="application/json,.json" required></label><label>Passphrase for encrypted backup<input name="passphrase" type="password" autocomplete="off"></label><label>Conflicts<select name="conflict"><option value="keep">Keep existing records</option><option value="replace">Replace matching records</option></select></label><div class="actions"><button class="btn ghost" name="intent" value="preview">Validate and preview</button><button class="btn primary" name="intent" value="apply" disabled>Apply preview</button></div></form><div id="import-preview"></div></section></div>`;
      document.getElementById('export-form').addEventListener('submit', async event => {
        event.preventDefault();
        await busy(event.submitter, async () => {
          const blob = await request('management-export',new FormData(event.target),true);
          const url = URL.createObjectURL(blob), link = document.createElement('a');
          link.href=url; link.download=`vmange-config-${Date.now()}.json`; link.click();
          setTimeout(()=>URL.revokeObjectURL(url),10000); event.target.reset(); notify('Backup downloaded.');
        });
      });
      const form = document.getElementById('import-form');
      form.addEventListener('input', () => { importDigest=''; form.querySelector('[value="apply"]').disabled=true; });
      form.addEventListener('submit', async event => {
        event.preventDefault(); const apply = event.submitter.value==='apply';
        if (apply && !confirm('Apply this preview? Existing records follow your selected conflict policy. No host commands will run.')) return;
        await busy(event.submitter, async () => {
          const data = new FormData(form); data.set('digest',importDigest);
          const result = await request('management-import-'+(apply?'apply':'preview'),data);
          if (apply) { form.reset(); importDigest=''; notify('Configuration imported. No commands were queued.'); }
          else { importDigest=result.digest; document.getElementById('import-preview').innerHTML=table(['Section','Record','Change'],result.preview.map(row=>[esc(row.section),esc(row.identity),esc(row.status)])); }
          form.querySelector('[value="apply"]').disabled=apply;
        });
      });
      return;
    }
    if (tab === 'releases') {
      root.innerHTML = `<h1>Agent releases</h1>${table(['Version','Availability','Requirements','Integrity','Action'],model.releases.map(release=>[esc(release.version),esc(release.status || 'bundled'),`Server ${esc(release.min_server || 'v2.0.0')} / Agent ${esc(release.min_agent || 'v1.7.0')}`,`<details><summary>SHA-256 / Notes</summary><code class="checksum">${esc(release.sha256)}</code><ul>${(release.notes||[]).map(note=>`<li>${esc(note)}</li>`).join('')}</ul></details>`,release.status==='draft'?`<button class="btn primary" data-publish="${esc(release.version)}">Publish</button>`:'Available for rollout']))}<section class="admin-section"><h2>Upload immutable release</h2><form id="release-form" class="admin-form">${field('version','Version','text','v2.0.1')}${field('min_server','Minimum server','text','v2.0.0')}${field('min_agent','Minimum installed agent','text','v2.0.0')}<label>Agent artifact (.sh, LF, up to 512 KB)<input name="artifact" type="file" accept=".sh" required></label><label class="span-all">Release notes<textarea name="notes" rows="4" maxlength="16000" required></textarea></label><button class="btn primary">Upload draft</button></form></section>`;
      document.getElementById('release-form').addEventListener('submit',event=>{event.preventDefault(); busy(event.submitter,async()=>{await request('management-release-upload',new FormData(event.target));notify('Draft uploaded. Publication and installation are separate actions.');await refresh();});});
      root.querySelectorAll('[data-publish]').forEach(button=>button.addEventListener('click',()=>busy(button,async()=>{if(!confirm(`Publish ${button.dataset.publish}? The artifact cannot be replaced.`))return;await request('management-release-publish',{version:button.dataset.publish});await refresh();notify('Release published; no hosts upgraded.');})));
      return;
    }
    if (tab === 'installed') {
      root.innerHTML=`<h1>Installed agents</h1>${table(['Host','Reported version','Last seen','Connection'],(dashboard.hosts||[]).map(host=>[esc(host.hostname),esc(host.metrics?.agent_version||'unknown'),esc(host.last_seen||'never'),esc(host.online?'Online':'Offline')]))}<section class="admin-section"><h2>Selected-host rollout</h2><form id="rollout-form"><label>Published release<select name="version">${model.releases.filter(r=>r.status!=='draft').map(r=>`<option value="${esc(r.version)}">${esc(r.version)}</option>`).join('')}</select></label><fieldset><legend>Hosts</legend>${hostOptions()}</fieldset><p class="muted">Upgrade one canary first. Batch installation requires a confirmed canary of the same release. Reported version acknowledgement determines completion.</p><button class="btn primary">Queue selected release</button></form></section>`;
      document.getElementById('rollout-form').addEventListener('submit',event=>{event.preventDefault();busy(event.submitter,async()=>{const form=new FormData(event.target),hosts=form.getAll('host');if(!hosts.length)throw new Error('Select at least one host');if(!confirm(`Install ${form.get('version')} on ${hosts.join(', ')}?`))return;const result=await request('management-rollout',{version:form.get('version'),hosts:JSON.stringify(hosts)});notify(result.outcomes.map(row=>`${row.hostname}: ${row.message||row.status}`).join('; '),result.outcomes.some(r=>r.status==='failed'));});});
      return;
    }
    if (tab === 'rollouts') {
      root.innerHTML=`<div class="panel-head"><h1>Rollouts</h1><button id="rollout-refresh" class="icon-btn" title="Refresh rollouts" aria-label="Refresh rollouts">&#8635;</button></div>${table(['Host','Version','State','Details','Created'],model.rollouts.map(row=>[esc(row.hostname),esc(row.version),esc(row.status),esc(row.message||'Awaiting host'),esc(row.created_at)]))}`;
      document.getElementById('rollout-refresh').addEventListener('click',event=>busy(event.currentTarget,refresh)); return;
    }
    const security=model.security;
    root.innerHTML=`<h1>Security readiness</h1><dl class="readiness"><div><dt>Connection</dt><dd>${security.https?'Verified HTTPS request':'HTTPS required before production'}</dd></div><div><dt>Encryption key</dt><dd>${security.encryption_ready?'Configured':'Missing or invalid'}</dd></div><div><dt>Encrypted backup support</dt><dd>${security.sodium?'Sodium available':'Install PHP Sodium'}</dd></div></dl><h2>Host credentials</h2>${table(['Host','Credential state','Last heartbeat','Actions'],security.hosts.map(host=>[esc(host.hostname),host.revoked==='1'?'Blocked':host.credential_ready==='1'?'Active hash stored':'Re-enrollment required',esc(host.last_seen||'never'),`<button class="btn ghost" data-rotate="${esc(host.hostname)}">Rotate</button> <button class="btn danger" data-revoke="${esc(host.hostname)}">Revoke</button>`]))}<section class="admin-section"><h2>Stored integration secrets</h2>${table(['Setting','Storage'],security.secrets.map(secret=>[esc(secret.name),esc(secret.status)]))}<button id="migrate-secrets" class="btn ghost">Encrypt legacy stored secrets</button></section><section class="admin-section"><h2>Recent rejected agent requests</h2>${table(['Host','Time'],security.failures.map(row=>[esc(row.target),esc(row.created_at)]))}</section>`;
    root.querySelectorAll('[data-revoke]').forEach(button=>button.addEventListener('click',()=>busy(button,async()=>{if(!confirm(`Revoke ${button.dataset.revoke}? Its agent will be rejected until re-enrolled.`))return;await request('management-revoke',{hostname:button.dataset.revoke});await refresh();notify('Host credentials revoked.');})));
    root.querySelectorAll('[data-rotate]').forEach(button=>button.addEventListener('click',()=>busy(button,async()=>{if(!confirm(`Rotate ${button.dataset.rotate}? You must update its host configuration immediately.`))return;const result=await request('rotate-token',{hostname:button.dataset.rotate});document.getElementById('new-credential').textContent=`VMANGE_TOKEN="${result.token}"`;document.getElementById('admin-dialog').showModal();await refresh();})));
    document.getElementById('migrate-secrets').addEventListener('click',event=>busy(event.currentTarget,async()=>{await request('management-migrate-secrets');await refresh();notify('Stored legacy integration secrets encrypted.');}));
  }
  async function busy(button, callback) {
    button.disabled=true;
    try { await callback(); } catch(error) { notify(error.message,true); }
    finally { if(button.isConnected)button.disabled=false; }
  }
  const dialog=document.getElementById('admin-dialog');
  dialog.addEventListener('close',()=>{document.getElementById('new-credential').textContent='';});
  dialog.addEventListener('click',event=>{if(event.target===dialog)dialog.close();});
  document.documentElement.dataset.theme=localStorage.getItem('vmange-theme')||'light';
  document.getElementById('admin-theme').addEventListener('click',()=>{const theme=document.documentElement.dataset.theme==='dark'?'light':'dark';document.documentElement.dataset.theme=theme;localStorage.setItem('vmange-theme',theme);});
  window.addEventListener('hashchange',()=>{importDigest='';if(model)render();});
  refresh().catch(error=>notify(error.message,true));
})();
