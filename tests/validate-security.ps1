$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot

$dbSource = Get-Content -LiteralPath (Join-Path $projectRoot 'db.php') -Raw
$indexSource = Get-Content -LiteralPath (Join-Path $projectRoot 'index.php') -Raw
$workerSource = Get-Content -LiteralPath (Join-Path $projectRoot 'monitor_worker.php') -Raw
$schemaSource = Get-Content -LiteralPath (Join-Path $projectRoot 'db\schema.sql') -Raw
$createUserSource = Get-Content -LiteralPath (Join-Path $projectRoot 'create_user.php') -Raw

if ($dbSource -match "change-me-agent-token") {
    throw 'Insecure default agent token is present'
}
if ($indexSource -notmatch 'role_allows_action\(current_user_role\(\), \$resourceAction\)') {
    throw 'Generic command endpoint does not enforce centralized action authorization'
}
if ($indexSource -notmatch "terminal_exec.*terminal_enabled") {
    throw 'Terminal command endpoint is not gated by terminal_enabled'
}
if ($workerSource -match '@mail\(') {
    throw 'Monitor worker bypasses the configured notification transport'
}
if ($workerSource -notmatch 'vmange_send_mail') {
    throw 'Monitor worker does not use the shared mail transport'
}
if ($indexSource -match 'https://cdn\.jsdelivr\.net') {
    throw 'Dashboard still loads executable JavaScript from a third-party CDN'
}
if ($createUserSource -notmatch "setup_csrf_token" -or $createUserSource -notmatch "LOCK_EX") {
    throw 'Standalone account installer is missing CSRF or exclusive lock protection'
}
if ($schemaSource -notmatch 'CREATE TABLE IF NOT EXISTS `vbox_notification_deliveries`[\s\S]+`next_attempt_at` datetime') {
    throw 'Notification retry schema is incomplete'
}
if ($schemaSource -match 'CREATE TABLE IF NOT EXISTS `vbox_script_runs`[\s\S]{0,700}`subject`') {
    throw 'Notification fields were added to the script run table'
}

Write-Output 'Security contract checks passed'
