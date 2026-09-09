<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notifications.php';

function worker_json(?string $value): array
{
    $decoded = json_decode((string) $value, true);
    return is_array($decoded) ? $decoded : [];
}

function worker_metric(array $host, string $metric, int $onlineWindow): ?float
{
    if ($metric === 'offline') {
        if (empty($host['last_seen'])) {
            return 1.0;
        }
        $age = time() - strtotime((string) $host['last_seen']);
        return $age > $onlineWindow ? 1.0 : 0.0;
    }
    $metrics = worker_json($host['metrics_json'] ?? null);
    return match ($metric) {
        'cpu' => (float) ($metrics['cpu'] ?? 0),
        'memory' => (float) (($metrics['ram_total_mb'] ?? 0) > 0 ? (($metrics['ram_used_mb'] ?? 0) / max(1, $metrics['ram_total_mb'])) * 100 : 0),
        'disk' => (float) (($metrics['disk_total_mb'] ?? 0) > 0 ? (($metrics['disk_used_mb'] ?? 0) / max(1, $metrics['disk_total_mb'])) * 100 : 0),
        default => null,
    };
}

function worker_matches(float $value, string $operator, float $threshold): bool
{
    return match ($operator) {
        '>' => $value > $threshold,
        '<' => $value < $threshold,
        '<=' => $value <= $threshold,
        '=' => abs($value - $threshold) < 0.0001,
        default => $value >= $threshold,
    };
}

function worker_webhook(string $body): string
{
    $cfg = app_config();
    $url = trim((string) ($cfg['webhook_url'] ?? ''));
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return 'webhook not configured';
    }
    $headers = "Content-Type: application/json\r\n";
    $secret = trim((string) ($cfg['webhook_secret'] ?? ''));
    if ($secret !== '') {
        $headers .= 'X-VMange-Signature: ' . hash_hmac('sha256', $body, $secret) . "\r\n";
    }
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => $headers,
        'content' => $body,
        'timeout' => 8,
        'ignore_errors' => true,
    ]]);
    $result = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if ($result === false || !preg_match('/\s2\d\d\s/', $statusLine)) {
        return 'webhook delivery failed: ' . ($statusLine !== '' ? $statusLine : 'no HTTP response');
    }
    return 'webhook delivered: ' . $statusLine;
}

function worker_queue_email(int $eventId, string $recipient, string $subject, string $body): void
{
    $status = 'pending';
    $stmt = db()->prepare('INSERT INTO vbox_notification_deliveries(alarm_event_id, channel, recipient, status, subject, body, next_attempt_at, updated_at) VALUES (?, "email", ?, ?, ?, ?, NOW(), NOW())');
    $stmt->bind_param('issss', $eventId, $recipient, $status, $subject, $body);
    $stmt->execute();
}

function worker_deliver_pending_email(): array
{
    $sent = 0;
    $failed = 0;
    $rows = db()->query("SELECT id, alarm_event_id, recipient, subject, body, attempts FROM vbox_notification_deliveries WHERE channel='email' AND status IN ('pending','retrying') AND (next_attempt_at IS NULL OR next_attempt_at <= NOW()) ORDER BY id LIMIT 20");
    while ($delivery = $rows->fetch_assoc()) {
        [$accepted, $message] = vmange_send_mail((string) $delivery['recipient'], (string) $delivery['subject'], (string) $delivery['body']);
        $attempts = (int) $delivery['attempts'] + 1;
        $status = $accepted ? 'sent' : ($attempts >= 5 ? 'failed' : 'retrying');
        $delayMinutes = min(60, 2 ** min($attempts, 6));
        $deliveryId = (int) $delivery['id'];
        if ($accepted || $attempts >= 5) {
            $stmt = db()->prepare('UPDATE vbox_notification_deliveries SET status=?, result=?, attempts=?, next_attempt_at=NULL, updated_at=NOW() WHERE id=?');
            $stmt->bind_param('ssii', $status, $message, $attempts, $deliveryId);
        } else {
            $stmt = db()->prepare('UPDATE vbox_notification_deliveries SET status=?, result=?, attempts=?, next_attempt_at=DATE_ADD(NOW(), INTERVAL ? MINUTE), updated_at=NOW() WHERE id=?');
            $stmt->bind_param('ssiii', $status, $message, $attempts, $delayMinutes, $deliveryId);
        }
        $stmt->execute();
        if ($accepted) {
            $eventId = (int) $delivery['alarm_event_id'];
            $eventStmt = db()->prepare('UPDATE vbox_alarm_events SET last_notified_at=NOW() WHERE id=?');
            $eventStmt->bind_param('i', $eventId);
            $eventStmt->execute();
            $sent++;
        } else {
            $failed++;
        }
    }
    return ['sent' => $sent, 'failed' => $failed];
}

function run_monitor_worker(): array
{
    $conn = db();
    $cfg = app_config();
    $onlineWindow = (int) ($cfg['online_window_seconds'] ?? 120);
    $retention = max(1, (int) ($cfg['metrics_retention_hours'] ?? 6));
    $conn->query("DELETE FROM vbox_metrics WHERE created_at < DATE_SUB(NOW(), INTERVAL {$retention} HOUR)");
    if (column_exists('vbox_commands', 'lease_expires_at')) {
        $conn->query("UPDATE vbox_commands SET status='expired', updated_at=NOW(), error_code='lease_expired', result='Command lease expired before the agent reported a result' WHERE status='running' AND lease_expires_at IS NOT NULL AND lease_expires_at < NOW()");
    }

    $hosts = [];
    $result = $conn->query('SELECT hostname, last_seen, metrics_json FROM vbox_hosts');
    while ($row = $result->fetch_assoc()) {
        $hosts[] = $row;
    }
    $rules = [];
    $result = $conn->query('SELECT * FROM vbox_alarm_rules WHERE enabled=1');
    while ($row = $result->fetch_assoc()) {
        $rules[] = $row;
    }
    $opened = 0;
    $resolved = 0;
    foreach ($rules as $rule) {
        foreach ($hosts as $host) {
            $value = worker_metric($host, (string) $rule['metric'], $onlineWindow);
            if ($value === null) {
                continue;
            }
            $matched = worker_matches($value, (string) $rule['operator'], (float) $rule['threshold']);
            $stmt = $conn->prepare("SELECT id FROM vbox_alarm_events WHERE rule_id=? AND hostname=? AND status IN ('active','acknowledged') ORDER BY id DESC LIMIT 1");
            $stmt->bind_param('is', $rule['id'], $host['hostname']);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            if ($matched && !$existing) {
                $message = sprintf('%s on %s is %.2f (%s %.2f)', $rule['metric'], $host['hostname'], $value, $rule['operator'], $rule['threshold']);
                $stmt = $conn->prepare('INSERT INTO vbox_alarm_events(rule_id, hostname, metric_value, message) VALUES (?, ?, ?, ?)');
                $stmt->bind_param('isds', $rule['id'], $host['hostname'], $value, $message);
                $stmt->execute();
                $eventId = (int) $conn->insert_id;
                $recipient = trim((string) ($rule['notify_email'] ?? ''));
                if ($recipient !== '') {
                    worker_queue_email($eventId, $recipient, 'VMange alarm: ' . $rule['name'], $message);
                }
                $webhookBody = json_encode(['event' => 'alarm_opened', 'id' => $eventId, 'rule' => $rule['name'], 'hostname' => $host['hostname'], 'message' => $message], JSON_UNESCAPED_SLASHES);
                if ($webhookBody !== false && ($cfg['webhook_url'] ?? '') !== '') {
                    $delivery = worker_webhook($webhookBody);
                    $stmt = $conn->prepare('INSERT INTO vbox_notification_deliveries(alarm_event_id, channel, recipient, status, result) VALUES (?, "webhook", ?, ?, ?)');
                    $recipient = (string) $cfg['webhook_url'];
                    $status = str_contains($delivery, 'delivered') ? 'sent' : 'failed';
                    $stmt->bind_param('isss', $eventId, $recipient, $status, $delivery);
                    $stmt->execute();
                }
                $opened++;
            } elseif (!$matched && $existing) {
                $stmt = $conn->prepare("UPDATE vbox_alarm_events SET status='resolved', resolved_at=NOW() WHERE id=?");
                $stmt->bind_param('i', $existing['id']);
                $stmt->execute();
                $resolved++;
            }
        }
    }
    $deliverySummary = worker_deliver_pending_email();
    $summary = ['ok' => true, 'hosts' => count($hosts), 'rules' => count($rules), 'opened' => $opened, 'resolved' => $resolved, 'email' => $deliverySummary, 'retention_hours' => $retention];
    save_setting('monitor_last_run_at', gmdate('Y-m-d H:i:s') . ' UTC');
    save_setting('monitor_last_result', json_encode($summary, JSON_UNESCAPED_SLASHES));
    return $summary;
}
