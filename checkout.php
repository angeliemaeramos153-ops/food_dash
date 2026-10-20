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
      --olive: #2f4325;
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
      box-shadow: 0 4px 12px rgba(47, 67, 37, 0.12);
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
      background: #2f4325;
      color: #ffffff;
      font-size: 15px;
      font-weight: 700;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
      transition: transform 0.1s ease, background 0.25s ease, color 0.25s ease;
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
      transition: opacity 0.28s ease;
    }
    .sim-sheet {
      width: 100%;
      max-width: 480px;
      border-radius: 28px 28px 0 0;
      box-shadow: 0 -12px 40px rgba(0,0,0,0.5);
      animation: sheetSlideUp 0.32s cubic-bezier(0.16, 1, 0.3, 1) forwards;
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
    .pin-dots {
      display: flex;
      justify-content: center;
      gap: 12px;
      margin: 10px 0 4px 0;
    }
    .pin-circle {
      width: 13px;
      height: 13px;
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
    /* Face ID Scanner Animation */
    .face-id-wrapper {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 10px 0 6px 0;
    }
    .face-id-box {
      width: 76px;
      height: 76px;
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 20px;
      background: #f1f5f9;
      transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .face-id-corner {
      position: absolute;
      width: 18px;
      height: 18px;
      border: 3px solid #007aff;
      transition: border-color 0.3s ease, transform 0.3s ease;
    }
    .face-id-corner.top-left {
      top: 0; left: 0;
      border-right: none; border-bottom: none;
      border-top-left-radius: 14px;
    }
    .face-id-corner.top-right {
      top: 0; right: 0;
      border-left: none; border-bottom: none;
      border-top-right-radius: 14px;
    }
    .face-id-corner.bottom-left {
      bottom: 0; left: 0;
      border-right: none; border-top: none;
      border-bottom-left-radius: 14px;
    }
    .face-id-corner.bottom-right {
      bottom: 0; right: 0;
      border-left: none; border-top: none;
      border-bottom-right-radius: 14px;
    }
    .face-id-box.scanning {
      background: #e0f2fe;
      box-shadow: 0 0 20px rgba(0, 122, 255, 0.28);
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
      width: 82%;
      height: 2.5px;
      background: linear-gradient(90deg, rgba(0,122,255,0) 0%, #007aff 50%, rgba(0,122,255,0) 100%);
      box-shadow: 0 0 10px #007aff, 0 0 4px #007aff;
      animation: faceScan 1s ease-in-out infinite alternate;
    }
    @keyframes faceScan {
      0% { transform: translateY(-24px); opacity: 0.4; }
      100% { transform: translateY(24px); opacity: 1; }
    }
    .face-id-box.success {
      background: #ecfdf5;
      box-shadow: 0 0 24px rgba(48, 209, 88, 0.35);
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
    <input type="hidden" name="total" value="200.00">
    <input type="hidden" name="total_amount" value="200.00">
    <input type="hidden" name="delivery_fee" value="30.00">
    <input type="hidden" name="cart" value='[{"name":"Signature Meal Combo","store":"Food Dash Grill","price":170.00,"qty":1,"icon":"🍱"}]'>

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

      <!-- Clean parsed coordinate hidden fields -->
      <input type="hidden" name="delivery_lat" id="delivery_lat" value="11.01020">
      <input type="hidden" name="delivery_lng" id="delivery_lng" value="124.61779">
    </div>

    <!-- Payment Methods -->
    <div class="checkout-card">
      <div class="card-title"><i class="fa-solid fa-credit-card"></i> Payment Method</div>
      <div class="pay-options-list">
        <!-- Cash on Delivery -->
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

        <!-- GCash -->
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

        <!-- Maya -->
        <div class="pay-option" data-method="Maya" onclick="selectPaymentMethod('Maya', this)">
          <div class="pay-option-left">
            <div class="pay-logo-box"><img src="assets/logos/maya.svg" alt="Maya"></div>
            <div class="pay-info">
              <strong>Maya</strong>
              <small>Maya Wallet & Digital Card</small>
            </div>
          </div>
          <i class="fa-regular fa-circle pay-radio"></i>
        </div>

        <!-- GoTyme Bank -->
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

        <!-- Apple Pay -->
        <div class="pay-option" data-method="Apple Pay" onclick="selectPaymentMethod('Apple Pay', this)">
          <div class="pay-option-left">
            <div class="pay-logo-box" style="background:#000;"><img src="assets/logos/apple-pay.svg" alt="Apple Pay"></div>
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
      <div class="summary-row"><span>Meal Subtotal</span><span>₱170.00</span></div>
      <div class="summary-row"><span>Delivery Fee</span><span>₱30.00</span></div>
      <div class="summary-row total"><span>Total Amount</span><span id="displayTotal">₱200.00</span></div>
    </div>

    <!-- Branded Dynamic Submit Button -->
    <button type="submit" class="btn-submit-order" id="btnSubmitOrder">
      <img src="assets/logos/cod.svg" alt="COD" style="height:22px; vertical-align:middle; border-radius:4px; background:#fff; padding:1px;">
      <span>Place Order • ₱200.00</span>
    </button>
  </form>
</div>

<!-- Interactive Payment Confirmation Modal -->
<div class="sim-modal" id="paymentConfirmModal" style="display:none;" onclick="if(event.target===this)closePaymentConfirmModal()">
  <div class="sim-sheet" id="modalSheetContainer">
    <!-- Brand Banner Header -->
    <div id="modalBrandBanner" style="background:#2f4325; color:#ffffff; padding:16px 20px 18px 20px; transition:background 0.25s ease;">
      <div class="sheet-grabber"></div>
      <div style="display:flex; align-items:center; justify-content:space-between; margin-top:8px;">
        <div style="display:flex; align-items:center; gap:10px;">
          <div id="modalLogoWrapper" style="display:flex; align-items:center;">
            <img id="modalProviderLogo" src="assets/logos/cod.svg" alt="Provider" style="height:26px; border-radius:6px; background:#fff; padding:2px;">
          </div>
          <div>
            <div id="modalProviderTitle" style="font-size:16px; font-weight:800; line-height:1.2;">Cash on Delivery</div>
            <small id="modalProviderSubtitle" style="font-size:11px; opacity:0.85;">Official Payment Method</small>
          </div>
        </div>
        <button type="button" onclick="closePaymentConfirmModal()" style="background:rgba(255,255,255,0.18); border:none; color:#ffffff; font-size:12px; font-weight:700; padding:6px 12px; border-radius:20px; cursor:pointer;">Cancel</button>
      </div>
    </div>

    <!-- Modal Content Body -->
    <div style="padding:22px; background:#ffffff;">
      <!-- Title: Confirm Payment of ₱200.00 -->
      <div style="text-align:center; margin-bottom:18px;">
        <div style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#6b7280; margin-bottom:4px;">Payment Verification</div>
        <h2 style="font-size:22px; font-weight:800; color:#111827;">Confirm Payment of <span id="modalHeadlineTotal" style="color:#2f4325;">₱200.00</span></h2>
      </div>

      <!-- Destination Card -->
      <div style="background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:16px; padding:14px 16px; margin-bottom:14px;">
        <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
          <i class="fa-solid fa-location-dot" style="color:#ef4444;"></i> Delivery Destination:
        </div>
        <div id="modalConfirmDestination" style="font-size:14px; font-weight:700; color:#1e293b;">
          Western Leyte College
        </div>
        <div style="font-size:11px; color:#059669; font-weight:600; margin-top:4px; display:flex; align-items:center; gap:4px;">
          <i class="fa-solid fa-circle-check"></i> Exact GPS Pin: 11.01020, 124.61779
        </div>
      </div>

      <!-- Breakdown Card -->
      <div style="background:#fdfbf7; border:1px solid #e8decb; border-radius:14px; padding:12px 14px; margin-bottom:16px; font-size:12px;">
        <div style="display:flex; justify-content:space-between; margin-bottom:4px; color:#475569;">
          <span>Signature Meal Combo</span>
          <span style="font-weight:600;">₱170.00</span>
        </div>
        <div style="display:flex; justify-content:space-between; margin-bottom:8px; color:#475569;">
          <span>Delivery Fee</span>
          <span style="font-weight:600;">₱30.00</span>
        </div>
        <div style="height:1px; background:#e2d9c8; margin:6px 0;"></div>
        <div style="display:flex; justify-content:space-between; font-weight:800; font-size:14px; color:#1e293b;">
          <span>Total</span>
          <span>₱200.00</span>
        </div>
      </div>

      <!-- Provider-specific dynamic security indicator -->
      <div id="modalSecurityNotice" style="margin-bottom:18px; text-align:center;">
        <!-- Injected via JS -->
      </div>

      <!-- Confirm & Pay Button -->
      <button type="button" class="btn-submit-order" id="btnConfirmAndPay" onclick="executeConfirmedPayment()" style="background:#2f4325; margin-top:0;">
        <i class="fa-solid fa-lock"></i> Confirm & Pay
      </button>

      <!-- Cancel Link -->
      <div style="text-align:center; margin-top:12px;">
        <a href="javascript:void(0)" onclick="closePaymentConfirmModal()" style="font-size:13px; font-weight:600; color:#64748b; text-decoration:none; padding:4px 10px;">Cancel</a>
      </div>
    </div>
  </div>
</div>

<div class="toast-msg" id="toastMsg"><i class="fa-solid fa-circle-check"></i> <span id="toastText"></span></div>

<script>
let currentMethod = 'Cash on Delivery (COD)';

const BRAND_CONFIGS = {
  'Cash on Delivery (COD)': {
    key: 'cod',
    color: '#2f4325',
    headlineColor: '#2f4325',
    textColor: '#ffffff',
    btnText: 'Place Order • ₱200.00',
    iconHtml: '<img src="assets/logos/cod.svg" alt="COD" style="height:22px; vertical-align:middle; border-radius:4px; background:#fff; padding:1px;">',
    title: 'Cash on Delivery (COD)',
    subtitle: 'Pay with cash upon arrival',
    logoSrc: 'assets/logos/cod.svg',
    securityNotice: '<div style="font-size:11px; font-weight:600; color:#4b5563;"><i class="fa-solid fa-money-bill-wave" style="color:#2f4325; margin-right:4px;"></i> Please prepare exact cash (₱200.00) upon courier arrival</div>'
  },
  'GCash': {
    key: 'gcash',
    color: '#007DFE',
    headlineColor: '#007DFE',
    textColor: '#ffffff',
    btnText: 'Pay with GCash • ₱200.00',
    iconHtml: '<img src="assets/logos/gcash.svg" alt="GCash" style="height:22px; vertical-align:middle; border-radius:4px; background:#fff; padding:1px;">',
    title: 'GCash Express Checkout',
    subtitle: 'Philippines\' Leading E-Wallet',
    logoSrc: 'assets/logos/gcash.svg',
    securityNotice: '<div style="font-size:11px; font-weight:600; color:#4b5563;">4-Digit MPIN Verified:<div class="pin-dots" style="color:#007DFE;"><div class="pin-circle"></div><div class="pin-circle"></div><div class="pin-circle"></div><div class="pin-circle"></div></div></div>'
  },
  'Maya': {
    key: 'maya',
    color: '#003323',
    headlineColor: '#05B851',
    textColor: '#ffffff',
    btnText: 'Pay with Maya • ₱200.00',
    iconHtml: '<img src="assets/logos/maya.svg" alt="Maya" style="height:22px; vertical-align:middle; border-radius:4px; background:#0d1117; padding:1px;">',
    title: 'Maya QuickPay',
    subtitle: 'Maya Wallet & Digital Card',
    logoSrc: 'assets/logos/maya.svg',
    securityNotice: '<div style="font-size:11px; font-weight:600; color:#4b5563;">Simulated 6-Digit OTP Verified:<div class="pin-dots" style="color:#05B851;"><div class="pin-circle"></div><div class="pin-circle"></div><div class="pin-circle"></div><div class="pin-circle"></div><div class="pin-circle"></div><div class="pin-circle"></div></div></div>'
  },
  'GoTyme Bank': {
    key: 'gotyme',
    color: '#0a2540',
    headlineColor: '#00c2cb',
    textColor: '#ffffff',
    btnText: 'Pay with GoTyme • ₱200.00',
    iconHtml: '<img src="assets/logos/gotyme.svg" alt="GoTyme" style="height:22px; vertical-align:middle; border-radius:4px;">',
    title: 'GoTyme Digital Bank',
    subtitle: 'Everyday Account (•••• 4920)',
    logoSrc: 'assets/logos/gotyme.svg',
    securityNotice: '<div style="font-size:11px; font-weight:600; color:#4b5563;">Bank Biometric Passcode Authorized:<div class="pin-dots" style="color:#00c2cb;"><div class="pin-circle"></div><div class="pin-circle"></div><div class="pin-circle"></div><div class="pin-circle"></div></div></div>'
  },
  'Apple Pay': {
    key: 'apple',
    color: '#000000',
    headlineColor: '#000000',
    textColor: '#ffffff',
    btnText: ' Pay • ₱200.00',
    iconHtml: '<i class="fa-brands fa-apple" style="font-size:18px; margin-right:2px; vertical-align:-1px;"></i>',
    title: 'Apple Pay',
    subtitle: 'Apple Card (•••• 8821)',
    logoSrc: 'assets/logos/apple-pay.svg',
    securityNotice: `
      <div class="face-id-wrapper" id="faceIdContainer">
        <div class="face-id-box" id="faceIdBox">
          <div class="face-id-corner top-left"></div>
          <div class="face-id-corner top-right"></div>
          <div class="face-id-corner bottom-left"></div>
          <div class="face-id-corner bottom-right"></div>
          <svg id="faceIdSvg" viewBox="0 0 48 48" width="46" height="46">
            <circle cx="16" cy="18" r="2.2" fill="#007aff"/>
            <circle cx="32" cy="18" r="2.2" fill="#007aff"/>
            <path d="M 24,18 L 24,26 L 20.5,26" fill="none" stroke="#007aff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M 15,31 C 18,36 30,36 33,31" fill="none" stroke="#007aff" stroke-width="2.4" stroke-linecap="round"/>
          </svg>
          <div id="faceIdSuccessCheck" style="display:none;">
            <i class="fa-solid fa-circle-check check-pop" style="font-size: 48px; color: #30d158;"></i>
          </div>
          <div class="face-id-scan-line" id="faceIdScanLine" style="display:none;"></div>
        </div>
        <div id="faceIdText" style="font-size:13px; font-weight:700; color:#475569; margin-top:10px;">
          Double-click side button or tap Confirm & Pay to verify Face ID
        </div>
      </div>
    `
  }
};

function playConfirmationCue() {
  // Mobile vibration feedback
  try {
    if (navigator.vibrate) {
      navigator.vibrate([35, 45, 35]);
    }
  } catch(e) {}

  // Expo WebView bridge support
  try {
    if (window.ReactNativeWebView && window.ReactNativeWebView.postMessage) {
      window.ReactNativeWebView.postMessage(JSON.stringify({ type: 'HAPTIC_CONFIRMATION' }));
    }
  } catch(e) {}

  // Native Web Audio synth cue (pleasant Apple Pay double chime)
  try {
    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    if (AudioCtx) {
      const ctx = new AudioCtx();
      // Tone 1
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

      // Tone 2
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
  currentMethod = method;
  const inputMethod = document.getElementById('selectedPaymentMethod');
  if (inputMethod) inputMethod.value = method;

  document.querySelectorAll('.pay-option').forEach(opt => {
    opt.classList.remove('active');
    const radio = opt.querySelector('.pay-radio');
    if (radio) radio.className = 'fa-regular fa-circle pay-radio';
  });

  if (elem) {
    elem.classList.add('active');
    const radio = elem.querySelector('.pay-radio');
    if (radio) radio.className = 'fa-solid fa-circle-check pay-radio';
  } else {
    // Match by data-method
    document.querySelectorAll('.pay-option').forEach(opt => {
      if (opt.getAttribute('data-method') === method || opt.getAttribute('data-method').includes(method)) {
        opt.classList.add('active');
        const radio = opt.querySelector('.pay-radio');
        if (radio) radio.className = 'fa-solid fa-circle-check pay-radio';
      }
    });
  }

  // Update dynamic submit button branding
  const cfg = BRAND_CONFIGS[method] || BRAND_CONFIGS['Cash on Delivery (COD)'];
  const btn = document.getElementById('btnSubmitOrder');
  if (btn) {
    btn.style.background = cfg.color;
    btn.style.color = cfg.textColor;
    btn.innerHTML = `${cfg.iconHtml} <span>${cfg.btnText}</span>`;
  }
}

function openPaymentConfirmModal() {
  const addrInput = document.getElementById('deliveryAddressInput');
  let address = (addrInput ? addrInput.value : '').trim();
  if (!address) {
    address = 'Western Leyte College';
    if (addrInput) addrInput.value = address;
  }

  const destElem = document.getElementById('modalConfirmDestination');
  if (destElem) destElem.textContent = address;

  const cfg = BRAND_CONFIGS[currentMethod] || BRAND_CONFIGS['Cash on Delivery (COD)'];

  // Update modal header & branding
  const banner = document.getElementById('modalBrandBanner');
  if (banner) banner.style.background = cfg.color;

  const logoImg = document.getElementById('modalProviderLogo');
  if (logoImg) logoImg.src = cfg.logoSrc;

  const titleElem = document.getElementById('modalProviderTitle');
  if (titleElem) titleElem.textContent = cfg.title;

  const subElem = document.getElementById('modalProviderSubtitle');
  if (subElem) subElem.textContent = cfg.subtitle;

  const hlTotal = document.getElementById('modalHeadlineTotal');
  if (hlTotal) {
    hlTotal.style.color = cfg.headlineColor || cfg.color;
  }

  const noticeElem = document.getElementById('modalSecurityNotice');
  if (noticeElem) noticeElem.innerHTML = cfg.securityNotice;

  const confirmBtn = document.getElementById('btnConfirmAndPay');
  if (confirmBtn) {
    confirmBtn.disabled = false;
    confirmBtn.style.background = cfg.color;
    confirmBtn.style.color = cfg.textColor;
    confirmBtn.innerHTML = '<i class="fa-solid fa-lock"></i> Confirm & Pay';
  }

  const modal = document.getElementById('paymentConfirmModal');
  if (modal) {
    modal.style.display = 'flex';
  }
}

function closePaymentConfirmModal() {
  const modal = document.getElementById('paymentConfirmModal');
  if (modal) {
    modal.style.display = 'none';
  }
}

function executeConfirmedPayment() {
  const confirmBtn = document.getElementById('btnConfirmAndPay');

  // Apple Pay Face ID Scanning Workflow
  if (currentMethod === 'Apple Pay') {
    const scanLine = document.getElementById('faceIdScanLine');
    const faceText = document.getElementById('faceIdText');
    const faceBox = document.getElementById('faceIdBox');
    const faceSvg = document.getElementById('faceIdSvg');
    const checkElem = document.getElementById('faceIdSuccessCheck');

    if (confirmBtn) {
      confirmBtn.disabled = true;
      confirmBtn.style.background = '#000000';
      confirmBtn.innerHTML = '<i class="fa-brands fa-apple"></i> Scanning Face...';
    }
    if (scanLine) scanLine.style.display = 'block';
    if (faceSvg) faceSvg.style.display = 'block';
    if (checkElem) checkElem.style.display = 'none';
    if (faceBox) {
      faceBox.classList.remove('success');
      faceBox.classList.add('scanning');
    }
    if (faceText) {
      faceText.innerHTML = '<span class="pulse-scan" style="color:#007aff; font-size:14px; font-weight:700;"><i class="fa-solid fa-spinner fa-spin" style="margin-right:6px;"></i>Scanning Face...</span>';
    }

    // 1.5 seconds Face ID scan animation
    setTimeout(() => {
      if (scanLine) scanLine.style.display = 'none';
      if (faceSvg) faceSvg.style.display = 'none';
      if (checkElem) checkElem.style.display = 'block';
      if (faceBox) {
        faceBox.classList.remove('scanning');
        faceBox.classList.add('success');
      }
      if (faceText) {
        faceText.innerHTML = '<span style="color:#30d158; font-size:16px; font-weight:800; display:inline-flex; align-items:center; gap:6px;"><i class="fa-solid fa-check"></i> Done</span>';
      }
      if (confirmBtn) {
        confirmBtn.style.background = '#30d158';
        confirmBtn.innerHTML = '<i class="fa-solid fa-check"></i> Done';
      }

      // Play subtle confirmation haptic / audio cue
      playConfirmationCue();

      // Submit order to api_orders.php after subtle confirmation pause
      setTimeout(() => {
        dispatchOrderPlacement();
      }, 400);
    }, 1500);

    return;
  }

  // Non-Apple Pay flows
  if (confirmBtn) {
    confirmBtn.disabled = true;
    confirmBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing Payment...';
  }
  dispatchOrderPlacement();
}

function dispatchOrderPlacement() {
  const confirmBtn = document.getElementById('btnConfirmAndPay');
  const addrInput = document.getElementById('deliveryAddressInput');
  let address = (addrInput ? addrInput.value : '').trim();
  if (!address) address = 'Western Leyte College';

  const latInput = document.getElementById('delivery_lat');
  const lngInput = document.getElementById('delivery_lng');
  const dLat = parseCoordinate(latInput ? latInput.value : null, 11.01020);
  const dLng = parseCoordinate(lngInput ? lngInput.value : null, 124.61779);

  const payload = {
    action: 'create_order',
    total: 200.00,
    total_amount: 200.00,
    subtotal: 170.00,
    delivery_fee: 30.00,
    delivery_address: address,
    delivery_lat: dLat,
    delivery_lng: dLng,
    customer_lat: dLat,
    customer_lng: dLng,
    payment_method: currentMethod,
    cart: [
      { name: 'Signature Meal Combo', store: 'Food Dash Grill', price: 170.00, qty: 1, icon: '🍱' }
    ]
  };

  fetch('api_orders.php', {
    method: 'POST',
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json'
    },
    body: JSON.stringify(payload)
  })
  .then(res => {
    if (!res.ok) {
      return res.text().then(t => { throw new Error('HTTP ' + res.status + ': ' + t); });
    }
    return res.json();
  })
  .then(data => {
    if (data.success || data.order_id) {
      showToast('Payment confirmed! Order placed successfully.');
      const orderId = data.order_id || 0;
      const redirectUrl = data.redirect_url || ('customer_dashboard.php?order_placed=1&order_id=' + orderId);

      // React Native WebView bridge support
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
      }, 400);
    } else {
      showToast(data.message || 'Payment failed', false);
      if (confirmBtn) {
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="fa-solid fa-lock"></i> Confirm & Pay';
      }
    }
  })
  .catch(err => {
    console.error('Checkout error:', err);
    showToast(err.message || 'Network error occurred', false);
    if (confirmBtn) {
      confirmBtn.disabled = false;
      confirmBtn.innerHTML = '<i class="fa-solid fa-lock"></i> Confirm & Pay';
    }
  });
}

document.addEventListener('DOMContentLoaded', function() {
  // Strip pattern attributes to prevent HTML5 validation error
  document.querySelectorAll('input, textarea, select, form').forEach(el => {
    if (el.hasAttribute('pattern')) el.removeAttribute('pattern');
    if (el.hasAttribute('required')) el.removeAttribute('required');
  });

  const form = document.getElementById('checkoutForm');
  if (form) {
    form.setAttribute('novalidate', 'novalidate');
    form.noValidate = true;
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      openPaymentConfirmModal();
    });
  }

  // Initialize with Cash on Delivery (COD)
  selectPaymentMethod('Cash on Delivery (COD)');
});
</script>

</body>
</html>
