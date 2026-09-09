# VMange Deployment Notes

VMange supports direct PHP routing, so it can run under `/public_html/vbox/`, `https://domain.com/vbox/`, `https://vbox.domain.com/`, a standalone PHP host, a Docker container, a local VM, public internet, or private LAN.

## Configuration

For a new install, open `install.php` in the browser. It asks for the base URL, database host/name/user/password, tests the database connection, confirms the settings, imports `db/schema.sql`, creates the first admin user, writes `config.php`, and creates `storage/installer.lock`.

For manual installs, copy `config.example.php` to `config.php`, or place `vbox-config.php` one directory above the web folder. Prefer the external file on shared hosting when possible.

Required settings:

- `base_url`: full public URL, for example `https://domain.com/vbox` or `https://vbox.domain.com`
- `db_host`, `db_name`, `db_user`, `db_pass`
- New installations use per-host enrollment tokens; leave legacy authentication disabled.
- `setup_token`: temporary token for `create_user.php`
- `force_https`: set to `true` for public deployments
- optional SMTP/IMAP values for alarm notifications and mailbox workflows

For Docker, the same values can be supplied as environment variables:

- `VBOX_BASE_URL`
- `VBOX_FORCE_HTTPS`
- `VBOX_CRON_SECRET`
- `VBOX_METRICS_RETENTION_HOURS` (default `6`)
- `VBOX_ENCRYPTION_KEY` (required for stored secrets; at least 32 characters)
- `VBOX_WEBHOOK_URL` and `VBOX_WEBHOOK_SECRET` (optional alarm webhook)
- `VBOX_DB_HOST`
- `VBOX_DB_NAME`
- `VBOX_DB_USER`
- `VBOX_DB_PASS`
- `VBOX_SETUP_TOKEN`
- `VBOX_SMTP_HOST`, `VBOX_SMTP_PORT`, `VBOX_SMTP_USERNAME`, `VBOX_SMTP_PASSWORD`
- `VBOX_IMAP_HOST`, `VBOX_IMAP_PORT`, `VBOX_IMAP_USERNAME`, `VBOX_IMAP_PASSWORD`

## Database

Import `db/schema.sql`. It creates the original VM tables plus additive tables for metrics, audit logs, rate limiting, command payloads, roles, and per-host tokens.

## Shared Hosting / WordPress Subfolder

Upload the contents of this folder to `/public_html/vbox/`. The included `.htaccess` disables rewrite handling inside the subfolder and keeps direct PHP file routing, avoiding WordPress rewrite conflicts and avoiding Apache config changes.

Do not leave generated install scripts or config files in a public directory. `storage/.htaccess` blocks direct access on Apache.

## Docker

From the `vbox` directory:

```bash
cp .env.example .env
# Fill all blank secrets in .env with independently generated values.
# Generate each value with: openssl rand -hex 32
docker compose up -d --build
```

Compose refuses to start when required secrets are blank. Use the same encryption
key for the app and worker. Open `/create_user.php?setup_token=YOUR_SETUP_TOKEN`
to create your first administrator after MariaDB is ready. For public deployments,
configure HTTPS and set the public base URL before enrolling hosts.

The distributed database schema contains no user accounts or host inventory.
Each installation starts with its own database, administrator and host tokens.
Never publish `.env`, `config.php`, database exports or the contents of `storage/`.

The compose file persists MariaDB in the `vmange-db` volume and mounts `./storage` for installer locks and private runtime files.

## Host Agent

Install `vbox-agent.sh` on each Linux host. Set:

```bash
VMANGE_API_URL="https://domain.com/vbox/api.php"
VMANGE_TOKEN="host-token"
VMANGE_HOSTNAME="host01"
```

The dashboard can rotate per-host tokens after the schema has been imported. Generated install scripts use the configured base URL, so they work in subfolder, subdomain, local VM, and container deployments.

## Alarms

Use the Alarms page to create CPU, memory, disk, and offline-host policies. Configure mail settings from Settings before enabling email notifications.

For shared hosting, schedule the protected worker endpoint every minute:

```cron
* * * * * /usr/bin/curl -fsS "https://example.test/vbox/cron.php?secret=YOUR_CRON_SECRET" >/dev/null 2>&1
```

For Docker, `docker compose up -d --build` starts both the Apache app and the `worker` service. The worker evaluates alarms and prunes metrics without a browser request.

## Security Checklist

- Enforce HTTPS for public deployments.
- Import the schema before rotating tokens or relying on audit/rate-limit tables.
- Create users through `create_user.php?setup_token=...`, then keep `storage/installer.lock` in place.
- Use `admin`, `operator`, and `viewer` roles.
- Use the dashboard confirmation modal for destructive VM/container/compose actions.
- Keep `config.php`, SQL dumps, logs, and generated scripts outside public access.
- Keep host tokens unique per host and rotate them after staff changes or suspected exposure.
