(function () {
  const root = document.querySelector('[data-receipt-bridge="1"]');
  if (!root) return;

  const isAndroid = root.getAttribute('data-is-android') === '1';
  const deepLink = root.getAttribute('data-bridge-link') || '';
  const token = root.getAttribute('data-print-token') || '';
  const apiUrl = root.getAttribute('data-api-url') || '';

  const appBtn = root.querySelector('[data-print-via-app]');
  const browserBtn = root.querySelector('[data-print-window]');
  const notice = root.querySelector('[data-receipt-bridge-notice]');

  const showNotice = (message, type) => {
    if (!notice) return;
    notice.hidden = false;
    notice.className = 'receipt-notice no-print ' + (type ? `is-${type}` : '');
    notice.textContent = message;
  };

  const openBrowserPrint = () => {
    window.print();
  };

  if (browserBtn) {
    browserBtn.addEventListener('click', openBrowserPrint);
  }

  const tryAppHandoff = () => {
    if (!isAndroid || !deepLink || !token) {
      showNotice('Mode app bridge hanya tersedia di Android. Gunakan Print Browser.', 'warn');
      return;
    }

    let switchedToApp = false;
    const start = Date.now();
    const timer = window.setTimeout(() => {
      if (!switchedToApp && Date.now() - start < 1800) {
        showNotice('Aplikasi print belum terpasang / tidak merespons. Gunakan Print Browser.', 'warn');
      }
    }, 1400);

    const onVisibility = () => {
      if (document.hidden) {
        switchedToApp = true;
        showNotice('Membuka aplikasi print bridge...', 'ok');
      }
    };

    document.addEventListener('visibilitychange', onVisibility, { once: true });
    window.location.href = deepLink;

    window.setTimeout(() => {
      window.clearTimeout(timer);
      if (!switchedToApp) {
        showNotice('Aplikasi print belum terpasang / tidak merespons. Gunakan Print Browser.', 'warn');
      }
    }, 2000);
  };

  if (appBtn) {
    appBtn.addEventListener('click', tryAppHandoff);
  }

  const refreshStatus = async () => {
    if (!apiUrl || !token) return;
    try {
      const res = await fetch(`${apiUrl}?action=get&token=${encodeURIComponent(token)}`, {
        method: 'GET',
        cache: 'no-store',
      });
      if (res.ok) return;
      if (res.status === 404) {
        showNotice('Print job tidak lagi pending (mungkin sudah tercetak/expired).', 'warn');
      }
    } catch (err) {
      // Ignore network issue on browser preview.
    }
  };

  refreshStatus();
})();
