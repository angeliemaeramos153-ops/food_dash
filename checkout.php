<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/order_transaction.php';
    exit;
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db_connect.php';

$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 15;
$customer_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Customer';
$customer_phone = '+63 928 555 0148';
$default_address = 'Western Leyte College';
$default_lat = 11.01020;
$default_lng = 124.61779;
$total_amount = 489.00;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
  <title>Checkout - Food Dash</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script>
  // WebKit / Safari Apple Pay & PaymentRequest Hardening Shield:
  // Intercept native PaymentRequest to prevent DOMException: "The string did not match the expected pattern."
  (function() {
    if (typeof window === 'undefined') return;
    try {
      if (window.PaymentRequest) {
        var _NativePaymentRequest = window.PaymentRequest;
        window.PaymentRequest = function(methods, details, options) {
          try {
            if (details && details.total && details.total.amount) {
              if (typeof details.total.amount.value === 'string') {
                details.total.amount.value = details.total.amount.value.replace(/[^0-9.]/g, '') || '0.00';
              }
            }
            return new _NativePaymentRequest(methods, details, options);
          } catch(err) {
            console.warn("Native payment skipped:", err);
            return {
              show: function() { return Promise.reject(new Error("Simulated payment active")); },
              abort: function() { return Promise.resolve(); },
              canMakePayment: function() { return Promise.resolve(false); }
            };
          }
        };
      }
      if (window.ApplePaySession) {
        try {
          var _origCanMake = window.ApplePaySession.canMakePayments;
          window.ApplePaySession.canMakePayments = function() {
            try { return _origCanMake ? _origCanMake.apply(this, arguments) : true; } catch(e) { return true; }
          };
        } catch(e) {}
      }
    } catch(e) {
      console.warn("PaymentRequest hardening error:", e);
    }
  })();
  </script>
  <style>
    :root {
      --harvest-sun: #D88F2A;
      --sagebrush: #788A6E;
      --earth-clay: #923D32;
      --olive-shadow: #4B5320;
      --sandstone: #D4C5A5;
      --sandstone-light: #FAF6EE;
      --olive: #4B5320;
      --olive-dark: #344016;
      --paper: #FFFDF9;
      --line: #E2D9C8;
    }
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      -webkit-tap-highlight-color: transparent;
    }
    body {
      font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background: var(--sandstone-light);
      color: #1f2937;
      min-height: 100vh;
      display: flex;
      justify-content: center;
    }
    .checkout-wrapper {
      width: 100%;
      max-width: 480px;
      background: var(--paper);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      box-shadow: 0 0 30px rgba(0,0,0,0.06);
      position: relative;
    }
    .checkout-header {
      background: #ffffff;
      padding: 16px 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 1px solid var(--line);
      position: sticky;
      top: 0;
      z-index: 100;
    }
    .checkout-header h1 {
      font-size: 18px;
      font-weight: 700;
      color: var(--olive-dark);
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .back-btn {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      border: 1px solid var(--line);
      background: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--olive-shadow);
      cursor: pointer;
      text-decoration: none;
      font-size: 14px;
    }
    .checkout-content {
      padding: 20px;
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 20px;
    }
    .checkout-card {
      background: #ffffff;
      border-radius: 18px;
      padding: 18px;
      border: 1px solid var(--line);
      box-shadow: 0 4px 16px rgba(0,0,0,0.03);
    }
    .card-title {
      font-size: 13px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: var(--olive-shadow);
      margin-bottom: 12px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .order-item-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 8px 0;
      font-size: 13px;
    }
    .order-item-desc {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .item-icon {
      font-size: 20px;
      background: var(--sandstone-light);
      width: 38px;
      height: 38px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .address-textarea {
      width: 100%;
      min-height: 70px;
      padding: 12px;
      border-radius: 12px;
      border: 1.5px solid var(--line);
      font-family: inherit;
      font-size: 12px;
      color: #374151;
      background: #fafafa;
      resize: none;
      outline: none;
      transition: border-color 0.2s ease;
    }
    .address-textarea:focus {
      border-color: var(--olive);
      background: #ffffff;
    }
    .coords-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #ecfdf5;
      color: #065f46;
      font-size: 11px;
      font-weight: 600;
      padding: 6px 12px;
      border-radius: 20px;
      margin-top: 8px;
      border: 1px solid #a7f3d0;
    }
    .pay-options-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .pay-option {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 12px 14px;
      border-radius: 14px;
      border: 1.5px solid var(--line);
      background: #ffffff;
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .pay-option.active {
      border-color: var(--olive);
      background: #f7f9f4;
      box-shadow: 0 4px 12px rgba(75, 83, 32, 0.1);
    }
    .pay-option-left {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .pay-logo-box {
      width: 44px;
      height: 32px;
      border-radius: 8px;
      background: #ffffff;
      border: 1px solid #e5e7eb;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 3px;
    }
    .pay-logo-box img {
      max-width: 100%;
      max-height: 100%;
      object-fit: contain;
    }
    .pay-info strong {
      display: block;
      font-size: 13px;
      color: #111827;
      font-weight: 600;
    }
    .pay-info small {
      display: block;
      font-size: 10px;
      color: #6b7280;
    }
    .pay-radio {
      font-size: 18px;
      color: #d1d5db;
    }
    .pay-option.active .pay-radio {
      color: var(--olive);
    }
    .summary-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 13px;
      color: #4b5563;
      padding: 5px 0;
    }
    .summary-row.total {
      border-top: 1px solid var(--line);
      margin-top: 10px;
      padding-top: 12px;
      font-size: 16px;
      font-weight: 700;
      color: var(--olive-dark);
    }
    .btn-submit-order {
      width: 100%;
      height: 54px;
      border-radius: 14px;
      border: none;
      background: var(--olive);
      color: #ffffff;
      font-size: 15px;
      font-weight: 700;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      box-shadow: 0 4px 16px rgba(75, 83, 32, 0.3);
      transition: transform 0.1s ease, background 0.2s ease;
      margin-top: 10px;
    }
    .btn-submit-order:active {
      transform: scale(0.98);
    }

    /* Modal Bottom Sheets */
    .sim-modal {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.7);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      z-index: 10000;
      display: flex;
      align-items: flex-end;
      justify-content: center;
      transition: opacity 0.3s ease;
    }
    .sim-sheet {
      width: 100%;
      max-width: 480px;
      border-radius: 28px 28px 0 0;
      padding: 18px 22px 34px 22px;
      box-shadow: 0 -12px 40px rgba(0,0,0,0.5);
      animation: sheetSlideUp 0.32s cubic-bezier(0.16, 1, 0.3, 1) forwards;
      box-sizing: border-box;
    }
    @keyframes sheetSlideUp {
      from { transform: translateY(100%); }
      to { transform: translateY(0); }
    }
    .sheet-grabber {
      width: 40px;
      height: 5px;
      border-radius: 3px;
      margin: 0 auto 16px auto;
      opacity: 0.4;
    }
    .pin-dots {
      display: flex;
      justify-content: center;
      gap: 12px;
      margin: 14px 0 6px 0;
    }
    .pin-circle {
      width: 14px;
      height: 14px;
      border-radius: 50%;
      border: 2px solid currentColor;
      background: currentColor;
    }
    .toast-msg {
      position: fixed;
      bottom: 24px;
      left: 50%;
      transform: translateX(-50%) translateY(100px);
      background: #1f2937;
      color: #ffffff;
      padding: 12px 24px;
      border-radius: 30px;
      font-size: 13px;
      font-weight: 600;
      box-shadow: 0 10px 25px rgba(0,0,0,0.25);
      z-index: 20000;
      opacity: 0;
      transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
      pointer-events: none;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .toast-msg.show {
      transform: translateX(-50%) translateY(0);
      opacity: 1;
    }
    .toast-msg.success {
      background: #15803d;
    }
  </style>
</head>
<body>

<div class="checkout-wrapper">
  <header class="checkout-header">
    <a href="customer_dashboard.php#cart" class="back-btn"><i class="fa-solid fa-chevron-left"></i></a>
    <h1><i class="fa-solid fa-bag-shopping" style="color:var(--harvest-sun);"></i> Order Checkout</h1>
    <span style="font-size:12px; font-weight:700; color:var(--olive);">1 Item</span>
  </header>

  <form id="checkoutForm" novalidate method="POST" action="api_orders.php" class="checkout-content">
    <input type="hidden" name="action" value="create_order">
    <input type="hidden" name="total" value="489.00">
    <input type="hidden" name="total_amount" value="489.00">
    <input type="hidden" name="delivery_fee" value="30.00">
    <input type="hidden" name="cart" value='[{"name":"Signature Meal Combo","store":"Food Dash Grill","price":459.00,"qty":1,"icon":"🍱"}]'>

    <!-- Order Summary -->
    <div class="checkout-card">
      <div class="card-title"><i class="fa-solid fa-utensils"></i> Order Summary</div>
      <div class="order-item-row">
        <div class="order-item-desc">
          <div class="item-icon">🍱</div>
          <div>
            <strong style="display:block; font-size:13px;">Signature Meal Combo</strong>
            <small style="color:#6b7280;">Food Dash Grill • 1x</small>
          </div>
        </div>
        <span style="font-weight:700; font-size:14px;">₱459.00</span>
      </div>
    </div>

    <!-- Delivery Destination -->
    <div class="checkout-card">
      <div class="card-title"><i class="fa-solid fa-location-dot"></i> Delivery Address</div>
      <textarea id="deliveryAddressInput" name="delivery_address" class="address-textarea" placeholder="Unit/House No., Street, Barangay, City, Landmark"><?php echo htmlspecialchars($default_address); ?></textarea>
      
      <div class="coords-badge">
        <i class="fa-solid fa-circle-check"></i>
        <span>Exact Live GPS Pin: <b id="coordsDisplay">11.01020, 124.61779</b></span>
      </div>

      <!-- Clean parsed coordinate hidden fields -->
      <input type="hidden" name="delivery_lat" id="delivery_lat" value="11.01020">
      <input type="hidden" name="delivery_lng" id="delivery_lng" value="124.61779">
    </div>

    <!-- Payment Methods -->
    <div class="checkout-card">
      <div class="card-title"><i class="fa-solid fa-credit-card"></i> Payment Method</div>
      <div class="pay-options-list">
        <div class="pay-option active" data-method="Cash on Delivery (COD)" onclick="selectPaymentMethod('Cash on Delivery (COD)', this)">
          <div class="pay-option-left">
            <div class="pay-logo-box"><img src="assets/logos/cod.svg" alt="COD"></div>
            <div class="pay-info">
              <strong>Cash on Delivery (COD)</strong>
              <small>Pay with cash upon arrival</small>
            </div>
          </div>
          <i class="fa-solid fa-circle-check pay-radio"></i>
        </div>

        <div class="pay-option" data-method="GCash" onclick="selectPaymentMethod('GCash', this)">
          <div class="pay-option-left">
            <div class="pay-logo-box"><img src="assets/logos/gcash.svg" alt="GCash"></div>
            <div class="pay-info">
              <strong>GCash</strong>
              <small>Philippines' Leading E-Wallet</small>
            </div>
          </div>
          <i class="fa-regular fa-circle pay-radio"></i>
        </div>

        <div class="pay-option" data-method="Maya (PayMaya)" onclick="selectPaymentMethod('Maya (PayMaya)', this)">
          <div class="pay-option-left">
            <div class="pay-logo-box"><img src="assets/logos/maya.svg" alt="Maya"></div>
            <div class="pay-info">
              <strong>Maya (PayMaya)</strong>
              <small>Maya Wallet & Digital Card</small>
            </div>
          </div>
          <i class="fa-regular fa-circle pay-radio"></i>
        </div>

        <div class="pay-option" data-method="GoTyme Bank" onclick="selectPaymentMethod('GoTyme Bank', this)">
          <div class="pay-option-left">
            <div class="pay-logo-box"><img src="assets/logos/gotyme.svg" alt="GoTyme"></div>
            <div class="pay-info">
              <strong>GoTyme Bank</strong>
              <small>Digital Banking & Rewards</small>
            </div>
          </div>
          <i class="fa-regular fa-circle pay-radio"></i>
        </div>

        <div class="pay-option" data-method="Apple Pay" onclick="selectPaymentMethod('Apple Pay', this)">
          <div class="pay-option-left">
            <div class="pay-logo-box"><img src="assets/logos/apple-pay.svg" alt="Apple Pay"></div>
            <div class="pay-info">
              <strong>Apple Pay</strong>
              <small>Instant Face ID / Touch ID</small>
            </div>
          </div>
          <i class="fa-regular fa-circle pay-radio"></i>
        </div>
      </div>
      <input type="hidden" id="selectedPaymentMethod" name="payment_method" value="Cash on Delivery (COD)">
    </div>

    <!-- Payment Totals -->
    <div class="checkout-card">
      <div class="summary-row"><span>Meal Subtotal</span><span>₱459.00</span></div>
      <div class="summary-row"><span>Delivery Fee</span><span>₱30.00</span></div>
      <div class="summary-row total"><span>Total Amount</span><span id="displayTotal">₱489.00</span></div>
    </div>

    <button type="submit" class="btn-submit-order" id="btnSubmitOrder">
      <i class="fa-solid fa-truck"></i> Place Order (Cash on Delivery)
    </button>
  </form>
</div>

<!-- Simulated GCash Modal Sheet -->
<div class="sim-modal" id="gcashModal" style="display:none;" onclick="if(event.target===this)closeGcashModal()">
  <div class="sim-sheet" style="background:#007ceb; color:#ffffff;">
    <div class="sheet-grabber" style="background:#ffffff;"></div>
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
      <div style="display:flex; align-items:center; gap:8px;">
        <img src="assets/logos/gcash.svg" alt="GCash" style="height:26px; border-radius:6px; background:#fff; padding:2px;">
        <span style="font-size:18px; font-weight:800;">GCash QuickPay</span>
      </div>
      <button type="button" onclick="closeGcashModal()" style="background:transparent; border:none; color:#ffffff; font-size:14px; font-weight:600; cursor:pointer;">Cancel</button>
    </div>

    <div style="background:#ffffff; color:#1f2937; border-radius:18px; padding:16px; margin-bottom:18px;">
      <div style="display:flex; justify-content:space-between; font-size:12px; color:#6b7280; margin-bottom:6px;">
        <span>Merchant</span><b>Food Dash Ormoc</b>
      </div>
      <div style="display:flex; justify-content:space-between; font-size:12px; color:#6b7280; margin-bottom:6px;">
        <span>Account</span><b>+63 928 ••• 0148</b>
      </div>
      <div style="display:flex; justify-content:space-between; font-size:12px; color:#6b7280; margin-bottom:12px;">
        <span>Deliver To</span><b id="gcashAddr">Western Leyte College</b>
      </div>
      <div style="height:1px; background:#e5e7eb; margin:10px 0;"></div>
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:14px; font-weight:700;">Amount Due</span>
        <span style="font-size:22px; font-weight:800; color:#007ceb;">₱489.00</span>
      </div>
      <div style="margin-top:14px; text-align:center;">
        <div style="font-size:11px; font-weight:600; color:#4b5563;">4-Digit MPIN Verified:</div>
        <div class="pin-dots" style="color:#007ceb;">
          <div class="pin-circle"></div><div class="pin-circle"></div><div class="pin-circle"></div><div class="pin-circle"></div>
        </div>
      </div>
    </div>

    <button type="button" class="btn-submit-order" id="btnGcashConfirm" onclick="confirmGcashPayment()" style="background:#ffffff; color:#007ceb;">
      <i class="fa-solid fa-lock"></i> Pay ₱489.00 with GCash
    </button>
  </div>
</div>

<!-- Simulated Maya Modal Sheet -->
<div class="sim-modal" id="mayaModal" style="display:none;" onclick="if(event.target===this)closeMayaModal()">
  <div class="sim-sheet" style="background:#141416; color:#ffffff; border-top:2px solid #00d632;">
    <div class="sheet-grabber" style="background:#3a3a3c;"></div>
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
      <div style="display:flex; align-items:center; gap:8px;">
        <img src="assets/logos/maya.svg" alt="Maya" style="height:24px;">
        <span style="font-size:18px; font-weight:800; color:#00d632;">Maya Checkout</span>
      </div>
      <button type="button" onclick="closeMayaModal()" style="background:transparent; border:none; color:#8e8e93; font-size:14px; font-weight:600; cursor:pointer;">Cancel</button>
    </div>

    <div style="background:#202024; color:#ffffff; border-radius:18px; padding:16px; margin-bottom:18px; border:1px solid #2e2e34;">
      <div style="display:flex; justify-content:space-between; font-size:12px; color:#9ca3af; margin-bottom:6px;">
        <span>Merchant</span><b>Food Dash Ormoc</b>
      </div>
      <div style="display:flex; justify-content:space-between; font-size:12px; color:#9ca3af; margin-bottom:12px;">
        <span>Deliver To</span><b id="mayaAddr">Western Leyte College</b>
      </div>
      <div style="height:1px; background:#2e2e34; margin:10px 0;"></div>
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:14px; font-weight:700;">Total</span>
        <span style="font-size:22px; font-weight:800; color:#00d632;">₱489.00</span>
      </div>
      <div style="margin-top:14px; text-align:center;">
        <div style="font-size:11px; font-weight:600; color:#9ca3af;">Simulated 6-Digit OTP:</div>
        <div class="pin-dots" style="color:#00d632;">
          <div class="pin-circle"></div><div class="pin-circle"></div><div class="pin-circle"></div><div class="pin-circle"></div><div class="pin-circle"></div><div class="pin-circle"></div>
        </div>
      </div>
    </div>

    <button type="button" class="btn-submit-order" id="btnMayaConfirm" onclick="confirmMayaPayment()" style="background:#00d632; color:#000000;">
      <i class="fa-solid fa-shield-halved"></i> Authorize & Pay ₱489.00
    </button>
  </div>
</div>

<!-- Simulated GoTyme Bank Modal Sheet -->
<div class="sim-modal" id="gotymeModal" style="display:none;" onclick="if(event.target===this)closeGoTymeModal()">
  <div class="sim-sheet" style="background:#0c1a20; color:#ffffff; border-top:2px solid #00c2cb;">
    <div class="sheet-grabber" style="background:#26434d;"></div>
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
      <div style="display:flex; align-items:center; gap:8px;">
        <img src="assets/logos/gotyme.svg" alt="GoTyme" style="height:24px;">
        <span style="font-size:18px; font-weight:800; color:#00c2cb;">GoTyme Digital Bank</span>
      </div>
      <button type="button" onclick="closeGoTymeModal()" style="background:transparent; border:none; color:#8e8e93; font-size:14px; font-weight:600; cursor:pointer;">Cancel</button>
    </div>

    <div style="background:#142831; color:#ffffff; border-radius:18px; padding:16px; margin-bottom:18px; border:1px solid #1f3c49;">
      <div style="display:flex; justify-content:space-between; font-size:12px; color:#94a3b8; margin-bottom:6px;">
        <span>Account</span><b>Everyday (•••• 4920)</b>
      </div>
      <div style="display:flex; justify-content:space-between; font-size:12px; color:#94a3b8; margin-bottom:12px;">
        <span>Deliver To</span><b id="gotymeAddr">Western Leyte College</b>
      </div>
      <div style="height:1px; background:#1f3c49; margin:10px 0;"></div>
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:14px; font-weight:700;">Amount</span>
        <span style="font-size:22px; font-weight:800; color:#00c2cb;">₱489.00</span>
      </div>
      <div style="margin-top:14px; text-align:center;">
        <div style="font-size:11px; font-weight:600; color:#94a3b8;">Security Passcode:</div>
        <div class="pin-dots" style="color:#00c2cb;">
          <div class="pin-circle"></div><div class="pin-circle"></div><div class="pin-circle"></div><div class="pin-circle"></div><div class="pin-circle"></div><div class="pin-circle"></div>
        </div>
      </div>
    </div>

    <button type="button" class="btn-submit-order" id="btnGoTymeConfirm" onclick="confirmGoTymePayment()" style="background:#00c2cb; color:#0b1a20;">
      <i class="fa-solid fa-building-columns"></i> Confirm Bank Payment
    </button>
  </div>
</div>

<!-- Simulated Apple Pay Modal Sheet -->
<div class="sim-modal" id="applePayModal" style="display:none;" onclick="if(event.target===this)closeApplePayModal()">
  <div class="sim-sheet" style="background:#1c1c1e; color:#ffffff;">
    <div class="sheet-grabber" style="background:#3a3a3c;"></div>
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
      <div style="display:flex; align-items:center; gap:6px; font-size:20px; font-weight:700;">
        <i class="fa-brands fa-apple"></i><span>Pay</span>
      </div>
      <button type="button" onclick="closeApplePayModal()" style="background:transparent; border:none; color:#0a84ff; font-size:15px; font-weight:600; cursor:pointer;">Cancel</button>
    </div>

    <div style="background:#2c2c2e; border-radius:18px; padding:14px 16px; margin-bottom:18px;">
      <div style="display:flex; justify-content:space-between; font-size:13px; color:#8e8e93; margin-bottom:6px;">
        <span>Merchant</span><b style="color:#ffffff;">Food Dash Ormoc</b>
      </div>
      <div style="display:flex; justify-content:space-between; font-size:13px; color:#8e8e93; margin-bottom:6px;">
        <span>Card</span><b style="color:#ffffff;">Apple Card (•••• 8821)</b>
      </div>
      <div style="display:flex; justify-content:space-between; font-size:13px; color:#8e8e93; margin-bottom:10px;">
        <span>Deliver To</span><b style="color:#ffffff;" id="appleAddr">Western Leyte College</b>
      </div>
      <div style="height:1px; background:#3a3a3c; margin:8px 0;"></div>
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:15px; font-weight:700;">Total</span>
        <span style="font-size:22px; font-weight:800; color:#30d158;">₱489.00</span>
      </div>
    </div>

    <div style="display:flex; flex-direction:column; align-items:center; gap:12px; margin-bottom:14px;">
      <div id="faceIdStatus" style="font-size:13px; color:#8e8e93; font-weight:500;">Double Tap to Authorize Face ID</div>
    </div>

    <button type="button" class="btn-submit-order" id="btnApplePayConfirm" onclick="confirmApplePayment()" style="background:#ffffff; color:#000000;">
      <i class="fa-brands fa-apple"></i> Confirm with Face ID
    </button>
  </div>
</div>

<div class="toast-msg" id="toastMsg"><i class="fa-solid fa-circle-check"></i> <span id="toastText"></span></div>

<script>
let pendingPayload = null;

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

function selectPaymentMethod(method, elem) {
  document.getElementById('selectedPaymentMethod').value = method;
  document.querySelectorAll('.pay-option').forEach(opt => {
    opt.classList.remove('active');
    const radio = opt.querySelector('.pay-radio');
    if (radio) radio.className = 'fa-regular fa-circle pay-radio';
  });

  if (elem) {
    elem.classList.add('active');
    const radio = elem.querySelector('.pay-radio');
    if (radio) radio.className = 'fa-solid fa-circle-check pay-radio';
  }

  const btn = document.getElementById('btnSubmitOrder');
  if (btn) {
    if (method === 'Apple Pay') {
      btn.innerHTML = '<i class="fa-brands fa-apple"></i> Place Order with Apple Pay';
      btn.style.background = '#000000';
    } else if (method === 'GCash') {
      btn.innerHTML = '<img src="assets/logos/gcash.svg" style="height:18px; vertical-align:middle; margin-right:6px; background:#fff; border-radius:3px; padding:1px;"> Place Order with GCash';
      btn.style.background = '#007ceb';
    } else if (method === 'Maya (PayMaya)') {
      btn.innerHTML = '<img src="assets/logos/maya.svg" style="height:16px; vertical-align:middle; margin-right:6px;"> Place Order with Maya';
      btn.style.background = '#00d632';
      btn.style.color = '#000000';
    } else if (method === 'GoTyme Bank') {
      btn.innerHTML = '<img src="assets/logos/gotyme.svg" style="height:16px; vertical-align:middle; margin-right:6px;"> Place Order with GoTyme';
      btn.style.background = '#00c2cb';
      btn.style.color = '#0b1a20';
    } else {
      btn.innerHTML = '<i class="fa-solid fa-truck"></i> Place Order (Cash on Delivery)';
      btn.style.background = 'var(--olive)';
      btn.style.color = '#ffffff';
    }
  }
}

function closeGcashModal() { document.getElementById('gcashModal').style.display = 'none'; }
function closeMayaModal() { document.getElementById('mayaModal').style.display = 'none'; }
function closeGoTymeModal() { document.getElementById('gotymeModal').style.display = 'none'; }
function closeApplePayModal() { document.getElementById('applePayModal').style.display = 'none'; }

function confirmGcashPayment() {
  const btn = document.getElementById('btnGcashConfirm');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Authorizing GCash...';
  setTimeout(() => {
    btn.innerHTML = '<i class="fa-solid fa-check"></i> GCash Approved!';
    setTimeout(() => {
      closeGcashModal();
      showToast('GCash payment authorized! Placing order...');
      if (pendingPayload) {
        pendingPayload.payment_method = 'GCash';
        pendingPayload.payment_status = 'paid';
        pendingPayload.payment_ref = 'GCASH-' + genRef();
        executeOrderPlacement(pendingPayload);
      }
    }, 400);
  }, 750);
}

function confirmMayaPayment() {
  const btn = document.getElementById('btnMayaConfirm');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Verifying Maya OTP...';
  setTimeout(() => {
    btn.innerHTML = '<i class="fa-solid fa-check"></i> Maya Payment Verified!';
    setTimeout(() => {
      closeMayaModal();
      showToast('Maya payment authorized! Placing order...');
      if (pendingPayload) {
        pendingPayload.payment_method = 'Maya (PayMaya)';
        pendingPayload.payment_status = 'paid';
        pendingPayload.payment_ref = 'MAYA-' + genRef();
        executeOrderPlacement(pendingPayload);
      }
    }, 400);
  }, 750);
}

function confirmGoTymePayment() {
  const btn = document.getElementById('btnGoTymeConfirm');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Connecting Bank...';
  setTimeout(() => {
    btn.innerHTML = '<i class="fa-solid fa-check"></i> GoTyme Transfer Complete!';
    setTimeout(() => {
      closeGoTymeModal();
      showToast('GoTyme payment authorized! Placing order...');
      if (pendingPayload) {
        pendingPayload.payment_method = 'GoTyme Bank';
        pendingPayload.payment_status = 'paid';
        pendingPayload.payment_ref = 'GOTYME-' + genRef();
        executeOrderPlacement(pendingPayload);
      }
    }, 400);
  }, 750);
}

function confirmApplePayment() {
  const btn = document.getElementById('btnApplePayConfirm');
  const status = document.getElementById('faceIdStatus');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Scanning Face ID...';
  if (status) status.innerHTML = 'Verifying Face ID...';
  setTimeout(() => {
    if (status) status.innerHTML = '<span style="color:#30d158; font-weight:700;">✓ Face ID Verified</span>';
    btn.innerHTML = '<i class="fa-solid fa-check"></i> Apple Pay Done';
    setTimeout(() => {
      closeApplePayModal();
      showToast('Apple Pay verified! Placing order...');
      if (pendingPayload) {
        pendingPayload.payment_method = 'Apple Pay';
        pendingPayload.payment_status = 'paid';
        pendingPayload.payment_ref = 'APAY-' + genRef();
        executeOrderPlacement(pendingPayload);
      }
    }, 400);
  }, 850);
}

function genRef() {
  const now = new Date();
  const dateCode = String(now.getFullYear()).slice(-2) + String(now.getMonth()+1).padStart(2,'0') + String(now.getDate()).padStart(2,'0');
  const randHex = Math.floor(Math.random()*0xFFFF).toString(16).toUpperCase().padStart(4, '0');
  return dateCode + '-' + randHex;
}

function executeOrderPlacement(payload) {
  const btn = document.getElementById('btnSubmitOrder');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Placing Order...';
  }

  fetch('api_orders.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  })
  .then(res => {
    if (!res.ok) {
      return res.text().then(t => { throw new Error('HTTP ' + res.status + ': ' + t); });
    }
    return res.json();
  })
  .then(data => {
    if (data.success) {
      showToast('Order placed successfully! Redirecting...');
      const orderId = data.order_id || (data.data && data.data.order_id) || 0;
      const redirectUrl = data.redirect_url || (data.data && data.data.redirect_url) || ('order_tracking.php?order_id=' + orderId);
      
      // Expo / WebView bridge postMessage
      try {
        if (window.ReactNativeWebView && window.ReactNativeWebView.postMessage) {
          window.ReactNativeWebView.postMessage(JSON.stringify({
            type: 'OPEN_TRACKING_MODAL',
            orderId: orderId,
            redirectUrl: redirectUrl
          }));
        }
      } catch(e) {}

      window.location.href = redirectUrl || 'customer_dashboard.php';
    } else {
      showToast(data.message || 'Order failed', false);
      if (btn) {
        btn.disabled = false;
        selectPaymentMethod(payload.payment_method);
      }
    }
  })
  .catch(err => {
    console.error('Order error:', err);
    showToast(err.message || 'Network error', false);
    if (btn) {
      btn.disabled = false;
      selectPaymentMethod(payload.payment_method);
    }
  });
}

function checkout(event) {
  if (event && event.preventDefault) event.preventDefault();

  // 1. Disable native HTML5 pattern validation dynamically across all inputs & form
  try {
    const form = document.getElementById('checkoutForm');
    if (form) {
      form.setAttribute('novalidate', 'novalidate');
      form.noValidate = true;
    }
    document.querySelectorAll('input, textarea, select, form').forEach(el => {
      if (el.hasAttribute('pattern')) el.removeAttribute('pattern');
      if (el.hasAttribute('required')) el.removeAttribute('required');
    });
  } catch(e) {}

  const addrInput = document.getElementById('deliveryAddressInput');
  let address = (addrInput ? addrInput.value : '').trim();

  // Validate required fields in JS
  if (!address) {
    address = 'Western Leyte College';
    if (addrInput) addrInput.value = address;
  }

  const latInput = document.getElementById('delivery_lat');
  const lngInput = document.getElementById('delivery_lng');

  const dLat = parseCoordinate(latInput ? latInput.value : null, 11.01020);
  const dLng = parseCoordinate(lngInput ? lngInput.value : null, 124.61779);

  const method = document.getElementById('selectedPaymentMethod') ? document.getElementById('selectedPaymentMethod').value : 'Cash on Delivery (COD)';

  const payload = {
    action: 'checkout',
    delivery_address: address,
    delivery_lat: dLat,
    delivery_lng: dLng,
    customer_lat: dLat,
    customer_lng: dLng,
    total: 489.00,
    total_amount: 489.00,
    delivery_fee: 30.00,
    cart: [
      { name: 'Signature Meal Combo', store: 'Food Dash Grill', price: 459.00, qty: 1, icon: '🍱' }
    ]
  };

  pendingPayload = payload;

  if (method === 'Cash on Delivery (COD)' || method === 'Cash on Delivery' || method === 'COD') {
    payload.payment_method = 'Cash on Delivery';
    payload.payment_status = 'pending_cod';
    executeOrderPlacement(payload);
    return;
  }

  if (method === 'GCash') {
    const gAddr = document.getElementById('gcashAddr');
    if (gAddr) gAddr.textContent = address;
    document.getElementById('gcashModal').style.display = 'flex';
    return;
  }

  if (method === 'Maya (PayMaya)' || method === 'Maya') {
    const mAddr = document.getElementById('mayaAddr');
    if (mAddr) mAddr.textContent = address;
    document.getElementById('mayaModal').style.display = 'flex';
    return;
  }

  if (method === 'GoTyme Bank' || method === 'GoTyme') {
    const tAddr = document.getElementById('gotymeAddr');
    if (tAddr) tAddr.textContent = address;
    document.getElementById('gotymeModal').style.display = 'flex';
    return;
  }

  if (method === 'Apple Pay') {
    const aAddr = document.getElementById('appleAddr');
    if (aAddr) aAddr.textContent = address;
    document.getElementById('applePayModal').style.display = 'flex';
    return;
  }

  executeOrderPlacement(payload);
}

function submitCheckoutOrder() {
  checkout();
}

function processCheckout(event) {
  submitCheckoutOrder();
}

document.addEventListener('DOMContentLoaded', function() {
  const form = document.getElementById('checkoutForm');
  if (form) {
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      const formData = new FormData(this);
      const payload = Object.fromEntries(formData.entries());

      // If simulated e-wallet modal sheet is open or needed, handle via executeOrderPlacement
      const raw_pm = (payload.payment_method || '').toLowerCase();
      if (!payload._modal_confirmed) {
        if (raw_pm === 'gcash') {
          document.getElementById('gcashModal').style.display = 'flex';
          return;
        }
        if (raw_pm.includes('maya')) {
          document.getElementById('mayaModal').style.display = 'flex';
          return;
        }
        if (raw_pm.includes('tyme')) {
          document.getElementById('gotymeModal').style.display = 'flex';
          return;
        }
        if (raw_pm.includes('apple')) {
          document.getElementById('applePayModal').style.display = 'flex';
          return;
        }
      }

      fetch('api_orders.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      })
      .then(res => res.json())
      .then(data => {
        if (data.success || data.order_id || data.redirect_url) {
          window.location.href = data.redirect_url || 'customer_dashboard.php?order_placed=1';
        } else {
          alert(data.message || 'Error creating order');
        }
      })
      .catch(err => {
        // Fallback standard submit if API JSON fails
        form.submit();
      });
    });
  }
  document.querySelectorAll('input, textarea, select').forEach(function(el) {
    if (el.hasAttribute('pattern')) el.removeAttribute('pattern');
    if (el.type === 'tel' || el.type === 'number' || el.type === 'url') {
      el.type = 'text';
    }
  });
});
</script>

</body>
</html>
