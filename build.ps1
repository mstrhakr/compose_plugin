#Requires -Version 7.0
<#
.SYNOPSIS
    Builds the compose.manager plugin package using Docker.

.DESCRIPTION
    This script runs a Slackware Docker container to build the .txz package.
    It downloads Docker Compose and Compose Switch, then packages
    everything together.

.PARAMETER Version
    The version string for the package (e.g., "0.1.0"). Defaults to version in .plg file.

.PARAMETER Dev
    Generate a development build with timestamp: YYYY.MM.DD.HHmm

.PARAMETER SkipTests
    Skip running tests before building. Not recommended.

.PARAMETER ComposeVersion
    Docker Compose version to include. Default: 5.1.2

.EXAMPLE
    ./build.ps1
    ./build.ps1 -Version "0.2.0"
    ./build.ps1 -Dev
    ./build.ps1 -ComposeVersion "2.41.0"
#>

param(
    [string]$Version,
    [switch]$Dev,
    [switch]$SkipTests,
    [string]$ComposeVersion,
    [string]$ResvgVersion,
    [string]$ResvgSha256
)

$ErrorActionPreference = "Stop"
$ScriptDir = $PSScriptRoot

# Run tests before building
$testScript = Join-Path $ScriptDir "test.ps1"
if (Test-Path $testScript) {
    if (-not $SkipTests) {
        Write-Host "Running tests..." -ForegroundColor Yellow
        & $testScript
        if ($LASTEXITCODE -ne 0) {
            throw "Tests failed. Build aborted."
        }
    }
} else {
    throw "test.ps1 not found. Cannot run tests."
}

# Read component versions from versions.env if not supplied as parameters
$versionsFile = Join-Path $ScriptDir "versions.env"
if (Test-Path $versionsFile) {
    Get-Content $versionsFile | ForEach-Object {
        if ($_ -match '^COMPOSE_VERSION=(.+)$' -and -not $ComposeVersion) { $ComposeVersion = $Matches[1].Trim() }
        if ($_ -match '^RESVG_VERSION=(.+)$'   -and -not $ResvgVersion)   { $ResvgVersion   = $Matches[1].Trim() }
        if ($_ -match '^RESVG_SHA256=(.+)$'    -and -not $ResvgSha256)    { $ResvgSha256    = $Matches[1].Trim() }
    }
}
if (-not $ComposeVersion) { $ComposeVersion = "5.1.2" }
if (-not $ResvgVersion)   { $ResvgVersion   = "0.48.1" }

# Generate dev version with timestamp if -Dev flag is used
if ($Dev) {
    $now = Get-Date
    $Version = $now.ToString("yyyy.MM.dd.HHmm")
    Write-Host "Generated dev version: $Version" -ForegroundColor Cyan
}

# If no version specified, read from .plg file
if (-not $Version) {
    $plgContent = Get-Content "$ScriptDir\compose.manager.plg" -Raw
    if ($plgContent -match 'ENTITY version\s+"([^"]+)"') {
        $Version = $Matches[1]
        Write-Host "Using version from .plg file: $Version" -ForegroundColor Cyan
    } else {
        throw "Could not determine version. Please specify -Version parameter."
    }
}

# Extract HHmm from version for dev builds (YYYY.MM.DD.HHmm), otherwise generate fresh
if ($Version -match '\d{4}\.\d{2}\.\d{2}\.(\d{4})$') {
    $BuildNum = $Matches[1]
} else {
    $BuildNum = (Get-Date).ToString("HHmm")
}
$PackageName = "compose.manager-$Version-noarch-$BuildNum.txz"
$OutputPath = "$ScriptDir\archive"

# Ensure output directory exists
if (-not (Test-Path $OutputPath)) {
    New-Item -ItemType Directory -Path $OutputPath -Force | Out-Null
}

# Generate a temporary plugin manifest for this specific build so install on remote will use local package path
$plgSourcePath = Join-Path $ScriptDir "compose.manager.plg"
if (-not (Test-Path $plgSourcePath -PathType Leaf)) {
    throw "Plugin manifest not found: $plgSourcePath"
}

$PackageBaseName = "compose.manager-$Version-noarch-$BuildNum"
$PackageFileName = "$PackageBaseName.txz"
$PackageURL = 'file:///tmp/' + $PackageFileName
$TempPluginPath = Join-Path $OutputPath "compose.manager.plg"

$plgLines = Get-Content $plgSourcePath
$plgLines = $plgLines | ForEach-Object {
    if ($_ -match '^\s*<!ENTITY version "') { "<!ENTITY version `"$Version`">" }
    elseif ($_ -match '^\s*<!ENTITY packageVER "') { "<!ENTITY packageVER `"$Version`">" }
    elseif ($_ -match '^\s*<!ENTITY pkgBUILD "') { "<!ENTITY pkgBUILD `"$BuildNum`">" }
    elseif ($_ -match '^\s*<!ENTITY packageName "') { "<!ENTITY packageName `"$PackageBaseName`">" }
    elseif ($_ -match '^\s*<!ENTITY packagefile "') { "<!ENTITY packagefile `"$PackageFileName`">" }
    elseif ($_ -match '^\s*<!ENTITY packageURL "') { "<!ENTITY packageURL `"$PackageURL`">" }
    elseif ($_ -match '^\s*<FILE Name="&pluginLOC;/&packagefile;"') { "<FILE Name='/tmp/$PackageFileName' Run='upgradepkg --install-new'>" }
    elseif ($_ -match '^\s*<URL>') { "<URL>$PackageURL</URL>" }
    else { $_ }
}
Set-Content -Path $TempPluginPath -Value $plgLines -Encoding UTF8
Write-Host "Generated temporary plugin manifest for build: $TempPluginPath" -ForegroundColor Cyan

Write-Host "Building compose.manager package v$Version (build $BuildNum)" -ForegroundColor Green
Write-Host "  Docker Compose: v$ComposeVersion" -ForegroundColor Gray
Write-Host ""

# Convert Windows paths to Docker-compatible paths
$ArchivePath = $OutputPath -replace '\\', '/' -replace '^([A-Za-z]):', '/$1'
$SourcePath = "$ScriptDir/source" -replace '\\', '/' -replace '^([A-Za-z]):', '/$1'

# CA bundle setup (host side) for Docker HTTPS validation
$HostCACert = $env:CA_CERT_PATH
if (-not $HostCACert) {
    $HostCACert = Join-Path $env:TEMP 'cacert.pem'
}

if (-not (Test-Path $HostCACert)) {
    Write-Host "CA bundle not found at $HostCACert. Downloading fresh bundle..." -ForegroundColor Yellow
    try {
        Invoke-WebRequest -Uri 'https://curl.se/ca/cacert.pem' -OutFile $HostCACert -UseBasicParsing -ErrorAction Stop
    }
    catch {
        Write-Host "Failed to download CA bundle for Docker build: $_" -ForegroundColor Red
        throw "CA bundle not available. Set CA_CERT_PATH to a valid file and rerun."
    }
}

# In-container path for CA bundle
$ContainerCACert = '/etc/ssl/certs/ca-certificates.crt'

# Build in Docker
$dockerArgs = @(
    "run", "--rm", "--tmpfs", "/tmp"
    "-v", "${ArchivePath}:/mnt/output:rw"
    "-v", "${SourcePath}:/mnt/source:ro"
    "-v", "${HostCACert}:${ContainerCACert}:ro"
    "-e", "TZ=America/New_York"
    "-e", "COMPOSE_VERSION=$ComposeVersion"
    "-e", "RESVG_VERSION=$ResvgVersion"
    "-e", "RESVG_SHA256=$ResvgSha256"
    "-e", "OUTPUT_FOLDER=/mnt/output"
    "-e", "PKG_VERSION=$Version"
    "-e", "PKG_BUILD=$BuildNum"
    "-e", "CA_CERT=${ContainerCACert}"
    "vbatts/slackware:latest"
    "/mnt/source/pkg_build.sh"
)

Write-Host "Running Docker build..." -ForegroundColor Yellow
docker @dockerArgs

if ($LASTEXITCODE -ne 0) {
    throw "Docker build failed with exit code $LASTEXITCODE"
}

$PackagePath = Join-Path $OutputPath $PackageName
if (Test-Path $PackagePath) {
    # Calculate MD5
    $md5 = (Get-FileHash -Path $PackagePath -Algorithm MD5).Hash.ToLower()

    Write-Host ""
    Write-Host "Build successful!" -ForegroundColor Green
    Write-Host "  Package: $PackagePath" -ForegroundColor Cyan
    Write-Host "  MD5: $md5" -ForegroundColor Cyan

    # Update packageMD5 in the temporary plugin manifest so plugin installer can validate local package
    $tempPlgLines = Get-Content $TempPluginPath
    $tempPlgLines = $tempPlgLines | ForEach-Object {
        if ($_ -match '^\s*<!ENTITY packageMD5 "') { "<!ENTITY packageMD5 `"$md5`">" } 
        elseif ($_ -match '^\s*<MD5>') { "<MD5>$md5</MD5>" }
        else { $_ }
    }
    Set-Content -Path $TempPluginPath -Value $tempPlgLines -Encoding UTF8

    # Return build info for use by release script
    return @{
        Version = $Version
        PackagePath = $PackagePath
        PackageName = $PackageName
        MD5 = $md5
        ComposeVersion = $ComposeVersion
        AceVersion = $AceVersion
        PluginPath = $TempPluginPath
    }
} else {
    throw "Package was not created: $PackagePath"
}
