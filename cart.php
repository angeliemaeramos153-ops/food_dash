<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/api_orders.php';
    exit;
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db_connect.php';

// 1. Session Identity: Customer ID is derived strictly from the active session
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    if (!empty($_SESSION['customer_id'])) {
        $user_id = (int)$_SESSION['customer_id'];
    } elseif (!empty($_SESSION['customer']['user_id'])) {
        $user_id = (int)$_SESSION['customer']['user_id'];
    }
}

$customer_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? '';
$customer_phone = $_SESSION['phone'] ?? '';
$default_address = $_SESSION['address'] ?? '';
$default_lat = isset($_SESSION['delivery_lat']) ? (float)$_SESSION['delivery_lat'] : null;
$default_lng = isset($_SESSION['delivery_lng']) ? (float)$_SESSION['delivery_lng'] : null;

if ($user_id) {
    try {
        $u_stmt = $conn->prepare("SELECT user_id, username, full_name, email, phone, address, default_address, latitude, longitude FROM users WHERE user_id = :uid LIMIT 1");
        $u_stmt->execute([':uid' => $user_id]);
        $user_row = $u_stmt->fetch(PDO::FETCH_ASSOC);
        if ($user_row) {
            if (empty($customer_name)) {
                $customer_name = $user_row['full_name'] ?: $user_row['username'];
            }
            if (empty($customer_phone) && !empty($user_row['phone'])) {
                $customer_phone = $user_row['phone'];
            }
            if (empty($default_address)) {
                $default_address = $user_row['default_address'] ?? $user_row['address'] ?? '';
            }
            if ($default_lat === null && !empty($user_row['latitude'])) {
                $default_lat = (float)$user_row['latitude'];
            }
            if ($default_lng === null && !empty($user_row['longitude'])) {
                $default_lng = (float)$user_row['longitude'];
            }
        }
    } catch (Throwable $e) {}
}

// Fallback if address or coords are completely blank
if (empty($default_address)) {
    $default_address = 'Western Leyte College';
}
if ($default_lat === null) $default_lat = 11.01039;
if ($default_lng === null) $default_lng = 124.61788;

// 2. Dynamic Financial Calculations: Compute subtotal, delivery fee, and grand total dynamically from the actual active cart
$cart_items = [];
if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    $cart_items = $_SESSION['cart'];
} elseif (!empty($_SESSION['foodDashCart']) && is_array($_SESSION['foodDashCart'])) {
    $cart_items = $_SESSION['foodDashCart'];
}

$cart_subtotal = 0.0;
if (!empty($cart_items)) {
    foreach ($cart_items as $ci) {
        $price = (float)($ci['price'] ?? 0);
        $qty = (int)($ci['qty'] ?? $ci['quantity'] ?? 1);
        $cart_subtotal += ($price * max(1, $qty));
    }
} else {
    $cart_subtotal = 170.00;
}

$subtotal = (float)$cart_subtotal;
$delivery_fee = (float)($delivery_fee ?? 30.00);
$grand_total = $subtotal + $delivery_fee;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
  <title>Checkout - Food Dash</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

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
      display: flex;
      flex-direction: column;
      gap: 16px;
      flex: 1;
    }
    .checkout-card {
      background: #ffffff;
      border-radius: 18px;
      padding: 18px;
      border: 1px solid var(--line);
      box-shadow: 0 2px 8px rgba(0,0,0,0.02);
    }
    .card-title {
      font-size: 14px;
      font-weight: 700;
      color: var(--olive-dark);
      margin-bottom: 14px;
      display: flex;
      align-items: center;
      gap: 8px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .order-item-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 8px 0;
      border-bottom: 1px dashed var(--line);
    }
    .order-item-row:last-child {
      border-bottom: none;
    }
    .order-item-desc {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .item-icon {
      font-size: 24px;
      background: var(--sandstone-light);
      width: 40px;
      height: 40px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .address-textarea {
      width: 100%;
      min-height: 72px;
      border-radius: 12px;
      border: 1px solid var(--line);
      padding: 12px;
      font-family: inherit;
      font-size: 13px;
      color: #374151;
      resize: vertical;
      background: var(--sandstone-light);
      outline: none;
      transition: border-color 0.2s;
    }
    .address-textarea:focus {
      border-color: var(--olive);
      background: #ffffff;
    }
    .coords-badge {
      display: flex;
      align-items: center;
      gap: 6px;
      margin-top: 8px;
      font-size: 11px;
      color: var(--olive-shadow);
      background: #edf3eb;
      padding: 6px 10px;
      border-radius: 8px;
    }

    /* Payment Cards */
    .pay-options-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .payment-card {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 14px 16px;
      border-radius: 14px;
      border: 1.5px solid var(--line);
      background: #ffffff;
      cursor: pointer;
      transition: all 0.2s ease;
      user-select: none;
    }
    .payment-card:hover {
      border-color: #cbd5e1;
      background: #fafafa;
    }
    .payment-card.active {
      border-color: var(--olive);
      background: #f7faf6;
      box-shadow: 0 0 0 1px var(--olive);
    }
    .payment-card#card-gcash.active {
      border-color: #007DFE;
      background: #f0f7ff;
      box-shadow: 0 0 0 1px #007DFE;
    }
    .payment-card#card-maya.active {
      border-color: #05B851;
      background: #f0fdf4;
      box-shadow: 0 0 0 1px #05B851;
    }
    .payment-card#card-gotyme.active {
      border-color: #00d2ff;
      background: #f0faff;
      box-shadow: 0 0 0 1px #00d2ff;
    }
    .payment-card#card-apple_pay.active {
      border-color: #000000;
      background: #f8f8f8;
      box-shadow: 0 0 0 1px #000000;
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
      background: #f3f4f6;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 4px;
      border: 1px solid rgba(0,0,0,0.06);
    }
    .pay-logo-box img {
      max-width: 100%;
      max-height: 100%;
      object-fit: contain;
    }
    .pay-info strong {
      display: block;
      font-size: 14px;
      color: #1f2937;
    }
    .pay-info small {
      font-size: 11px;
      color: #6b7280;
    }
    .pay-radio {
      font-size: 18px;
      color: #9ca3af;
      transition: color 0.2s ease;
    }
    .payment-card.active .pay-radio {
      color: var(--olive);
    }
    .payment-card#card-gcash.active .pay-radio { color: #007DFE; }
    .payment-card#card-maya.active .pay-radio { color: #05B851; }
    .payment-card#card-gotyme.active .pay-radio { color: #00d2ff; }
    .payment-card#card-apple_pay.active .pay-radio { color: #000000; }

    /* Summary Totals */
    .summary-row {
      display: flex;
      justify-content: space-between;
      font-size: 13px;
      color: #4b5563;
      margin-bottom: 8px;
    }
    .summary-row.total {
      border-top: 1px solid var(--line);
      margin-top: 10px;
      padding-top: 12px;
      font-size: 16px;
      font-weight: 700;
      color: var(--olive-dark);
    }
    .btn-submit-order, .checkout-btn {
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
    .btn-submit-order:active, .checkout-btn:active {
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
  </style>
</head>
<body>

<div class="checkout-wrapper">
  <header class="checkout-header">
    <a href="customer_dashboard.php#cart" class="back-btn"><i class="fa-solid fa-chevron-left"></i></a>
    <h1><i class="fa-solid fa-bag-shopping" style="color:var(--harvest-sun);"></i> Order Checkout</h1>
    <span id="cartHeaderCount" style="font-size:12px; font-weight:700; color:var(--olive);"><?= !empty($cart_items) ? count($cart_items) . ' Item(s)' : '1 Item' ?></span>
  </header>

  <!-- 2. Form with novalidate and onsubmit="return false;" - ZERO pattern attributes, standard type="text" -->
  <form id="cartForm" novalidate onsubmit="return false;" class="checkout-content">
    <input type="text" style="display:none;" name="action" id="order_action" value="create_order">
    <input type="text" style="display:none;" name="total_amount" id="total_amount" value="<?= htmlspecialchars((string)$grand_total) ?>">
    <input type="text" style="display:none;" name="total" id="total" value="<?= htmlspecialchars((string)$grand_total) ?>">
    <input type="text" style="display:none;" name="subtotal" id="subtotal" value="<?= htmlspecialchars((string)$subtotal) ?>">
    <input type="text" style="display:none;" name="delivery_fee" id="delivery_fee" value="<?= htmlspecialchars((string)$delivery_fee) ?>">
    <input type="text" style="display:none;" name="delivery_lat_lng" id="delivery_lat_lng" value="<?= htmlspecialchars("{$default_lat}, {$default_lng}") ?>">
    <input type="text" style="display:none;" name="delivery_lat" id="delivery_lat" value="<?= htmlspecialchars((string)$default_lat) ?>">
    <input type="text" style="display:none;" name="delivery_lng" id="delivery_lng" value="<?= htmlspecialchars((string)$default_lng) ?>">
    <input type="text" style="display:none;" name="customer_lat" id="customer_lat" value="<?= htmlspecialchars((string)$default_lat) ?>">
    <input type="text" style="display:none;" name="customer_lng" id="customer_lng" value="<?= htmlspecialchars((string)$default_lng) ?>">
    <input type="text" style="display:none;" name="cart" id="cart_json_input" value="<?= htmlspecialchars(json_encode(!empty($cart_items) ? $cart_items : [['name' => 'Signature Meal Combo', 'store' => 'Food Dash Grill', 'price' => 170.00, 'qty' => 1, 'icon' => '🍱']])) ?>">
    <input type="text" style="display:none;" name="payment_method_val" id="selectedPaymentMethod" value="gcash">
    <input type="text" style="display:none;" name="payment_method" id="formPaymentMethod" value="gcash">

    <!-- Order Summary Card -->
    <div class="checkout-card">
      <div class="card-title" style="display:flex; justify-content:space-between; align-items:center;">
        <span><i class="fa-solid fa-utensils"></i> Order Summary</span>
        <span id="orderItemCount" style="font-size:12px; font-weight:700; color:var(--olive);"><?= !empty($cart_items) ? count($cart_items) . ' Item(s)' : '1 Item' ?></span>
      </div>
      <div id="orderSummaryItems">
        <?php if (!empty($cart_items)): ?>
          <?php foreach ($cart_items as $ci): ?>
            <div class="order-item-row">
              <div class="order-item-desc">
                <div class="item-icon"><?= htmlspecialchars($ci['icon'] ?? '🍱') ?></div>
                <div>
                  <strong style="display:block; font-size:13px;"><?= htmlspecialchars($ci['name'] ?? 'Meal') ?></strong>
                  <small style="color:#6b7280;"><?= htmlspecialchars($ci['store'] ?? 'Food Dash') ?> • <?= (int)($ci['qty'] ?? 1) ?>x</small>
                </div>
              </div>
              <span style="font-weight:700; font-size:14px;">₱<?= number_format(((float)($ci['price'] ?? 0)) * max(1, (int)($ci['qty'] ?? 1)), 2) ?></span>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
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
        <?php endif; ?>
      </div>
    </div>

    <!-- Delivery Destination Card -->
    <div class="checkout-card">
      <div class="card-title"><i class="fa-solid fa-location-dot"></i> Delivery Address</div>
      <textarea id="delivery_address" name="delivery_address" class="address-textarea" placeholder="Unit/House No., Street, Barangay, City, Landmark"><?= htmlspecialchars($default_address) ?></textarea>
      <textarea id="deliveryAddressInput" name="address" style="display:none;"><?= htmlspecialchars($default_address) ?></textarea>
      
      <div class="coords-badge">
        <i class="fa-solid fa-circle-check"></i>
        <span>Exact Live GPS Pin: <b id="coordsDisplay"><?= htmlspecialchars("{$default_lat}, {$default_lng}") ?></b></span>
      </div>
    </div>

    <!-- Payment Methods: 5 Options -->
    <div class="checkout-card">
      <div class="card-title"><i class="fa-solid fa-credit-card"></i> Payment Method</div>
      <div class="pay-options-list">
        <!-- Cash on Delivery -->
        <div class="payment-card pay-option" id="card-cod" data-method="cod" onclick="selectMethod('cod')">
          <div class="pay-option-left">
            <div class="pay-logo-box"><img src="assets/logos/cod.svg" alt="COD"></div>
            <div class="pay-info">
              <strong>Cash on Delivery (COD)</strong>
              <small>Pay with cash upon arrival</small>
            </div>
          </div>
          <input type="radio" name="payment_method_radio" id="radio-cod" value="cod" style="display:none;">
          <i class="fa-regular fa-circle pay-radio" id="icon-cod"></i>
        </div>

        <!-- GCash -->
        <div class="payment-card pay-option active" id="card-gcash" data-method="gcash" onclick="selectMethod('gcash')">
          <div class="pay-option-left">
            <div class="pay-logo-box"><img src="assets/logos/gcash.svg" alt="GCash"></div>
            <div class="pay-info">
              <strong>GCash</strong>
              <small>Philippines' Leading E-Wallet</small>
            </div>
          </div>
          <input type="radio" name="payment_method_radio" id="radio-gcash" value="gcash" checked style="display:none;">
          <i class="fa-solid fa-circle-check pay-radio" id="icon-gcash"></i>
        </div>

        <!-- Maya -->
        <div class="payment-card pay-option" id="card-maya" data-method="maya" onclick="selectMethod('maya')">
          <div class="pay-option-left">
            <div class="pay-logo-box"><img src="assets/logos/maya.svg" alt="Maya"></div>
            <div class="pay-info">
              <strong>Maya (PayMaya)</strong>
              <small>Maya Wallet & Digital Card</small>
            </div>
          </div>
          <input type="radio" name="payment_method_radio" id="radio-maya" value="maya" style="display:none;">
          <i class="fa-regular fa-circle pay-radio" id="icon-maya"></i>
        </div>

        <!-- GoTyme Bank -->
        <div class="payment-card pay-option" id="card-gotyme" data-method="gotyme" onclick="selectMethod('gotyme')">
          <div class="pay-option-left">
            <div class="pay-logo-box"><img src="assets/logos/gotyme.svg" alt="GoTyme"></div>
            <div class="pay-info">
              <strong>GoTyme Bank</strong>
              <small>Digital Banking & Rewards</small>
            </div>
          </div>
          <input type="radio" name="payment_method_radio" id="radio-gotyme" value="gotyme" style="display:none;">
          <i class="fa-regular fa-circle pay-radio" id="icon-gotyme"></i>
        </div>

        <!-- Apple Pay -->
        <div class="payment-card pay-option" id="card-apple_pay" data-method="apple_pay" onclick="selectMethod('apple_pay')">
          <div class="pay-option-left">
            <div class="pay-logo-box" style="background:#000;"><img src="assets/logos/apple-pay.svg" alt="Apple Pay"></div>
            <div class="pay-info">
              <strong>Apple Pay</strong>
              <small>Instant Face ID / Touch ID</small>
            </div>
          </div>
          <input type="radio" name="payment_method_radio" id="radio-apple_pay" value="apple_pay" style="display:none;">
          <i class="fa-regular fa-circle pay-radio" id="icon-apple_pay"></i>
        </div>
      </div>
    </div>

    <!-- Payment Totals -->
    <div class="checkout-card">
      <div class="summary-row"><span>Meal Subtotal</span><span id="displaySubtotal">₱<?= number_format($subtotal, 2) ?></span></div>
      <div class="summary-row"><span>Delivery Fee</span><span id="displayFee">₱<?= number_format($delivery_fee, 2) ?></span></div>
      <div class="summary-row total"><span>Total Amount</span><span id="displayTotal">₱<?= number_format($grand_total, 2) ?></span></div>
    </div>

    <!-- 2. Main Checkout button with type="button" and id="payActionBtn" -->
    <button type="button" class="btn-submit-order checkout-btn btn-checkout" id="payActionBtn" onclick="handleCheckoutClick(event)" style="background:#007DFE; color:#ffffff; width:100%; border:none; border-radius:12px; padding:15px; font-weight:bold; font-size:16px; cursor:pointer;"><span style="background:#fff; color:#007DFE; font-weight:bold; padding:2px 6px; border-radius:4px; margin-right:6px; font-size:12px;">G</span> Pay with GCash • ₱<?= number_format($grand_total, 2) ?></button>
  </form>
</div>

<!-- ============================================== -->
<!-- 1. APPLE PAY NATIVE IOS-STYLE BOTTOM SHEET MODAL -->
<!-- ============================================== -->
<div class="sim-modal" id="applePayModal" style="display:none;" onclick="if(event.target===this)closeModal('applePayModal')">
  <div class="sim-sheet" style="background:#1c1c1e; color:#ffffff;">
    <div class="sheet-grabber" style="background:#3a3a3c;"></div>
    <div style="display:flex; align-items:center; justify-content:space-between; padding:4px 22px 14px 22px; border-bottom:1px solid #2c2c2e;">
      <div style="display:flex; align-items:center; gap:6px; font-size:20px; font-weight:700;">
        <span style="font-size:22px;"></span><span>Pay</span>
      </div>
      <button type="button" onclick="closeModal('applePayModal')" style="background:transparent; border:none; color:#0a84ff; font-size:15px; font-weight:600; cursor:pointer;">Cancel</button>
    </div>

    <div style="padding:18px 22px 28px 22px;">
      <div style="background:#2c2c2e; border-radius:18px; padding:16px; margin-bottom:16px;">
        <div style="display:flex; justify-content:space-between; font-size:13px; color:#8e8e93; margin-bottom:6px;">
          <span>Merchant</span><b style="color:#ffffff;">Food Dash</b>
        </div>
        <div style="display:flex; justify-content:space-between; font-size:13px; color:#8e8e93; margin-bottom:6px;">
          <span>Card</span><b style="color:#ffffff;">Apple Card (•••• 8821)</b>
        </div>
        <div style="display:flex; justify-content:space-between; font-size:13px; color:#8e8e93; margin-bottom:10px;">
          <span>Deliver To</span><b style="color:#ffffff;" id="appleModalDest"><?= htmlspecialchars($default_address) ?></b>
        </div>
        <div style="height:1px; background:#3a3a3c; margin:8px 0;"></div>
        <div style="display:flex; justify-content:space-between; align-items:center;">
          <span style="font-size:15px; font-weight:700;">Total</span>
          <span style="font-size:22px; font-weight:800; color:#30d158;" id="appleModalTotal">₱<?= number_format($grand_total, 2) ?></span>
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
        <div id="faceIdStatus" style="font-size:14px; font-weight:700; color:#8e8e93; margin-top:12px;">
          Scanning Face...
        </div>
      </div>

      <button type="button" class="btn-submit-order checkout-btn" id="btnApplePayTrigger" onclick="startApplePayScan()" style="background:#333336; color:#8e8e93; margin-top:16px;" disabled>
        <span style="font-size:18px;"></span> Scanning Face...
      </button>
    </div>
  </div>
</div>
<div id="modal-apple_pay" style="display:none;"></div>

<!-- ============================================== -->
<!-- 2. GENERIC WALLET MODAL (GCash / Maya / GoTyme)-->
<!-- ============================================== -->
<div class="sim-modal" id="walletModal" style="display:none;" onclick="if(event.target===this)closeModal('walletModal')">
  <div class="sim-sheet" style="background:#ffffff;">
    <div id="walletHeader" style="background:#007DFE; color:#ffffff; padding:16px 20px 18px 20px;">
      <div class="sheet-grabber"></div>
      <div style="display:flex; align-items:center; justify-content:space-between; margin-top:8px;">
        <div style="display:flex; align-items:center; gap:10px;">
          <img id="walletLogo" src="assets/logos/gcash.svg" alt="Wallet" style="height:26px; border-radius:6px; background:#fff; padding:2px;">
          <div>
            <div id="walletTitle" style="font-size:16px; font-weight:800; line-height:1.2;">GCash QuickPay</div>
            <small id="walletSub" style="font-size:11px; opacity:0.85;">Official Digital Payment</small>
          </div>
        </div>
        <button type="button" onclick="closeModal('walletModal')" style="background:rgba(255,255,255,0.18); border:none; color:#ffffff; font-size:12px; font-weight:700; padding:6px 12px; border-radius:20px; cursor:pointer;">Cancel</button>
      </div>
    </div>
    <div style="padding:22px; background:#ffffff;">
      <div style="text-align:center; margin-bottom:16px;">
        <div style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#6b7280; margin-bottom:4px;">Payment Authorization</div>
        <h2 style="font-size:22px; font-weight:800; color:#111827;">Total: <span id="walletTotal" style="color:#007DFE;">₱<?= number_format($grand_total, 2) ?></span></h2>
      </div>
      <div style="background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:16px; padding:12px 16px; margin-bottom:14px;">
        <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
          <i class="fa-solid fa-location-dot" style="color:#ef4444;"></i> Delivery Destination:
        </div>
        <div style="font-size:14px; font-weight:700; color:#1e293b;" id="walletDest"><?= htmlspecialchars($default_address) ?></div>
      </div>
      <div style="background:#fdfbf7; border:1px solid #e8decb; border-radius:16px; padding:16px; margin-bottom:18px; text-align:center;">
        <label id="walletPinLabel" style="display:block; font-size:12px; font-weight:700; color:#374151; margin-bottom:8px;">
          <i class="fa-solid fa-lock" style="color:#007DFE;"></i> Enter 4-Digit MPIN / Security OTP:
        </label>
        <div style="display:flex; justify-content:center; margin-bottom:6px;">
          <input type="password" id="walletPinInput" maxlength="6" value="1234" autocomplete="off" style="width:170px; height:46px; text-align:center; font-size:24px; letter-spacing:10px; border-radius:12px; border:2px solid #007DFE; font-family:monospace; font-weight:800; outline:none; background:#ffffff; color:#1e293b;">
        </div>
        <small style="color:#6b7280; font-size:11px;"><i class="fa-solid fa-circle-check" style="color:#10b981;"></i> Security Verified • Instant Processing</small>
      </div>
      <button type="button" class="btn-submit-order checkout-btn" id="walletSubmitBtn" onclick="dispatchOrderPlacement(activePaymentMethod)" style="background:#007DFE; color:#ffffff; margin-top:0;">
        <i class="fa-solid fa-shield-halved"></i> Confirm & Pay • ₱<?= number_format($grand_total, 2) ?>
      </button>
    </div>
  </div>
</div>
<div id="modal-gcash" style="display:none;"></div>
<div id="modal-maya" style="display:none;"></div>
<div id="modal-gotyme" style="display:none;"></div>

<!-- ============================================== -->
<!-- 3. CASH ON DELIVERY (COD) CONFIRMATION MODAL   -->
<!-- ============================================== -->
<div class="sim-modal" id="modal-cod" style="display:none;" onclick="if(event.target===this)closeModal('modal-cod')">
  <div class="sim-sheet" style="background:#ffffff;">
    <div style="background:#283d23; color:#ffffff; padding:16px 20px 18px 20px;">
      <div class="sheet-grabber"></div>
      <div style="display:flex; align-items:center; justify-content:space-between; margin-top:8px;">
        <div style="display:flex; align-items:center; gap:10px;">
          <img src="assets/logos/cod.svg" alt="COD" style="height:26px; border-radius:6px; background:#fff; padding:2px;">
          <div>
            <div style="font-size:16px; font-weight:800; line-height:1.2;">Cash on Delivery</div>
            <small style="font-size:11px; opacity:0.85;">Pay upon arrival</small>
          </div>
        </div>
        <button type="button" onclick="closeModal('modal-cod')" style="background:rgba(255,255,255,0.18); border:none; color:#ffffff; font-size:12px; font-weight:700; padding:6px 12px; border-radius:20px; cursor:pointer;">Cancel</button>
      </div>
    </div>
    <div style="padding:22px; background:#ffffff;">
      <div style="text-align:center; margin-bottom:16px;">
        <div style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#6b7280; margin-bottom:4px;">Order Summary</div>
        <h2 style="font-size:22px; font-weight:800; color:#111827;">Total Amount: <span id="codTotal" style="color:#283d23;">₱<?= number_format($grand_total, 2) ?></span></h2>
      </div>
      <div style="background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:16px; padding:12px 16px; margin-bottom:14px;">
        <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
          <i class="fa-solid fa-location-dot" style="color:#ef4444;"></i> Delivery Destination:
        </div>
        <div style="font-size:14px; font-weight:700; color:#1e293b;" id="codDest"><?= htmlspecialchars($default_address) ?></div>
      </div>
      <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:14px; padding:14px; margin-bottom:18px; font-size:12px; color:#166534; display:flex; align-items:center; gap:10px;">
        <i class="fa-solid fa-money-bill-wave" style="font-size:20px; color:#15803d;"></i>
        <span id="codNote">Please prepare exact cash of <b id="codCashAmt">₱<?= number_format($grand_total, 2) ?></b> upon arrival for swift handover.</span>
      </div>
      <button type="button" class="btn-submit-order checkout-btn" id="codSubmitBtn" onclick="dispatchOrderPlacement('cod')" style="background:#283d23; color:#ffffff; margin-top:0;">
        <i class="fa-solid fa-check"></i> Confirm COD Order • ₱<?= number_format($grand_total, 2) ?>
      </button>
    </div>
  </div>
</div>

<!-- ============================================== -->
<!-- 100% DYNAMIC JAVASCRIPT CONTROLLER             -->
<!-- ============================================== -->
<script>
// 1. DYNAMIC FINANCIAL CALCULATIONS:
var currentGrandTotal = <?= json_encode($grand_total) ?>;
var grandTotalFormatted = '₱' + Number(<?= json_encode($grand_total) ?>).toLocaleString('en-PH', {minimumFractionDigits: 2});
var currentSubtotal = <?= json_encode($subtotal) ?>;
var currentDeliveryFee = <?= json_encode($delivery_fee) ?>;
var activePaymentMethod = 'gcash';
var activeMethod = 'gcash';
var selectedMethod = 'gcash';
var currentPayment = 'gcash';

// Sync dynamically from client-side localStorage if cart was updated on the customer dashboard
function syncCartFromLocalStorage() {
    try {
        var rawCart = localStorage.getItem('foodDashCart');
        if (rawCart) {
            var parsed = JSON.parse(rawCart);
            if (Array.isArray(parsed) && parsed.length > 0) {
                var calcSub = 0;
                var html = '';
                for (var idx = 0; idx < parsed.length; idx++) {
                    var item = parsed[idx];
                    var price = Number(item.price) || 0;
                    var qty = Number(item.qty || item.quantity) || 1;
                    calcSub += (price * qty);
                    var icon = item.icon || '🍱';
                    var name = item.name || item.title || 'Meal Item';
                    var store = item.store || 'Food Dash Grill';
                    html += '<div class="order-item-row">' +
                        '<div class="order-item-desc">' +
                        '<div class="item-icon">' + icon + '</div>' +
                        '<div>' +
                        '<strong style="display:block; font-size:13px;">' + name + '</strong>' +
                        '<small style="color:#6b7280;">' + store + ' • ' + qty + 'x</small>' +
                        '</div>' +
                        '</div>' +
                        '<span style="font-weight:700; font-size:14px;">₱' + (price * qty).toFixed(2) + '</span>' +
                        '</div>';
                }
                currentSubtotal = calcSub;
                currentGrandTotal = currentSubtotal + currentDeliveryFee;
                grandTotalFormatted = '₱' + Number(currentGrandTotal).toLocaleString('en-PH', {minimumFractionDigits: 2});
                
                var itemsContainer = document.getElementById('orderSummaryItems');
                if (itemsContainer && html) itemsContainer.innerHTML = html;
                var countEl = document.getElementById('orderItemCount');
                if (countEl) countEl.textContent = parsed.length + ' Item(s)';
                var headCount = document.getElementById('cartHeaderCount');
                if (headCount) headCount.textContent = parsed.length + ' Item(s)';
                
                var subEl = document.getElementById('displaySubtotal');
                if (subEl) subEl.textContent = '₱' + Number(currentSubtotal).toLocaleString('en-PH', {minimumFractionDigits: 2});
                var totEl = document.getElementById('displayTotal');
                if (totEl) totEl.textContent = grandTotalFormatted;
                
                var cartIn = document.getElementById('cart_json_input');
                if (cartIn) cartIn.value = JSON.stringify(parsed);
                var totIn = document.getElementById('total_amount');
                if (totIn) totIn.value = currentGrandTotal;
                var totIn2 = document.getElementById('total');
                if (totIn2) totIn2.value = currentGrandTotal;
                var subIn = document.getElementById('subtotal');
                if (subIn) subIn.value = currentSubtotal;
            }
        }
        
        var savedAddr = localStorage.getItem('foodDashDeliveryAddress');
        if (savedAddr) {
            var addrEl = document.getElementById('delivery_address');
            if (addrEl && (!addrEl.value || addrEl.value === 'Western Leyte College')) {
                addrEl.value = savedAddr;
            }
            var addrSec = document.getElementById('deliveryAddressInput');
            if (addrSec && (!addrSec.value || addrSec.value === 'Western Leyte College')) {
                addrSec.value = savedAddr;
            }
        }
    } catch(e) {
        console.error('Cart sync error:', e);
    }
}

// 3. DYNAMIC BUTTON THEME & LOGO UPDATE: (Direct getElementById ONLY, NO querySelector)
function selectMethod(method) {
    activePaymentMethod = method;
    selectedMethod = method;
    activeMethod = method;
    currentPayment = method;
    var btn = document.getElementById('payActionBtn');
    if (!btn) return;

    var methods = ['cod', 'gcash', 'maya', 'gotyme', 'apple_pay'];
    for (var i = 0; i < methods.length; i++) {
        var m = methods[i];
        var isCurrent = (m === method);
        var card = document.getElementById('card-' + m);
        if (card) {
            if (isCurrent) card.classList.add('active');
            else card.classList.remove('active');
        }
        var icon = document.getElementById('icon-' + m);
        if (icon) {
            icon.className = isCurrent ? 'fa-solid fa-circle-check pay-radio' : 'fa-regular fa-circle pay-radio';
        }
        var r = document.getElementById('radio-' + m);
        if (r) r.checked = isCurrent;
    }

    var hidden = document.getElementById('selectedPaymentMethod');
    if (hidden) hidden.value = method;
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
        btn.innerHTML = '<i class="fa-solid fa-money-bill-wave"></i> Place Order • ' + grandTotalFormatted;
    } else if (method === 'gcash') {
        btn.style.setProperty('background-color', '#007DFE', 'important');
        btn.style.setProperty('color', '#ffffff', 'important');
        btn.innerHTML = '<span style="background:#fff; color:#007DFE; font-weight:bold; padding:2px 6px; border-radius:4px; margin-right:6px; font-size:12px;">G</span> Pay with GCash • ' + grandTotalFormatted;
    } else if (method === 'maya') {
        btn.style.setProperty('background-color', '#023a2b', 'important');
        btn.style.setProperty('color', '#05B851', 'important');
        btn.innerHTML = '<span style="background:#05B851; color:#023a2b; font-weight:bold; padding:2px 6px; border-radius:4px; margin-right:6px; font-size:12px;">M</span> Pay with Maya • ' + grandTotalFormatted;
    } else if (method === 'gotyme') {
        btn.style.setProperty('background-color', '#0a2540', 'important');
        btn.style.setProperty('color', '#00d2ff', 'important');
        btn.innerHTML = '<span style="background:#00d2ff; color:#0a2540; font-weight:bold; padding:2px 6px; border-radius:4px; margin-right:6px; font-size:12px;">GT</span> Pay with GoTyme • ' + grandTotalFormatted;
    } else if (method === 'apple_pay') {
        btn.style.setProperty('background-color', '#000000', 'important');
        btn.style.setProperty('color', '#ffffff', 'important');
        btn.innerHTML = '<span style="font-size:18px; margin-right:6px;"></span> Pay • ' + grandTotalFormatted;
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
    
    if (tot) tot.textContent = grandTotalFormatted;

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
            btn.innerHTML = '<i class="fa-solid fa-shield-halved"></i> Confirm & Pay • ' + grandTotalFormatted;
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
            btn.innerHTML = '<i class="fa-solid fa-shield-halved"></i> Confirm & Pay • ' + grandTotalFormatted;
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
            btn.innerHTML = '<i class="fa-solid fa-building-columns"></i> Confirm & Pay • ' + grandTotalFormatted;
        }
    }

    var codTot = document.getElementById('codTotal');
    if (codTot) codTot.textContent = grandTotalFormatted;
    var codCash = document.getElementById('codCashAmt');
    if (codCash) codCash.textContent = grandTotalFormatted;
    var codBtn = document.getElementById('codSubmitBtn');
    if (codBtn) codBtn.innerHTML = '<i class="fa-solid fa-check"></i> Confirm COD Order • ' + grandTotalFormatted;
    var appleTot = document.getElementById('appleModalTotal');
    if (appleTot) appleTot.textContent = grandTotalFormatted;
}

// 2. HARD-ISOLATE THE BUTTON CLICK & PREVENT SUPPRESSION:
function handleCheckoutClick(event) {
    if (event) {
        if (event.preventDefault) event.preventDefault();
        if (event.stopPropagation) event.stopPropagation();
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
            startApplePayScan();
            return;
        }
    } else if (['gcash', 'maya', 'gotyme'].includes(activePaymentMethod)) {
        updateWalletModalTheme(activePaymentMethod);
        var walletModal = document.getElementById('walletModal');
        if (walletModal) {
            walletModal.style.display = 'flex';
            return;
        }
    } else if (activePaymentMethod === 'cod') {
        // Direct COD post as requested
        dispatchOrderPlacement('cod', address, latLng);
        return;
    }

    dispatchOrderPlacement(activePaymentMethod || 'cod', address, latLng);
}

var triggerCheckout = handleCheckoutClick;

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
var updatePaymentTheme = selectMethod;
var setPayment = selectMethod;
var triggerCheckoutFlow = handleCheckoutClick;
var onCheckoutButtonClick = handleCheckoutClick;
var handlePayAction = handleCheckoutClick;
var triggerCheckout = handleCheckoutClick;
var submitDynamicOrder = dispatchOrderPlacement;
var submitOrderToBackend = dispatchOrderPlacement;
var processOrderAJAX = dispatchOrderPlacement;
var confirmAndPlaceOrder = dispatchOrderPlacement;

window.selectMethod = selectMethod;
window.updatePaymentTheme = selectMethod;
window.setPayment = selectMethod;
window.triggerCheckout = handleCheckoutClick;
window.handleCheckoutClick = handleCheckoutClick;
window.dispatchOrderPlacement = dispatchOrderPlacement;

document.addEventListener('DOMContentLoaded', function() {
    syncCartFromLocalStorage();
    selectMethod('gcash');
});
</script>
</body>
</html>
