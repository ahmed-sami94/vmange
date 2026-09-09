# Command Lifecycle

Remote work is outbound-only. The dashboard creates an allowlisted command, and the host agent claims it on its next heartbeat.

## States

- `pending`: queued and waiting for a host heartbeat.
- `running`: claimed by one agent lease and currently executing.
- `done`: completed with exit code zero.
- `failed`: completed with a non-zero exit code or a host preflight error.
- `expired`: the host did not complete its lease before the timeout.

## Diagnostics

Every completed command stores stdout, stderr, exit code, error code, timestamps, and agent context such as the run user, HOME, and detected tool path. Use Audit to inspect the exact reason for a failed VM, Docker, Compose, script, or terminal action.

## Reliability

Commands are leased atomically so two agent loops cannot execute the same action. After VM or Docker actions, the agent refreshes inventory before posting the final result. VM UUID is preferred when supplied; the display name is only a fallback.
