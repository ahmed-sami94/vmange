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
Configure SMTP and IMAP from Settings. SMTP is used for outbound alarm delivery, while IMAP is available for inbound mailbox workflows where required by the deployment.
