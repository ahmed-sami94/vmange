# Configuration Backups

## Export formats

Configuration backups are administrator-only. Redacted JSON includes reusable mail settings, alarm policies and host/WOL metadata. Script and stack identities are included without bodies or descriptions. These identities are informational and are skipped on import.

Encrypted backups include script and Compose bodies. Integration credentials may be included explicitly. Use a unique passphrase of at least 16 characters, stored separately. PHP Sodium is required; encryption uses Argon2id-derived keys and authenticated secretbox encryption.

Both formats exclude database credentials, the server encryption key, login accounts, enrollment tokens, sessions, command queues, metrics and release artifacts. This is configuration portability, not a replacement for database and filesystem disaster-recovery backups.

## Import procedure

1. Upload the file and enter its passphrase if encrypted.
2. Choose **Keep existing records** or **Replace matching records**.
3. Select **Validate and preview** and review the changes.
4. Apply the preview after checking the destination installation.

Unsupported versions, unrecognized sections, invalid policy values and damaged ciphertext are rejected. Changes use a database transaction. Imported integration credentials are encrypted using the destination key.

## No automatic execution

Imports never run scripts, deploy stacks, enroll hosts, queue commands or install agents. Host metadata is not an authorization credential. Enroll hosts separately before they can report data.

Take a protected database backup before replacing configuration. Refresh the preview immediately before applying it when other administrators may be editing configuration.
