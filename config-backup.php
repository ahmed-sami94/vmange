<?php
declare(strict_types=1);

const BACKUP_SETTINGS = ['mail_from', 'mail_transport', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_encryption', 'imap_host', 'imap_port', 'imap_username', 'imap_encryption'];
const BACKUP_SECRETS = ['smtp_password', 'imap_password', 'webhook_secret', 'terminal_gateway_token'];
const BACKUP_TABLES = [
    'hosts' => ['vbox_hosts', ['hostname','wol_mac','wol_broadcast','wol_port','wol_relay_host'], ['hostname']],
    'rules' => ['vbox_alarm_rules', ['name','metric','operator','threshold','enabled','notify_email','cooldown_minutes'], ['name']],
    'stacks' => ['vbox_compose_stacks', ['hostname','project','compose_yaml'], ['hostname','project']],
    'scripts' => ['vbox_scripts', ['name','description','body'], ['name']],
];

function backup_collect(bool $encrypted, bool $secrets): array
{
    $backup = ['format' => 'vmange-config', 'version' => 1, 'created_at' => gmdate(DATE_ATOM), 'settings' => []];
    foreach (array_merge(BACKUP_SETTINGS, $encrypted && $secrets ? BACKUP_SECRETS : []) as $key) {
        $backup['settings'][$key] = setting_value($key, (string)(app_config()[$key] ?? ''));
    }
    foreach (BACKUP_TABLES as $section => [$table, $columns]) {
        $backup[$section] = db()->query('SELECT ' . implode(',', array_map(static fn($c) => '`' . $c . '`', $columns)) . ' FROM ' . $table)->fetch_all(MYSQLI_ASSOC);
        if (!$encrypted && in_array($section, ['scripts', 'stacks'], true)) {
            foreach ($backup[$section] as &$row) {
                unset($row['body'], $row['compose_yaml'], $row['description']);
            }
            unset($row);
        }
    }
    return $backup;
}

function backup_encode(array $backup, string $passphrase): string
{
    $json = json_encode($backup, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($passphrase === '') return $json;
    if (!function_exists('sodium_crypto_pwhash')) throw new RuntimeException('PHP Sodium is required for encrypted backups');
    if (strlen($passphrase) < 16 || strlen($passphrase) > 1024) throw new InvalidArgumentException('Use a backup passphrase of 16 to 1024 characters');
    $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $key = sodium_crypto_pwhash(32, $passphrase, $salt, 3, 67108864, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    $cipher = sodium_crypto_secretbox($json, $nonce, $key);
    sodium_memzero($key);
    return json_encode(['format' => 'vmange-encrypted', 'version' => 1, 'salt' => base64_encode($salt), 'nonce' => base64_encode($nonce), 'ciphertext' => base64_encode($cipher)], JSON_THROW_ON_ERROR);
}

function backup_decode(string $content, string $passphrase): array
{
    if (strlen($content) > 8 * 1024 * 1024) throw new InvalidArgumentException('Backup exceeds 8 MB');
    $backup = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($backup)) throw new InvalidArgumentException('Backup must be a JSON object');
    if (($backup['format'] ?? '') === 'vmange-encrypted') {
        if (($backup['version'] ?? null) !== 1 || !function_exists('sodium_crypto_pwhash')) throw new InvalidArgumentException('Unsupported encrypted backup or PHP Sodium is unavailable');
        if (strlen($passphrase) < 16 || strlen($passphrase) > 1024) throw new InvalidArgumentException('Enter the original backup passphrase');
        $salt = base64_decode((string) ($backup['salt'] ?? ''), true);
        $nonce = base64_decode((string) ($backup['nonce'] ?? ''), true);
        $cipher = base64_decode((string) ($backup['ciphertext'] ?? ''), true);
        if ($salt === false || strlen($salt) !== 16 || $nonce === false || strlen($nonce) !== 24 || $cipher === false || strlen($cipher) < 16) throw new InvalidArgumentException('Invalid encrypted backup');
        $key = sodium_crypto_pwhash(32, $passphrase, $salt, 3, 67108864, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        sodium_memzero($key);
        if ($plain === false) throw new InvalidArgumentException('Wrong passphrase or damaged backup');
        $backup = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($backup)) throw new InvalidArgumentException('Backup must be a JSON object');
    } else {
        if (!is_array($backup['settings'] ?? []) || !is_array($backup['scripts'] ?? []) || !is_array($backup['stacks'] ?? [])) throw new InvalidArgumentException('Invalid backup sections');
        foreach (BACKUP_SECRETS as $secret) {
            if (array_key_exists($secret, $backup['settings'] ?? [])) throw new InvalidArgumentException('Credentials require an encrypted backup');
        }
        foreach (array_merge($backup['scripts'] ?? [], $backup['stacks'] ?? []) as $row) {
            if (isset($row['body']) || isset($row['compose_yaml'])) throw new InvalidArgumentException('Script and stack bodies require an encrypted backup');
        }
    }
    backup_validate($backup);
    return $backup;
}

function backup_validate(array $backup): void
{
    if (($backup['format'] ?? '') !== 'vmange-config' || ($backup['version'] ?? null) !== 1) throw new InvalidArgumentException('Unsupported configuration format');
    if (array_diff(array_keys($backup), ['format','version','created_at','settings', ...array_keys(BACKUP_TABLES)])) throw new InvalidArgumentException('Unexpected backup section');
    if (!is_array($backup['settings'] ?? null) || array_diff(array_keys($backup['settings']), array_merge(BACKUP_SETTINGS, BACKUP_SECRETS))) throw new InvalidArgumentException('Backup contains unsupported settings');
    foreach ($backup['settings'] as $key => $value) {
        if (!is_string($value) || strlen($value) > 4096 || preg_match('/[\r\n\x00]/', $value)) throw new InvalidArgumentException('Invalid setting: ' . $key);
        if (str_ends_with($key, '_encryption') && !in_array($value, ['tls','ssl'], true)) throw new InvalidArgumentException('Mail requires TLS');
        if (str_ends_with($key, '_port') && (!ctype_digit($value) || (int) $value < 1 || (int) $value > 65535)) throw new InvalidArgumentException('Invalid mail port');
        if ($key === 'mail_transport' && !in_array($value, ['smtp','php','auto'], true)) throw new InvalidArgumentException('Invalid mail transport');
        if ($key === 'mail_from' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Invalid sender address');
        if (str_ends_with($key, '_host') && $value !== '' && !preg_match('/^[a-zA-Z0-9.-]+$/D', $value)) throw new InvalidArgumentException('Invalid mail server');
    }
    foreach (BACKUP_TABLES as $section => [$table, $columns, $identity]) {
        if (!is_array($backup[$section] ?? null) || !array_is_list($backup[$section]) || count($backup[$section]) > 1000) throw new InvalidArgumentException('Invalid section: ' . $section);
        $seen = [];
        foreach ($backup[$section] as $row) {
            if (!is_array($row) || array_diff(array_keys($row), $columns) || array_diff($identity, array_keys($row))) throw new InvalidArgumentException('Invalid fields in ' . $section);
            foreach ($identity as $key) if (!is_string($row[$key]) || trim($row[$key])==='') throw new InvalidArgumentException('Record identity cannot be empty');
            foreach ($row as $key => $value) {
                if (!is_scalar($value) && $value !== null) throw new InvalidArgumentException('Nested backup fields are not supported');
                if (strlen((string) $value) > (in_array($key, ['body','compose_yaml'], true) ? 262144 : 4096) || str_contains((string) $value, "\0")) throw new InvalidArgumentException('Field exceeds limits');
                if (in_array($key, ['hostname','project','wol_relay_host'], true) && $value !== null && $value !== '' && !preg_match('/^[A-Za-z0-9._-]{1,100}$/D', (string) $value)) throw new InvalidArgumentException('Invalid host/project identifier');
                if ($key === 'name' && (trim((string) $value) === '' || strlen((string) $value) > 100)) throw new InvalidArgumentException('Invalid name');
            }
            if ($section === 'rules' && (!in_array($row['metric'] ?? '', ['cpu','memory','disk','offline'], true) || !in_array($row['operator'] ?? '', ['>=','>','<=','<','='], true) || !is_numeric($row['threshold'] ?? null) || (float)$row['threshold'] < 0 || (float)$row['threshold'] > 100 || !in_array((string)($row['enabled'] ?? ''), ['0','1'], true) || !is_numeric($row['cooldown_minutes'] ?? null) || (int)$row['cooldown_minutes'] < 1 || (int)$row['cooldown_minutes'] > 10080 || (!empty($row['notify_email']) && !filter_var($row['notify_email'], FILTER_VALIDATE_EMAIL)))) throw new InvalidArgumentException('Invalid alarm policy');
            if ($section === 'hosts' && ((!empty($row['wol_mac']) && !preg_match('/^([a-fA-F0-9]{2}:){5}[a-fA-F0-9]{2}$/D', $row['wol_mac'])) || (!empty($row['wol_broadcast']) && !filter_var($row['wol_broadcast'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) || isset($row['wol_port']) && ((int)$row['wol_port'] < 1 || (int)$row['wol_port'] > 65535))) throw new InvalidArgumentException('Invalid WOL profile');
            $id = json_encode(array_map(static fn($key)=>$row[$key],$identity));
            if (isset($seen[$id])) throw new InvalidArgumentException('Duplicate record in ' . $section);
            $seen[$id] = true;
        }
    }
}

function backup_existing(mysqli $conn, string $table, array $identity, array $row): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM ' . $table . ' WHERE ' . implode(' AND ', array_map(static fn($c) => '`' . $c . '`=?', $identity)) . ' LIMIT 2');
    $stmt->execute(array_map(static fn($c) => $row[$c], $identity));
    $result=$stmt->get_result();
    if ($result->num_rows>1) throw new InvalidArgumentException('Ambiguous existing record; resolve duplicate names before import');
    return (bool) $result->fetch_row();
}

function backup_preview(array $backup): array
{
    $preview = [];
    foreach (BACKUP_TABLES as $section => [$table, $columns, $identity]) {
        foreach ($backup[$section] as $row) {
            $omitted = ($section === 'scripts' && !isset($row['body'])) || ($section === 'stacks' && !isset($row['compose_yaml']));
            $preview[] = ['section' => $section, 'identity' => implode(' / ', array_map(static fn($c) => $row[$c], $identity)), 'status' => $omitted ? 'metadata only; skipped' : (backup_existing(db(), $table, $identity, $row) ? 'conflict' : 'new')];
        }
    }
    foreach ($backup['settings'] as $key => $value) $preview[] = ['section' => 'settings', 'identity' => $key, 'status' => backup_existing(db(), 'vbox_settings', ['setting_key'], ['setting_key' => $key]) ? 'conflict' : 'new'];
    return $preview;
}

function backup_apply(array $backup, string $conflict): void
{
    if (!in_array($conflict, ['keep','replace'], true)) throw new InvalidArgumentException('Choose keep existing or replace imported records');
    $conn = db();
    $conn->begin_transaction();
    try {
        foreach ($backup['settings'] as $key => $value) {
            if ($conflict === 'keep' && backup_existing($conn, 'vbox_settings', ['setting_key'], ['setting_key' => $key])) continue;
            save_setting($key, in_array($key, BACKUP_SECRETS, true) ? encrypt_secret_value($value) : $value);
        }
        foreach (BACKUP_TABLES as $section => [$table, $columns, $identity]) {
            foreach ($backup[$section] as $row) {
                if (($section === 'scripts' && !isset($row['body'])) || ($section === 'stacks' && !isset($row['compose_yaml']))) continue;
                $exists = backup_existing($conn, $table, $identity, $row);
                if ($exists && $conflict === 'keep') continue;
                $fields = array_keys($row);
                if ($exists) {
                    $sql = 'UPDATE ' . $table . ' SET ' . implode(',', array_map(static fn($c) => '`' . $c . '`=?', $fields)) . ' WHERE ' . implode(' AND ', array_map(static fn($c) => '`' . $c . '`=?', $identity));
                    $params = [...array_values($row), ...array_map(static fn($c) => $row[$c], $identity)];
                } else {
                    if ($section === 'hosts') $row += ['all_vms' => '', 'running_vms' => '', 'vm_specs' => '', 'last_seen' => null];
                    $sql = 'INSERT INTO ' . $table . ' (`' . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')';
                    $params = array_values($row);
                }
                $conn->prepare($sql)->execute($params);
            }
        }
        audit_log('configuration_imported', 'configuration', 'Validated configuration imported; existing records: ' . $conflict);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}
