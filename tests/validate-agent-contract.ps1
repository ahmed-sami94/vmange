$ErrorActionPreference = 'Stop'
$agent = Get-Content (Join-Path $PSScriptRoot '..\assets\agent\vmange-agent.sh') -Raw
$agentBytes = [System.IO.File]::ReadAllBytes((Join-Path $PSScriptRoot '..\assets\agent\vmange-agent.sh'))
$manifest = Get-Content (Join-Path $PSScriptRoot '..\assets\agent\version.json') -Raw | ConvertFrom-Json
if ($manifest.version -ne 'v2.0.0') { throw "Unexpected agent version: $($manifest.version)" }
if ($agent -notmatch ('(?m)^AGENT_VERSION="' + [regex]::Escape($manifest.version) + '"$')) {
    throw 'Agent executable and manifest versions differ'
}
foreach ($required in @('host_uuid=', 'command_lease_token', 'version" != "v3"', 'running_vm_names', 'running_vm_uuids')) {
    if ($agent -notlike "*$required*") { throw "Agent contract marker is missing: $required" }
}
if ($agent -match 'change-me-agent-token') { throw 'Agent contains an insecure fallback token' }
if ($agentBytes -contains 13) { throw 'Agent must use LF line endings; CRLF breaks /usr/bin/env bash on Linux' }
Write-Output "Agent contract valid: $($manifest.version)"
