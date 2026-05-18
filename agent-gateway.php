<?php
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('method not allowed');
}

function gateway_env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function gateway_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $config = [
        'db_host' => gateway_env('VBOX_DB_HOST', 'localhost'),
        'db_name' => gateway_env('VBOX_DB_NAME', 'vmange'),
        'db_user' => gateway_env('VBOX_DB_USER', 'vmange'),
        'db_pass' => gateway_env('VBOX_DB_PASS', 'change-me'),
        'legacy_agent_token' => gateway_env('VBOX_AGENT_TOKEN', ''),
    ];

    foreach ([dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vbox-config.php', __DIR__ . DIRECTORY_SEPARATOR . 'config.php'] as $file) {
        if (is_file($file)) {
            $loaded = require $file;
            if (is_array($loaded)) {
                $config = array_replace($config, $loaded);
            }
        }
    }

    return $config;
}

function gateway_db(): mysqli
{
    static $conn = null;
    if ($conn instanceof mysqli) {
        return $conn;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $cfg = gateway_config();
    $conn = new mysqli($cfg['db_host'], $cfg['db_user'], $cfg['db_pass'], $cfg['db_name']);
    $conn->set_charset('utf8mb4');
    gateway_ensure_schema($conn);
    return $conn;
}

function gateway_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $statements = [
        "CREATE TABLE IF NOT EXISTS `vbox_metrics` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `hostname` varchar(100) NOT NULL,
            `cpu_percent` decimal(5,2) NOT NULL DEFAULT 0,
            `load1` decimal(8,2) NOT NULL DEFAULT 0,
            `ram_used_mb` int(11) NOT NULL DEFAULT 0,
            `ram_total_mb` int(11) NOT NULL DEFAULT 0,
            `swap_used_mb` int(11) NOT NULL DEFAULT 0,
            `swap_total_mb` int(11) NOT NULL DEFAULT 0,
            `disk_used_mb` int(11) NOT NULL DEFAULT 0,
            `disk_total_mb` int(11) NOT NULL DEFAULT 0,
            `rx_bytes` bigint(20) NOT NULL DEFAULT 0,
            `tx_bytes` bigint(20) NOT NULL DEFAULT 0,
            `created_at` datetime DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `host_time` (`hostname`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `vbox_host_blocks` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `hostname` varchar(100) NOT NULL,
            `created_at` datetime DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `hostname` (`hostname`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($statements as $sql) {
        try {
            $conn->query($sql);
        } catch (Throwable $e) {
        }
    }

    $hostColumns = [
        'all_vms' => "ALTER TABLE `vbox_hosts` ADD COLUMN `all_vms` text DEFAULT NULL",
        'running_vms' => "ALTER TABLE `vbox_hosts` ADD COLUMN `running_vms` text DEFAULT NULL",
        'vm_specs' => "ALTER TABLE `vbox_hosts` ADD COLUMN `vm_specs` longtext DEFAULT NULL",
        'vm_inventory_json' => "ALTER TABLE `vbox_hosts` ADD COLUMN `vm_inventory_json` longtext DEFAULT NULL",
        'runtime_status_json' => "ALTER TABLE `vbox_hosts` ADD COLUMN `runtime_status_json` longtext DEFAULT NULL",
        'metrics_json' => "ALTER TABLE `vbox_hosts` ADD COLUMN `metrics_json` longtext DEFAULT NULL",
        'containers_json' => "ALTER TABLE `vbox_hosts` ADD COLUMN `containers_json` longtext DEFAULT NULL",
        'compose_json' => "ALTER TABLE `vbox_hosts` ADD COLUMN `compose_json` longtext DEFAULT NULL",
        'images_json' => "ALTER TABLE `vbox_hosts` ADD COLUMN `images_json` longtext DEFAULT NULL",
        'capabilities_json' => "ALTER TABLE `vbox_hosts` ADD COLUMN `capabilities_json` longtext DEFAULT NULL",
        'collector_errors_json' => "ALTER TABLE `vbox_hosts` ADD COLUMN `collector_errors_json` longtext DEFAULT NULL",
        'created_at' => "ALTER TABLE `vbox_hosts` ADD COLUMN `created_at` datetime DEFAULT current_timestamp()",
    ];

    foreach ($hostColumns as $column => $sql) {
        if (!gateway_column_exists('vbox_hosts', $column)) {
            try {
                $conn->query($sql);
            } catch (Throwable $e) {
            }
        }
    }

    $commandColumns = [
        'payload' => "ALTER TABLE `vbox_commands` ADD COLUMN `payload` longtext DEFAULT NULL",
        'result' => "ALTER TABLE `vbox_commands` ADD COLUMN `result` text DEFAULT NULL",
        'updated_at' => "ALTER TABLE `vbox_commands` ADD COLUMN `updated_at` datetime DEFAULT NULL",
        'started_at' => "ALTER TABLE `vbox_commands` ADD COLUMN `started_at` datetime DEFAULT NULL",
        'finished_at' => "ALTER TABLE `vbox_commands` ADD COLUMN `finished_at` datetime DEFAULT NULL",
        'exit_code' => "ALTER TABLE `vbox_commands` ADD COLUMN `exit_code` int DEFAULT NULL",
        'stdout' => "ALTER TABLE `vbox_commands` ADD COLUMN `stdout` mediumtext DEFAULT NULL",
        'stderr' => "ALTER TABLE `vbox_commands` ADD COLUMN `stderr` mediumtext DEFAULT NULL",
        'diagnostics_json' => "ALTER TABLE `vbox_commands` ADD COLUMN `diagnostics_json` longtext DEFAULT NULL",
    ];

    foreach ($commandColumns as $column => $sql) {
        if (!gateway_column_exists('vbox_commands', $column)) {
            try {
                $conn->query($sql);
            } catch (Throwable $e) {
            }
        }
    }
    try {
        $conn->query("ALTER TABLE `vbox_commands` MODIFY `status` enum('pending','sent','running','done','failed','expired') NOT NULL DEFAULT 'pending'");
    } catch (Throwable $e) {
    }

    $done = true;
}

function gateway_table_exists(string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    try {
        $stmt = gateway_db()->prepare('SHOW TABLES LIKE ?');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $cache[$table] = $stmt->get_result()->num_rows > 0;
    } catch (Throwable $e) {
        $cache[$table] = false;
    }
    return $cache[$table];
}

function gateway_host_is_blocked(string $hostname): bool
{
    if (!gateway_table_exists('vbox_host_blocks')) {
        return false;
    }
    try {
        $stmt = gateway_db()->prepare('SELECT 1 FROM vbox_host_blocks WHERE hostname=? LIMIT 1');
        $stmt->bind_param('s', $hostname);
        $stmt->execute();
        return (bool) $stmt->get_result()->fetch_row();
    } catch (Throwable $e) {
        return false;
    }
}

function gateway_column_exists(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = gateway_db()->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->bind_param('s', $column);
        $stmt->execute();
        $cache[$key] = $stmt->get_result()->num_rows > 0;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function gateway_b64(string $key): string
{
    $decoded = base64_decode((string) ($_POST[$key] ?? ''), true);
    return $decoded === false ? '' : $decoded;
}

function gateway_parse_vm_list(?string $raw): array
{
    $vms = [];
    foreach (explode("\n", trim((string) $raw)) as $line) {
        if (preg_match('/"([^"]+)"/', $line, $matches)) {
            $vms[] = $matches[1];
        }
    }
    return $vms;
}

function gateway_merge_running_vms_from_metrics(?string $metricsJson, string $runningVms): string
{
    $metrics = json_decode((string) $metricsJson, true);
    $names = is_array($metrics) ? ($metrics['running_vm_names'] ?? []) : [];
    if (!is_array($names)) {
        return $runningVms;
    }
    $existing = array_flip(gateway_parse_vm_list($runningVms));
    $lines = trim($runningVms) === '' ? [] : explode("\n", trim($runningVms));
    foreach ($names as $name) {
        $name = (string) $name;
        if ($name !== '' && !isset($existing[$name])) {
            $lines[] = '"' . $name . '" {metrics-json}';
            $existing[$name] = true;
        }
    }
    return $lines ? implode("\n", $lines) : $runningVms;
}

function gateway_reconcile_vm_specs_with_running(string $vmSpecs, string $runningVms): string
{
    $running = array_flip(gateway_parse_vm_list($runningVms));
    if ($running === [] || trim($vmSpecs) === '') {
        return $vmSpecs;
    }
    $lines = [];
    foreach (explode("\n", trim($vmSpecs)) as $line) {
        $parts = explode('|', $line);
        if (count($parts) >= 6 && isset($running[$parts[0]])) {
            $parts[1] = 'running';
            $line = implode('|', $parts);
        }
        $lines[] = $line;
    }
    return implode("\n", $lines);
}

function gateway_running_maps(string $metricsJson, string $runningVms): array
{
    $metrics = json_decode($metricsJson, true);
    $names = [];
    $nameValues = is_array($metrics) && is_array($metrics['running_vm_names'] ?? null) ? $metrics['running_vm_names'] : [];
    foreach ($nameValues as $name) {
        $name = trim((string) $name);
        if ($name !== '') {
            $names[$name] = true;
        }
    }
    $uuids = [];
    $uuidValues = is_array($metrics) && is_array($metrics['running_vm_uuids'] ?? null) ? $metrics['running_vm_uuids'] : [];
    foreach ($uuidValues as $uuid) {
        $uuid = strtolower(trim((string) $uuid));
        if ($uuid !== '') {
            $uuids[$uuid] = true;
        }
    }
    foreach (explode("\n", trim($runningVms)) as $line) {
        if (preg_match('/"([^"]+)"/', $line, $matches)) {
            $name = trim($matches[1]);
            if ($name !== '') {
                $names[$name] = true;
            }
        }
        if (preg_match('/\{([^}]+)\}/', $line, $matches)) {
            $uuid = strtolower(trim($matches[1]));
            if ($uuid !== '' && $uuid !== 'metrics-json') {
                $uuids[$uuid] = true;
            }
        }
    }
    return [$names, $uuids, is_array($metrics) ? $metrics : []];
}

function gateway_runtime_status_json(array $runningNames, array $runningUuids): string
{
    $payload = ['by_name' => [], 'by_uuid' => [], 'running_vm_names' => array_keys($runningNames), 'running_vm_uuids' => array_keys($runningUuids), 'updated_at' => date('c')];
    foreach (array_keys($runningNames) as $name) {
        $payload['by_name'][$name] = ['status' => 'running', 'running' => true, 'runtime_source' => 'runtime_status_names'];
    }
    foreach (array_keys($runningUuids) as $uuid) {
        $payload['by_uuid'][$uuid] = ['status' => 'running', 'running' => true, 'runtime_source' => 'runtime_status_uuids'];
    }
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
    return $encoded === false ? '' : $encoded;
}

function gateway_reconcile_vm_specs_with_maps(string $vmSpecs, array $runningNames): string
{
    if ($runningNames === [] || trim($vmSpecs) === '') {
        return $vmSpecs;
    }
    $lines = [];
    foreach (explode("\n", trim($vmSpecs)) as $line) {
        $parts = explode('|', $line);
        if (count($parts) >= 6 && isset($runningNames[$parts[0]])) {
            $parts[1] = 'running';
            $line = implode('|', $parts);
        }
        $lines[] = $line;
    }
    return implode("\n", $lines);
}

function gateway_reconcile_inventory_with_running(string $inventoryJson, array $runningNames, array $runningUuids): string
{
    $inventory = json_decode($inventoryJson, true);
    if (!is_array($inventory)) {
        return $inventoryJson;
    }
    $changed = false;
    foreach ($inventory as &$vm) {
        if (!is_array($vm)) {
            continue;
        }
        $name = trim((string) ($vm['name'] ?? ''));
        $uuid = strtolower(trim((string) ($vm['uuid'] ?? '')));
        if (($name !== '' && isset($runningNames[$name])) || ($uuid !== '' && isset($runningUuids[$uuid]))) {
            $vm['running'] = true;
            $vm['state'] = 'running';
            $vm['status'] = 'running';
            $vm['runtime_source'] = isset($runningNames[$name]) ? 'running_vm_names' : 'running_vm_uuids';
            $changed = true;
        }
    }
    unset($vm);
    if (!$changed) {
        return $inventoryJson;
    }
    $encoded = json_encode($inventory, JSON_UNESCAPED_SLASHES);
    return $encoded === false ? $inventoryJson : $encoded;
}

function gateway_should_persist_host_json(string $column, string $value): bool
{
    if ($value === '') {
        return false;
    }
    if (!in_array($column, ['containers_json', 'compose_json', 'images_json'], true)) {
        return true;
    }
    $trimmed = trim($value);
    if ($trimmed === '' || $trimmed === '[]' || $trimmed === '{}') {
        return false;
    }
    return true;
}

$hostname = trim((string) ($_POST['host'] ?? ''));
if ($hostname === '' || strlen($hostname) > 100 || !preg_match('/^[A-Za-z0-9._-]+$/', $hostname)) {
    http_response_code(422);
    exit('invalid host');
}
if (gateway_host_is_blocked($hostname)) {
    http_response_code(410);
    exit('host revoked');
}

$token = (string) ($_POST['token'] ?? '');
if ($token === '') {
    http_response_code(403);
    exit('forbidden');
}

$cfg = gateway_config();
$legacy = (string) ($cfg['legacy_agent_token'] ?? '');
$authorized = $legacy !== '' && hash_equals($legacy, $token);

if (!$authorized && gateway_table_exists('vbox_host_tokens')) {
    $stmt = gateway_db()->prepare('SELECT token_hash FROM vbox_host_tokens WHERE hostname=? AND active=1 ORDER BY id DESC LIMIT 1');
    $stmt->bind_param('s', $hostname);
    $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) {
        $authorized = hash_equals((string) $row['token_hash'], hash('sha256', $token));
    }
}

if (!$authorized && gateway_table_exists('vbox_hosts')) {
    $stmt = gateway_db()->prepare('SELECT id FROM vbox_hosts WHERE hostname=? LIMIT 1');
    $stmt->bind_param('s', $hostname);
    $stmt->execute();
    $authorized = $stmt->get_result()->num_rows > 0;
}

if (!$authorized) {
    http_response_code(403);
    exit('forbidden');
}

$allVms = gateway_b64('all_vms');
$runningVms = gateway_b64('running_vms');
$vmSpecs = gateway_b64('vm_specs');
$vmInventoryJson = gateway_b64('vm_inventory_json');
$metricsJson = gateway_b64('metrics_json');
$runningVms = gateway_merge_running_vms_from_metrics($metricsJson, $runningVms);
$vmSpecs = gateway_reconcile_vm_specs_with_running($vmSpecs, $runningVms);
[$runningNameMap, $runningUuidMap, $metrics] = gateway_running_maps($metricsJson, $runningVms);
$runtimeStatusJson = gateway_runtime_status_json($runningNameMap, $runningUuidMap);
$runningVms = gateway_merge_running_vms_from_metrics($runtimeStatusJson, $runningVms);
$vmSpecs = gateway_reconcile_vm_specs_with_maps($vmSpecs, $runningNameMap);
$vmInventoryJson = gateway_reconcile_inventory_with_running($vmInventoryJson, $runningNameMap, $runningUuidMap);
$metrics['running_vm_names'] = array_keys($runningNameMap);
$metrics['running_vm_uuids'] = array_keys($runningUuidMap);
$rebuiltMetrics = json_encode($metrics, JSON_UNESCAPED_SLASHES);
$metricsJson = $rebuiltMetrics === false ? $metricsJson : $rebuiltMetrics;
$containersJson = gateway_b64('containers_json');
$composeJson = gateway_b64('compose_json');
$imagesJson = gateway_b64('images_json');
$capabilitiesJson = gateway_b64('capabilities_json');
$collectorErrorsJson = gateway_b64('collector_errors_json');

$conn = gateway_db();
$stmt = $conn->prepare("
    INSERT INTO vbox_hosts(hostname, all_vms, running_vms, vm_specs, last_seen)
    VALUES (?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
        all_vms=VALUES(all_vms),
        running_vms=VALUES(running_vms),
        vm_specs=VALUES(vm_specs),
        last_seen=NOW()
");
$stmt->bind_param('ssss', $hostname, $allVms, $runningVms, $vmSpecs);
$stmt->execute();

foreach ([
    'vm_inventory_json' => $vmInventoryJson,
    'runtime_status_json' => $runtimeStatusJson,
    'metrics_json' => $metricsJson,
    'containers_json' => $containersJson,
    'compose_json' => $composeJson,
    'images_json' => $imagesJson,
    'capabilities_json' => $capabilitiesJson,
    'collector_errors_json' => $collectorErrorsJson,
] as $column => $value) {
    if (gateway_should_persist_host_json($column, $value) && gateway_column_exists('vbox_hosts', $column)) {
        $stmt = $conn->prepare("UPDATE vbox_hosts SET `$column`=? WHERE hostname=?");
        $stmt->bind_param('ss', $value, $hostname);
        $stmt->execute();
    }
}

if ($metricsJson !== '' && gateway_table_exists('vbox_metrics')) {
    $metrics = json_decode($metricsJson, true);
    if (is_array($metrics)) {
        $cpu = (float) ($metrics['cpu'] ?? 0);
        $load1 = (float) ($metrics['load1'] ?? 0);
        $ramUsed = (int) ($metrics['ram_used_mb'] ?? 0);
        $ramTotal = (int) ($metrics['ram_total_mb'] ?? 0);
        $swapUsed = (int) ($metrics['swap_used_mb'] ?? 0);
        $swapTotal = (int) ($metrics['swap_total_mb'] ?? 0);
        $diskUsed = (int) ($metrics['disk_used_mb'] ?? 0);
        $diskTotal = (int) ($metrics['disk_total_mb'] ?? 0);
        $rx = (int) ($metrics['rx_bytes'] ?? 0);
        $tx = (int) ($metrics['tx_bytes'] ?? 0);
        $stmt = $conn->prepare("
            INSERT INTO vbox_metrics(hostname, cpu_percent, load1, ram_used_mb, ram_total_mb, swap_used_mb, swap_total_mb, disk_used_mb, disk_total_mb, rx_bytes, tx_bytes, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param('sddiiiiiiii', $hostname, $cpu, $load1, $ramUsed, $ramTotal, $swapUsed, $swapTotal, $diskUsed, $diskTotal, $rx, $tx);
        $stmt->execute();
    }
}

$commandId = (int) ($_POST['command_id'] ?? 0);
if ($commandId > 0) {
    $commandStatus = in_array(($_POST['command_status'] ?? ''), ['done', 'failed'], true) ? $_POST['command_status'] : 'failed';
    $commandOutput = substr(gateway_b64('command_output'), 0, 4000);
    $exitCode = isset($_POST['command_exit_code']) ? (int) $_POST['command_exit_code'] : ($commandStatus === 'done' ? 0 : 1);
    $stdout = substr(gateway_b64('command_stdout'), 0, 65535);
    $stderr = substr(gateway_b64('command_stderr'), 0, 65535);
    $diagnostics = substr(gateway_b64('command_diagnostics_json'), 0, 65535);
    $stmt = $conn->prepare('UPDATE vbox_commands SET status=?, result=?, exit_code=?, stdout=?, stderr=?, diagnostics_json=?, finished_at=NOW(), updated_at=NOW() WHERE id=? AND hostname=?');
    $stmt->bind_param('ssisssis', $commandStatus, $commandOutput, $exitCode, $stdout, $stderr, $diagnostics, $commandId, $hostname);
    $stmt->execute();
    if (gateway_table_exists('vbox_script_runs')) {
        try {
            $stmt = $conn->prepare('UPDATE vbox_script_runs SET status=?, result=?, updated_at=NOW() WHERE command_id=? AND hostname=?');
            $stmt->bind_param('ssis', $commandStatus, $commandOutput, $commandId, $hostname);
            $stmt->execute();
        } catch (Throwable $e) {
        }
    }
}

$payloadSql = gateway_column_exists('vbox_commands', 'payload') ? ', payload' : ', NULL AS payload';
$cmd = $conn->prepare("
    SELECT id, action, vmname{$payloadSql}
    FROM vbox_commands
    WHERE hostname=? AND status='pending'
    ORDER BY id ASC
    LIMIT 1
");
$cmd->bind_param('s', $hostname);
$cmd->execute();
$result = $cmd->get_result();

if ($row = $result->fetch_assoc()) {
    $id = (int) $row['id'];
    $update = $conn->prepare("UPDATE vbox_commands SET status='running', started_at=COALESCE(started_at, NOW()), updated_at=NOW() WHERE id=?");
    $update->bind_param('i', $id);
    $update->execute();

    header('Content-Type: text/plain; charset=utf-8');
    echo 'v2|' . $id . '|' . $row['action'] . '|' . base64_encode((string) $row['vmname']) . '|' . base64_encode((string) ($row['payload'] ?? ''));
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo 'none|';
