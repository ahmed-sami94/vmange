param(
    [string]$Source = (Split-Path -Parent $PSScriptRoot),
    [string]$Output = (Join-Path (Split-Path -Parent (Split-Path -Parent $PSScriptRoot)) 'vbox.zip')
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression

$sourceRoot = (Resolve-Path -LiteralPath $Source).Path
$outputPath = [System.IO.Path]::GetFullPath($Output)
$temporaryPath = "$outputPath.tmp"
$excludedRootNames = @(
    'config.php',
    'error_log',
    'sessions',
    'vbox.zip',
    'vbox.tar.gz'
)
$excludedPatterns = @(
    '(^|/)(\.git|sessions?|cookies?[^/]*|\.env)(/|$)',
    '\.(zip|tar|tar\.gz|bak|sql\.dump)$'
    '(^|/)__pycache__(/|$)|\.pyc$'
)

function Set-ZipUnixMode {
    param(
        [System.IO.Compression.ZipArchiveEntry]$Entry,
        [int]$Mode,
        [bool]$Directory
    )

    $external = ([uint64]$Mode -shl 16)
    if ($Directory) {
        $external = $external -bor 0x10
    }
    $Entry.ExternalAttributes = [System.BitConverter]::ToInt32(
        [System.BitConverter]::GetBytes([uint32]$external),
        0
    )
}

function Get-ArchiveRelativePath {
    param([string]$FullName)

    return $FullName.Substring($sourceRoot.Length).TrimStart('\', '/').Replace('\', '/')
}

if (Test-Path -LiteralPath $temporaryPath) {
    Remove-Item -LiteralPath $temporaryPath -Force
}

$items = Get-ChildItem -LiteralPath $sourceRoot -Force -Recurse |
    Where-Object {
        $relative = Get-ArchiveRelativePath -FullName $_.FullName
        $rootName = $relative.Split('/')[0]
        $excludedRootNames -notcontains $rootName -and
        ($rootName -ne 'storage' -or $relative -eq 'storage/.htaccess') -and
        $rootName -ne '.runtime' -and
        $_.Name -ne 'vbox-config.php' -and
        ($_.Name -notlike '.env*' -or $_.Name -eq '.env.example') -and
        $_.Name -notlike '*.log' -and
        $_.Name -notlike '*cookie*' -and
        -not ($excludedPatterns | Where-Object { $relative -match $_ })
    } |
    Sort-Object FullName

$stream = [System.IO.File]::Open(
    $temporaryPath,
    [System.IO.FileMode]::CreateNew,
    [System.IO.FileAccess]::ReadWrite,
    [System.IO.FileShare]::None
)

try {
    $archive = [System.IO.Compression.ZipArchive]::new(
        $stream,
        [System.IO.Compression.ZipArchiveMode]::Create,
        $false
    )

    try {
        foreach ($item in $items) {
            $relative = Get-ArchiveRelativePath -FullName $item.FullName
            if ($item.PSIsContainer) {
                $entry = $archive.CreateEntry("$relative/")
                Set-ZipUnixMode -Entry $entry -Mode 0x41ED -Directory $true
                continue
            }

            $entry = $archive.CreateEntry($relative, [System.IO.Compression.CompressionLevel]::Optimal)
            $mode = if ($item.Extension -eq '.sh') { 0x81ED } else { 0x81A4 }
            Set-ZipUnixMode -Entry $entry -Mode $mode -Directory $false

            $input = [System.IO.File]::OpenRead($item.FullName)
            try {
                $entryStream = $entry.Open()
                try {
                    $input.CopyTo($entryStream)
                } finally {
                    $entryStream.Dispose()
                }
            } finally {
                $input.Dispose()
            }
        }
    } finally {
        $archive.Dispose()
    }
} finally {
    $stream.Dispose()
}

Move-Item -LiteralPath $temporaryPath -Destination $outputPath -Force
Write-Output "Created $outputPath"
