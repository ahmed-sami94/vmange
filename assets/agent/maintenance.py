#!/usr/bin/env python3
"""One allowlisted request on a systemd-accepted Unix socket."""
import json
import os
import pwd
import socket
import struct
import subprocess
import sys

ALLOWED = {"probe", "install-docker", "install-virtualbox", "repair-virtualbox-access",
           "restart-agent", "reboot-host", "uninstall-agent"}


def validate_request(request, peer_uid, run_user):
    if peer_uid not in (0, pwd.getpwnam(run_user).pw_uid):
        raise ValueError("Socket peer is not the enrolled agent user")
    if not isinstance(request, list) or not 1 <= len(request) <= 2:
        raise ValueError("Expected action and optional run user")
    if any(not isinstance(arg, str) for arg in request) or request[0] not in ALLOWED:
        raise ValueError("Maintenance action is not permitted")
    if len(request) == 2 and request[1] != run_user:
        raise ValueError("Maintenance cannot target another user")
    return ["/usr/local/sbin/vmange-root-helper", request[0], run_user]


def serve(run_user):
    connection = socket.socket(fileno=os.dup(0))
    connection.settimeout(10)
    try:
        _, uid, _ = struct.unpack("3i", connection.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, 12))
        wire = bytearray()
        while b"\n" not in wire and len(wire) <= 4096:
            chunk = connection.recv(1024)
            if not chunk:
                break
            wire.extend(chunk)
        if len(wire) > 4096 or not wire.endswith(b"\n"):
            raise ValueError("Invalid maintenance request length")
        command = validate_request(json.loads(wire), uid, run_user)
        completed = subprocess.run(command, capture_output=True, text=True, timeout=840,
                                   env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "HOME": "/root", "LANG": "C.UTF-8"})
        reply = {"exit_code": completed.returncode, "stdout": completed.stdout[-60000:], "stderr": completed.stderr[-60000:]}
    except (ValueError, KeyError, OSError, subprocess.TimeoutExpired) as error:
        reply = {"exit_code": 1, "stdout": "", "stderr": str(error)}
    connection.sendall((json.dumps(reply) + "\n").encode())
    connection.close()


if __name__ == "__main__":
    serve(sys.argv[1])
