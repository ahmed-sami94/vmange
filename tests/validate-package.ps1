param(
    [string]$Archive = (Join-Path (Split-Path -Parent $PSScriptRoot) '..\vbox.zip')
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem
$path = [System.IO.Path]::GetFullPath($Archive)
if (-not (Test-Path -LiteralPath $path)) { throw "Package not found: $path" }
$zip = [System.IO.Compression.ZipFile]::OpenRead($path)
try {
    $names = @($zip.Entries.FullName)
    if ($names -notcontains 'index.php') { throw 'index.php is not at archive root' }
    if (@($names | Where-Object { $_ -like 'vbox/*' }).Count -gt 0) { throw 'Nested vbox/ directory found' }
    $forbidden = @($names | Where-Object { $_ -match '(^|/)(config\.php|error_log|cookies?[^/]*|sessions?|\.env|\.git)($|/)|\.(zip|tar|tar\.gz|bak|sql\.dump)$' })
    if ($forbidden.Count -gt 0) { throw "Forbidden artifacts: $($forbidden -join ', ')" }
    $privateFiles = @($names | Where-Object {
        ($_ -like 'storage/*' -and $_ -ne 'storage/.htaccess') -or
        $_ -match '(^|/)(vbox-config\.php|\.runtime|__pycache__|\.env\.[^/]+)($|/)|\.(log|pyc)$'
    })
    $privateFiles = @($privateFiles | Where-Object { $_ -ne '.env.example' })
    if ($privateFiles.Count -gt 0) { throw 'Private runtime or configuration files found in package' }
    if ($names -notcontains 'storage/.htaccess') { throw 'Storage access protection is missing' }
    if ($names -notcontains 'assets/agent/vmange-agent.sh') { throw 'Agent script is missing' }
    if ($names -notcontains 'assets/js/chart.umd.min.js') { throw 'Local Chart.js dependency is missing' }
    if ($names -notcontains 'notifications.php') { throw 'Shared mail transport is missing' }
    Write-Output "Package valid: $path ($($names.Count) entries)"
} finally {
    $zip.Dispose()
}
