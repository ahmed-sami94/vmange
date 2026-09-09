<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function vmange_mail_settings(): array
{
    $config = app_config();
    return [
        'transport' => strtolower(setting_value('mail_transport', (string) $config['mail_transport'])),
        'from' => setting_value('mail_from', (string) $config['mail_from']),
        'host' => setting_value('smtp_host', (string) $config['smtp_host']),
        'port' => (int) setting_value('smtp_port', (string) $config['smtp_port']),
        'username' => setting_value('smtp_username', (string) $config['smtp_username']),
        'password' => setting_value('smtp_password', (string) $config['smtp_password']),
        'encryption' => strtolower(setting_value('smtp_encryption', (string) $config['smtp_encryption'])),
    ];
}

function vmange_local_mail(string $to, string $subject, string $body, string $from): array
{
    $safeSubject = str_replace(["\r", "\n"], ' ', $subject);
    $headers = "From: {$from}\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Mailer: VMange";
    $accepted = @mail($to, $safeSubject, $body, $headers);
    return [$accepted, $accepted
        ? 'Message accepted by the local PHP mail transport'
        : 'Local PHP mail transport rejected the message'];
}

function vmange_smtp_read($socket): string
{
    $response = '';
    while (($line = fgets($socket, 2048)) !== false) {
        $response .= $line;
        if (strlen($response) > 16384) throw new RuntimeException('SMTP response exceeds limit');
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }
    return trim($response);
}

function vmange_smtp_expect(string $response, array $codes, string $step): void
{
    if (!in_array((int) substr($response, 0, 3), $codes, true)) {
        throw new RuntimeException($step . ' failed: ' . ($response !== '' ? $response : 'no SMTP response'));
    }
}

function vmange_smtp_write($socket, string $line): void
{
    if (fwrite($socket, $line . "\r\n") === false) {
        throw new RuntimeException('SMTP socket write failed');
    }
}

function vmange_smtp_mail(array $settings, string $to, string $subject, string $body): array
{
    if (!in_array($settings['encryption'], ['tls','ssl'], true)) throw new RuntimeException('SMTP requires verified TLS');
    if (!preg_match('/^[A-Za-z0-9.-]+$/D', $settings['host']) || $settings['port'] < 1 || $settings['port'] > 65535) throw new RuntimeException('Invalid SMTP server');
    if (!filter_var($settings['from'], FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid mail address');
    $remote = ($settings['encryption'] === 'ssl' ? 'ssl://' : '') . $settings['host'] . ':' . $settings['port'];
    $context = stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'peer_name'=>$settings['host']]]);
    $socket = @stream_socket_client($remote, $errorNumber, $errorMessage, 10, STREAM_CLIENT_CONNECT, $context);
    if ($socket === false) {
        throw new RuntimeException('SMTP connect failed: ' . ($errorMessage ?: 'connection refused'));
    }
    stream_set_timeout($socket, 10);
    try {
        vmange_smtp_expect(vmange_smtp_read($socket), [220], 'SMTP greeting');
        vmange_smtp_write($socket, 'EHLO vmange.local');
        vmange_smtp_expect(vmange_smtp_read($socket), [250], 'SMTP EHLO');
        if ($settings['encryption'] === 'tls') {
            vmange_smtp_write($socket, 'STARTTLS');
            vmange_smtp_expect(vmange_smtp_read($socket), [220], 'SMTP STARTTLS');
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP STARTTLS encryption failed');
            }
            vmange_smtp_write($socket, 'EHLO vmange.local');
            vmange_smtp_expect(vmange_smtp_read($socket), [250], 'SMTP EHLO after STARTTLS');
        }
        if ($settings['username'] !== '' && $settings['password'] !== '') {
            vmange_smtp_write($socket, 'AUTH LOGIN');
            vmange_smtp_expect(vmange_smtp_read($socket), [334], 'SMTP authentication challenge');
            vmange_smtp_write($socket, base64_encode($settings['username']));
            vmange_smtp_expect(vmange_smtp_read($socket), [334], 'SMTP username');
            vmange_smtp_write($socket, base64_encode($settings['password']));
            vmange_smtp_expect(vmange_smtp_read($socket), [235], 'SMTP password');
        }
        vmange_smtp_write($socket, 'MAIL FROM:<' . $settings['from'] . '>');
        vmange_smtp_expect(vmange_smtp_read($socket), [250], 'SMTP sender');
        vmange_smtp_write($socket, 'RCPT TO:<' . $to . '>');
        vmange_smtp_expect(vmange_smtp_read($socket), [250, 251], 'SMTP recipient');
        vmange_smtp_write($socket, 'DATA');
        vmange_smtp_expect(vmange_smtp_read($socket), [354], 'SMTP DATA');
        $safeSubject = str_replace(["\r", "\n"], ' ', $subject);
        $safeBody = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $body));
        $message = "Subject: {$safeSubject}\r\nFrom: {$settings['from']}\r\nTo: {$to}\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n"
            . str_replace("\n", "\r\n", (string) $safeBody) . "\r\n.\r\n";
        if (fwrite($socket, $message) === false) {
            throw new RuntimeException('SMTP message write failed');
        }
        $deliveryResponse = vmange_smtp_read($socket);
        vmange_smtp_expect($deliveryResponse, [250], 'SMTP delivery');
        vmange_smtp_write($socket, 'QUIT');
        return [true, $deliveryResponse];
    } finally {
        fclose($socket);
    }
}

function vmange_send_mail(string $to, string $subject, string $body): array
{
    $settings = vmange_mail_settings();
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($settings['from'], FILTER_VALIDATE_EMAIL)) {
        return [false, 'A valid recipient and from address are required'];
    }
    if ($settings['transport'] === 'php') {
        return vmange_local_mail($to, $subject, $body, $settings['from']);
    }
    if ($settings['host'] === '') {
        return $settings['transport'] === 'auto'
            ? vmange_local_mail($to, $subject, $body, $settings['from'])
            : [false, 'SMTP host is required'];
    }
    try {
        return vmange_smtp_mail($settings, $to, $subject, $body);
    } catch (RuntimeException $exception) {
        if ($settings['transport'] !== 'auto') {
            return [false, $exception->getMessage()];
        }
        [$accepted, $fallbackMessage] = vmange_local_mail($to, $subject, $body, $settings['from']);
        return [$accepted, $exception->getMessage() . '; ' . $fallbackMessage];
    }
}
