<?php
declare(strict_types=1);
require_once __DIR__ . '/agent-auth.php';

function installer_base_url(string $path = ''): string
{
    $https = agent_request_is_https();
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/D', $host)) throw new RuntimeException('Invalid request host');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/vbox/host-install.php')), '/');
    $base = $scheme . '://' . $host . $dir;

    $configFile = __DIR__ . '/config.php';
    if (is_file($configFile)) {
        $config = require $configFile;
        if (is_array($config) && !empty($config['base_url'])) {
            $base = rtrim((string) $config['base_url'], '/');
        }
    }

    return $base . '/' . ltrim($path, '/');
}

$apiUrl = installer_base_url('agent-sync.php');
$agentUrl = installer_base_url('assets/agent/vmange-agent.sh');
$versionManifest = __DIR__ . '/assets/agent/version.json';
$versionData = is_file($versionManifest) ? json_decode((string) file_get_contents($versionManifest), true) : [];
$installerVersion = is_array($versionData) && is_string($versionData['version'] ?? null)
    ? $versionData['version']
    : 'v1.9.0';

header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

echo <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

BASH;

echo 'VMANGE_DEFAULT_API_URL=' . escapeshellarg($apiUrl) . PHP_EOL;
echo 'VMANGE_DEFAULT_AGENT_URL=' . escapeshellarg($agentUrl) . PHP_EOL;
echo 'VMANGE_INSTALLER_VERSION=' . escapeshellarg($installerVersion) . PHP_EOL;
echo 'VMANGE_AGENT_SHA256=' . escapeshellarg(hash_file('sha256', __DIR__ . '/assets/agent/vmange-agent.sh')) . PHP_EOL;
echo 'VMANGE_MAINTENANCE_URL=' . escapeshellarg(installer_base_url('assets/agent/maintenance.py')) . PHP_EOL;
echo 'VMANGE_MAINTENANCE_SHA256=' . escapeshellarg(hash_file('sha256', __DIR__ . '/assets/agent/maintenance.py')) . PHP_EOL;

echo <<<'BASH'

need_root() {
  if [ "$(id -u)" -ne 0 ]; then
    echo "Run this installer with sudo." >&2
    exit 1
  fi
}

ask_value() {
  local prompt="$1"
  local default_value="${2:-}"
  local value
  if [ -n "$default_value" ]; then
    read -r -p "$prompt [$default_value]: " value </dev/tty
    printf '%s' "${value:-$default_value}"
  else
    read -r -p "$prompt: " value </dev/tty
    printf '%s' "$value"
  fi
}

ask_secret() {
  local prompt="$1"
  local value
  read -r -s -p "$prompt: " value </dev/tty
  printf '\n' >&2
  printf '%s' "$value"
}

value_or_prompt() {
  local provided_value="${1:-}"
  local prompt="$2"
  local default_value="${3:-}"
  if [ -n "$provided_value" ]; then
    printf '%s' "$provided_value"
    return
  fi
  if [ "${VMANGE_NONINTERACTIVE:-0}" = "1" ]; then
    printf '%s' "$default_value"
    return
  fi
  ask_value "$prompt" "$default_value"
}

resolve_run_user() {
  if [ -n "${VMANGE_RUN_USER_PRESET:-}" ] && id -u "$VMANGE_RUN_USER_PRESET" >/dev/null 2>&1; then
    printf '%s' "$VMANGE_RUN_USER_PRESET"
    return
  fi
  if [ -n "${VMANGE_RUN_USER:-}" ] && id -u "$VMANGE_RUN_USER" >/dev/null 2>&1; then
    printf '%s' "$VMANGE_RUN_USER"
    return
  fi
  if [ -n "${SUDO_USER:-}" ] && [ "$SUDO_USER" != "root" ] && id -u "$SUDO_USER" >/dev/null 2>&1; then
    printf '%s' "$SUDO_USER"
    return
  fi
  local login_name
  login_name="$(logname 2>/dev/null || true)"
  if [ -n "$login_name" ] && [ "$login_name" != "root" ] && id -u "$login_name" >/dev/null 2>&1; then
    printf '%s' "$login_name"
    return
  fi
  printf 'root'
}

user_home_dir() {
  getent passwd "$1" | cut -d: -f6
}

safe_hostname() {
  printf '%s' "$1" | tr -cd 'A-Za-z0-9._-'
}

install_package_tools() {
  if command -v curl >/dev/null 2>&1; then
    return
  fi
  if command -v apt-get >/dev/null 2>&1; then
    apt-get update
    apt-get install -y curl ca-certificates
  elif command -v dnf >/dev/null 2>&1; then
    dnf install -y curl ca-certificates
  elif command -v yum >/dev/null 2>&1; then
    yum install -y curl ca-certificates
  else
    echo "curl is required. Install curl and run again." >&2
    exit 1
  fi
}

cleanup_existing_install() {
  systemctl stop vmange-agent.service 2>/dev/null || true
  systemctl disable vmange-agent.service 2>/dev/null || true
  rm -f /etc/systemd/system/vmange-agent.service
  rm -f /usr/local/bin/vmange-agent
  rm -f /etc/vmange-agent.env
  rm -f /etc/vmange/agent.env
  rm -f /etc/sudoers.d/vmange-agent
  rm -f /usr/local/sbin/vmange-root-helper
  rm -f /var/lib/vmange/agent.revoked 2>/dev/null || true
  rm -f /var/lib/vmange/bin/vmange-agent 2>/dev/null || true
  systemctl daemon-reload
  systemctl reset-failed 2>/dev/null || true
}

need_root
install_package_tools
if [ -r /etc/vmange/agent.env ]; then
  . /etc/vmange/agent.env
  VMANGE_API_URL_PRESET="${VMANGE_API_URL_PRESET:-${VMANGE_API_URL:-}}"
  VMANGE_ENROLL_TOKEN="${VMANGE_ENROLL_TOKEN:-${VMANGE_TOKEN:-}}"
  VMANGE_HOSTNAME_PRESET="${VMANGE_HOSTNAME_PRESET:-${VMANGE_HOSTNAME:-}}"
  VMANGE_RUN_USER_PRESET="${VMANGE_RUN_USER_PRESET:-${VMANGE_RUN_USER:-}}"
fi

VMANGE_AGENT_URL="${VMANGE_AGENT_URL_PRESET:-$VMANGE_DEFAULT_AGENT_URL}"

DEFAULT_HOST="$(hostname)"
API_URL="$(value_or_prompt "${VMANGE_API_URL_PRESET:-}" "VMange API URL" "$VMANGE_DEFAULT_API_URL")"
HOST_PROMPT_DEFAULT="${VMANGE_HOSTNAME_PRESET:-$DEFAULT_HOST}"
HOSTNAME_VALUE="$(safe_name="$(value_or_prompt "${VMANGE_HOSTNAME_VALUE:-}" "Host name" "$HOST_PROMPT_DEFAULT")"; safe_hostname "$safe_name")"
TOKEN_VALUE="${VMANGE_ENROLL_TOKEN:-}"
if [ -z "$TOKEN_VALUE" ]; then
  if [ "${VMANGE_NONINTERACTIVE:-0}" = "1" ]; then
    echo "VMANGE_ENROLL_TOKEN is required when running non-interactively." >&2
    exit 1
  fi
  TOKEN_VALUE="$(ask_secret "Paste host enrollment token")"
fi
INTERVAL_VALUE="$(value_or_prompt "${VMANGE_INTERVAL_PRESET:-}" "Polling interval seconds" "15")"
RUN_USER="$(resolve_run_user)"
RUN_GROUP="$(id -gn "$RUN_USER" 2>/dev/null || printf '%s' "$RUN_USER")"
RUN_HOME="$(user_home_dir "$RUN_USER")"
if [ -z "$RUN_HOME" ]; then
  RUN_HOME="/root"
fi

if [ -z "$HOSTNAME_VALUE" ] || [ -z "$TOKEN_VALUE" ]; then
  echo "Host name and token are required." >&2
  exit 1
fi
case "$API_URL" in https://*) ;; *) echo 'HTTPS API URL required' >&2; exit 2 ;; esac
[[ "$API_URL" =~ ^https://[a-zA-Z0-9.:/_-]+$ ]] || { echo 'Invalid API URL' >&2; exit 2; }
[[ "$VMANGE_AGENT_URL" =~ ^https://[a-zA-Z0-9.:/_-]+$ ]] || { echo 'Invalid agent URL' >&2; exit 2; }
[[ "$RUN_USER" =~ ^[a-zA-Z0-9_-]+$ ]] && [[ "$RUN_GROUP" =~ ^[a-zA-Z0-9_-]+$ ]] && [[ "$RUN_HOME" =~ ^/[a-zA-Z0-9/._-]+$ ]] || { echo 'Unsupported service user or HOME path' >&2; exit 2; }
[[ "${VMANGE_HOST_UUID_PRESET:-}" =~ ^[a-zA-Z0-9-]*$ ]] || exit 2
[[ "$TOKEN_VALUE" =~ ^[a-fA-F0-9]{64}$ ]] || { echo 'Valid per-host enrollment token required' >&2; exit 2; }
[[ "$INTERVAL_VALUE" =~ ^[0-9]+$ ]] && [ "$INTERVAL_VALUE" -ge 5 ] && [ "$INTERVAL_VALUE" -le 3600 ] || exit 2
for required in curl sha256sum bash flock runuser; do
  command -v "$required" >/dev/null || { echo "Required host tool is missing: $required" >&2; exit 2; }
done
if ! command -v python3 >/dev/null; then
  if command -v apt-get >/dev/null; then apt-get update && apt-get install -y python3;
  elif command -v dnf >/dev/null; then dnf install -y python3;
  elif command -v yum >/dev/null; then yum install -y python3;
  else echo 'Python 3 is required for the local maintenance service' >&2; exit 2; fi
fi
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
curl --proto '=https' --connect-timeout 15 --max-time 120 -fsS "$VMANGE_AGENT_URL" -o "$stage/agent"
curl --proto '=https' --connect-timeout 15 --max-time 120 -fsS "$VMANGE_MAINTENANCE_URL" -o "$stage/maintenance.py"
printf '%s  %s\n' "$VMANGE_AGENT_SHA256" "$stage/agent" "$VMANGE_MAINTENANCE_SHA256" "$stage/maintenance.py" | sha256sum -c -
bash -n "$stage/agent"
systemctl stop vmange-agent.service 2>/dev/null || true
if [ -e /etc/vmange/agent.env ]; then cp -p /etc/vmange/agent.env /etc/vmange/agent.env.previous; fi

SUPPLEMENTARY_GROUPS=""
getent group docker >/dev/null 2>&1 && SUPPLEMENTARY_GROUPS="${SUPPLEMENTARY_GROUPS:+$SUPPLEMENTARY_GROUPS }docker"
getent group vboxusers >/dev/null 2>&1 && SUPPLEMENTARY_GROUPS="${SUPPLEMENTARY_GROUPS:+$SUPPLEMENTARY_GROUPS }vboxusers"

install -d -m 0750 /etc/vmange
chown root:"$RUN_GROUP" /etc/vmange
install -d -m 0750 /var/lib/vmange/compose
install -d -m 0750 /var/lib/vmange/bin
chown "$RUN_USER:$RUN_GROUP" /var/lib/vmange /var/lib/vmange/bin /var/lib/vmange/compose
if [ -f /var/lib/vmange/bin/vmange-agent ]; then cp -p /var/lib/vmange/bin/vmange-agent /var/lib/vmange/bin/vmange-agent.previous; fi
install -o "$RUN_USER" -g "$RUN_GROUP" -m 0755 "$stage/agent" /var/lib/vmange/bin/vmange-agent
chmod 0755 /var/lib/vmange/bin/vmange-agent
ln -sf /var/lib/vmange/bin/vmange-agent /usr/local/bin/vmange-agent

cat >/etc/vmange/agent.env <<ENV
VMANGE_API_URL="$API_URL"
VMANGE_TOKEN="$TOKEN_VALUE"
VMANGE_HOSTNAME="$HOSTNAME_VALUE"
VMANGE_HOST_UUID="${VMANGE_HOST_UUID_PRESET:-}"
VMANGE_INTERVAL="$INTERVAL_VALUE"
VMANGE_COMPOSE_ROOT="/var/lib/vmange/compose"
VMANGE_AGENT_PATH="/var/lib/vmange/bin/vmange-agent"
VMANGE_AGENT_URL="$VMANGE_AGENT_URL"
VMANGE_RUN_USER="$RUN_USER"
VMANGE_RUN_GROUP="$RUN_GROUP"
VMANGE_DOCKER_USER="$RUN_USER"
HOME="$RUN_HOME"
ENV
chown root:"$RUN_GROUP" /etc/vmange/agent.env
chmod 0640 /etc/vmange/agent.env

cat >/usr/local/sbin/vmange-root-helper <<'ROOTHELPER'
#!/usr/bin/env bash
set -euo pipefail

action="${1:-}"
run_user="${2:-}"
if [ -n "$run_user" ] && ! id -u "$run_user" >/dev/null 2>&1; then
  echo "Unknown run user: $run_user" >&2
  exit 2
fi

install_docker() {
  if command -v apt-get >/dev/null 2>&1; then
    apt-get update
    apt-get install -y docker.io
    apt-get install -y docker-compose-plugin \
      || apt-get install -y docker-compose-v2 \
      || apt-get install -y docker-compose
  elif command -v dnf >/dev/null 2>&1; then
    dnf install -y docker docker-compose-plugin
  elif command -v yum >/dev/null 2>&1; then
    yum install -y docker docker-compose-plugin
  else
    echo "No supported package manager was found." >&2
    exit 2
  fi
  systemctl enable --now docker
  if [ -n "$run_user" ] && [ "$run_user" != "root" ] && getent group docker >/dev/null 2>&1; then
    usermod -aG docker "$run_user"
  fi
}

install_virtualbox() {
  if command -v apt-get >/dev/null 2>&1; then
    apt-get update
    apt-get install -y virtualbox
  elif command -v dnf >/dev/null 2>&1; then
    dnf install -y VirtualBox
  elif command -v yum >/dev/null 2>&1; then
    yum install -y VirtualBox
  else
    echo "No supported package manager was found." >&2
    exit 2
  fi
  if [ -n "$run_user" ] && [ "$run_user" != "root" ] && getent group vboxusers >/dev/null 2>&1; then
    usermod -aG vboxusers "$run_user"
  fi
  repair_virtualbox_access
}

repair_virtualbox_access() {
  getent group vboxusers >/dev/null 2>&1 || groupadd --system vboxusers
  install -d -m 0755 /etc/udev/rules.d
  printf '%s\n' \
    'KERNEL=="vboxdrv", OWNER="root", GROUP="vboxusers", MODE="0660"' \
    'KERNEL=="vboxdrvu", OWNER="root", GROUP="vboxusers", MODE="0660"' \
    'KERNEL=="vboxnetctl", OWNER="root", GROUP="vboxusers", MODE="0660"' \
    > /etc/udev/rules.d/60-vmange-vboxdrv.rules
  if [ -n "$run_user" ] && [ "$run_user" != "root" ]; then
    usermod -aG vboxusers "$run_user"
  fi
  command -v udevadm >/dev/null 2>&1 && udevadm control --reload-rules
  for device in /dev/vboxdrv /dev/vboxdrvu /dev/vboxnetctl; do
    [ -e "$device" ] || continue
    chgrp vboxusers "$device"
    chmod 0660 "$device"
  done
  echo "VirtualBox device access repaired for ${run_user:-root}."
}

case "$action" in
  probe) exit 0 ;;
  install-docker) install_docker ;;
  install-virtualbox) install_virtualbox ;;
  repair-virtualbox-access) repair_virtualbox_access ;;
  restart-agent) nohup sh -c 'sleep 3; systemctl restart vmange-agent.service' >/dev/null 2>&1 & ;;
  reboot-host) nohup sh -c 'sleep 3; systemctl reboot' >/dev/null 2>&1 & ;;
  uninstall-agent)
    nohup sh -c 'sleep 3; systemctl disable --now vmange-agent.service 2>/dev/null || true; rm -f /etc/systemd/system/vmange-agent.service /etc/vmange/agent.env /usr/local/bin/vmange-agent /var/lib/vmange/bin/vmange-agent /etc/sudoers.d/vmange-agent /usr/local/sbin/vmange-root-helper; systemctl daemon-reload' >/dev/null 2>&1 &
    ;;
  *)
    echo "Root helper action is not allowed: $action" >&2
    exit 2
    ;;
esac
ROOTHELPER
chown root:root /usr/local/sbin/vmange-root-helper
chmod 0755 /usr/local/sbin/vmange-root-helper

rm -f /etc/sudoers.d/vmange-agent
install -o root -g root -m 0755 "$stage/maintenance.py" /usr/local/sbin/vmange-maintenance.py
cat >/etc/systemd/system/vmange-maintenance.socket <<SOCKET
[Unit]
Description=VMange local maintenance socket
[Socket]
ListenStream=/run/vmange-maintenance.sock
SocketUser=$RUN_USER
SocketGroup=$RUN_GROUP
SocketMode=0600
Accept=yes
MaxConnections=1
[Install]
WantedBy=sockets.target
SOCKET
cat >/etc/systemd/system/vmange-maintenance@.service <<SERVICE
[Unit]
Description=VMange allowlisted host maintenance
[Service]
ExecStart=/usr/bin/python3 /usr/local/sbin/vmange-maintenance.py $RUN_USER
StandardInput=socket
StandardOutput=journal
StandardError=journal
User=root
TimeoutStartSec=900
SERVICE
if command -v VBoxManage >/dev/null 2>&1 || command -v vboxmanage >/dev/null 2>&1; then
  /usr/local/sbin/vmange-root-helper repair-virtualbox-access "$RUN_USER"
fi

cat >/etc/systemd/system/vmange-agent.service <<SERVICE
[Unit]
Description=VMange host agent
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
EnvironmentFile=/etc/vmange/agent.env
User=$RUN_USER
Group=$RUN_GROUP
WorkingDirectory=$RUN_HOME
Environment=HOME=$RUN_HOME
ExecStart=/var/lib/vmange/bin/vmange-agent loop
Restart=on-failure
RestartSec=10
NoNewPrivileges=true
$( [ -n "$SUPPLEMENTARY_GROUPS" ] && printf 'SupplementaryGroups=%s\n' "$SUPPLEMENTARY_GROUPS" )

[Install]
WantedBy=multi-user.target
SERVICE

systemctl daemon-reload
systemctl enable --now vmange-maintenance.socket
systemctl enable vmange-agent.service

set +e
# Read the newly written config, never credentials inherited from the installer.
HEARTBEAT_OUTPUT="$(runuser -u "$RUN_USER" -- env -i PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin HOME="$RUN_HOME" /usr/local/bin/vmange-agent once 2>&1)"
HEARTBEAT_STATUS=$?
set -e
systemctl start vmange-agent.service

echo "VMange agent installed."
echo "Saved VMange data was preserved. Previous configuration is retained as agent.env.previous."
echo "Installer version: $VMANGE_INSTALLER_VERSION"
echo "API URL: $API_URL"
echo "Config file: /etc/vmange/agent.env"
echo "Run user: $RUN_USER"
if [ -n "$SUPPLEMENTARY_GROUPS" ]; then
  echo "Supplementary groups: $SUPPLEMENTARY_GROUPS"
fi
echo "Service: systemctl status vmange-agent.service"
echo "Service tmp isolation: disabled for VirtualBox IPC compatibility"
if [ "$HEARTBEAT_STATUS" -eq 0 ]; then
  echo "Connection check: success"
else
  echo "Connection check: failed"
  echo "$HEARTBEAT_OUTPUT"
  exit "$HEARTBEAT_STATUS"
fi
echo "If VirtualBox is installed, VMs will appear after the first heartbeat."
echo "If Docker is installed and this user can access it, containers will appear too."
BASH;
