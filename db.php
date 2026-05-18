<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', getenv('VBOX_DEBUG') === '1' ? '1' : '0');
ini_set('log_errors', '1');

function app_env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $defaults = [
        'app_name' => 'VMange',
        'base_url' => app_env('VBOX_BASE_URL', ''),
        'force_https' => app_env('VBOX_FORCE_HTTPS', '0') === '1',
        'session_name' => app_env('VBOX_SESSION_NAME', 'vmange_session'),
        'cookie_path' => app_env('VBOX_COOKIE_PATH', ''),
        'db_host' => app_env('VBOX_DB_HOST', 'localhost'),
        'db_name' => app_env('VBOX_DB_NAME', 'vmange'),
        'db_user' => app_env('VBOX_DB_USER', 'vmange'),
        'db_pass' => app_env('VBOX_DB_PASS', 'change-me'),
        'legacy_agent_token' => app_env('VBOX_AGENT_TOKEN', 'change-me-agent-token'),
        'setup_token' => app_env('VBOX_SETUP_TOKEN', ''),
        'rate_limit_window' => (int) app_env('VBOX_RATE_LIMIT_WINDOW', '60'),
        'rate_limit_max' => (int) app_env('VBOX_RATE_LIMIT_MAX', '90'),
        'online_window_seconds' => (int) app_env('VBOX_ONLINE_WINDOW', '120'),
        'gateway_url' => app_env('VBOX_GATEWAY_URL', ''),
        'terminal_gateway_enabled' => app_env('VBOX_TERMINAL_GATEWAY_ENABLED', '0') === '1',
        'terminal_gateway_url' => app_env('VBOX_TERMINAL_GATEWAY_URL', ''),
        'terminal_gateway_token' => app_env('VBOX_TERMINAL_GATEWAY_TOKEN', ''),
        'docs_enabled' => app_env('VBOX_DOCS_ENABLED', '1') !== '0',
        'mail_from' => app_env('VBOX_MAIL_FROM', ''),
        'smtp_host' => app_env('VBOX_SMTP_HOST', ''),
        'smtp_port' => (int) app_env('VBOX_SMTP_PORT', '587'),
        'smtp_username' => app_env('VBOX_SMTP_USERNAME', ''),
        'smtp_password' => app_env('VBOX_SMTP_PASSWORD', ''),
        'smtp_encryption' => app_env('VBOX_SMTP_ENCRYPTION', 'tls'),
        'imap_host' => app_env('VBOX_IMAP_HOST', ''),
        'imap_port' => (int) app_env('VBOX_IMAP_PORT', '993'),
        'imap_username' => app_env('VBOX_IMAP_USERNAME', ''),
        'imap_password' => app_env('VBOX_IMAP_PASSWORD', ''),
        'imap_encryption' => app_env('VBOX_IMAP_ENCRYPTION', 'ssl'),
    ];

    $external = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vbox-config.php';
    $local = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
    foreach ([$external, $local] as $file) {
        if (is_file($file)) {
            $loaded = require $file;
            if (is_array($loaded)) {
                $defaults = array_replace($defaults, $loaded);
            }
        }
    }

    $config = $defaults;
    return $config;
}

function secure_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $config = app_config();
    $secure = is_https();
    session_name($config['session_name']);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $config['cookie_path'] ?: base_path(),
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function base_path(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $dir = str_replace('\\', '/', dirname($script));
    return rtrim($dir, '/') . '/';
}

function base_url(string $path = ''): string
{
    $config = app_config();
    $base = rtrim((string) $config['base_url'], '/');
    if ($base === '') {
        $scheme = is_https() ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = $scheme . '://' . $host . rtrim(base_path(), '/');
    }
    return $base . '/' . ltrim($path, '/');
}

function db(): mysqli
{
    static $conn = null;
    if ($conn instanceof mysqli) {
        return $conn;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $config = app_config();
    $conn = new mysqli($config['db_host'], $config['db_user'], $config['db_pass'], $config['db_name']);
    $conn->set_charset('utf8mb4');
    ensure_runtime_schema($conn);
    return $conn;
}

$conn = db();
$TOKEN = app_config()['legacy_agent_token'];

function ensure_runtime_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $statements = [
        "CREATE TABLE IF NOT EXISTS `vbox_metrics` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `hostname` varchar(100) NOT NULL,
            `cpu_percent` decimal(5,2) NOT NULL DEFAULT 0,
            `load1` decimal(8,2) NOT NULL DEFAULT 0,
            `ram_used_mb` int(11) NOT NULL DEFAULT 0,
            `ram_total_mb` int(11) NOT NULL DEFAULT 0,
            `swap_used_mb` int(11) NOT NULL DEFAULT 0,
            `swap_total_mb` int(11) NOT NULL DEFAULT 0,
            `disk_used_mb` int(11) NOT NULL DEFAULT 0,
            `disk_total_mb` int(11) NOT NULL DEFAULT 0,
            `rx_bytes` bigint(20) NOT NULL DEFAULT 0,
            `tx_bytes` bigint(20) NOT NULL DEFAULT 0,
            `created_at` datetime DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `host_time` (`hostname`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `vbox_host_tokens` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `hostname` varchar(100) NOT NULL,
            `token_hash` char(64) NOT NULL,
            `active` tinyint(1) NOT NULL DEFAULT 1,
            `created_at` datetime DEFAULT current_timestamp(),
            `rotated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `host_active` (`hostname`,`active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `vbox_host_blocks` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `hostname` varchar(100) NOT NULL,
            `created_at` datetime DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `hostname` (`hostname`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `vbox_audit_logs` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `username` varchar(100) DEFAULT NULL,
            `action` varchar(100) NOT NULL,
            `target` varchar(255) DEFAULT NULL,
            `details` text DEFAULT NULL,
            `ip_address` varchar(64) DEFAULT NULL,
            `created_at` datetime DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `created_at` (`created_at`),
            KEY `action` (`action`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `vbox_rate_limits` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `rate_key` char(64) NOT NULL,
            `created_at` datetime DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `rate_key` (`rate_key`),
            KEY `created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `vbox_compose_stacks` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `hostname` varchar(100) NOT NULL,
            `project` varchar(100) NOT NULL,
            `compose_yaml` longtext NOT NULL,
            `status` varchar(64) NOT NULL DEFAULT 'saved',
            `last_action` varchar(64) DEFAULT NULL,
            `last_result` text DEFAULT NULL,
            `created_by` varchar(100) DEFAULT NULL,
            `created_at` datetime DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `host_project` (`hostname`,`project`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `vbox_scripts` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `body` longtext NOT NULL,
            `description` text DEFAULT NULL,
            `created_by` varchar(100) DEFAULT NULL,
            `created_at` datetime DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `vbox_script_runs` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `script_id` bigint(20) NOT NULL,
            `hostname` varchar(100) NOT NULL,
            `command_id` bigint(20) DEFAULT NULL,
            `status` varchar(32) NOT NULL DEFAULT 'pending',
            `result` text DEFAULT NULL,
            `created_at` datetime DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `script_host` (`script_id`,`hostname`),
            KEY `command_id` (`command_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `vbox_settings` (
            `setting_key` varchar(120) NOT NULL,
            `setting_value` longtext DEFAULT NULL,
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `vbox_alarm_rules` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `name` varchar(120) NOT NULL,
            `metric` varchar(64) NOT NULL,
            `operator` varchar(8) NOT NULL DEFAULT '>=',
            `threshold` decimal(10,2) NOT NULL DEFAULT 0,
            `enabled` tinyint(1) NOT NULL DEFAULT 1,
            `notify_email` varchar(255) DEFAULT NULL,
            `cooldown_minutes` int(11) NOT NULL DEFAULT 15,
            `created_at` datetime DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `vbox_alarm_events` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `rule_id` bigint(20) NOT NULL,
            `hostname` varchar(100) NOT NULL,
            `metric_value` decimal(12,2) NOT NULL DEFAULT 0,
            `status` enum('active','resolved','acknowledged') NOT NULL DEFAULT 'active',
            `message` text DEFAULT NULL,
            `opened_at` datetime DEFAULT current_timestamp(),
            `resolved_at` datetime DEFAULT NULL,
            `acknowledged_at` datetime DEFAULT NULL,
            `last_notified_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `rule_host_status` (`rule_id`,`hostname`,`status`),
            KEY `opened_at` (`opened_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `vbox_notification_deliveries` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `alarm_event_id` bigint(20) DEFAULT NULL,
            `channel` varchar(32) NOT NULL,
            `recipient` varchar(255) DEFAULT NULL,
            `status` varchar(32) NOT NULL DEFAULT 'pending',
            `result` text DEFAULT NULL,
            `created_at` datetime DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `alarm_event_id` (`alarm_event_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($statements as $sql) {
        try {
            $conn->query($sql);
        } catch (Throwable $e) {
            error_log('VMange ensure_runtime_schema create failed: ' . $e->getMessage());
        }
    }

    $hostColumns = [
        'all_vms' => "ALTER TABLE `vbox_hosts` ADD COLUMN `all_vms` text DEFAULT NULL",
        'running_vms' => "ALTER TABLE `vbox_hosts` ADD COLUMN `running_vms` text DEFAULT NULL",
        'vm_specs' => "ALTER TABLE `vbox_hosts` ADD COLUMN `vm_specs` longtext DEFAULT NULL",
        'vm_inventory_json' => "ALTER TABLE `vbox_hosts` ADD COLUMN `vm_inventory_json` longtext DEFAULT NULL",
        'runtime_status_json' => "ALTER TABLE `vbox_hosts` ADD COLUMN `runtime_status_json` longtext DEFAULT NULL",
        'metrics_json' => "ALTER TABLE `vbox_hosts` ADD COLUMN `metrics_json` longtext DEFAULT NULL",
        'containers_json' => "ALTER TABLE `vbox_hosts` ADD COLUMN `containers_json` longtext DEFAULT NULL",
        'compose_json' => "ALTER TABLE `vbox_hosts` ADD COLUMN `compose_json` longtext DEFAULT NULL",
        'images_json' => "ALTER TABLE `vbox_hosts` ADD COLUMN `images_json` longtext DEFAULT NULL",
        'capabilities_json' => "ALTER TABLE `vbox_hosts` ADD COLUMN `capabilities_json` longtext DEFAULT NULL",
        'collector_errors_json' => "ALTER TABLE `vbox_hosts` ADD COLUMN `collector_errors_json` longtext DEFAULT NULL",
        'agent_debug' => "ALTER TABLE `vbox_hosts` ADD COLUMN `agent_debug` longtext DEFAULT NULL",
        'wol_mac' => "ALTER TABLE `vbox_hosts` ADD COLUMN `wol_mac` varchar(32) DEFAULT NULL",
        'wol_broadcast' => "ALTER TABLE `vbox_hosts` ADD COLUMN `wol_broadcast` varchar(64) DEFAULT NULL",
        'wol_port' => "ALTER TABLE `vbox_hosts` ADD COLUMN `wol_port` int(11) NOT NULL DEFAULT 9",
        'wol_relay_host' => "ALTER TABLE `vbox_hosts` ADD COLUMN `wol_relay_host` varchar(100) DEFAULT NULL",
        'created_at' => "ALTER TABLE `vbox_hosts` ADD COLUMN `created_at` datetime DEFAULT current_timestamp()",
    ];
    foreach ($hostColumns as $column => $sql) {
        if (!runtime_column_exists($conn, 'vbox_hosts', $column)) {
            try {
                $conn->query($sql);
            } catch (Throwable $e) {
                error_log('VMange ensure_runtime_schema host column failed for ' . $column . ': ' . $e->getMessage());
            }
        }
    }

    $userColumns = [
        'role' => "ALTER TABLE `vbox_users` ADD COLUMN `role` enum('admin','operator','viewer') NOT NULL DEFAULT 'admin'",
    ];
    foreach ($userColumns as $column => $sql) {
        if (!runtime_column_exists($conn, 'vbox_users', $column)) {
            try {
                $conn->query($sql);
            } catch (Throwable $e) {
                error_log('VMange ensure_runtime_schema user column failed for ' . $column . ': ' . $e->getMessage());
            }
        }
    }

    $commandColumns = [
        'payload' => "ALTER TABLE `vbox_commands` ADD COLUMN `payload` longtext DEFAULT NULL",
        'requested_by' => "ALTER TABLE `vbox_commands` ADD COLUMN `requested_by` varchar(100) DEFAULT NULL",
        'result' => "ALTER TABLE `vbox_commands` ADD COLUMN `result` text DEFAULT NULL",
        'updated_at' => "ALTER TABLE `vbox_commands` ADD COLUMN `updated_at` datetime DEFAULT NULL",
        'started_at' => "ALTER TABLE `vbox_commands` ADD COLUMN `started_at` datetime DEFAULT NULL",
        'finished_at' => "ALTER TABLE `vbox_commands` ADD COLUMN `finished_at` datetime DEFAULT NULL",
        'exit_code' => "ALTER TABLE `vbox_commands` ADD COLUMN `exit_code` int DEFAULT NULL",
        'stdout' => "ALTER TABLE `vbox_commands` ADD COLUMN `stdout` mediumtext DEFAULT NULL",
        'stderr' => "ALTER TABLE `vbox_commands` ADD COLUMN `stderr` mediumtext DEFAULT NULL",
        'diagnostics_json' => "ALTER TABLE `vbox_commands` ADD COLUMN `diagnostics_json` longtext DEFAULT NULL",
    ];
    foreach ($commandColumns as $column => $sql) {
        if (!runtime_column_exists($conn, 'vbox_commands', $column)) {
            try {
                $conn->query($sql);
            } catch (Throwable $e) {
                error_log('VMange ensure_runtime_schema command column failed for ' . $column . ': ' . $e->getMessage());
            }
        }
    }
    try {
        $conn->query("ALTER TABLE `vbox_commands` MODIFY `status` enum('pending','sent','running','done','failed','expired') NOT NULL DEFAULT 'pending'");
    } catch (Throwable $e) {
        error_log('VMange ensure_runtime_schema command status enum failed: ' . $e->getMessage());
    }

    $done = true;
}

function runtime_column_exists(mysqli $conn, string $table, string $column): bool
{
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->bind_param('s', $column);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function setting_value(string $key, ?string $default = null): ?string
{
    if (!table_exists('vbox_settings')) {
        return $default;
    }
    $stmt = db()->prepare('SELECT setting_value FROM vbox_settings WHERE setting_key=? LIMIT 1');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (string) $row['setting_value'] : $default;
}

function save_setting(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT INTO vbox_settings(setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=NOW()');
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function csrf_token(): string
{
    secure_session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    secure_session_start();
    $token = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        audit_log('csrf_failed', null, 'Blocked request with invalid CSRF token');
        json_response(['ok' => false, 'error' => 'Invalid CSRF token'], 419);
    }
}

function require_login(bool $json = false): void
{
    secure_session_start();
    if (!empty($_SESSION['vbox_logged_in'])) {
        return;
    }
    if ($json) {
        json_response(['ok' => false, 'error' => 'Authentication required'], 401);
    }
    header('Location: index.php');
    exit;
}

function current_user_role(): string
{
    secure_session_start();
    return $_SESSION['vbox_role'] ?? 'viewer';
}

function can_manage(): bool
{
    return in_array(current_user_role(), ['admin', 'operator'], true);
}

function client_ip(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? 'unknown', 0, 64);
}

function table_exists(string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    try {
        $stmt = db()->prepare('SHOW TABLES LIKE ?');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $cache[$table] = $stmt->get_result()->num_rows > 0;
    } catch (Throwable $e) {
        error_log('VMange table_exists failed for ' . $table . ': ' . $e->getMessage());
        $cache[$table] = false;
    }
    return $cache[$table];
}

function column_exists(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }
    try {
        $stmt = db()->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->bind_param('s', $column);
        $stmt->execute();
        $cache[$key] = $stmt->get_result()->num_rows > 0;
    } catch (Throwable $e) {
        error_log('VMange column_exists failed for ' . $key . ': ' . $e->getMessage());
        $cache[$key] = false;
    }
    return $cache[$key];
}

function validate_hostname(string $hostname): string
{
    $hostname = trim($hostname);
    if ($hostname === '' || strlen($hostname) > 100 || !preg_match('/^[A-Za-z0-9._-]+$/', $hostname)) {
        throw new InvalidArgumentException('Invalid host name');
    }
    return $hostname;
}

function validate_target(string $target): string
{
    $target = trim($target);
    if ($target === '' || strlen($target) > 255 || preg_match('/[\x00-\x1F\x7F]/', $target)) {
        throw new InvalidArgumentException('Invalid target name');
    }
    return $target;
}

function allowed_actions(): array
{
    return [
        'start', 'stop', 'poweroff', 'pause', 'resume', 'reset', 'restart', 'refresh_inventory',
        'snapshot_create', 'snapshot_restore', 'snapshot_delete',
        'vm_clone', 'vm_delete', 'vm_set_resources', 'vm_set_boot_order', 'vm_set_description', 'vm_set_autostart',
        'vm_attach_iso', 'vm_detach_iso', 'vm_attach_disk', 'vm_create_disk', 'vm_resize_disk',
        'vm_set_network', 'vm_cable_connected', 'vm_export', 'vm_import', 'vm_create', 'vm_enable_vrde', 'vm_disable_vrde', 'vm_screenshot',
        'vm_logs_list', 'vm_log_tail',
        'container_start', 'container_stop', 'container_restart',
        'container_pause', 'container_unpause', 'container_kill', 'container_remove',
        'image_pull', 'image_remove',
        'compose_up', 'compose_down', 'compose_pull',
        'compose_restart', 'compose_deploy', 'dockerfile_deploy', 'logs_tail',
        'host_install_virtualbox', 'host_install_docker', 'host_refresh_inventory', 'agent_restart',
        'host_reboot', 'host_wol_send',
        'script_run', 'terminal_exec',
        'agent_upgrade', 'agent_uninstall',
    ];
}

function is_destructive_action(string $action): bool
{
    return in_array($action, [
        'poweroff', 'reset', 'snapshot_restore', 'snapshot_delete', 'vm_delete',
        'vm_set_resources', 'vm_set_boot_order', 'vm_set_description', 'vm_set_autostart',
        'vm_attach_iso', 'vm_detach_iso', 'vm_attach_disk', 'vm_create_disk', 'vm_resize_disk',
        'vm_set_network', 'vm_cable_connected', 'vm_export', 'vm_import', 'vm_create', 'vm_disable_vrde',
        'container_kill', 'container_remove', 'image_remove', 'compose_down', 'compose_deploy', 'dockerfile_deploy',
        'host_install_virtualbox', 'host_install_docker', 'agent_restart', 'host_reboot', 'script_run', 'terminal_exec', 'agent_uninstall',
    ], true);
}

function should_persist_host_json(string $column, string $value): bool
{
    if ($value === '') {
        return false;
    }
    if (!in_array($column, ['containers_json', 'compose_json', 'images_json'], true)) {
        return true;
    }
    $trimmed = trim($value);
    if ($trimmed === '' || $trimmed === '[]' || $trimmed === '{}') {
        return false;
    }
    return true;
}

function audit_log(string $action, ?string $target = null, ?string $details = null): void
{
    if (!table_exists('vbox_audit_logs')) {
        return;
    }
    try {
        $user = $_SESSION['vbox_user'] ?? 'system';
        $ip = client_ip();
        $stmt = db()->prepare('INSERT INTO vbox_audit_logs(username, action, target, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $stmt->bind_param('sssss', $user, $action, $target, $details, $ip);
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('VMange audit_log failed: ' . $e->getMessage());
    }
}

function rate_limit(string $bucket, int $max = 0, int $window = 0): bool
{
    $config = app_config();
    $max = $max > 0 ? $max : (int) $config['rate_limit_max'];
    $window = $window > 0 ? $window : (int) $config['rate_limit_window'];
    $key = hash('sha256', client_ip() . '|' . $bucket);

    if (!table_exists('vbox_rate_limits')) {
        secure_session_start();
        $now = time();
        $_SESSION['rate_limits'][$key] = array_values(array_filter($_SESSION['rate_limits'][$key] ?? [], fn($ts) => $ts > $now - $window));
        if (count($_SESSION['rate_limits'][$key]) >= $max) {
            return false;
        }
        $_SESSION['rate_limits'][$key][] = $now;
        return true;
    }

    $conn = db();
    $stmt = $conn->prepare('DELETE FROM vbox_rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)');
    $stmt->bind_param('i', $window);
    $stmt->execute();

    $stmt = $conn->prepare('SELECT COUNT(*) AS hits FROM vbox_rate_limits WHERE rate_key=?');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $hits = (int) ($stmt->get_result()->fetch_assoc()['hits'] ?? 0);
    if ($hits >= $max) {
        return false;
    }

    $stmt = $conn->prepare('INSERT INTO vbox_rate_limits(rate_key, created_at) VALUES (?, NOW())');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    return true;
}

function expire_stale_commands(?string $hostname = null, int $ageSeconds = 180): void
{
    if (!table_exists('vbox_commands')) {
        return;
    }
    $ageSeconds = max(30, $ageSeconds);
    $hasUpdatedAt = column_exists('vbox_commands', 'updated_at');
    $timeColumn = $hasUpdatedAt ? 'COALESCE(updated_at, created_at)' : 'created_at';
    try {
        if ($hostname !== null && $hostname !== '') {
            $stmt = db()->prepare("UPDATE vbox_commands SET status='expired'" . ($hasUpdatedAt ? ', updated_at=NOW()' : '') . " WHERE hostname=? AND status IN ('pending','sent','running') AND TIMESTAMPDIFF(SECOND, $timeColumn, NOW()) > ?");
            $stmt->bind_param('si', $hostname, $ageSeconds);
            $stmt->execute();
            return;
        }
        db()->query("UPDATE vbox_commands SET status='expired'" . ($hasUpdatedAt ? ', updated_at=NOW()' : '') . " WHERE status IN ('pending','sent','running') AND TIMESTAMPDIFF(SECOND, $timeColumn, NOW()) > " . (int) $ageSeconds);
    } catch (Throwable $e) {
        error_log('VMange command expiry failed: ' . $e->getMessage());
    }
}

function verify_agent_token(string $hostname, string $token): bool
{
    if ($token === '') {
        return false;
    }

    $legacy = (string) app_config()['legacy_agent_token'];
    if ($legacy !== '' && hash_equals($legacy, $token)) {
        return true;
    }

    if (table_exists('vbox_host_tokens')) {
        $stmt = db()->prepare('SELECT token_hash FROM vbox_host_tokens WHERE hostname=? AND active=1 ORDER BY id DESC LIMIT 1');
        $stmt->bind_param('s', $hostname);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            return hash_equals($row['token_hash'], hash('sha256', $token));
        }
    }

    return false;
}

function host_is_blocked(string $hostname): bool
{
    try {
        ensure_host_blocks_table();
        $stmt = db()->prepare('SELECT 1 FROM vbox_host_blocks WHERE hostname=? LIMIT 1');
        $stmt->bind_param('s', $hostname);
        $stmt->execute();
        return (bool) $stmt->get_result()->fetch_row();
    } catch (Throwable $e) {
        error_log('VMange host block check failed: ' . $e->getMessage());
        return false;
    }
}

function block_host(string $hostname): void
{
    ensure_host_blocks_table();
    $stmt = db()->prepare('INSERT INTO vbox_host_blocks(hostname, created_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE created_at=VALUES(created_at)');
    $stmt->bind_param('s', $hostname);
    $stmt->execute();
}

function unblock_host(string $hostname): void
{
    ensure_host_blocks_table();
    $stmt = db()->prepare('DELETE FROM vbox_host_blocks WHERE hostname=?');
    $stmt->bind_param('s', $hostname);
    $stmt->execute();
}

function ensure_host_blocks_table(): void
{
    db()->query("CREATE TABLE IF NOT EXISTS `vbox_host_blocks` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `hostname` varchar(100) NOT NULL,
        `created_at` datetime DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `hostname` (`hostname`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function parse_vm_specs(?string $raw): array
{
    $specs = [];
    foreach (explode("\n", trim((string) $raw)) as $line) {
        if ($line === '') {
            continue;
        }
        $parts = explode('|', $line);
        if (count($parts) >= 6) {
            $specs[$parts[0]] = [
                'state' => $parts[1],
                'memory' => (int) $parts[2],
                'cpu' => (int) $parts[3],
                'os' => $parts[4],
                'vram' => (int) $parts[5],
            ];
        }
    }
    return $specs;
}

function parse_vm_list(?string $raw): array
{
    $vms = [];
    foreach (explode("\n", trim((string) $raw)) as $line) {
        if (preg_match('/"([^"]+)"/', $line, $matches)) {
            $vms[] = $matches[1];
        }
    }
    return $vms;
}

function parse_vm_uuid_list(?string $raw): array
{
    $uuids = [];
    foreach (explode("\n", trim((string) $raw)) as $line) {
        if (preg_match('/\{([^}]+)\}/', $line, $matches)) {
            $uuid = strtolower(trim($matches[1]));
            if ($uuid !== '' && $uuid !== 'metrics-json') {
                $uuids[] = $uuid;
            }
        }
    }
    return $uuids;
}

function merge_running_vms_from_metrics(?string $metricsJson, string $runningVms): string
{
    $metrics = decode_json_column($metricsJson);
    $names = $metrics['running_vm_names'] ?? [];
    if (!is_array($names)) {
        return $runningVms;
    }
    $existing = array_flip(parse_vm_list($runningVms));
    $lines = trim($runningVms) === '' ? [] : explode("\n", trim($runningVms));
    foreach ($names as $name) {
        $name = (string) $name;
        if ($name !== '' && !isset($existing[$name])) {
            $lines[] = '"' . $name . '" {metrics-json}';
            $existing[$name] = true;
        }
    }
    return $lines ? implode("\n", $lines) : $runningVms;
}

function reconcile_vm_specs_with_running(string $vmSpecs, string $runningVms): string
{
    $running = array_flip(parse_vm_list($runningVms));
    if ($running === [] || trim($vmSpecs) === '') {
        return $vmSpecs;
    }
    $lines = [];
    foreach (explode("\n", trim($vmSpecs)) as $line) {
        $parts = explode('|', $line);
        if (count($parts) >= 6 && isset($running[$parts[0]]) && !str_contains(strtolower((string) ($parts[1] ?? '')), 'paused')) {
            $parts[1] = 'running';
            $line = implode('|', $parts);
        }
        $lines[] = $line;
    }
    return implode("\n", $lines);
}

function running_state_from_metrics_json(?string $metricsJson): array
{
    $metrics = decode_json_column($metricsJson);
    $names = [];
    $nameValues = $metrics['running_vm_names'] ?? [];
    if (!is_array($nameValues)) {
        $nameValues = [];
    }
    foreach ($nameValues as $name) {
        $name = trim((string) $name);
        if ($name !== '') {
            $names[$name] = true;
        }
    }
    $uuids = [];
    $uuidValues = $metrics['running_vm_uuids'] ?? [];
    if (!is_array($uuidValues)) {
        $uuidValues = [];
    }
    foreach ($uuidValues as $uuid) {
        $uuid = strtolower(trim((string) $uuid));
        if ($uuid !== '') {
            $uuids[$uuid] = true;
        }
    }
    return [$names, $uuids, $metrics];
}

function running_state_from_raw(string $runningVms, array $runningNames = [], array $runningUuids = []): array
{
    foreach (explode("\n", trim($runningVms)) as $line) {
        if (preg_match('/"([^"]+)"/', $line, $matches)) {
            $name = trim($matches[1]);
            if ($name !== '') {
                $runningNames[$name] = true;
            }
        }
        if (preg_match('/\{([^}]+)\}/', $line, $matches)) {
            $uuid = strtolower(trim($matches[1]));
            if ($uuid !== '' && $uuid !== 'metrics-json') {
                $runningUuids[$uuid] = true;
            }
        }
    }
    return [$runningNames, $runningUuids];
}

function reconcile_vm_specs_with_maps(string $vmSpecs, array $runningNames): string
{
    if ($runningNames === [] || trim($vmSpecs) === '') {
        return $vmSpecs;
    }
    $lines = [];
    foreach (explode("\n", trim($vmSpecs)) as $line) {
        $parts = explode('|', $line);
        if (count($parts) >= 6 && isset($runningNames[$parts[0]]) && !str_contains(strtolower((string) ($parts[1] ?? '')), 'paused')) {
            $parts[1] = 'running';
            $line = implode('|', $parts);
        }
        $lines[] = $line;
    }
    return implode("\n", $lines);
}

function runtime_status_json(array $runningNames, array $runningUuids): string
{
    $payload = [
        'by_name' => [],
        'by_uuid' => [],
        'running_vm_names' => array_keys($runningNames),
        'running_vm_uuids' => array_keys($runningUuids),
        'updated_at' => date('c'),
    ];
    foreach (array_keys($runningNames) as $name) {
        $payload['by_name'][$name] = ['status' => 'running', 'running' => true, 'runtime_source' => 'runtime_status_names'];
    }
    foreach (array_keys($runningUuids) as $uuid) {
        $payload['by_uuid'][$uuid] = ['status' => 'running', 'running' => true, 'runtime_source' => 'runtime_status_uuids'];
    }
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
    return $encoded === false ? '' : $encoded;
}

function reconcile_inventory_with_running(string $inventoryJson, array $runningNames, array $runningUuids): string
{
    if (trim($inventoryJson) === '') {
        return $inventoryJson;
    }
    $inventory = json_decode($inventoryJson, true);
    if (!is_array($inventory)) {
        return $inventoryJson;
    }
    $changed = false;
    foreach ($inventory as &$vm) {
        if (!is_array($vm)) {
            continue;
        }
        $name = trim((string) ($vm['name'] ?? ''));
        $uuid = strtolower(trim((string) ($vm['uuid'] ?? '')));
        $state = strtolower((string) ($vm['state'] ?? 'unknown'));
        $isPaused = str_contains($state, 'paused');
        if (($name !== '' && isset($runningNames[$name])) || ($uuid !== '' && isset($runningUuids[$uuid]))) {
            $vm['running'] = true;
            if ($isPaused) {
                $vm['state'] = 'paused';
                $vm['status'] = 'paused';
            } else {
                $vm['state'] = 'running';
                $vm['status'] = 'running';
            }
            $vm['runtime_source'] = isset($runningNames[$name]) ? 'running_vm_names' : 'running_vm_uuids';
            $changed = true;
        }
    }
    unset($vm);
    if (!$changed) {
        return $inventoryJson;
    }
    $encoded = json_encode($inventory, JSON_UNESCAPED_SLASHES);
    return $encoded === false ? $inventoryJson : $encoded;
}

function rebuild_metrics_json_with_running(string $metricsJson, array $metrics, array $runningNames, array $runningUuids): string
{
    $metrics['running_vm_names'] = array_keys($runningNames);
    $metrics['running_vm_uuids'] = array_keys($runningUuids);
    $encoded = json_encode($metrics, JSON_UNESCAPED_SLASHES);
    return $encoded === false ? $metricsJson : $encoded;
}

function normalize_vm_inventory(array $inventory, array $runningNames = [], array $runningUuids = []): array
{
    $runningNames = array_flip(array_map('strval', $runningNames));
    $runningUuids = array_flip(array_map('strtolower', array_map('strval', $runningUuids)));
    $vms = [];
    foreach ($inventory as $vm) {
        if (!is_array($vm)) {
            continue;
        }
        $name = trim((string) ($vm['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $uuid = trim((string) ($vm['uuid'] ?? ''));
        $running = !empty($vm['running'])
            || isset($runningNames[$name])
            || ($uuid !== '' && isset($runningUuids[strtolower($uuid)]));
        $state = strtolower((string) ($vm['state'] ?? 'unknown'));
        $isPaused = str_contains($state, 'paused');
        if ($running && !$isPaused) {
            $state = 'running';
        }
        $vms[] = [
            'name' => $name,
            'uuid' => $uuid,
            'state' => $state,
            'status' => $state === 'poweroff' ? 'stopped' : $state,
            'running' => $running || $isPaused,
            'running_source' => $running ? 'structured_inventory' : 'inventory',
            'runtime_source' => (string) ($vm['runtime_source'] ?? ($running ? 'structured_inventory' : 'inventory')),
            'os' => (string) ($vm['os'] ?? '-'),
            'cpu' => (int) ($vm['cpu'] ?? 0),
            'memory' => (int) ($vm['ram_mb'] ?? $vm['memory'] ?? 0),
            'ram_mb' => (int) ($vm['ram_mb'] ?? $vm['memory'] ?? 0),
            'vram' => (int) ($vm['vram_mb'] ?? $vm['vram'] ?? 0),
            'vram_mb' => (int) ($vm['vram_mb'] ?? $vm['vram'] ?? 0),
            'boot_order' => is_array($vm['boot_order'] ?? null) ? $vm['boot_order'] : [],
            'storage' => is_array($vm['storage'] ?? null) ? $vm['storage'] : [],
            'nics' => is_array($vm['nics'] ?? null) ? $vm['nics'] : [],
            'snapshots' => is_array($vm['snapshots'] ?? null) ? $vm['snapshots'] : [],
            'description' => (string) ($vm['description'] ?? ''),
            'autostart' => (string) ($vm['autostart'] ?? ''),
            'session_state' => (string) ($vm['session_state'] ?? ''),
            'last_state_change' => (string) ($vm['last_state_change'] ?? ''),
            'vrde_enabled' => (string) ($vm['vrde_enabled'] ?? ''),
            'vrde_port' => (string) ($vm['vrde_port'] ?? ''),
            'log_folder' => (string) ($vm['log_folder'] ?? ''),
            'collected_at' => (string) ($vm['collected_at'] ?? ''),
        ];
    }
    return $vms;
}

function decode_json_column(?string $value): array
{
    if (!$value) {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}
