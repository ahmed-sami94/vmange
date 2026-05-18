# Troubleshooting

For host offline issues, check:

- `systemctl status vmange-agent.service`
- `journalctl -u vmange-agent.service -n 80 --no-pager`
- `cat /etc/vmange/agent.env`
- `sudo -u <run-user> env HOME=<home> VBoxManage list runningvms`
- `sudo -u <run-user> /var/lib/vmange/bin/vmange-agent metrics`

For VM state issues, VMange prefers live `running_vm_names` and `running_vm_uuids` from the agent. If the dashboard is wrong, refresh inventory and inspect the latest operation diagnostics.

For Docker inventory issues, confirm the agent run user can access Docker or is in the `docker` group.
