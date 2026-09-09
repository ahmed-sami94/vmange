<?php
declare(strict_types=1);
require_once __DIR__ . '/config-backup.php';

function management_security(): array
{
    $hosts = db()->query("SELECT h.hostname,h.last_seen,EXISTS(SELECT 1 FROM vbox_host_tokens t WHERE t.hostname=h.hostname AND active=1) AS credential_ready,EXISTS(SELECT 1 FROM vbox_host_blocks b WHERE b.hostname=h.hostname) AS revoked FROM vbox_hosts h ORDER BY h.hostname")->fetch_all(MYSQLI_ASSOC);
    try { secret_key_material(); $keyReady = true; } catch (RuntimeException $error) { $keyReady = false; }
    $secrets = [];
    foreach (BACKUP_SECRETS as $key) {
        $stmt = db()->prepare('SELECT setting_value FROM vbox_settings WHERE setting_key=?');
        $stmt->execute([$key]);
        $stored = $stmt->get_result()->fetch_assoc()['setting_value'] ?? '';
        $status = $stored === '' ? 'not configured' : (str_starts_with($stored,'enc:v1:') ? 'encrypted' : 'legacy plaintext; migrate');
        if (str_starts_with($stored,'enc:v1:')) {
            try { decrypt_secret_value($stored); } catch (RuntimeException $error) { $status = 'decryption failed'; }
        }
        $secrets[] = ['name' => $key, 'status' => $status];
    }
    return ['hosts'=>$hosts,'encryption_ready'=>$keyReady,'sodium'=>function_exists('sodium_crypto_pwhash'),'https'=>is_https(),'smtp_tls'=>in_array(setting_value('smtp_encryption','tls'),['tls','ssl'],true),'imap_tls'=>in_array(setting_value('imap_encryption','ssl'),['tls','ssl'],true),'secrets'=>$secrets,'failures'=>db()->query("SELECT action,target,created_at FROM vbox_audit_logs WHERE action='agent_auth_failed' ORDER BY id DESC LIMIT 30")->fetch_all(MYSQLI_ASSOC)];
}

function management_rollout(string $version, array $hosts): array
{
    release_version($version);
    if (!$hosts || count($hosts)>100 || count($hosts)!==count(array_unique($hosts))) throw new InvalidArgumentException('Select 1 to 100 distinct hosts');
    $release = null;
    foreach (agent_release_catalog() as $candidate) if ($candidate['version'] === $version) $release = $candidate;
    if (!$release) throw new InvalidArgumentException('Release is not published');
    if (count($hosts)>1) {
        $stmt = db()->prepare("SELECT 1 FROM vbox_agent_rollouts WHERE version=? AND status='done' LIMIT 1");
        $stmt->execute([$version]);
        if (!$stmt->get_result()->fetch_row()) throw new InvalidArgumentException('Complete one canary host upgrade before a batch rollout');
    }
    $outcomes = [];
    foreach ($hosts as $hostname) {
        try {
            $hostname = validate_hostname((string)$hostname);
            $stmt = db()->prepare('SELECT metrics_json FROM vbox_hosts WHERE hostname=?');
            $stmt->execute([$hostname]);
            $metrics = json_decode($stmt->get_result()->fetch_assoc()['metrics_json'] ?? '{}', true);
            if (version_compare(ltrim((string)($metrics['agent_version'] ?? '0'),'v'), ltrim($release['min_agent'] ?? 'v1.7.0','v'), '<')) throw new InvalidArgumentException('Upgrade to the bundled v2.0.0 agent first, or reinstall using the host installer');
            $stmt = db()->prepare("SELECT 1 FROM vbox_agent_rollouts WHERE hostname=? AND status NOT IN ('done','failed','expired') LIMIT 1");
            $stmt->execute([$hostname]);
            if ($stmt->get_result()->fetch_row()) throw new InvalidArgumentException('Host already has an active rollout');
            $conn = db();
            $conn->begin_transaction();
            try {
                $conn->prepare('SELECT hostname FROM vbox_hosts WHERE hostname=? FOR UPDATE')->execute([$hostname]);
                $active=$conn->prepare("SELECT 1 FROM vbox_agent_rollouts WHERE hostname=? AND status NOT IN ('done','failed','expired') FOR UPDATE");
                $active->execute([$hostname]);
                if ($active->get_result()->fetch_row()) throw new InvalidArgumentException('Host already has an active rollout');
                $id = queue_command($hostname,'agent_upgrade','vmange-agent',json_encode(['version'=>$version],JSON_THROW_ON_ERROR));
                $conn->prepare('INSERT INTO vbox_agent_rollouts(command_id,hostname,version) VALUES(?,?,?)')->execute([$id,$hostname,$version]);
                $conn->commit();
            } catch (Throwable $error) { $conn->rollback(); throw $error; }
            $outcomes[] = ['hostname'=>$hostname,'status'=>'queued','command_id'=>$id];
        } catch (Throwable $error) { $outcomes[] = ['hostname'=>$hostname,'status'=>'failed','message'=>$error->getMessage()]; }
    }
    return $outcomes;
}

function management_action(string $action): never
{
    if (current_user_role() !== 'admin') json_response(['ok'=>false,'error'=>'Admin role required'],403);
    if (!rate_limit('management',30,60)) json_response(['ok'=>false,'error'=>'Too many requests'],429);
    try {
        switch ($action) {
            case 'status':
                json_response(['ok'=>true,'security'=>management_security(),'releases'=>array_merge(agent_release_catalog(), array_values(array_filter(uploaded_release_catalog(true),static fn($r)=>$r['status']==='draft'))),'rollouts'=>release_rollouts()]);
            case 'revoke':
                $hostname = validate_hostname((string)($_POST['hostname'] ?? ''));
                db()->prepare('UPDATE vbox_host_tokens SET active=0,rotated_at=NOW() WHERE hostname=?')->execute([$hostname]);
                audit_log('token_revoked',$hostname,'Host credentials revoked');
                json_response(['ok'=>true]);
            case 'migrate-secrets':
                secret_key_material();
                $conn = db(); $conn->begin_transaction();
                try {
                    foreach (BACKUP_SECRETS as $key) {
                        $stmt=$conn->prepare('SELECT setting_value FROM vbox_settings WHERE setting_key=? FOR UPDATE'); $stmt->execute([$key]);
                        $stored=$stmt->get_result()->fetch_assoc()['setting_value'] ?? '';
                        if ($stored!=='' && !str_starts_with($stored,'enc:v1:')) save_secret_setting($key,$stored);
                    }
                    $conn->commit();
                } catch (Throwable $error) { $conn->rollback(); throw $error; }
                audit_log('secrets_migrated','settings','Legacy stored integration secrets encrypted');
                json_response(['ok'=>true]);
            case 'release-upload':
                release_upload($_FILES['artifact'] ?? [],$_POST);
                json_response(['ok'=>true]);
            case 'release-publish':
                release_publish((string)($_POST['version'] ?? ''));
                json_response(['ok'=>true]);
            case 'rollout':
                $hosts=json_decode((string)($_POST['hosts'] ?? '[]'),true,8,JSON_THROW_ON_ERROR);
                if (!is_array($hosts)) throw new InvalidArgumentException('Select hosts');
                json_response(['ok'=>true,'outcomes'=>management_rollout((string)($_POST['version'] ?? ''),$hosts)]);
            case 'export':
                $encrypted=($_POST['mode'] ?? '')==='encrypted';
                $passphrase=(string)($_POST['passphrase'] ?? '');
                if ($encrypted && $passphrase==='') throw new InvalidArgumentException('An encryption passphrase is required');
                $content=backup_encode(backup_collect($encrypted,($_POST['include_secrets'] ?? '')==='1'),$encrypted?$passphrase:'');
                audit_log('configuration_exported','configuration',$encrypted?'Encrypted export':'Redacted export');
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="vmange-config-' . gmdate('Ymd-His') . ($encrypted?'.encrypted':'') . '.json"');
                echo $content; exit;
            case 'import-preview':
            case 'import-apply':
                $upload=$_FILES['backup'] ?? [];
                if (($upload['error'] ?? -1)!==UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '') || ($upload['size'] ?? 0)>8388608) throw new InvalidArgumentException('Select a backup file up to 8 MB');
                $backup=backup_decode((string)file_get_contents($upload['tmp_name']),(string)($_POST['passphrase'] ?? ''));
                $preview=backup_preview($backup);
                $digest=hash_hmac('sha256',json_encode([$backup,$preview],JSON_THROW_ON_ERROR),csrf_token());
                if ($action==='import-preview') json_response(['ok'=>true,'preview'=>$preview,'digest'=>$digest]);
                if (!hash_equals($digest,(string)($_POST['digest'] ?? ''))) throw new InvalidArgumentException('Configuration changed; preview the import again');
                backup_apply($backup,(string)($_POST['conflict'] ?? ''));
                json_response(['ok'=>true]);
            default: json_response(['ok'=>false,'error'=>'Unknown management action'],404);
        }
    } catch (InvalidArgumentException|JsonException $error) { json_response(['ok'=>false,'error'=>$error->getMessage()],422); }
    catch (Throwable $error) { error_log('VMange management: ' . $error->getMessage()); json_response(['ok'=>false,'error'=>'Operation failed. Check server configuration and the protected error log.'],500); }
}
