(function () {
  const root = document.querySelector('[data-receipt-bridge="1"]');
  if (!root) return;

  const bridgeName = root.getAttribute('data-android-bridge-name') || 'AndroidBridge';
  const appBtn = root.querySelector('[data-print-via-app]');
  const browserBtn = root.querySelector('[data-print-window]');
  const settingsBtn = root.querySelector('[data-open-printer-settings]');
  const notice = root.querySelector('[data-receipt-bridge-notice]');

  const isAndroidApp =
    root.getAttribute('data-is-android-app') === '1' ||
    /HOPePOSAndroidWebView/i.test(navigator.userAgent || '');

  const showNotice = (message, type) => {
    if (!notice) return;
    notice.hidden = false;
    notice.className = 'receipt-notice no-print ' + (type ? `is-${type}` : '');
    notice.textContent = message;
  };

  const parseBridgeResult = (raw) => {
    if (raw === true || raw === 'true') return { ok: true };
    if (raw === false || raw === 'false') return { ok: false, message: 'Bridge Android menolak perintah cetak.' };
    if (typeof raw !== 'string') return { ok: true };
    try {
      const parsed = JSON.parse(raw);
      return {
        ok: parsed.ok !== false,
        message: parsed.message || '',
        code: parsed.code || '',
      };
    } catch (_) {
      return { ok: true };
    }
  };

  const getAndroidBridge = () => {
    const bridge = window[bridgeName];
    if (!bridge) return null;
    return bridge;
  };

  const canUseAndroidNativePrint = () => {
    const bridge = getAndroidBridge();
    if (!bridge) return false;
    if (typeof bridge.isReady === 'function') {
      try {
        return String(bridge.isReady()) === '1';
      } catch (_) {
        return false;
      }
    }
    return typeof bridge.printReceipt === 'function';
  };

  const loadPayloadJson = () => {
    const payloadNode = document.getElementById('receipt-bridge-payload');
    if (!payloadNode) return null;
    const raw = (payloadNode.textContent || '').trim();
    if (!raw) return null;
    try {
      const parsed = JSON.parse(raw);
      return JSON.stringify(parsed);
    } catch (_) {
      return null;
    }
  };

  const openBrowserPrint = () => window.print();

  const openPrinterSettings = () => {
    const bridge = getAndroidBridge();
    if (bridge && typeof bridge.openPrinterSettings === 'function') {
      bridge.openPrinterSettings();
      return;
    }
    showNotice('Halaman pengaturan printer hanya tersedia di APK Android.', 'warn');
  };

  const tryNativePrint = () => {
    const payloadJson = loadPayloadJson();
    if (!payloadJson) {
      showNotice('Gagal memproses data receipt.', 'warn');
      return;
    }

    if (!isAndroidApp) {
      showNotice('Mode browser biasa terdeteksi. Gunakan Print Browser.', 'warn');
      openBrowserPrint();
      return;
    }

    const bridge = getAndroidBridge();
    if (!bridge || !canUseAndroidNativePrint()) {
      showNotice('Bridge Android tidak tersedia. Silakan buka dari APK HOPe POS.', 'warn');
      return;
    }

    if (typeof bridge.printReceipt !== 'function') {
      showNotice('Bridge Android tidak mendukung perintah cetak.', 'warn');
      return;
    }

    try {
      const rawResult = bridge.printReceipt(payloadJson);
      const result = parseBridgeResult(rawResult);
      if (!result.ok) {
        showNotice(result.message || 'Gagal mengirim data ke printer Android.', 'warn');
        if (result.code === 'PRINTER_NOT_SELECTED') {
          openPrinterSettings();
        }
        return;
      }
      showNotice(result.message || 'Data receipt dikirim ke printer Android...', 'ok');
    } catch (err) {
      showNotice('Bridge Android error: ' + (err && err.message ? err.message : 'unknown error'), 'warn');
    }
  };

  window.HopePosBridge = {
    isAndroidApp,
    canPrintNative: canUseAndroidNativePrint,
    printReceipt: tryNativePrint,
    openPrinterSettings,
  };

  if (browserBtn) browserBtn.addEventListener('click', openBrowserPrint);
  if (appBtn) appBtn.addEventListener('click', tryNativePrint);
  if (settingsBtn) settingsBtn.addEventListener('click', openPrinterSettings);
})();
