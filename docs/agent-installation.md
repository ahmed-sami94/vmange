# Host Agent Installation

Use **Add new host** from the dashboard. VMange creates a short-lived enrollment token and a command that downloads the installer from the current base URL.

- Run the generated command on the Linux host.
- The installer writes `/etc/vmange/agent.env`.
- The agent runs from `/var/lib/vmange/bin/vmange-agent`.
- The systemd unit is `vmange-agent.service`.
- The installer creates `/usr/local/sbin/vmange-root-helper`, a root-owned allowlisted helper for package installation, reboot, agent restart, and full uninstall.
- The agent polls VMange outbound, so VMange does not SSH into the host.

After install, the host should show online after one heartbeat. VirtualBox, Docker, Compose, IPs, uptime, and agent version are reported by the heartbeat.

## Agent versions

Open **Agents** to see every host, its installed version, the latest release, checksums, and release notes. Administrators can install the latest release or select a preserved rollback release. VMange accepts agent download URLs only from its own trusted agent directory and verifies the SHA-256 checksum before replacing the installed agent.

Hosts installed before v1.8.0 should run the generated installer once as root. A normal code-only upgrade cannot create the root maintenance helper or its restricted sudo policy.

## Action verification

Agent v1.8.2 verifies the final VirtualBox state for start, stop, pause, resume, reset, and restart. It also retries one transient start failure and includes relevant `VBox.log` diagnostics when startup still fails. Saved scripts, terminal commands, Compose YAML, and Dockerfiles are normalized to Unix line endings before use, so files authored on Windows run correctly on Linux. A successful command result therefore means VMange observed the requested state, not only that `VBoxManage` exited.

The latest installer also repairs the standard Linux `vboxusers` device permissions for `/dev/vboxdrv`, `/dev/vboxdrvu`, and `/dev/vboxnetctl`. If a host reports **VirtualBox installed** but **Device access unavailable**, rerun the generated installer once, then use **Repair VirtualBox access** from Host tools if needed.
