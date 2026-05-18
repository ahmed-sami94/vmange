# Host Agent Installation

Use **Add new host** from the dashboard. VMange creates a short-lived enrollment token and a command that downloads the installer from the current base URL.

- Run the generated command on the Linux host.
- The installer writes `/etc/vmange/agent.env`.
- The agent runs from `/var/lib/vmange/bin/vmange-agent`.
- The systemd unit is `vmange-agent.service`.
- The agent polls VMange outbound, so VMange does not SSH into the host.

After install, the host should show online after one heartbeat. VirtualBox, Docker, Compose, IPs, uptime, and agent version are reported by the heartbeat.
