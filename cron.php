<?php
declare(strict_types=1);

require_once __DIR__ . '/monitor_worker.php';

$secret = trim((string) (app_config()['cron_secret'] ?? ''));
$provided = (string) ($_GET['secret'] ?? ($_SERVER['HTTP_X_VMANGE_CRON_SECRET'] ?? ''));
$authorized = PHP_SAPI === 'cli' && $secret === '';
if ($secret !== '' && hash_equals($secret, $provided)) {
    $authorized = true;
}
if (!$authorized) {
    http_response_code(403);
    exit('forbidden');
}

header('Content-Type: application/json; charset=utf-8');
try {
    echo json_encode(run_monitor_worker(), JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'monitor worker failed'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    error_log('VMange monitor worker failed: ' . $e->getMessage());
}
