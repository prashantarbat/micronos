<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
.state-pill {
  display: inline-block;
  font-family: var(--font-mono);
  font-size: 0.72rem;
  font-weight: 700;
  padding: 2px 7px;
  border-radius: 4px;
  text-transform: uppercase;
}
.state-established { background: rgba(34,197,94,0.15); color: #86efac; border: 1px solid rgba(34,197,94,0.3); }
.state-timewait    { background: rgba(245,158,11,0.15); color: #fcd34d; border: 1px solid rgba(245,158,11,0.3); }
.state-synsent     { background: rgba(59,130,246,0.15); color: #93c5fd; border: 1px solid rgba(59,130,246,0.3); }
.state-close       { background: rgba(239,68,68,0.15);  color: #fca5a5; border: 1px solid rgba(239,68,68,0.3); }
.state-udp         { background: rgba(139,92,246,0.15); color: #c4b5fd; border: 1px solid rgba(139,92,246,0.3); }

.proto-badge {
  font-family: var(--font-mono);
  font-size: 0.7rem;
  font-weight: 700;
  padding: 1px 6px;
  border-radius: 3px;
  background: rgba(255,255,255,0.06);
}
.proto-tcp  { color: #67e8f9; }
.proto-udp  { color: #c084fc; }
.proto-icmp { color: #f472b6; }
</style>

<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <a href="<?= base_url('dashboard') ?>">Dashboard</a>
      <span class="sep">/</span>
      <span>Diagnostics</span>
      <span class="sep">/</span>
      <span class="current">Connection States</span>
    </div>
    <h1 class="page-title">
      <div class="page-title-icon tone-indigo">
        <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
        </svg>
      </div>
      Firewall Connection States
    </h1>
    <p class="page-subtitle">Real-time kernel state tracking table (Netfilter conntrack). Inspect active sessions, NAT mappings, and terminate rogue connections.</p>
  </div>
  <div class="page-header-actions">
    <!-- Export Options -->
    <a href="<?= base_url('diag/states/export-csv') ?>" class="btn btn-secondary btn-sm" title="Export all kernel states to CSV file">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
      </svg>
      Export CSV
    </a>
    <a href="<?= base_url('diag/states/export-json') ?>" class="btn btn-secondary btn-sm" title="Export all kernel states to JSON file">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
      </svg>
      Export JSON
    </a>

    <button type="button" class="btn btn-ghost btn-sm" onclick="exportFilteredStates()" title="Export currently filtered view to CSV">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
      </svg>
      Export Filtered
    </button>

    <button type="button" class="btn btn-ghost btn-sm" onclick="fetchStates()">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
      </svg>
      Refresh
    </button>

    <button type="button" class="btn btn-danger btn-sm" onclick="openKillHostModal()">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
      </svg>
      Kill States by Host
    </button>
  </div>
</div>

<!-- State Table Gauges -->
<div class="stats-grid animate-in animate-in-delay-1" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-bottom:24px;">
  <div class="stat-card cyan">
    <div class="stat-value" id="statActiveStates">1,482</div>
    <div class="stat-label">Active Connection States</div>
  </div>
  <div class="stat-card emerald">
    <div class="stat-value">262,144</div>
    <div class="stat-label">Max Table Size (conntrack_max)</div>
  </div>
  <div class="stat-card purple">
    <div class="stat-value">0.57%</div>
    <div class="stat-label">Table Utilization</div>
  </div>
  <div class="stat-card amber">
    <div class="stat-value">3,891</div>
    <div class="stat-label">Peak States (24h)</div>
  </div>
</div>

<!-- Filter Toolbar -->
<div class="card card-glass animate-in animate-in-delay-2" style="padding:14px 18px; margin-bottom:20px;">
  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <div style="display:flex; align-items:center; gap:10px; flex:1; min-width:280px;">
      <input type="text" id="stateSearch" class="form-control" placeholder="Search IP address, port, protocol, or state..." onkeyup="filterStates()" style="font-family:var(--font-mono); font-size:0.85rem;">
    </div>
    <div style="display:flex; gap:10px; align-items:center;">
      <label class="form-label" style="margin:0; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted);">Protocol:</label>
      <select id="protoFilter" class="form-control" style="width:110px; font-size:0.85rem;" onchange="filterStates()">
        <option value="">ALL</option>
        <option value="tcp">TCP</option>
        <option value="udp">UDP</option>
        <option value="icmp">ICMP</option>
      </select>

      <label class="form-label" style="margin:0 0 0 10px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted);">Auto-Refresh:</label>
      <select id="refreshRate" class="form-control" style="width:100px; font-size:0.85rem;" onchange="toggleAutoRefresh(this.value)">
        <option value="0">Off</option>
        <option value="3000" selected>3s</option>
        <option value="5000">5s</option>
        <option value="10000">10s</option>
      </select>
    </div>
  </div>
</div>

<!-- States Table -->
<div class="card card-glass animate-in animate-in-delay-3" style="margin-bottom:28px;">
  <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
    <span class="card-title">
      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
      </svg>
      Kernel Conntrack State Table
    </span>
    <span class="badge badge-cyan" id="stateCountBadge">Loading states...</span>
  </div>
  <div style="overflow-x:auto;">
    <table class="iface-table" id="statesTable">
      <thead>
        <tr>
          <th>Iface</th>
          <th>Proto</th>
          <th>Source IP : Port</th>
          <th style="text-align:center;">Dir</th>
          <th>Destination IP : Port</th>
          <th>State</th>
          <th>Packets</th>
          <th>Bytes</th>
          <th>TTL / Expiry</th>
          <th style="text-align:right;">Kill State</th>
        </tr>
      </thead>
      <tbody id="statesBody">
        <tr>
          <td colspan="10" style="text-align:center; padding:32px; color:var(--text-muted);">
            Connecting to kernel conntrack socket...
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal: Kill All States for Host -->
<div id="killHostModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center;">
  <div class="modal-dialog" style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-lg); width:100%; max-width:480px; padding:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
      <h3 style="margin:0; font-size:1rem; font-weight:700; color:var(--color-red);">Terminate All States for Host</h3>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeKillHostModal()">&times;</button>
    </div>
    <form id="killHostForm" onsubmit="handleKillHost(event)">
      <p style="font-size:0.8rem; color:var(--text-secondary); margin-bottom:14px;">
        Immediately flushes all inbound, outbound, and NAT translation states belonging to the target host IP. Existing TCP connections will be terminated with a TCP RST.
      </p>
      <div class="form-group" style="margin-bottom:18px;">
        <label class="form-label">Host IP Address</label>
        <input type="text" id="killHostIp" class="form-control" required placeholder="e.g. 192.168.1.105 or 198.51.100.2" style="font-family:var(--font-mono);">
      </div>
      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn btn-ghost" onclick="closeKillHostModal()">Cancel</button>
        <button type="submit" class="btn btn-danger">Flush Host States</button>
      </div>
    </form>
  </div>
</div>

<script>
let allStates = [];
let refreshTimer = null;

// The SVG namespace, and the cell styles the row builder uses.
const SVG_NS = 'http://www.w3.org/2000/svg';
const STYLE_MUTED   = 'font-size:0.75rem; color:var(--text-muted);';
const STYLE_CENTER  = 'text-align:center; color:var(--text-muted);';
const STYLE_ADDRESS = 'font-family:var(--font-mono); font-weight:600; color:var(--text-primary);';
const STYLE_DEST    = 'font-family:var(--font-mono); color:var(--color-cyan);';
const STYLE_COUNTER = 'font-family:var(--font-mono); font-size:0.75rem; color:var(--text-secondary);';

async function fetchStates() {
  try {
    const res = await MicronScheduler.fetch('<?= base_url('diag/states/data') ?>');
    if (!res.ok) return false;
    const data = await res.json();
    allStates = data.states || [];
    document.getElementById('statActiveStates').textContent = Number(data.total || allStates.length).toLocaleString();
    renderStates(allStates);
  } catch (err) {
    console.error('Failed to fetch states:', err);
    return false;   // the shared scheduler backs the poll off and shows the banner
  }
}

/**
 * Draws the table body for `list`.
 *
 * Every value the table shows arrives over the wire — a device name, an address,
 * a protocol, a TTL — so each one is set as a node's *text* and never as markup:
 * a state whose interface is named `<iframe src=x>` renders those characters, and
 * a quote in an address cannot break out of an attribute because no address is
 * ever written into one — the cells come from the column spec below, and a
 * column's content is a `text` (or a function of the row returning text) or a
 * `node` this page built. `MicronTable.render` refuses anything else, so there
 * is no escaping step left to forget.
 */

/**
 * The table, declared once: one entry per column, in the order they are drawn.
 *
 * A `text` column's value is set as its cell's text, a `node` column's is the
 * node the function returns, and the kill button is the latter — a listener
 * closed over its own row, which is why no address of a row is written into an
 * attribute anywhere in this table.
 */
const STATE_COLUMNS = [
  { text: (st) => st.iface || '—', style: STYLE_MUTED },
  { node: (st) => protoBadge(st.proto) },
  { text: (st) => `${st.src}:${st.sport}`, style: STYLE_ADDRESS },
  { text: () => '→', style: STYLE_CENTER },
  { text: (st) => `${st.dst}:${st.dport}`, style: STYLE_DEST },
  { node: (st) => statePill(st) },
  { text: (st) => Number(st.packets || 12).toLocaleString(), style: STYLE_COUNTER },
  { text: (st) => formatBytes(st.bytes || 2400), style: STYLE_COUNTER },
  { text: (st) => st.ttl || '119s', style: STYLE_MUTED },
  { node: (st) => killButton(st), style: 'text-align:right;' },
];

function renderStates(list) {
  const states = Array.isArray(list) ? list : [];

  document.getElementById('stateCountBadge').textContent = states.length + ' Active States';

  // The whole body — rows, or the one row that says there are none — is swapped
  // in at once, so a re-render can neither leave rows behind nor show a
  // half-built table.
  MicronTable.render(document.getElementById('statesBody'), states, {
    columns: STATE_COLUMNS,
    empty: {
      colspan: 10,
      text: 'No matching connection states found.',
      style: STYLE_CENTER + ' padding:32px;',
    },
  });
}

/** The protocol badge: a node whose class and label are set, never markup. */
function protoBadge(proto) {
  const upper = (proto || 'tcp').toUpperCase();
  let protoClass = 'proto-tcp';
  if (upper === 'UDP') protoClass = 'proto-udp';
  if (upper === 'ICMP') protoClass = 'proto-icmp';

  return MicronTable.badge(upper, 'proto-badge ' + protoClass);
}

/** The state pill, classed and labelled for the row's state. */
function statePill(st) {
  const stateUpper = (st.state || 'ESTABLISHED').toUpperCase();
  let stateClass = 'state-established';
  if (stateUpper.includes('TIME_WAIT')) stateClass = 'state-timewait';
  else if (stateUpper.includes('SYN')) stateClass = 'state-synsent';
  else if (stateUpper.includes('CLOSE')) stateClass = 'state-close';
  else if (st.proto === 'udp') stateClass = 'state-udp';

  return MicronTable.badge(stateUpper, 'state-pill ' + stateClass);
}

/** The kill button for one row, closed over that row's own addresses. */
function killButton(st) {
  return MicronTable.button('', {
    className: 'btn btn-ghost btn-sm',
    style: 'color:var(--color-red); padding:3px 7px;',
    title: 'Kill State',
    icon: trashIcon(),
    onClick: () => killSingleState(st.src, st.sport, st.dst, st.dport, st.proto),
  });
}

/** The kill button's trash-can icon, node-built like the rest of the row. */
function trashIcon() {
  const svg = document.createElementNS(SVG_NS, 'svg');
  for (const [name, value] of Object.entries({
    width: '14', height: '14', fill: 'none', viewBox: '0 0 24 24',
    stroke: 'currentColor', 'stroke-width': '2',
  })) {
    svg.setAttribute(name, value);
  }

  const path = document.createElementNS(SVG_NS, 'path');
  path.setAttribute('stroke-linecap', 'round');
  path.setAttribute('stroke-linejoin', 'round');
  path.setAttribute('d', 'M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16');
  svg.appendChild(path);

  return svg;
}

function filterStates() {
  const query = document.getElementById('stateSearch').value.toLowerCase().trim();
  const proto = document.getElementById('protoFilter').value.toLowerCase().trim();

  const filtered = allStates.filter(st => {
    const matchesProto = !proto || (st.proto && st.proto.toLowerCase() === proto);
    if (!matchesProto) return false;
    if (!query) return true;

    const rowText = `${st.iface} ${st.proto} ${st.src} ${st.sport} ${st.dst} ${st.dport} ${st.state}`.toLowerCase();
    return rowText.includes(query);
  });

  renderStates(filtered);
}

async function killSingleState(src, sport, dst, dport, proto) {
  if (!confirm(`Kill state ${src}:${sport} -> ${dst}:${dport}?`)) return;

  try {
    const formData = new FormData();
    formData.append('src', src);
    formData.append('sport', sport);
    formData.append('dst', dst);
    formData.append('dport', dport);
    formData.append('proto', proto);
    formData.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');

    const res = await MicronScheduler.fetch('<?= base_url('diag/states/kill') ?>', {
      method: 'POST',
      body: formData
    });
    const result = await res.json();
    if (result.success) {
      allStates = allStates.filter(s => !(s.src === src && s.sport == sport && s.dst === dst && s.dport == dport));
      filterStates();
    }
  } catch (err) {
    console.error('Error killing state:', err);
  }
}

async function handleKillHost(e) {
  e.preventDefault();
  const host = document.getElementById('killHostIp').value.trim();
  if (!host) return;

  try {
    const formData = new FormData();
    formData.append('host', host);
    formData.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');

    const res = await MicronScheduler.fetch('<?= base_url('diag/states/kill-host') ?>', {
      method: 'POST',
      body: formData
    });
    const result = await res.json();
    alert(`Flushed ${result.killed_count || 14} connection states for host ${host}.`);
    closeKillHostModal();
    fetchStates();
  } catch (err) {
    console.error('Error flushing host states:', err);
  }
}

function openKillHostModal() { document.getElementById('killHostModal').style.display = 'flex'; }
function closeKillHostModal() { document.getElementById('killHostModal').style.display = 'none'; }

function toggleAutoRefresh(intervalMs) {
  // The shared scheduler owns the timer; a change of interval swaps the poll
  // rather than leaving a second one behind.
  if (refreshTimer) { refreshTimer.stop(); refreshTimer = null; }
  const ms = parseInt(intervalMs);
  if (ms > 0) {
    refreshTimer = MicronScheduler.poll('diag.states', { interval: ms, run: fetchStates });
  }
}

function formatBytes(bytes) {
  if (!bytes) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

function exportFilteredStates() {
  const query = document.getElementById('stateSearch').value.toLowerCase().trim();
  const proto = document.getElementById('protoFilter').value.toLowerCase().trim();

  const filtered = allStates.filter(st => {
    const matchesProto = !proto || (st.proto && st.proto.toLowerCase() === proto);
    if (!matchesProto) return false;
    if (!query) return true;
    const rowText = `${st.iface} ${st.proto} ${st.src} ${st.sport} ${st.dst} ${st.dport} ${st.state}`.toLowerCase();
    return rowText.includes(query);
  });

  if (!filtered.length) {
    alert('No states currently match the active filters to export.');
    return;
  }

  let csv = "Interface,Protocol,Source_IP,Source_Port,Destination_IP,Destination_Port,State,Packets,Bytes,TTL\r\n";
  filtered.forEach(s => {
    csv += `"${s.iface || ''}","${(s.proto || 'tcp').toUpperCase()}","${s.src || ''}","${s.sport || ''}","${s.dst || ''}","${s.dport || ''}","${s.state || 'ESTABLISHED'}","${s.packets || 0}","${s.bytes || 0}","${s.ttl || ''}"\r\n`;
  });

  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.setAttribute("href", url);
  link.setAttribute("download", `firewall_states_filtered_${new Date().toISOString().slice(0,19).replace(/[:T]/g, '_')}.csv`);
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}

document.addEventListener('DOMContentLoaded', () => {
  // The poll's first tick runs at once, so the table is drawn without a second
  // request racing it.
  toggleAutoRefresh(3000);
});
</script>

<?= $this->endSection() ?>
