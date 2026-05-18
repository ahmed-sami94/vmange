# WOL And Host Tools

Wake-on-LAN lets one online host send a magic packet for another machine.

## Configure Wake-on-LAN
- The agent reports detected interfaces and VMange automatically refreshes the current WOL MAC from the first physical NIC it finds.
- Review the detected interfaces in the host page before sending the first wake packet.
- Save its broadcast address and UDP port, usually `9`.
- Optionally choose a preferred relay host.

## Send a wake packet
Choose the offline target and an online relay. The relay agent tries `wakeonlan`, then `etherwake`, then a built-in Python fallback.

## Reboot safety
Host reboot is intentionally guarded twice: first by confirmation, then by typing the hostname exactly.
