<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/monitor_worker.php';
$interval = max(30, (int) (getenv('VBOX_WORKER_INTERVAL') ?: 60));
while (true) {
    try {
        run_monitor_worker();
    } catch (Throwable $e) {
        error_log('VMange worker cycle failed: ' . $e->getMessage());
    }
    sleep($interval);
}
