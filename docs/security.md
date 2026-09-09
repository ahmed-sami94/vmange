# Security Model

## VMange 2.0 authentication

All historical agent URLs delegate to one handler. Heartbeats, command claims and results require an active token bound to the requested hostname. Random host tokens are stored as SHA-256 hashes. Hostnames and former shared tokens are not authorization. Incorrect or revoked credentials require re-enrollment.

The Security page shows credential and encryption readiness without saved secrets. Rotation shows a new token once; update the protected host configuration immediately. Check legitimate credentials before deploying the v1.9.2 authentication hotfix. Older enrollment failures may need fresh enrollment.

## TLS and encrypted settings

Agent connections and downloads require verified HTTPS. Agent upgrades do not follow redirects. SMTP and IMAP require certificate-verified TLS. Reverse-proxy deployments must explicitly set exact proxy IPs in `VBOX_TRUSTED_PROXY_IPS`; arbitrary forwarded headers are not trusted.

Recoverable stored integration secrets use AES-256-GCM with a dedicated server key. Damaged ciphertext and incorrect keys fail visibly. Keep the key outside published packages and exports, with a separate protected recovery copy. Encrypted configuration backups use Sodium and a passphrase-derived key.

Read the [maintenance service guide](docs.php?page=maintenance-socket) before enabling privileged host actions. Review release artifacts before publishing them; a checksum is not an independent publisher signature.

VMange uses defense in depth:

- Login required for all dashboard pages.
- CSRF protection for actions.
- Role-based controls for admin, operator, and viewer.
- Central authorization on every generic and dedicated action endpoint.
- Per-host enrollment tokens.
- Allowlisted host commands only; no arbitrary dashboard shell execution except the admin-only audited terminal mode.
- Dangerous actions require confirmation.
- Host delete revokes the token and queues agent uninstall when possible.
- Audit logs record important actions and command outcomes.
- Alarm notifications and mail settings should use dedicated service accounts where possible.
- New SMTP and IMAP passwords require OpenSSL and a dedicated encryption key of at least 32 characters.
- Executable browser dependencies are served locally under the dashboard Content Security Policy.

For public deployments, use HTTPS, leave the legacy shared token empty, rotate credentials after any suspected exposure, and avoid exposing installer or generated scripts permanently.
