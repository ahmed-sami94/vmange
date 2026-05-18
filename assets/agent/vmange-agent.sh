#!/usr/bin/env bash
set -euo pipefail

AGENT_VERSION="v1.7.0"

if [ -z "${VMANGE_API_URL:-}" ] && [ -r /etc/vmange/agent.env ]; then
  set -a
  . /etc/vmange/agent.env
  set +a
fi

API_URL="${VMANGE_API_URL:-}"
TOKEN="${VMANGE_TOKEN:-change-me-agent-token}"
HOSTNAME_VALUE="${VMANGE_HOSTNAME:-$(hostname)}"
INTERVAL="${VMANGE_INTERVAL:-15}"
COMPOSE_ROOT="${VMANGE_COMPOSE_ROOT:-/var/lib/vmange/compose}"
RUN_USER="${VMANGE_RUN_USER:-}"
AGENT_URL="${VMANGE_AGENT_URL:-}"
AGENT_PATH="${VMANGE_AGENT_PATH:-/var/lib/vmange/bin/vmange-agent}"
DOCKER_USER="${VMANGE_DOCKER_USER:-${RUN_USER:-}}"
export HOME="${HOME:-/root}"

VBOXMANAGE_BIN="${VMANGE_VBOXMANAGE_BIN:-}"
if [ -z "$VBOXMANAGE_BIN" ]; then
  if command -v VBoxManage >/dev/null 2>&1; then
    VBOXMANAGE_BIN="VBoxManage"
  elif command -v vboxmanage >/dev/null 2>&1; then
    VBOXMANAGE_BIN="vboxmanage"
  fi
fi

b64() {
  base64 -w0 2>/dev/null || base64 | tr -d '\n'
}

safe_name() {
  printf '%s' "$1" | tr -cd 'A-Za-z0-9._-'
}

json_escape() {
  awk 'BEGIN{ORS=""}
  {
    gsub(/\\/,"\\\\")
    gsub(/"/,"\\\"")
    gsub(/\t/,"\\t")
    gsub(/\r/,"\\r")
    gsub(/\n/,"\\n")
    printf "%s", $0
  }'
}

json_payload_str() {
  local key="$1"
  printf '%s' "$2" | sed -n "s/.*\"$key\"[[:space:]]*:[[:space:]]*\"\\([^\"]*\\)\".*/\\1/p" | head -n 1
}

json_payload_num() {
  local key="$1"
  printf '%s' "$2" | sed -n "s/.*\"$key\"[[:space:]]*:[[:space:]]*\\([0-9][0-9]*\\).*/\\1/p" | head -n 1
}

json_payload_bool() {
  local key="$1"
  printf '%s' "$2" | sed -n "s/.*\"$key\"[[:space:]]*:[[:space:]]*\\(true\\|false\\).*/\\1/p" | head -n 1
}

json_num() {
  local key="$1"
  printf '%s' "$2" | sed -n "s/.*\"$key\":\\([^,}]*\\).*/\\1/p" | head -n 1
}

json_str() {
  local key="$1"
  printf '%s' "$2" | sed -n "s/.*\"$key\":\"\\([^\"]*\\)\".*/\\1/p" | head -n 1
}

run_as_vm_user() {
  local target_user="${RUN_USER:-}"
  if [ -z "$target_user" ] || [ "$target_user" = "root" ] || [ "$(id -un)" = "$target_user" ]; then
    "$@"
    return
  fi

  local target_home
  target_home="$(getent passwd "$target_user" | cut -d: -f6)"
  if command -v runuser >/dev/null 2>&1 && [ "$(id -u)" -eq 0 ]; then
    runuser -u "$target_user" -- env HOME="${target_home:-/home/$target_user}" "$@"
    return
  fi
  if command -v sudo >/dev/null 2>&1; then
    sudo -u "$target_user" env HOME="${target_home:-/home/$target_user}" "$@"
    return
  fi
  "$@"
}

collect_vms() {
  if [ -n "$VBOXMANAGE_BIN" ]; then
    run_as_vm_user "$VBOXMANAGE_BIN" list vms || true
  fi
}

collect_running_vms() {
  if [ -n "$VBOXMANAGE_BIN" ]; then
    run_as_vm_user "$VBOXMANAGE_BIN" list runningvms || true
  fi
}

lookup_vm_name_by_uuid() {
  local all_vms="$1"
  local target_uuid="$2"
  while IFS= read -r line; do
    local vmname uuid
    vmname=$(printf '%s\n' "$line" | sed -n 's/^"\(.*\)" {.*/\1/p')
    uuid=$(printf '%s\n' "$line" | sed -n 's/^".*" {\([^}]*\)}.*/\1/p')
    if [ -n "$vmname" ] && [ -n "$uuid" ] && [ "$uuid" = "$target_uuid" ]; then
      printf '%s' "$vmname"
      return 0
    fi
  done <<< "$all_vms"
  return 1
}

command_target_name() {
  local target="$1"
  local payload="${2:-}"
  local vm_uuid vm_name resolved
  vm_uuid=$(json_payload_str vm_uuid "$payload")
  vm_name=$(json_payload_str vm_name "$payload")
  if [ -n "$vm_uuid" ]; then
    resolved=$(lookup_vm_name_by_uuid "$(collect_vms)" "$vm_uuid" || true)
    if [ -n "$resolved" ]; then
      printf '%s' "$resolved"
      return 0
    fi
  fi
  if [ -n "$vm_name" ]; then
    printf '%s' "$vm_name"
    return 0
  fi
  printf '%s' "$target"
}

vm_log_folder() {
  local vmname="$1"
  run_as_vm_user "$VBOXMANAGE_BIN" showvminfo "$vmname" --machinereadable 2>/dev/null \
    | awk -F= '/^LogFldr=/{gsub(/^"/,"",$2); gsub(/"$/,"",$2); print $2; exit}'
}

safe_log_file() {
  local filename="$1"
  filename=$(basename "$filename")
  case "$filename" in
    VBox.log|VBox.log.[0-9]|*.log) printf '%s' "$filename" ;;
    *) printf 'VBox.log' ;;
  esac
}

augment_running_vms_with_processes() {
  local all_vms="$1"
  local running_vms="${2:-}"
  local lines existing
  existing=$(printf '%s' "$running_vms")
  lines=$(printf '%s' "$running_vms")

  while IFS= read -r proc; do
    local uuid name
    uuid=$(printf '%s\n' "$proc" | sed -n 's/.*--startvm[=[:space:]]*"\{0,1\}\([0-9A-Fa-f-]\{36\}\)".*/\1/p' | head -n 1)
    name=$(printf '%s\n' "$proc" | sed -n 's/.*--comment[=[:space:]]*"\{0,1\}\([^"]*\)".*/\1/p' | head -n 1)
    if [ -z "$name" ] && [ -n "$uuid" ]; then
      name=$(lookup_vm_name_by_uuid "$all_vms" "$uuid" || true)
    fi
    [ -n "$name" ] || continue
    if ! printf '%s\n' "$existing" | grep -F "\"$name\" {" >/dev/null 2>&1; then
      lines="${lines}${lines:+$'\n'}\"$name\" {${uuid:-process-detected}}"
      existing=$(printf '%s\n' "$existing${existing:+$'\n'}\"$name\" {${uuid:-process-detected}}")
    fi
  done < <(ps -eo args= 2>/dev/null | grep -E 'VBoxHeadless|VirtualBoxVM|VBoxSDL' | grep -v grep || true)

  printf '%s' "$lines"
}

synthesize_running_vms() {
  local all_vms="$1"
  local lines=""
  while IFS= read -r line; do
    local vmname uuid info state
    vmname=$(printf '%s\n' "$line" | sed -n 's/^"\(.*\)" {.*/\1/p')
    uuid=$(printf '%s\n' "$line" | sed -n 's/^".*" {\([^}]*\)}.*/\1/p')
    [ -n "$vmname" ] || continue
    info=$(run_as_vm_user "$VBOXMANAGE_BIN" showvminfo "$vmname" --machinereadable 2>/dev/null || true)
    state=$(printf '%s\n' "$info" | awk -F= '/^VMState=/{gsub(/"/,"",$2);print tolower($2);exit}')
    case "$state" in
      running|paused|stopping|saving|restoring|teleporting|teleported|live-snapshotting|live_snapshotting)
        lines="${lines}\"${vmname}\" {${uuid:-synthetic}}"$'\n'
        ;;
    esac
  done <<< "$all_vms"
  printf '%s' "$lines"
}

running_vm_names_json() {
  local running_vms="$1"
  printf '%s\n' "$running_vms" | awk -F'"' 'BEGIN{printf "["} NF >= 2 {name=$2; if(n++) printf ","; gsub(/\\/,"\\\\",name); gsub(/"/,"\\\"",name); printf "\"%s\"", name} END{printf "]"}'
}

running_vm_uuids_json() {
  local running_vms="$1"
  printf '%s\n' "$running_vms" | sed -n 's/^".*" {\([^}]*\)}.*/\1/p' | awk 'BEGIN{printf "["} NF {if(n++) printf ","; gsub(/\\/,"\\\\",$0); gsub(/"/,"\\\"",$0); printf "\"%s\"", $0} END{printf "]"}'
}

collect_snapshots_json() {
  local vmname="$1"
  [ -n "$VBOXMANAGE_BIN" ] || { printf '[]'; return; }
  run_as_vm_user "$VBOXMANAGE_BIN" snapshot "$vmname" list --machinereadable 2>/dev/null \
    | awk -F= '
      /^SnapshotName-/ {
        name=$2; gsub(/^"/,"",name); gsub(/"$/,"",name); names[++n]=name
      }
      /^SnapshotUUID-/ {
        uuid=$2; gsub(/^"/,"",uuid); gsub(/"$/,"",uuid); uuids[n]=uuid
      }
      END {
        printf "["
        for (i=1;i<=n;i++) {
          if (i>1) printf ","
          gsub(/\\/,"\\\\",names[i]); gsub(/"/,"\\\"",names[i])
          gsub(/\\/,"\\\\",uuids[i]); gsub(/"/,"\\\"",uuids[i])
          printf "{\"name\":\"%s\",\"uuid\":\"%s\"}", names[i], uuids[i]
        }
        printf "]"
      }'
}

collect_storage_json() {
  local info="$1"
  printf '%s\n' "$info" | awk -F= '
    /^storagecontrollername[0-9]+=/ {
      key=$1; idx=key; sub(/^storagecontrollername/,"",idx)
      val=$2; gsub(/^"/,"",val); gsub(/"$/,"",val); ctl[idx]=val
    }
    /^storagecontrollertype[0-9]+=/ {
      key=$1; idx=key; sub(/^storagecontrollertype/,"",idx)
      val=$2; gsub(/^"/,"",val); gsub(/"$/,"",val); type[idx]=val
    }
    /^[^=]+-[0-9]+-[0-9]+=/ && $1 !~ /ImageUUID/ {
      key=$1
      val=$2; gsub(/^"/,"",val); gsub(/"$/,"",val)
      if (val != "" && val != "none" && val != "emptydrive") media[key]=val
    }
    END {
      printf "["
      first=1
      for (i in ctl) {
        if (!first) printf ","
        first=0
        gsub(/\\/,"\\\\",ctl[i]); gsub(/"/,"\\\"",ctl[i])
        gsub(/\\/,"\\\\",type[i]); gsub(/"/,"\\\"",type[i])
        printf "{\"name\":\"%s\",\"type\":\"%s\"}", ctl[i], type[i]
      }
      for (i in media) {
        if (!first) printf ","
        first=0
        split(i, parts, "-")
        controller=parts[1]; port=parts[2]; device=parts[3]
        gsub(/\\/,"\\\\",controller); gsub(/"/,"\\\"",controller)
        gsub(/\\/,"\\\\",media[i]); gsub(/"/,"\\\"",media[i])
        printf "{\"name\":\"%s\",\"type\":\"medium\",\"port\":%d,\"device\":%d,\"path\":\"%s\"}", controller, port+0, device+0, media[i]
      }
      printf "]"
    }'
}

collect_nics_json() {
  local info="$1"
  printf '%s\n' "$info" | awk -F= '
    /^nic[0-9]+=/ {
      key=$1; idx=key; sub(/^nic/,"",idx)
      val=$2; gsub(/^"/,"",val); gsub(/"$/,"",val); mode[idx]=val
    }
    /^cableconnected[0-9]+=/ {
      key=$1; idx=key; sub(/^cableconnected/,"",idx)
      val=$2; gsub(/^"/,"",val); gsub(/"$/,"",val); cable[idx]=val
    }
    /^bridgeadapter[0-9]+=/ {
      key=$1; idx=key; sub(/^bridgeadapter/,"",idx)
      val=$2; gsub(/^"/,"",val); gsub(/"$/,"",val); bridge[idx]=val
    }
    END {
      printf "["
      for (i=1;i<=8;i++) {
        if (mode[i] == "") continue
        if (n++) printf ","
        gsub(/\\/,"\\\\",mode[i]); gsub(/"/,"\\\"",mode[i])
        gsub(/\\/,"\\\\",cable[i]); gsub(/"/,"\\\"",cable[i])
        gsub(/\\/,"\\\\",bridge[i]); gsub(/"/,"\\\"",bridge[i])
        printf "{\"adapter\":%d,\"mode\":\"%s\",\"cable\":\"%s\",\"bridge\":\"%s\"}", i, mode[i], cable[i], bridge[i]
      }
      printf "]"
    }'
}

collect_vm_inventory() {
  local all_vms="$1"
  local running_vms="${2:-}"
  local first=1
  printf '['
  while IFS= read -r line; do
    local vmname uuid info state session_state state_change memory cpu os vram description autostart running snapshots storage nics collected vrde_enabled vrde_port log_folder
    vmname=$(printf '%s\n' "$line" | sed -n 's/^"\(.*\)" {.*/\1/p')
    uuid=$(printf '%s\n' "$line" | sed -n 's/^".*" {\([^}]*\)}.*/\1/p')
    [ -n "$vmname" ] || continue
    info=$(run_as_vm_user "$VBOXMANAGE_BIN" showvminfo "$vmname" --machinereadable 2>/dev/null || true)
    state=$(printf '%s\n' "$info" | awk -F= '/^VMState=/{gsub(/"/,"",$2);print $2;exit}')
    session_state=$(printf '%s\n' "$info" | awk -F= '/^SessionState=|^sessionState=/{gsub(/"/,"",$2);print $2;exit}')
    state_change=$(printf '%s\n' "$info" | awk -F= '/^VMStateChangeTime=|^lastStateChange=/{gsub(/"/,"",$2);print $2;exit}')
    running=false
    if printf '%s\n' "$running_vms" | grep -F "\"$vmname\" {" >/dev/null 2>&1 || { [ -n "$uuid" ] && printf '%s\n' "$running_vms" | grep -F "{$uuid}" >/dev/null 2>&1; }; then
      running=true
    fi
    memory=$(printf '%s\n' "$info" | awk -F= '/^memory=/{print $2+0;exit}')
    cpu=$(printf '%s\n' "$info" | awk -F= '/^cpus=/{print $2+0;exit}')
    os=$(printf '%s\n' "$info" | awk -F= '/^ostype=/{gsub(/"/,"",$2);print $2;exit}')
    vram=$(printf '%s\n' "$info" | awk -F= '/^vram=/{print $2+0;exit}')
    description=$(printf '%s\n' "$info" | awk -F= '/^description=/{sub(/^[^=]*=/,""); gsub(/^"/,""); gsub(/"$/,""); print; exit}' | json_escape)
    autostart=$(printf '%s\n' "$info" | awk -F= '/^autostart-enabled=/{gsub(/"/,"",$2);print $2;exit}')
    vrde_enabled=$(printf '%s\n' "$info" | awk -F= '/^vrde=|^VRDE=/{gsub(/"/,"",$2);print tolower($2);exit}')
    vrde_port=$(printf '%s\n' "$info" | awk -F= '/^vrdeport=|^VRDEPort=/{gsub(/"/,"",$2);print $2;exit}')
    log_folder=$(printf '%s\n' "$info" | awk -F= '/^LogFldr=/{gsub(/^"/,"",$2); gsub(/"$/,"",$2); print $2; exit}' | json_escape)
    snapshots=$(collect_snapshots_json "$vmname")
    storage=$(collect_storage_json "$info")
    nics=$(collect_nics_json "$info")
    collected=$(date -u +%Y-%m-%dT%H:%M:%SZ)
    [ "$first" -eq 1 ] || printf ','
    first=0
    printf '{"name":"%s","uuid":"%s","state":"%s","status":"%s","session_state":"%s","last_state_change":"%s","running":%s,"os":"%s","cpu":%d,"ram_mb":%d,"vram_mb":%d,"description":"%s","autostart":"%s","vrde_enabled":"%s","vrde_port":"%s","log_folder":"%s","boot_order":["%s","%s","%s","%s"],"storage":%s,"nics":%s,"snapshots":%s,"collected_at":"%s"}' \
      "$(printf '%s' "$vmname" | json_escape)" "$(printf '%s' "$uuid" | json_escape)" "$(printf '%s' "${state:-unknown}" | json_escape)" "$(printf '%s' "${state:-unknown}" | json_escape)" "$(printf '%s' "${session_state:-}" | json_escape)" "$(printf '%s' "${state_change:-}" | json_escape)" "$running" "$(printf '%s' "${os:-unknown}" | json_escape)" "${cpu:-0}" "${memory:-0}" "${vram:-0}" "$description" "$(printf '%s' "${autostart:-}" | json_escape)" "$(printf '%s' "${vrde_enabled:-off}" | json_escape)" "$(printf '%s' "${vrde_port:-}" | json_escape)" "$log_folder" \
      "$(printf '%s\n' "$info" | awk -F= '/^boot1=/{gsub(/"/,"",$2);print $2;exit}' | json_escape)" \
      "$(printf '%s\n' "$info" | awk -F= '/^boot2=/{gsub(/"/,"",$2);print $2;exit}' | json_escape)" \
      "$(printf '%s\n' "$info" | awk -F= '/^boot3=/{gsub(/"/,"",$2);print $2;exit}' | json_escape)" \
      "$(printf '%s\n' "$info" | awk -F= '/^boot4=/{gsub(/"/,"",$2);print $2;exit}' | json_escape)" \
      "$storage" "$nics" "$snapshots" "$collected"
  done <<< "$all_vms"
  printf ']'
}

collect_vm_specs() {
  local all_vms="$1"
  local running_vms="${2:-}"
  local specs=""
  while IFS= read -r line; do
    local vmname
    vmname=$(printf '%s\n' "$line" | sed -n 's/^"\(.*\)" {.*/\1/p')
    if [ -n "$vmname" ]; then
      local info state memory cpu os vram
      info=$(run_as_vm_user "$VBOXMANAGE_BIN" showvminfo "$vmname" --machinereadable 2>/dev/null || true)
      state=$(printf '%s\n' "$info" | awk -F= '/^VMState=/{gsub(/"/,"",$2);print $2;exit}')
      if [ -z "$state" ] && printf '%s\n' "$running_vms" | grep -F "\"$vmname\" {" >/dev/null 2>&1; then
        state="running"
      fi
      memory=$(printf '%s\n' "$info" | awk -F= '/^memory=/{print $2;exit}')
      cpu=$(printf '%s\n' "$info" | awk -F= '/^cpus=/{print $2;exit}')
      os=$(printf '%s\n' "$info" | awk -F= '/^ostype=/{gsub(/"/,"",$2);print $2;exit}')
      vram=$(printf '%s\n' "$info" | awk -F= '/^vram=/{print $2;exit}')
      specs="${specs}${vmname}|${state:-unknown}|${memory:-0}|${cpu:-0}|${os:-unknown}|${vram:-0}"$'\n'
    fi
  done <<< "$all_vms"
  printf '%s' "$specs"
}

collect_metrics() {
  local running_vms_text="${1:-}"
  local load1 ram_total ram_available swap_total swap_free rx tx disk_total disk_used cpu_percent kernel uptime_seconds ips_json interfaces_json preferred_wol_mac running_names_json running_uuids_json
  load1=$(awk '{print $1+0}' /proc/loadavg 2>/dev/null || echo 0)
  ram_total=$(awk '$1=="MemTotal:"{print int($2/1024); found=1} END{if(!found) print 0}' /proc/meminfo 2>/dev/null || echo 0)
  ram_available=$(awk '$1=="MemAvailable:"{print int($2/1024); found=1} END{if(!found) print 0}' /proc/meminfo 2>/dev/null || echo 0)
  swap_total=$(awk '$1=="SwapTotal:"{print int($2/1024); found=1} END{if(!found) print 0}' /proc/meminfo 2>/dev/null || echo 0)
  swap_free=$(awk '$1=="SwapFree:"{print int($2/1024); found=1} END{if(!found) print 0}' /proc/meminfo 2>/dev/null || echo 0)
  read -r rx tx < <(awk -F'[: ]+' '$1 !~ /lo/ && NF > 10 {rx+=$3; tx+=$11} END{print rx+0, tx+0}' /proc/net/dev 2>/dev/null || echo "0 0")
  disk_total=$(df -Pm / 2>/dev/null | awk 'NR==2{print $2+0; found=1} END{if(!found) print 0}')
  disk_used=$(df -Pm / 2>/dev/null | awk 'NR==2{print $3+0; found=1} END{if(!found) print 0}')
  cpu_percent=$(awk '
    /^cpu / {
      idle=$5+$6
      total=0
      for (i=2; i<=NF; i++) total+=$i
      if (total > 0) print int(((total-idle)/total)*100)
      else print 0
      exit
    }
  ' /proc/stat 2>/dev/null || echo 0)
  kernel=$(uname -sr 2>/dev/null | sed 's/"/\\"/g' || echo unknown)
  uptime_seconds=$(awk '{print int($1)}' /proc/uptime 2>/dev/null || echo 0)
  if command -v ip >/dev/null 2>&1; then
    ips_json=$(ip -o -4 addr show scope global 2>/dev/null | awk 'BEGIN{printf "["} {split($4,a,"/"); if(n++) printf ","; printf "\"%s:%s\"", $2, a[1]} END{printf "]"}')
  else
    ips_json=$(hostname -I 2>/dev/null | awk 'BEGIN{printf "["} {for(i=1;i<=NF;i++){if(n++) printf ","; printf "\"%s\"", $i}} END{printf "]"}')
  fi
  [ -n "$ips_json" ] || ips_json="[]"
  interfaces_json='[]'
  preferred_wol_mac=''
  if [ -d /sys/class/net ]; then
    local interface_rows="" fallback_mac=""
    for iface_path in /sys/class/net/*; do
      local iface mac physical
      iface=$(basename "$iface_path")
      [ "$iface" = "lo" ] && continue
      mac=$(cat "$iface_path/address" 2>/dev/null || true)
      [ -n "$mac" ] || continue
      physical=false
      [ -e "$iface_path/device" ] && physical=true
      [ -z "$fallback_mac" ] && fallback_mac="$mac"
      if [ "$physical" = true ] && [ -z "$preferred_wol_mac" ]; then
        preferred_wol_mac="$mac"
      fi
      interface_rows="${interface_rows}${interface_rows:+,}{\"name\":\"$(printf '%s' "$iface" | json_escape)\",\"mac\":\"$(printf '%s' "$mac" | json_escape)\",\"physical\":$physical}"
    done
    [ -z "$preferred_wol_mac" ] && preferred_wol_mac="$fallback_mac"
    interfaces_json="[${interface_rows}]"
  fi
  running_names_json=$(running_vm_names_json "$running_vms_text")
  running_uuids_json=$(running_vm_uuids_json "$running_vms_text")
  printf '{"agent_version":"%s","vboxmanage_bin":"%s","cpu":%d,"load1":%.2f,"ram_used_mb":%d,"ram_total_mb":%d,"swap_used_mb":%d,"swap_total_mb":%d,"disk_used_mb":%d,"disk_total_mb":%d,"rx_bytes":%d,"tx_bytes":%d,"ips":%s,"interfaces":%s,"preferred_wol_mac":"%s","kernel":"%s","uptime_seconds":%d,"running_vm_names":%s,"running_vm_uuids":%s}\n' \
    "$AGENT_VERSION" "$(printf '%s' "${VBOXMANAGE_BIN:-missing}" | json_escape)" "${cpu_percent:-0}" "$load1" "$((ram_total - ram_available))" "$ram_total" "$((swap_total - swap_free))" "$swap_total" "${disk_used:-0}" "${disk_total:-0}" "${rx:-0}" "${tx:-0}" "$ips_json" "$interfaces_json" "$(printf '%s' "$preferred_wol_mac" | json_escape)" "$kernel" "${uptime_seconds:-0}" "$running_names_json" "$running_uuids_json"
}

collect_capabilities() {
  local has_virtualbox=false has_docker=false has_compose=false docker_bin="" compose_bin="" vbox_bin=""
  [ -n "${VBOXMANAGE_BIN:-}" ] && has_virtualbox=true && vbox_bin="$VBOXMANAGE_BIN"
  if command -v docker >/dev/null 2>&1; then
    docker_bin="$(command -v docker 2>/dev/null || printf docker)"
    if run_docker_cmd info >/dev/null 2>&1; then
      has_docker=true
      if run_docker_cmd compose version >/dev/null 2>&1; then
        has_compose=true
        compose_bin="docker compose"
      fi
    fi
  fi
  printf '{"has_virtualbox":%s,"has_docker":%s,"has_compose":%s,"vboxmanage_bin":"%s","docker_bin":"%s","compose_bin":"%s","run_user":"%s","home":"%s","uptime_seconds":%d}\n' \
    "$has_virtualbox" "$has_docker" "$has_compose" \
    "$(printf '%s' "$vbox_bin" | json_escape)" "$(printf '%s' "$docker_bin" | json_escape)" "$(printf '%s' "$compose_bin" | json_escape)" \
    "$(printf '%s' "${RUN_USER:-$(id -un)}" | json_escape)" "$(printf '%s' "${HOME:-}" | json_escape)" \
    "$(awk '{print int($1)}' /proc/uptime 2>/dev/null || echo 0)"
}

collect_errors() {
  local errors=""
  if [ -z "${VBOXMANAGE_BIN:-}" ]; then
    errors="${errors}{\"collector\":\"virtualbox\",\"message\":\"VBoxManage was not found\"}"
  fi
  if command -v docker >/dev/null 2>&1 && ! run_docker_cmd info >/dev/null 2>&1; then
    errors="${errors}${errors:+,}{\"collector\":\"docker\",\"message\":\"Docker exists but the agent user cannot access the daemon\"}"
  fi
  printf '[%s]' "$errors"
}

run_docker_cmd() {
  if ! command -v docker >/dev/null 2>&1; then
    return 127
  fi
  if docker info >/dev/null 2>&1; then
    docker "$@"
    return
  fi
  if [ -n "$DOCKER_USER" ] && [ "$(id -u)" -eq 0 ] && [ "$DOCKER_USER" != "root" ] && id -u "$DOCKER_USER" >/dev/null 2>&1; then
    if run_as_vm_user true >/dev/null 2>&1; then
      RUN_USER="$DOCKER_USER" run_as_vm_user docker "$@" && return
    fi
  fi
  if command -v sudo >/dev/null 2>&1 && sudo -n docker info >/dev/null 2>&1; then
    sudo -n docker "$@"
    return
  fi
  return 126
}

collect_containers() {
  local rows
  rows=$(run_docker_cmd ps -a --format '{{json .}}' 2>/dev/null || true)
  if [ -n "$(printf '%s' "$rows" | tr -d '[:space:]')" ]; then
    printf '%s\n' "$rows" | awk 'BEGIN{printf "["} NR>1{printf ","} {printf "%s",$0} END{printf "]"}'
    return
  fi
  printf '[]'
}

collect_compose() {
  if run_docker_cmd compose version >/dev/null 2>&1; then
    run_docker_cmd compose ls --format json 2>/dev/null || printf '[]'
    return
  fi
  printf '[]'
}

collect_images() {
  local rows
  rows=$(run_docker_cmd images --format '{{json .}}' 2>/dev/null || true)
  if [ -n "$(printf '%s' "$rows" | tr -d '[:space:]')" ]; then
    printf '%s\n' "$rows" | awk 'BEGIN{printf "["} NR>1{printf ","} {printf "%s",$0} END{printf "]"}'
    return
  fi
  printf '[]'
}

post_heartbeat() {
  local command_id="${1:-}"
  local command_status="${2:-}"
  local command_output="${3:-}"
  local command_exit_code="${4:-}"
  local command_stdout="${5:-}"
  local command_stderr="${6:-}"
  local command_diagnostics="${7:-}"
  local all_vms running_vms specs inventory metrics containers compose images capabilities collector_errors
  all_vms=$(collect_vms)
  running_vms=$(collect_running_vms)
  running_vms=$(augment_running_vms_with_processes "$all_vms" "$running_vms")
  if [ -z "$(printf '%s' "$running_vms" | tr -d '[:space:]')" ] && [ -n "$(printf '%s' "$all_vms" | tr -d '[:space:]')" ]; then
    running_vms=$(synthesize_running_vms "$all_vms")
  fi
  specs=$(collect_vm_specs "$all_vms" "$running_vms")
  inventory=$(collect_vm_inventory "$all_vms" "$running_vms")
  metrics=$(collect_metrics "$running_vms")
  containers=$(collect_containers)
  compose=$(collect_compose)
  images=$(collect_images)
  capabilities=$(collect_capabilities)
  collector_errors=$(collect_errors)
  local metric_cpu metric_load metric_ram_used metric_ram_total metric_swap_used metric_swap_total metric_disk_used metric_disk_total metric_rx metric_tx metric_kernel metric_uptime
  metric_cpu=$(json_num cpu "$metrics")
  metric_load=$(json_num load1 "$metrics")
  metric_ram_used=$(json_num ram_used_mb "$metrics")
  metric_ram_total=$(json_num ram_total_mb "$metrics")
  metric_swap_used=$(json_num swap_used_mb "$metrics")
  metric_swap_total=$(json_num swap_total_mb "$metrics")
  metric_disk_used=$(json_num disk_used_mb "$metrics")
  metric_disk_total=$(json_num disk_total_mb "$metrics")
  metric_rx=$(json_num rx_bytes "$metrics")
  metric_tx=$(json_num tx_bytes "$metrics")
  metric_kernel=$(json_str kernel "$metrics")
  metric_uptime=$(json_num uptime_seconds "$metrics")

  local args=(
    -sS -X POST "$API_URL"
    --data-urlencode "token=$TOKEN" \
    --data-urlencode "host=$HOSTNAME_VALUE" \
    --data-urlencode "all_vms=$(printf '%s' "$all_vms" | b64)" \
    --data-urlencode "running_vms=$(printf '%s' "$running_vms" | b64)" \
    --data-urlencode "vm_specs=$(printf '%s' "$specs" | b64)" \
    --data-urlencode "vm_inventory_json=$(printf '%s' "$inventory" | b64)" \
    --data-urlencode "metrics_json=$(printf '%s' "$metrics" | b64)" \
    --data-urlencode "agent_version=$AGENT_VERSION" \
    --data-urlencode "metric_cpu=${metric_cpu:-0}" \
    --data-urlencode "metric_load1=${metric_load:-0}" \
    --data-urlencode "metric_ram_used_mb=${metric_ram_used:-0}" \
    --data-urlencode "metric_ram_total_mb=${metric_ram_total:-0}" \
    --data-urlencode "metric_swap_used_mb=${metric_swap_used:-0}" \
    --data-urlencode "metric_swap_total_mb=${metric_swap_total:-0}" \
    --data-urlencode "metric_disk_used_mb=${metric_disk_used:-0}" \
    --data-urlencode "metric_disk_total_mb=${metric_disk_total:-0}" \
    --data-urlencode "metric_rx_bytes=${metric_rx:-0}" \
    --data-urlencode "metric_tx_bytes=${metric_tx:-0}" \
    --data-urlencode "metric_kernel=${metric_kernel:-}" \
    --data-urlencode "metric_uptime_seconds=${metric_uptime:-0}" \
    --data-urlencode "containers_json=$(printf '%s' "$containers" | b64)" \
    --data-urlencode "compose_json=$(printf '%s' "$compose" | b64)" \
    --data-urlencode "images_json=$(printf '%s' "$images" | b64)" \
    --data-urlencode "capabilities_json=$(printf '%s' "$capabilities" | b64)" \
    --data-urlencode "collector_errors_json=$(printf '%s' "$collector_errors" | b64)"
  )
  [ -n "$command_id" ] && args+=(--data-urlencode "command_id=$command_id")
  [ -n "$command_status" ] && args+=(--data-urlencode "command_status=$command_status")
  [ -n "$command_output" ] && args+=(--data-urlencode "command_output=$(printf '%s' "$command_output" | b64)")
  [ -n "$command_exit_code" ] && args+=(--data-urlencode "command_exit_code=$command_exit_code")
  [ -n "$command_stdout" ] && args+=(--data-urlencode "command_stdout=$(printf '%s' "$command_stdout" | b64)")
  [ -n "$command_stderr" ] && args+=(--data-urlencode "command_stderr=$(printf '%s' "$command_stderr" | b64)")
  [ -n "$command_diagnostics" ] && args+=(--data-urlencode "command_diagnostics_json=$(printf '%s' "$command_diagnostics" | b64)")
  curl "${args[@]}" -w $'\nVMANGE_HTTP_CODE:%{http_code}'
}

agent_self_disable() {
  if [ "$(id -u)" -eq 0 ]; then
    systemctl disable --now vmange-agent.service 2>/dev/null || true
    rm -f /etc/systemd/system/vmange-agent.service /usr/local/bin/vmange-agent /etc/vmange/agent.env 2>/dev/null || true
    systemctl daemon-reload 2>/dev/null || true
    return 0
  fi
  if command -v sudo >/dev/null 2>&1 && sudo -n true 2>/dev/null; then
    sudo systemctl disable --now vmange-agent.service 2>/dev/null || true
    sudo rm -f /etc/systemd/system/vmange-agent.service /usr/local/bin/vmange-agent /etc/vmange/agent.env 2>/dev/null || true
    sudo systemctl daemon-reload 2>/dev/null || true
    return 0
  fi
  return 1
}

agent_revoked() {
  mkdir -p /var/lib/vmange 2>/dev/null || true
  touch /var/lib/vmange/agent.revoked 2>/dev/null || true
  echo "VMange host was revoked by dashboard. Trying to disable local agent service." >&2
  if agent_self_disable; then
    echo "VMange agent disabled locally." >&2
    exit 0
  fi
  echo "VMange agent has no passwordless sudo rights; entering revoked idle mode." >&2
  while true; do
    sleep 3600
  done
}

as_root() {
  if [ "$(id -u)" = "0" ]; then
    "$@"
  elif command -v sudo >/dev/null 2>&1; then
    if ! sudo -n true >/dev/null 2>&1; then
      echo "Root privileges are required for $*. Passwordless sudo is not available for ${RUN_USER:-$(id -un)}." >&2
      return 126
    fi
    sudo -n "$@"
  else
    echo "Root privileges are required for $*" >&2
    return 1
  fi
}

run_command() {
  local action="$1"
  local target="$2"
  local payload="${3:-}"
  local value

  case "$action" in
    agent_upgrade|agent_uninstall) ;;
    container_start|container_stop|container_restart|container_pause|container_unpause|container_kill|container_remove|image_pull|image_remove|logs_tail|compose_up|compose_down|compose_pull|compose_restart|compose_deploy|dockerfile_deploy|host_install_virtualbox|host_install_docker|host_refresh_inventory|agent_restart|host_reboot|host_wol_send|script_run|terminal_exec) ;;
    *) [ -n "$VBOXMANAGE_BIN" ] || { echo "VBoxManage/vboxmanage command was not found"; return 2; } ;;
  esac

  case "$action" in
    start|stop|poweroff|pause|resume|reset|restart|refresh_inventory|snapshot_create|snapshot_restore|snapshot_delete|vm_clone|vm_delete|vm_set_resources|vm_set_boot_order|vm_set_description|vm_set_autostart|vm_attach_iso|vm_detach_iso|vm_attach_disk|vm_create_disk|vm_resize_disk|vm_set_network|vm_cable_connected|vm_export|vm_enable_vrde|vm_disable_vrde|vm_screenshot|vm_logs_list|vm_log_tail)
      target=$(command_target_name "$target" "$payload")
      echo "vmange preflight: action=$action target=$target user=${RUN_USER:-$(id -un)} home=${HOME:-} vbox=${VBOXMANAGE_BIN:-missing}"
      run_as_vm_user "$VBOXMANAGE_BIN" showvminfo "$target" --machinereadable >/dev/null 2>&1 || { echo "VM not found or inaccessible through VBoxManage: $target"; return 2; }
      ;;
  esac

  case "$action" in
    start)
      if collect_running_vms | grep -Fq "\"$target\""; then
        echo "VM is already running: $target"
      else
        run_as_vm_user "$VBOXMANAGE_BIN" startvm "$target" --type headless
      fi
      ;;
    stop) run_as_vm_user "$VBOXMANAGE_BIN" controlvm "$target" acpipowerbutton ;;
    poweroff) run_as_vm_user "$VBOXMANAGE_BIN" controlvm "$target" poweroff ;;
    pause) run_as_vm_user "$VBOXMANAGE_BIN" controlvm "$target" pause ;;
    resume) run_as_vm_user "$VBOXMANAGE_BIN" controlvm "$target" resume ;;
    reset) run_as_vm_user "$VBOXMANAGE_BIN" controlvm "$target" reset ;;
    refresh_inventory) echo "inventory refresh requested" ;;
    restart)
      run_as_vm_user "$VBOXMANAGE_BIN" controlvm "$target" acpipowerbutton
      sleep 8
      run_as_vm_user "$VBOXMANAGE_BIN" startvm "$target" --type headless
      ;;
    snapshot_create)
      value=$(json_payload_str name "$payload")
      run_as_vm_user "$VBOXMANAGE_BIN" snapshot "$target" take "${value:-vmange-$(date +%Y%m%d-%H%M%S)}"
      ;;
    snapshot_restore)
      value=$(json_payload_str snapshot "$payload")
      [ -n "$value" ] || { echo "snapshot is required"; return 2; }
      run_as_vm_user "$VBOXMANAGE_BIN" snapshot "$target" restore "$value"
      ;;
    snapshot_delete)
      value=$(json_payload_str snapshot "$payload")
      [ -n "$value" ] || { echo "snapshot is required"; return 2; }
      run_as_vm_user "$VBOXMANAGE_BIN" snapshot "$target" delete "$value"
      ;;
    vm_clone)
      value=$(json_payload_str name "$payload")
      [ -n "$value" ] || { echo "clone name is required"; return 2; }
      run_as_vm_user "$VBOXMANAGE_BIN" clonevm "$target" --name "$value" --register
      ;;
    vm_delete)
      run_as_vm_user "$VBOXMANAGE_BIN" unregistervm "$target" --delete
      ;;
    vm_set_resources)
      value=$(json_payload_num cpu "$payload")
      [ -z "$value" ] || run_as_vm_user "$VBOXMANAGE_BIN" modifyvm "$target" --cpus "$value"
      value=$(json_payload_num ram_mb "$payload")
      [ -z "$value" ] || run_as_vm_user "$VBOXMANAGE_BIN" modifyvm "$target" --memory "$value"
      value=$(json_payload_num vram_mb "$payload")
      [ -z "$value" ] || run_as_vm_user "$VBOXMANAGE_BIN" modifyvm "$target" --vram "$value"
      ;;
    vm_set_boot_order)
      for i in 1 2 3 4; do
        value=$(json_payload_str "boot$i" "$payload")
        [ -z "$value" ] || run_as_vm_user "$VBOXMANAGE_BIN" modifyvm "$target" "--boot$i" "$value"
      done
      ;;
    vm_set_description)
      value=$(json_payload_str description "$payload")
      run_as_vm_user "$VBOXMANAGE_BIN" modifyvm "$target" --description "$value"
      ;;
    vm_set_autostart)
      value=$(json_payload_bool enabled "$payload")
      [ "$value" = "true" ] && value=on || value=off
      run_as_vm_user "$VBOXMANAGE_BIN" modifyvm "$target" --autostart-enabled "$value"
      ;;
    vm_attach_iso)
      local controller port device path
      controller=$(json_payload_str controller "$payload")
      port=$(json_payload_num port "$payload")
      device=$(json_payload_num device "$payload")
      path=$(json_payload_str path "$payload")
      [ -n "$controller" ] && [ -n "$path" ] || { echo "controller and path are required"; return 2; }
      run_as_vm_user "$VBOXMANAGE_BIN" storageattach "$target" --storagectl "$controller" --port "${port:-1}" --device "${device:-0}" --type dvddrive --medium "$path"
      ;;
    vm_detach_iso)
      local controller port device
      controller=$(json_payload_str controller "$payload")
      port=$(json_payload_num port "$payload")
      device=$(json_payload_num device "$payload")
      [ -n "$controller" ] || { echo "controller is required"; return 2; }
      run_as_vm_user "$VBOXMANAGE_BIN" storageattach "$target" --storagectl "$controller" --port "${port:-1}" --device "${device:-0}" --type dvddrive --medium emptydrive
      ;;
    vm_attach_disk)
      local controller port device path
      controller=$(json_payload_str controller "$payload")
      port=$(json_payload_num port "$payload")
      device=$(json_payload_num device "$payload")
      path=$(json_payload_str path "$payload")
      [ -n "$controller" ] && [ -n "$path" ] || { echo "controller and path are required"; return 2; }
      run_as_vm_user "$VBOXMANAGE_BIN" storageattach "$target" --storagectl "$controller" --port "${port:-0}" --device "${device:-0}" --type hdd --medium "$path"
      ;;
    vm_create_disk)
      local path size format controller port device
      path=$(json_payload_str path "$payload")
      size=$(json_payload_num size_mb "$payload")
      format=$(json_payload_str format "$payload")
      controller=$(json_payload_str controller "$payload")
      port=$(json_payload_num port "$payload")
      device=$(json_payload_num device "$payload")
      [ -n "$path" ] && [ -n "$size" ] || { echo "path and size_mb are required"; return 2; }
      run_as_vm_user "$VBOXMANAGE_BIN" createmedium disk --filename "$path" --size "$size" --format "${format:-VDI}"
      [ -z "$controller" ] || run_as_vm_user "$VBOXMANAGE_BIN" storageattach "$target" --storagectl "$controller" --port "${port:-0}" --device "${device:-0}" --type hdd --medium "$path"
      ;;
    vm_resize_disk)
      local path size
      path=$(json_payload_str path "$payload")
      size=$(json_payload_num size_mb "$payload")
      [ -n "$path" ] && [ -n "$size" ] || { echo "path and size_mb are required"; return 2; }
      run_as_vm_user "$VBOXMANAGE_BIN" modifymedium disk "$path" --resize "$size"
      ;;
    vm_set_network)
      local adapter mode bridge hostonly intnet
      adapter=$(json_payload_num adapter "$payload")
      mode=$(json_payload_str mode "$payload")
      bridge=$(json_payload_str bridge "$payload")
      hostonly=$(json_payload_str hostonly "$payload")
      intnet=$(json_payload_str intnet "$payload")
      [ -n "$adapter" ] && [ -n "$mode" ] || { echo "adapter and mode are required"; return 2; }
      run_as_vm_user "$VBOXMANAGE_BIN" modifyvm "$target" "--nic$adapter" "$mode"
      [ -z "$bridge" ] || run_as_vm_user "$VBOXMANAGE_BIN" modifyvm "$target" "--bridgeadapter$adapter" "$bridge"
      [ -z "$hostonly" ] || run_as_vm_user "$VBOXMANAGE_BIN" modifyvm "$target" "--hostonlyadapter$adapter" "$hostonly"
      [ -z "$intnet" ] || run_as_vm_user "$VBOXMANAGE_BIN" modifyvm "$target" "--intnet$adapter" "$intnet"
      ;;
    vm_cable_connected)
      local adapter connected
      adapter=$(json_payload_num adapter "$payload")
      connected=$(json_payload_bool connected "$payload")
      [ -n "$adapter" ] || { echo "adapter is required"; return 2; }
      [ "$connected" = "false" ] && connected=off || connected=on
      run_as_vm_user "$VBOXMANAGE_BIN" modifyvm "$target" "--cableconnected$adapter" "$connected"
      ;;
    vm_export)
      value=$(json_payload_str path "$payload")
      [ -n "$value" ] || value="$COMPOSE_ROOT/../exports/$target.ova"
      mkdir -p "$(dirname "$value")"
      run_as_vm_user "$VBOXMANAGE_BIN" export "$target" --output "$value"
      ;;
    vm_import)
      value=$(json_payload_str path "$payload")
      [ -n "$value" ] || { echo "path is required"; return 2; }
      run_as_vm_user "$VBOXMANAGE_BIN" import "$value"
      ;;
    vm_create)
      local name ostype cpu ram vram disk_size disk_path controller iso_path network_mode start_after unattended guest_hostname guest_username guest_password guest_full_name guest_ssh_key guest_timezone guest_locale
      name=$(json_payload_str name "$payload")
      ostype=$(json_payload_str ostype "$payload")
      cpu=$(json_payload_num cpu "$payload")
      ram=$(json_payload_num ram_mb "$payload")
      vram=$(json_payload_num vram_mb "$payload")
      disk_size=$(json_payload_num disk_size_mb "$payload")
      disk_path=$(json_payload_str disk_path "$payload")
      controller=$(json_payload_str controller "$payload")
      iso_path=$(json_payload_str iso_path "$payload")
      network_mode=$(json_payload_str network_mode "$payload")
      start_after=$(json_payload_bool start "$payload")
      unattended=$(json_payload_bool unattended "$payload")
      guest_hostname=$(json_payload_str hostname "$payload")
      guest_username=$(json_payload_str username "$payload")
      guest_password=$(json_payload_str password "$payload")
      guest_full_name=$(json_payload_str full_name "$payload")
      guest_ssh_key=$(json_payload_str ssh_key "$payload")
      guest_timezone=$(json_payload_str timezone "$payload")
      guest_locale=$(json_payload_str locale "$payload")
      name=$(safe_name "$name")
      [ -n "$name" ] || { echo "VM name is required"; return 2; }
      [ -n "$disk_path" ] || disk_path="$HOME/VirtualBox VMs/$name/$name.vdi"
      echo "createvm: starting for $name"
      run_as_vm_user "$VBOXMANAGE_BIN" createvm --name "$name" --ostype "${ostype:-Ubuntu_64}" --register
      run_as_vm_user "$VBOXMANAGE_BIN" modifyvm "$name" --cpus "${cpu:-1}" --memory "${ram:-1024}" --vram "${vram:-16}" --nic1 "${network_mode:-nat}"
      run_as_vm_user "$VBOXMANAGE_BIN" storagectl "$name" --name "${controller:-SATA}" --add sata --controller IntelAhci
      mkdir -p "$(dirname "$disk_path")"
      run_as_vm_user "$VBOXMANAGE_BIN" createmedium disk --filename "$disk_path" --size "${disk_size:-20480}" --format VDI
      run_as_vm_user "$VBOXMANAGE_BIN" storageattach "$name" --storagectl "${controller:-SATA}" --port 0 --device 0 --type hdd --medium "$disk_path"
      echo "createvm: disk attached"
      if [ -n "$iso_path" ]; then
        run_as_vm_user "$VBOXMANAGE_BIN" storagectl "$name" --name IDE --add ide 2>/dev/null || true
        run_as_vm_user "$VBOXMANAGE_BIN" storageattach "$name" --storagectl IDE --port 1 --device 0 --type dvddrive --medium "$iso_path"
        echo "createvm: iso attached"
      fi
      if [ "$unattended" = "true" ]; then
        [ -n "$iso_path" ] || { echo "unattended install requires iso_path"; return 2; }
        [ -n "$guest_username" ] || { echo "unattended install requires username"; return 2; }
        [ -n "$guest_password" ] || { echo "unattended install requires password"; return 2; }
        echo "createvm: preparing unattended install"
        run_as_vm_user "$VBOXMANAGE_BIN" unattended install "$name" \
          --iso="$iso_path" \
          --user="$guest_username" \
          --password="$guest_password" \
          --full-user-name="${guest_full_name:-$guest_username}" \
          --hostname="${guest_hostname:-$name}" \
          --time-zone="${guest_timezone:-UTC}" \
          --locale="${guest_locale:-en_US}" \
          --start-vm=disabled
        if [ -n "$guest_ssh_key" ]; then
          echo "createvm: ssh key provided (guest post-config may still be required)"
        fi
        echo "createvm: unattended media applied"
      fi
      [ "$start_after" = "true" ] && run_as_vm_user "$VBOXMANAGE_BIN" startvm "$name" --type headless
      [ "$start_after" = "true" ] && echo "createvm: vm boot started"
      ;;
    vm_enable_vrde)
      value=$(json_payload_num port "$payload")
      run_as_vm_user "$VBOXMANAGE_BIN" modifyvm "$target" --vrde on --vrdeport "${value:-3389}"
      ;;
    vm_disable_vrde)
      run_as_vm_user "$VBOXMANAGE_BIN" modifyvm "$target" --vrde off
      ;;
    vm_screenshot)
      value=$(json_payload_str path "$payload")
      [ -n "$value" ] || value="$COMPOSE_ROOT/../screenshots/$target-$(date +%Y%m%d-%H%M%S).png"
      mkdir -p "$(dirname "$value")"
      run_as_vm_user "$VBOXMANAGE_BIN" controlvm "$target" screenshotpng "$value"
      echo "screenshot saved to $value"
      ;;
    vm_logs_list)
      value=$(vm_log_folder "$target")
      [ -n "$value" ] || { echo "No VirtualBox log folder found for $target"; return 2; }
      echo "Log folder: $value"
      ls -1 "$value" 2>/dev/null || true
      ;;
    vm_log_tail)
      local log_dir log_file log_lines
      log_dir=$(vm_log_folder "$target")
      [ -n "$log_dir" ] || { echo "No VirtualBox log folder found for $target"; return 2; }
      log_file=$(safe_log_file "$(json_payload_str file "$payload")")
      log_lines=$(json_payload_num lines "$payload")
      echo "Log file: $log_dir/$log_file"
      tail -n "${log_lines:-200}" "$log_dir/$log_file"
      ;;
    container_start) run_docker_cmd start "$target" ;;
    container_stop) run_docker_cmd stop "$target" ;;
    container_restart) run_docker_cmd restart "$target" ;;
    container_pause) run_docker_cmd pause "$target" ;;
    container_unpause) run_docker_cmd unpause "$target" ;;
    container_kill) run_docker_cmd kill "$target" ;;
    container_remove) run_docker_cmd rm -f "$target" ;;
    image_pull) run_docker_cmd pull "$target" ;;
    image_remove) run_docker_cmd rmi "$target" ;;
    logs_tail) run_docker_cmd logs --tail 200 "$target" ;;
    compose_up)
      local project file
      project=$(safe_name "$target")
      file="$COMPOSE_ROOT/$project/compose.yml"
      [ -f "$file" ] || { echo "Compose file not found: $file"; return 2; }
      run_docker_cmd compose -p "$project" -f "$file" up -d
      ;;
    compose_down)
      local project file
      project=$(safe_name "$target")
      file="$COMPOSE_ROOT/$project/compose.yml"
      [ -f "$file" ] || { echo "Compose file not found: $file"; return 2; }
      run_docker_cmd compose -p "$project" -f "$file" down
      ;;
    compose_pull)
      local project file
      project=$(safe_name "$target")
      file="$COMPOSE_ROOT/$project/compose.yml"
      [ -f "$file" ] || { echo "Compose file not found: $file"; return 2; }
      run_docker_cmd compose -p "$project" -f "$file" pull
      ;;
    compose_restart)
      local project file
      project=$(safe_name "$target")
      file="$COMPOSE_ROOT/$project/compose.yml"
      [ -f "$file" ] || { echo "Compose file not found: $file"; return 2; }
      run_docker_cmd compose -p "$project" -f "$file" restart
      ;;
    compose_deploy)
      local project dir file
      project=$(safe_name "$target")
      [ -n "$project" ] || { echo "invalid project"; return 2; }
      dir="$COMPOSE_ROOT/$project"
      file="$dir/compose.yml"
      install -d -m 0750 "$dir"
      value=$(json_payload_str compose_yaml "$payload")
      [ -n "$value" ] || value="$payload"
      printf '%s' "$value" > "$file"
      chmod 0640 "$file"
      run_docker_cmd compose -p "$project" -f "$file" config
      run_docker_cmd compose -p "$project" -f "$file" up -d
      ;;
    dockerfile_deploy)
      local image dir file
      image=$(safe_name "$target")
      [ -n "$image" ] || { echo "invalid image"; return 2; }
      dir="$COMPOSE_ROOT/dockerfiles/$image"
      file="$dir/Dockerfile"
      install -d -m 0750 "$dir"
      value=$(json_payload_str dockerfile "$payload")
      [ -n "$value" ] || value="$payload"
      printf '%s' "$value" > "$file"
      chmod 0640 "$file"
      run_docker_cmd build --check -t "$image" "$dir" >/dev/null 2>&1 || true
      run_docker_cmd build -t "$image" "$dir"
      ;;
    host_refresh_inventory)
      echo "inventory refresh requested"
      ;;
    agent_restart)
      echo "agent restart requested"
      ( sleep 2; as_root systemctl restart vmange-agent.service >/dev/null 2>&1 || true ) &
      ;;
    host_reboot)
      echo "host reboot requested for $HOSTNAME_VALUE"
      ( sleep 2; as_root systemctl reboot >/dev/null 2>&1 || as_root reboot >/dev/null 2>&1 || true ) &
      ;;
    host_wol_send)
      local wol_mac wol_broadcast wol_port wol_target
      wol_mac=$(json_payload_str mac "$payload")
      wol_broadcast=$(json_payload_str broadcast "$payload")
      wol_port=$(json_payload_num port "$payload")
      wol_target=$(json_payload_str target_host "$payload")
      [ -n "$wol_mac" ] || { echo "wol mac is required"; return 2; }
      wol_broadcast="${wol_broadcast:-255.255.255.255}"
      wol_port="${wol_port:-9}"
      echo "sending Wake-on-LAN packet for ${wol_target:-target} to $wol_mac via $wol_broadcast:$wol_port"
      if command -v wakeonlan >/dev/null 2>&1; then
        wakeonlan -i "$wol_broadcast" -p "$wol_port" "$wol_mac"
      elif command -v etherwake >/dev/null 2>&1; then
        as_root etherwake "$wol_mac"
      elif command -v python3 >/dev/null 2>&1; then
        python3 - "$wol_mac" "$wol_broadcast" "$wol_port" <<'PY'
import re
import socket
import sys

mac, broadcast, port = sys.argv[1], sys.argv[2], int(sys.argv[3])
clean = re.sub(r'[^0-9A-Fa-f]', '', mac)
if len(clean) != 12:
    raise SystemExit('invalid MAC address')
packet = bytes.fromhex('FF' * 6 + clean * 16)
sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
sock.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)
sock.sendto(packet, (broadcast, port))
print(f'wake packet sent to {mac} via {broadcast}:{port}')
PY
      else
        echo "no WOL sender available; install wakeonlan, etherwake, or python3" >&2
        return 2
      fi
      ;;
    host_install_docker)
      as_root true >/dev/null
      if command -v apt-get >/dev/null 2>&1; then
        as_root apt-get update && as_root apt-get install -y docker.io docker-compose-plugin
      elif command -v dnf >/dev/null 2>&1; then
        as_root dnf install -y docker docker-compose-plugin
      elif command -v yum >/dev/null 2>&1; then
        as_root yum install -y docker docker-compose-plugin
      else
        echo "No supported package manager found"; return 2
      fi
      as_root systemctl enable --now docker 2>/dev/null || true
      ;;
    host_install_virtualbox)
      as_root true >/dev/null
      if command -v apt-get >/dev/null 2>&1; then
        as_root apt-get update && as_root apt-get install -y virtualbox
      elif command -v dnf >/dev/null 2>&1; then
        as_root dnf install -y VirtualBox
      elif command -v yum >/dev/null 2>&1; then
        as_root yum install -y VirtualBox
      else
        echo "No supported package manager found"; return 2
      fi
      ;;
    script_run)
      value=$(json_payload_str body "$payload")
      [ -n "$value" ] || value="$payload"
      [ -n "$value" ] || { echo "script body is required"; return 2; }
      tmp="/tmp/vmange-script.$$"
      printf '%s\n' "$value" > "$tmp"
      chmod 0700 "$tmp"
      bash "$tmp"
      rm -f "$tmp"
      ;;
    terminal_exec)
      value=$(json_payload_str command "$payload")
      [ -n "$value" ] || value="$payload"
      [ -n "$value" ] || { echo "command is required"; return 2; }
      bash -lc "$value"
      ;;
    agent_upgrade)
      [ -n "$AGENT_URL" ] || { echo "VMANGE_AGENT_URL is not configured"; return 2; }
      tmp="/tmp/vmange-agent.$$"
      curl -fsSL "$AGENT_URL" -o "$tmp"
      new_version=$(sed -n 's/^AGENT_VERSION="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' "$tmp" | head -n 1)
      chmod 0755 "$tmp"
      install -d -m 0750 "$(dirname "$AGENT_PATH")"
      install -m 0755 "$tmp" "$AGENT_PATH"
      ln -sf "$AGENT_PATH" /usr/local/bin/vmange-agent 2>/dev/null || true
      rm -f "$tmp"
      echo "agent upgraded to ${new_version:-unknown}"
      ;;
    agent_uninstall)
      mkdir -p /var/lib/vmange 2>/dev/null || true
      touch /var/lib/vmange/agent.revoked 2>/dev/null || true
      if agent_self_disable; then
        echo "agent disabled and local service files removed"
      else
        echo "agent marked revoked; no passwordless sudo available to remove service files"
      fi
      ;;
    *) echo "action not allowed: $action"; return 2 ;;
  esac
}

handle_response() {
  local response="$1"
  response=$(printf '%s\n' "$response" | awk 'NF{line=$0} END{print line}')
  [ -n "$response" ] || return 0
  if [[ "$response" == none\|* ]]; then
    return 0
  fi

  local version command_id action target_b64 payload_b64 target payload output status stdout stderr exit_code diagnostics out_file err_file
  IFS='|' read -r version command_id action target_b64 payload_b64 <<< "$response"
  if [ "$version" != "v2" ]; then
    IFS='|' read -r action target <<< "$response"
    command_id=""
    payload=""
  else
    target=$(printf '%s' "$target_b64" | base64 -d 2>/dev/null || true)
    payload=$(printf '%s' "$payload_b64" | base64 -d 2>/dev/null || true)
  fi

  out_file=$(mktemp)
  err_file=$(mktemp)
  set +e
  run_command "$action" "$target" "$payload" >"$out_file" 2>"$err_file"
  exit_code=$?
  set -e
  stdout=$(cat "$out_file" 2>/dev/null || true)
  stderr=$(cat "$err_file" 2>/dev/null || true)
  rm -f "$out_file" "$err_file"
  output="${stdout}${stderr:+$'\n'}${stderr}"
  diagnostics=$(printf '{"agent_version":"%s","action":"%s","target":"%s","exit_code":%d,"run_user":"%s","home":"%s","vboxmanage_bin":"%s"}' \
    "$(printf '%s' "$AGENT_VERSION" | json_escape)" "$(printf '%s' "$action" | json_escape)" "$(printf '%s' "$target" | json_escape)" "$exit_code" "$(printf '%s' "${RUN_USER:-$(id -un)}" | json_escape)" "$(printf '%s' "${HOME:-}" | json_escape)" "$(printf '%s' "${VBOXMANAGE_BIN:-missing}" | json_escape)")
  if [ "$exit_code" -eq 0 ]; then
    status="done"
  else
    status="failed"
  fi
  if [ -n "$command_id" ]; then
    post_heartbeat "$command_id" "$status" "$output" "$exit_code" "$stdout" "$stderr" "$diagnostics" >/dev/null || true
  fi
  if [ "$action" = "agent_upgrade" ] && [ "$status" = "done" ] && [ "${VMANGE_AGENT_MODE:-}" = "loop" ]; then
    exec "$AGENT_PATH" loop
  fi
  if [ "$action" = "agent_uninstall" ] && [ "$status" = "done" ] && [ "${VMANGE_AGENT_MODE:-}" = "loop" ]; then
    agent_revoked
  fi
}

run_once() {
  if [ -z "$API_URL" ]; then
    echo "VMANGE_API_URL is required. Install through host-install.php or set it in /etc/vmange/agent.env." >&2
    return 2
  fi
  local response
  response=$(post_heartbeat)
  local http_code body
  http_code=$(printf '%s' "$response" | sed -n 's/^VMANGE_HTTP_CODE://p' | tail -n 1)
  body=$(printf '%s' "$response" | sed '/^VMANGE_HTTP_CODE:/d')
  if [ "$http_code" = "410" ]; then
    agent_revoked
  fi
  if [ "${http_code:-000}" -lt 200 ] || [ "${http_code:-000}" -ge 300 ]; then
    echo "VMange heartbeat failed with HTTP ${http_code:-000}: $body" >&2
    return 1
  fi
  response="$body"
  handle_response "$response"
}

if [ "${1:-once}" = "metrics" ]; then
  collect_metrics "$(collect_running_vms)"
elif [ "${1:-once}" = "loop" ]; then
  export VMANGE_AGENT_MODE=loop
  while true; do
    run_once || true
    sleep "$INTERVAL"
  done
else
  export VMANGE_AGENT_MODE=once
  run_once
fi
