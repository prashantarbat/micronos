<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="page-header animate-in">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
      <div style="display:flex; align-items:center; gap:8px; font-size:0.75rem; color:var(--text-muted); margin-bottom:4px;">
        <a href="<?= base_url('interfaces') ?>" style="color:var(--text-muted); text-decoration:none;">Interfaces</a>
        <span>/</span>
        <span style="color:var(--text-primary); font-weight:600;"><?= esc($iface['name']) ?></span>
      </div>
      <h1 class="page-title" style="display:flex; align-items:center; gap:10px;">
        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
        </svg>
        Configure Interface: <?= esc($iface['name']) ?>
      </h1>
      <p class="page-subtitle">Configure physical/virtual link binding, IPv4 & IPv6 addressing, and traffic parameters</p>
    </div>
    <div style="display:flex; gap:10px; align-items:center;">
      <a href="<?= base_url('interfaces') ?>" class="btn btn-ghost" style="display:inline-flex; align-items:center; gap:6px;">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Back to Interfaces
      </a>
    </div>
  </div>
</div>

<form method="POST" action="<?= base_url('interfaces/save') ?>" class="animate-in animate-in-delay-1">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= esc($iface['id']) ?>">

  <!-- ── 1. General & Hardware Binding Card ── -->
  <div class="card card-glass" style="max-width:880px; margin-bottom:24px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
      <span class="card-title" style="display:flex; align-items:center; gap:8px;">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
        </svg>
        General & Hardware Binding
      </span>
      <?php if (($iface['interface_type'] ?? '') === 'vlan'): ?>
        <span class="badge badge-purple" style="font-size:0.7rem;">802.1Q VLAN</span>
      <?php else: ?>
        <span class="badge badge-cyan" style="font-size:0.7rem;">PHYSICAL PORT</span>
      <?php endif; ?>
    </div>
    <div class="card-body" style="padding:22px;">

      <!-- Enabled Toggle -->
      <div class="form-group" style="padding:14px 18px; background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-md); margin-bottom:20px;">
        <label style="display:flex; align-items:center; gap:12px; cursor:pointer; margin:0;">
          <input type="checkbox" name="enabled" value="1" <?= ((int)($iface['enabled'] ?? 1) === 1) ? 'checked' : '' ?>
                 style="accent-color:var(--color-emerald); width:18px; height:18px; cursor:pointer;">
          <div>
            <div style="font-weight:600; font-size:0.9rem; color:var(--text-primary);">Enable Interface</div>
            <div style="font-size:0.75rem; color:var(--text-muted);">Activate physical carrier state and allow kernel packet forwarding on this interface.</div>
          </div>
        </label>
      </div>

      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:16px;">
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" for="name">Interface Identifier Name <span style="color:var(--color-red);">*</span></label>
          <input type="text" id="name" name="name" class="form-control" required
                 value="<?= esc(old('name', $iface['name'])) ?>">
          <div class="form-hint">Descriptive name for firewall rules and routing (e.g. WAN, LAN, DMZ, OPT1).</div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Kernel Device Binding</label>
          <input type="text" class="form-control" value="<?= esc($iface['physical_device']) ?>" readonly
                 style="opacity:0.75; cursor:not-allowed; font-family:var(--font-mono); background:var(--bg-tertiary);">
          <div class="form-hint">Linux netlink network device name in kernel routing table.</div>
        </div>
      </div>

      <!-- If 802.1Q VLAN, show VLAN configuration -->
      <?php if (($iface['interface_type'] ?? '') === 'vlan'): ?>
        <div style="background:rgba(168,85,247,0.06); border:1px solid rgba(168,85,247,0.3); border-radius:var(--radius-md); padding:16px; margin-bottom:16px;">
          <div style="font-size:0.82rem; font-weight:600; color:var(--color-purple); margin-bottom:10px;">
            802.1Q Virtual LAN Configuration
          </div>
          <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px;">
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" for="vlan_parent">Parent Physical Interface</label>
              <input type="text" id="vlan_parent" name="vlan_parent" class="form-control" value="<?= esc($iface['vlan_parent']) ?>" readonly
                     style="font-family:var(--font-mono); opacity:0.75; background:var(--bg-tertiary);">
              <div class="form-hint">Underlying trunk port.</div>
            </div>

            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" for="vlan_tag">VLAN Tag (1 - 4094) <span style="color:var(--color-red);">*</span></label>
              <input type="number" id="vlan_tag" name="vlan_tag" class="form-control" min="1" max="4094"
                     value="<?= esc(old('vlan_tag', $iface['vlan_tag'])) ?>" required style="font-family:var(--font-mono);">
              <div class="form-hint">802.1Q VID tag.</div>
            </div>

            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" for="vlan_priority">Priority (PCP 0-7)</label>
              <select id="vlan_priority" name="vlan_priority" class="form-control" style="font-family:var(--font-mono);">
                <?php for ($p = 0; $p <= 7; $p++): ?>
                  <option value="<?= $p ?>" <?= ((int)($iface['vlan_priority'] ?? 0) === $p) ? 'selected' : '' ?>><?= $p ?></option>
                <?php endfor; ?>
              </select>
              <div class="form-hint">802.1p priority tag.</div>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- Description -->
      <div class="form-group" style="margin-bottom:0;">
        <label class="form-label" for="description">Administrative Description / Notes</label>
        <input type="text" id="description" name="description" class="form-control"
               placeholder="Optional description (e.g. DMZ Web Tier, Secondary ISP Uplink)"
               value="<?= esc(old('description', $iface['description'] ?? '')) ?>">
      </div>

    </div>
  </div>

  <!-- ── 2. IPv4 Addressing Configuration ── -->
  <div class="card card-glass" style="max-width:880px; margin-bottom:24px;">
    <div class="card-header">
      <span class="card-title" style="display:flex; align-items:center; gap:8px;">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
        </svg>
        IPv4 Addressing Configuration
      </span>
    </div>
    <div class="card-body" style="padding:22px;">

      <div class="form-group" style="margin-bottom:18px;">
        <label class="form-label" for="ip_assignment_type">IPv4 Configuration Mode <span style="color:var(--color-red);">*</span></label>
        <select id="ip_assignment_type" name="ip_assignment_type" class="form-control" style="background:var(--color-surface-1);">
          <option value="dhcp" <?= ($iface['ip_assignment_type'] === 'dhcp') ? 'selected' : '' ?>>DHCP (Acquire address automatically from upstream)</option>
          <option value="static" <?= ($iface['ip_assignment_type'] === 'static') ? 'selected' : '' ?>>Static IPv4 (Manual IP address, subnet mask, and gateway)</option>
          <option value="pppoe" <?= ($iface['ip_assignment_type'] === 'pppoe') ? 'selected' : '' ?>>PPPoE (Point-to-Point Protocol over Ethernet dialup)</option>
          <option value="none" <?= ($iface['ip_assignment_type'] === 'none') ? 'selected' : '' ?>>None / Bridged (Unnumbered L2 Layer)</option>
        </select>
      </div>

      <!-- Static IP fields container -->
      <div id="staticIpFields" style="display:<?= ($iface['ip_assignment_type'] === 'static') ? 'block' : 'none' ?>; padding:18px; background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-md); margin-bottom:18px;">
        <div style="font-weight:600; font-size:0.85rem; color:var(--text-primary); margin-bottom:12px;">Static IPv4 Address & Gateway</div>
        <div style="display:grid; grid-template-columns: 2fr 1fr; gap:16px; margin-bottom:14px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="ipv4_address">IPv4 Address <span style="color:var(--color-red);">*</span></label>
            <input type="text" id="ipv4_address" name="ipv4_address" class="form-control"
                   placeholder="192.168.1.1" value="<?= esc(old('ipv4_address', $iface['ipv4_address'])) ?>"
                   style="font-family:var(--font-mono);">
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="ipv4_netmask">Subnet Mask (CIDR /)</label>
            <select id="ipv4_netmask" name="ipv4_netmask" class="form-control" style="font-family:var(--font-mono);">
              <?php for ($i = 32; $i >= 8; $i--): ?>
                <option value="<?= $i ?>" <?= ((int)($iface['ipv4_netmask'] ?? 24) === $i) ? 'selected' : '' ?>>/<?= $i ?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" for="ipv4_gateway">Upstream Gateway IPv4</label>
          <input type="text" id="ipv4_gateway" name="ipv4_gateway" class="form-control"
                 placeholder="e.g. 192.168.1.254 (Leave blank if internal LAN subnet)"
                 value="<?= esc(old('ipv4_gateway', $iface['ipv4_gateway'])) ?>"
                 style="font-family:var(--font-mono);">
          <div class="form-hint">Specifying an upstream gateway registers a default or transit route via this interface.</div>
        </div>
      </div>

      <!-- PPPoE client fields container -->
      <div id="pppoeFields" style="display:<?= ($iface['ip_assignment_type'] === 'pppoe') ? 'block' : 'none' ?>; padding:18px; background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-md); margin-bottom:18px;">
        <div style="font-weight:600; font-size:0.85rem; color:var(--text-primary); margin-bottom:4px;">PPPoE Dialup Credentials</div>
        <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:14px;">
          Dial the ISP access concentrator on this interface. Escalating backoff automatically protects against aggressive retry loops.
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="pppoe_username">Username <span style="color:var(--color-red);">*</span></label>
            <input type="text" id="pppoe_username" name="pppoe_username" class="form-control"
                   value="<?= esc(old('pppoe_username', $iface['pppoe_username'])) ?>" autocomplete="off"
                   style="font-family:var(--font-mono);">
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="pppoe_password">Password</label>
            <input type="password" id="pppoe_password" name="pppoe_password" class="form-control" autocomplete="new-password" spellcheck="false"
                   placeholder="<?= !empty($iface['pppoe_password']) ? '•••••••• (unchanged)' : '' ?>"
                   autocomplete="new-password" style="font-family:var(--font-mono);">
            <div class="form-hint">Leave blank to retain stored encrypted password.</div>
          </div>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="pppoe_service">Service Name (Optional)</label>
            <input type="text" id="pppoe_service" name="pppoe_service" class="form-control"
                   placeholder="Provider service name"
                   value="<?= esc(old('pppoe_service', $iface['pppoe_service'])) ?>" style="font-family:var(--font-mono);">
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="pppoe_ac_name">Access Concentrator Name (Optional)</label>
            <input type="text" id="pppoe_ac_name" name="pppoe_ac_name" class="form-control"
                   placeholder="Leave blank to accept any AC"
                   value="<?= esc(old('pppoe_ac_name', $iface['pppoe_ac_name'])) ?>" style="font-family:var(--font-mono);">
          </div>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; margin-bottom:14px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="pppoe_mtu">PPPoE MTU</label>
            <input type="number" id="pppoe_mtu" name="pppoe_mtu" class="form-control" style="font-family:var(--font-mono);"
                   value="<?= esc(old('pppoe_mtu', $iface['pppoe_mtu'] ?: 1492)) ?>" min="576" max="9000">
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="pppoe_mru">PPPoE MRU</label>
            <input type="number" id="pppoe_mru" name="pppoe_mru" class="form-control" style="font-family:var(--font-mono);"
                   value="<?= esc(old('pppoe_mru', $iface['pppoe_mru'] ?: 1492)) ?>" min="576" max="9000">
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="pppoe_idle_timeout">Idle Timeout (s)</label>
            <input type="number" id="pppoe_idle_timeout" name="pppoe_idle_timeout" class="form-control" style="font-family:var(--font-mono);"
                   value="<?= esc(old('pppoe_idle_timeout', $iface['pppoe_idle_timeout'] ?? 0)) ?>" min="0" max="86400">
          </div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label style="display:flex; align-items:center; gap:12px; cursor:pointer; margin:0;">
            <input type="checkbox" name="pppoe_default_route" value="1"
                   <?= ((int)($iface['pppoe_default_route'] ?? 1) === 1) ? 'checked' : '' ?>
                   style="accent-color:var(--color-emerald); width:18px; height:18px; cursor:pointer;">
            <div>
              <div style="font-weight:600; font-size:0.85rem; color:var(--text-primary);">Install default route via this PPPoE link</div>
              <div style="font-size:0.75rem; color:var(--text-muted);">Uncheck on backup WAN links to prevent overwriting primary routing table.</div>
            </div>
          </label>
        </div>
      </div>

    </div>
  </div>

  <!-- ── 3. IPv6 Configuration Card ── -->
  <div class="card card-glass" style="max-width:880px; margin-bottom:24px;">
    <div class="card-header">
      <span class="card-title" style="display:flex; align-items:center; gap:8px;">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        IPv6 Configuration
      </span>
    </div>
    <div class="card-body" style="padding:22px;">

      <div class="form-group" style="margin-bottom:16px;">
        <label class="form-label" for="ipv6_assignment_type">IPv6 Configuration Mode</label>
        <select id="ipv6_assignment_type" name="ipv6_assignment_type" class="form-control" onchange="toggleIpv6Fields(this.value)">
          <option value="disabled" <?= (($iface['ipv6_assignment_type'] ?? 'disabled') === 'disabled') ? 'selected' : '' ?>>Disabled</option>
          <option value="dhcp6" <?= (($iface['ipv6_assignment_type'] ?? '') === 'dhcp6') ? 'selected' : '' ?>>DHCPv6 Client</option>
          <option value="slaac" <?= (($iface['ipv6_assignment_type'] ?? '') === 'slaac') ? 'selected' : '' ?>>SLAAC (Stateless Address Autoconfiguration)</option>
          <option value="static" <?= (($iface['ipv6_assignment_type'] ?? '') === 'static') ? 'selected' : '' ?>>Static IPv6</option>
        </select>
      </div>

      <div id="staticIpv6Container" style="display:<?= (($iface['ipv6_assignment_type'] ?? '') === 'static') ? 'block' : 'none' ?>; padding:16px; background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-md);">
        <div style="display:grid; grid-template-columns: 2fr 1fr; gap:16px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="ipv6_address">Static IPv6 Address</label>
            <input type="text" id="ipv6_address" name="ipv6_address" class="form-control"
                   placeholder="2001:db8::1" value="<?= esc(old('ipv6_address', $iface['ipv6_address'] ?? '')) ?>"
                   style="font-family:var(--font-mono);">
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" for="ipv6_prefix">Prefix Length (/)</label>
            <input type="number" id="ipv6_prefix" name="ipv6_prefix" class="form-control" min="1" max="128"
                   value="<?= esc(old('ipv6_prefix', $iface['ipv6_prefix'] ?? 64)) ?>" style="font-family:var(--font-mono);">
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- ── 4. Traffic & Link Parameters Card ── -->
  <div class="card card-glass" style="max-width:880px; margin-bottom:28px;">
    <div class="card-header">
      <span class="card-title" style="display:flex; align-items:center; gap:8px;">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
        </svg>
        Traffic & Link Parameters (MSS Clamping, MAC, Speed / Duplex)
      </span>
    </div>
    <div class="card-body" style="padding:22px;">

      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:18px;">
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" for="mtu">Maximum Transmission Unit (MTU)</label>
          <input type="number" id="mtu" name="mtu" class="form-control" style="font-family:var(--font-mono);"
                 value="<?= esc(old('mtu', $iface['mtu'] ?: 1500)) ?>" min="576" max="9000">
          <div class="form-hint">Standard Ethernet is 1500. PPPoE is 1492. Jumbo frames up to 9000.</div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" for="mss_clamp">TCP MSS Clamping (Bytes)</label>
          <input type="number" id="mss_clamp" name="mss_clamp" class="form-control" style="font-family:var(--font-mono);"
                 placeholder="Leave blank for auto/disabled" value="<?= esc(old('mss_clamp', $iface['mss_clamp'] ?? '')) ?>" min="500" max="8960">
          <div class="form-hint">Clamps SYN MSS to prevent fragmentation over VPN/PPPoE (e.g. 1452).</div>
        </div>
      </div>

      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" for="mac_override">MAC Address Override / Spoofing</label>
          <input type="text" id="mac_override" name="mac_override" class="form-control" style="font-family:var(--font-mono);"
                 placeholder="xx:xx:xx:xx:xx:xx" value="<?= esc(old('mac_override', $iface['mac_override'] ?? '')) ?>" maxlength="17">
          <div class="form-hint">Overrides the hardware MAC address presented to upstream ISP.</div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" for="speed_duplex">Speed & Duplex</label>
          <select id="speed_duplex" name="speed_duplex" class="form-control" style="font-family:var(--font-mono);">
            <option value="auto" <?= (($iface['speed_duplex'] ?? 'auto') === 'auto') ? 'selected' : '' ?>>Auto-Negotiate (Default)</option>
            <option value="1000baseT-FD" <?= (($iface['speed_duplex'] ?? '') === '1000baseT-FD') ? 'selected' : '' ?>>1000 Mbps Full Duplex (1000baseT-FD)</option>
            <option value="100baseT-FD" <?= (($iface['speed_duplex'] ?? '') === '100baseT-FD') ? 'selected' : '' ?>>100 Mbps Full Duplex (100baseT-FD)</option>
            <option value="100baseT-HD" <?= (($iface['speed_duplex'] ?? '') === '100baseT-HD') ? 'selected' : '' ?>>100 Mbps Half Duplex (100baseT-HD)</option>
            <option value="10baseT-FD" <?= (($iface['speed_duplex'] ?? '') === '10baseT-FD') ? 'selected' : '' ?>>10 Mbps Full Duplex (10baseT-FD)</option>
          </select>
          <div class="form-hint">Physical PHY link negotiation parameter.</div>
        </div>
      </div>

    </div>
  </div>

  <!-- ── Bottom Action Buttons ── -->
  <div style="display:flex; justify-content:space-between; align-items:center; max-width:880px; margin-bottom:50px;">
    <div>
      <?php if (!in_array(strtolower($iface['id']), ['wan', 'lan'], true)): ?>
        <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteFromEdit()" style="display:inline-flex; align-items:center; gap:6px;">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
          </svg>
          Delete Interface
        </button>
      <?php endif; ?>
    </div>

    <div style="display:flex; gap:12px; align-items:center;">
      <a href="<?= base_url('interfaces') ?>" class="btn btn-ghost">Cancel</a>
      <button type="submit" class="btn btn-primary" style="min-width:140px; display:inline-flex; align-items:center; justify-content:center; gap:6px;">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
        Save Interface
      </button>
    </div>
  </div>
</form>

<?php if (!in_array(strtolower($iface['id']), ['wan', 'lan'], true)): ?>
  <form id="deleteFormDirect" method="POST" action="<?= base_url('interfaces/' . esc($iface['id']) . '/delete') ?>" style="display:none;">
    <?= csrf_field() ?>
  </form>
<?php endif; ?>

<script>
function syncAssignmentFields() {
  const type     = document.getElementById('ip_assignment_type').value;
  const isStatic = type === 'static';
  const isPppoe  = type === 'pppoe';

  document.getElementById('staticIpFields').style.display = isStatic ? 'block' : 'none';
  document.getElementById('pppoeFields').style.display   = isPppoe  ? 'block' : 'none';

  const ipInput = document.getElementById('ipv4_address');
  if (ipInput) {
    if (isStatic) ipInput.setAttribute('required', 'required');
    else ipInput.removeAttribute('required');
  }

  const userInput = document.getElementById('pppoe_username');
  if (userInput) {
    if (isPppoe) userInput.setAttribute('required', 'required');
    else userInput.removeAttribute('required');
  }
}

function toggleIpv6Fields(mode) {
  const isStatic = mode === 'static';
  const container = document.getElementById('staticIpv6Container');
  if (container) {
    container.style.display = isStatic ? 'block' : 'none';
  }
}

function confirmDeleteFromEdit() {
  if (confirm("Are you sure you want to delete this interface? Active firewall or NAT dependencies will be checked.")) {
    document.getElementById('deleteFormDirect').submit();
  }
}

document.getElementById('ip_assignment_type')?.addEventListener('change', syncAssignmentFields);
syncAssignmentFields();
</script>

<?= $this->endSection() ?>
