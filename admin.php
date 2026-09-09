<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
secure_session_start();
require_login();
if (current_user_role() !== 'admin') { http_response_code(403); exit('Administrator access required'); }
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
?>
<!doctype html><html lang="en" data-theme="light"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title>Administration | VMange</title>
<link rel="icon" href="assets/img/vmange-symbol.png">
<link rel="stylesheet" href="assets/css/app.css?v=2.0.0"><link rel="stylesheet" href="assets/css/admin.css?v=2.0.0">
<script src="assets/js/admin.js?v=2.0.0" defer></script></head>
<body><header class="admin-header"><a class="identity" href="index.php"><img src="assets/img/vmange-symbol.png" alt=""><strong>VMange</strong></a><span>Administration / 2.0</span><div class="actions"><button class="icon-btn" id="admin-theme" title="Theme" aria-label="Toggle theme">&#9680;</button><a class="btn ghost" href="docs.php?page=security">Handbook</a><a class="btn ghost" href="index.php">Dashboard</a></div></header>
<main class="admin-main"><nav class="admin-tabs" aria-label="Administration"><a href="#security">Security</a><a href="#installed">Installed Agents</a><a href="#releases">Releases</a><a href="#rollouts">Rollouts</a><a href="#backups">Configuration</a></nav>
<div id="admin-message" role="status" aria-live="polite"></div><section id="admin-content"><p>Loading administration...</p></section></main>
<dialog id="admin-dialog"><form method="dialog"><button class="icon-btn dialog-close" aria-label="Close" title="Close">&times;</button><h2>New host credential</h2><p>This credential is shown once. Update the host's protected environment file before its next heartbeat. The previous credential is no longer valid.</p><pre id="new-credential"></pre><button class="btn ghost">Close</button></form></dialog>
</body></html>
