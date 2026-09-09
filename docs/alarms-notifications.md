# Alarms And Notifications

VMange can evaluate host heartbeats against alarm rules and surface active issues in the dashboard.

## Supported rules
- CPU percentage
- Memory percentage
- Disk percentage
- Offline host state

## Workflow
- Create a rule from the Alarms page.
- Active alarms appear in the top bar and in alarm history.
- Acknowledge an active alarm after review.
- Resolved alarms close automatically when the metric returns below threshold.

## Mail
Configure SMTP and IMAP from Settings. The mail test and scheduled monitor use the same selected transport. SMTP-only mode is recommended because it reports authentication and delivery failures instead of silently switching transports.

Notification delivery records show each attempt, the server response, and the next retry. Failed messages retry with increasing delays and stop after five attempts. The Alarms page also shows the last successful monitor-worker run so a broken cron job is visible.

IMAP is used only for connection verification and future inbound mailbox workflows. It is not required for outbound alarms.

## Scheduled worker

The dashboard does not evaluate alarms during page views. Configure `cron.php` to run once per minute with the deployment cron secret. Metrics older than the configured retention period, six hours by default, are deleted by this worker.
