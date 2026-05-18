<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$baseDir = __DIR__;
$storageDir = $baseDir . '/storage';
$lockFile = $storageDir . '/installer.lock';
$configFile = $baseDir . '/config.php';
$schemaFile = $baseDir . '/db/schema.sql';

if (!is_dir($storageDir)) {
    mkdir($storageDir, 0750, true);
}

session_name('vmange_installer');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/vbox/install.php'), '/') . '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf(): string
{
    if (empty($_SESSION['install_csrf'])) {
        $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['install_csrf'];
}

function check_csrf(): void
{
    if (!hash_equals($_SESSION['install_csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        throw new RuntimeException('Invalid installer session. Refresh the page and try again.');
    }
}

function default_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/vbox/install.php')), '/');
    return $scheme . '://' . $host . $dir;
}

function random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function connect_db(array $settings): mysqli
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = new mysqli($settings['db_host'], $settings['db_user'], $settings['db_pass'], $settings['db_name']);
    $conn->set_charset('utf8mb4');
    return $conn;
}

function import_schema(mysqli $conn, string $schemaFile): void
{
    if (!is_file($schemaFile)) {
        throw new RuntimeException('Schema file not found: db/schema.sql');
    }

    $sql = file_get_contents($schemaFile);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('Schema file is empty.');
    }

    if (!$conn->multi_query($sql)) {
        throw new RuntimeException('Could not start schema import: ' . $conn->error);
    }

    do {
        if ($result = $conn->store_result()) {
            $result->free();
        }
        if ($conn->errno) {
            throw new RuntimeException('Schema import failed: ' . $conn->error);
        }
    } while ($conn->more_results() && $conn->next_result());
}

function write_config(string $file, array $settings): void
{
    $config = [
        'app_name' => 'VMange',
        'base_url' => $settings['base_url'],
        'force_https' => $settings['force_https'],
        'db_host' => $settings['db_host'],
        'db_name' => $settings['db_name'],
        'db_user' => $settings['db_user'],
        'db_pass' => $settings['db_pass'],
        'legacy_agent_token' => $settings['agent_token'],
        'setup_token' => $settings['setup_token'],
        'cookie_path' => $settings['cookie_path'],
        'online_window_seconds' => 120,
        'rate_limit_window' => 60,
        'rate_limit_max' => 90,
    ];

    $content = "<?php\nreturn " . var_export($config, true) . ";\n";
    if (file_put_contents($file, $content, LOCK_EX) === false) {
        throw new RuntimeException('Could not write config.php. Check folder permissions.');
    }
    @chmod($file, 0640);
}

function create_admin(mysqli $conn, string $username, string $password): void
{
    if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) {
        throw new RuntimeException('Admin username must be 3-100 letters, numbers, dots, underscores, or dashes.');
    }
    if (strlen($password) < 14) {
        throw new RuntimeException('Admin password must be at least 14 characters.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $role = 'admin';
    $hasRole = false;
    $column = $conn->query("SHOW COLUMNS FROM `vbox_users` LIKE 'role'");
    if ($column && $column->num_rows > 0) {
        $hasRole = true;
    }

    if ($hasRole) {
        $stmt = $conn->prepare('INSERT INTO vbox_users(username, password_hash, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), role=VALUES(role)');
        $stmt->bind_param('sss', $username, $hash, $role);
    } else {
        $stmt = $conn->prepare('INSERT INTO vbox_users(username, password_hash) VALUES (?, ?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)');
        $stmt->bind_param('ss', $username, $hash);
    }
    $stmt->execute();
}

function render_page(string $title, string $body): never
{
    echo '<!DOCTYPE html><html lang="en" data-theme="dark"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . h($title) . '</title><link rel="stylesheet" href="assets/css/app.css"></head><body class="login-body"><main class="login-shell"><section class="login-panel"><img src="assets/img/vmange-logo.png" alt="VMange" class="login-logo">' . $body . '</section></main></body></html>';
    exit;
}

if (is_file($lockFile)) {
    render_page('VMange Installer Locked', '<h1>Installer locked</h1><p class="muted">VMange is already installed. Remove <code>storage/installer.lock</code> only during a planned reinstall.</p><a class="btn primary" href="index.php">Go to login</a>');
}

$error = '';
$step = $_POST['step'] ?? 'settings';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();

        if ($step === 'confirm') {
            $settings = [
                'base_url' => rtrim(trim((string) $_POST['base_url']), '/'),
                'force_https' => ($_POST['force_https'] ?? '') === '1',
                'cookie_path' => trim((string) $_POST['cookie_path']),
                'db_host' => trim((string) $_POST['db_host']),
                'db_name' => trim((string) $_POST['db_name']),
                'db_user' => trim((string) $_POST['db_user']),
                'db_pass' => (string) $_POST['db_pass'],
                'agent_token' => trim((string) $_POST['agent_token']),
                'setup_token' => trim((string) $_POST['setup_token']),
            ];

            if ($settings['base_url'] === '' || !preg_match('#^https?://#', $settings['base_url'])) {
                throw new RuntimeException('Base URL must start with http:// or https://.');
            }
            if ($settings['cookie_path'] === '') {
                $path = parse_url($settings['base_url'], PHP_URL_PATH);
                $settings['cookie_path'] = rtrim((string) $path, '/') . '/';
            }
            foreach (['db_host', 'db_name', 'db_user'] as $required) {
                if ($settings[$required] === '') {
                    throw new RuntimeException('Database host, name, and user are required.');
                }
            }
            if (strlen($settings['agent_token']) < 24 || strlen($settings['setup_token']) < 24) {
                throw new RuntimeException('Agent token and setup token must be long random values.');
            }

            $conn = connect_db($settings);
            $conn->query('SELECT 1');
            $_SESSION['install_settings'] = $settings;

            render_page('Confirm VMange Install', '
                <h1>Confirm installation</h1>
                <p class="muted">Database connection succeeded. Confirm these settings, then create the first admin account.</p>
                <div class="metric-grid">
                    <article class="metric-card"><span>Base URL</span><strong style="font-size:16px">' . h($settings['base_url']) . '</strong><span>' . h($settings['cookie_path']) . '</span></article>
                    <article class="metric-card"><span>Database</span><strong style="font-size:16px">' . h($settings['db_name']) . '</strong><span>' . h($settings['db_host']) . '</span></article>
                </div>
                <form method="post" class="login-form">
                    <input type="hidden" name="csrf" value="' . h(csrf()) . '">
                    <input type="hidden" name="step" value="install">
                    <label><span>Admin username</span><input name="admin_user" required pattern="[A-Za-z0-9._-]{3,100}" value="admin"></label>
                    <label><span>Admin password</span><input name="admin_pass" type="password" required minlength="14"></label>
                    <label><span>Confirm password</span><input name="admin_pass_confirm" type="password" required minlength="14"></label>
                    <button class="btn primary" type="submit">Import database and create admin</button>
                </form>
                <form method="get" class="login-form"><button class="btn ghost" type="submit">Back</button></form>
            ');
        }

        if ($step === 'install') {
            $settings = $_SESSION['install_settings'] ?? null;
            if (!is_array($settings)) {
                throw new RuntimeException('Installer settings expired. Start again.');
            }
            $password = (string) $_POST['admin_pass'];
            if (!hash_equals($password, (string) $_POST['admin_pass_confirm'])) {
                throw new RuntimeException('Admin passwords do not match.');
            }

            $conn = connect_db($settings);
            import_schema($conn, $GLOBALS['schemaFile']);
            create_admin($conn, trim((string) $_POST['admin_user']), $password);
            write_config($GLOBALS['configFile'], $settings);
            file_put_contents($GLOBALS['lockFile'], 'locked ' . gmdate('c'), LOCK_EX);
            unset($_SESSION['install_settings']);

            render_page('VMange Installed', '<h1>Installation complete</h1><p class="muted">The database schema was imported, config.php was written, and the admin user was created. The installer is now locked.</p><a class="btn primary" href="index.php">Open login</a>');
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$baseUrl = default_base_url();
$cookiePath = rtrim((string) parse_url($baseUrl, PHP_URL_PATH), '/') . '/';
$agentToken = random_token();
$setupToken = random_token();

render_page('Install VMange', '
    <h1>Install VMange</h1>
    <p class="muted">Enter the deployment base URL and database credentials. The next step will confirm the connection before importing the schema and creating admin.</p>
    ' . ($error ? '<div class="alert error">' . h($error) . '</div>' : '') . '
    <form method="post" class="login-form">
        <input type="hidden" name="csrf" value="' . h(csrf()) . '">
        <input type="hidden" name="step" value="confirm">
        <label><span>Base URL</span><input name="base_url" required value="' . h($baseUrl) . '"></label>
        <label><span>Cookie path</span><input name="cookie_path" required value="' . h($cookiePath) . '"></label>
        <label><span>Force HTTPS</span><select name="force_https"><option value="1" selected>Yes</option><option value="0">No</option></select></label>
        <label><span>Database host</span><input name="db_host" required value="localhost"></label>
        <label><span>Database name</span><input name="db_name" required value="vmange"></label>
        <label><span>Database user</span><input name="db_user" required value="vmange"></label>
        <label><span>Database password</span><input name="db_pass" type="password"></label>
        <label><span>Agent token</span><input name="agent_token" required value="' . h($agentToken) . '"></label>
        <label><span>Setup token</span><input name="setup_token" required value="' . h($setupToken) . '"></label>
        <button class="btn primary" type="submit">Test database and continue</button>
    </form>
');
