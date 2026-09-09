<?php
declare(strict_types=1);

function agent_request_is_https(): bool
{
    if ((!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
    $trusted = array_filter(array_map('trim', explode(',', (string)getenv('VBOX_TRUSTED_PROXY_IPS'))));
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', $trusted, true) && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/** Shared by all heartbeat routes, without loading the dashboard session. */
function agent_token_valid(mysqli $conn, string $hostname, string $token): bool
{
    if (!preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $hostname) || strlen($token) < 32 || strlen($token) > 256) {
        return false;
    }
    $stmt = $conn->prepare('SELECT token_hash FROM vbox_host_tokens t WHERE hostname=? AND active=1 AND NOT EXISTS (SELECT 1 FROM vbox_host_blocks b WHERE b.hostname=t.hostname) ORDER BY id DESC LIMIT 1');
    $stmt->bind_param('s', $hostname);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row !== null && hash_equals((string) $row['token_hash'], hash('sha256', $token));
}

function require_agent_identity(mysqli $conn, string $hostname, string $token): void
{
    if (!agent_request_is_https()) {
        http_response_code(400);
        exit('Verified HTTPS is required; configure a trusted reverse proxy explicitly if used.');
    }
    if (!agent_token_valid($conn, $hostname, $token)) {
        error_log('VMange agent authentication rejected for host ' . preg_replace('/[^A-Za-z0-9._-]/', '', $hostname));
        $safeHost = substr(preg_replace('/[^A-Za-z0-9._-]/', '', $hostname), 0, 100);
        $stmt = $conn->prepare("INSERT INTO vbox_audit_logs(action,target,details) SELECT 'agent_auth_failed',?,'Invalid or revoked host credential' WHERE NOT EXISTS (SELECT 1 FROM vbox_audit_logs WHERE action='agent_auth_failed' AND target=? AND created_at>DATE_SUB(NOW(),INTERVAL 1 MINUTE))");
        $stmt->execute([$safeHost,$safeHost]);
        http_response_code(403);
        header('Cache-Control: no-store');
        exit('Invalid or revoked host credential. Re-enroll this host from VMange.');
    }
}
