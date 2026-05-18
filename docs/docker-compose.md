# Docker And Compose

VMange stores Compose projects and sends them to the selected host through the agent.

- Save a Compose stack first.
- VMange queues deploy/start/stop/restart/pull actions to the host.
- The agent writes Compose files under `/var/lib/vmange/compose/<project>/compose.yml`.
- `docker compose config` is run before deploy.
- Container and image inventory is preserved if one Docker collector heartbeat fails.

Container actions include start, stop, restart, pause, unpause, kill, delete, and logs.
