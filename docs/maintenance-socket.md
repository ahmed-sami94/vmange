# Local Maintenance Service

## Trust boundary

The collector runs as the enrolled Linux user with `NoNewPrivileges=true`. Package installation, service restart and host reboot use a separate root-owned service. VMange 2.0 uses a local Unix socket, not a public listener or unrestricted sudo.

The installer creates `/run/vmange-maintenance.sock` through systemd with mode `0600`, owned by the enrolled user. Linux `SO_PEERCRED` checks each connection. Only root and the enrolled UID are accepted. Requests contain an allowlisted action and optionally the enrolled username. Arbitrary commands and other usernames are rejected.

## Provisioning

Run the v2 host installer once with root privileges on each host. An agent-file upgrade cannot install a new root-owned system service. Python 3 and systemd are required. Checksums are verified before replacing downloaded files; saved Compose projects are preserved.

```bash
sudo systemctl status vmange-maintenance.socket
sudo systemctl cat vmange-maintenance@.service
sudo journalctl -u 'vmange-maintenance@*' -n 50 --no-pager
```

## Permitted maintenance

- Probe availability.
- Install Docker or VirtualBox using the host package manager.
- Repair VirtualBox access for the enrolled user.
- Restart or uninstall the agent.
- Reboot the host after dashboard confirmation.

The enrolled user has explicit access to these privileged operations. Do not use an untrusted shared Linux account. Docker group membership also provides powerful host access.

## Troubleshooting

If the socket is missing, rerun the installer. Do not remove `NoNewPrivileges` or grant unrestricted sudo as a workaround. Check socket ownership and the configured user. Validate peer-credential enforcement and package installation on a disposable canary before fleet rollout.
