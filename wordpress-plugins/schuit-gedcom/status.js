(() => {
  const panel = document.querySelector('[data-job]');
  if (!panel || !['uploaded', 'analyzing', 'queued', 'backing_up', 'importing'].includes(panel.dataset.state)) return;
  const poll = async () => {
    try {
      const query = new URLSearchParams({action: 'schuit_gedcom_status', job: panel.dataset.job, _ajax_nonce: schuitGedcom.nonce});
      const response = await fetch(`${schuitGedcom.endpoint}?${query}`, {credentials: 'same-origin', cache: 'no-store'});
      if (!response.ok) throw new Error();
      const result = await response.json();
      if (!result.success) throw new Error();
      if (result.data.state !== panel.dataset.state) { window.location.reload(); return; }
      document.getElementById('sg-state').textContent = result.data.label;
      document.getElementById('sg-progress').textContent = result.data.progress;
      document.getElementById('sg-connection').textContent = '';
    } catch {
      document.getElementById('sg-connection').textContent = 'Status tijdelijk niet bereikbaar. We proberen opnieuw. U kunt deze pagina later opnieuw openen.';
    }
    window.setTimeout(poll, 5000);
  };
  window.setTimeout(poll, 3000);
})();
