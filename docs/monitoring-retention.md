# Monitoring And Retention

VMange keeps monitoring history for the latest six hours by default. This keeps shared-hosting databases small while preserving enough context for incident review.

## Heartbeats

Each host agent sends a heartbeat at the configured interval. The heartbeat contains host metrics, uptime, network counters, capabilities, VM runtime state, Docker inventory, and collector errors.

## Scheduled worker

The `cron.php` endpoint evaluates alarm rules and prunes old metric rows. Protect it with `VBOX_CRON_SECRET` and schedule it every minute from cPanel or the host scheduler:

```text
* * * * * curl -fsS "https://example.test/vbox/cron.php?secret=YOUR_SECRET" >/dev/null
```

Docker deployments include a separate `worker` service. It runs the same worker loop without exposing a public endpoint.

## Chart range

The dashboard history is limited to the retained window. If a host was offline, the chart shows the gap instead of inventing zero values.
