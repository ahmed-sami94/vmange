<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/releases.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (!is_https()) { http_response_code(403); exit('HTTPS required'); }
$hostname = (string)($_SERVER['HTTP_X_VMANGE_HOST'] ?? '');
$authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$token = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '';
require_agent_identity(db(), $hostname, $token);
try {
    $version = release_version((string)($_GET['version'] ?? ''));
    $stmt = db()->prepare("SELECT sha256 FROM vbox_agent_releases WHERE version=? AND status='published'");
    $stmt->execute([$version]);
    $row = $stmt->get_result()->fetch_assoc();
    $path = release_storage() . '/' . $version . '.sh';
    if (!$row || !is_file($path) || !hash_equals($row['sha256'], hash_file('sha256', $path))) { http_response_code(404); exit('Release unavailable'); }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="vmange-agent-' . $version . '.sh"');
    readfile($path);
} catch (Throwable $error) { http_response_code(422); echo 'Release unavailable'; }
