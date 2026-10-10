<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
.log-pill {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-family: var(--font-mono);
  font-size: 0.72rem;
  font-weight: 700;
  padding: 2px 7px;
  border-radius: 4px;
  text-transform: uppercase;
}
.log-pass  { background: rgba(34,197,94,0.15); color: #86efac; border: 1px solid rgba(34,197,94,0.3); }
.log-block { background: rgba(239,68,68,0.15);  color: #fca5a5; border: 1px solid rgba(239,68,68,0.3); }
.log-reject{ background: rgba(245,158,11,0.15); color: #fcd34d; border: 1px solid rgba(245,158,11,0.3); }

.live-pulse {
  display: inline-block;
  width: 8px; height: 8px;
  border-radius: 50%;
  background: var(--color-emerald);
  box-shadow: 0 0 8px var(--color-emerald);
  animation: pulseLive 1.5s infinite;
}
@keyframes pulseLive {
  0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34,197,94, 0.7); }
  70% { transform: scale(1.1); box-shadow: 0 0 0 6px rgba(34,197,94, 0); }
  100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34,197,94, 0); }
}
</style>

<div class="page-header animate-in">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
      <h1 class="page-title">Firewall Live Filter Logs</h1>
      <p class="page-subtitle">Real-time packet filtering telemetry from Netfilter/nftables engine. Inspect passed sessions and blocked intrusions dynamically.</p>
    </div>
    <div style="display:flex; align-items:center; gap:12px;">
      <span style="display:flex; align-items:center; gap:6px; font-size:0.75rem; font-weight:600; color:var(--text-secondary);">
        <span class="live-pulse" id="liveDot"></span>
        <span id="liveStatusText">LIVE STREAMING</span>
      </span>
      <button type="button" class="btn btn-secondary btn-sm" id="btnToggleLive" onclick="toggleStream()">
        Pause Stream
      </button>
      <button type="button" class="btn btn-ghost btn-sm" onclick="clearLogs()">
        Clear Display
      </button>
    </div>
  </div>
</div>

<!-- Filter Toolbar -->
<div class="card card-glass animate-in animate-in-delay-1" style="padding:14px 18px; margin-bottom:20px;">
  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <div style="display:flex; align-items:center; gap:10px; flex:1; min-width:260px;">
      <input type="text" id="logSearch" class="form-control" placeholder="Filter by IP, port, or rule description..." onkeyup="filterLogs()" style="font-family:var(--font-mono); font-size:0.85rem;">
    </div>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <label class="form-label" style="margin:0; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted);">Action:</label>
      <select id="actionFilter" class="form-control" style="width:105px; font-size:0.85rem;" onchange="filterLogs()">
        <option value="">ALL</option>
        <option value="pass">PASS</option>
        <option value="block">BLOCK</option>
      </select>

      <label class="form-label" style="margin:0 0 0 8px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted);">Iface:</label>
      <select id="ifaceFilter" class="form-control" style="width:105px; font-size:0.85rem;" onchange="filterLogs()">
        <option value="">ALL</option>
        <option value="wan">WAN</option>
        <option value="lan">LAN</option>
        <option value="dmz">DMZ</option>
        <option value="wg0">VPN</option>
      </select>

      <label class="form-label" style="margin:0 0 0 8px; font-size:0.75rem; text-transform:uppercase; color:var(--text-muted);">Proto:</label>
      <select id="protoFilter" class="form-control" style="width:95px; font-size:0.85rem;" onchange="filterLogs()">
        <option value="">ALL</option>
        <option value="tcp">TCP</option>
        <option value="udp">UDP</option>
        <option value="icmp">ICMP</option>
      </select>
    </div>
  </div>
</div>

<!-- Logs Stream Table -->
<div class="card card-glass animate-in animate-in-delay-2" style="margin-bottom:28px;">
  <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
    <span class="card-title">
      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
      Firewall Packet Filter Events
    </span>
    <span class="badge badge-cyan" id="logCountBadge">Streaming live events...</span>
  </div>
  <div style="overflow-x:auto;">
    <table class="iface-table" id="logsTable">
      <thead>
        <tr>
          <th>Time</th>
          <th>Action</th>
          <th>Interface</th>
          <th>Protocol</th>
          <th>Source IP : Port</th>
          <th style="text-align:center;">&rarr;</th>
          <th>Destination IP : Port</th>
          <th>Rule Description / ID</th>
          <th>TCP Flags</th>
        </tr>
      </thead>
      <tbody id="logsBody">
        <tr>
          <td colspan="9" style="text-align:center; padding:32px; color:var(--text-muted);">
            Connecting to kernel nftables packet stream...
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<script>
let logsBuffer = [];
let isStreaming = true;
let streamInterval = null;

async function fetchLogEvents() {
  if (!isStreaming) return;   // paused is idle, not an outage — the poll keeps its cadence
  try {
    const res = await MicronScheduler.fetch('<?= base_url('diag/logs/data') ?>');
    if (!res.ok) return false;
    const data = await res.json();
    const newEvents = data.events || [];

    // Prepend new events, keep up to 150 events
    logsBuffer = [...newEvents, ...logsBuffer].slice(0, 150);
    filterLogs();
  } catch (err) {
    // `false` tells the shared scheduler this attempt failed, so it backs the
    // stream off and raises the one connection banner for the whole page.
    console.error('Failed to fetch firewall log events:', err);
    return false;
  }
}

function filterLogs() {
  const query = document.getElementById('logSearch').value.toLowerCase().trim();
  const action = document.getElementById('actionFilter').value.toLowerCase().trim();
  const iface = document.getElementById('ifaceFilter').value.toLowerCase().trim();
  const proto = document.getElementById('protoFilter').value.toLowerCase().trim();

  const filtered = logsBuffer.filter(ev => {
    if (action && ev.action.toLowerCase() !== action) return false;
    if (iface && !ev.iface.toLowerCase().includes(iface)) return false;
    if (proto && ev.proto.toLowerCase() !== proto) return false;
    if (!query) return true;

    const rowText = `${ev.src} ${ev.sport} ${ev.dst} ${ev.dport} ${ev.rule} ${ev.iface} ${ev.proto}`.toLowerCase();
    return rowText.includes(query);
  });

  renderLogs(filtered);
}

function renderLogs(list) {
  const tbody = document.getElementById('logsBody');
  const countBadge = document.getElementById('logCountBadge');
  countBadge.textContent = list.length + ' Filter Events Displayed';

  if (!list || list.length === 0) {
    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:32px; color:var(--text-muted);">No packet events match the active filters.</td></tr>';
    return;
  }

  let html = '';
  list.forEach(ev => {
    const isPass = (ev.action || 'pass').toLowerCase() === 'pass';
    const actionClass = isPass ? 'log-pass' : 'log-block';
    const actionIcon = isPass
      ? `<svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>`
      : `<svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>`;

    const protoUpper = (ev.proto || 'tcp').toUpperCase();
    let protoColor = '#67e8f9';
    if (protoUpper === 'UDP') protoColor = '#c084fc';
    if (protoUpper === 'ICMP') protoColor = '#f472b6';

    html += `
      <tr>
        <td style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-muted); white-space:nowrap;">${ev.time}</td>
        <td><span class="log-pill ${actionClass}">${actionIcon} ${ev.action.toUpperCase()}</span></td>
        <td style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-secondary); text-transform:uppercase;">${ev.iface}</td>
        <td><span style="font-family:var(--font-mono); font-size:0.75rem; font-weight:700; color:${protoColor};">${protoUpper}</span></td>
        <td style="font-family:var(--font-mono); font-size:0.8rem; color:var(--text-primary);">${ev.src}:${ev.sport}</td>
        <td style="text-align:center; color:var(--text-muted);">&rarr;</td>
        <td style="font-family:var(--font-mono); font-size:0.8rem; color:var(--color-cyan);">${ev.dst}:${ev.dport}</td>
        <td style="font-size:0.78rem; color:var(--text-secondary); max-width:240px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${ev.rule}">
          <span style="color:var(--text-muted); font-size:0.7rem; font-family:var(--font-mono);">[#${ev.rule_id || '100'}]</span> ${ev.rule}
        </td>
        <td style="font-family:var(--font-mono); font-size:0.7rem; color:var(--text-muted);">${ev.flags || '—'}</td>
      </tr>
    `;
  });
  tbody.innerHTML = html;
}

function toggleStream() {
  isStreaming = !isStreaming;
  const btn = document.getElementById('btnToggleLive');
  const dot = document.getElementById('liveDot');
  const text = document.getElementById('liveStatusText');

  if (isStreaming) {
    btn.textContent = 'Pause Stream';
    btn.className = 'btn btn-secondary btn-sm';
    dot.style.display = 'inline-block';
    text.textContent = 'LIVE STREAMING';
    fetchLogEvents();
  } else {
    btn.textContent = 'Resume Stream';
    btn.className = 'btn btn-primary btn-sm';
    dot.style.display = 'none';
    text.textContent = 'STREAM PAUSED';
  }
}

function clearLogs() {
  logsBuffer = [];
  renderLogs([]);
}

document.addEventListener('DOMContentLoaded', () => {
  streamInterval = MicronScheduler.poll('diag.logs', { interval: 2500, run: fetchLogEvents });
});
</script>

<?= $this->endSection() ?>
