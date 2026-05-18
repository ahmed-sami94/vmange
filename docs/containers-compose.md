# Containers And Compose

VMange records Docker containers, images, and saved Compose stacks from each host.

## Containers
- Search by container name, image, host, status, or port.
- Available actions include start, stop, restart, pause, logs, and remove.

## Compose stacks
- Save reusable stacks in the dashboard.
- When deploying from the editor, choose whether to save and deploy or run a one-time deployment.
- Deployments are validated with `docker compose config` before they run.
- Saved stacks can be edited, started, stopped, restarted, pulled, redeployed, or removed later.

## Collector behavior
If Docker collection fails temporarily, VMange keeps the last known inventory and records the collector error instead of silently wiping the list.
