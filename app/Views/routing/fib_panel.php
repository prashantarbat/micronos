<style>
.fib-stat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 14px;
  margin-bottom: 20px;
}
.fib-stat-card {
  background: var(--color-surface-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: 14px 18px;
  display: flex;
  align-items: center;
  gap: 12px;
}
.fib-stat-num {
  font-size: 1.35rem;
  font-weight: 700;
  line-height: 1.2;
  font-family: var(--font-mono, monospace);
  color: var(--text-primary);
}
.fib-stat-label {
  font-size: 0.72rem;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  font-weight: 600;
}
.fib-table-wrap {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  overflow: hidden;
  background: var(--color-surface-1);
}
.fib-table-toolbar {
  padding: 12px 18px;
  background: var(--color-surface-2);
  border-bottom: 1px solid var(--color-border);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}
.fib-search-box {
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--color-surface-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: 6px 12px;
  width: 320px;
  max-width: 100%;
}
.fib-search-box input {
  background: transparent;
  border: none;
  color: var(--text-primary);
  font-size: 0.82rem;
  outline: none;
  width: 100%;
}
.fib-proto-badge {
  display: inline-block;
  padding: 2px 7px;
  border-radius: 4px;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  font-family: var(--font-mono, monospace);
}
.proto-bgp    { background: rgba(139,92,246,0.15); color: #a78bfa; border: 1px solid rgba(139,92,246,0.3); }
.proto-ospf   { background: rgba(16,185,129,0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.3); }
.proto-kernel { background: rgba(100,116,139,0.15); color: #94a3b8; border: 1px solid rgba(100,116,139,0.3); }
.proto-static { background: rgba(6,182,212,0.15);  color: #22d3ee; border: 1px solid rgba(6,182,212,0.3); }
.proto-dhcp   { background: rgba(59,130,246,0.15);  color: #60a5fa; border: 1px solid rgba(59,130,246,0.3); }
.proto-rip    { background: rgba(245,158,11,0.15); color: #fbbf24; border: 1px solid rgba(245,158,11,0.3); }
.proto-isis   { background: rgba(236,72,153,0.15); color: #f472b6; border: 1px solid rgba(236,72,153,0.3); }
</style>

<?php
$totalKernel = count($kernelRoutes ?? []);
$gwRoutes    = count(array_filter($kernelRoutes ?? [], static fn($r) => !in_array($r['gateway'] ?? '', ['direct', '—', '', 'none'])));
$directRoutes= $totalKernel - $gwRoutes;
$defaultRt   = array_filter($kernelRoutes ?? [], static fn($r) => in_array($r['destination'] ?? '', ['0.0.0.0/0', 'default', '::/0']));
?>

<!-- ── FIB Sub-Tab Bar ── -->
<div class="fib-sub-tabs" style="display:flex; gap:0; border-bottom:2px solid var(--color-border); margin-bottom:20px; overflow-x:auto;">
  <button type="button" class="fib-sub-tab active" style="display:flex; align-items:center; gap:6px; padding:8px 16px; font-size:0.8rem; font-weight:600; cursor:pointer; border-bottom:2px solid var(--color-cyan); margin-bottom:-2px; background:none; border-top:none; border-left:none; border-right:none; color:var(--color-cyan); pointer-events:none;">
    <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v3m0 12v3M3 12h3m12 0h3"/></svg>
    Kernel FIB Routing Table (<?= $totalKernel ?>)
  </button>
</div>

<div class="fib-stat-grid animate-in">
  <div class="fib-stat-card">
    <div style="width:36px; height:36px; border-radius:var(--radius-sm); background:rgba(6,182,212,0.12); color:var(--color-cyan); display:flex; align-items:center; justify-content:center;">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v3m0 12v3M3 12h3m12 0h3"/></svg>
    </div>
    <div>
      <div class="fib-stat-num"><?= $totalKernel ?></div>
      <div class="fib-stat-label">Active Kernel FIB Routes</div>
    </div>
  </div>
  <div class="fib-stat-card">
    <div style="width:36px; height:36px; border-radius:var(--radius-sm); background:rgba(59,130,246,0.12); color:var(--color-blue); display:flex; align-items:center; justify-content:center;">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
    </div>
    <div>
      <div class="fib-stat-num"><?= $gwRoutes ?></div>
      <div class="fib-stat-label">Via Next-Hop Gateway</div>
    </div>
  </div>
  <div class="fib-stat-card">
    <div style="width:36px; height:36px; border-radius:var(--radius-sm); background:rgba(16,185,129,0.12); color:var(--color-emerald); display:flex; align-items:center; justify-content:center;">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16m-7 6h7"/></svg>
    </div>
    <div>
      <div class="fib-stat-num"><?= $directRoutes ?></div>
      <div class="fib-stat-label">Direct Connected Links</div>
    </div>
  </div>
  <div class="fib-stat-card">
    <div style="width:36px; height:36px; border-radius:var(--radius-sm); background:rgba(139,92,246,0.12); color:var(--color-purple); display:flex; align-items:center; justify-content:center;">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064"/></svg>
    </div>
    <div>
      <div class="fib-stat-num"><?= count($defaultRt) ? count($defaultRt) : '0' ?></div>
      <div class="fib-stat-label">Default Gateway Prefix</div>
    </div>
  </div>
</div>

<div class="fib-table-wrap animate-in">
  <div class="fib-table-toolbar">
    <div style="display:flex; align-items:center; gap:10px;">
      <span style="font-weight:700; font-size:0.9rem; color:var(--text-primary);">Kernel Forwarding Information Base (FIB)</span>
      <span class="badge badge-neutral"><?= $totalKernel ?> active routes</span>
    </div>
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
      <div class="fib-search-box">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35"/></svg>
        <input type="text" id="fibSearchInput" placeholder="Filter prefix, gateway, interface..." oninput="filterFibTable(this.value)">
      </div>
      <a href="<?= base_url('routing/table') ?>" class="btn btn-secondary btn-sm" title="Detailed Full Routing Table View">
        Full Table View &rarr;
      </a>
    </div>
  </div>

  <table class="rt-table" id="fibTable" style="margin:0;">
    <thead>
      <tr>
        <th>Destination Prefix</th>
        <th>Gateway / Next-Hop</th>
        <th>Interface / Device</th>
        <th>Metric</th>
        <th>Protocol</th>
        <th>Flags</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($kernelRoutes)): ?>
        <tr>
          <td colspan="6" style="text-align:center; padding:36px; color:var(--text-muted);">
            No kernel routes currently installed in the system forwarding table.
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($kernelRoutes as $r): ?>
          <?php
            $proto = strtolower($r['proto'] ?? 'kernel');
            $pClass = match($proto) {
              'bgp'    => 'proto-bgp',
              'ospf'   => 'proto-ospf',
              'rip'    => 'proto-rip',
              'isis'   => 'proto-isis',
              'dhcp'   => 'proto-dhcp',
              'static' => 'proto-static',
              default  => 'proto-kernel',
            };
            $gwVal = $r['gateway'] ?? 'direct';
            $isDirect = in_array($gwVal, ['direct', '—', '', 'none'], true);
          ?>
          <tr>
            <td>
              <span class="rt-chip rt-chip-cyan" style="font-size:0.84rem;"><?= esc($r['destination'] ?? '—') ?></span>
            </td>
            <td>
              <?php if ($isDirect): ?>
                <span style="font-size:0.78rem; color:var(--text-muted); font-style:italic;">direct</span>
              <?php else: ?>
                <span style="font-family:var(--font-mono); font-size:0.84rem; font-weight:600; color:var(--text-primary);"><?= esc($gwVal) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <span class="rt-chip"><?= esc($r['interface'] ?? '—') ?></span>
            </td>
            <td style="font-family:var(--font-mono); font-size:0.8rem; color:var(--text-secondary);">
              <?= esc($r['metric'] ?? '—') ?>
            </td>
            <td>
              <span class="fib-proto-badge <?= $pClass ?>"><?= strtoupper(esc($proto)) ?></span>
            </td>
            <td style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-muted);">
              <?= esc(($r['flags'] ?? '') ?: '—') ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<script>
function filterFibTable(term) {
  var t = (term || '').toLowerCase().trim();
  var rows = document.querySelectorAll('#fibTable tbody tr');
  rows.forEach(function(row) {
    if (row.cells.length < 6) return;
    var text = row.innerText.toLowerCase();
    row.style.display = text.indexOf(t) !== -1 ? '' : 'none';
  });
}
</script>
