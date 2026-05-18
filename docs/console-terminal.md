# Console And Terminal Gateway

Shared hosting mode supports:

- VirtualBox VRDE enable/disable.
- RDP connection details.
- VM screenshot capture.
- Responsive audited command terminal with command/output history, timestamps, and exit-state feedback.

True browser console and PTY terminal need a separate gateway. WeTTY is the reference model: xterm.js in the browser connected to a Node/WebSocket terminal service. Configure `terminal_gateway_enabled`, `terminal_gateway_url`, and `gateway_url` only when that service is deployed.
