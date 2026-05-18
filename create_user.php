<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$lockFile = __DIR__ . '/storage/installer.lock';
if (is_file($lockFile)) {
    http_response_code(403);
    exit('Installer is locked. Remove storage/installer.lock only during a planned maintenance window.');
}

$isCli = PHP_SAPI === 'cli';
$config = app_config();
if (!$isCli) {
    secure_session_start();
    $setupToken = (string) ($config['setup_token'] ?? '');
    if ($setupToken === '' || !hash_equals($setupToken, (string) ($_GET['setup_token'] ?? ''))) {
        http_response_code(403);
        exit('Missing setup token.');
    }
}

$username = $isCli ? ($argv[1] ?? 'admin') : trim((string) ($_POST['username'] ?? ''));
$password = $isCli ? ($argv[2] ?? '') : (string) ($_POST['password'] ?? '');
$role = $isCli ? ($argv[3] ?? 'admin') : (string) ($_POST['role'] ?? 'admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' || $isCli) {
    if (!in_array($role, ['admin', 'operator', 'viewer'], true)) {
        exit('Invalid role.');
    }
    if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) {
        exit('Invalid username.');
    }
    if (strlen($password) < 14) {
        exit('Password must be at least 14 characters.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (column_exists('vbox_users', 'role')) {
        $stmt = db()->prepare('INSERT INTO vbox_users(username, password_hash, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), role=VALUES(role)');
        $stmt->bind_param('sss', $username, $hash, $role);
    } else {
        $stmt = db()->prepare('INSERT INTO vbox_users(username, password_hash) VALUES (?, ?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)');
        $stmt->bind_param('ss', $username, $hash);
    }
    $stmt->execute();
    file_put_contents($lockFile, 'locked ' . gmdate('c'));
    audit_log('installer_user_created', $username, 'Installer locked after account creation');
    exit('User created and installer locked.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create VMange User</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="login-body">
    <main class="login-shell">
        <section class="login-panel">
            <img src="assets/img/vmange-logo.png" alt="VMange" class="login-logo">
            <h1>Create administrator</h1>
            <form method="post" class="login-form">
                <label><span>Username</span><input name="username" required pattern="[A-Za-z0-9._-]{3,100}"></label>
                <label><span>Password</span><input name="password" type="password" required minlength="14"></label>
                <label><span>Role</span><select name="role"><option value="admin">Admin</option><option value="operator">Operator</option><option value="viewer">Viewer</option></select></label>
                <button class="btn primary" type="submit">Create and lock installer</button>
            </form>
        </section>
    </main>
</body>
</html>
