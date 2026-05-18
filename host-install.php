<?php
declare(strict_types=1);

function installer_base_url(string $path = ''): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
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

header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');

echo <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

BASH;

echo 'VMANGE_DEFAULT_API_URL=' . escapeshellarg($apiUrl) . PHP_EOL;
echo 'VMANGE_AGENT_URL=' . escapeshellarg($agentUrl) . PHP_EOL;

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
  rm -f /var/lib/vmange/agent.revoked 2>/dev/null || true
  rm -f /var/lib/vmange/bin/vmange-agent 2>/dev/null || true
  systemctl daemon-reload
  systemctl reset-failed 2>/dev/null || true
}

need_root
install_package_tools
cleanup_existing_install

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

SUPPLEMENTARY_GROUPS=""
getent group docker >/dev/null 2>&1 && SUPPLEMENTARY_GROUPS="${SUPPLEMENTARY_GROUPS:+$SUPPLEMENTARY_GROUPS }docker"
getent group vboxusers >/dev/null 2>&1 && SUPPLEMENTARY_GROUPS="${SUPPLEMENTARY_GROUPS:+$SUPPLEMENTARY_GROUPS }vboxusers"

install -d -m 0750 /etc/vmange
install -d -m 0750 /var/lib/vmange/compose
install -d -m 0750 /var/lib/vmange/bin
chown -R "$RUN_USER:$RUN_GROUP" /var/lib/vmange
curl -fsSL "$VMANGE_AGENT_URL" -o /var/lib/vmange/bin/vmange-agent
chmod 0755 /var/lib/vmange/bin/vmange-agent
ln -sf /var/lib/vmange/bin/vmange-agent /usr/local/bin/vmange-agent

cat >/etc/vmange/agent.env <<ENV
VMANGE_API_URL="$API_URL"
VMANGE_TOKEN="$TOKEN_VALUE"
VMANGE_HOSTNAME="$HOSTNAME_VALUE"
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
systemctl enable --now vmange-agent.service

set +e
HEARTBEAT_OUTPUT="$(set -a; . /etc/vmange/agent.env; set +a; /usr/local/bin/vmange-agent once 2>&1)"
HEARTBEAT_STATUS=$?
set -e

echo "VMange agent installed."
echo "Previous VMange agent installation was cleared before reinstall."
echo "Installer version: v1.6.0"
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
