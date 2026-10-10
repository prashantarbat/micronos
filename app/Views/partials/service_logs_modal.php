<?php
/**
 * Shared daemon log viewer.
 *
 * Fetches `services/logs/{id}` (systemd journalctl on the appliance, a
 * representative log in mock mode) and shows the last 30 lines. Included once
 * per page — by the Services page and by the service-control widget, so a
 * failing LED is one click from the daemon's logs.
 */
?>
<!-- Modal: Service Log Viewer -->
<div id="serviceLogModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center;">
  <div class="modal-dialog" style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-lg); width:100%; max-width:750px; padding:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
      <div>
        <h3 style="margin:0; font-size:1.05rem; font-weight:700;" id="logModalTitle">Daemon Journal Logs</h3>
        <p style="margin:4px 0 0; font-size:0.75rem; color:var(--text-muted);" id="logModalSubtitle">Recent 30 log lines from journalctl</p>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeServiceLogModal()">&times;</button>
    </div>

    <div style="background:#070a12; border:1px solid rgba(255,255,255,0.08); border-radius:var(--radius-md); padding:16px; font-family:var(--font-mono); font-size:0.75rem; color:#a5b4fc; max-height:360px; overflow-y:auto; line-height:1.6; white-space:pre-wrap;" id="logModalContent">
      Loading journal logs...
    </div>

    <div style="display:flex; justify-content:flex-end; margin-top:16px;">
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeServiceLogModal()">Close</button>
    </div>
  </div>
</div>

<script>
if (typeof window.openServiceLogModal !== 'function') {
  window.openServiceLogModal = async function (id, name, base) {
    const logsBase = base || `<?= base_url('services/logs') ?>`;
    document.getElementById('logModalTitle').innerText = `${name}.service Journal`;
    document.getElementById('logModalSubtitle').innerText = `Recent 30 log entries from systemd journalctl`;
    document.getElementById('logModalContent').innerText = `Fetching journalctl logs for ${name}...`;
    document.getElementById('serviceLogModal').style.display = 'flex';

    try {
      const res = await MicronScheduler.fetch(`${logsBase}/${id}`);
      const data = await res.json();
      document.getElementById('logModalContent').innerText = data.logs || 'No log messages recorded for this service.';
    } catch (err) {
      document.getElementById('logModalContent').innerText = `Failed to fetch service logs: ${err.message}`;
    }
  };

  window.closeServiceLogModal = function () {
    document.getElementById('serviceLogModal').style.display = 'none';
  };

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { window.closeServiceLogModal(); }
  });
}
</script>
