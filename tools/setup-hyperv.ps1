<#
.SYNOPSIS
    Automated Hyper-V Virtual Switch & Appliance Provisioning Script for MicronOS.
.DESCRIPTION
    Provisions the dual-NIC virtual networking topology (MicronOS-WAN and MicronOS-LAN)
    and binds the host management IP. Optionally provisions the MicronOS Generation 2 VM.
.EXAMPLE
    .\tools\setup-hyperv.ps1
.EXAMPLE
    .\tools\setup-hyperv.ps1 -CreateVM
#>

[CmdletBinding()]
param(
    [switch]$CreateVM,
    [string]$VmName = "MicronOS-Dev",
    [string]$IsoPath = "D:\laragon\www\micronos\micronos-2.0.0-amd64.hybrid.iso",
    [string]$VhdDir = "C:\Hyper-V\Virtual Hard Disks",
    [string]$VmDir = "C:\Hyper-V\Virtual Machines"
)

$ErrorActionPreference = "Stop"

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "  MicronOS Hyper-V Provisioning & Network Topology Setup   " -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

# 1. Check Hyper-V permissions
try {
    $existingSwitches = Get-VMSwitch -ErrorAction Stop
} catch {
    Write-Error "Failed to access Hyper-V: $($_.Exception.Message)`nPlease ensure you are running in an elevated PowerShell prompt or have logged off and back on after joining 'Hyper-V Administrators'."
    exit 1
}

# 2. Identify physical network adapter with Internet connectivity
Write-Host ">> [1/4] Inspecting host network adapters..." -ForegroundColor Yellow
$physicalNic = Get-NetAdapter | Where-Object { 
    $_.Status -eq "Up" -and 
    $_.InterfaceDescription -notmatch "Hyper-V|vEthernet|Virtual|WSL|TAP|WireGuard|Loopback" 
} | Select-Object -First 1

if ($physicalNic) {
    Write-Host "   Detected active uplink adapter: $($physicalNic.Name) ($($physicalNic.InterfaceDescription))" -ForegroundColor Green
} else {
    Write-Warning "   No active physical uplink adapter detected. WAN switch may need manual binding."
}

# 3. Create or verify External WAN Switch (MicronOS-WAN)
Write-Host ">> [2/4] Configuring External WAN Switch (MicronOS-WAN)..." -ForegroundColor Yellow
$wanSwitch = Get-VMSwitch -Name "MicronOS-WAN" -ErrorAction SilentlyContinue
if (-not $wanSwitch) {
    if ($physicalNic) {
        New-VMSwitch -Name "MicronOS-WAN" -NetAdapterName $physicalNic.Name -AllowManagementOS $true
        Write-Host "   Created External Switch 'MicronOS-WAN' bound to $($physicalNic.Name)." -ForegroundColor Green
    } else {
        New-VMSwitch -Name "MicronOS-WAN" -SwitchType Internal
        Write-Host "   Created Internal Switch 'MicronOS-WAN' (fallback: no active physical NIC)." -ForegroundColor Yellow
    }
} else {
    Write-Host "   Switch 'MicronOS-WAN' already exists." -ForegroundColor Green
}

# 4. Create or verify Internal LAN Switch (MicronOS-LAN)
Write-Host ">> [3/4] Configuring Internal LAN Switch (MicronOS-LAN)..." -ForegroundColor Yellow
$lanSwitch = Get-VMSwitch -Name "MicronOS-LAN" -ErrorAction SilentlyContinue
if (-not $lanSwitch) {
    New-VMSwitch -Name "MicronOS-LAN" -SwitchType Internal
    Write-Host "   Created Internal Switch 'MicronOS-LAN'." -ForegroundColor Green
} else {
    Write-Host "   Switch 'MicronOS-LAN' already exists." -ForegroundColor Green
}

# 5. Bind Host IP to vEthernet (MicronOS-LAN)
Write-Host ">> [4/4] Configuring Host Management Interface IP..." -ForegroundColor Yellow
$lanAdapterName = "vEthernet (MicronOS-LAN)"
Start-Sleep -Seconds 2

$currentLanIp = Get-NetIPAddress -InterfaceAlias $lanAdapterName -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.IPAddress -eq "192.168.1.254" }
if (-not $currentLanIp) {
    try {
        New-NetIPAddress -InterfaceAlias $lanAdapterName -IPAddress 192.168.1.254 -PrefixLength 24 -ErrorAction Stop | Out-Null
        Write-Host "   Assigned host IP 192.168.1.254/24 to $lanAdapterName." -ForegroundColor Green
    } catch {
        Write-Warning "   Could not bind 192.168.1.254: $($_.Exception.Message)"
    }
} else {
    Write-Host "   Host interface already configured at 192.168.1.254/24." -ForegroundColor Green
}

# 6. Optional VM Creation
if ($CreateVM) {
    Write-Host ">> Provisioning Generation 2 VM: $VmName..." -ForegroundColor Cyan
    New-Item -ItemType Directory -Force -Path $VmDir, $VhdDir | Out-Null
    $vhdPath = Join-Path $VhdDir "$VmName.vhdx"

    if (-not (Get-VM -Name $VmName -ErrorAction SilentlyContinue)) {
        New-VM -Name $VmName `
               -Generation 2 `
               -MemoryStartupBytes 4GB `
               -SwitchName "MicronOS-WAN" `
               -NewVHDPath $vhdPath `
               -NewVHDSizeBytes 32GB `
               -Path $VmDir

        Set-VMProcessor -VMName $VmName -Count 4
        Set-VMMemory -VMName $VmName -DynamicMemoryEnabled $false
        Add-VMNetworkAdapter -VMName $VmName -SwitchName "MicronOS-LAN" -Name "LAN"
        Set-VMFirmware -VMName $VmName -EnableSecureBoot Off

        if (Test-Path $IsoPath) {
            Add-VMDvdDrive -VMName $VmName -Path $IsoPath
            $dvd = Get-VMDvdDrive -VMName $VmName
            Set-VMFirmware -VMName $VmName -FirstBootDevice $dvd
            Write-Host "   Mounted installer ISO: $IsoPath" -ForegroundColor Green
        } else {
            if (-not (Get-VMDvdDrive -VMName $VmName -ErrorAction SilentlyContinue)) {
                Add-VMDvdDrive -VMName $VmName
            }
            Write-Warning "   ISO path not found at $IsoPath. DVD drive created; attach ISO when ready."
        }

        Write-Host "   VM '$VmName' provisioned successfully." -ForegroundColor Green
    } else {
        Write-Host "   VM '$VmName' already exists." -ForegroundColor Yellow
        # Ensure SecureBoot is disabled for Linux kernel compatibility
        Set-VMFirmware -VMName $VmName -EnableSecureBoot Off
        Write-Host "   Verified SecureBoot is Off on '$VmName'." -ForegroundColor Green
    }
}

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "  Setup Complete! Hyper-V switches ready for MicronOS.    " -ForegroundColor Green
Write-Host "==========================================================" -ForegroundColor Cyan
