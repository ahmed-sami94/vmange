# Security Model

VMange uses defense in depth:

- Login required for all dashboard pages.
- CSRF protection for actions.
- Role-based controls for admin, operator, and viewer.
- Per-host enrollment tokens.
- Allowlisted host commands only; no arbitrary dashboard shell execution except the admin-only audited terminal mode.
- Dangerous actions require confirmation.
- Host delete revokes the token and queues agent uninstall when possible.
- Audit logs record important actions and command outcomes.
- Alarm notifications and mail settings should use dedicated service accounts where possible.

For public deployments, use HTTPS and avoid exposing installer or generated scripts permanently.
