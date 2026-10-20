/**
 * Food Dash - Checkout & Payment Controller
 * Handles dynamic branded payment selection, Face ID scanning, and order dispatch.
 */

let currentMethod = 'Cash on Delivery (COD)';

function showToast(text, isSuccess = true) {
  const t = document.getElementById('toastMsg');
  const txt = document.getElementById('toastText');
  if (t && txt) {
    txt.textContent = text;
    t.className = isSuccess ? 'toast-msg show success' : 'toast-msg show';
    clearTimeout(window._toastTimer);
    window._toastTimer = setTimeout(() => { t.className = 'toast-msg'; }, 3500);
  }
}

function parseCoordinate(val, fallback) {
  if (val === null || val === undefined) return fallback;
  if (typeof val === 'number' && !isNaN(val)) return val;
  const str = String(val).trim();
  const match = str.match(/-?\d+(?:\.\d+)?/);
  if (match) {
    const parsed = parseFloat(match[0]);
    if (!isNaN(parsed)) return parsed;
  }
  return fallback;
}

function normalizeMethodKey(m) {
  if (!m) return 'cod';
  const s = String(m).toLowerCase();
  if (s.includes('gcash')) return 'gcash';
  if (s.includes('maya')) return 'maya';
  if (s.includes('tyme')) return 'gotyme';
  if (s.includes('apple')) return 'apple_pay';
  return 'cod';
}

// 2. ATTACH PAYMENT CARD CLICK HANDLERS (COLOR, LOGO, & TEXT)
function selectPaymentMethod(method) {
  const btn = document.getElementById('mainCheckoutBtn') || document.querySelector('.checkout-btn') || document.getElementById('placeOrderBtn');
  if (!btn) return;
  
  const totalText = '₱200.00'; // or dynamic total PHP variable
  
  const norm = normalizeMethodKey(method);
  currentMethod = norm;
  
  const inputMethod = document.getElementById('selectedPaymentMethod');
  if (inputMethod) inputMethod.value = norm;

  // Update card active classes & radio checks
  document.querySelectorAll('.payment-card, .pay-option').forEach(card => {
    const cardMethod = normalizeMethodKey(card.getAttribute('data-method') || card.getAttribute('data-name') || '');
    const isSelected = (cardMethod === norm);
    const radio = card.querySelector('input[type="radio"]');
    const iconRadio = card.querySelector('.pay-radio');

    if (isSelected) {
      card.classList.add('active');
      if (radio) radio.checked = true;
      if (iconRadio) iconRadio.className = 'fa-solid fa-circle-check pay-radio';
    } else {
      card.classList.remove('active');
      if (radio) radio.checked = false;
      if (iconRadio) iconRadio.className = 'fa-regular fa-circle pay-radio';
    }
  });

  if (method === 'cod' || norm === 'cod') {
    btn.style.backgroundColor = '#283d23';
    btn.style.color = '#ffffff';
    btn.innerHTML = '<i class="fa-solid fa-money-bill-wave" style="margin-right:8px;"></i> Place Order • ' + totalText;
  } else if (method === 'gcash' || norm === 'gcash') {
    btn.style.backgroundColor = '#007DFE';
    btn.style.color = '#ffffff';
    btn.innerHTML = '<span style="font-weight:bold; margin-right:8px; background:#fff; color:#007DFE; padding:2px 6px; border-radius:4px; font-size:12px;">G</span> Pay with GCash • ' + totalText;
  } else if (method === 'maya' || norm === 'maya') {
    btn.style.backgroundColor = '#023a2b';
    btn.style.color = '#05b851';
    btn.innerHTML = '<span style="font-weight:bold; margin-right:8px; background:#05b851; color:#023a2b; padding:2px 6px; border-radius:4px; font-size:12px;">M</span> Pay with Maya • ' + totalText;
  } else if (method === 'gotyme' || norm === 'gotyme') {
    btn.style.backgroundColor = '#0a2540';
    btn.style.color = '#00d2ff';
    btn.innerHTML = '<span style="font-weight:bold; margin-right:8px; background:#00d2ff; color:#0a2540; padding:2px 6px; border-radius:4px; font-size:12px;">GT</span> Pay with GoTyme • ' + totalText;
  } else if (method === 'apple_pay' || norm === 'apple_pay') {
    btn.style.backgroundColor = '#000000';
    btn.style.color = '#ffffff';
    btn.innerHTML = '<span style="font-size:18px; margin-right:6px;"></span> Pay • ' + totalText;
  }
}

// Audio & Haptic confirmation cue
function playConfirmationCue() {
  try {
    if (navigator.vibrate) navigator.vibrate([35, 45, 35]);
  } catch(e) {}

  try {
    if (window.ReactNativeWebView && window.ReactNativeWebView.postMessage) {
      window.ReactNativeWebView.postMessage(JSON.stringify({ type: 'HAPTIC_CONFIRMATION' }));
    }
  } catch(e) {}

  try {
    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    if (AudioCtx) {
      const ctx = new AudioCtx();
      const osc1 = ctx.createOscillator();
      const gain1 = ctx.createGain();
      osc1.type = 'sine';
      osc1.frequency.setValueAtTime(880, ctx.currentTime);
      gain1.gain.setValueAtTime(0.08, ctx.currentTime);
      gain1.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.15);
      osc1.connect(gain1);
      gain1.connect(ctx.destination);
      osc1.start(ctx.currentTime);
      osc1.stop(ctx.currentTime + 0.15);

      const osc2 = ctx.createOscillator();
      const gain2 = ctx.createGain();
      osc2.type = 'sine';
      osc2.frequency.setValueAtTime(1320, ctx.currentTime + 0.1);
      gain2.gain.setValueAtTime(0.09, ctx.currentTime + 0.1);
      gain2.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.35);
      osc2.connect(gain2);
      gain2.connect(ctx.destination);
      osc2.start(ctx.currentTime + 0.1);
      osc2.stop(ctx.currentTime + 0.35);
    }
  } catch(e) {}
}

// 3. RESTORE INTERACTIVE PAYMENT CONFIRMATION & APPLE PAY FACE ID
function openApplePayModal(address) {
  const dest = document.getElementById('appleModalDest');
  if (dest) dest.textContent = address;

  const scanLine = document.getElementById('appleFaceIdScanLine');
  const svg = document.getElementById('appleFaceIdSvg');
  const check = document.getElementById('appleFaceIdCheck');
  const box = document.getElementById('appleFaceIdBox');
  const status = document.getElementById('appleFaceIdStatusText');
  const btn = document.getElementById('btnApplePayTrigger');

  if (scanLine) scanLine.style.display = 'block';
  if (svg) svg.style.display = 'block';
  if (check) check.style.display = 'none';
  if (box) {
    box.classList.remove('success');
    box.classList.add('scanning');
  }
  if (status) {
    status.innerHTML = '<span class="pulse-scan" style="color:#007aff; font-size:14px; font-weight:700;"><i class="fa-solid fa-spinner fa-spin" style="margin-right:6px;"></i>Scanning Face...</span>';
  }
  if (btn) {
    btn.disabled = true;
    btn.style.background = '#333336';
    btn.style.color = '#8e8e93';
    btn.innerHTML = '<span style="font-size:18px;"></span> Scanning Face...';
  }

  document.getElementById('applePayModal').style.display = 'flex';

  // 1.5 seconds Face ID scan animation
  setTimeout(() => {
    if (scanLine) scanLine.style.display = 'none';
    if (svg) svg.style.display = 'none';
    if (check) check.style.display = 'block';
    if (box) {
      box.classList.remove('scanning');
      box.classList.add('success');
    }
    if (status) {
      status.innerHTML = '<span style="color:#30d158; font-size:16px; font-weight:800; display:inline-flex; align-items:center; gap:6px;"><i class="fa-solid fa-check"></i> Done</span>';
    }
    if (btn) {
      btn.style.background = '#30d158';
      btn.style.color = '#ffffff';
      btn.innerHTML = '<i class="fa-solid fa-check"></i> Done';
    }

    playConfirmationCue();

    // Automatically submit order after subtle confirmation pause
    setTimeout(() => {
      submitOrderViaAjax('Apple Pay');
    }, 450);
  }, 1500);
}

function closeApplePayModal() {
  document.getElementById('applePayModal').style.display = 'none';
}

function openWalletModal(method, address) {
  const dest = document.getElementById('walletDestDisplay');
  if (dest) dest.textContent = address;

  const banner = document.getElementById('walletBrandBanner');
  const logo = document.getElementById('walletBrandLogo');
  const title = document.getElementById('walletBrandTitle');
  const sub = document.getElementById('walletBrandSub');
  const total = document.getElementById('walletTotalDisplay');
  const btn = document.getElementById('btnWalletConfirm');
  const pinInput = document.getElementById('walletPinInput');

  if (method === 'GCash') {
    if (banner) banner.style.backgroundColor = '#007dfe';
    if (logo) logo.src = 'assets/logos/gcash.svg';
    if (title) title.textContent = 'GCash QuickPay';
    if (sub) sub.textContent = 'Philippines\' Leading E-Wallet';
    if (total) total.style.color = '#007dfe';
    if (btn) {
      btn.style.backgroundColor = '#007dfe';
      btn.innerHTML = '<i class="fa-solid fa-shield-halved"></i> Authorize & Pay';
      btn.disabled = false;
    }
    if (pinInput) pinInput.style.borderColor = '#007dfe';
  } else if (method.includes('Maya')) {
    if (banner) banner.style.backgroundColor = '#023a2b';
    if (logo) logo.src = 'assets/logos/maya.svg';
    if (title) title.textContent = 'Maya Checkout';
    if (sub) sub.textContent = 'Maya Wallet & Digital Card';
    if (total) total.style.color = '#05b851';
    if (btn) {
      btn.style.backgroundColor = '#023a2b';
      btn.innerHTML = '<i class="fa-solid fa-shield-halved"></i> Authorize & Pay';
      btn.disabled = false;
    }
    if (pinInput) pinInput.style.borderColor = '#05b851';
  } else {
    // GoTyme
    if (banner) banner.style.backgroundColor = '#0a2540';
    if (logo) logo.src = 'assets/logos/gotyme.svg';
    if (title) title.textContent = 'GoTyme Bank Transfer';
    if (sub) sub.textContent = 'Everyday Account (•••• 4920)';
    if (total) total.style.color = '#00c2cb';
    if (btn) {
      btn.style.backgroundColor = '#0a2540';
      btn.innerHTML = '<i class="fa-solid fa-shield-halved"></i> Authorize & Pay';
      btn.disabled = false;
    }
    if (pinInput) pinInput.style.borderColor = '#00c2cb';
  }

  document.getElementById('walletModal').style.display = 'flex';
}

function closeWalletModal() {
  document.getElementById('walletModal').style.display = 'none';
}

function submitWalletOrder() {
  const btn = document.getElementById('btnWalletConfirm');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Authorizing...';
  }
  const pin = document.getElementById('walletPinInput') ? document.getElementById('walletPinInput').value : '1234';
  submitOrderViaAjax(currentMethod, { pin: pin });
}

function openCodModal(address) {
  const dest = document.getElementById('codDestDisplay');
  if (dest) dest.textContent = address;
  const btn = document.getElementById('btnCodConfirm');
  if (btn) {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-check"></i> Confirm Order';
  }
  document.getElementById('codModal').style.display = 'flex';
}

function closeCodModal() {
  document.getElementById('codModal').style.display = 'none';
}

function submitCodOrder() {
  const btn = document.getElementById('btnCodConfirm');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Confirming Order...';
  }
  submitOrderViaAjax('Cash on Delivery (COD)');
}

function resetModalButtons() {
  const btnW = document.getElementById('btnWalletConfirm');
  if (btnW) {
    btnW.disabled = false;
    btnW.innerHTML = '<i class="fa-solid fa-shield-halved"></i> Authorize & Pay';
  }
  const btnC = document.getElementById('btnCodConfirm');
  if (btnC) {
    btnC.disabled = false;
    btnC.innerHTML = '<i class="fa-solid fa-check"></i> Confirm Order';
  }
}

// 4. SUBMIT TO BACKEND WITHOUT 401 ERRORS
function submitOrderViaAjax(paymentMethod, extraData = {}) {
  const addrInput = document.getElementById('deliveryAddressInput');
  let address = (addrInput ? addrInput.value : '').trim() || 'Western Leyte College';

  const latInput = document.getElementById('delivery_lat');
  const lngInput = document.getElementById('delivery_lng');
  const dLat = parseCoordinate(latInput ? latInput.value : null, 11.01020);
  const dLng = parseCoordinate(lngInput ? lngInput.value : null, 124.61779);

  const formData = new FormData();
  formData.append('action', 'create_order');
  formData.append('total', '200.00');
  formData.append('total_amount', '200.00');
  formData.append('subtotal', '170.00');
  formData.append('delivery_fee', '30.00');
  formData.append('delivery_address', address);
  formData.append('delivery_lat', String(dLat));
  formData.append('delivery_lng', String(dLng));
  formData.append('customer_lat', String(dLat));
  formData.append('customer_lng', String(dLng));
  formData.append('payment_method', paymentMethod);
  formData.append('cart', JSON.stringify([
    { name: 'Signature Meal Combo', store: 'Food Dash Grill', price: 170.00, qty: 1, icon: '🍱' }
  ]));

  if (extraData && typeof extraData === 'object') {
    Object.keys(extraData).forEach(k => {
      formData.append(k, extraData[k]);
    });
  }

  fetch('api_orders.php', {
    method: 'POST',
    credentials: 'include',
    body: formData
  })
  .then(res => {
    if (!res.ok) {
      return res.text().then(t => { throw new Error('HTTP ' + res.status + ': ' + t); });
    }
    return res.json();
  })
  .then(data => {
    if (data.success || data.order_id) {
      showToast('Order confirmed! Redirecting...');
      const orderId = data.order_id || 0;
      const redirectUrl = data.redirect_url || ('customer_dashboard.php?order_placed=1&order_id=' + orderId);

      try {
        if (window.ReactNativeWebView && window.ReactNativeWebView.postMessage) {
          window.ReactNativeWebView.postMessage(JSON.stringify({
            type: 'ORDER_PLACED',
            orderId: orderId,
            redirectUrl: redirectUrl
          }));
        }
      } catch(e) {}

      setTimeout(() => {
        window.location.href = redirectUrl;
      }, 350);
    } else {
      showToast(data.message || 'Payment authorization failed', false);
      resetModalButtons();
    }
  })
  .catch(err => {
    console.error('Order submission error:', err);
    showToast(err.message || 'Network error occurred', false);
    resetModalButtons();
  });
}

function handlePlaceOrderClick(e) {
  if (e && e.preventDefault) e.preventDefault();

  const addrInput = document.getElementById('deliveryAddressInput');
  const address = (addrInput ? addrInput.value : '').trim() || 'Western Leyte College';

  const m = normalizeMethodKey(currentMethod);
  if (m === 'apple_pay') {
    openApplePayModal(address);
  } else if (m === 'cod') {
    openCodModal(address);
  } else {
    openWalletModal(m, address);
  }
}

document.addEventListener('DOMContentLoaded', function() {
  // 1. Remove all pattern and strict attributes across all fields
  document.querySelectorAll('input, textarea, select, form').forEach(el => {
    if (el.hasAttribute('pattern')) el.removeAttribute('pattern');
    if (el.hasAttribute('required')) el.removeAttribute('required');
  });

  // Attach button click handler
  const btn = document.getElementById('mainCheckoutBtn') || document.querySelector('.checkout-btn') || document.getElementById('placeOrderBtn');
  if (btn) {
    btn.addEventListener('click', handlePlaceOrderClick);
  }

  // 2. Attach click listeners to every payment card container
  document.querySelectorAll('.payment-card, .pay-option, input[name="payment_method"]').forEach(elem => {
    elem.addEventListener('click', function(e) {
      let method = this.getAttribute('data-method') || this.getAttribute('data-name') || this.value;
      if (!method) {
        const parent = this.closest('.payment-card, .pay-option');
        if (parent) method = parent.getAttribute('data-method') || parent.getAttribute('data-name');
      }
      if (method) {
        selectPaymentMethod(method);
      }
    });
  });

  // Default to Cash on Delivery (COD)
  selectPaymentMethod('cod');
});
