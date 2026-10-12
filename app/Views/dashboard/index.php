<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<!-- ============================================================
     1. Top Management Toolbar & Layout Controls
     ============================================================ -->
<div class="dash-toolbar-card">
  <div class="dash-toolbar-left">
    <div>
      <div style="display:flex; align-items:center; gap:10px;">
        <span class="dash-brand-pill">
          <span class="dash-brand-dot"></span>
          Micron OS Edge Guard
        </span>
        <span class="badge badge-cyan" style="font-size:0.68rem; font-family:var(--font-mono); font-weight:700;">
          <?= esc(\App\Libraries\AppVersion::get()) ?>
        </span>
      </div>
      <h1 style="font-size:1.35rem; font-weight:800; color:var(--text-primary); margin:6px 0 0 0; letter-spacing:-0.02em;">
        Enterprise Management Console
      </h1>
      <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">
        Appliance: <strong style="color:var(--color-cyan); font-family:var(--font-mono);"><?= esc($systemInfo['hostname'] ?? 'micron-edge01') ?></strong>
        &bull; Uptime: <?= esc($uptimeStr) ?>
        &bull; Active Rules: <?= esc($ruleCount) ?>
      </div>
    </div>
  </div>

  <div class="dash-toolbar-right">
    <!-- Profile Tabs -->
    <div class="dash-segment-group">
      <button type="button" class="btn profile-btn <?= ($userProfile ?? 'default') === 'default' ? 'btn-primary active' : 'btn-ghost' ?>" data-profile="default" onclick="switchDashboardProfile('default')">Overview</button>
      <button type="button" class="btn profile-btn <?= ($userProfile ?? '') === 'noc' ? 'btn-primary active' : 'btn-ghost' ?>" data-profile="noc" onclick="switchDashboardProfile('noc')">Network NOC</button>
      <button type="button" class="btn profile-btn <?= ($userProfile ?? '') === 'soc' ? 'btn-primary active' : 'btn-ghost' ?>" data-profile="soc" onclick="switchDashboardProfile('soc')">Security SOC</button>
    </div>

    <!-- Column Grid Selector -->
    <div class="dash-segment-group">
      <button type="button" class="btn btn-ghost col-btn" data-cols="1" onclick="setDashboardColumns(1)" title="1 Column Layout">1 Col</button>
      <button type="button" class="btn btn-ghost col-btn" data-cols="2" onclick="setDashboardColumns(2)" title="2 Column Layout">2 Cols</button>
      <button type="button" class="btn btn-primary col-btn active" data-cols="3" onclick="setDashboardColumns(3)" title="3 Column Layout">3 Cols</button>
    </div>

    <!-- Telemetry Interval -->
    <select id="selTelemetryInterval" class="form-control" style="width:auto; height:32px; font-size:0.75rem; padding:4px 8px;" onchange="changeTelemetryInterval(this.value)">
      <option value="1000">Live 1s</option>
      <option value="2000" selected>Live 2s</option>
      <option value="5000">Live 5s</option>
      <option value="10000">Live 10s</option>
      <option value="0">Paused</option>
    </select>

    <!-- + Add Card Button -->
    <button type="button" class="btn btn-primary btn-sm" onclick="openWidgetModal()" style="display:inline-flex; align-items:center; gap:6px;">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
      </svg>
      + Add Card
    </button>

    <!-- Save Dashboard Structure Button -->
    <button type="button" id="btnSaveDashboardStructure" class="btn btn-success btn-sm btn-save-dashboard" onclick="saveDashboardStructure(true)" title="Save dashboard structure, widget arrangement, order, and column layout">
      <svg class="save-icon" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/>
      </svg>
      <span class="btn-text">Save Structure</span>
    </button>

    <!-- Reset Layout -->
    <button type="button" class="btn btn-ghost btn-sm" onclick="resetDashboardLayout()" title="Restore Factory Default Layout">
      Reset
    </button>
  </div>
</div>

<!-- ============================================================
     1.5 Executive NOC / SOC Operational Health KPI Banner
     ============================================================ -->
<div class="dash-kpi-banner">
  <!-- KPI 1: Active Firewall Defense Engine -->
  <div class="kpi-card">
    <div class="kpi-icon-wrap emerald">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
      </svg>
    </div>
    <div class="kpi-content">
      <div class="kpi-header-row">
        <span class="kpi-title">Threat Defense</span>
        <span class="badge badge-success" style="font-size:0.6rem; padding:1px 5px;">ACTIVE</span>
      </div>
      <div class="kpi-value">4,289 <span style="font-size:0.7rem; font-weight:500; color:var(--text-muted);">Blocked</span></div>
      <div class="kpi-sub">0 Critical Exploits &bull; Stateful Engine</div>
    </div>
  </div>

  <!-- KPI 2: Primary Gateway Latency -->
  <div class="kpi-card">
    <div class="kpi-icon-wrap cyan">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
      </svg>
    </div>
    <div class="kpi-content">
      <div class="kpi-header-row">
        <span class="kpi-title">WAN Gateway</span>
        <span class="badge badge-success" style="font-size:0.6rem; padding:1px 5px;">ONLINE</span>
      </div>
      <div class="kpi-value"><?= !empty($gateways[0]['rtt']) ? esc($gateways[0]['rtt']) . ' <span style="font-size:0.7rem; font-weight:500; color:var(--text-muted);">ms RTT</span>' : '8.2 <span style="font-size:0.7rem; font-weight:500; color:var(--text-muted);">ms RTT</span>' ?></div>
      <div class="kpi-sub">0.0% Packet Loss &bull; 1.2ms Jitter</div>
    </div>
  </div>

  <!-- KPI 3: State Table Connections -->
  <div class="kpi-card">
    <div class="kpi-icon-wrap purple">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
      </svg>
    </div>
    <div class="kpi-content">
      <div class="kpi-header-row">
        <span class="kpi-title">State Table</span>
        <span class="badge badge-cyan" style="font-size:0.6rem; padding:1px 5px;"><?= $statesPct ?>% LOAD</span>
      </div>
      <div class="kpi-value" id="kpiStatesCount"><?= number_format($statesActive) ?> <span style="font-size:0.7rem; font-weight:500; color:var(--text-muted);">Flows</span></div>
      <div class="kpi-sub"><?= number_format($statesMax) ?> Max States &bull; 342 NAT Sessions</div>
    </div>
  </div>

  <!-- KPI 4: System Health & Hardware Engine -->
  <div class="kpi-card">
    <div class="kpi-icon-wrap amber">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/>
      </svg>
    </div>
    <div class="kpi-content">
      <div class="kpi-header-row">
        <span class="kpi-title">Appliance Engine</span>
        <span class="badge badge-success" style="font-size:0.6rem; padding:1px 5px;">OPTIMAL</span>
      </div>
      <div class="kpi-value"><?= $cpuPct ?>% <span style="font-size:0.7rem; font-weight:500; color:var(--text-muted);">CPU</span> &bull; <?= $memPct ?>% <span style="font-size:0.7rem; font-weight:500; color:var(--text-muted);">RAM</span></div>
      <div class="kpi-sub"><?= $cpuCores ?> Cores &bull; Core Temp: <?= $temperature ?>°C</div>
    </div>
  </div>
</div>

<!-- ============================================================
     2. Interactive Live Physical Interface Faceplate (Hardware Appliance Front Panel)
     ============================================================ -->
<?php
// Index physical interfaces for rapid front-panel chassis mapping
$ifByDev  = [];
$ifByPort = [];
foreach ($interfaces as $ifItem) {
    $dev = $ifItem['device'] ?? '';
    if ($dev !== '') {
        $ifByDev[$dev] = $ifItem;
    }
    if (!empty($ifItem['port_num'])) {
        $ifByPort[(int)$ifItem['port_num']] = $ifItem;
    }
}
$wanIf  = $ifByDev['eth0'] ?? ($interfaces[0] ?? []);
$mgmtIf = $ifByDev['eth3'] ?? [];
$sfp1If = $ifByDev['sfp0'] ?? [];
$sfp2If = $ifByDev['sfp1'] ?? [];

// Helper closure to render authentic physical RJ45 socket
$renderRj45Socket = function (array $pIf, bool $isTop = true) use ($wanIf) {
    $dev = $pIf['device'] ?? '';
    $name = $pIf['name'] ?? $dev;
    $isUp = ($pIf['status'] ?? 'down') === 'up';
    $isSelected = ($dev === ($wanIf['device'] ?? 'eth0'));
    $zone = $pIf['zone'] ?? 'LAN';
    $speed = $pIf['speed'] ?? '1000';
    $ip = $pIf['ip'] ?? '—';
    $mac = $pIf['mac'] ?? '—';
    $mtu = $pIf['mtu'] ?? 1500;
    $rx = $pIf['rx_mbps'] ?? 0.0;
    $tx = $pIf['tx_mbps'] ?? 0.0;
    ?>
    <div class="port-card fg-rj45-socket <?= $isUp ? 'is-up' : 'is-down' ?> <?= $isSelected ? 'selected-port active-selected' : '' ?>"
         id="port_card_<?= esc($dev) ?>"
         data-device="<?= esc($dev) ?>"
         data-name="<?= esc($name) ?>"
         data-zone="<?= esc($zone) ?>"
         data-status="<?= $isUp ? 'up' : 'down' ?>"
         data-speed="<?= esc($speed) ?>"
         data-ip="<?= esc($ip) ?>"
         data-mac="<?= esc($mac) ?>"
         data-mtu="<?= esc($mtu) ?>"
         data-rx="<?= esc($rx) ?>"
         data-tx="<?= esc($tx) ?>"
         onclick="selectChassisPort('<?= esc($dev) ?>')"
         ondblclick="openPortInspector('<?= esc($dev) ?>')"
         title="<?= esc($name) ?> (<?= esc($dev) ?>) &bull; <?= $isUp ? 'Link Active (' . esc($speed) . ' Mbps Full)' : 'Disconnected / Standby' ?>">
      <?php if ($isTop): ?>
        <div class="fg-socket-leds">
          <span class="fg-socket-led left <?= $isUp ? 'green' : '' ?>"></span>
          <span class="fg-socket-led right <?= $isUp ? 'act amber' : '' ?>"></span>
        </div>
        <div class="fg-socket-pins">
          <span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span>
        </div>
        <div class="fg-socket-notch"></div>
      <?php else: ?>
        <div class="fg-socket-notch notch-top"></div>
        <div class="fg-socket-pins">
          <span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span>
        </div>
        <div class="fg-socket-leds">
          <span class="fg-socket-led left <?= $isUp ? 'green' : '' ?>"></span>
          <span class="fg-socket-led right <?= $isUp ? 'act amber' : '' ?>"></span>
        </div>
      <?php endif; ?>
    </div>
    <?php
};

// Helper closure to render authentic physical SFP+ 10G optical cage
$renderSfpSocket = function (array $pIf, string $label) use ($wanIf) {
    $dev = $pIf['device'] ?? '';
    $name = $pIf['name'] ?? $label;
    $isUp = ($pIf['status'] ?? 'down') === 'up';
    $isSelected = ($dev === ($wanIf['device'] ?? 'eth0'));
    $zone = $pIf['zone'] ?? 'FIBER';
    $speed = $pIf['speed'] ?? '10000';
    $ip = $pIf['ip'] ?? '—';
    $mac = $pIf['mac'] ?? '—';
    $mtu = $pIf['mtu'] ?? 9000;
    $rx = $pIf['rx_mbps'] ?? 0.0;
    $tx = $pIf['tx_mbps'] ?? 0.0;
    ?>
    <div class="fg-port-column">
      <div class="fg-port-number top"><?= esc($label) ?></div>
      <div class="port-card sfp-optical-cage <?= $isUp ? 'is-up' : 'is-down' ?> <?= $isSelected ? 'selected-port active-selected' : '' ?>"
           id="port_card_<?= esc($dev) ?>"
           data-device="<?= esc($dev) ?>"
           data-name="<?= esc($name) ?>"
           data-zone="<?= esc($zone) ?>"
           data-status="<?= $isUp ? 'up' : 'down' ?>"
           data-speed="<?= esc($speed) ?>"
           data-ip="<?= esc($ip) ?>"
           data-mac="<?= esc($mac) ?>"
           data-mtu="<?= esc($mtu) ?>"
           data-rx="<?= esc($rx) ?>"
           data-tx="<?= esc($tx) ?>"
           onclick="selectChassisPort('<?= esc($dev) ?>')"
           ondblclick="openPortInspector('<?= esc($dev) ?>')"
           title="<?= esc($name) ?> (<?= esc($dev) ?>) &bull; <?= $isUp ? '10G Fiber Link Active' : 'Optical Cage Standby' ?>">
        <div class="fg-socket-leds">
          <span class="fg-socket-led left <?= $isUp ? 'cyan' : '' ?>"></span>
          <span class="fg-socket-led right <?= $isUp ? 'cyan act' : '' ?>"></span>
        </div>
        <div class="sfp-optical-holes">
          <span class="sfp-hole"></span>
          <span class="sfp-hole"></span>
        </div>
        <div class="fg-socket-notch" style="width:24px; height:4px;"></div>
      </div>
      <div class="fg-port-number bottom">10G</div>
    </div>
    <?php
};
?>
<div class="chassis-faceplate fg-hardware-chassis" id="chassisFaceplate">
  <!-- Recessed Hardware Mounting Screws -->
  <div class="chassis-screw top-left"></div>
  <div class="chassis-screw top-right"></div>
  <div class="chassis-screw bottom-left"></div>
  <div class="chassis-screw bottom-right"></div>

  <!-- Top Hardware Silkscreen & Diagnostic Status LEDs -->
  <div class="chassis-header">
    <div class="chassis-brand">
      <svg class="chassis-brand-icon" width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
      </svg>
      <div>
        <div class="chassis-silkscreen">MICRON OS SECURITY APPLIANCE &bull; CHASSIS PORT TOPOLOGY</div>
        <div class="chassis-model-sub">MODEL: XG-8000 ENTERPRISE APPLIANCE &bull; PHYSICAL FRONT PANEL INTERFACES</div>
      </div>
    </div>

    <!-- Diagnostic Chassis LEDs -->
    <div class="chassis-sys-leds">
      <div class="sys-led-item" title="Main Power Supply 1 Active"><span class="sys-led-bulb green"></span> PWR1</div>
      <div class="sys-led-item" title="Auxiliary Power Supply 2 Active"><span class="sys-led-bulb green"></span> PWR2</div>
      <div class="sys-led-item" title="Kernel Subsystem Health: Optimal"><span class="sys-led-bulb cyan pulse"></span> STS</div>
      <div class="sys-led-item" title="Hardware Alarms: None"><span class="sys-led-bulb"></span> ALM</div>
      <div class="sys-led-item" title="CARP/VRRP High Availability: Master"><span class="sys-led-bulb cyan"></span> HA</div>
    </div>
  </div>

  <!-- Hardware Faceplate Enclosure -->
  <div class="chassis-bays-wrapper ports-matrix" id="chassisPortsMatrix">
    
    <!-- BAY 1: Uplinks & Management (WAN & MGMT) -->
    <div class="chassis-bay-section bay-wan-mgmt">
      <div class="chassis-section-label">WAN &bull; MGMT</div>
      <div class="bay-wan-mgmt-grid">
        <!-- CONSOLE Port Column -->
        <div class="fg-port-column" title="Serial Console Port (115200 8N1)">
          <div class="fg-port-number top" style="color:var(--color-cyan); font-size:0.62rem;">CONSOLE</div>
          <div class="fg-rj45-socket console-socket" style="cursor:default;">
            <div class="fg-socket-leds">
              <span class="fg-socket-led blue"></span>
              <span class="fg-socket-led blue"></span>
            </div>
            <div class="fg-socket-pins">
              <span></span><span></span><span></span><span></span>
            </div>
            <div class="fg-socket-notch"></div>
          </div>
          <div class="fg-port-number bottom" style="color:var(--text-muted); font-size:0.58rem;">RJ45</div>
        </div>

        <!-- MGMT Port Column -->
        <?php if (!empty($mgmtIf)): ?>
          <div class="fg-port-column">
            <div class="fg-port-number top" style="font-size:0.62rem;">MGMT</div>
            <?php $renderRj45Socket($mgmtIf, true); ?>
            <div class="fg-port-number bottom" style="color:var(--text-muted); font-size:0.58rem;">OOB</div>
          </div>
        <?php endif; ?>

        <!-- WAN 1 Port Column -->
        <?php if (!empty($wanIf)): ?>
          <div class="fg-port-column">
            <div class="fg-port-number top" style="color:var(--color-cyan); font-size:0.62rem;">WAN 1</div>
            <?php $renderRj45Socket($wanIf, true); ?>
            <div class="fg-port-number bottom" style="color:var(--text-muted); font-size:0.58rem;">1G</div>
          </div>
        <?php endif; ?>

        <!-- WAN 2 Port Column (Redundant WAN Uplink) -->
        <div class="fg-port-column" title="WAN 2 Secondary Uplink">
          <div class="fg-port-number top" style="color:var(--color-cyan); font-size:0.62rem;">WAN 2</div>
          <div class="port-card fg-rj45-socket is-down" style="opacity:0.65; cursor:pointer;"
               id="port_card_eth18" data-device="eth18" data-name="WAN 2" data-zone="WAN" data-status="down" data-speed="1000" data-ip="—" data-mac="00:1a:2b:3c:4d:18" data-mtu="1500" data-rx="0.0" data-tx="0.0"
               onclick="selectChassisPort('eth18')">
            <div class="fg-socket-leds">
              <span class="fg-socket-led left"></span>
              <span class="fg-socket-led right"></span>
            </div>
            <div class="fg-socket-pins">
              <span></span><span></span><span></span><span></span>
            </div>
            <div class="fg-socket-notch"></div>
          </div>
          <div class="fg-port-number bottom" style="color:var(--text-muted); font-size:0.58rem;">1G</div>
        </div>
      </div>
    </div>

    <!-- Vertical Bay Divider -->
    <div class="chassis-bay-divider"></div>

    <!-- BAY 2: The 16-Port Switch Matrix (Ports 1 to 16 arranged in 8 columns - EXACTLY MATCHING USER'S IMAGE 2!) -->
    <div class="chassis-bay-section bay-internal-16">
      <div class="chassis-section-label">INTERNAL SWITCH MATRIX (1 &ndash; 16)</div>
      <div class="fg-16port-grid">
        <?php for ($col = 1; $col <= 8; $col++): 
          $oddNum  = ($col * 2) - 1; // 1, 3, 5, 7, 9, 11, 13, 15
          $evenNum = $col * 2;       // 2, 4, 6, 8, 10, 12, 14, 16
          $oddPort  = $ifByPort[$oddNum]  ?? ['device' => 'port' . $oddNum,  'name' => 'Port ' . $oddNum,  'status' => 'down', 'zone' => 'INTERNAL', 'ip' => '—', 'mac' => '00:1a:2b:3c:4d:' . sprintf('%02x', $oddNum),  'speed' => '1000', 'mtu' => 1500, 'rx_mbps' => 0.0, 'tx_mbps' => 0.0];
          $evenPort = $ifByPort[$evenNum] ?? ['device' => 'port' . $evenNum, 'name' => 'Port ' . $evenNum, 'status' => 'down', 'zone' => 'INTERNAL', 'ip' => '—', 'mac' => '00:1a:2b:3c:4d:' . sprintf('%02x', $evenNum), 'speed' => '1000', 'mtu' => 1500, 'rx_mbps' => 0.0, 'tx_mbps' => 0.0];
        ?>
          <div class="fg-port-column">
            <div class="fg-port-number top"><?= $oddNum ?></div>
            <?php $renderRj45Socket($oddPort, true); ?>
            <?php $renderRj45Socket($evenPort, false); ?>
            <div class="fg-port-number bottom"><?= $evenNum ?></div>
          </div>
        <?php endfor; ?>
      </div>
    </div>

    <!-- Vertical Bay Divider -->
    <div class="chassis-bay-divider"></div>

    <!-- BAY 3: 10G Optical Uplinks (SFP+ 1 & SFP+ 2) -->
    <div class="chassis-bay-section bay-sfp">
      <div class="chassis-section-label">10G SFP+ FIBER</div>
      <div class="sfp-cages-row">
        <?php if (!empty($sfp1If)): ?>
          <?php $renderSfpSocket($sfp1If, 'SFP+ 1'); ?>
        <?php endif; ?>
        <?php if (!empty($sfp2If)): ?>
          <?php $renderSfpSocket($sfp2If, 'SFP+ 2'); ?>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /chassis-bays-wrapper -->

  <!-- Live Selected Port Telemetry & Quick Action Bar -->
  <div class="chassis-hud-bar">
    <div class="chassis-hud-left">
      <span class="hud-status-orb" id="hudOrb"></span>
      <div>
        <div style="display:flex; align-items:center; gap:8px;">
          <strong id="hudTitle"><?= esc($wanIf['name'] ?? 'WAN 1') ?> (<?= esc($wanIf['device'] ?? 'eth0') ?>)</strong>
          <span class="badge badge-cyan" id="hudZoneBadge"><?= esc($wanIf['zone'] ?? 'WAN') ?> ZONE</span>
        </div>
        <div id="hudIpVal"><?= esc($wanIf['ip'] ?? '198.51.100.25') ?>/<?= esc($wanIf['mask'] ?? '24') ?> &bull; MAC: <?= esc($wanIf['mac'] ?? '00:1a:2b:3c:4d:01') ?></div>
      </div>
    </div>

    <!-- Live Bandwidth Rates for Selected Port -->
    <div class="chassis-hud-rates">
      <div>
        <span class="hud-rate-label">Inbound (Rx)</span>
        <div class="hud-rate-val green" id="hudRxRate"><?= number_format($wanIf['rx_mbps'] ?? 41.2, 1) ?> <span class="unit">Mbps</span></div>
      </div>
      <div>
        <span class="hud-rate-label">Outbound (Tx)</span>
        <div class="hud-rate-val cyan" id="hudTxRate"><?= number_format($wanIf['tx_mbps'] ?? 14.5, 1) ?> <span class="unit">Mbps</span></div>
      </div>
      <div>
        <span class="hud-rate-label">Carrier Link</span>
        <div class="hud-rate-val text-primary" id="hudSpeedVal"><?= esc($wanIf['speed'] ?? '1000') ?> Mbps Full-Duplex</div>
      </div>
    </div>

    <!-- Quick Physical Port Actions -->
    <div class="chassis-hud-actions">
      <button type="button" class="btn btn-primary btn-sm" onclick="openSelectedPortInspector()" style="display:inline-flex; align-items:center; gap:5px; font-size:0.72rem;">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
        </svg>
        <span>Packet Sniffer (tcpdump)</span>
      </button>
      <button type="button" class="btn btn-ghost btn-sm" onclick="toggleSelectedPortLink()" style="font-size:0.72rem;">
        ⚡ Toggle Link
      </button>
      <a href="<?= base_url('interfaces') ?>" class="btn btn-ghost btn-sm" style="font-size:0.72rem;">
        Configure &rarr;
      </a>
    </div>
  </div>

  <!-- Bottom Virtual Adapters Rail -->
  <div class="fg-chassis-subrail" style="margin-top:12px; padding-top:10px; border-top:1px dashed var(--color-border); display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
    <span class="fg-subrail-title" style="font-size:0.65rem; font-weight:800; font-family:var(--font-mono); color:var(--text-muted); text-transform:uppercase;">VIRTUAL TUNNELS:</span>
    <span class="fg-subrail-pill" title="WireGuard VPN Tunnel"><span style="display:inline-block; width:6px; height:6px; border-radius:50%; background:#10b981;"></span><strong>wg0</strong> &bull; 10.10.0.1/24</span>
    <span class="fg-subrail-pill" title="LACP Link Aggregation Group"><span style="display:inline-block; width:6px; height:6px; border-radius:50%; background:#00d4ff;"></span><strong>bond0</strong> &bull; Trunk (802.3ad)</span>
    <span class="fg-subrail-pill" title="802.1Q Segmented Guest Network"><span style="display:inline-block; width:6px; height:6px; border-radius:50%; background:#a855f7;"></span><strong>vlan10@eth1</strong> &bull; 192.168.10.1/24</span>
  </div>
</div>

<!-- ============================================================
     3. Dynamic Modular Card Grid (pfSense / FortiGate Style)
     ============================================================ -->
<div class="dash-masonry-grid" id="dashMasonryGrid" data-cols="<?= esc($userLayout['columns'] ?? 3) ?>">

  <!-- CARD 1: Resource Telemetry Gauges -->
  <div class="dash-card" id="card_widget_gauges" draggable="true">
    <div class="dash-card-header">
      <div class="dash-card-title-group">
        <span class="drag-handle" title="Drag to rearrange">&#x22EE;&#x22EE;</span>
        <span class="dash-card-title">
          <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
            <rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/>
          </svg>
          Resource Telemetry Gauges
        </span>
      </div>
      <div class="dash-card-actions">
        <button type="button" class="card-btn" onclick="refreshCard('widget_gauges')" title="Refresh">&#x21BB;</button>
        <button type="button" class="card-btn btn-remove" onclick="removeCard('card_widget_gauges')" title="Remove Widget">&#x2715;</button>
      </div>
    </div>
    <div class="dash-card-body">
      <!-- CPU -->
      <div style="margin-bottom:14px;">
        <div style="display:flex; justify-content:space-between; font-size:0.75rem; margin-bottom:4px;">
          <span style="font-weight:700; color:var(--text-secondary);">CPU Utilization</span>
          <span style="font-family:var(--font-mono); font-weight:800; color:var(--color-cyan);" id="val_cpu_pct"><?= $cpuPct ?>%</span>
        </div>
        <div style="height:6px; background:var(--color-surface-3); border-radius:99px; overflow:hidden;">
          <div id="bar_cpu" style="height:100%; width:<?= $cpuPct ?>%; background:linear-gradient(90deg, #0096c7, var(--color-cyan)); border-radius:99px; transition:width 0.5s ease;"></div>
        </div>
        <div style="font-size:0.68rem; color:var(--text-muted); margin-top:3px;"><?= $cpuCores ?> Cores &bull; Temp: <?= $temperature ?>°C</div>
      </div>

      <!-- RAM -->
      <div style="margin-bottom:14px;">
        <div style="display:flex; justify-content:space-between; font-size:0.75rem; margin-bottom:4px;">
          <span style="font-weight:700; color:var(--text-secondary);">Memory (RAM)</span>
          <span style="font-family:var(--font-mono); font-weight:800; color:var(--color-emerald);" id="val_mem_pct"><?= $memPct ?>%</span>
        </div>
        <div style="height:6px; background:var(--color-surface-3); border-radius:99px; overflow:hidden;">
          <div id="bar_mem" style="height:100%; width:<?= $memPct ?>%; background:linear-gradient(90deg, #059669, var(--color-emerald)); border-radius:99px; transition:width 0.5s ease;"></div>
        </div>
        <div style="font-size:0.68rem; color:var(--text-muted); margin-top:3px;"><?= number_format($memUsed) ?> MB / <?= number_format($memTotal) ?> MB</div>
      </div>

      <!-- State Table -->
      <div>
        <div style="display:flex; justify-content:space-between; font-size:0.75rem; margin-bottom:4px;">
          <span style="font-weight:700; color:var(--text-secondary);">State Table</span>
          <span style="font-family:var(--font-mono); font-weight:800; color:var(--color-purple);" id="val_states_cnt"><?= number_format($statesActive) ?></span>
        </div>
        <div style="height:6px; background:var(--color-surface-3); border-radius:99px; overflow:hidden;">
          <div id="bar_states" style="height:100%; width:<?= max(2, $statesPct) ?>%; background:linear-gradient(90deg, #6d28d9, var(--color-purple)); border-radius:99px; transition:width 0.5s ease;"></div>
        </div>
        <div style="font-size:0.68rem; color:var(--text-muted); margin-top:3px;"><?= $statesPct ?>% of <?= number_format($statesMax) ?> max states</div>
      </div>
    </div>
  </div>

  <!-- CARD 2: Real-Time Traffic & Bandwidth Graph -->
  <div class="dash-card card-span-2" id="card_widget_traffic" draggable="true">
    <div class="dash-card-header">
      <div class="dash-card-title-group">
        <span class="drag-handle" title="Drag to rearrange">&#x22EE;&#x22EE;</span>
        <span class="dash-card-title">
          <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"/>
          </svg>
          Real-Time Traffic Graph (Live SVG Canvas)
        </span>
      </div>
      <div class="dash-card-actions">
        <select id="trafficIfaceSelect" class="form-control" style="height:26px; padding:2px 6px; font-size:0.72rem; width:auto;" onchange="changeTrafficIface(this.value)">
          <?php foreach ($interfaces as $if): ?>
            <option value="<?= esc($if['id']) ?>"><?= esc($if['name']) ?> (<?= esc($if['device']) ?>)</option>
          <?php endforeach; ?>
        </select>
        <button type="button" class="card-btn btn-remove" onclick="removeCard('card_widget_traffic')" title="Remove Widget">&#x2715;</button>
      </div>
    </div>
    <div class="dash-card-body" style="padding:14px 18px;">
      <div class="dash-chart-container">
        <svg id="trafficSvgChart" viewBox="0 0 600 160" preserveAspectRatio="none" style="width:100%; height:100%; display:block;">
          <defs>
            <linearGradient id="gradIn" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stop-color="#10b981" stop-opacity="0.45"/>
              <stop offset="100%" stop-color="#10b981" stop-opacity="0.0"/>
            </linearGradient>
            <linearGradient id="gradOut" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stop-color="var(--color-cyan)" stop-opacity="0.4"/>
              <stop offset="100%" stop-color="var(--color-cyan)" stop-opacity="0.0"/>
            </linearGradient>
          </defs>
          <line x1="0" y1="40" x2="600" y2="40" stroke="var(--color-border)" stroke-width="1" stroke-dasharray="4,4"/>
          <line x1="0" y1="80" x2="600" y2="80" stroke="var(--color-border)" stroke-width="1" stroke-dasharray="4,4"/>
          <line x1="0" y1="120" x2="600" y2="120" stroke="var(--color-border)" stroke-width="1" stroke-dasharray="4,4"/>
          <path id="svgAreaIn" d="" fill="url(#gradIn)"/>
          <path id="svgAreaOut" d="" fill="url(#gradOut)"/>
          <path id="svgLineIn" d="" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round"/>
          <path id="svgLineOut" d="" fill="none" stroke="var(--color-cyan)" stroke-width="2.5" stroke-linecap="round"/>
        </svg>
      </div>

      <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:10px; margin-top:12px;">
        <div class="dash-stat-box">
          <div style="font-size:0.65rem; color:var(--color-emerald); font-weight:700;">Inbound (Rx)</div>
          <div style="font-size:1.1rem; font-weight:800; font-family:var(--font-mono); color:var(--text-primary);" id="stat_in_mbps">38.4 <span style="font-size:0.7rem; font-weight:500;">Mbps</span></div>
        </div>
        <div class="dash-stat-box">
          <div style="font-size:0.65rem; color:var(--color-cyan); font-weight:700;">Outbound (Tx)</div>
          <div style="font-size:1.1rem; font-weight:800; font-family:var(--font-mono); color:var(--text-primary);" id="stat_out_mbps">14.2 <span style="font-size:0.7rem; font-weight:500;">Mbps</span></div>
        </div>
        <div class="dash-stat-box">
          <div style="font-size:0.65rem; color:var(--text-muted); font-weight:700;">Peak Bandwidth</div>
          <div style="font-size:1.1rem; font-weight:800; font-family:var(--font-mono); color:var(--text-primary);" id="stat_peak">68.5 <span style="font-size:0.7rem; font-weight:500;">Mbps</span></div>
        </div>
      </div>
    </div>
  </div>

  <!-- CARD 3: Network Interfaces Matrix -->
  <div class="dash-card card-span-2" id="card_widget_interfaces" draggable="true">
    <div class="dash-card-header">
      <div class="dash-card-title-group">
        <span class="drag-handle" title="Drag to rearrange">&#x22EE;&#x22EE;</span>
        <span class="dash-card-title">
          <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M12 5l7 7-7 7"/>
          </svg>
          Interfaces Status &amp; Link Details
        </span>
      </div>
      <div class="dash-card-actions">
        <a href="<?= base_url('interfaces') ?>" class="card-btn" title="Configure Interfaces">&rarr;</a>
        <button type="button" class="card-btn btn-remove" onclick="removeCard('card_widget_interfaces')" title="Remove Widget">&#x2715;</button>
      </div>
    </div>
    <div class="dash-card-body" style="padding:0; overflow-x:auto;">
      <table class="iface-table" style="width:100%; font-size:0.8rem;">
        <thead>
          <tr style="background:var(--color-surface-2); border-bottom:1px solid var(--color-border); text-align:left; font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.04em;">
            <th style="padding:8px 14px;">Interface</th>
            <th style="padding:8px 14px;">Link</th>
            <th style="padding:8px 14px;">IP Address</th>
            <th style="padding:8px 14px;">MAC / MTU</th>
            <th style="padding:8px 14px;">Live Rate</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($interfaces as $if): ?>
            <tr style="border-bottom:1px solid var(--color-border);">
              <td style="padding:10px 14px;">
                <strong><?= esc($if['name']) ?></strong>
                <div style="font-size:0.7rem; color:var(--color-cyan); font-family:var(--font-mono);"><?= esc($if['device']) ?></div>
              </td>
              <td style="padding:10px 14px;">
                <span class="badge <?= $if['status'] === 'up' ? 'badge-success' : 'badge-danger' ?>" style="font-size:0.65rem;">
                  <?= strtoupper($if['status']) ?>
                </span>
              </td>
              <td style="padding:10px 14px; font-family:var(--font-mono); font-weight:700;">
                <?= esc($if['ip']) ?>
              </td>
              <td style="padding:10px 14px; font-size:0.72rem; font-family:var(--font-mono); color:var(--text-muted);">
                <?= esc($if['mac']) ?>
              </td>
              <td style="padding:10px 14px; font-family:var(--font-mono); font-size:0.75rem;">
                <span style="color:var(--color-emerald);">&darr; <?= number_format($if['rx_mbps'], 1) ?>M</span>
                <span style="color:var(--color-cyan); margin-left:6px;">&uarr; <?= number_format($if['tx_mbps'], 1) ?>M</span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- CARD 4: WAN & Gateway Quality -->
  <div class="dash-card" id="card_widget_gateways" draggable="true">
    <div class="dash-card-header">
      <div class="dash-card-title-group">
        <span class="drag-handle" title="Drag to rearrange">&#x22EE;&#x22EE;</span>
        <span class="dash-card-title">
          <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064"/>
          </svg>
          WAN &amp; Gateway Quality
        </span>
      </div>
      <div class="dash-card-actions">
        <button type="button" class="card-btn btn-remove" onclick="removeCard('card_widget_gateways')" title="Remove Widget">&#x2715;</button>
      </div>
    </div>
    <div class="dash-card-body" style="padding:12px 16px;">
      <div style="display:flex; flex-direction:column; gap:10px;">
        <?php foreach ($gateways as $gw): ?>
          <div class="dash-stat-box">
            <div style="display:flex; justify-content:space-between; align-items:center;">
              <strong style="font-size:0.82rem; color:var(--text-primary);"><?= esc($gw['name']) ?></strong>
              <span class="badge badge-success" style="font-size:0.62rem;">ONLINE</span>
            </div>
            <div style="font-size:0.7rem; color:var(--text-muted); font-family:var(--font-mono); margin-top:2px;">
              <?= esc($gw['ip']) ?> &bull; <?= esc($gw['iface']) ?>
            </div>
            <div style="display:flex; justify-content:space-between; margin-top:8px; font-family:var(--font-mono); font-size:0.78rem;">
              <span style="color:var(--color-emerald); font-weight:800;"><?= esc($gw['rtt']) ?> ms RTT</span>
              <span style="color:var(--text-muted);">Jitter: <?= esc($gw['rttsd']) ?>ms</span>
              <span style="color:var(--color-emerald); font-weight:700;"><?= esc($gw['loss']) ?>% Loss</span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- CARD 5: Firewall Live Filter Log -->
  <div class="dash-card card-span-2" id="card_widget_filter_log" draggable="true">
    <div class="dash-card-header">
      <div class="dash-card-title-group">
        <span class="drag-handle" title="Drag to rearrange">&#x22EE;&#x22EE;</span>
        <span class="dash-card-title">
          <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 10 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"/>
          </svg>
          Firewall Live Filter Log Stream
        </span>
      </div>
      <div class="dash-card-actions">
        <a href="<?= base_url('diag/logs') ?>" class="card-btn" title="Full Logs">&rarr;</a>
        <button type="button" class="card-btn btn-remove" onclick="removeCard('card_widget_filter_log')" title="Remove Widget">&#x2715;</button>
      </div>
    </div>
    <div class="dash-card-body" style="padding:0; overflow-x:auto;">
      <table style="width:100%; font-size:0.75rem; border-collapse:collapse;">
        <thead>
          <tr style="background:var(--color-surface-2); border-bottom:1px solid var(--color-border); text-align:left; font-size:0.68rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.04em;">
            <th style="padding:8px 12px;">Action</th>
            <th style="padding:8px 12px;">Time</th>
            <th style="padding:8px 12px;">Iface</th>
            <th style="padding:8px 12px;">Source &rarr; Destination</th>
            <th style="padding:8px 12px;">Rule</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentLogs as $log): ?>
            <tr style="border-bottom:1px solid var(--color-border);">
              <td style="padding:8px 12px;">
                <span class="badge <?= $log['action'] === 'pass' ? 'badge-success' : 'badge-danger' ?>" style="font-size:0.62rem; padding:1px 5px;">
                  <?= strtoupper($log['action']) ?>
                </span>
              </td>
              <td style="padding:8px 12px; font-family:var(--font-mono); color:var(--text-muted);"><?= esc($log['time']) ?></td>
              <td style="padding:8px 12px; font-family:var(--font-mono); color:var(--color-cyan);"><?= esc($log['iface']) ?></td>
              <td style="padding:8px 12px; font-family:var(--font-mono);">
                <?= esc($log['src']) ?> &rarr; <span style="color:var(--color-emerald);"><?= esc($log['dst']) ?></span>
              </td>
              <td style="padding:8px 12px; color:var(--text-muted);"><?= esc($log['desc']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- CARD 6: Core Services Watchdog -->
  <div class="dash-card" id="card_widget_services" draggable="true">
    <div class="dash-card-header">
      <div class="dash-card-title-group">
        <span class="drag-handle" title="Drag to rearrange">&#x22EE;&#x22EE;</span>
        <span class="dash-card-title">
          <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
          </svg>
          Services Watchdog
        </span>
        <span class="badge badge-success" style="font-size:0.6rem; padding:1px 6px; font-weight:700;">5/5 ACTIVE</span>
      </div>
      <div class="dash-card-actions">
        <button type="button" class="card-btn" onclick="refreshServicesWatchdog()" title="Refresh Watchdog Status">&#x21BB;</button>
        <button type="button" class="card-btn btn-remove" onclick="removeCard('card_widget_services')" title="Remove Widget">&#x2715;</button>
      </div>
    </div>
    <div class="dash-card-body" style="padding:12px 16px;">
      <div class="service-list">
        <?php foreach (array_slice($services, 0, 5) as $srv): 
          $isRunning = ($srv['status'] ?? '') === 'running';
        ?>
          <div class="service-row" id="row_srv_<?= esc($srv['name']) ?>">
            <div class="service-info">
              <span class="service-status-dot <?= $isRunning ? 'running' : 'stopped' ?>"></span>
              <div class="service-text">
                <div class="service-title"><?= esc($srv['title']) ?></div>
                <div class="service-sub" title="<?= esc($srv['desc'] ?? '') ?>">
                  <?= esc($srv['desc'] ?? 'System daemon') ?>
                </div>
              </div>
            </div>
            <div class="service-actions-cell">
              <span class="badge <?= $isRunning ? 'badge-success' : 'badge-danger' ?>" style="font-size:0.6rem; font-family:var(--font-mono); font-weight:700; padding:2px 6px;">
                <?= strtoupper($srv['status'] ?? 'RUNNING') ?>
              </span>
              <button type="button" class="service-restart-btn" onclick="triggerServiceAction('<?= esc($srv['name']) ?>', 'restart')" title="Restart <?= esc($srv['title']) ?>">
                <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="color:var(--color-cyan);">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                <span>Restart</span>
              </button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div style="display:flex; justify-content:space-between; align-items:center; margin-top:12px; padding-top:10px; border-top:1px solid var(--color-border); font-size:0.7rem; color:var(--text-muted);">
        <span>Auto-failover: <strong style="color:var(--color-emerald);">Enabled</strong></span>
        <a href="<?= base_url('status/services') ?>" style="color:var(--color-cyan); text-decoration:none; font-weight:600;">Full Service Manager &rarr;</a>
      </div>
    </div>
  </div>

  <!-- CARD 7: System Information & Appliance Hardware -->
  <div class="dash-card card-span-2" id="card_widget_sysinfo" draggable="true">
    <div class="dash-card-header">
      <div class="dash-card-title-group">
        <span class="drag-handle" title="Drag to rearrange">&#x22EE;&#x22EE;</span>
        <span class="dash-card-title">
          <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          System Information &amp; Hardware
        </span>
      </div>
      <div class="dash-card-actions">
        <a href="<?= base_url('system') ?>" class="card-btn" title="Settings">&rarr;</a>
        <button type="button" class="card-btn btn-remove" onclick="removeCard('card_widget_sysinfo')" title="Remove Widget">&#x2715;</button>
      </div>
    </div>
    <div class="dash-card-body">
      <table class="spec-table" style="width:100%; font-size:0.8rem;">
        <tr>
          <td style="color:var(--text-muted); width:35%;">Appliance Model</td>
          <td style="font-family:var(--font-mono); font-weight:700; color:var(--text-primary);">Micron OS Enterprise Security Appliance XG-8000</td>
        </tr>
        <tr>
          <td style="color:var(--text-muted);">OS Version</td>
          <td style="font-family:var(--font-mono); color:var(--color-cyan);">Micron OS v<?= esc(\App\Libraries\AppVersion::get()) ?></td>
        </tr>
        <tr>
          <td style="color:var(--text-muted);">CPU Processor</td>
          <td style="font-family:var(--font-mono); color:var(--text-primary);"><?= esc($cpuModel) ?></td>
        </tr>
        <tr>
          <td style="color:var(--text-muted);">Kernel Release</td>
          <td style="font-family:var(--font-mono); color:var(--text-secondary);"><?= esc($systemInfo['kernel'] ?? 'Linux 6.8.0-micron-hardened') ?></td>
        </tr>
        <tr>
          <td style="color:var(--text-muted);">Last Rollback</td>
          <td style="font-size:0.75rem;">
            <?php if ($lastRollback === null): ?>
              <span style="color:var(--text-secondary);">No configuration rollback has been recorded</span>
            <?php else: ?>
              <span style="color:var(--color-amber); font-weight:700;">
                <?= esc($lastRollback['at'] !== '' ? $lastRollback['at'] : 'time not recorded') ?>
              </span>
              <?php if ($lastRollback['ago'] !== ''): ?>
                <span style="color:var(--text-muted);">(<?= esc($lastRollback['ago']) ?>)</span>
              <?php endif; ?>
              <span style="color:var(--text-secondary);">by <?= esc($lastRollback['admin_user']) ?></span>
              <?php if (! $lastRollback['exact']): ?>
                <div style="color:var(--text-muted); font-size:0.68rem; margin-top:2px;">
                  The rollback itself was not timestamped; this is when the discarded configuration was applied.
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      </table>
    </div>
  </div>

  <!-- CARD 8: WireGuard & OpenVPN Gateway -->
  <div class="dash-card" id="card_widget_vpn" draggable="true">
    <div class="dash-card-header">
      <div class="dash-card-title-group">
        <span class="drag-handle" title="Drag to rearrange">&#x22EE;&#x22EE;</span>
        <span class="dash-card-title">
          <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
          </svg>
          VPN Gateways &amp; Tunnels
        </span>
      </div>
      <div class="dash-card-actions">
        <a href="<?= base_url('vpn/wireguard') ?>" class="card-btn" title="VPN Config">&rarr;</a>
        <button type="button" class="card-btn btn-remove" onclick="removeCard('card_widget_vpn')" title="Remove Widget">&#x2715;</button>
      </div>
    </div>
    <div class="dash-card-body" style="padding:14px 18px;">
      <div style="display:flex; flex-direction:column; gap:10px;">
        <div class="dash-stat-box">
          <div style="display:flex; justify-content:space-between; align-items:center;">
            <strong style="font-size:0.8rem; color:var(--text-primary);">WireGuard (wg0)</strong>
            <span class="badge badge-success" style="font-size:0.62rem;">ONLINE</span>
          </div>
          <div style="font-size:0.72rem; color:var(--text-muted); margin-top:4px;">
            Peers: <strong style="color:var(--color-cyan);"><?= $wgPeersCount ?></strong> active &bull; Listen: 51820/UDP
          </div>
        </div>

        <div class="dash-stat-box">
          <div style="display:flex; justify-content:space-between; align-items:center;">
            <strong style="font-size:0.8rem; color:var(--text-primary);">OpenVPN Server</strong>
            <span class="badge badge-success" style="font-size:0.62rem;">RUNNING</span>
          </div>
          <div style="font-size:0.72rem; color:var(--text-muted); margin-top:4px;">
            Port: 1194/UDP &bull; TLS 1.3 &bull; 2FA TOTP Active
          </div>
        </div>
      </div>
    </div>
  </div>

</div><!-- /dash-masonry-grid -->

<!-- ============================================================
     4. Hover Heads-Up Display (HUD) Tooltip Element
     ============================================================ -->
<div id="portHudTooltip">
  <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--color-border); padding-bottom:6px; margin-bottom:8px;">
    <strong style="color:var(--text-primary); font-size:0.85rem;" id="hudPortName">WAN 1 (eth0)</strong>
    <span class="badge badge-cyan" id="hudPortZone" style="font-size:0.65rem;">WAN</span>
  </div>
  <table style="width:100%; font-size:0.72rem; line-height:1.6;">
    <tr><td style="color:var(--text-muted);">Link Status:</td><td style="font-weight:700; color:var(--color-emerald);" id="hudPortStatus">UP (Carrier Active)</td></tr>
    <tr><td style="color:var(--text-muted);">Negotiated Speed:</td><td style="font-family:var(--font-mono); color:var(--text-primary);" id="hudPortSpeed">1000 Mbps Full Duplex</td></tr>
    <tr><td style="color:var(--text-muted);">IPv4 Address:</td><td style="font-family:var(--font-mono); font-weight:700; color:var(--color-cyan);" id="hudPortIp">198.51.100.25/24</td></tr>
    <tr><td style="color:var(--text-muted);">MAC Address:</td><td style="font-family:var(--font-mono); color:var(--text-secondary);" id="hudPortMac">00:1a:2b:3c:4d:01</td></tr>
    <tr><td style="color:var(--text-muted);">MTU:</td><td style="font-family:var(--font-mono); color:var(--text-secondary);" id="hudPortMtu">1500</td></tr>
    <tr><td style="color:var(--text-muted);">Throughput Rate:</td><td style="font-family:var(--font-mono); font-weight:700; color:var(--color-emerald);" id="hudPortRate">&darr; 38.5M &bull; &uarr; 14.2M</td></tr>
  </table>
  <div style="margin-top:8px; font-size:0.65rem; color:var(--text-muted); border-top:1px dashed var(--color-border); padding-top:6px;">
    Click to open Port Inspector Drawer &bull; Toggle Up/Down
  </div>
</div>

<!-- ============================================================
     5. Port Inspector Action Drawer Modal
     ============================================================ -->
<div class="modal fade" id="portInspectorModal" tabindex="-1" style="display:none; position:fixed; z-index:10000; inset:0; background:rgba(0,0,0,0.7); backdrop-filter:blur(6px); align-items:center; justify-content:center;">
  <div style="background:var(--color-surface-2); border:1px solid var(--color-cyan); border-radius:var(--radius-lg); width:90%; max-width:540px; padding:24px; box-shadow:0 20px 50px rgba(0,0,0,0.8);">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
      <div>
        <h3 style="font-size:1.1rem; font-weight:800; color:var(--text-primary); margin:0;" id="inspPortTitle">
          Port Diagnostic Inspector: eth0
        </h3>
        <div style="font-size:0.75rem; color:var(--text-muted);" id="inspPortSub">Hardware Interface Actions</div>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closePortInspector()">✕</button>
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:18px;">
      <button type="button" class="btn btn-outline-cyan btn-sm" onclick="triggerPortAction('toggle')" id="btnPortToggle">
        Toggle Link State (UP/DOWN)
      </button>
      <button type="button" class="btn btn-outline-emerald btn-sm" onclick="triggerPortAction('pcap')">
        Live Packet Sniffer (tcpdump)
      </button>
    </div>

    <!-- Packet Stream Terminal -->
    <div class="dash-terminal" id="inspPcapTerminal">
      # Ready for port diagnostic probe...
      # Click "Live Packet Sniffer" to stream active netfilter headers.
    </div>

    <div style="display:flex; justify-content:space-between; align-items:center;">
      <a href="<?= base_url('interfaces') ?>" class="btn btn-ghost btn-sm">Configure in Interfaces &rarr;</a>
      <button type="button" class="btn btn-secondary btn-sm" onclick="closePortInspector()">Close</button>
    </div>
  </div>
</div>

<!-- ============================================================
     6. Add Widget Store Modal
     ============================================================ -->
<div class="modal fade" id="addWidgetModal" tabindex="-1" style="display:none; position:fixed; z-index:10000; inset:0; background:rgba(0,0,0,0.7); backdrop-filter:blur(6px); align-items:center; justify-content:center;">
  <div style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-lg); width:90%; max-width:760px; padding:24px; box-shadow:0 20px 50px rgba(0,0,0,0.8);">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
      <div>
        <h3 style="font-size:1.15rem; font-weight:800; color:var(--text-primary); margin:0;">
          + Add Dashboard Widget Cards
        </h3>
        <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">
          Customize your enterprise operations dashboard (pfSense / FortiGate Style)
        </div>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeWidgetModal()">✕</button>
    </div>

    <div class="widget-catalog-grid" id="widgetCatalogGrid">
      <!-- Catalog items dynamically populated by JS -->
    </div>

    <div style="display:flex; justify-content:flex-end; margin-top:18px; border-top:1px solid var(--color-border); padding-top:14px;">
      <button type="button" class="btn btn-primary btn-sm" onclick="closeWidgetModal()">Done</button>
    </div>
  </div>
</div>

<!-- ============================================================
     7. Interactive Dashboard JavaScript Engine
     ============================================================ -->
<script>
// Server Saved Layout
const SERVER_SAVED_LAYOUT = <?= json_encode($userLayout ?? null, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

// Available Widget Catalogue
const WIDGET_CATALOG = [
  { id: 'card_widget_gauges', name: 'Resource Telemetry Gauges', cat: 'System', desc: 'Real-time CPU, RAM, and state table meters.', span: 1 },
  { id: 'card_widget_traffic', name: 'Real-Time Traffic Graph', cat: 'Network', desc: 'Dual-stream SVG bandwidth chart with peak meters.', span: 2 },
  { id: 'card_widget_interfaces', name: 'Network Interfaces Matrix', cat: 'Network', desc: 'Tabular live status, link speed, MAC and IP details.', span: 2 },
  { id: 'card_widget_gateways', name: 'WAN & Gateway Quality', cat: 'Network', desc: 'Multi-WAN ping, RTT latency, and packet loss.', span: 1 },
  { id: 'card_widget_filter_log', name: 'Firewall Live Filter Log', cat: 'Security', desc: 'Real-time scrolling log of dropped/passed flows.', span: 2 },
  { id: 'card_widget_services', name: 'Services Watchdog', cat: 'System', desc: 'Core daemons status and daemon control buttons.', span: 1 },
  { id: 'card_widget_sysinfo', name: 'System Hardware Info', cat: 'System', desc: 'Appliance model, kernel release, and hardware crypto.', span: 2 },
  { id: 'card_widget_vpn', name: 'VPN Gateways & Tunnels', cat: 'Network', desc: 'WireGuard and OpenVPN active tunnel metrics.', span: 1 },
];

let activeSelectedPort = null;
let trafficInterval = null;
let telemetryIntervalMs = 2000;

// Initialize Dashboard
document.addEventListener('DOMContentLoaded', () => {
  initDragAndDrop();
  initChassisFaceplate();

  // Apply saved dashboard structure (server preference with fallback to localStorage)
  let layoutToApply = SERVER_SAVED_LAYOUT;
  if (!layoutToApply || (!layoutToApply.cards && !layoutToApply.active_widgets)) {
    try {
      const local = localStorage.getItem('micron_dash_layout_' + (currentProfile || 'default'));
      if (local) layoutToApply = JSON.parse(local);
    } catch (e) {}
  }

  if (layoutToApply) {
    applySavedStructure(layoutToApply);
  } else {
    const savedCols = parseInt(localStorage.getItem('micron_dash_cols')) || parseInt(document.getElementById('dashMasonryGrid').getAttribute('data-cols')) || 3;
    setDashboardColumns(savedCols);
  }

  renderWidgetCatalog();

  // Sync active profile on boot if explicitly passed and no custom layout override
  const initialProfile = '<?= esc($userProfile ?? 'default') ?>';
  if (initialProfile !== 'default' && (!layoutToApply || !layoutToApply.cards)) {
    switchDashboardProfile(initialProfile);
  }

  // Register with shared request scheduler
  trafficInterval = MicronScheduler.poll('dashboard.traffic', {
    interval: telemetryIntervalMs,
    run: pollTelemetry
  });
});

// Profile Switcher (Overview, Network NOC, Security SOC)
let currentProfile = '<?= esc($userProfile ?? 'default') ?>';

const PROFILE_CONFIGS = {
  default: {
    name: 'Overview',
    description: 'Balanced enterprise telemetry and security overview',
    cards: ['card_widget_gauges', 'card_widget_traffic', 'card_widget_interfaces', 'card_widget_gateways', 'card_widget_filter_log', 'card_widget_services', 'card_widget_vpn', 'card_widget_sysinfo']
  },
  noc: {
    name: 'Network NOC',
    description: 'Bandwidth throughput, WAN gateways and link quality priority',
    cards: ['card_widget_traffic', 'card_widget_interfaces', 'card_widget_gateways', 'card_widget_vpn', 'card_widget_gauges']
  },
  soc: {
    name: 'Security SOC',
    description: 'Stateful firewall logs, threat defense, and daemon watchdogs priority',
    cards: ['card_widget_filter_log', 'card_widget_services', 'card_widget_vpn', 'card_widget_sysinfo', 'card_widget_gauges']
  }
};

function switchDashboardProfile(profile) {
  currentProfile = profile;
  const cfg = PROFILE_CONFIGS[profile] || PROFILE_CONFIGS.default;

  // 1. Update button styling
  document.querySelectorAll('.profile-btn').forEach(btn => {
    const isAct = btn.dataset.profile === profile;
    btn.classList.toggle('active', isAct);
    btn.classList.toggle('btn-primary', isAct);
    btn.classList.toggle('btn-ghost', !isAct);
  });

  // 2. Smoothly filter cards according to the selected profile
  const grid = document.getElementById('dashMasonryGrid');
  if (grid) {
    const allCards = grid.querySelectorAll('.dash-card');
    allCards.forEach(card => {
      const isIncluded = cfg.cards.includes(card.id);
      if (isIncluded) {
        card.style.display = 'flex';
        card.style.opacity = '1';
        card.style.transform = 'scale(1)';
      } else {
        card.style.opacity = '0';
        card.style.transform = 'scale(0.95)';
        setTimeout(() => {
          if (!cfg.cards.includes(card.id)) {
            card.style.display = 'none';
          }
        }, 180);
      }
    });

    // In NOC profile, make traffic graph full width / prominent
    const trafficCard = document.getElementById('card_widget_traffic');
    if (trafficCard) {
      if (profile === 'noc') {
        trafficCard.classList.add('card-span-3');
        trafficCard.classList.remove('card-span-2');
      } else {
        trafficCard.classList.remove('card-span-3');
        trafficCard.classList.add('card-span-2');
      }
    }

    // In SOC profile, make filter log full width
    const filterCard = document.getElementById('card_widget_filter_log');
    if (filterCard) {
      if (profile === 'soc') {
        filterCard.classList.add('card-span-3');
        filterCard.classList.remove('card-span-2');
      } else {
        filterCard.classList.remove('card-span-3');
        filterCard.classList.add('card-span-2');
      }
    }
  }

  // 3. Update URL without page reload
  try {
    const url = new URL(window.location.href);
    url.searchParams.set('profile', profile);
    window.history.replaceState({ profile }, '', url.toString());
  } catch (e) {}

  // 4. Re-enforce column spans
  const currentCols = parseInt(document.getElementById('dashMasonryGrid').getAttribute('data-cols')) || 3;
  setDashboardColumns(currentCols);

  renderWidgetCatalog();
}

// Column Layout Switcher (1 Col, 2 Cols, 3 Cols)
function setDashboardColumns(cols) {
  cols = parseInt(cols) || 3;
  const grid = document.getElementById('dashMasonryGrid');
  if (!grid) return;
  grid.setAttribute('data-cols', cols);

  // Update button state visually
  document.querySelectorAll('.col-btn').forEach(b => {
    const isAct = parseInt(b.dataset.cols) === cols;
    b.classList.toggle('active', isAct);
    b.classList.toggle('btn-primary', isAct);
    b.classList.toggle('btn-ghost', !isAct);
  });

  // Explicitly enforce column tracks and card spans in JavaScript
  if (cols === 1) {
    grid.style.gridTemplateColumns = '1fr';
    grid.querySelectorAll('.dash-card').forEach(c => {
      c.style.gridColumn = '1 / -1';
    });
  } else if (cols === 2) {
    grid.style.gridTemplateColumns = 'repeat(2, minmax(0, 1fr))';
    grid.querySelectorAll('.dash-card').forEach(c => {
      if (c.classList.contains('card-span-3')) {
        c.style.gridColumn = 'span 2';
      } else if (c.classList.contains('card-span-2')) {
        c.style.gridColumn = 'span 2';
      } else {
        c.style.gridColumn = 'span 1';
      }
    });
  } else {
    grid.style.gridTemplateColumns = 'repeat(3, minmax(0, 1fr))';
    grid.querySelectorAll('.dash-card').forEach(c => {
      if (c.classList.contains('card-span-3')) {
        c.style.gridColumn = 'span 3';
      } else if (c.classList.contains('card-span-2')) {
        c.style.gridColumn = 'span 2';
      } else {
        c.style.gridColumn = 'span 1';
      }
    });
  }

  localStorage.setItem('micron_dash_cols', cols);
  saveLayoutToServer();
}

// Drag & Drop Engine
function initDragAndDrop() {
  const grid = document.getElementById('dashMasonryGrid');
  const cards = grid.querySelectorAll('.dash-card');

  cards.forEach(card => {
    card.addEventListener('dragstart', handleDragStart);
    card.addEventListener('dragend', handleDragEnd);
    card.addEventListener('dragover', handleDragOver);
    card.addEventListener('drop', handleDrop);
  });
}

let draggedCard = null;

function handleDragStart(e) {
  draggedCard = this;
  this.classList.add('dragging');
  e.dataTransfer.effectAllowed = 'move';
}

function handleDragEnd() {
  this.classList.remove('dragging');
  draggedCard = null;
  saveLayoutToServer();
}

function handleDragOver(e) {
  e.preventDefault();
  e.dataTransfer.dropEffect = 'move';
  const grid = document.getElementById('dashMasonryGrid');
  const afterElement = getDragAfterElement(grid, e.clientY);
  if (draggedCard && afterElement == null) {
    grid.appendChild(draggedCard);
  } else if (draggedCard && afterElement) {
    grid.insertBefore(draggedCard, afterElement);
  }
}

function handleDrop(e) {
  e.preventDefault();
}

function getDragAfterElement(container, y) {
  const draggableElements = [...container.querySelectorAll('.dash-card:not(.dragging)')];
  return draggableElements.reduce((closest, child) => {
    const box = child.getBoundingClientRect();
    const offset = y - box.top - box.height / 2;
    if (offset < 0 && offset > closest.offset) {
      return { offset: offset, element: child };
    } else {
      return closest;
    }
  }, { offset: Number.NEGATIVE_INFINITY }).element;
}

// Remove Card
function removeCard(cardId) {
  const card = document.getElementById(cardId);
  if (!card) return;
  card.style.opacity = '0';
  card.style.transform = 'scale(0.85)';
  setTimeout(() => {
    card.style.display = 'none';
    saveLayoutToServer();
    renderWidgetCatalog();
  }, 200);
}

// Add Card Modal
function openWidgetModal() {
  renderWidgetCatalog();
  document.getElementById('addWidgetModal').style.display = 'flex';
}
function closeWidgetModal() {
  document.getElementById('addWidgetModal').style.display = 'none';
}

function renderWidgetCatalog() {
  const container = document.getElementById('widgetCatalogGrid');
  if (!container) return;
  container.innerHTML = '';

  WIDGET_CATALOG.forEach(item => {
    const cardEl = document.getElementById(item.id);
    const isVisible = cardEl && cardEl.style.display !== 'none';

    const div = document.createElement('div');
    div.className = 'widget-catalog-card';
    div.innerHTML = `
      <div>
        <div style="display:flex; justify-content:space-between; align-items:center;">
          <strong style="color:var(--text-primary); font-size:0.85rem;">${item.name}</strong>
          <span class="badge ${isVisible ? 'badge-success' : 'badge-muted'}" style="font-size:0.62rem;">
            ${isVisible ? 'ACTIVE' : 'AVAILABLE'}
          </span>
        </div>
        <div style="font-size:0.72rem; color:var(--text-muted); margin-top:4px;">${item.desc}</div>
      </div>
      <div>
        ${isVisible 
          ? `<button type="button" class="btn btn-outline-danger btn-sm" style="width:100%; font-size:0.72rem;" onclick="removeCard('${item.id}')">Remove Card</button>`
          : `<button type="button" class="btn btn-primary btn-sm" style="width:100%; font-size:0.72rem;" onclick="addCard('${item.id}')">+ Add to Dashboard</button>`
        }
      </div>
    `;
    container.appendChild(div);
  });
}

function addCard(cardId) {
  const card = document.getElementById(cardId);
  if (card) {
    card.style.display = 'flex';
    card.style.opacity = '1';
    card.style.transform = 'none';
    saveLayoutToServer();
    renderWidgetCatalog();
  }
}

// Apply Saved Layout Structure
function applySavedStructure(layout) {
  if (!layout) return;
  const grid = document.getElementById('dashMasonryGrid');
  if (!grid) return;

  // 1. Column layout
  const cols = parseInt(layout.columns) || parseInt(grid.getAttribute('data-cols')) || 3;
  setDashboardColumns(cols);

  // 2. Telemetry interval
  if (layout.refresh_interval_ms) {
    const ms = parseInt(layout.refresh_interval_ms);
    if (!isNaN(ms) && ms > 0) {
      telemetryIntervalMs = ms;
      const sel = document.getElementById('selTelemetryInterval');
      if (sel) sel.value = String(ms);
    }
  }

  // 3. Card sequence & visibility from saved cards array
  if (Array.isArray(layout.cards) && layout.cards.length > 0) {
    layout.cards.forEach(item => {
      const cardId = typeof item === 'string' ? item : item.id;
      const isVisible = typeof item === 'object' ? (item.visible !== false) : true;
      const cardEl = document.getElementById(cardId);
      if (cardEl) {
        grid.appendChild(cardEl);
        cardEl.style.display = isVisible ? 'flex' : 'none';
        cardEl.style.opacity = isVisible ? '1' : '0';
        cardEl.style.transform = 'none';
        if (typeof item === 'object' && item.col_span) {
          if (item.col_span === 3) {
            cardEl.classList.add('card-span-3');
            cardEl.classList.remove('card-span-2');
          } else if (item.col_span === 2) {
            cardEl.classList.add('card-span-2');
            cardEl.classList.remove('card-span-3');
          }
        }
      }
    });
  } else if (Array.isArray(layout.active_widgets) && layout.active_widgets.length > 0) {
    layout.active_widgets.forEach(cardId => {
      const cardEl = document.getElementById(cardId);
      if (cardEl) {
        grid.appendChild(cardEl);
        cardEl.style.display = 'flex';
      }
    });
    if (layout.widget_configs) {
      Object.keys(layout.widget_configs).forEach(cardId => {
        const conf = layout.widget_configs[cardId];
        const cardEl = document.getElementById(cardId);
        if (cardEl && conf && conf.visible === false) {
          cardEl.style.display = 'none';
        }
      });
    }
  }
}

// Save & Reset Dashboard Structure API
function saveDashboardStructure(showFeedback = true) {
  const saveBtn = document.getElementById('btnSaveDashboardStructure');
  const originalHtml = saveBtn ? saveBtn.innerHTML : '';

  if (saveBtn && showFeedback) {
    saveBtn.classList.add('is-saving');
    saveBtn.disabled = true;
    saveBtn.innerHTML = `
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="animation:spin 0.8s linear infinite;">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
      </svg>
      <span class="btn-text">Saving...</span>
    `;
  }

  const grid = document.getElementById('dashMasonryGrid');
  const cols = parseInt(grid ? grid.getAttribute('data-cols') : 3) || 3;
  const cards = grid ? [...grid.querySelectorAll('.dash-card')].map((c, idx) => ({
    id: c.id,
    order: idx + 1,
    visible: c.style.display !== 'none',
    col_span: c.classList.contains('card-span-3') ? 3 : (c.classList.contains('card-span-2') ? 2 : 1)
  })) : [];

  const activeWidgets = cards.filter(c => c.visible).map(c => c.id);

  const payload = {
    profile: currentProfile || 'default',
    columns: cols,
    refresh_interval_ms: telemetryIntervalMs,
    cards: cards,
    active_widgets: activeWidgets
  };

  try {
    localStorage.setItem('micron_dash_cols', cols);
    localStorage.setItem('micron_dash_layout_' + (currentProfile || 'default'), JSON.stringify(payload));
  } catch (e) {}

  const formData = new FormData();
  formData.append('profile', currentProfile || 'default');
  formData.append('layout', JSON.stringify(payload));
  formData.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');

  return MicronScheduler.fetch('<?= base_url('dashboard/layout/save') ?>', {
    method: 'POST',
    body: formData,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(res => res.json())
  .then(data => {
    if (saveBtn && showFeedback) {
      saveBtn.classList.remove('is-saving');
      saveBtn.classList.add('is-saved');
      saveBtn.disabled = false;
      saveBtn.innerHTML = `
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
        <span class="btn-text">Structure Saved!</span>
      `;

      if (window.showToast) {
        showToast('Dashboard structure saved successfully. Card positions and view settings preserved.', 'success');
      }

      setTimeout(() => {
        saveBtn.classList.remove('is-saved');
        saveBtn.innerHTML = originalHtml;
      }, 2500);
    }
    return data;
  })
  .catch(err => {
    console.error('[Dashboard] Error saving structure:', err);
    if (saveBtn && showFeedback) {
      saveBtn.classList.remove('is-saving');
      saveBtn.disabled = false;
      saveBtn.innerHTML = originalHtml;
      if (window.showToast) {
        showToast('Failed to save dashboard structure: ' + err, 'error');
      } else {
        alert('Failed to save dashboard structure');
      }
    }
    throw err;
  });
}

function saveLayoutToServer() {
  saveDashboardStructure(false);
}

function resetDashboardLayout() {
  if (!confirm('Reset dashboard cards to factory default layout?')) return;
  const formData = new FormData();
  formData.append('profile', currentProfile || 'default');
  formData.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');

  MicronScheduler.fetch('<?= base_url('dashboard/layout/reset') ?>', {
    method: 'POST',
    body: formData,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  }).then(() => {
    try {
      localStorage.removeItem('micron_dash_cols');
      localStorage.removeItem('micron_dash_layout_' + (currentProfile || 'default'));
    } catch (e) {}
    window.location.reload();
  });
}

// Chassis Faceplate Physical Port Selection & HUD Engine
let currentSelectedPort = '<?= esc($wanIf['device'] ?? 'eth0') ?>';

function selectChassisPort(device) {
  currentSelectedPort = device;
  activeSelectedPort = device;

  // Highlight selected port socket
  document.querySelectorAll('.port-card').forEach(p => {
    const isThis = p.dataset.device === device;
    p.classList.toggle('selected-port', isThis);
    p.classList.toggle('active-selected', isThis);
  });

  const port = document.getElementById(`port_card_${device}`);
  if (!port) return;

  const isUp = port.dataset.status === 'up';
  const name = port.dataset.name || device;
  const zone = port.dataset.zone || 'NET';
  const ip = port.dataset.ip || '—';
  const mac = port.dataset.mac || '—';
  const speed = port.dataset.speed || '1000';
  const mtu = port.dataset.mtu || '1500';
  const rx = parseFloat(port.dataset.rx || '0').toFixed(1);
  const tx = parseFloat(port.dataset.tx || '0').toFixed(1);

  // Update HUD Header
  const elTitle = document.getElementById('hudTitle');
  const elSub = document.getElementById('hudSub');
  const elZone = document.getElementById('hudZoneBadge');
  const elOrb = document.getElementById('hudOrb');

  if (elTitle) elTitle.textContent = `${name} (${device})`;
  if (elSub) elSub.textContent = `${zone} Physical Interface &bull; ${isUp ? 'Carrier Active (1000Base-T Full-Duplex)' : 'Disconnected (Standby Auto-MDIX)'}`;
  if (elZone) {
    elZone.textContent = `${zone} ZONE`;
    elZone.className = `badge ${zone === 'WAN' ? 'badge-cyan' : 'badge-success'}`;
  }
  if (elOrb) elOrb.classList.toggle('down', !isUp);

  // Update HUD Metrics Grid
  const elIp = document.getElementById('hudIpVal');
  const elSpeed = document.getElementById('hudSpeedVal');
  const elMac = document.getElementById('hudMacVal');
  const elMtu = document.getElementById('hudMtuVal');

  if (elIp) elIp.textContent = ip;
  if (elSpeed) elSpeed.textContent = isUp ? `${speed} Mbps Full-Duplex` : 'Link Down (Cable Unplugged)';
  if (elMac) elMac.textContent = mac;
  if (elMtu) elMtu.textContent = `${mtu} Bytes`;

  // Update Throughput Meters
  updateHudRates(rx, tx);
}

function updateHudRates(rx, tx) {
  const elRx = document.getElementById('hudRxRate');
  const elTx = document.getElementById('hudTxRate');
  const barRx = document.getElementById('hudRxBar');
  const barTx = document.getElementById('hudTxBar');

  if (elRx) elRx.innerHTML = `${rx} <span class="unit">Mbps</span>`;
  if (elTx) elTx.innerHTML = `${tx} <span class="unit">Mbps</span>`;

  if (barRx) barRx.style.width = `${Math.min(100, Math.max(4, (parseFloat(rx) / 100) * 100))}%`;
  if (barTx) barTx.style.width = `${Math.min(100, Math.max(4, (parseFloat(tx) / 100) * 100))}%`;
}

function openSelectedPortInspector() {
  openPortInspector(currentSelectedPort || 'eth0');
}

function toggleSelectedPortLink() {
  triggerPortAction('toggle');
}

function initChassisFaceplate() {
  const ports = document.querySelectorAll('.port-card');
  const hud = document.getElementById('portHudTooltip');

  // Initialize selected port HUD
  selectChassisPort(currentSelectedPort);

  ports.forEach(port => {
    port.addEventListener('mouseenter', (e) => {
      hud.querySelector('#hudPortName').textContent = `${port.dataset.name} (${port.dataset.device})`;
      hud.querySelector('#hudPortZone').textContent = port.dataset.zone;
      hud.querySelector('#hudPortStatus').textContent = port.dataset.status === 'up' ? 'UP (Carrier Active)' : 'DOWN (Disconnected)';
      hud.querySelector('#hudPortSpeed').textContent = `${port.dataset.speed} Mbps Full Duplex`;
      hud.querySelector('#hudPortIp').textContent = port.dataset.ip || '—';
      hud.querySelector('#hudPortMac').textContent = port.dataset.mac || '—';
      hud.querySelector('#hudPortMtu').textContent = port.dataset.mtu || '1500';
      hud.querySelector('#hudPortRate').textContent = `↓ ${port.dataset.rx}M • ↑ ${port.dataset.tx}M`;

      hud.classList.add('visible');
    });

    port.addEventListener('mousemove', (e) => {
      hud.style.left = `${e.clientX + 14}px`;
      hud.style.top = `${e.clientY + 14}px`;
    });

    port.addEventListener('mouseleave', () => {
      hud.classList.remove('visible');
    });
  });
}

// Port Action Drawer
function openPortInspector(device) {
  activeSelectedPort = device;
  const portEl = document.getElementById(`port_card_${device}`);
  document.getElementById('inspPortTitle').textContent = `Port Diagnostic Inspector: ${device}`;
  document.getElementById('inspPortSub').textContent = `${portEl ? portEl.dataset.name : ''} (${portEl ? portEl.dataset.zone : ''})`;
  document.getElementById('portInspectorModal').style.display = 'flex';
}
function closePortInspector() {
  document.getElementById('portInspectorModal').style.display = 'none';
}

function triggerPortAction(action) {
  if (!activeSelectedPort) return;
  const terminal = document.getElementById('inspPcapTerminal');
  terminal.innerHTML += `\n> Executing port action [${action}] on ${activeSelectedPort}...`;

  const formData = new FormData();
  formData.append('device', activeSelectedPort);
  formData.append('action', action);

  MicronScheduler.fetch('<?= base_url('dashboard/port-action') ?>', {
    method: 'POST',
    body: formData,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(res => res.json())
  .then(data => {
    if (data.packets) {
      terminal.innerHTML = data.packets.join('\n');
    } else {
      terminal.innerHTML += `\n✔ ${data.message || 'Action executed successfully.'}`;
    }
  })
  .catch(err => {
    terminal.innerHTML += `\n✖ Error executing action: ${err}`;
  });
}

function refreshCard(cardId) {
  const targetId = cardId.startsWith('card_') ? cardId : `card_${cardId}`;
  const card = document.getElementById(targetId);
  if (card) {
    card.style.opacity = '0.5';
    if (typeof pollTelemetry === 'function') pollTelemetry();
    setTimeout(() => { card.style.opacity = '1'; }, 300);
  }
}

function refreshServicesWatchdog() {
  const card = document.getElementById('card_widget_services');
  if (card) {
    card.style.opacity = '0.5';
    setTimeout(() => { card.style.opacity = '1'; }, 300);
  }
}

function triggerServiceAction(service, action) {
  const row = document.getElementById(`row_srv_${service}`);
  const btn = row ? row.querySelector('.service-restart-btn') : null;
  const originalBtnHtml = btn ? btn.innerHTML : '';
  
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = `
      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="animation:led-pulse 0.8s infinite linear;">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
      </svg>
      <span>Working...</span>
    `;
  }
  if (row) row.style.opacity = '0.6';

  const formData = new FormData();
  formData.append('service', service);
  formData.append('action', action);
  formData.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');

  MicronScheduler.fetch('<?= base_url('dashboard/service-toggle') ?>', {
    method: 'POST',
    body: formData,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(res => res.json())
  .then(data => {
    if (window.showToast) {
      showToast(data.message || `Service ${service} ${action} completed successfully`, 'success');
    } else {
      alert(data.message || `Service ${service} ${action} triggered`);
    }
  })
  .catch(err => {
    if (window.showToast) {
      showToast(`Failed to ${action} ${service}: ${err}`, 'error');
    } else {
      alert(`Failed to ${action} ${service}`);
    }
  })
  .finally(() => {
    if (row) row.style.opacity = '1';
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = originalBtnHtml;
    }
  });
}

// Real-Time Telemetry Polling Loop
function changeTelemetryInterval(val) {
  telemetryIntervalMs = parseInt(val);
  if (trafficInterval) {
    trafficInterval.stop();
  }
  if (telemetryIntervalMs > 0) {
    trafficInterval = MicronScheduler.poll('dashboard.traffic', {
      interval: telemetryIntervalMs,
      run: pollTelemetry
    });
  }
}

async function pollTelemetry() {
  try {
    const res = await MicronScheduler.fetch('<?= base_url('dashboard/telemetry') ?>', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    if (!res.ok) {
      return false; // report failed attempt to scheduler
    }
    const data = await res.json();
    if (!data) {
      return false; // report failed attempt to scheduler
    }

    // 1. Update Resource Gauges
    if (data.gauges) {
      const g = data.gauges;
      const elCpu = document.getElementById('val_cpu_pct');
      const barCpu = document.getElementById('bar_cpu');
      if (elCpu) elCpu.textContent = `${g.cpu_pct}%`;
      if (barCpu) barCpu.style.width = `${g.cpu_pct}%`;

      const elMem = document.getElementById('val_mem_pct');
      const barMem = document.getElementById('bar_mem');
      if (elMem) elMem.textContent = `${g.mem_pct}%`;
      if (barMem) barMem.style.width = `${g.mem_pct}%`;

      const elStates = document.getElementById('val_states_cnt');
      if (elStates) elStates.textContent = g.states.toLocaleString();
    }

    // 2. Update Physical Port Rates & Sync Selected Port HUD
    if (data.ports && Array.isArray(data.ports)) {
      data.ports.forEach(p => {
        const rateRx = document.getElementById(`rate_rx_${p.device}`);
        const rateTx = document.getElementById(`rate_tx_${p.device}`);
        const portCard = document.getElementById(`port_card_${p.device}`);
        if (rateRx) rateRx.textContent = p.rx_mbps;
        if (rateTx) rateTx.textContent = p.tx_mbps;
        if (portCard) {
          portCard.dataset.rx = p.rx_mbps;
          portCard.dataset.tx = p.tx_mbps;
          if (p.device === currentSelectedPort) {
            updateHudRates(p.rx_mbps, p.tx_mbps);
          }
        }
      });
    }

    // 3. Update Live Traffic Graph
    const totalRx = (data.ports || []).reduce((acc, p) => acc + (parseFloat(p.rx_mbps) || 0), 0);
    const totalTx = (data.ports || []).reduce((acc, p) => acc + (parseFloat(p.tx_mbps) || 0), 0);
    trafficPointsIn.shift(); trafficPointsIn.push(Math.max(5, Math.min(100, totalRx || 25)));
    trafficPointsOut.shift(); trafficPointsOut.push(Math.max(2, Math.min(100, totalTx || 12)));
    renderSvgChart();

    return true;
  } catch (err) {
    console.warn('[Dashboard] Telemetry poll failed:', err);
    return false; // report failed attempt to scheduler
  }
}

// Live Traffic Graph Simulation / History
let trafficPointsIn = Array(30).fill(25);
let trafficPointsOut = Array(30).fill(12);

document.addEventListener('DOMContentLoaded', () => {
  renderSvgChart();
});

function renderSvgChart() {
  const svg = document.getElementById('trafficSvgChart');
  if (!svg) return;

  const w = 600, h = 160;
  const step = w / (trafficPointsIn.length - 1);

  const ptsIn = trafficPointsIn.map((v, i) => `${i * step},${h - (v / 100 * h)}`).join(' ');
  const ptsOut = trafficPointsOut.map((v, i) => `${i * step},${h - (v / 100 * h)}`).join(' ');

  const areaIn = `M0,${h} L${ptsIn} L${w},${h} Z`;
  const areaOut = `M0,${h} L${ptsOut} L${w},${h} Z`;

  const elAreaIn = document.getElementById('svgAreaIn');
  const elAreaOut = document.getElementById('svgAreaOut');
  const elLineIn = document.getElementById('svgLineIn');
  const elLineOut = document.getElementById('svgLineOut');

  if (elAreaIn) elAreaIn.setAttribute('d', areaIn);
  if (elAreaOut) elAreaOut.setAttribute('d', areaOut);
  if (elLineIn) elLineIn.setAttribute('d', `M${ptsIn}`);
  if (elLineOut) elLineOut.setAttribute('d', `M${ptsOut}`);
}
</script>

<?= $this->endSection() ?>
