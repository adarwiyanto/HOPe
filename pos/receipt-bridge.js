(function () {
  const root = document.querySelector('[data-receipt-bridge="1"]');
  if (!root) return;

  const isAndroidApp = root.getAttribute('data-is-android-app') === '1';
  const appBtn = root.querySelector('[data-print-via-app]');
  const browserBtn = root.querySelector('[data-print-window]');
  const settingsBtn = root.querySelector('[data-open-printer-settings]');
  const notice = root.querySelector('[data-receipt-bridge-notice]');

  const showNotice = (message, type) => {
    if (!notice) return;
    notice.hidden = false;
    notice.className = 'receipt-notice no-print ' + (type ? `is-${type}` : '');
    notice.textContent = message;
  };

  const openBrowserPrint = () => window.print();

  const buildReceiptPayload = () => {
    const receiptRoot = document.getElementById('receipt-print-root');
    if (!receiptRoot) return null;
    return {
      html: receiptRoot.outerHTML,
      meta: JSON.stringify({
        url: window.location.href,
        receiptId: receiptRoot.dataset.receiptId || '',
        cashier: receiptRoot.dataset.cashier || '',
        time: receiptRoot.dataset.time || '',
        storeName: receiptRoot.dataset.storeName || '',
        logoUrl: receiptRoot.dataset.logoSrc || ''
      })
    };
  };

  const tryNativePrint = () => {
    if (!isAndroidApp) {
      showNotice('Mode Android app tidak aktif. Gunakan Print Browser.', 'warn');
      openBrowserPrint();
      return;
    }

    if (!window.AndroidBridge || typeof window.AndroidBridge.printReceipt !== 'function') {
      showNotice('Bridge Android tidak ditemukan, fallback ke Print Browser.', 'warn');
      openBrowserPrint();
      return;
    }

    const payload = buildReceiptPayload();
    if (!payload) {
      showNotice('Data receipt tidak ditemukan, fallback ke Print Browser.', 'warn');
      openBrowserPrint();
      return;
    }

    try {
      window.AndroidBridge.printReceipt(payload.html, payload.meta);
      showNotice('Mengirim receipt ke printer native Android...', 'ok');
    } catch (err) {
      showNotice('Gagal mengirim ke Android bridge, fallback ke Print Browser.', 'warn');
      openBrowserPrint();
    }
  };

  const openPrinterSettings = () => {
    if (window.AndroidBridge && typeof window.AndroidBridge.openPrinterSettings === 'function') {
      window.AndroidBridge.openPrinterSettings();
      return;
    }
    showNotice('Halaman pengaturan printer hanya tersedia di APK Android.', 'warn');
  };

  if (browserBtn) browserBtn.addEventListener('click', openBrowserPrint);
  if (appBtn) appBtn.addEventListener('click', tryNativePrint);
  if (settingsBtn) settingsBtn.addEventListener('click', openPrinterSettings);
})();
