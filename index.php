<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const VMANGE_LATEST_AGENT_VERSION = 'v1.6.2';

$config = app_config();
secure_session_start();

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self'; img-src 'self' data:; connect-src 'self'; base-uri 'self'; form-action 'self'");
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if ($config['force_https'] && !is_https()) {
    http_response_code(403);
    exit('HTTPS is required for this deployment.');
}

if (isset($_GET['logout'])) {
    audit_log('logout', $_SESSION['vbox_user'] ?? null);
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
}

function latest_agent_version(): string
{
    $manifest = __DIR__ . '/assets/agent/version.json';
    if (is_file($manifest)) {
        $data = json_decode((string) file_get_contents($manifest), true);
        if (is_array($data) && !empty($data['version']) && is_string($data['version'])) {
            return $data['version'];
        }
    }
    return VMANGE_LATEST_AGENT_VERSION;
}

function asset_url(string $path): string
{
    $fullPath = __DIR__ . '/' . ltrim($path, '/');
    $version = is_file($fullPath) ? (string) filemtime($fullPath) : latest_agent_version();
    return $path . '?v=' . rawurlencode($version);
}

function latest_metrics(string $hostname, array $fallback): array
{
    if (table_exists('vbox_metrics')) {
        $stmt = db()->prepare('SELECT * FROM vbox_metrics WHERE hostname=? ORDER BY id DESC LIMIT 1');
        $stmt->bind_param('s', $hostname);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            $latest = [
                'cpu' => (float) $row['cpu_percent'],
                'load1' => (float) $row['load1'],
                'ram_used_mb' => (int) $row['ram_used_mb'],
                'ram_total_mb' => (int) $row['ram_total_mb'],
                'swap_used_mb' => (int) ($row['swap_used_mb'] ?? 0),
                'swap_total_mb' => (int) ($row['swap_total_mb'] ?? 0),
                'disk_used_mb' => (int) $row['disk_used_mb'],
                'disk_total_mb' => (int) $row['disk_total_mb'],
                'rx_bytes' => (int) $row['rx_bytes'],
                'tx_bytes' => (int) $row['tx_bytes'],
                'created_at' => $row['created_at'],
            ];
            foreach (['ips', 'interfaces', 'preferred_wol_mac', 'kernel', 'uptime_seconds', 'agent_version', 'vboxmanage_bin', 'running_vm_names', 'running_vm_uuids'] as $key) {
                if (array_key_exists($key, $fallback)) {
                    $latest[$key] = $fallback[$key];
                }
            }
            foreach (['ram_total_mb', 'disk_total_mb', 'rx_bytes', 'tx_bytes'] as $key) {
                if (($latest[$key] ?? 0) <= 0 && ($fallback[$key] ?? 0) > 0) {
                    $latest[$key] = $fallback[$key];
                }
            }
            foreach (['ram_used_mb', 'swap_used_mb', 'swap_total_mb', 'disk_used_mb', 'cpu', 'load1'] as $key) {
                if (($latest[$key] ?? 0) <= 0 && isset($fallback[$key])) {
                    $latest[$key] = $fallback[$key];
                }
            }
            return $latest;
        }
    }
    return $fallback;
}

function first_string_value(array $row, array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }
        $value = trim((string) $row[$key]);
        if ($value !== '') {
            return $value;
        }
    }
    return $default;
}

function normalize_container_inventory($containers): array
{
    if (!is_array($containers)) {
        return [];
    }

    $normalized = [];
    foreach ($containers as $container) {
        if (!is_array($container)) {
            continue;
        }

        $name = first_string_value($container, ['name', 'names', 'Names']);
        $image = first_string_value($container, ['image', 'Image']);
        $status = first_string_value($container, ['status', 'Status']);
        $state = strtolower(first_string_value($container, ['state', 'State']));
        $ports = first_string_value($container, ['ports', 'Ports']);
        $id = first_string_value($container, ['id', 'ID', 'ContainerID', 'container_id']);
        $command = first_string_value($container, ['command', 'Command']);
        $created = first_string_value($container, ['created_at', 'CreatedAt', 'Created']);

        if ($state === '') {
            $statusLower = strtolower($status);
            if (str_contains($statusLower, 'paused')) {
                $state = 'paused';
            } elseif (str_contains($statusLower, 'up')) {
                $state = 'running';
            } elseif (str_contains($statusLower, 'restarting')) {
                $state = 'restarting';
            } elseif ($statusLower !== '') {
                $state = 'stopped';
            } else {
                $state = 'unknown';
            }
        }

        if ($name === '' && $id !== '') {
            $name = $id;
        }

        $normalized[] = [
            'id' => $id,
            'name' => $name,
            'image' => $image,
            'status' => $status !== '' ? $status : $state,
            'state' => $state,
            'ports' => $ports,
            'command' => $command,
            'created_at' => $created,
            'raw' => $container,
        ];
    }

    return $normalized;
}

function normalize_image_inventory($images): array
{
    if (!is_array($images)) {
        return [];
    }

    $normalized = [];
    foreach ($images as $image) {
        if (!is_array($image)) {
            continue;
        }

        $normalized[] = [
            'repository' => first_string_value($image, ['repository', 'Repository'], '-'),
            'tag' => first_string_value($image, ['tag', 'Tag'], '-'),
            'id' => first_string_value($image, ['id', 'ID', 'ImageID']),
            'size' => first_string_value($image, ['size', 'Size']),
            'created_since' => first_string_value($image, ['CreatedSince', 'created_since']),
            'raw' => $image,
        ];
    }

    return $normalized;
}

function metric_history(string $hostname, array $fallback = []): array
{
    $fallbackRow = $fallback ? [[
        'cpu' => (float) ($fallback['cpu'] ?? 0),
        'load1' => (float) ($fallback['load1'] ?? 0),
        'ram' => (int) ($fallback['ram_total_mb'] ?? 0) > 0 ? round(((int) ($fallback['ram_used_mb'] ?? 0) / (int) $fallback['ram_total_mb']) * 100, 1) : 0,
        'swap' => (int) ($fallback['swap_total_mb'] ?? 0) > 0 ? round(((int) ($fallback['swap_used_mb'] ?? 0) / (int) $fallback['swap_total_mb']) * 100, 1) : 0,
        'rx' => (int) ($fallback['rx_bytes'] ?? 0),
        'tx' => (int) ($fallback['tx_bytes'] ?? 0),
        'time' => 'now',
    ]] : [];
    if (!table_exists('vbox_metrics')) {
        return $fallbackRow;
    }
    $swapSelect = column_exists('vbox_metrics', 'swap_used_mb') && column_exists('vbox_metrics', 'swap_total_mb')
        ? 'swap_used_mb, swap_total_mb'
        : '0 AS swap_used_mb, 0 AS swap_total_mb';
    $stmt = db()->prepare("SELECT cpu_percent, load1, ram_used_mb, ram_total_mb, $swapSelect, rx_bytes, tx_bytes, created_at FROM vbox_metrics WHERE hostname=? ORDER BY id DESC LIMIT 80");
    $stmt->bind_param('s', $hostname);
    $stmt->execute();
    $rows = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'cpu' => (float) $row['cpu_percent'],
            'load1' => (float) $row['load1'],
            'ram' => (int) $row['ram_total_mb'] > 0 ? round(((int) $row['ram_used_mb'] / (int) $row['ram_total_mb']) * 100, 1) : 0,
            'swap' => (int) $row['swap_total_mb'] > 0 ? round(((int) $row['swap_used_mb'] / (int) $row['swap_total_mb']) * 100, 1) : 0,
            'rx' => (int) $row['rx_bytes'],
            'tx' => (int) $row['tx_bytes'],
            'time' => $row['created_at'],
        ];
    }
    $rows = array_reverse($rows);
    return $rows ?: $fallbackRow;
}

function ensure_host_tokens_table(): void
{
    db()->query("
        CREATE TABLE IF NOT EXISTS vbox_host_tokens (
            id int(11) NOT NULL AUTO_INCREMENT,
            hostname varchar(100) NOT NULL,
            token_hash char(64) NOT NULL,
            active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime DEFAULT current_timestamp(),
            rotated_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY host_active (hostname, active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function ensure_user_role_column(): void
{
    if (column_exists('vbox_users', 'role')) {
        return;
    }
    try {
        db()->query("ALTER TABLE vbox_users ADD COLUMN role enum('admin','operator','viewer') NOT NULL DEFAULT 'admin'");
    } catch (Throwable $e) {
        error_log('VMange could not add user role column: ' . $e->getMessage());
    }
}

function users_payload(): array
{
    if (current_user_role() !== 'admin') {
        return [];
    }
    $users = [];
    $select = column_exists('vbox_users', 'role')
        ? 'id, username, role, created_at'
        : "id, username, 'admin' AS role, created_at";
    $result = db()->query("SELECT $select FROM vbox_users ORDER BY username");
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    return $users;
}

function validated_command_payload(string $action, string $payload): string
{
    $payload = trim($payload);
    if ($payload === '') {
        return '';
    }
    $rawTextActions = ['compose_deploy', 'dockerfile_deploy', 'script_run', 'terminal_exec'];
    if (in_array($action, $rawTextActions, true) && substr($payload, 0, 1) !== '{') {
        return $payload;
    }
    $data = json_decode($payload, true);
    if (!is_array($data)) {
        if (in_array($action, $rawTextActions, true)) {
            return $payload;
        }
        throw new InvalidArgumentException('Command payload must be valid JSON');
    }
    $allowedKeys = [
        'snapshot_create' => ['name'],
        'snapshot_restore' => ['snapshot'],
        'snapshot_delete' => ['snapshot'],
        'vm_clone' => ['name', 'mode'],
        'vm_delete' => ['delete_files'],
        'vm_set_resources' => ['cpu', 'ram_mb', 'vram_mb'],
        'vm_set_boot_order' => ['boot1', 'boot2', 'boot3', 'boot4'],
        'vm_set_description' => ['description'],
        'vm_set_autostart' => ['enabled'],
        'vm_attach_iso' => ['controller', 'port', 'device', 'path'],
        'vm_detach_iso' => ['controller', 'port', 'device'],
        'vm_attach_disk' => ['controller', 'port', 'device', 'path'],
        'vm_create_disk' => ['path', 'size_mb', 'format', 'controller', 'port', 'device'],
        'vm_resize_disk' => ['path', 'size_mb'],
        'vm_set_network' => ['adapter', 'mode', 'bridge', 'hostonly', 'intnet'],
        'vm_cable_connected' => ['adapter', 'connected'],
        'vm_export' => ['path'],
        'vm_import' => ['path'],
        'vm_create' => ['name', 'ostype', 'cpu', 'ram_mb', 'vram_mb', 'disk_size_mb', 'disk_path', 'controller', 'iso_path', 'network_mode', 'start', 'unattended', 'hostname', 'username', 'password', 'full_name', 'ssh_key', 'timezone', 'locale'],
        'vm_enable_vrde' => ['port'],
        'vm_disable_vrde' => [],
        'vm_screenshot' => ['path'],
        'vm_logs_list' => [],
        'vm_log_tail' => ['file', 'lines'],
        'compose_deploy' => ['compose_yaml'],
        'dockerfile_deploy' => ['dockerfile'],
        'script_run' => ['script_id', 'body'],
        'terminal_exec' => ['command'],
        'host_wol_send' => ['target_host', 'mac', 'broadcast', 'port'],
    ];
    $vmIdentityActions = [
        'start', 'stop', 'poweroff', 'pause', 'resume', 'reset', 'restart', 'refresh_inventory',
        'snapshot_create', 'snapshot_restore', 'snapshot_delete', 'vm_clone', 'vm_delete',
        'vm_set_resources', 'vm_set_boot_order', 'vm_set_description', 'vm_set_autostart',
        'vm_attach_iso', 'vm_detach_iso', 'vm_attach_disk', 'vm_create_disk', 'vm_resize_disk',
        'vm_set_network', 'vm_cable_connected', 'vm_export', 'vm_enable_vrde', 'vm_disable_vrde',
        'vm_screenshot', 'vm_logs_list', 'vm_log_tail',
    ];
    foreach ($vmIdentityActions as $vmAction) {
        if (!isset($allowedKeys[$vmAction])) {
            $allowedKeys[$vmAction] = [];
        }
        $allowedKeys[$vmAction] = array_values(array_unique(array_merge($allowedKeys[$vmAction], ['vm_uuid', 'vm_name'])));
    }
    if (!isset($allowedKeys[$action])) {
        return $payload;
    }
    foreach (array_keys($data) as $key) {
        if (!in_array((string) $key, $allowedKeys[$action], true)) {
            throw new InvalidArgumentException('Unsupported payload field: ' . $key);
        }
    }
    return json_encode($data, JSON_UNESCAPED_SLASHES);
}

function validate_project_name(string $value): string
{
    $value = trim($value);
    if (!preg_match('/^[A-Za-z0-9._-]{1,100}$/', $value)) {
        throw new InvalidArgumentException('Invalid project name');
    }
    return $value;
}

function validate_script_name(string $value): string
{
    $value = trim($value);
    if (!preg_match('/^[A-Za-z0-9._ -]{1,100}$/', $value)) {
        throw new InvalidArgumentException('Invalid script name');
    }
    return $value;
}

function validate_wol_mac(string $value): string
{
    $value = strtolower(trim($value));
    if (!preg_match('/^([0-9a-f]{2}[:-]){5}[0-9a-f]{2}$/', $value)) {
        throw new InvalidArgumentException('Wake-on-LAN MAC must use aa:bb:cc:dd:ee:ff format');
    }
    return str_replace('-', ':', $value);
}

function validate_wol_broadcast(string $value): string
{
    $value = trim($value);
    if ($value === '' || !filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        throw new InvalidArgumentException('Wake-on-LAN broadcast must be a valid IPv4 address');
    }
    return $value;
}

function validate_wol_port($value): int
{
    $port = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    if ($port === false) {
        throw new InvalidArgumentException('Wake-on-LAN port must be between 1 and 65535');
    }
    return (int) $port;
}

function rename_host_everywhere(string $oldHostname, string $newHostname): void
{
    $conn = db();
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('UPDATE vbox_hosts SET hostname=? WHERE hostname=?');
        $stmt->bind_param('ss', $newHostname, $oldHostname);
        $stmt->execute();

        $updates = [
            ['vbox_commands', 'hostname'],
            ['vbox_metrics', 'hostname'],
            ['vbox_host_tokens', 'hostname'],
            ['vbox_host_blocks', 'hostname'],
            ['vbox_compose_stacks', 'hostname'],
            ['vbox_script_runs', 'hostname'],
        ];
        foreach ($updates as [$table, $column]) {
            if (!table_exists($table) || !column_exists($table, $column)) {
                continue;
            }
            $stmt = $conn->prepare("UPDATE `$table` SET `$column`=? WHERE `$column`=?");
            $stmt->bind_param('ss', $newHostname, $oldHostname);
            $stmt->execute();
        }

        if (table_exists('vbox_hosts') && column_exists('vbox_hosts', 'wol_relay_host')) {
            $stmt = $conn->prepare('UPDATE vbox_hosts SET wol_relay_host=? WHERE wol_relay_host=?');
            $stmt->bind_param('ss', $newHostname, $oldHostname);
            $stmt->execute();
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function saved_stacks_payload(): array
{
    if (!table_exists('vbox_compose_stacks')) {
        return [];
    }
    $rows = [];
    $result = db()->query('SELECT id, hostname, project, compose_yaml, status, last_action, last_result, updated_at, created_at FROM vbox_compose_stacks ORDER BY hostname, project');
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    return $rows;
}

function scripts_payload(): array
{
    if (!table_exists('vbox_scripts')) {
        return [];
    }
    $rows = [];
    $result = db()->query('SELECT id, name, description, body, created_by, updated_at, created_at FROM vbox_scripts ORDER BY name');
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    return $rows;
}

function script_runs_payload(): array
{
    if (!table_exists('vbox_script_runs')) {
        return [];
    }
    $rows = [];
    $result = db()->query('SELECT r.id, r.script_id, s.name AS script_name, r.hostname, r.command_id, r.status, r.result, r.created_at, r.updated_at FROM vbox_script_runs r LEFT JOIN vbox_scripts s ON s.id=r.script_id ORDER BY r.id DESC LIMIT 50');
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    return $rows;
}

function alarms_payload(): array
{
    if (!table_exists('vbox_alarm_events') || !table_exists('vbox_alarm_rules')) {
        return ['rules' => [], 'events' => [], 'active' => 0, 'unread' => 0];
    }
    $rules = [];
    $result = db()->query('SELECT id, name, metric, operator, threshold, enabled, notify_email, cooldown_minutes, created_at, updated_at FROM vbox_alarm_rules ORDER BY name');
    while ($row = $result->fetch_assoc()) {
        $rules[] = $row;
    }
    $events = [];
    $result = db()->query('SELECT e.id, e.rule_id, r.name AS rule_name, e.hostname, e.metric_value, e.status, e.message, e.opened_at, e.resolved_at, e.acknowledged_at, e.last_notified_at FROM vbox_alarm_events e LEFT JOIN vbox_alarm_rules r ON r.id=e.rule_id ORDER BY e.id DESC LIMIT 200');
    while ($row = $result->fetch_assoc()) {
        $events[] = $row;
    }
    $active = 0;
    $unread = 0;
    foreach ($events as $event) {
        if (($event['status'] ?? '') === 'active') {
            $active++;
        }
        if (($event['status'] ?? '') === 'active' && empty($event['acknowledged_at'])) {
            $unread++;
        }
    }
    return compact('rules', 'events', 'active', 'unread');
}

function mail_settings_payload(): array
{
    $cfg = app_config();
    return [
        'mail_from' => setting_value('mail_from', (string) $cfg['mail_from']),
        'smtp_host' => setting_value('smtp_host', (string) $cfg['smtp_host']),
        'smtp_port' => setting_value('smtp_port', (string) $cfg['smtp_port']),
        'smtp_username' => setting_value('smtp_username', (string) $cfg['smtp_username']),
        'smtp_encryption' => setting_value('smtp_encryption', (string) $cfg['smtp_encryption']),
        'imap_host' => setting_value('imap_host', (string) $cfg['imap_host']),
        'imap_port' => setting_value('imap_port', (string) $cfg['imap_port']),
        'imap_username' => setting_value('imap_username', (string) $cfg['imap_username']),
        'imap_encryption' => setting_value('imap_encryption', (string) $cfg['imap_encryption']),
    ];
}

function alarm_metric_value(array $host, string $metric): ?float
{
    $metrics = $host['metrics'] ?? [];
    if ($metric === 'cpu') {
        return (float) ($metrics['cpu'] ?? 0);
    }
    if ($metric === 'memory') {
        return ($metrics['ram_total_mb'] ?? 0) > 0 ? (($metrics['ram_used_mb'] ?? 0) / max(1, $metrics['ram_total_mb'])) * 100 : 0.0;
    }
    if ($metric === 'disk') {
        return ($metrics['disk_total_mb'] ?? 0) > 0 ? (($metrics['disk_used_mb'] ?? 0) / max(1, $metrics['disk_total_mb'])) * 100 : 0.0;
    }
    if ($metric === 'offline') {
        return empty($host['online']) ? 1.0 : 0.0;
    }
    return null;
}

function alarm_matches(float $value, string $operator, float $threshold): bool
{
    return match ($operator) {
        '>' => $value > $threshold,
        '<' => $value < $threshold,
        '<=' => $value <= $threshold,
        '=' => $value === $threshold,
        default => $value >= $threshold,
    };
}

function smtp_send_message(string $to, string $subject, string $body): array
{
    $cfg = app_config();
    $host = setting_value('smtp_host', (string) $cfg['smtp_host']);
    $port = (int) setting_value('smtp_port', (string) $cfg['smtp_port']);
    $user = setting_value('smtp_username', (string) $cfg['smtp_username']);
    $pass = setting_value('smtp_password', (string) $cfg['smtp_password']);
    $from = setting_value('mail_from', (string) $cfg['mail_from']);
    $encryption = strtolower((string) setting_value('smtp_encryption', (string) $cfg['smtp_encryption']));
    if ($host === '' || $from === '') {
        return [false, 'SMTP host and from address are required'];
    }
    $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $socket = @stream_socket_client($remote, $errno, $errstr, 10);
    if (!$socket) {
        return [false, 'SMTP connect failed: ' . $errstr];
    }
    stream_set_timeout($socket, 10);
    $read = static function () use ($socket): string {
        return (string) fgets($socket, 1024);
    };
    $write = static function (string $line) use ($socket): void {
        fwrite($socket, $line . "\r\n");
    };
    $read();
    $write('EHLO vmange.local');
    $read();
    if ($encryption === 'tls') {
        $write('STARTTLS');
        $read();
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return [false, 'SMTP STARTTLS failed'];
        }
        $write('EHLO vmange.local');
        $read();
    }
    if ($user !== '' && $pass !== '') {
        $write('AUTH LOGIN');
        $read();
        $write(base64_encode($user));
        $read();
        $write(base64_encode($pass));
        $auth = $read();
        if (!str_starts_with($auth, '235')) {
            fclose($socket);
            return [false, 'SMTP authentication failed'];
        }
    }
    $write('MAIL FROM:<' . $from . '>');
    $read();
    $write('RCPT TO:<' . $to . '>');
    $read();
    $write('DATA');
    $read();
    fwrite($socket, "Subject: {$subject}\r\nFrom: {$from}\r\nTo: {$to}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$body}\r\n.\r\n");
    $result = $read();
    $write('QUIT');
    fclose($socket);
    return [str_starts_with($result, '250'), trim($result)];
}

function evaluate_alarm_rules(array $hosts): void
{
    if (!table_exists('vbox_alarm_rules') || !table_exists('vbox_alarm_events')) {
        return;
    }
    $rules = [];
    $result = db()->query('SELECT * FROM vbox_alarm_rules WHERE enabled=1');
    while ($row = $result->fetch_assoc()) {
        $rules[] = $row;
    }
    foreach ($rules as $rule) {
        foreach ($hosts as $host) {
            $value = alarm_metric_value($host, (string) $rule['metric']);
            if ($value === null) {
                continue;
            }
            $matched = alarm_matches($value, (string) $rule['operator'], (float) $rule['threshold']);
            $stmt = db()->prepare("SELECT id, status FROM vbox_alarm_events WHERE rule_id=? AND hostname=? AND status IN ('active','acknowledged') ORDER BY id DESC LIMIT 1");
            $stmt->bind_param('is', $rule['id'], $host['hostname']);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            if ($matched && !$existing) {
                $message = sprintf('%s on %s is %.2f (%s %.2f)', $rule['metric'], $host['hostname'], $value, $rule['operator'], $rule['threshold']);
                $stmt = db()->prepare('INSERT INTO vbox_alarm_events(rule_id, hostname, metric_value, message) VALUES (?, ?, ?, ?)');
                $stmt->bind_param('isds', $rule['id'], $host['hostname'], $value, $message);
                $stmt->execute();
                $eventId = (int) db()->insert_id;
                $recipient = trim((string) ($rule['notify_email'] ?? ''));
                if ($recipient !== '') {
                    [$sent, $result] = smtp_send_message($recipient, 'VMange alarm: ' . $rule['name'], $message);
                    $status = $sent ? 'sent' : 'failed';
                    $stmt = db()->prepare('INSERT INTO vbox_notification_deliveries(alarm_event_id, channel, recipient, status, result) VALUES (?, "email", ?, ?, ?)');
                    $stmt->bind_param('isss', $eventId, $recipient, $status, $result);
                    $stmt->execute();
                    $stmt = db()->prepare('UPDATE vbox_alarm_events SET last_notified_at=NOW() WHERE id=?');
                    $stmt->bind_param('i', $eventId);
                    $stmt->execute();
                }
            } elseif (!$matched && $existing) {
                $stmt = db()->prepare("UPDATE vbox_alarm_events SET status='resolved', resolved_at=NOW() WHERE id=?");
                $stmt->bind_param('i', $existing['id']);
                $stmt->execute();
            }
        }
    }
}

function is_vm_command_action(string $action): bool
{
    return in_array($action, [
        'start', 'stop', 'poweroff', 'pause', 'resume', 'reset', 'restart', 'refresh_inventory',
        'snapshot_create', 'snapshot_restore', 'snapshot_delete', 'vm_clone', 'vm_delete',
        'vm_set_resources', 'vm_set_boot_order', 'vm_set_description', 'vm_set_autostart',
        'vm_attach_iso', 'vm_detach_iso', 'vm_attach_disk', 'vm_create_disk', 'vm_resize_disk',
        'vm_set_network', 'vm_cable_connected', 'vm_export', 'vm_enable_vrde', 'vm_disable_vrde',
        'vm_screenshot', 'vm_logs_list', 'vm_log_tail',
    ], true);
}

function command_preflight(string $hostname, string $action, string $target, string $payload): void
{
    $stmt = db()->prepare('SELECT *, TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS age_seconds FROM vbox_hosts WHERE hostname=? LIMIT 1');
    $stmt->bind_param('s', $hostname);
    $stmt->execute();
    $host = $stmt->get_result()->fetch_assoc();
    if (!$host || host_is_blocked($hostname)) {
        throw new InvalidArgumentException('Host is not enrolled or has been removed');
    }

    $onlineWindow = (int) app_config()['online_window_seconds'];
    $ageSeconds = isset($host['age_seconds']) ? (int) $host['age_seconds'] : PHP_INT_MAX;
    if (empty($host['last_seen']) || $ageSeconds < 0 || $ageSeconds > $onlineWindow) {
        throw new InvalidArgumentException('Host is offline. Last seen: ' . ($host['last_seen'] ?: 'never'));
    }

    if (!is_vm_command_action($action)) {
        return;
    }

    $capabilities = decode_json_column($host['capabilities_json'] ?? null);
    if (($capabilities['has_virtualbox'] ?? true) === false) {
        throw new InvalidArgumentException('This host has not reported VirtualBox capability');
    }

    $data = json_decode($payload, true);
    $data = is_array($data) ? $data : [];
    $wantedUuid = strtolower(trim((string) ($data['vm_uuid'] ?? '')));
    $wantedName = trim((string) ($data['vm_name'] ?? $target));
    $inventory = normalize_vm_inventory(decode_json_column($host['vm_inventory_json'] ?? null), [], []);
    $legacyNames = parse_vm_list($host['all_vms'] ?? '');
    foreach ($inventory as $vm) {
        $name = (string) ($vm['name'] ?? '');
        $uuid = strtolower((string) ($vm['uuid'] ?? ''));
        if (($wantedUuid !== '' && $uuid === $wantedUuid) || ($wantedName !== '' && $name === $wantedName)) {
            return;
        }
    }
    if ($wantedName !== '' && in_array($wantedName, $legacyNames, true)) {
        return;
    }
    throw new InvalidArgumentException('VM was not found on host inventory. Refresh inventory, then retry.');
}

function docs_payload(): array
{
    $docsDir = __DIR__ . DIRECTORY_SEPARATOR . 'docs';
    $items = [
        'getting-started' => 'Getting Started',
        'installation' => 'Installation',
        'agent-installation' => 'Host Agent Installation',
        'hosts' => 'Hosts',
        'virtual-machines' => 'Virtual Machines',
        'containers-compose' => 'Containers And Compose',
        'wol-host-tools' => 'WOL And Host Tools',
        'audit-logs' => 'Audit And Logs',
        'alarms-notifications' => 'Alarms And Notifications',
        'troubleshooting' => 'Troubleshooting',
        'security' => 'Security Model',
        'about' => 'About',
    ];
    $docs = [];
    foreach ($items as $slug => $title) {
        $file = $docsDir . DIRECTORY_SEPARATOR . $slug . '.md';
        $docs[] = [
            'slug' => $slug,
            'title' => $title,
            'body' => is_file($file) ? (string) file_get_contents($file) : '',
        ];
    }
    return $docs;
}

function queue_command(string $hostname, string $resourceAction, string $target, string $payload, bool $confirmed = false): int
{
    if (!in_array($resourceAction, allowed_actions(), true)) {
        throw new InvalidArgumentException('Action is not allowed');
    }
    if (is_destructive_action($resourceAction) && !$confirmed) {
        throw new InvalidArgumentException('Confirmation required');
    }
    $payload = validated_command_payload($resourceAction, substr($payload, 0, 65535));
    command_preflight($hostname, $resourceAction, $target, $payload);
    expire_stale_commands($hostname, max(60, (int) app_config()['online_window_seconds']));
    if (column_exists('vbox_commands', 'updated_at')) {
        $stmt = db()->prepare("UPDATE vbox_commands SET status='expired', updated_at=NOW() WHERE hostname=? AND action=? AND vmname=? AND status IN ('pending','sent','running')");
    } else {
        $stmt = db()->prepare("UPDATE vbox_commands SET status='expired' WHERE hostname=? AND action=? AND vmname=? AND status IN ('pending','sent','running')");
    }
    $stmt->bind_param('sss', $hostname, $resourceAction, $target);
    $stmt->execute();
    $status = 'pending';
    $user = $_SESSION['vbox_user'] ?? 'unknown';
    if (column_exists('vbox_commands', 'payload') && column_exists('vbox_commands', 'requested_by')) {
        $stmt = db()->prepare('INSERT INTO vbox_commands(hostname, action, vmname, payload, status, requested_by) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('ssssss', $hostname, $resourceAction, $target, $payload, $status, $user);
    } elseif (column_exists('vbox_commands', 'payload')) {
        $stmt = db()->prepare('INSERT INTO vbox_commands(hostname, action, vmname, payload, status) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('sssss', $hostname, $resourceAction, $target, $payload, $status);
    } else {
        $stmt = db()->prepare('INSERT INTO vbox_commands(hostname, action, vmname, status) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('ssss', $hostname, $resourceAction, $target, $status);
    }
    $stmt->execute();
    audit_log('command_queued', $hostname . ':' . $target, $resourceAction);
    return (int) db()->insert_id;
}

function dashboard_payload(): array
{
    $onlineWindow = (int) app_config()['online_window_seconds'];
    expire_stale_commands(null, max(180, $onlineWindow * 2));
    $hosts = [];
    $summary = ['hosts' => 0, 'online' => 0, 'offline' => 0, 'vms' => 0, 'running' => 0, 'stopped' => 0, 'containers' => 0, 'alerts' => 0];

    $result = db()->query('SELECT *, TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS age_seconds FROM vbox_hosts ORDER BY hostname');
    while ($host = $result->fetch_assoc()) {
        $hostname = (string) $host['hostname'];
        if (host_is_blocked($hostname)) {
            continue;
        }
        $ageSeconds = isset($host['age_seconds']) ? (int) $host['age_seconds'] : PHP_INT_MAX;
        $online = !empty($host['last_seen']) && $ageSeconds >= 0 && $ageSeconds <= $onlineWindow;
        $fallbackMetrics = decode_json_column($host['metrics_json'] ?? null);
        $agentDebug = decode_json_column($host['agent_debug'] ?? null);
        $debugMetrics = decode_json_column($agentDebug['metrics_sample'] ?? null);
        $metrics = latest_metrics($hostname, $fallbackMetrics);
        $capabilities = decode_json_column($host['capabilities_json'] ?? null);
        $collectorErrors = decode_json_column($host['collector_errors_json'] ?? null);
        $inventory = decode_json_column($host['vm_inventory_json'] ?? null);
        $runtimeStatus = decode_json_column($host['runtime_status_json'] ?? null);
        $runtimeByName = is_array($runtimeStatus['by_name'] ?? null) ? $runtimeStatus['by_name'] : [];
        $runtimeByUuid = is_array($runtimeStatus['by_uuid'] ?? null) ? $runtimeStatus['by_uuid'] : [];
        $runtimeNames = is_array($runtimeStatus['running_vm_names'] ?? null) ? $runtimeStatus['running_vm_names'] : [];
        $runtimeUuids = is_array($runtimeStatus['running_vm_uuids'] ?? null) ? $runtimeStatus['running_vm_uuids'] : [];
        $specs = parse_vm_specs($host['vm_specs'] ?? '');
        $vmNames = parse_vm_list($host['all_vms'] ?? '');
        $runningVmNames = array_flip(parse_vm_list($host['running_vms'] ?? ''));
        $runningVmUuids = array_flip(parse_vm_uuid_list($host['running_vms'] ?? ''));
        foreach ($specs as $specName => $spec) {
            $specState = strtolower((string) ($spec['state'] ?? ''));
            if ($specState === 'running') {
                $runningVmNames[(string) $specName] = true;
                if (!in_array((string) $specName, $vmNames, true)) {
                    $vmNames[] = (string) $specName;
                }
            }
        }
        $runningSources = [
            $metrics['running_vm_names'] ?? [],
            $fallbackMetrics['running_vm_names'] ?? [],
            $debugMetrics['running_vm_names'] ?? [],
            parse_vm_list($agentDebug['running_vms_sample'] ?? ''),
        ];
        foreach ([$metrics['running_vm_uuids'] ?? [], $fallbackMetrics['running_vm_uuids'] ?? [], $debugMetrics['running_vm_uuids'] ?? []] as $uuidSource) {
            if (!is_array($uuidSource)) {
                continue;
            }
            foreach ($uuidSource as $runningUuid) {
                $runningUuid = strtolower(trim((string) $runningUuid));
                if ($runningUuid !== '') {
                    $runningVmUuids[$runningUuid] = true;
                }
            }
        }
        foreach ($runningSources as $runningSource) {
            if (!is_array($runningSource)) {
                continue;
            }
            foreach ($runningSource as $runningName) {
                $runningName = trim((string) $runningName);
                if ($runningName === '') {
                    continue;
                }
                $runningVmNames[$runningName] = true;
                if (!in_array($runningName, $vmNames, true)) {
                    $vmNames[] = $runningName;
                }
            }
        }
        foreach ($runtimeNames as $runningName) {
            $runningName = trim((string) $runningName);
            if ($runningName !== '') {
                $runningVmNames[$runningName] = true;
                if (!in_array($runningName, $vmNames, true)) {
                    $vmNames[] = $runningName;
                }
            }
        }
        foreach ($runtimeUuids as $runningUuid) {
            $runningUuid = strtolower(trim((string) $runningUuid));
            if ($runningUuid !== '') {
                $runningVmUuids[$runningUuid] = true;
            }
        }
        $vms = normalize_vm_inventory($inventory, array_keys($runningVmNames), array_keys($runningVmUuids));
        if ($vms === []) {
            foreach ($vmNames as $vmName) {
                $state = isset($runningVmNames[$vmName]) ? 'running' : ($specs[$vmName]['state'] ?? 'unknown');
                $running = $state === 'running';
                $vms[] = [
                    'name' => $vmName,
                    'uuid' => '',
                    'state' => $state,
                    'status' => $state === 'poweroff' ? 'stopped' : $state,
                    'running' => $running,
                    'running_source' => isset($runningVmNames[$vmName]) ? 'running_vms' : 'vm_specs',
                    'os' => $specs[$vmName]['os'] ?? '-',
                    'cpu' => $specs[$vmName]['cpu'] ?? 0,
                    'memory' => $specs[$vmName]['memory'] ?? 0,
                    'ram_mb' => $specs[$vmName]['memory'] ?? 0,
                    'vram' => $specs[$vmName]['vram'] ?? 0,
                    'vram_mb' => $specs[$vmName]['vram'] ?? 0,
                    'boot_order' => [],
                    'storage' => [],
                    'nics' => [],
                    'snapshots' => [],
                    'description' => '',
                    'autostart' => '',
                ];
            }
        }

        foreach ($vms as &$vm) {
            $vmName = (string) ($vm['name'] ?? '');
            $vmUuid = strtolower((string) ($vm['uuid'] ?? ''));
            $currentState = strtolower((string) ($vm['state'] ?? $vm['status'] ?? 'unknown'));
            $isPaused = str_contains($currentState, 'paused');
            $inventorySaysRunning = !empty($vm['running']) || $currentState === 'running';
            if ($isPaused) {
                $vm['state'] = 'paused';
                $vm['status'] = 'paused';
                $vm['running'] = true;
                $vm['runtime_source'] = $vm['runtime_source'] ?? 'inventory_paused';
                continue;
            }
            if ($vmName !== '' && isset($runtimeByName[$vmName])) {
                $vm['state'] = 'running';
                $vm['status'] = 'running';
                $vm['running'] = true;
                $vm['runtime_source'] = is_array($runtimeByName[$vmName]) ? ($runtimeByName[$vmName]['runtime_source'] ?? 'runtime_status_names') : 'runtime_status_names';
                continue;
            }
            if ($vmUuid !== '' && isset($runtimeByUuid[$vmUuid])) {
                $vm['state'] = 'running';
                $vm['status'] = 'running';
                $vm['running'] = true;
                $vm['runtime_source'] = is_array($runtimeByUuid[$vmUuid]) ? ($runtimeByUuid[$vmUuid]['runtime_source'] ?? 'runtime_status_uuids') : 'runtime_status_uuids';
                continue;
            }
            if ($vmName !== '' && isset($runningVmNames[$vmName])) {
                $vm['state'] = 'running';
                $vm['status'] = 'running';
                $vm['running'] = true;
                $vm['runtime_source'] = 'running_vm_names';
                continue;
            }
            if ($vmUuid !== '' && isset($runningVmUuids[$vmUuid])) {
                $vm['state'] = 'running';
                $vm['status'] = 'running';
                $vm['running'] = true;
                $vm['runtime_source'] = 'running_vm_uuids';
                continue;
            }
            if ($inventorySaysRunning) {
                $vm['state'] = 'running';
                $vm['status'] = 'running';
                $vm['running'] = true;
                $vm['runtime_source'] = $vm['runtime_source'] ?? 'inventory_running';
                continue;
            }
            $state = strtolower((string) ($vm['state'] ?? $vm['status'] ?? 'unknown'));
            $vm['state'] = $state;
            $vm['status'] = $state === 'poweroff' ? 'stopped' : $state;
            $vm['running'] = $state === 'running';
            $vm['runtime_source'] = $vm['runtime_source'] ?? 'inventory';
        }
        unset($vm);

        $resolvedRunningNames = [];
        $resolvedRunningUuids = [];
        foreach ($vms as $vm) {
            $summary['vms']++;
            if (($vm['status'] ?? $vm['state'] ?? '') === 'running') {
                $summary['running']++;
                $resolvedRunningNames[] = (string) ($vm['name'] ?? '');
                if (!empty($vm['uuid'])) {
                    $resolvedRunningUuids[] = strtolower((string) $vm['uuid']);
                }
            } else {
                $summary['stopped']++;
            }
        }
        if (!isset($metrics['running_vm_names']) || !is_array($metrics['running_vm_names']) || count($metrics['running_vm_names']) === 0) {
            $metrics['running_vm_names'] = array_values(array_filter($resolvedRunningNames));
        }
        if (!isset($metrics['running_vm_uuids']) || !is_array($metrics['running_vm_uuids']) || count($metrics['running_vm_uuids']) === 0) {
            $metrics['running_vm_uuids'] = array_values(array_filter($resolvedRunningUuids));
        }

        $containers = normalize_container_inventory(decode_json_column($host['containers_json'] ?? null));
        $compose = decode_json_column($host['compose_json'] ?? null);
        $images = normalize_image_inventory(decode_json_column($host['images_json'] ?? null));

        $hostAlert = !$online || (($metrics['cpu'] ?? 0) >= 90) || (($metrics['disk_total_mb'] ?? 0) > 0 && (($metrics['disk_used_mb'] ?? 0) / $metrics['disk_total_mb']) >= 0.9);
        $summary['hosts']++;
        $summary[$online ? 'online' : 'offline']++;
        $summary['containers'] += count($containers);
        if ($hostAlert) {
            $summary['alerts']++;
        }

        $hosts[] = [
            'hostname' => $hostname,
            'last_seen' => $host['last_seen'],
            'online' => (bool) $online,
            'health' => $online ? ($hostAlert ? 'warning' : 'healthy') : 'offline',
            'vms' => $vms,
            'metrics' => $metrics,
            'history' => metric_history($hostname, $metrics),
            'capabilities' => $capabilities,
            'collector_errors' => $collectorErrors,
            'wol' => [
                'mac' => (string) ($host['wol_mac'] ?? ''),
                'reported_mac' => (string) ($metrics['preferred_wol_mac'] ?? ''),
                'interfaces' => is_array($metrics['interfaces'] ?? null) ? $metrics['interfaces'] : [],
                'broadcast' => (string) ($host['wol_broadcast'] ?? ''),
                'port' => (int) ($host['wol_port'] ?? 9),
                'relay_host' => (string) ($host['wol_relay_host'] ?? ''),
            ],
            'containers' => $containers,
            'compose' => $compose,
            'images' => $images,
            'agent_debug' => $agentDebug,
        ];
    }

    $commands = [];
    $commandColumns = ['id', 'hostname', 'action', 'vmname', 'status', 'created_at'];
    foreach (['payload', 'requested_by', 'result', 'updated_at', 'started_at', 'finished_at', 'exit_code', 'stdout', 'stderr', 'diagnostics_json'] as $column) {
        $commandColumns[] = column_exists('vbox_commands', $column) ? $column : "NULL AS $column";
    }
    $cmdResult = db()->query('SELECT ' . implode(', ', $commandColumns) . ' FROM vbox_commands ORDER BY id DESC LIMIT 200');
    while ($row = $cmdResult->fetch_assoc()) {
        $commands[] = $row;
    }

    $audit = [];
    if (table_exists('vbox_audit_logs')) {
        $auditResult = db()->query('SELECT username, action, target, details, ip_address, created_at FROM vbox_audit_logs ORDER BY id DESC LIMIT 200');
        while ($row = $auditResult->fetch_assoc()) {
            $audit[] = $row;
        }
    }

    evaluate_alarm_rules($hosts);
    $alarms = alarms_payload();

    return [
        'ok' => true,
        'summary' => $summary,
        'hosts' => $hosts,
        'commands' => $commands,
        'audit' => $audit,
        'stacks' => saved_stacks_payload(),
        'scripts' => scripts_payload(),
        'scriptRuns' => script_runs_payload(),
        'users' => users_payload(),
        'alarms' => $alarms,
        'mailSettings' => mail_settings_payload(),
        'csrf' => csrf_token(),
        'role' => current_user_role(),
        'canManage' => can_manage(),
        'baseUrl' => base_url(),
        'agentVersion' => latest_agent_version(),
        'gatewayUrl' => (string) app_config()['gateway_url'],
        'terminalGatewayEnabled' => (bool) app_config()['terminal_gateway_enabled'],
        'terminalGatewayUrl' => (string) app_config()['terminal_gateway_url'],
        'docsEnabled' => (bool) app_config()['docs_enabled'],
        'docs' => docs_payload(),
    ];
}

function handle_ajax(): void
{
    require_login(true);
    $action = $_GET['ajax'] ?? '';

    if ($action === 'dashboard') {
        json_response(dashboard_payload());
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
    }
    verify_csrf();

    if ($action === 'command') {
        if (!can_manage()) {
            json_response(['ok' => false, 'error' => 'You do not have permission to manage resources'], 403);
        }
        if (!rate_limit('dashboard-command', 40, 60)) {
            json_response(['ok' => false, 'error' => 'Too many actions. Try again shortly.'], 429);
        }

        try {
            $hostname = validate_hostname((string) ($_POST['hostname'] ?? ''));
            $resourceAction = (string) ($_POST['action_name'] ?? '');
            $target = validate_target((string) ($_POST['target'] ?? ''));
        } catch (Throwable $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        if (!in_array($resourceAction, allowed_actions(), true)) {
            json_response(['ok' => false, 'error' => 'Action is not allowed'], 422);
        }
        if (current_user_role() !== 'admin' && in_array($resourceAction, ['vm_delete', 'agent_uninstall', 'host_reboot', 'host_wol_send'], true)) {
            json_response(['ok' => false, 'error' => 'Admin role required for this action'], 403);
        }
        if (is_destructive_action($resourceAction) && ($_POST['confirm'] ?? '') !== 'true') {
            json_response(['ok' => false, 'error' => 'Confirmation required'], 409);
        }

        try {
            queue_command($hostname, $resourceAction, $target, (string) ($_POST['payload'] ?? ''), ($_POST['confirm'] ?? '') === 'true');
        } catch (Throwable $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        json_response(['ok' => true, 'message' => 'Action queued']);
    }

    if ($action === 'wol-save') {
        if (current_user_role() !== 'admin') {
            json_response(['ok' => false, 'error' => 'Admin role required'], 403);
        }
        try {
            $hostname = validate_hostname((string) ($_POST['hostname'] ?? ''));
            $mac = validate_wol_mac((string) ($_POST['mac'] ?? ''));
            $broadcast = validate_wol_broadcast((string) ($_POST['broadcast'] ?? '255.255.255.255'));
            $port = validate_wol_port($_POST['port'] ?? 9);
            $relayHost = trim((string) ($_POST['relay_host'] ?? ''));
            if ($relayHost !== '') {
                $relayHost = validate_hostname($relayHost);
            }
        } catch (Throwable $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        $stmt = db()->prepare('UPDATE vbox_hosts SET wol_mac=?, wol_broadcast=?, wol_port=?, wol_relay_host=? WHERE hostname=?');
        $stmt->bind_param('ssiss', $mac, $broadcast, $port, $relayHost, $hostname);
        $stmt->execute();
        audit_log('wol_profile_saved', $hostname, 'Wake-on-LAN profile updated');
        json_response(['ok' => true, 'message' => 'Wake-on-LAN profile saved']);
    }

    if ($action === 'host-rename') {
        if (current_user_role() !== 'admin') {
            json_response(['ok' => false, 'error' => 'Admin role required'], 403);
        }
        try {
            $hostname = validate_hostname((string) ($_POST['hostname'] ?? ''));
            $newHostname = validate_hostname((string) ($_POST['new_hostname'] ?? ''));
        } catch (Throwable $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        if ($hostname === $newHostname) {
            json_response(['ok' => false, 'error' => 'New host name must be different'], 422);
        }
        $stmt = db()->prepare('SELECT 1 FROM vbox_hosts WHERE hostname=? LIMIT 1');
        $stmt->bind_param('s', $newHostname);
        $stmt->execute();
        if ($stmt->get_result()->fetch_row()) {
            json_response(['ok' => false, 'error' => 'That host name is already in use'], 409);
        }
        try {
            rename_host_everywhere($hostname, $newHostname);
        } catch (Throwable $e) {
            json_response(['ok' => false, 'error' => 'Could not rename host: ' . $e->getMessage()], 500);
        }
        audit_log('host_renamed', $hostname . ' -> ' . $newHostname, 'Host name updated across dashboard tables');
        json_response(['ok' => true, 'message' => 'Host renamed successfully', 'hostname' => $newHostname]);
    }

    if ($action === 'stack-save') {
        if (!can_manage()) {
            json_response(['ok' => false, 'error' => 'You do not have permission to manage resources'], 403);
        }
        try {
            $hostname = validate_hostname((string) ($_POST['hostname'] ?? ''));
            $project = validate_project_name((string) ($_POST['project'] ?? ''));
        } catch (Throwable $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        $yaml = trim((string) ($_POST['compose_yaml'] ?? ''));
        if ($yaml === '' || strlen($yaml) > 65535) {
            json_response(['ok' => false, 'error' => 'Compose YAML is required and must be smaller than 64 KB'], 422);
        }
        $user = $_SESSION['vbox_user'] ?? 'unknown';
        $stmt = db()->prepare('INSERT INTO vbox_compose_stacks(hostname, project, compose_yaml, status, created_by, updated_at) VALUES (?, ?, ?, "saved", ?, NOW()) ON DUPLICATE KEY UPDATE compose_yaml=VALUES(compose_yaml), updated_at=NOW()');
        $stmt->bind_param('ssss', $hostname, $project, $yaml, $user);
        $stmt->execute();
        audit_log('stack_saved', $hostname . ':' . $project, 'Compose stack saved');
        json_response(['ok' => true, 'message' => 'Compose stack saved']);
    }

    if ($action === 'stack-delete') {
        if (current_user_role() !== 'admin') {
            json_response(['ok' => false, 'error' => 'Admin role required'], 403);
        }
        try {
            $hostname = validate_hostname((string) ($_POST['hostname'] ?? ''));
            $project = validate_project_name((string) ($_POST['project'] ?? ''));
        } catch (Throwable $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        $stmt = db()->prepare('DELETE FROM vbox_compose_stacks WHERE hostname=? AND project=?');
        $stmt->bind_param('ss', $hostname, $project);
        $stmt->execute();
        audit_log('stack_deleted', $hostname . ':' . $project, 'Compose stack deleted from dashboard');
        json_response(['ok' => true, 'message' => 'Compose stack deleted']);
    }

    if ($action === 'stack-deploy') {
        if (!can_manage()) {
            json_response(['ok' => false, 'error' => 'You do not have permission to manage resources'], 403);
        }
        try {
            $hostname = validate_hostname((string) ($_POST['hostname'] ?? ''));
            $project = validate_project_name((string) ($_POST['project'] ?? ''));
        } catch (Throwable $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        $stmt = db()->prepare('SELECT compose_yaml FROM vbox_compose_stacks WHERE hostname=? AND project=? LIMIT 1');
        $stmt->bind_param('ss', $hostname, $project);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            json_response(['ok' => false, 'error' => 'Saved stack was not found'], 404);
        }
        $payload = (string) $row['compose_yaml'];
        try {
            queue_command($hostname, 'compose_deploy', $project, $payload, true);
        } catch (Throwable $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        $stmt = db()->prepare('UPDATE vbox_compose_stacks SET status="queued", last_action="deploy", updated_at=NOW() WHERE hostname=? AND project=?');
        $stmt->bind_param('ss', $hostname, $project);
        $stmt->execute();
        json_response(['ok' => true, 'message' => 'Compose deployment queued']);
    }

    if ($action === 'script-save') {
        if (current_user_role() !== 'admin') {
            json_response(['ok' => false, 'error' => 'Admin role required'], 403);
        }
        try {
            $name = validate_script_name((string) ($_POST['name'] ?? ''));
        } catch (Throwable $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        $body = trim((string) ($_POST['body'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        if ($body === '' || strlen($body) > 65535) {
            json_response(['ok' => false, 'error' => 'Script body is required and must be smaller than 64 KB'], 422);
        }
        $user = $_SESSION['vbox_user'] ?? 'unknown';
        $stmt = db()->prepare('INSERT INTO vbox_scripts(name, description, body, created_by, updated_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE description=VALUES(description), body=VALUES(body), updated_at=NOW()');
        $stmt->bind_param('ssss', $name, $description, $body, $user);
        $stmt->execute();
        audit_log('script_saved', $name, 'Reusable script saved');
        json_response(['ok' => true, 'message' => 'Script saved']);
    }

    if ($action === 'script-delete') {
        if (current_user_role() !== 'admin') {
            json_response(['ok' => false, 'error' => 'Admin role required'], 403);
        }
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            json_response(['ok' => false, 'error' => 'Invalid script'], 422);
        }
        $stmt = db()->prepare('DELETE FROM vbox_scripts WHERE id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        audit_log('script_deleted', (string) $id, 'Reusable script deleted');
        json_response(['ok' => true, 'message' => 'Script deleted']);
    }

    if ($action === 'script-run') {
        if (current_user_role() !== 'admin') {
            json_response(['ok' => false, 'error' => 'Admin role required'], 403);
        }
        $scriptId = (int) ($_POST['script_id'] ?? 0);
        $hosts = json_decode((string) ($_POST['hosts'] ?? '[]'), true);
        if ($scriptId <= 0 || !is_array($hosts) || $hosts === []) {
            json_response(['ok' => false, 'error' => 'Script and at least one host are required'], 422);
        }
        $stmt = db()->prepare('SELECT body FROM vbox_scripts WHERE id=? LIMIT 1');
        $stmt->bind_param('i', $scriptId);
        $stmt->execute();
        $script = $stmt->get_result()->fetch_assoc();
        if (!$script) {
            json_response(['ok' => false, 'error' => 'Script was not found'], 404);
        }
        $queued = 0;
        $failures = [];
        foreach ($hosts as $hostValue) {
            try {
                $hostname = validate_hostname((string) $hostValue);
                $commandId = queue_command($hostname, 'script_run', 'host-script-' . $scriptId, (string) $script['body'], true);
                $status = 'pending';
                $stmt = db()->prepare('INSERT INTO vbox_script_runs(script_id, hostname, command_id, status, updated_at) VALUES (?, ?, ?, ?, NOW())');
                $stmt->bind_param('isis', $scriptId, $hostname, $commandId, $status);
                $stmt->execute();
                $queued++;
            } catch (Throwable $e) {
                $failures[] = validate_target((string) $hostValue) . ': ' . $e->getMessage();
            }
        }
        json_response(['ok' => $queued > 0, 'message' => $queued . ' script run(s) queued', 'failures' => $failures, 'error' => $queued > 0 ? null : implode('; ', $failures)]);
    }

    if ($action === 'mail-save') {
        if (current_user_role() !== 'admin') {
            json_response(['ok' => false, 'error' => 'Admin role required'], 403);
        }
        foreach (['mail_from','smtp_host','smtp_port','smtp_username','smtp_password','smtp_encryption','imap_host','imap_port','imap_username','imap_password','imap_encryption'] as $key) {
            save_setting($key, trim((string) ($_POST[$key] ?? '')));
        }
        audit_log('mail_settings_saved', 'mail', 'SMTP and IMAP settings updated');
        json_response(['ok' => true, 'message' => 'Mail settings saved']);
    }

    if ($action === 'alarm-save') {
        if (current_user_role() !== 'admin') {
            json_response(['ok' => false, 'error' => 'Admin role required'], 403);
        }
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $metric = trim((string) ($_POST['metric'] ?? 'cpu'));
        $operator = trim((string) ($_POST['operator'] ?? '>='));
        $threshold = (float) ($_POST['threshold'] ?? 0);
        $notify = trim((string) ($_POST['notify_email'] ?? ''));
        $cooldown = max(1, (int) ($_POST['cooldown_minutes'] ?? 15));
        $enabled = ($_POST['enabled'] ?? '1') === '1' ? 1 : 0;
        if ($name === '' || !in_array($metric, ['cpu','memory','disk','offline'], true) || !in_array($operator, ['>','>=','<','<=','='], true)) {
            json_response(['ok' => false, 'error' => 'Invalid alarm rule'], 422);
        }
        if ($id > 0) {
            $stmt = db()->prepare('UPDATE vbox_alarm_rules SET name=?, metric=?, operator=?, threshold=?, notify_email=?, cooldown_minutes=?, enabled=?, updated_at=NOW() WHERE id=?');
            $stmt->bind_param('sssdsiii', $name, $metric, $operator, $threshold, $notify, $cooldown, $enabled, $id);
        } else {
            $stmt = db()->prepare('INSERT INTO vbox_alarm_rules(name, metric, operator, threshold, notify_email, cooldown_minutes, enabled, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
            $stmt->bind_param('sssdsii', $name, $metric, $operator, $threshold, $notify, $cooldown, $enabled);
        }
        $stmt->execute();
        audit_log('alarm_rule_saved', $name, 'Alarm rule saved');
        json_response(['ok' => true, 'message' => 'Alarm rule saved']);
    }

    if ($action === 'alarm-ack') {
        if (!can_manage()) {
            json_response(['ok' => false, 'error' => 'Permission denied'], 403);
        }
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = db()->prepare("UPDATE vbox_alarm_events SET status='acknowledged', acknowledged_at=NOW() WHERE id=? AND status='active'");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        json_response(['ok' => true, 'message' => 'Alarm acknowledged']);
    }

    if ($action === 'rotate-token') {
        if (current_user_role() !== 'admin') {
            json_response(['ok' => false, 'error' => 'Admin role required'], 403);
        }
        try {
            ensure_host_tokens_table();
        } catch (Throwable $e) {
            json_response(['ok' => false, 'error' => 'Could not create host token table. Check database permissions.'], 500);
        }
        try {
            $hostname = validate_hostname((string) ($_POST['hostname'] ?? ''));
        } catch (Throwable $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        $rawToken = bin2hex(random_bytes(32));
        $hash = hash('sha256', $rawToken);
        $conn = db();
        $stmt = $conn->prepare('UPDATE vbox_host_tokens SET active=0 WHERE hostname=?');
        $stmt->bind_param('s', $hostname);
        $stmt->execute();
        $stmt = $conn->prepare('INSERT INTO vbox_host_tokens(hostname, token_hash, active, created_at) VALUES (?, ?, 1, NOW())');
        $stmt->bind_param('ss', $hostname, $hash);
        $stmt->execute();
        audit_log('token_rotated', $hostname, 'A new host token was issued');
        json_response(['ok' => true, 'token' => $rawToken]);
    }

    if ($action === 'enroll-host') {
        if (current_user_role() !== 'admin') {
            json_response(['ok' => false, 'error' => 'Admin role required'], 403);
        }
        $sharedToken = (string) app_config()['legacy_agent_token'];
        $canUseHostTokens = $sharedToken === '';
        try {
            if ($canUseHostTokens) {
                ensure_host_tokens_table();
            }
        } catch (Throwable $e) {
            $canUseHostTokens = false;
            error_log('VMange host token table unavailable, falling back to legacy token: ' . $e->getMessage());
        }

        try {
            $hostname = validate_hostname((string) ($_POST['hostname'] ?? ''));
        } catch (Throwable $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        $rawToken = bin2hex(random_bytes(32));
        $hash = hash('sha256', $rawToken);
        $conn = db();
        unblock_host($hostname);

        $stmt = $conn->prepare('INSERT INTO vbox_hosts(hostname, all_vms, running_vms, vm_specs, last_seen) VALUES (?, "", "", "", NULL) ON DUPLICATE KEY UPDATE hostname=VALUES(hostname)');
        $stmt->bind_param('s', $hostname);
        $stmt->execute();

        if ($canUseHostTokens) {
            $stmt = $conn->prepare('UPDATE vbox_host_tokens SET active=0, rotated_at=NOW() WHERE hostname=?');
            $stmt->bind_param('s', $hostname);
            $stmt->execute();

            $stmt = $conn->prepare('INSERT INTO vbox_host_tokens(hostname, token_hash, active, created_at) VALUES (?, ?, 1, NOW())');
            $stmt->bind_param('ss', $hostname, $hash);
            $stmt->execute();
            $tokenMode = 'per-host';
        } else {
            $tokenMode = 'direct';
        }

        $installerUrl = base_url('host-install.php');
        $embeddedEnv = 'VMANGE_NONINTERACTIVE=1 VMANGE_ENROLL_TOKEN=' . escapeshellarg($rawToken) . ' VMANGE_HOSTNAME_PRESET=' . escapeshellarg($hostname) . ' VMANGE_API_URL_PRESET=' . escapeshellarg(base_url('agent-sync.php')) . ' VMANGE_INTERVAL_PRESET=15';
        $downloadCommand = 'curl -fsSL "' . $installerUrl . '" -o vmange-install.sh && chmod +x vmange-install.sh && sudo ' . $embeddedEnv . ' ./vmange-install.sh';
        $oneLineCommand = 'curl -fsSL "' . $installerUrl . '" | sudo ' . $embeddedEnv . ' bash';
        audit_log('host_enrolled', $hostname, 'New host enrollment token generated using ' . $tokenMode . ' mode');
        json_response([
            'ok' => true,
            'hostname' => $hostname,
            'token' => $rawToken,
            'tokenMode' => $tokenMode,
            'installerUrl' => $installerUrl,
            'downloadCommand' => $downloadCommand,
            'oneLineCommand' => $oneLineCommand,
            'message' => 'Host enrollment created',
        ]);
    }

    if ($action === 'user-save') {
        if (current_user_role() !== 'admin') {
            json_response(['ok' => false, 'error' => 'Admin role required'], 403);
        }
        ensure_user_role_column();
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? 'viewer');
        if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) {
            json_response(['ok' => false, 'error' => 'Invalid username'], 422);
        }
        if (!in_array($role, ['admin', 'operator', 'viewer'], true)) {
            json_response(['ok' => false, 'error' => 'Invalid role'], 422);
        }
        $userExists = false;
        $stmt = db()->prepare('SELECT id FROM vbox_users WHERE username=? LIMIT 1');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            $userExists = true;
        }
        if (!$userExists && strlen($password) < 14) {
            json_response(['ok' => false, 'error' => 'New user password must be at least 14 characters'], 422);
        }
        if ($password !== '') {
            if (strlen($password) < 14) {
                json_response(['ok' => false, 'error' => 'Password must be at least 14 characters'], 422);
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if (column_exists('vbox_users', 'role')) {
                $stmt = db()->prepare('INSERT INTO vbox_users(username, password_hash, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), role=VALUES(role)');
                $stmt->bind_param('sss', $username, $hash, $role);
            } else {
                $stmt = db()->prepare('INSERT INTO vbox_users(username, password_hash) VALUES (?, ?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)');
                $stmt->bind_param('ss', $username, $hash);
            }
        } else {
            if (!column_exists('vbox_users', 'role')) {
                json_response(['ok' => false, 'error' => 'Password required because role column is unavailable'], 422);
            }
            $stmt = db()->prepare('UPDATE vbox_users SET role=? WHERE username=?');
            $stmt->bind_param('ss', $role, $username);
        }
        $stmt->execute();
        audit_log('user_saved', $username, $role);
        json_response(['ok' => true, 'message' => 'User saved']);
    }

    if ($action === 'user-delete') {
        if (current_user_role() !== 'admin') {
            json_response(['ok' => false, 'error' => 'Admin role required'], 403);
        }
        $username = trim((string) ($_POST['username'] ?? ''));
        if ($username === ($_SESSION['vbox_user'] ?? '')) {
            json_response(['ok' => false, 'error' => 'You cannot delete your own account'], 409);
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) {
            json_response(['ok' => false, 'error' => 'Invalid username'], 422);
        }
        $stmt = db()->prepare('DELETE FROM vbox_users WHERE username=?');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        audit_log('user_deleted', $username, 'Dashboard user removed');
        json_response(['ok' => true, 'message' => 'User deleted']);
    }

    if ($action === 'host-delete') {
        if (current_user_role() !== 'admin') {
            json_response(['ok' => false, 'error' => 'Admin role required'], 403);
        }
        try {
            $hostname = validate_hostname((string) ($_POST['hostname'] ?? ''));
        } catch (Throwable $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        block_host($hostname);

        $stmt = db()->prepare('DELETE FROM vbox_commands WHERE hostname=?');
        $stmt->bind_param('s', $hostname);
        $stmt->execute();

        if (table_exists('vbox_host_tokens')) {
            $stmt = db()->prepare('DELETE FROM vbox_host_tokens WHERE hostname=?');
            $stmt->bind_param('s', $hostname);
            $stmt->execute();
        }

        if (table_exists('vbox_metrics')) {
            $stmt = db()->prepare('DELETE FROM vbox_metrics WHERE hostname=?');
            $stmt->bind_param('s', $hostname);
            $stmt->execute();
        }

        $stmt = db()->prepare('DELETE FROM vbox_hosts WHERE hostname=?');
        $stmt->bind_param('s', $hostname);
        $stmt->execute();

        audit_log('host_deleted', $hostname, 'Host removed from dashboard');
        json_response(['ok' => true, 'message' => 'Host deleted']);
    }

    if ($action === 'install-script') {
        if (current_user_role() !== 'admin') {
            json_response(['ok' => false, 'error' => 'Admin role required'], 403);
        }
        $hostname = preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($_POST['hostname'] ?? 'new-host'));
        $token = (string) ($_POST['token'] ?? app_config()['legacy_agent_token']);
        $apiUrl = base_url('agent-sync.php');
        $script = "#!/bin/bash\nset -euo pipefail\nRUN_USER=\"\${VMANGE_RUN_USER_PRESET:-\${SUDO_USER:-\$(id -un)}}\"\nRUN_GROUP=\"\$(id -gn \"\$RUN_USER\" 2>/dev/null || printf '%s' \"\$RUN_USER\")\"\nRUN_HOME=\"\$(getent passwd \"\$RUN_USER\" | cut -d: -f6)\"\n[ -n \"\$RUN_HOME\" ] || RUN_HOME=\"/root\"\nsudo install -d -m 0750 /etc/vmange /var/lib/vmange/compose\nsudo chown \"\$RUN_USER:\$RUN_GROUP\" /var/lib/vmange/compose\nsudo install -m 0755 vbox-agent.sh /usr/local/bin/vmange-agent\nsudo tee /etc/vmange/agent.env >/dev/null <<'ENV'\nVMANGE_API_URL=\"$apiUrl\"\nVMANGE_TOKEN=\"$token\"\nVMANGE_HOSTNAME=\"$hostname\"\nVMANGE_INTERVAL=\"15\"\nVMANGE_COMPOSE_ROOT=\"/var/lib/vmange/compose\"\nENV\nsudo tee /etc/systemd/system/vmange-agent.service >/dev/null <<SERVICE\n[Unit]\nDescription=VMange host agent\nAfter=network-online.target\nWants=network-online.target\n\n[Service]\nType=simple\nEnvironmentFile=/etc/vmange/agent.env\nUser=\$RUN_USER\nGroup=\$RUN_GROUP\nWorkingDirectory=\$RUN_HOME\nEnvironment=HOME=\$RUN_HOME\nExecStart=/usr/local/bin/vmange-agent loop\nRestart=on-failure\nRestartSec=10\nNoNewPrivileges=true\nSupplementaryGroups=docker\n\n[Install]\nWantedBy=multi-user.target\nSERVICE\nsudo systemctl daemon-reload\nsudo systemctl enable --now vmange-agent.service\n";
        audit_log('install_script_generated', $hostname, 'Host install script viewed');
        json_response(['ok' => true, 'script' => $script]);
    }

    json_response(['ok' => false, 'error' => 'Unknown AJAX action'], 404);
}

if (isset($_GET['ajax'])) {
    handle_ajax();
}

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_user'], $_POST['login_pass'])) {
    try {
        verify_csrf();
        if (!rate_limit('login:' . strtolower((string) $_POST['login_user']), 8, 300)) {
            $loginError = 'Too many login attempts. Please wait a few minutes.';
        } else {
            $username = trim((string) $_POST['login_user']);
            $password = (string) $_POST['login_pass'];
            $stmt = db()->prepare('SELECT id, username, password_hash FROM vbox_users WHERE username=? LIMIT 1');
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['vbox_logged_in'] = true;
                $_SESSION['vbox_user'] = $user['username'];
                $_SESSION['vbox_user_id'] = (int) $user['id'];
                $_SESSION['vbox_role'] = 'admin';
                if (column_exists('vbox_users', 'role')) {
                    $roleStmt = db()->prepare('SELECT role FROM vbox_users WHERE id=? LIMIT 1');
                    $roleStmt->bind_param('i', $_SESSION['vbox_user_id']);
                    $roleStmt->execute();
                    $roleRow = $roleStmt->get_result()->fetch_assoc();
                    $_SESSION['vbox_role'] = $roleRow['role'] ?? 'admin';
                }
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                audit_log('login', $user['username'], 'Dashboard login');
                header('Location: index.php');
                exit;
            }
            audit_log('login_failed', $username, 'Invalid username or password');
            $loginError = 'Invalid username or password';
        }
    } catch (Throwable $e) {
        error_log('VMange login failed with exception: ' . $e->getMessage());
        $loginError = 'Login failed because the server configuration is incomplete. Check the PHP error log.';
    }
}

$loggedIn = !empty($_SESSION['vbox_logged_in']);
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($config['app_name']) ?> Dashboard</title>
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css')) ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js" defer></script>
    <script src="<?= e(asset_url('assets/js/app.js')) ?>" defer></script>
</head>
<body class="<?= $loggedIn ? 'dashboard-body' : 'login-body' ?>">
<?php if (!$loggedIn): ?>
    <main class="login-shell">
        <section class="login-panel">
            <img src="<?= e(asset_url('assets/img/vmange-logo.png')) ?>" alt="VMange" class="login-logo">
            <h1>Sign in to VMange</h1>
            <p class="muted">Secure VirtualBox, host, and container operations.</p>
            <?php if ($loginError): ?>
                <div class="alert error"><?= e($loginError) ?></div>
            <?php endif; ?>
            <form method="post" class="login-form">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <label>
                    <span>Username</span>
                    <input type="text" name="login_user" autocomplete="username" required>
                </label>
                <label>
                    <span>Password</span>
                    <input type="password" name="login_pass" autocomplete="current-password" required>
                </label>
                <button type="submit" class="btn primary">Sign in</button>
            </form>
        </section>
    </main>
<?php else: ?>
    <script type="application/json" id="app-config"><?= json_encode([
        'csrf' => csrf_token(),
        'user' => $_SESSION['vbox_user'],
        'role' => current_user_role(),
        'baseUrl' => base_url(),
        'agentVersion' => latest_agent_version(),
        'gatewayUrl' => (string) app_config()['gateway_url'],
        'terminalGatewayEnabled' => (bool) app_config()['terminal_gateway_enabled'],
        'terminalGatewayUrl' => (string) app_config()['terminal_gateway_url'],
        'docsEnabled' => (bool) app_config()['docs_enabled'],
        'logo' => asset_url('assets/img/vmange-logo.png'),
    ], JSON_UNESCAPED_SLASHES) ?></script>
    <div class="app-shell">
        <aside class="sidebar" aria-label="Primary navigation">
            <div class="sidebar-head">
                <a class="brand" href="#overview" aria-label="VMange overview">
                    <img src="<?= e(asset_url('assets/img/vmange-logo.png')) ?>" alt="VMange">
                </a>
                <button class="icon-btn sidebar-toggle" id="sidebar-toggle" type="button" aria-label="Collapse sidebar" title="Collapse sidebar">&lt;</button>
            </div>
            <nav>
                <a href="#overview" data-nav="overview" class="active">Overview</a>
                <a href="#hosts" data-nav="hosts">Hosts</a>
                <a href="#vms" data-nav="vms">Virtual Machines</a>
                <a href="#containers" data-nav="containers">Containers</a>
                <a href="#compose" data-nav="compose">Compose</a>
                <a href="#scripts" data-nav="scripts">Scripts</a>
                <a href="#audit" data-nav="audit">Audit</a>
                <a href="#alarms" data-nav="alarms">Alarms</a>
                <a href="#settings" data-nav="settings">Settings</a>
                <a href="docs.php">Docs</a>
                <a href="#about" data-nav="about">About</a>
            </nav>
        </aside>

        <main class="main-panel">
            <header class="topbar">
                <div>
                    <p class="eyebrow">Infrastructure dashboard</p>
                    <h1 id="page-title">Overview</h1>
                </div>
                <div class="top-actions">
                    <button class="icon-btn mobile-nav-toggle" id="mobile-nav-toggle" type="button" aria-label="Open navigation" title="Open navigation">=</button>
                    <input id="global-search" type="search" placeholder="Search hosts, VMs, containers" aria-label="Search resources">
                    <a class="icon-btn alarm-link" href="#alarms" id="alarm-link" aria-label="Open alarms" title="Open alarms">!</a>
                    <button class="icon-btn" id="help-button" type="button" aria-label="Open page help" title="Page help">?</button>
                    <button class="icon-btn" id="theme-toggle" type="button" aria-label="Toggle color theme">T</button>
                    <span class="user-pill"><?= e($_SESSION['vbox_user']) ?> / <?= e(current_user_role()) ?></span>
                    <a class="btn ghost" href="index.php?logout=1">Logout</a>
                </div>
            </header>

            <section id="view-root" class="view-root" aria-live="polite">
                <div class="loading-panel">Loading dashboard...</div>
            </section>
        </main>
    </div>
    <button class="nav-backdrop" id="nav-backdrop" type="button" aria-label="Close navigation"></button>

    <div id="toast-region" class="toast-region" aria-live="polite"></div>
    <dialog id="confirm-modal" class="modal">
        <form method="dialog">
            <button class="modal-close" type="button" data-dialog-close aria-label="Close dialog">x</button>
            <h2 id="confirm-title">Confirm action</h2>
            <p id="confirm-copy"></p>
            <div class="modal-actions">
                <button class="btn ghost" value="cancel">Cancel</button>
                <button class="btn danger" id="confirm-accept" value="confirm">Confirm</button>
            </div>
        </form>
    </dialog>
    <dialog id="enroll-modal" class="modal wide-modal">
        <div>
            <button class="modal-close" type="button" data-dialog-close aria-label="Close dialog">x</button>
            <h2>Add new host</h2>
            <p class="muted">Add the host name here, copy the generated command, run it on that Linux PC, paste the token when asked, then the host will report VMs and containers to this dashboard.</p>
            <label class="field-block">
                <span>Host name</span>
                <input id="enroll-hostname" pattern="[A-Za-z0-9._-]{1,100}" placeholder="host01" required>
            </label>
            <div class="modal-actions">
                <button class="btn ghost" id="enroll-cancel" type="button">Cancel</button>
                <button class="btn primary" id="enroll-create" type="button">Generate token and commands</button>
            </div>
            <div id="enroll-output" class="enroll-output" hidden></div>
        </div>
    </dialog>
    <dialog id="action-modal" class="modal wide-modal">
        <form method="dialog" id="action-form">
            <button class="modal-close" type="button" data-dialog-close aria-label="Close dialog">x</button>
            <h2 id="action-modal-title">Edit action</h2>
            <p id="action-modal-copy" class="muted"></p>
            <div id="action-modal-fields" class="modal-form-grid"></div>
            <p id="action-modal-note" class="muted modal-note" hidden></p>
            <div class="modal-actions">
                <button class="btn ghost" value="cancel">Cancel</button>
                <button class="btn primary" id="action-modal-submit" value="submit">Queue action</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>
</body>
</html>
