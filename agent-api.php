<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('method not allowed');
}

if (!rate_limit('agent-api', 240, 60)) {
    http_response_code(429);
    exit('rate limited');
}

try {
    $hostname = validate_hostname($_POST['host'] ?? 'unknown');
} catch (Throwable $e) {
    http_response_code(422);
    exit('invalid host');
}
if (host_is_blocked($hostname)) {
    http_response_code(410);
    exit('host revoked');
}

$token = (string) ($_POST['token'] ?? '');
if (!verify_agent_token($hostname, $token)) {
    audit_log('agent_auth_failed', $hostname, 'Invalid host token');
    http_response_code(403);
    exit('forbidden');
}

function b64_field_agent(string $key): string
{
    $decoded = base64_decode((string) ($_POST[$key] ?? ''), true);
    return $decoded === false ? '' : $decoded;
}

$allVms = b64_field_agent('all_vms');
$runningVms = b64_field_agent('running_vms');
$vmSpecs = b64_field_agent('vm_specs');
$vmInventoryJson = b64_field_agent('vm_inventory_json');
$metricsJson = b64_field_agent('metrics_json');
$runningVms = merge_running_vms_from_metrics($metricsJson, $runningVms);
$vmSpecs = reconcile_vm_specs_with_running($vmSpecs, $runningVms);
[$runningNameMap, $runningUuidMap, $metrics] = running_state_from_metrics_json($metricsJson);
[$runningNameMap, $runningUuidMap] = running_state_from_raw($runningVms, $runningNameMap, $runningUuidMap);
$runtimeStatusJson = runtime_status_json($runningNameMap, $runningUuidMap);
$runningVms = merge_running_vms_from_metrics($runtimeStatusJson, $runningVms);
$vmSpecs = reconcile_vm_specs_with_maps($vmSpecs, $runningNameMap);
$vmInventoryJson = reconcile_inventory_with_running($vmInventoryJson, $runningNameMap, $runningUuidMap);
$metricsJson = rebuild_metrics_json_with_running($metricsJson, $metrics, $runningNameMap, $runningUuidMap);
$containersJson = b64_field_agent('containers_json');
$composeJson = b64_field_agent('compose_json');
$imagesJson = b64_field_agent('images_json');
$capabilitiesJson = b64_field_agent('capabilities_json');
$collectorErrorsJson = b64_field_agent('collector_errors_json');

$conn = db();
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
    if (should_persist_host_json($column, $value) && column_exists('vbox_hosts', $column)) {
        $stmt = $conn->prepare("UPDATE vbox_hosts SET `$column`=? WHERE hostname=?");
        $stmt->bind_param('ss', $value, $hostname);
        $stmt->execute();
    }
}

if ($metricsJson !== '' && table_exists('vbox_metrics')) {
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
    $commandOutput = substr(b64_field_agent('command_output'), 0, 4000);
    if (column_exists('vbox_commands', 'result') && column_exists('vbox_commands', 'updated_at')) {
        $stmt = $conn->prepare('UPDATE vbox_commands SET status=?, result=?, updated_at=NOW() WHERE id=? AND hostname=?');
        $stmt->bind_param('ssis', $commandStatus, $commandOutput, $commandId, $hostname);
    } else {
        $stmt = $conn->prepare('UPDATE vbox_commands SET status=? WHERE id=? AND hostname=?');
        $stmt->bind_param('sis', $commandStatus, $commandId, $hostname);
    }
    $stmt->execute();
}

$cmd = $conn->prepare("
    SELECT id, action, vmname" . (column_exists('vbox_commands', 'payload') ? ', payload' : ', NULL AS payload') . "
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

    $target = base64_encode((string) $row['vmname']);
    $payload = base64_encode((string) ($row['payload'] ?? ''));
    header('Content-Type: text/plain; charset=utf-8');
    echo 'v2|' . $id . '|' . $row['action'] . '|' . $target . '|' . $payload;
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo 'none|';
