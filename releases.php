<?php
declare(strict_types=1);

function release_schema(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS vbox_agent_releases (version varchar(32) PRIMARY KEY, sha256 char(64) NOT NULL, notes text NOT NULL, min_server varchar(32) NOT NULL, min_agent varchar(32) NOT NULL, status varchar(16) NOT NULL DEFAULT 'draft', created_by varchar(100) NOT NULL, created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP, published_at datetime DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS vbox_agent_rollouts (id bigint AUTO_INCREMENT PRIMARY KEY, command_id int NOT NULL UNIQUE, hostname varchar(100) NOT NULL, version varchar(32) NOT NULL, status varchar(32) NOT NULL DEFAULT 'pending', message text DEFAULT NULL, created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP, finished_at datetime DEFAULT NULL, KEY host_status(hostname,status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function release_version(string $version): string
{
    if (!preg_match('/^v[0-9]{1,4}\.[0-9]{1,4}\.[0-9]{1,4}$/D', $version)) throw new InvalidArgumentException('Use a version such as v2.0.1');
    return $version;
}

function release_storage(): string
{
    $configured = (string) (app_config()['release_storage'] ?? '');
    $webroot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: realpath(__DIR__);
    $path = $configured !== '' ? $configured : dirname($webroot) . '/vmange-private/releases';
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) throw new RuntimeException('Cannot create private release storage');
    $resolved = realpath($path);
    if ($resolved === false || $resolved === $webroot || str_starts_with(str_replace('\\','/',$resolved) . '/', str_replace('\\','/',$webroot) . '/')) throw new RuntimeException('Release storage must be outside the document root');
    return $resolved;
}

function release_upload(array $upload, array $metadata): void
{
    $version = release_version(trim((string)($metadata['version'] ?? '')));
    $minServer = release_version(trim((string)($metadata['min_server'] ?? 'v2.0.0')));
    $minAgent = release_version(trim((string)($metadata['min_agent'] ?? 'v2.0.0')));
    if (version_compare(ltrim($minAgent,'v'),'2.0.0','<')) throw new InvalidArgumentException('Private release downloads require agent v2.0.0 or later');
    $notes = trim((string)($metadata['notes'] ?? ''));
    if ($notes === '' || strlen($notes) > 16000) throw new InvalidArgumentException('Release notes are required, up to 16 KB');
    if (($upload['error'] ?? -1) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '') || ($upload['size'] ?? 0) > 524288 || strtolower(pathinfo($upload['name'] ?? '', PATHINFO_EXTENSION)) !== 'sh') throw new InvalidArgumentException('Upload one shell agent file, up to 512 KB');
    $source = file_get_contents($upload['tmp_name']);
    if ($source === false || str_contains($source, "\0") || str_contains($source, "\r") || !str_starts_with($source, "#!/usr/bin/env bash\n") || !preg_match('/^AGENT_VERSION="' . preg_quote($version, '/') . '"$/m', $source)) throw new InvalidArgumentException('Agent must use LF line endings, the Bash shebang, and the exact AGENT_VERSION');
    foreach (agent_release_catalog() as $release) if ($release['version'] === $version) throw new InvalidArgumentException('Version already exists; publish a new version');
    $path = release_storage() . '/' . $version . '.sh';
    $stream = @fopen($path, 'x');
    if ($stream === false) throw new InvalidArgumentException('Version already exists or storage is not writable');
    try {
        if (fwrite($stream, $source) !== strlen($source)) throw new RuntimeException('Incomplete artifact write');
    } finally { fclose($stream); }
    chmod($path, 0600);
    try {
        db()->prepare('INSERT INTO vbox_agent_releases(version,sha256,notes,min_server,min_agent,created_by) VALUES(?,?,?,?,?,?)')->execute([$version,hash('sha256',$source),$notes,$minServer,$minAgent,$_SESSION['vbox_user']]);
    } catch (Throwable $error) { unlink($path); throw $error; }
    audit_log('agent_release_uploaded', $version, 'Draft artifact stored; no code executed');
}

function uploaded_release_catalog(bool $drafts = false): array
{
    $rows = db()->query('SELECT * FROM vbox_agent_releases' . ($drafts ? '' : " WHERE status='published'") . ' ORDER BY created_at DESC')->fetch_all(MYSQLI_ASSOC);
    return array_map(static function($row) {
        $row['notes'] = preg_split('/\R/', $row['notes']);
        $row['file'] = '';
        $row['url'] = base_url('release-download.php?version=' . rawurlencode($row['version']));
        $row['released_at'] = $row['published_at'] ?? $row['created_at'];
        $row['latest'] = false;
        return $row;
    }, $rows);
}

function release_publish(string $version): void
{
    release_version($version);
    $stmt = db()->prepare('SELECT * FROM vbox_agent_releases WHERE version=?');
    $stmt->execute([$version]);
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row || !hash_equals($row['sha256'], (string) @hash_file('sha256', release_storage() . '/' . $version . '.sh'))) throw new RuntimeException('Artifact is missing or has changed');
    if (version_compare(ltrim($row['min_server'],'v'), '2.0.0', '>')) throw new InvalidArgumentException('Release requires a newer VMange server');
    db()->prepare("UPDATE vbox_agent_releases SET status='published', published_at=COALESCE(published_at,NOW()) WHERE version=?")->execute([$version]);
    audit_log('agent_release_published', $version, 'Available for selected-host installation');
}

function release_observe(mysqli $conn, string $hostname, string $version): void
{
    $stmt = $conn->prepare("UPDATE vbox_agent_rollouts r JOIN vbox_commands c ON c.id=r.command_id SET r.status=CASE WHEN c.status IN ('failed','expired') THEN c.status WHEN c.status='done' AND r.version=? THEN 'done' WHEN c.status='done' THEN 'awaiting_heartbeat' ELSE c.status END, r.message=CASE WHEN c.status='done' AND r.version=? THEN 'Expected version confirmed by authenticated heartbeat' ELSE COALESCE(c.result,c.progress_message,'Awaiting host') END, r.finished_at=CASE WHEN c.status IN ('failed','expired') OR (c.status='done' AND r.version=?) THEN NOW() ELSE NULL END WHERE r.hostname=? AND r.status NOT IN ('done','failed','expired')");
    $stmt->execute([$version,$version,$version,$hostname]);
}

function release_rollouts(): array
{
    db()->query("UPDATE vbox_agent_rollouts SET status='expired',message='No confirmed upgrade within 15 minutes',finished_at=NOW() WHERE status NOT IN ('done','failed','expired') AND created_at<DATE_SUB(NOW(),INTERVAL 15 MINUTE)");
    return db()->query('SELECT * FROM vbox_agent_rollouts ORDER BY id DESC LIMIT 100')->fetch_all(MYSQLI_ASSOC);
}
