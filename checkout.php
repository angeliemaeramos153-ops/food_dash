<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/api_orders.php';
    exit;
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db_connect.php';

// Authenticated customer session fallback (user 'noah' / ID 26 / ID 1)
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 26;
$customer_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Noah James';
$customer_phone = '+63 928 555 0148';
$default_address = 'Western Leyte College';
$default_lat = 11.01020;
$default_lng = 124.61779;
$total_amount = 200.00;
$meal_subtotal = 170.00;
$delivery_fee = 30.00;
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
      --olive: #283d23;
      --olive-dark: #1e2d18;
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
    .payment-card, .pay-option {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 12px 14px;
      border-radius: 14px;
      border: 1.5px solid var(--line);
      background: #ffffff;
      cursor: pointer;
      transition: all 0.2s ease;
      user-select: none;
    }
    .payment-card.active, .pay-option.active {
      border-color: var(--olive);
      background: #f7f9f4;
      box-shadow: 0 4px 12px rgba(40, 61, 35, 0.12);
    }
    .pay-option-left {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .pay-logo-box {
      width: 52px;
      height: 34px;
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
    .payment-card.active .pay-radio, .pay-option.active .pay-radio {
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
      background: #283d23;
      color: #ffffff;
      font-size: 15px;
      font-weight: 700;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
      transition: transform 0.1s ease, background 0.2s ease, color 0.2s ease;
      margin-top: 10px;
    }
    .btn-submit-order:active {
      transform: scale(0.98);
    }

    /* Modal Bottom Sheet */
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
      transition: opacity 0.25s ease;
    }
    .sim-sheet {
      width: 100%;
      max-width: 480px;
      border-radius: 28px 28px 0 0;
      box-shadow: 0 -12px 40px rgba(0,0,0,0.5);
      animation: sheetSlideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
      box-sizing: border-box;
      overflow: hidden;
      background: #ffffff;
    }
    @keyframes sheetSlideUp {
      from { transform: translateY(100%); }
      to { transform: translateY(0); }
    }
    .sheet-grabber {
      width: 42px;
      height: 5px;
      border-radius: 3px;
      margin: 12px auto 6px auto;
      background: rgba(255,255,255,0.6);
    }

    /* Face ID Scanner Styles */
    .face-id-wrapper {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 16px 0 8px 0;
    }
    .face-id-box {
      width: 80px;
      height: 80px;
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 22px;
      background: #2c2c2e;
      transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .face-id-corner {
      position: absolute;
      width: 20px;
      height: 20px;
      border: 3.5px solid #007aff;
      transition: border-color 0.3s ease, transform 0.3s ease;
    }
    .face-id-corner.top-left {
      top: 0; left: 0;
      border-right: none; border-bottom: none;
      border-top-left-radius: 16px;
    }
    .face-id-corner.top-right {
      top: 0; right: 0;
      border-left: none; border-bottom: none;
      border-top-right-radius: 16px;
    }
    .face-id-corner.bottom-left {
      bottom: 0; left: 0;
      border-right: none; border-top: none;
      border-bottom-left-radius: 16px;
    }
    .face-id-corner.bottom-right {
      bottom: 0; right: 0;
      border-left: none; border-top: none;
      border-bottom-right-radius: 16px;
    }
    .face-id-box.scanning {
      background: #1c2834;
      box-shadow: 0 0 24px rgba(0, 122, 255, 0.4);
    }
    .face-id-box.scanning .face-id-corner {
      animation: cornerPulse 1.2s ease-in-out infinite alternate;
    }
    @keyframes cornerPulse {
      0% { transform: scale(1); opacity: 0.85; }
      100% { transform: scale(1.08); opacity: 1; }
    }
    .face-id-scan-line {
      position: absolute;
      width: 84%;
      height: 2.5px;
      background: linear-gradient(90deg, rgba(0,122,255,0) 0%, #007aff 50%, rgba(0,122,255,0) 100%);
      box-shadow: 0 0 10px #007aff, 0 0 4px #007aff;
      animation: faceScan 1s ease-in-out infinite alternate;
    }
    @keyframes faceScan {
      0% { transform: translateY(-26px); opacity: 0.4; }
      100% { transform: translateY(26px); opacity: 1; }
    }
    .face-id-box.success {
      background: #182f22;
      box-shadow: 0 0 26px rgba(48, 209, 88, 0.45);
    }
    .face-id-box.success .face-id-corner {
      border-color: #30d158;
    }
    @keyframes popCheck {
      0% { transform: scale(0.3); opacity: 0; }
      65% { transform: scale(1.18); }
      100% { transform: scale(1); opacity: 1; }
    }
    .check-pop {
      animation: popCheck 0.35s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
    }
    @keyframes pulseScanText {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.55; }
    }
    .pulse-scan {
      animation: pulseScanText 0.8s ease-in-out infinite;
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

  <!-- 1. Form with novalidate and onsubmit="return false;" to prevent WebKit validation -->
  <form id="checkoutForm" novalidate onsubmit="return false;" class="checkout-content">
    <input type="text" style="display:none;" name="action" value="create_order">
    <input type="text" style="display:none;" name="total" value="200.00">
    <input type="text" style="display:none;" name="total_amount" value="200.00">
    <input type="text" style="display:none;" name="delivery_fee" value="30.00">
    <input type="text" style="display:none;" name="cart" value='[{"name":"Signature Meal Combo","store":"Food Dash Grill","price":170.00,"qty":1,"icon":"🍱"}]'>

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
        <span style="font-weight:700; font-size:14px;">₱170.00</span>
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

      <!-- Clean parsed coordinate text fields (no strict patterns) -->
      <input type="text" style="display:none;" name="delivery_lat" id="delivery_lat" value="11.01020">
      <input type="text" style="display:none;" name="delivery_lng" id="delivery_lng" value="124.61779">
    </div>

    <!-- Payment Methods: 5 Options with active classes and radio inputs -->
    <div class="checkout-card">
      <div class="card-title"><i class="fa-solid fa-credit-card"></i> Payment Method</div>
      <div class="pay-options-list">
        <!-- Cash on Delivery -->
        <div class="payment-card pay-option active" data-method="Cash on Delivery (COD)">
          <div class="pay-option-left">
            <div class="pay-logo-box"><img src="assets/logos/cod.svg" alt="COD"></div>
            <div class="pay-info">
              <strong>Cash on Delivery (COD)</strong>
              <small>Pay with cash upon arrival</small>
            </div>
          </div>
          <input type="radio" name="payment_method" value="Cash on Delivery (COD)" checked style="display:none;">
          <i class="fa-solid fa-circle-check pay-radio"></i>
        </div>

        <!-- GCash -->
        <div class="payment-card pay-option" data-method="GCash">
          <div class="pay-option-left">
            <div class="pay-logo-box"><img src="assets/logos/gcash.svg" alt="GCash"></div>
            <div class="pay-info">
              <strong>GCash</strong>
              <small>Philippines' Leading E-Wallet</small>
            </div>
          </div>
          <input type="radio" name="payment_method" value="GCash" style="display:none;">
          <i class="fa-regular fa-circle pay-radio"></i>
        </div>

        <!-- Maya -->
        <div class="payment-card pay-option" data-method="Maya (PayMaya)">
          <div class="pay-option-left">
            <div class="pay-logo-box"><img src="assets/logos/maya.svg" alt="Maya"></div>
            <div class="pay-info">
              <strong>Maya (PayMaya)</strong>
              <small>Maya Wallet & Digital Card</small>
            </div>
          </div>
          <input type="radio" name="payment_method" value="Maya (PayMaya)" style="display:none;">
          <i class="fa-regular fa-circle pay-radio"></i>
        </div>

        <!-- GoTyme Bank -->
        <div class="payment-card pay-option" data-method="GoTyme Bank">
          <div class="pay-option-left">
            <div class="pay-logo-box"><img src="assets/logos/gotyme.svg" alt="GoTyme"></div>
            <div class="pay-info">
              <strong>GoTyme Bank</strong>
              <small>Digital Banking & Rewards</small>
            </div>
          </div>
          <input type="radio" name="payment_method" value="GoTyme Bank" style="display:none;">
          <i class="fa-regular fa-circle pay-radio"></i>
        </div>

        <!-- Apple Pay -->
        <div class="payment-card pay-option" data-method="Apple Pay">
          <div class="pay-option-left">
            <div class="pay-logo-box" style="background:#000;"><img src="assets/logos/apple-pay.svg" alt="Apple Pay"></div>
            <div class="pay-info">
              <strong>Apple Pay</strong>
              <small>Instant Face ID / Touch ID</small>
            </div>
          </div>
          <input type="radio" name="payment_method" value="Apple Pay" style="display:none;">
          <i class="fa-regular fa-circle pay-radio"></i>
        </div>
      </div>
      <input type="text" style="display:none;" id="selectedPaymentMethod" name="payment_method_val" value="Cash on Delivery (COD)">
    </div>

    <!-- Payment Totals -->
    <div class="checkout-card">
      <div class="summary-row"><span>Meal Subtotal</span><span>₱170.00</span></div>
      <div class="summary-row"><span>Delivery Fee</span><span>₱30.00</span></div>
      <div class="summary-row total"><span>Total Amount</span><span id="displayTotal">₱200.00</span></div>
    </div>

    <!-- 1. Place Order button with type="button" and id="placeOrderBtn" -->
    <button type="button" class="btn-submit-order" id="placeOrderBtn" onclick="handlePlaceOrderClick(event)">
      <i class="fa-solid fa-money-bill-wave"></i> Place Order • ₱200.00
    </button>
  </form>
</div>

<!-- ============================================== -->
<!-- 1. APPLE PAY NATIVE IOS-STYLE BOTTOM SHEET MODAL -->
<!-- ============================================== -->
<div class="sim-modal" id="applePayModal" style="display:none;" onclick="if(event.target===this)closeApplePayModal()">
  <div class="sim-sheet" style="background:#1c1c1e; color:#ffffff;">
    <div class="sheet-grabber" style="background:#3a3a3c;"></div>
    <div style="display:flex; align-items:center; justify-content:space-between; padding:4px 22px 14px 22px; border-bottom:1px solid #2c2c2e;">
      <div style="display:flex; align-items:center; gap:6px; font-size:20px; font-weight:700;">
        <span style="font-size:22px;"></span><span>Pay</span>
      </div>
      <button type="button" onclick="closeApplePayModal()" style="background:transparent; border:none; color:#0a84ff; font-size:15px; font-weight:600; cursor:pointer;">Cancel</button>
    </div>

    <div style="padding:18px 22px 28px 22px;">
      <!-- Order details card in dark mode -->
      <div style="background:#2c2c2e; border-radius:18px; padding:16px; margin-bottom:16px;">
        <div style="display:flex; justify-content:space-between; font-size:13px; color:#8e8e93; margin-bottom:6px;">
          <span>Merchant</span><b style="color:#ffffff;">Food Dash Ormoc</b>
        </div>
        <div style="display:flex; justify-content:space-between; font-size:13px; color:#8e8e93; margin-bottom:6px;">
          <span>Card</span><b style="color:#ffffff;">Apple Card (•••• 8821)</b>
        </div>
        <div style="display:flex; justify-content:space-between; font-size:13px; color:#8e8e93; margin-bottom:10px;">
          <span>Deliver To</span><b style="color:#ffffff;" id="appleModalDest">Western Leyte College</b>
        </div>
        <div style="height:1px; background:#3a3a3c; margin:8px 0;"></div>
        <div style="display:flex; justify-content:space-between; align-items:center;">
          <span style="font-size:15px; font-weight:700;">Total</span>
          <span style="font-size:22px; font-weight:800; color:#30d158;">₱200.00</span>
        </div>
      </div>

      <!-- Face ID Radar Viewfinder Animation Container -->
      <div class="face-id-wrapper">
        <div class="face-id-box" id="appleFaceIdBox">
          <div class="face-id-corner top-left"></div>
          <div class="face-id-corner top-right"></div>
          <div class="face-id-corner bottom-left"></div>
          <div class="face-id-corner bottom-right"></div>
          <svg id="appleFaceIdSvg" viewBox="0 0 48 48" width="46" height="46">
            <circle cx="16" cy="18" r="2.2" fill="#007aff"/>
            <circle cx="32" cy="18" r="2.2" fill="#007aff"/>
            <path d="M 24,18 L 24,26 L 20.5,26" fill="none" stroke="#007aff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M 15,31 C 18,36 30,36 33,31" fill="none" stroke="#007aff" stroke-width="2.4" stroke-linecap="round"/>
          </svg>
          <div id="appleFaceIdCheck" style="display:none;">
            <i class="fa-solid fa-circle-check check-pop" style="font-size: 48px; color: #30d158;"></i>
          </div>
          <div class="face-id-scan-line" id="appleFaceIdScanLine" style="display:none;"></div>
        </div>
        <div id="appleFaceIdStatusText" style="font-size:14px; font-weight:700; color:#8e8e93; margin-top:12px;">
          Double Tap to Authorize Face ID
        </div>
      </div>

      <button type="button" class="btn-submit-order" id="btnApplePayTrigger" onclick="openApplePayModal()" style="background:#ffffff; color:#000000; margin-top:16px;">
        <span style="font-size:18px;"></span> Confirm with Face ID
      </button>
    </div>
  </div>
</div>

<!-- ============================================== -->
<!-- 2. BRANDED WALLET MODAL (GCash / Maya / GoTyme) -->
<!-- ============================================== -->
<div class="sim-modal" id="walletModal" style="display:none;" onclick="if(event.target===this)closeWalletModal()">
  <div class="sim-sheet" style="background:#ffffff;">
    <div id="walletBrandBanner" style="background:#007dfe; color:#ffffff; padding:16px 20px 18px 20px; transition:background 0.25s ease;">
      <div class="sheet-grabber"></div>
      <div style="display:flex; align-items:center; justify-content:space-between; margin-top:8px;">
        <div style="display:flex; align-items:center; gap:10px;">
          <img id="walletBrandLogo" src="assets/logos/gcash.svg" alt="Provider" style="height:26px; border-radius:6px; background:#fff; padding:2px;">
          <div>
            <div id="walletBrandTitle" style="font-size:16px; font-weight:800; line-height:1.2;">GCash QuickPay</div>
            <small id="walletBrandSub" style="font-size:11px; opacity:0.85;">Official Digital Payment</small>
          </div>
        </div>
        <button type="button" onclick="closeWalletModal()" style="background:rgba(255,255,255,0.18); border:none; color:#ffffff; font-size:12px; font-weight:700; padding:6px 12px; border-radius:20px; cursor:pointer;">Cancel</button>
      </div>
    </div>

    <div style="padding:22px; background:#ffffff;">
      <div style="text-align:center; margin-bottom:16px;">
        <div style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#6b7280; margin-bottom:4px;">Payment Authorization</div>
        <h2 style="font-size:22px; font-weight:800; color:#111827;">Total: <span id="walletTotalDisplay" style="color:#007dfe;">₱200.00</span></h2>
      </div>

      <!-- Destination Card -->
      <div style="background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:16px; padding:12px 16px; margin-bottom:14px;">
        <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
          <i class="fa-solid fa-location-dot" style="color:#ef4444;"></i> Delivery Destination:
        </div>
        <div id="walletDestDisplay" style="font-size:14px; font-weight:700; color:#1e293b;">Western Leyte College</div>
      </div>

      <!-- 4-Digit PIN / OTP Simulator Field -->
      <div style="background:#fdfbf7; border:1px solid #e8decb; border-radius:16px; padding:16px; margin-bottom:18px; text-align:center;">
        <label for="walletPinInput" style="display:block; font-size:12px; font-weight:700; color:#374151; margin-bottom:8px;">
          <i class="fa-solid fa-lock" style="color:#007dfe;"></i> Enter 4-Digit MPIN / Security OTP:
        </label>
        <div style="display:flex; justify-content:center; margin-bottom:6px;">
          <input type="password" id="walletPinInput" maxlength="4" value="1234" autocomplete="off" style="width:170px; height:46px; text-align:center; font-size:24px; letter-spacing:10px; border-radius:12px; border:2px solid #007dfe; font-family:monospace; font-weight:800; outline:none; background:#ffffff; color:#1e293b;">
        </div>
        <small style="color:#6b7280; font-size:11px;"><i class="fa-solid fa-circle-check" style="color:#10b981;"></i> Security Verified • Demo Auto-Filled</small>
      </div>

      <!-- Authorize & Pay Button -->
      <button type="button" class="btn-submit-order" id="btnWalletConfirm" onclick="submitWalletOrder()" style="background:#007dfe; margin-top:0;">
        <i class="fa-solid fa-shield-halved"></i> Authorize & Pay
      </button>

      <div style="text-align:center; margin-top:12px;">
        <a href="javascript:void(0)" onclick="closeWalletModal()" style="font-size:13px; font-weight:600; color:#64748b; text-decoration:none;">Cancel</a>
      </div>
    </div>
  </div>
</div>

<!-- ============================================== -->
<!-- 3. CASH ON DELIVERY (COD) ORDER REVIEW MODAL   -->
<!-- ============================================== -->
<div class="sim-modal" id="codModal" style="display:none;" onclick="if(event.target===this)closeCodModal()">
  <div class="sim-sheet" style="background:#ffffff;">
    <div style="background:#283d23; color:#ffffff; padding:16px 20px 18px 20px;">
      <div class="sheet-grabber"></div>
      <div style="display:flex; align-items:center; justify-content:space-between; margin-top:8px;">
        <div style="display:flex; align-items:center; gap:10px;">
          <img src="assets/logos/cod.svg" alt="COD" style="height:26px; border-radius:6px; background:#fff; padding:2px;">
          <div>
            <div style="font-size:16px; font-weight:800; line-height:1.2;">Cash on Delivery</div>
            <small style="font-size:11px; opacity:0.85;">Pay with cash upon arrival</small>
          </div>
        </div>
        <button type="button" onclick="closeCodModal()" style="background:rgba(255,255,255,0.18); border:none; color:#ffffff; font-size:12px; font-weight:700; padding:6px 12px; border-radius:20px; cursor:pointer;">Cancel</button>
      </div>
    </div>

    <div style="padding:22px; background:#ffffff;">
      <div style="text-align:center; margin-bottom:16px;">
        <div style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#6b7280; margin-bottom:4px;">Order Summary</div>
        <h2 style="font-size:22px; font-weight:800; color:#111827;">Total Amount: <span style="color:#283d23;">₱200.00</span></h2>
      </div>

      <!-- Destination Card -->
      <div style="background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:16px; padding:12px 16px; margin-bottom:14px;">
        <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
          <i class="fa-solid fa-location-dot" style="color:#ef4444;"></i> Delivery Destination:
        </div>
        <div id="codDestDisplay" style="font-size:14px; font-weight:700; color:#1e293b;">Western Leyte College</div>
      </div>

      <!-- Cash preparation advice -->
      <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:14px; padding:14px; margin-bottom:18px; font-size:12px; color:#166534; display:flex; align-items:center; gap:10px;">
        <i class="fa-solid fa-money-bill-wave" style="font-size:20px; color:#15803d;"></i>
        <span>Please prepare exact cash of <b>₱200.00</b> upon arrival for swift handover.</span>
      </div>

      <!-- Confirm Order Button -->
      <button type="button" class="btn-submit-order" id="btnCodConfirm" onclick="submitCodOrder()" style="background:#283d23; margin-top:0;">
        <i class="fa-solid fa-check"></i> Confirm Order
      </button>

      <div style="text-align:center; margin-top:12px;">
        <a href="javascript:void(0)" onclick="closeCodModal()" style="font-size:13px; font-weight:600; color:#64748b; text-decoration:none;">Cancel</a>
      </div>
    </div>
  </div>
</div>

<div class="toast-msg" id="toastMsg"><i class="fa-solid fa-circle-check"></i> <span id="toastText"></span></div>

<!-- 5. Cache-busted script loader -->
<script src="assets/js/checkout.js?v=<?= time() ?>"></script>

</body>
</html>
