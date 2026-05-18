# VMange
<img width="1004" height="666" alt="vboxmange" src="https://github.com/user-attachments/assets/3b20de8e-457a-4ba8-8115-51ef85409859" />

VMange is a free and open-source infrastructure management dashboard for Linux hosts, VirtualBox, Docker, Docker Compose, scripts, terminal workflows, monitoring, and alarms.

It is designed around outbound host agents, so it can work from shared hosting, a WordPress subfolder, a public subfolder, a subdomain, a standalone PHP host, a Docker deployment, or a private local VM without requiring VMange to SSH directly into managed hosts.

## Highlights

- Grafana-style host monitoring with CPU, load, RAM, swap, disk, network traffic, and time-series history
- VirtualBox inventory and remote management for VM power actions, snapshots, storage, network, VRDE, screenshots, logs, cloning, exports, and VM creation
- Docker container and image inventory with start, stop, restart, pause, unpause, kill, remove, logs, and image actions
- Persistent Docker Compose stack management with save, edit, validate, deploy, start, stop, restart, pull, and delete workflows
- Host enrollment with per-host tokens, agent upgrade/reinstall, capability detection, IP reporting, uptime, Wake-on-LAN, and host reboot controls
- Reusable scripts with per-host execution status and output history
- Audited command terminal plus optional terminal gateway support for deployments that can host a live PTY service
- Alarm policies for CPU, memory, disk, and offline hosts, with notification tracking and mail configuration
- Security controls including login, CSRF protection, RBAC, allowlisted actions, prepared statements, audit logs, installer locking, token rotation, and confirmation gates for destructive actions

## Why VMange

VMange aims to bring together the daily workflows usually split across several tools:

- monitor Linux hosts
- manage VirtualBox VMs
- manage Docker resources
- save and operate Compose stacks
- run audited host actions
- keep a record of what changed and why

The project favors practical operations, clear diagnostics, and deployment flexibility over unnecessary complexity.

## Main Features

### Host Management

- Add hosts from the dashboard with generated install commands
- Per-host enrollment tokens and token rotation
- Online/offline status, IP addresses, uptime, kernel, and agent version
- Capability indicators for VirtualBox, Docker, and Compose
- Agent restart, upgrade, reinstall, and uninstall workflows
- Install/repair actions for Docker and VirtualBox
- Wake-on-LAN profiles and relay-host workflow
- Safe reboot flow with confirmation

### Monitoring And Alarms

- CPU, load, RAM, swap, disk, RX, and TX metrics
- Time-series charts and host drill-down pages
- Fleet overview plus per-host metrics
- Alarm rules for CPU, memory, disk, and offline hosts
- Active alarm count in the dashboard header
- Alarm acknowledgement and history
- SMTP/IMAP configuration for notification workflows

### VirtualBox Management

- Live VM inventory and runtime state resolution
- Start, stop, force poweroff, pause, resume, reset, and restart
- Snapshots: create, restore, delete
- CPU, RAM, VRAM, boot order, description, and autostart settings
- ISO and disk attach/detach workflows
- Network adapter settings
- Clone, export, logs, screenshots, and VRDE connection guidance
- VM creation flow with Ubuntu unattended-install support

### Docker And Compose

- Container inventory with name, image, ports, state, and logs
- Container lifecycle actions
- Docker image inventory
- Saved Compose stacks that remain reusable across sessions
- Compose validation before deployment
- Dockerfile build workflow
- Last-known inventory retention when a collector fails temporarily

### Scripts And Terminal

- Save reusable scripts
- Run scripts on selected hosts
- Per-host run result tracking
- Audited terminal command history
- Optional live terminal gateway integration for deployments that support it

### Security

- Authenticated dashboard access
- Role-based permissions for admin, operator, and viewer
- CSRF protection on writes
- Per-host API tokens
- Allowlisted command model
- Audit trails and command diagnostics
- Confirmation dialogs for destructive actions
- Installer lock support
- No arbitrary dashboard shell execution except the explicit admin-only terminal workflow

## Deployment Options

VMange supports:

1. Shared hosting under a WordPress/public subfolder such as `/public_html/vbox/`
2. Public subfolder deployments such as `https://domain.com/vbox/`
3. Subdomains such as `https://vbox.domain.com/`
4. Standalone PHP hosts
5. Docker containers
6. Local VMs
7. Public internet deployments
8. Private LAN-only deployments

For installation and deployment notes, see [README_DEPLOYMENT.md](README_DEPLOYMENT.md).

## Documentation Guide

VMange includes authenticated in-app documentation pages. The current documentation set is:

| Document | Summary |
| --- | --- |
| Getting Started | First login, adding hosts, and the main dashboard areas |
| Installation | Deployment modes, archive layout, installer usage, and shared-hosting notes |
| Host Agent Installation | How the generated installer works and where agent files live |
| Hosts | Host health, capabilities, maintenance tools, reboot, and Wake-on-LAN |
| Virtual Machines | VM inventory, runtime state, detail panels, and common actions |
| Containers And Compose | Docker inventory, saved stacks, validation, and collector behavior |
| Docker And Compose | Compose project lifecycle and Docker actions |
| WOL And Host Tools | Wake-on-LAN profiles, relay hosts, and reboot safety |
| Audit And Logs | Command history, status meanings, and diagnostics |
| Alarms And Notifications | Threshold rules, alarm lifecycle, and mail configuration |
| Console And Terminal Gateway | Shared-hosting console guidance and optional gateway behavior |
| Troubleshooting | Host offline, VM state, and Docker collector checks |
| Security Model | Roles, CSRF, tokens, confirmations, and deployment hardening |
| About VMange | Project purpose, author, and contribution notes |

## Project Structure

```text
assets/        Frontend assets and host agent script
db/            Database schema
docs/          In-app documentation pages
storage/       Private runtime storage
index.php      Main authenticated dashboard
docs.php       Documentation site
agent-sync.php Agent heartbeat and command endpoint
```

## Configuration

Core settings are stored in `config.php`, external `vbox-config.php`, or environment variables depending on deployment style.

Important settings include:

- base URL
- database credentials
- agent token values
- HTTPS enforcement
- optional gateway URLs
- SMTP/IMAP settings

See [README_DEPLOYMENT.md](README_DEPLOYMENT.md) for the full deployment matrix.

## Contributing

VMange is free and open source under the Apache-2.0 license.

Contributions are welcome:

- report bugs
- suggest improvements
- improve documentation
- submit fixes and new features

For bug reports, contact: `i@ahmed-sami.me`

When reporting a bug, include:

- VMange version
- agent version
- deployment mode
- affected host
- action attempted
- exact error message
- relevant audit or agent log output

## Author

VMange is created by Ahmed Sami Abdelhamed, a cloud and infrastructure engineer with experience across infrastructure design, cloud operations, virtualization, Linux, Docker, OpenStack, networking, automation, CI/CD, and service reliability. His background includes work across enterprise support, cloud operations, hybrid infrastructure, and technical leadership roles. Learn more at [ahmed-sami.me](https://ahmed-sami.me/). 

## License

VMange is licensed under the Apache License 2.0. See [LICENSE](LICENSE).
