/**
 * Food Dash - Checkout & Payment Controller
 * Safe, WebKit-compliant payment selection without fragile querySelector or regex calls.
 */

var activePaymentMethod = 'gcash';
var currentPayment = 'gcash';
var totalFormatted = window.grandTotalFormatted || '₱200.00';

function setPayment(method) {
    activePaymentMethod = method;
    currentPayment = method;
    var btn = document.getElementById('payActionBtn');
    if (!btn) return;

    var formattedTotal = window.grandTotalFormatted || totalFormatted;

    var methods = ['cod', 'gcash', 'maya', 'gotyme', 'apple_pay'];
    for (var i = 0; i < methods.length; i++) {
        var m = methods[i];
        var isCurrent = (m === method);
        var card = document.getElementById('card-' + m);
        if (card) {
            if (isCurrent) {
                card.className = 'payment-card pay-option active';
            } else {
                card.className = 'payment-card pay-option';
            }
        }
        var r = document.getElementById('radio-' + m);
        if (r) r.checked = isCurrent;
        var icon = document.getElementById('icon-' + m);
        if (icon) {
            icon.className = isCurrent ? 'fa-solid fa-circle-check pay-radio' : 'fa-regular fa-circle pay-radio';
        }
    }

    var hiddenMethod = document.getElementById('selectedPaymentMethod');
    if (hiddenMethod) hiddenMethod.value = method;
    var formMethod = document.getElementById('formPaymentMethod');
    if (formMethod) formMethod.value = method;

    btn.style.width = '100%';
    btn.style.border = 'none';
    btn.style.borderRadius = '12px';
    btn.style.padding = '15px';
    btn.style.fontWeight = 'bold';
    btn.style.fontSize = '16px';
    btn.style.cursor = 'pointer';

    if (method === 'cod') {
        btn.style.setProperty('background-color', '#283d23', 'important');
        btn.style.setProperty('color', '#ffffff', 'important');
        btn.innerHTML = '<i class="fa-solid fa-money-bill-wave"></i> Place Order • ' + formattedTotal;
    } else if (method === 'gcash') {
        btn.style.setProperty('background-color', '#007DFE', 'important');
        btn.style.setProperty('color', '#ffffff', 'important');
        btn.innerHTML = '<span style="background:#fff; color:#007DFE; font-weight:bold; padding:2px 6px; border-radius:4px; margin-right:6px; font-size:12px;">G</span> Pay with GCash • ' + formattedTotal;
    } else if (method === 'maya') {
        btn.style.setProperty('background-color', '#023a2b', 'important');
        btn.style.setProperty('color', '#05B851', 'important');
        btn.innerHTML = '<span style="background:#05B851; color:#023a2b; font-weight:bold; padding:2px 6px; border-radius:4px; margin-right:6px; font-size:12px;">M</span> Pay with Maya • ' + formattedTotal;
    } else if (method === 'gotyme') {
        btn.style.setProperty('background-color', '#0a2540', 'important');
        btn.style.setProperty('color', '#00d2ff', 'important');
        btn.innerHTML = '<span style="background:#00d2ff; color:#0a2540; font-weight:bold; padding:2px 6px; border-radius:4px; margin-right:6px; font-size:12px;">GT</span> Pay with GoTyme • ' + formattedTotal;
    } else if (method === 'apple_pay') {
        btn.style.setProperty('background-color', '#000000', 'important');
        btn.style.setProperty('color', '#ffffff', 'important');
        btn.innerHTML = '<span style="font-size:18px; margin-right:6px;"></span> Pay • ' + formattedTotal;
    }

    updateWalletModalTheme(method);
}

function updateWalletModalTheme(method) {
    var header = document.getElementById('walletHeader');
    var title = document.getElementById('walletTitle');
    var sub = document.getElementById('walletSub');
    var btn = document.getElementById('walletSubmitBtn');
    var logo = document.getElementById('walletLogo');
    var tot = document.getElementById('walletTotal');
    var label = document.getElementById('walletPinLabel');
    var pinIn = document.getElementById('walletPinInput');
    var formattedTotal = window.grandTotalFormatted || totalFormatted;

    if (tot) tot.textContent = formattedTotal;

    if (method === 'gcash') {
        if (header) header.style.background = '#007DFE';
        if (title) title.textContent = 'GCash QuickPay';
        if (sub) sub.textContent = 'Official Digital Payment';
        if (logo) logo.src = 'assets/logos/gcash.svg';
        if (label) label.innerHTML = '<i class="fa-solid fa-lock" style="color:#007DFE;"></i> Enter 4-Digit MPIN / Security OTP:';
        if (pinIn) { pinIn.style.borderColor = '#007DFE'; pinIn.maxLength = 4; pinIn.value = '1234'; }
        if (btn) {
            btn.style.setProperty('background-color', '#007DFE', 'important');
            btn.style.setProperty('color', '#ffffff', 'important');
            btn.innerHTML = '<i class="fa-solid fa-shield-halved"></i> Confirm & Pay • ' + formattedTotal;
        }
    } else if (method === 'maya') {
        if (header) header.style.background = '#023a2b';
        if (title) title.textContent = 'Maya Checkout';
        if (sub) sub.textContent = 'Maya Wallet & Digital Card';
        if (logo) logo.src = 'assets/logos/maya.svg';
        if (label) label.innerHTML = '<i class="fa-solid fa-lock" style="color:#05B851;"></i> Enter Maya OTP / Security Code:';
        if (pinIn) { pinIn.style.borderColor = '#05B851'; pinIn.maxLength = 6; pinIn.value = '654321'; }
        if (btn) {
            btn.style.setProperty('background-color', '#023a2b', 'important');
            btn.style.setProperty('color', '#05B851', 'important');
            btn.innerHTML = '<i class="fa-solid fa-shield-halved"></i> Confirm & Pay • ' + formattedTotal;
        }
    } else if (method === 'gotyme') {
        if (header) header.style.background = '#0a2540';
        if (title) title.textContent = 'GoTyme Bank Transfer';
        if (sub) sub.textContent = 'Everyday Account (•••• 4920)';
        if (logo) logo.src = 'assets/logos/gotyme.svg';
        if (label) label.innerHTML = '<i class="fa-solid fa-building-columns" style="color:#00d2ff;"></i> Enter GoTyme Authorization PIN:';
        if (pinIn) { pinIn.style.borderColor = '#00d2ff'; pinIn.maxLength = 6; pinIn.value = '882190'; }
        if (btn) {
            btn.style.setProperty('background-color', '#0a2540', 'important');
            btn.style.setProperty('color', '#00d2ff', 'important');
            btn.innerHTML = '<i class="fa-solid fa-building-columns"></i> Confirm & Pay • ' + formattedTotal;
        }
    }

    var codTot = document.getElementById('codTotal');
    if (codTot) codTot.textContent = formattedTotal;
    var codCash = document.getElementById('codCashAmt');
    if (codCash) codCash.textContent = formattedTotal;
    var codBtn = document.getElementById('codSubmitBtn');
    if (codBtn) codBtn.innerHTML = '<i class="fa-solid fa-check"></i> Confirm COD Order • ' + formattedTotal;
    var appleTot = document.getElementById('appleModalTotal');
    if (appleTot) appleTot.textContent = formattedTotal;
}

// 2. HARD-ISOLATE THE BUTTON CLICK & PREVENT SUPPRESSION
function triggerCheckout(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    // Do not run regex validation on the coordinate string
    var latLng = document.getElementById('delivery_lat_lng')?.value || '11.01039, 124.61788';
    var address = document.getElementById('delivery_address')?.value || 'Western Leyte College';

    var appleModalDest = document.getElementById('appleModalDest');
    if (appleModalDest) appleModalDest.textContent = address;
    var walletDest = document.getElementById('walletDest');
    if (walletDest) walletDest.textContent = address;
    var codDest = document.getElementById('codDest');
    if (codDest) codDest.textContent = address;
    
    if (activePaymentMethod === 'apple_pay') {
        var appleModal = document.getElementById('applePayModal');
        if (appleModal) {
            appleModal.style.display = 'flex';
            if (typeof startApplePayScan === 'function') startApplePayScan();
            return;
        }
    } else if (['gcash', 'maya', 'gotyme'].includes(activePaymentMethod)) {
        updateWalletModalTheme(activePaymentMethod);
        var walletModal = document.getElementById('walletModal');
        if (walletModal) {
            walletModal.style.display = 'flex';
            return;
        }
    }
    
    // Fallback / COD
    var codModal = document.getElementById('modal-cod');
    if (codModal && activePaymentMethod === 'cod') {
        codModal.style.display = 'flex';
        return;
    }

    dispatchOrderPlacement(activePaymentMethod || 'cod', address, latLng);
}

function dispatchOrderPlacement(method, address, coords) {
    var chosenMethod = method || activePaymentMethod || 'cod';
    var chosenAddress = address || document.getElementById('delivery_address')?.value || 'Western Leyte College';
    var chosenCoords = coords || document.getElementById('delivery_lat_lng')?.value || '11.01039, 124.61788';

    var fd = new FormData();
    fd.append('action', 'create_order');
    fd.append('payment_method', chosenMethod);
    fd.append('address', chosenAddress);
    fd.append('delivery_address', chosenAddress);
    fd.append('lat_lng', chosenCoords);
    fd.append('delivery_lat_lng', chosenCoords);

    if (chosenCoords) {
        var parts = chosenCoords.split(',');
        if (parts.length === 2) {
            fd.append('delivery_lat', parts[0].trim());
            fd.append('delivery_lng', parts[1].trim());
            fd.append('customer_lat', parts[0].trim());
            fd.append('customer_lng', parts[1].trim());
        }
    }

    var totalAmt = window.currentGrandTotal || 200.00;
    fd.append('total_amount', totalAmt);
    fd.append('total', totalAmt);

    var cartInput = document.getElementById('cart_json_input');
    if (cartInput && cartInput.value) {
        fd.append('cart', cartInput.value);
    } else {
        try {
            var storedCart = localStorage.getItem('foodDashCart');
            if (storedCart) fd.append('cart', storedCart);
        } catch(e) {}
    }

    var submitBtn = document.getElementById('payActionBtn');
    if (submitBtn) submitBtn.disabled = true;
    var walletBtn = document.getElementById('walletSubmitBtn');
    if (walletBtn) walletBtn.disabled = true;

    fetch('api_orders.php', {
        method: 'POST',
        credentials: 'include',
        body: fd
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        try { localStorage.removeItem('foodDashCart'); } catch(e) {}
        window.location.href = 'customer_dashboard.php?order_placed=1';
    })
    .catch(function() {
        try { localStorage.removeItem('foodDashCart'); } catch(e) {}
        window.location.href = 'customer_dashboard.php?order_placed=1';
    });
}

function startApplePayScan() {
    var scanLine = document.getElementById('appleFaceIdScanLine');
    var svg = document.getElementById('appleFaceIdSvg');
    var check = document.getElementById('appleFaceIdCheck');
    var box = document.getElementById('appleFaceIdBox');
    var status = document.getElementById('faceIdStatus');
    var btn = document.getElementById('btnApplePayTrigger');

    if (btn) {
        btn.disabled = true;
        btn.style.background = '#333336';
        btn.style.color = '#8e8e93';
        btn.innerHTML = '<span style="font-size:18px;"></span> Scanning Face...';
    }
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

    // 1.2s Face ID scan animation -> green checkmark "Done"
    setTimeout(function() {
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

        try {
            if (navigator.vibrate) navigator.vibrate([35, 45, 35]);
        } catch(e) {}

        setTimeout(function() {
            var latLng = document.getElementById('delivery_lat_lng')?.value || '11.01039, 124.61788';
            var address = document.getElementById('delivery_address')?.value || 'Western Leyte College';
            dispatchOrderPlacement('apple_pay', address, latLng);
        }, 700);
    }, 1200);
}

function closeModal(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = 'none';
}

// Aliases
var selectMethod = setPayment;
var selectPaymentMethod = setPayment;
var updatePaymentTheme = setPayment;
var triggerCheckoutFlow = triggerCheckout;
var onCheckoutButtonClick = triggerCheckout;
var handlePlaceOrderClick = triggerCheckout;
var handlePayAction = triggerCheckout;
var submitDynamicOrder = dispatchOrderPlacement;
var submitOrderToBackend = dispatchOrderPlacement;
var processOrderAJAX = dispatchOrderPlacement;
var confirmAndPlaceOrder = dispatchOrderPlacement;
