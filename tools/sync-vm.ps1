<#
.SYNOPSIS
    MicronOS VM Hot-Sync Utility for Windows 11 / Hyper-V Development.
.DESCRIPTION
    Pushes modified application, view, and daemon files to the running MicronOS
    virtual appliance and reloads services in under 2 seconds.
.EXAMPLE
    .\tools\sync-vm.ps1
.EXAMPLE
    .\tools\sync-vm.ps1 -DaemonOnly
.EXAMPLE
    .\tools\sync-vm.ps1 -GuiOnly
#>

[CmdletBinding()]
param(
    [string]$ApplianceIp = "192.168.1.1",
    [string]$User = "root",
    [switch]$DaemonOnly,
    [switch]$GuiOnly,
    [switch]$FullSync
)

$ErrorActionPreference = "Stop"

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "  MicronOS Fast Hot-Sync -> ${User}@${ApplianceIp}" -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

# Test network ping reachability
if (-not (Test-Connection -ComputerName $ApplianceIp -Count 1 -Quiet)) {
    Write-Warning "Appliance at $ApplianceIp is not responding to ICMP ping."
    Write-Host "Checking if SSH is still listening..." -ForegroundColor Yellow
}

# 1. Sync Python Root Daemon (micrond)
if ($DaemonOnly -or (-not $GuiOnly)) {
    if (Test-Path "system\micrond\micrond.py") {
        Write-Host ">> [1/2] Syncing system/micrond/micrond.py..." -ForegroundColor Yellow
        scp system/micrond/micrond.py "${User}@${ApplianceIp}:/usr/local/bin/micrond"
        ssh "${User}@${ApplianceIp}" "systemctl restart micrond"
        Write-Host "   micrond service successfully restarted." -ForegroundColor Green
    }
}

# 2. Sync Web Management Plane (CodeIgniter 4 PHP GUI)
if ($GuiOnly -or (-not $DaemonOnly)) {
    Write-Host ">> [2/2] Syncing Web Management Plane (app/ & public/)..." -ForegroundColor Yellow
    scp -r app "${User}@${ApplianceIp}:/usr/local/micron/"
    
    if (Test-Path "public\css") {
        scp -r public\css "${User}@${ApplianceIp}:/usr/local/micron/public/"
    }
    if (Test-Path "public\assets") {
        scp -r public\assets "${User}@${ApplianceIp}:/usr/local/micron/public/"
    }
    
    if ($FullSync) {
        Write-Host "   Performing full tree synchronization..." -ForegroundColor Yellow
        scp -r system "${User}@${ApplianceIp}:/usr/local/micron/"
        scp -r bin "${User}@${ApplianceIp}:/usr/local/micron/"
    }

    # Clear application cache and reload PHP-FPM + Apache2
    ssh "${User}@${ApplianceIp}" "rm -rf /usr/local/micron/writable/cache/* && (systemctl reload php8.4-fpm 2>/dev/null || systemctl reload php-fpm 2>/dev/null || systemctl reload php8.3-fpm 2>/dev/null || true) && systemctl reload apache2"
    Write-Host "   PHP-FPM & Apache2 reloaded, application cache purged." -ForegroundColor Green
}

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "  Hot-Sync Complete! Access Web GUI at https://${ApplianceIp}" -ForegroundColor Green
Write-Host "==========================================================" -ForegroundColor Cyan
