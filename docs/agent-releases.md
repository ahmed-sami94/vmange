# Agent Releases And Rollouts

## Installed agents

Open **Agents**, then **Installed Agents**. The reported version comes from an authenticated heartbeat, not the selected release. An offline host cannot confirm an upgrade.

## Publish a release

Upload a Bash artifact with LF line endings, an exact `AGENT_VERSION="vX.Y.Z"` declaration, compatibility requirements and release notes. Maximum size is 512 KB. A version cannot be replaced. Upload creates a draft; publication makes it available without installing it.

Files are stored outside the document root. Set `release_storage` in protected configuration if the default private directory is not writable. Downloads require an active host credential and HTTPS. PHP never executes uploaded agents.

## Canary and batch rollout

1. Select a release and one disposable canary host.
2. Queue the release and inspect **Rollouts** and **Audit**.
3. Confirm the new version appears in an authenticated heartbeat.
4. After a successful canary, select additional hosts for that release.

The agent requires a matching SHA-256 checksum, matching version and successful Bash syntax validation before atomic replacement. Checksums verify consistency with the server artifact, not an independent publisher signature. Review code before publishing it.

## Recovery and rollback

The previous executable is retained as `vmange-agent.previous` beside the installed executable. Configuration remains in `/etc/vmange/agent.env`. Older bundled releases can be selected for rollback; review their security limitations first.

A missing version acknowledgement is not success. It expires after 15 minutes. Use console access to inspect the service and restore the previous executable when polling is unavailable. A failed agent cannot receive remote recovery commands.

The maintenance socket requires a one-time v2 installer run. Updating only the executable does not provision root-owned services.
