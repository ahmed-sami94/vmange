# Console And Terminal Gateway

Shared hosting mode supports:

- VirtualBox VRDE enable/disable.
- RDP connection details.
- VM screenshot capture.
- Responsive audited command terminal with command/output history, timestamps, and exit-state feedback.

## Scripts and audited commands

Saved scripts can be edited and queued on selected hosts by administrators. Inspect per-host command output in Audit. The command terminal is not a live PTY: each command runs separately and does not retain an interactive shell's current directory or processes. Do not enter passwords into command text; command history is retained for auditing.

## Streaming limitations

True browser console and PTY terminal require a separate authenticated gateway. This release does not install a streaming gateway or expose a new public agent listener. Existing `terminal_gateway_enabled`, `terminal_gateway_url` and `gateway_url` hooks remain, but an external gateway and a separately reviewed CSP/integration configuration are prerequisites. Leave these disabled in ordinary shared hosting. Gateway URLs must use HTTPS and cannot embed credentials or query tokens.
