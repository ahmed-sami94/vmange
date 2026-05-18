# VirtualBox Management

VMange follows phpVirtualBox-style management patterns while keeping the outbound agent architecture.

- VM actions are allowlisted and executed by the host agent.
- VM UUID is preferred over name when queueing actions.
- Power actions include start, graceful stop, pause, resume, reset, restart, and force poweroff.
- Management actions include snapshots, ISO attach/detach, network adapter settings, CPU/RAM, clone, export, screenshots, and VM logs.
- After each action, the agent immediately refreshes inventory before reporting the final result.

Shared hosting cannot connect directly to `vboxwebsrv`; the agent remains the source of truth.
