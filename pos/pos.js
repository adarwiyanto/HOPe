document.addEventListener('DOMContentLoaded', () => {
  const input = document.querySelector('#pos-search');
  const cards = Array.from(document.querySelectorAll('.pos-product-card'));
  const empty = document.querySelector('#pos-empty');
  const printBtn = document.querySelector('[data-print-receipt]');
  if (printBtn) {
    printBtn.addEventListener('click', () => {
      window.print();
    });
  }

  if (!input || !cards.length) return;

  const normalize = (value) => value.toLowerCase().trim();

  const filterProducts = () => {
    const query = normalize(input.value);
    let visibleCount = 0;

    cards.forEach((card) => {
      const name = card.dataset.name || '';
      const match = name.includes(query);
      card.style.display = match ? '' : 'none';
      if (match) visibleCount += 1;
    });

    if (empty) {
      empty.style.display = visibleCount ? 'none' : 'block';
    }
  };

  input.addEventListener('input', filterProducts);
  filterProducts();

  const paymentRadios = Array.from(document.querySelectorAll('input[name="payment_method"]'));
  const qrisPanel = document.querySelector('[data-qris-panel]');
  const video = document.querySelector('#qris-video');
  const preview = document.querySelector('#qris-preview');
  const canvas = document.querySelector('#qris-canvas');
  const startBtn = document.querySelector('#qris-start');
  const captureBtn = document.querySelector('#qris-capture');
  const retakeBtn = document.querySelector('#qris-retake');
  const proofInput = document.querySelector('#payment-proof');
  const errorEl = document.querySelector('#qris-error');
  const checkoutForm = qrisPanel ? qrisPanel.closest('form') : null;
  let stream = null;

  const setError = (message) => {
    if (!errorEl) return;
    errorEl.textContent = message;
    errorEl.hidden = !message;
  };

  const stopStream = () => {
    if (stream) {
      stream.getTracks().forEach((track) => track.stop());
      stream = null;
    }
  };

  const resetProof = () => {
    if (proofInput) proofInput.value = '';
    if (preview) {
      preview.src = '';
      preview.hidden = true;
    }
    if (video) video.hidden = false;
    if (retakeBtn) retakeBtn.hidden = true;
    if (captureBtn) captureBtn.disabled = true;
  };

  const startCamera = async () => {
    if (!video || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      setError('Perangkat tidak mendukung kamera.');
      return;
    }
    try {
      setError('');
      stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: { ideal: 'environment' } },
        audio: false,
      });
      video.srcObject = stream;
      await video.play();
      if (captureBtn) captureBtn.disabled = false;
    } catch (err) {
      setError('Izin kamera ditolak atau tidak tersedia.');
    }
  };

  const capturePhoto = () => {
    if (!video || !canvas || !preview) return;
    const width = video.videoWidth || 640;
    const height = video.videoHeight || 480;
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;
    ctx.drawImage(video, 0, 0, width, height);
    const dataUrl = canvas.toDataURL('image/jpeg', 0.9);
    preview.src = dataUrl;
    preview.hidden = false;
    video.hidden = true;
    if (proofInput) proofInput.value = dataUrl;
    stopStream();
    if (captureBtn) captureBtn.disabled = true;
    if (retakeBtn) retakeBtn.hidden = false;
  };

  const toggleQrisPanel = (enabled) => {
    if (!qrisPanel) return;
    qrisPanel.hidden = !enabled;
    if (!enabled) {
      stopStream();
      resetProof();
      setError('');
    }
  };

  if (paymentRadios.length && qrisPanel) {
    const updatePanel = () => {
      const selected = paymentRadios.find((radio) => radio.checked);
      toggleQrisPanel(selected && selected.value === 'qris');
      if (selected && selected.value === 'qris') {
        startCamera();
      }
    };
    paymentRadios.forEach((radio) => radio.addEventListener('change', updatePanel));
    updatePanel();
  }

  if (startBtn) {
    startBtn.addEventListener('click', () => {
      startCamera();
    });
  }

  if (captureBtn) {
    captureBtn.addEventListener('click', () => {
      capturePhoto();
    });
  }

  if (retakeBtn) {
    retakeBtn.addEventListener('click', () => {
      resetProof();
      startCamera();
    });
  }

  if (checkoutForm) {
    checkoutForm.addEventListener('submit', (event) => {
      const selected = paymentRadios.find((radio) => radio.checked);
      if (selected && selected.value === 'qris' && proofInput && !proofInput.value) {
        event.preventDefault();
        setError('Silakan ambil foto bukti QRIS sebelum checkout.');
      }
    });
  }
});
