# Hosts

Hosts are Linux machines enrolled into VMange with one outbound agent each.

## Host status
- Online means the agent has checked in inside the configured freshness window.
- Capability chips show VirtualBox, Docker, and Compose when the agent can use them.
- Host cards also show uptime, IPs, and recent resource use.

## Host tools
- Refresh inventory asks the agent to send fresh host state.
- Restart agent restarts only the VMange service.
- Reboot requires a second typed hostname confirmation.
- Wake-on-LAN uses a saved MAC profile and an online relay host.

## Capability actions
If VirtualBox, Docker, or Compose is already detected, VMange shows an installed state. Missing capabilities expose repair or install actions instead.
