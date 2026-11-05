<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/api_orders.php';
    exit;
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: text/html; charset=utf-8');
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
// Delivery address and coords start completely blank on page load per user requirement
$default_address = '';
$default_lat = null;
$default_lng = null;

if ($user_id) {
    try {
        $u_stmt = $conn->prepare("SELECT user_id, username, full_name, email, phone FROM users WHERE user_id = :uid LIMIT 1");
        $u_stmt->execute([':uid' => $user_id]);
        $user_row = $u_stmt->fetch(PDO::FETCH_ASSOC);
        if ($user_row) {
            if (empty($customer_name)) {
                $customer_name = $user_row['full_name'] ?: $user_row['username'];
            }
            if (empty($customer_phone) && !empty($user_row['phone'])) {
                $customer_phone = $user_row['phone'];
            }
        }
    } catch (Throwable $e) {}
}

// Delivery address input field starts completely blank on page load (zero automatic startup geolocation)
$default_address = '';
$default_lat = null;
$default_lng = null;

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
    $cart_subtotal = 0.00;
}

$subtotal = (float)$cart_subtotal;
$delivery_fee = $subtotal > 0 ? (float)($delivery_fee ?? 30.00) : 0.00;
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
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

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
    .btn-qty-minus, .btn-qty-plus {
      pointer-events: auto !important;
      cursor: pointer !important;
      touch-action: manipulation !important;
      user-select: none;
      -webkit-user-select: none;
      transition: transform 0.08s ease, opacity 0.08s ease;
    }
    .btn-qty-minus:active, .btn-qty-plus:active {
      transform: scale(0.90) !important;
      opacity: 0.8 !important;
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
    .btn-locate-me {
      background: rgba(216, 143, 42, 0.12);
      border: 1px solid rgba(216, 143, 42, 0.35);
      border-radius: 8px;
      padding: 5px 12px;
      font-size: 11.5px;
      font-weight: 600;
      color: var(--harvest-sun);
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s ease;
      touch-action: manipulation;
    }
    .btn-locate-me:hover {
      background: rgba(216, 143, 42, 0.22);
      border-color: var(--harvest-sun);
    }
    .btn-locate-me:active {
      transform: scale(0.96);
    }
    .btn-exact-location {
      background: rgba(216, 143, 42, 0.12);
      border: 1.5px solid rgba(216, 143, 42, 0.35);
      border-radius: 8px;
      padding: 6px 14px;
      font-size: 12px;
      font-weight: 700;
      color: var(--harvest-sun);
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s ease;
      touch-action: manipulation;
    }
    .btn-exact-location:hover {
      background: rgba(216, 143, 42, 0.22);
      border-color: var(--harvest-sun);
    }
    .btn-exact-location:active {
      transform: scale(0.96);
    }
    .btn-map-helper {
      background: #f3f4f6;
      border: 1px solid #d1d5db;
      border-radius: 8px;
      padding: 5px 12px;
      font-size: 11.5px;
      font-weight: 600;
      color: var(--olive-dark);
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s ease;
      touch-action: manipulation;
    }
    .btn-map-helper:hover {
      background: #e5e7eb;
      border-color: #9ca3af;
    }
    .btn-map-helper:active {
      transform: scale(0.96);
    }
    .toast-cart-box {
      position: fixed;
      bottom: 24px;
      left: 50%;
      transform: translateX(-50%);
      background: #1f2937;
      color: #ffffff;
      padding: 10px 18px;
      border-radius: 10px;
      font-size: 12.5px;
      font-weight: 600;
      box-shadow: 0 10px 25px rgba(0,0,0,0.25);
      z-index: 100000;
      display: flex;
      align-items: center;
      gap: 8px;
      transition: opacity 0.3s ease, transform 0.3s ease;
      pointer-events: none;
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
      max-height: 92vh;
      border-radius: 28px 28px 0 0;
      box-shadow: 0 -12px 40px rgba(0,0,0,0.5);
      animation: sheetSlideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
      box-sizing: border-box;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      background: #ffffff;
    }
    .sim-sheet-header {
      flex-shrink: 0;
    }
    .sim-sheet-body {
      flex: 1 1 auto;
      overflow-y: auto;
      -webkit-overflow-scrolling: touch;
      padding: 20px 22px max(28px, env(safe-area-inset-bottom, 28px)) 22px !important;
      box-sizing: border-box;
      display: flex;
      flex-direction: column;
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
    <span id="cartHeaderCount" style="font-size:12px; font-weight:700; color:var(--olive);"><?= !empty($cart_items) ? count($cart_items) . ' Item(s)' : '0 Items' ?></span>
  </header>

  <!-- 2. Form with novalidate and onsubmit="return false;" - ZERO pattern attributes, standard type="text" -->
  <form id="cartCheckoutForm" novalidate onsubmit="return false;" class="checkout-content">
    <input type="text" style="display:none;" name="action" id="order_action" value="create_order">
    <input type="text" style="display:none;" name="total_amount" id="total_amount" value="<?= htmlspecialchars((string)$grand_total) ?>">
    <input type="text" style="display:none;" name="total" id="total" value="<?= htmlspecialchars((string)$grand_total) ?>">
    <input type="text" style="display:none;" name="subtotal" id="subtotal" value="<?= htmlspecialchars((string)$subtotal) ?>">
    <input type="text" style="display:none;" name="delivery_fee" id="delivery_fee" value="<?= htmlspecialchars((string)$delivery_fee) ?>">
    <input type="text" style="display:none;" name="delivery_lat_lng" id="delivery_lat_lng" value="<?= htmlspecialchars($default_lat && $default_lng ? "{$default_lat}, {$default_lng}" : '') ?>">
    <input type="text" style="display:none;" name="delivery_lat" id="delivery_lat" value="<?= htmlspecialchars($default_lat !== null ? (string)$default_lat : '') ?>">
    <input type="text" style="display:none;" name="delivery_lng" id="delivery_lng" value="<?= htmlspecialchars($default_lng !== null ? (string)$default_lng : '') ?>">
    <input type="text" style="display:none;" name="customer_lat" id="customer_lat" value="<?= htmlspecialchars($default_lat !== null ? (string)$default_lat : '') ?>">
    <input type="text" style="display:none;" name="customer_lng" id="customer_lng" value="<?= htmlspecialchars($default_lng !== null ? (string)$default_lng : '') ?>">
    <input type="text" style="display:none;" name="cart" id="cart_json_input" value="<?= htmlspecialchars(json_encode(!empty($cart_items) ? $cart_items : [])) ?>">
    <input type="text" style="display:none;" name="payment_method_val" id="selectedPaymentMethod" value="gcash">
    <input type="text" style="display:none;" name="payment_method" id="formPaymentMethod" value="gcash">

    <!-- Order Summary Card -->
    <div class="checkout-card">
      <div class="card-title" style="display:flex; justify-content:space-between; align-items:center;">
        <span><i class="fa-solid fa-utensils"></i> Order Summary</span>
        <span id="orderItemCount" style="font-size:12px; font-weight:700; color:var(--olive);"><?= !empty($cart_items) ? count($cart_items) . ' Item(s)' : '0 Items' ?></span>
      </div>
      <div id="orderSummaryItems">
        <?php if (!empty($cart_items)): ?>
          <?php foreach ($cart_items as $cIdx => $ci): 
            $cQty = max(1, (int)($ci['qty'] ?? 1));
            $cPrice = (float)($ci['price'] ?? 0);
          ?>
            <div class="order-item-row" data-index="<?= $cIdx ?>">
              <div class="order-item-desc">
                <div class="item-icon"><?= htmlspecialchars($ci['icon'] ?? '🍱') ?></div>
                <div>
                  <strong style="display:block; font-size:13px;"><?= htmlspecialchars($ci['name'] ?? 'Meal') ?></strong>
                  <div style="display:flex; align-items:center; gap:8px; margin-top:3px;">
                    <small style="color:#6b7280;"><?= htmlspecialchars($ci['store'] ?? 'Food Dash') ?></small>
                    <div class="qty" style="display:inline-flex; align-items:center; gap:5px; background:#f3f4f6; padding:2px 6px; border-radius:12px;">
                      <button type="button" class="btn-qty-minus" onclick="updateCartItemQty(<?= $cIdx ?>, -1, event)" aria-label="Decrease quantity" style="width:20px; height:20px; border-radius:50%; border:1px solid #d1d5db; background:#fff; cursor:pointer; font-size:11px; display:inline-flex; align-items:center; justify-content:center; line-height:1; padding:0; touch-action:manipulation;">−</button>
                      <span style="font-weight:700; font-size:11px; min-width:14px; text-align:center;"><?= $cQty ?></span>
                      <button type="button" class="btn-qty-plus" onclick="updateCartItemQty(<?= $cIdx ?>, 1, event)" aria-label="Increase quantity" style="width:20px; height:20px; border-radius:50%; border:1px solid #d1d5db; background:#fff; cursor:pointer; font-size:11px; display:inline-flex; align-items:center; justify-content:center; line-height:1; padding:0; touch-action:manipulation;">+</button>
                    </div>
                  </div>
                </div>
              </div>
              <span style="font-weight:700; font-size:14px;">₱<?= number_format($cPrice * $cQty, 2) ?></span>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="order-item-row empty-cart-box" style="text-align:center; padding:24px 12px; display:block; border:1px dashed #cbd5e1; border-radius:12px; background:#f8fafc; margin:6px 0;">
            <div style="font-size:32px; margin-bottom:8px;">🛒</div>
            <strong style="display:block; font-size:14px; color:#334155;">Your cart is empty</strong>
            <small style="color:#64748b; display:block; margin:4px 0 12px;">Add delicious meals from partner stores to begin checkout.</small>
            <a href="customer_dashboard.php#stores" style="display:inline-flex; align-items:center; gap:6px; background:#283d23; color:#ffffff; padding:8px 16px; border-radius:8px; font-size:12px; font-weight:700; text-decoration:none;">
              <i class="fa-solid fa-store"></i> Browse Stores
            </a>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Delivery Destination Card -->
    <div class="checkout-card">
      <div class="card-title" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
        <span><i class="fa-solid fa-location-dot"></i> Delivery Address</span>
        <div style="display:flex; gap:6px; align-items:center;">
          <button type="button" class="btn-locate-me" id="cartBtnLocateMe" onclick="locateCartCurrentGps(false)">
            <i class="fa-solid fa-crosshairs"></i> Locate Me
          </button>
          <button type="button" class="btn-map-helper" onclick="openCartMapModal()">
            <i class="fa-solid fa-map-location-dot" style="color:var(--harvest-sun);"></i> Pin on Map
          </button>
        </div>
      </div>
      <textarea id="delivery_address" name="delivery_address" class="address-textarea" placeholder="Enter complete address (e.g., Purok 3, House No., Street, Barangay, Landmark)" oninput="handleCartAddressTyping(this.value)"></textarea>
      <textarea id="deliveryAddressInput" name="address" style="display:none;"></textarea>
      
      <div class="coords-badge" id="coordsBadge" style="cursor:pointer;" onclick="openCartMapModal()">
        <i class="fa-solid fa-circle-check"></i>
        <span>Coordinates: <b id="coordsDisplay"><?= htmlspecialchars($default_lat && $default_lng ? "{$default_lat}, {$default_lng}" : "Not set (Optional - Pin on Map)") ?></b></span>
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

    <!-- 2. Main Checkout button with type="button" and id="mainPayBtn" -->
    <button type="button" class="btn-submit-order checkout-btn btn-checkout" id="mainPayBtn" onclick="openPaymentPopup(event)" style="background:#007DFE; color:#ffffff; width:100%; border:none; border-radius:12px; padding:15px; font-weight:bold; font-size:16px; cursor:pointer;"><span style="background:#fff; color:#007DFE; font-weight:bold; padding:2px 6px; border-radius:4px; margin-right:6px; font-size:12px;">G</span> Pay with GCash • ₱<?= number_format($grand_total, 2) ?></button>
  </form>
</div>

<!-- ============================================== -->
<!-- 1. APPLE PAY NATIVE IOS-STYLE BOTTOM SHEET MODAL -->
<!-- ============================================== -->
<div class="sim-modal" id="applePayModal" style="display:none;" onclick="if(event.target===this)closeModal('applePayModal')">
  <div class="sim-sheet" style="background:#1c1c1e; color:#ffffff;">
    <div class="sim-sheet-header" style="padding:10px 22px 14px 22px; border-bottom:1px solid #2c2c2e;">
      <div class="sheet-grabber" style="background:#3a3a3c; margin:0 auto 10px auto;"></div>
      <div style="display:flex; align-items:center; justify-content:space-between;">
<div style="display:inline-flex; align-items:center; gap:2px; font-size:20px; font-weight:700;"><svg viewBox="0 0 15 16" width="18" height="19" fill="currentColor" style="vertical-align:-1px; margin-right:2px;" aria-hidden="true"><path d="M 8.5,1.2 C 9.5,0.2 10.6,0 10.6,0 C 10.6,0 10.4,1.1 9.4,2.1 C 8.4,3.1 7.4,3 7.4,3 C 7.4,3 7.5,2.1 8.5,1.2 Z"/><path d="M 11.2,6.5 C 11.2,4.8 12.6,3.9 12.7,3.8 C 11.9,2.6 10.6,2.4 10.1,2.4 C 8.9,2.2 7.7,3.1 7.1,3.1 C 6.5,3.1 5.5,2.3 4.5,2.4 C 3.2,2.4 2.0,3.2 1.3,4.4 C -0.1,6.8 0.9,10.3 2.3,12.3 C 3.0,13.3 3.8,14.4 4.9,14.4 C 5.9,14.3 6.3,13.7 7.6,13.7 C 8.8,13.7 9.2,14.4 10.3,14.3 C 11.4,14.3 12.1,13.3 12.8,12.3 C 13.6,11.2 13.9,10.1 14.0,10.0 C 13.9,10.0 11.2,9.0 11.2,6.5 Z"/></svg><span>Pay</span></div>
        <button type="button" onclick="closeModal('applePayModal')" style="background:transparent; border:none; color:#0a84ff; font-size:15px; font-weight:600; cursor:pointer;">Cancel</button>
      </div>
    </div>

    <div class="sim-sheet-body" style="background:#1c1c1e;">
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
        <svg viewBox="0 0 15 16" width="16" height="17" fill="currentColor" style="vertical-align:-2px; margin-right:4px;" aria-hidden="true"><path d="M 8.5,1.2 C 9.5,0.2 10.6,0 10.6,0 C 10.6,0 10.4,1.1 9.4,2.1 C 8.4,3.1 7.4,3 7.4,3 C 7.4,3 7.5,2.1 8.5,1.2 Z"/><path d="M 11.2,6.5 C 11.2,4.8 12.6,3.9 12.7,3.8 C 11.9,2.6 10.6,2.4 10.1,2.4 C 8.9,2.2 7.7,3.1 7.1,3.1 C 6.5,3.1 5.5,2.3 4.5,2.4 C 3.2,2.4 2.0,3.2 1.3,4.4 C -0.1,6.8 0.9,10.3 2.3,12.3 C 3.0,13.3 3.8,14.4 4.9,14.4 C 5.9,14.3 6.3,13.7 7.6,13.7 C 8.8,13.7 9.2,14.4 10.3,14.3 C 11.4,14.3 12.1,13.3 12.8,12.3 C 13.6,11.2 13.9,10.1 14.0,10.0 C 13.9,10.0 11.2,9.0 11.2,6.5 Z"/></svg> Scanning Face...
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
    <div id="walletHeader" class="sim-sheet-header" style="background:#007DFE; color:#ffffff; padding:16px 20px 18px 20px;">
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
    <div class="sim-sheet-body" style="background:#ffffff;">
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
      <button type="button" class="btn-submit-order checkout-btn" id="walletSubmitBtn" onclick="finalizeOrder(activePaymentMethod)" style="background:#007DFE; color:#ffffff; margin-top:0;">
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
    <div class="sim-sheet-header" style="background:#283d23; color:#ffffff; padding:16px 20px 18px 20px;">
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
    <div class="sim-sheet-body" style="background:#ffffff;">
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
      <button type="button" class="btn-submit-order checkout-btn" id="codSubmitBtn" onclick="finalizeOrder('cod')" style="background:#283d23; color:#ffffff; margin-top:0;">
        <i class="fa-solid fa-check"></i> Confirm Order • ₱<?= number_format($grand_total, 2) ?>
      </button>
    </div>
  </div>
</div>

<!-- ============================================== -->
<!-- 4. OPTIONAL MAP HELPER MODAL -->
<!-- ============================================== -->
<div class="sim-modal" id="cartMapModal" style="display:none;" onclick="if(event.target===this)closeCartMapModal()">
  <div class="sim-sheet" style="background:#ffffff; max-height:94vh;">
    <div class="sim-sheet-header" style="background:var(--olive-dark); color:#ffffff; padding:14px 20px;">
      <div class="sheet-grabber" style="background:rgba(255,255,255,0.4); margin:0 auto 8px auto;"></div>
      <div style="display:flex; align-items:center; justify-content:space-between;">
        <div style="display:flex; align-items:center; gap:8px; font-size:15px; font-weight:700;">
          <i class="fa-solid fa-map-location-dot" style="color:var(--harvest-sun);"></i>
          <span>Pin Location on Map</span>
        </div>
        <button type="button" onclick="closeCartMapModal()" style="background:transparent; border:none; color:#ffffff; font-size:22px; line-height:1; cursor:pointer;">&times;</button>
      </div>
    </div>
    <div class="sim-sheet-body" style="background:#ffffff; padding:16px;">
      <!-- Search bar for Ormoc City landmarks & streets -->
      <div style="position:relative; margin-bottom:10px;">
        <div style="display:flex; gap:6px;">
          <div style="position:relative; flex:1;">
            <i class="fa-solid fa-search" style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#9ca3af; font-size:12px;"></i>
            <input type="text" id="cartMapSearchInput" placeholder="Search street, subdivision, village, landmark..." style="width:100%; height:38px; border-radius:10px; border:1.5px solid var(--line); padding:6px 28px 6px 30px; font-size:12px; font-family:inherit; outline:none; box-sizing:border-box;" oninput="handleCartMapSearchInput(this.value)" onkeydown="if(event.key==='Enter'){event.preventDefault();executeCartMapSearch();}">
            <button type="button" id="cartMapSearchClear" onclick="clearCartMapSearch()" style="display:none; position:absolute; right:8px; top:50%; transform:translateY(-50%); background:none; border:none; color:#9ca3af; font-size:14px; cursor:pointer; padding:2px;">&times;</button>
          </div>
          <button type="button" onclick="executeCartMapSearch()" id="btnCartMapSearch" style="background:var(--olive); color:#ffffff; border:none; border-radius:10px; padding:0 14px; font-size:12px; font-weight:600; cursor:pointer; flex-shrink:0;">Search</button>
        </div>
        <div id="cartMapSearchResults" style="display:none; position:absolute; top:42px; left:0; right:0; background:#ffffff; border:1px solid #d8cbb5; border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,0.16); max-height:180px; overflow-y:auto; z-index:99999;"></div>
      </div>

      <!-- Action Row: Use My Exact Location & GPS Status Indicator -->
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; gap:8px; flex-wrap:wrap;">
        <button type="button" class="btn-exact-location" id="cartModalBtnLocateMe" onclick="locateCartCurrentGps(true)">
          <i class="fa-solid fa-location-crosshairs"></i> Use My Exact Location
        </button>
        <span id="cartModalGpsStatus" style="font-size:11.5px; color:#6b7280; font-weight:500;">
          <i class="fa-solid fa-crosshairs"></i> Tap Locate Me or map
        </span>
      </div>

      <!-- Mini Leaflet Map -->
      <div id="cartMapContainer" style="height:210px; width:100%; border-radius:12px; border:1px solid var(--line); margin-bottom:12px;"></div>

      <!-- Address Input (Populated with live location, allowing user to supplement specific local Purok) -->
      <div style="margin-bottom:12px;">
        <label style="display:block; font-size:11px; font-weight:700; color:var(--olive-dark); margin-bottom:4px;">
          <i class="fa-solid fa-pen" style="color:var(--harvest-sun);"></i> Delivery Address (supplement with your local Purok details):
        </label>
        <textarea id="cartModalAddressInput" class="address-textarea" placeholder="Enter complete address (e.g. Purok 3, House No., Street, Barangay, Landmark)" style="min-height:60px; font-size:12px;" oninput="window._cartUserHasTypedAddress=true;"></textarea>
        <div id="cartModalCoordsStatus" class="coords-badge" style="margin-top:6px; display:none;">
          <i class="fa-solid fa-circle-check"></i>
          <span id="cartModalCoordsText">Pinned Location</span>
        </div>
      </div>

      <button type="button" class="btn-submit-order checkout-btn" onclick="confirmCartMapLocation()" style="background:var(--olive); color:#ffffff; margin-top:0;">
        <i class="fa-solid fa-check"></i> Use This Location
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
                    html += '<div class="order-item-row" data-index="' + idx + '">' +
                        '<div class="order-item-desc">' +
                        '<div class="item-icon">' + icon + '</div>' +
                        '<div>' +
                        '<strong style="display:block; font-size:13px;">' + name + '</strong>' +
                        '<div style="display:flex; align-items:center; gap:8px; margin-top:3px;">' +
                        '<small style="color:#6b7280;">' + store + '</small>' +
                        '<div class="qty" style="display:inline-flex; align-items:center; gap:5px; background:#f3f4f6; padding:2px 6px; border-radius:12px;">' +
                        '<button type="button" class="btn-qty-minus" onclick="updateCartItemQty(' + idx + ', -1, event)" aria-label="Decrease quantity" style="width:20px; height:20px; border-radius:50%; border:1px solid #d1d5db; background:#fff; cursor:pointer; font-size:11px; display:inline-flex; align-items:center; justify-content:center; line-height:1; padding:0; touch-action:manipulation;">−</button>' +
                        '<span style="font-weight:700; font-size:11px; min-width:14px; text-align:center;">' + qty + '</span>' +
                        '<button type="button" class="btn-qty-plus" onclick="updateCartItemQty(' + idx + ', 1, event)" aria-label="Increase quantity" style="width:20px; height:20px; border-radius:50%; border:1px solid #d1d5db; background:#fff; cursor:pointer; font-size:11px; display:inline-flex; align-items:center; justify-content:center; line-height:1; padding:0; touch-action:manipulation;">+</button>' +
                        '</div>' +
                        '</div>' +
                        '</div>' +
                        '</div>' +
                        '<span style="font-weight:700; font-size:14px;">₱' + (price * qty).toFixed(2) + '</span>' +
                        '</div>';
                }
                currentSubtotal = calcSub;
                currentDeliveryFee = currentSubtotal > 0 ? 30.00 : 0.00;
                currentGrandTotal = currentSubtotal + currentDeliveryFee;
                grandTotalFormatted = '₱' + Number(currentGrandTotal).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                
                var itemsContainer = document.getElementById('orderSummaryItems');
                if (itemsContainer && html) itemsContainer.innerHTML = html;
                var countEl = document.getElementById('orderItemCount');
                if (countEl) countEl.textContent = parsed.length + ' Item(s)';
                var headCount = document.getElementById('cartHeaderCount');
                if (headCount) headCount.textContent = parsed.length + ' Item(s)';
                
                var subEl = document.getElementById('displaySubtotal');
                if (subEl) subEl.textContent = '₱' + Number(currentSubtotal).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                var feeEl = document.getElementById('displayFee');
                if (feeEl) feeEl.textContent = '₱' + Number(currentDeliveryFee).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
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
                var feeIn = document.getElementById('delivery_fee');
                if (feeIn) feeIn.value = currentDeliveryFee;
            }
        }
        
        // Delivery address input starts completely blank on page load per user requirement
        var addrEl = document.getElementById('delivery_address');
        var addrSec = document.getElementById('deliveryAddressInput');
        if (addrEl && addrSec && !window._cartUserHasTypedAddress) {
            addrEl.value = '';
            addrSec.value = '';
        }
    } catch(e) {
        console.error('Cart sync error:', e);
    }
}

// Debounce execution guard to strictly prevent double-firing (+2 increment) on mobile touch/click
var _isCartQtyUpdating = false;
var _lastCartQtyActionTime = 0;

function updateCartItemQty(index, change, event) {
    if (event) {
        if (event.preventDefault && event.cancelable) event.preventDefault();
        if (event.stopPropagation) event.stopPropagation();
        if (event.stopImmediatePropagation) event.stopImmediatePropagation();
    }

    var now = Date.now();
    // 1. Debounce threshold: ignore duplicate events within 300ms
    if (now - _lastCartQtyActionTime < 300) {
        return;
    }
    // 2. Atomic mutex guard: ignore any trigger if already executing
    if (_isCartQtyUpdating) {
        return;
    }

    _isCartQtyUpdating = true;
    _lastCartQtyActionTime = now;

    try {
        var rawCart = localStorage.getItem('foodDashCart');
        var cartData = rawCart ? JSON.parse(rawCart) : <?= json_encode(!empty($cart_items) ? $cart_items : []) ?>;
        
        if (Array.isArray(cartData) && cartData[index]) {
            var currentQty = Number(cartData[index].qty || cartData[index].quantity) || 1;
            var nextQty = currentQty + change;
            
            if (nextQty <= 0) {
                cartData.splice(index, 1);
            } else {
                cartData[index].qty = nextQty;
            }
            
            localStorage.setItem('foodDashCart', JSON.stringify(cartData));
            syncCartFromLocalStorage();
            if (typeof selectMethod === 'function') {
                selectMethod(activePaymentMethod || 'gcash');
            }
        }
    } catch(err) {
        console.error('Error updating cart quantity:', err);
    } finally {
        setTimeout(function() {
            _isCartQtyUpdating = false;
        }, 300);
    }
}

window.updateCartItemQty = updateCartItemQty;
window.updateCartQty = updateCartItemQty;
window.changeQty = updateCartItemQty;

// 3. DYNAMIC BUTTON THEME & LOGO UPDATE: (Direct getElementById ONLY, NO querySelector)
function selectMethod(method) {
    activePaymentMethod = method;
    selectedMethod = method;
    activeMethod = method;
    currentPayment = method;
    var btn = document.getElementById('mainPayBtn') || document.getElementById('payActionBtn');
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
        btn.innerHTML = '<svg viewBox="0 0 15 16" width="16" height="17" fill="currentColor" style="vertical-align:-2px; margin-right:4px;" aria-hidden="true"><path d="M 8.5,1.2 C 9.5,0.2 10.6,0 10.6,0 C 10.6,0 10.4,1.1 9.4,2.1 C 8.4,3.1 7.4,3 7.4,3 C 7.4,3 7.5,2.1 8.5,1.2 Z"/><path d="M 11.2,6.5 C 11.2,4.8 12.6,3.9 12.7,3.8 C 11.9,2.6 10.6,2.4 10.1,2.4 C 8.9,2.2 7.7,3.1 7.1,3.1 C 6.5,3.1 5.5,2.3 4.5,2.4 C 3.2,2.4 2.0,3.2 1.3,4.4 C -0.1,6.8 0.9,10.3 2.3,12.3 C 3.0,13.3 3.8,14.4 4.9,14.4 C 5.9,14.3 6.3,13.7 7.6,13.7 C 8.8,13.7 9.2,14.4 10.3,14.3 C 11.4,14.3 12.1,13.3 12.8,12.3 C 13.6,11.2 13.9,10.1 14.0,10.0 C 13.9,10.0 11.2,9.0 11.2,6.5 Z"/></svg>' + ' Pay • ' + grandTotalFormatted;
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
    if (codBtn) codBtn.innerHTML = '<i class="fa-solid fa-check"></i> Confirm Order • ' + grandTotalFormatted;
    var appleTot = document.getElementById('appleModalTotal');
    if (appleTot) appleTot.textContent = grandTotalFormatted;
}

// 2. HARD-ISOLATE THE BUTTON CLICK & PREVENT SUPPRESSION:
function openPaymentPopup(event) {
    if (event) {
        if (event.preventDefault) event.preventDefault();
        if (event.stopPropagation) event.stopPropagation();
    }

    // Do not run regex validation on the coordinate string
    var latLng = document.getElementById('delivery_lat_lng')?.value || '';
    var address = (document.getElementById('delivery_address')?.value || '').trim();

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
        var codModal = document.getElementById('modal-cod');
        if (codModal) {
            codModal.style.display = 'flex';
            return;
        }
    }

    finalizeOrder(activePaymentMethod || 'cod', address, latLng);
}

var handleCheckoutClick = openPaymentPopup;
var triggerCheckout = openPaymentPopup;

function finalizeOrder(method, address, coords) {
    var checkAddr = String(address || document.getElementById('delivery_address')?.value || '').trim();
    if (!checkAddr) {
        alert('Please enter your delivery address before confirming your order.');
        var addrInput = document.getElementById('delivery_address');
        if (addrInput) addrInput.focus();
        return;
    }
    var chosenMethod = method || activePaymentMethod || 'cod';
    var chosenAddress = String(address || document.getElementById('delivery_address')?.value || '').trim();
    var chosenCoords = coords || document.getElementById('delivery_lat_lng')?.value || '';

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

    var totalAmt = (typeof currentGrandTotal === 'number' && currentGrandTotal > 0) ? currentGrandTotal : (currentSubtotal + currentDeliveryFee);
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

    var submitBtn = document.getElementById('mainPayBtn') || document.getElementById('payActionBtn');
    if (submitBtn) submitBtn.disabled = true;
    var walletBtn = document.getElementById('walletSubmitBtn');
    if (walletBtn) walletBtn.disabled = true;
    var codBtn = document.getElementById('codSubmitBtn');
    if (codBtn) codBtn.disabled = true;

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

var dispatchOrderPlacement = finalizeOrder;

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
        btn.innerHTML = '<svg viewBox="0 0 15 16" width="16" height="17" fill="currentColor" style="vertical-align:-2px; margin-right:4px;" aria-hidden="true"><path d="M 8.5,1.2 C 9.5,0.2 10.6,0 10.6,0 C 10.6,0 10.4,1.1 9.4,2.1 C 8.4,3.1 7.4,3 7.4,3 C 7.4,3 7.5,2.1 8.5,1.2 Z"/><path d="M 11.2,6.5 C 11.2,4.8 12.6,3.9 12.7,3.8 C 11.9,2.6 10.6,2.4 10.1,2.4 C 8.9,2.2 7.7,3.1 7.1,3.1 C 6.5,3.1 5.5,2.3 4.5,2.4 C 3.2,2.4 2.0,3.2 1.3,4.4 C -0.1,6.8 0.9,10.3 2.3,12.3 C 3.0,13.3 3.8,14.4 4.9,14.4 C 5.9,14.3 6.3,13.7 7.6,13.7 C 8.8,13.7 9.2,14.4 10.3,14.3 C 11.4,14.3 12.1,13.3 12.8,12.3 C 13.6,11.2 13.9,10.1 14.0,10.0 C 13.9,10.0 11.2,9.0 11.2,6.5 Z"/></svg> Scanning Face...';
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
            var latLng = document.getElementById('delivery_lat_lng')?.value || '';
            var address = (document.getElementById('delivery_address')?.value || '').trim();
            finalizeOrder('apple_pay', address, latLng);
        }, 700);
    }, 1200);
}

function closeModal(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = 'none';
}

// ==============================================
// OPTIONAL MAP SEARCH & PINNING CONTROLLER
// ==============================================
var CART_ORMOC_LANDMARKS = [
    { name: 'Alta Vista', desc: 'Alta Vista, Ormoc City, Leyte', lat: 11.0134, lon: 124.6199 },
    { name: 'Western Leyte College (WLC)', desc: 'A. Bonifacio St, Barangay Cogon, Ormoc City', lat: 11.0076, lon: 124.6089 },
    { name: 'Don Felipe Subdivision', desc: 'Barangay Don Felipe Larrazabal, Ormoc City', lat: 11.0120, lon: 124.6150 },
    { name: 'Cogon Combado', desc: 'Combado / Cogon, Ormoc City, Leyte', lat: 11.0185, lon: 124.6062 },
    { name: 'Barangay Cogon', desc: 'Cogon, Ormoc City, Leyte', lat: 11.0233014, lon: 124.6040868 },
    { name: 'Batuan', desc: 'Barangay Batuan, Ormoc City, Leyte', lat: 11.0185, lon: 124.6062 },
    { name: 'Lilia Subdivision', desc: 'Lilia, Cogon, Ormoc City, Leyte', lat: 11.0185, lon: 124.6062 },
    { name: 'Linao', desc: 'Barangay Linao, Ormoc City, Leyte', lat: 11.0159798, lon: 124.5908042 },
    { name: 'Punta', desc: 'Barangay Punta, Ormoc City, Leyte', lat: 11.0092018, lon: 124.6003072 },
    { name: 'San Pedro', desc: 'Barangay San Pedro, Ormoc City, Leyte', lat: 11.0057362, lon: 124.6108008 },
    { name: 'Valencia', desc: 'Barangay Valencia, Ormoc City, Leyte', lat: 11.1071389, lon: 124.5782215 },
    { name: 'Camp Downes', desc: 'Camp Downes, Ormoc City, Leyte', lat: 10.9966183, lon: 124.6182589 },
    { name: 'Bantigue', desc: 'Barangay Bantigue, Ormoc City, Leyte', lat: 10.9895862, lon: 124.6222361 },
    { name: 'Tambulilid', desc: 'Barangay Tambulilid, Ormoc City, Leyte', lat: 11.0231637, lon: 124.5846183 },
    { name: 'Can-Adieng', desc: 'Barangay Can-Adieng, Ormoc City, Leyte', lat: 11.0025430, lon: 124.6139237 },
    { name: 'Ipil', desc: 'Barangay Ipil, Ormoc City, Leyte', lat: 10.9754111, lon: 124.6331304 }
];

var cartLeafletMap = null;
var cartLeafletMarker = null;
var cartPinnedLat = null;
var cartPinnedLng = null;
var cartMapDebounceTimer = null;
var cartMapSearchResults = [];
window._cartUserHasTypedAddress = false;
window._isCartLocatingGps = false;
window._lastCartGeocodedArea = '';

function showCartToast(msg, type) {
    var existing = document.getElementById('cartToastNotification');
    if (existing) existing.remove();

    var toast = document.createElement('div');
    toast.id = 'cartToastNotification';
    toast.className = 'toast-cart-box';
    
    var icon = 'fa-circle-info';
    var bg = '#1f2937';
    if (type === 'success') {
        icon = 'fa-circle-check';
        bg = '#143820';
    } else if (type === 'warning' || type === 'info') {
        icon = 'fa-location-dot';
        bg = '#2a261a';
    } else if (type === 'error') {
        icon = 'fa-triangle-exclamation';
        bg = '#3f1a1a';
    }
    toast.style.background = bg;
    toast.innerHTML = '<i class="fa-solid ' + icon + '" style="color:var(--harvest-sun);"></i> <span>' + msg + '</span>';
    document.body.appendChild(toast);

    setTimeout(function() {
        if (toast && toast.parentNode) {
            toast.style.opacity = '0';
            toast.style.transform = 'translate(-50%, 10px)';
            setTimeout(function() { if (toast && toast.parentNode) toast.remove(); }, 350);
        }
    }, 3200);
}

function setCartGpsButtonsLoading(isLoading) {
    var btnLocate = document.getElementById('cartBtnLocateMe');
    var btnModalLocate = document.getElementById('cartModalBtnLocateMe');
    var statusText = document.getElementById('cartModalGpsStatus');

    if (btnLocate) {
        btnLocate.disabled = isLoading;
        btnLocate.innerHTML = isLoading 
            ? '<i class="fa-solid fa-spinner fa-spin"></i> Locating...' 
            : '<i class="fa-solid fa-crosshairs"></i> Locate Me';
    }
    if (btnModalLocate) {
        btnModalLocate.disabled = isLoading;
        btnModalLocate.innerHTML = isLoading 
            ? '<i class="fa-solid fa-spinner fa-spin"></i> Locating...' 
            : '<i class="fa-solid fa-location-crosshairs"></i> Use My Exact Location';
    }
    if (statusText) {
        if (isLoading) {
            statusText.innerHTML = '<i class="fa-solid fa-satellite-dish fa-spin" style="color:var(--harvest-sun);"></i> Fetching Live GPS...';
        } else if (cartPinnedLat && cartPinnedLng) {
            statusText.innerHTML = '<i class="fa-solid fa-check" style="color:#05B851;"></i> Live GPS Acquired';
        } else {
            statusText.innerHTML = '<i class="fa-solid fa-location-dot"></i> Live GPS / Tap to Pin';
        }
    }
}

// 1. Dynamic Live Browser GPS Geolocation Controller
// Calls navigator.geolocation.getCurrentPosition(successCallback, errorCallback, { enableHighAccuracy: true }) dynamically
// Immediately updates Leaflet map view and marker to exact live coordinates, and runs reverse-geocoding fetch
// If geolocation fails, is denied, or times out: gracefully allows manual map pin drop or typing without hardcoded fallbacks
function locateCartCurrentGps(isModal) {
    if (window._isCartLocatingGps) return;
    window._isCartLocatingGps = true;
    setCartGpsButtonsLoading(true);

    function errorCallback(error) {
        setCartGpsButtonsLoading(false);
        window._isCartLocatingGps = false;

        var reason = 'Could not acquire live GPS.';
        if (error && error.code === error.PERMISSION_DENIED) {
            reason = 'Location permission was denied. Tap the map to drop a pin or type your address manually.';
        } else if (error && error.code === error.TIMEOUT) {
            reason = 'Live GPS request timed out. Tap the map to drop a pin or type your address manually.';
        } else if (error && error.code === error.POSITION_UNAVAILABLE) {
            reason = 'Live GPS signal is unavailable. Tap the map to drop a pin or type your address manually.';
        } else if (typeof error === 'string') {
            reason = error;
        }

        var statusText = document.getElementById('cartModalGpsStatus');
        if (statusText) {
            statusText.innerHTML = '<span style="color:#d97706;"><i class="fa-solid fa-hand-pointer"></i> Tap map to drop pin or type address</span>';
        }

        // Strictly NO hardcoded city or network IP fallback
        showCartToast(reason, 'info');
    }

    if (!navigator.geolocation) {
        errorCallback('Geolocation is not supported by your browser. Please tap the map or type your address manually.');
        return;
    }

    var gpsResolved = false;
    var safetyTimeout = setTimeout(function() {
        if (!gpsResolved) {
            gpsResolved = true;
            console.warn('Live GPS request timed out.');
            errorCallback('Live GPS timed out. Tap the map to drop a pin or type your address manually.');
        }
    }, 12000);

    try {
        navigator.geolocation.getCurrentPosition(
            function successCallback(position) {
                if (gpsResolved) return;
                gpsResolved = true;
                clearTimeout(safetyTimeout);

                // 2. Extract live device coordinates
                var lat = position.coords.latitude;
                var lng = position.coords.longitude;

                cartPinnedLat = lat;
                cartPinnedLng = lng;

                // Immediately update Leaflet map view and marker position to exact live values
                if (!cartLeafletMap) {
                    initCartMap();
                }
                if (cartLeafletMap) {
                    cartLeafletMap.setView([lat, lng], 17);
                    if (!cartLeafletMarker) {
                        cartLeafletMarker = L.marker([lat, lng], { draggable: true }).addTo(cartLeafletMap);
                        cartLeafletMarker.bindPopup('<b>Live GPS Location</b><br>Drag to fine-tune your delivery location');
                        cartLeafletMarker.on('dragend', function() {
                            var pos = cartLeafletMarker.getLatLng();
                            onCartMapPinMoved(pos.lat, pos.lng);
                        });
                    } else {
                        cartLeafletMarker.setLatLng([lat, lng]);
                    }
                    if (cartLeafletMarker) {
                        cartLeafletMarker.bindPopup('<b>Live GPS Location</b><br>Drag to fine-tune your delivery location').openPopup();
                    }
                }

                // Update modal coords badge
                var statusEl = document.getElementById('cartModalCoordsStatus');
                var textEl = document.getElementById('cartModalCoordsText');
                if (statusEl && textEl) {
                    statusEl.style.display = 'flex';
                    textEl.textContent = 'Live GPS: ' + lat.toFixed(5) + ', ' + lng.toFixed(5);
                }

                // Sync hidden inputs
                var latLngInput = document.getElementById('delivery_lat_lng');
                if (latLngInput) latLngInput.value = lat + ', ' + lng;
                var latIn = document.getElementById('delivery_lat');
                if (latIn) latIn.value = lat;
                var lngIn = document.getElementById('delivery_lng');
                if (lngIn) lngIn.value = lng;
                var cLatIn = document.getElementById('customer_lat');
                if (cLatIn) cLatIn.value = lat;
                var cLngIn = document.getElementById('customer_lng');
                if (cLngIn) cLngIn.value = lng;

                var coordsDisp = document.getElementById('coordsDisplay');
                if (coordsDisp) {
                    coordsDisp.textContent = lat.toFixed(5) + ', ' + lng.toFixed(5);
                }

                var statusText = document.getElementById('cartModalGpsStatus');
                if (statusText) {
                    statusText.innerHTML = '<i class="fa-solid fa-check" style="color:#05B851;"></i> Live GPS Acquired';
                }

                // Immediately run reverse-geocoding fetch with live GPS coordinates
                reverseGeocodeCartCoords(lat, lng, !isModal);

                setCartGpsButtonsLoading(false);
                window._isCartLocatingGps = false;
                showCartToast('Pinned to your live device location!', 'success');
            },
            function onGpsError(err) {
                if (gpsResolved) return;
                gpsResolved = true;
                clearTimeout(safetyTimeout);
                errorCallback(err);
            },
            {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 0
            }
        );
    } catch(e) {
        if (!gpsResolved) {
            gpsResolved = true;
            clearTimeout(safetyTimeout);
            console.warn('Geolocation exception:', e);
            errorCallback('Unable to access live GPS. Please tap the map or enter your address manually.');
        }
    }
}

function handleCartAddressTyping(value) {
    window._cartUserHasTypedAddress = true;
    var val = value || '';
    var sec = document.getElementById('deliveryAddressInput');
    if (sec) sec.value = val;
    var appleDest = document.getElementById('appleModalDest');
    if (appleDest) appleDest.textContent = val;
    var walletDest = document.getElementById('walletDest');
    if (walletDest) walletDest.textContent = val;
    var codDest = document.getElementById('codDest');
    if (codDest) codDest.textContent = val;
}

function openCartMapModal() {
    var modal = document.getElementById('cartMapModal');
    if (modal) modal.style.display = 'flex';
    var mainAddr = document.getElementById('delivery_address');
    var modalAddr = document.getElementById('cartModalAddressInput');
    if (mainAddr && modalAddr) {
        modalAddr.value = mainAddr.value;
    }
    initCartMap();
}

function closeCartMapModal() {
    var modal = document.getElementById('cartMapModal');
    if (modal) modal.style.display = 'none';
}

function initCartMap() {
    if (typeof L === 'undefined') return;
    var container = document.getElementById('cartMapContainer');
    if (!container) return;

    var hasCoords = cartPinnedLat && cartPinnedLng;
    var initLat = hasCoords ? cartPinnedLat : 11.01034;
    var initLng = hasCoords ? cartPinnedLng : 124.61781;
    var initZoom = hasCoords ? 17 : 14;

    if (!cartLeafletMap) {
        cartLeafletMap = L.map('cartMapContainer').setView([initLat, initLng], initZoom);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap'
        }).addTo(cartLeafletMap);

        if (hasCoords) {
            cartLeafletMarker = L.marker([initLat, initLng], { draggable: true }).addTo(cartLeafletMap);
            cartLeafletMarker.bindPopup('<b>Delivery Pin</b><br>Drag to fine-tune your delivery location');
            cartLeafletMarker.on('dragend', function() {
                var pos = cartLeafletMarker.getLatLng();
                onCartMapPinMoved(pos.lat, pos.lng);
            });
        }

        cartLeafletMap.on('click', function(e) {
            if (!cartLeafletMarker) {
                cartLeafletMarker = L.marker(e.latlng, { draggable: true }).addTo(cartLeafletMap);
                cartLeafletMarker.bindPopup('<b>Delivery Pin</b><br>Drag to fine-tune your delivery location');
                cartLeafletMarker.on('dragend', function() {
                    var pos = cartLeafletMarker.getLatLng();
                    onCartMapPinMoved(pos.lat, pos.lng);
                });
            } else {
                cartLeafletMarker.setLatLng(e.latlng);
            }
            onCartMapPinMoved(e.latlng.lat, e.latlng.lng);
        });
    } else {
        if (hasCoords) {
            cartLeafletMap.setView([initLat, initLng], 17);
            if (!cartLeafletMarker) {
                cartLeafletMarker = L.marker([initLat, initLng], { draggable: true }).addTo(cartLeafletMap);
                cartLeafletMarker.bindPopup('<b>Delivery Pin</b><br>Drag to fine-tune your delivery location');
                cartLeafletMarker.on('dragend', function() {
                    var pos = cartLeafletMarker.getLatLng();
                    onCartMapPinMoved(pos.lat, pos.lng);
                });
            } else {
                cartLeafletMarker.setLatLng([initLat, initLng]);
            }
        } else {
            cartLeafletMap.setView([initLat, initLng], initZoom);
        }
    }

    setTimeout(function() {
        if (cartLeafletMap) cartLeafletMap.invalidateSize();
    }, 200);
}

function onCartMapPinMoved(lat, lng) {
    cartPinnedLat = lat;
    cartPinnedLng = lng;
    var statusEl = document.getElementById('cartModalCoordsStatus');
    var textEl = document.getElementById('cartModalCoordsText');
    if (statusEl && textEl) {
        statusEl.style.display = 'flex';
        textEl.textContent = 'Pinned: ' + lat.toFixed(5) + ', ' + lng.toFixed(5);
    }
    var statusText = document.getElementById('cartModalGpsStatus');
    if (statusText) {
        statusText.innerHTML = '<i class="fa-solid fa-map-pin" style="color:var(--harvest-sun);"></i> Pinned on Map';
    }
    reverseGeocodeCartCoords(lat, lng, false);
}

function reverseGeocodeCartCoords(lat, lng, syncMainAddress) {
    fetch('https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&lat=' + lat + '&lon=' + lng, {
        headers: { 'Accept': 'application/json' }
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data && data.address) {
            var addr = data.address;
            var road = addr.road || addr.pedestrian || addr.street || '';
            var brgy = addr.village || addr.suburb || addr.neighbourhood || addr.residential || addr.quarter || '';
            var city = addr.city || addr.town || addr.municipality || '';

            var parts = [];
            if (road) parts.push(road);
            if (brgy && brgy !== road) parts.push(brgy);
            if (city && !parts.join(', ').includes(city)) parts.push(city);

            var area = parts.join(', ') || data.name || (data.display_name ? data.display_name.split(',')[0] : '');
            if (area) {
                applyCartPinnedArea(area, syncMainAddress);
            }
        }
    })
    .catch(function(err) {
        console.warn('Reverse geocoding fetch failed:', err);
    });
}

function applyCartPinnedArea(areaName, syncMain) {
    if (!areaName) return;
    var modalAddr = document.getElementById('cartModalAddressInput');
    var mainAddr = document.getElementById('delivery_address');
    
    // Choose active base value: modal input if available, else main delivery input
    var current = (modalAddr ? modalAddr.value : (mainAddr ? mainAddr.value : '')).trim();
    var lastArea = window._lastCartGeocodedArea || '';
    var newAddress = '';

    if (!current) {
        newAddress = areaName;
    } else if (lastArea && current.includes(lastArea)) {
        // Smoothly replace previous geocoded area suffix, preserving user's typed local Purok prefix
        newAddress = current.replace(lastArea, areaName);
    } else {
        // Check if user has entered specific local Purok or street details
        var hasPurokOrSpecifics = /\b(purok|prk|sitio|unit|house|lot|blk|block|near|tapad|atbang|gate|corner)\b/i.test(current);
        if (hasPurokOrSpecifics) {
            if (!current.toLowerCase().includes(areaName.toLowerCase())) {
                newAddress = current + ', ' + areaName;
            } else {
                newAddress = current;
            }
        } else if (!current.toLowerCase().includes(areaName.toLowerCase())) {
            newAddress = current + ', ' + areaName;
        } else {
            newAddress = current;
        }
    }

    window._lastCartGeocodedArea = areaName;

    if (modalAddr) {
        modalAddr.value = newAddress;
    }
    if (syncMain && mainAddr) {
        mainAddr.value = newAddress;
        handleCartAddressTyping(newAddress);
    }
}

function handleCartMapSearchInput(value) {
    var query = (value || '').trim();
    var clearBtn = document.getElementById('cartMapSearchClear');
    var resultsBox = document.getElementById('cartMapSearchResults');
    if (clearBtn) clearBtn.style.display = query ? 'block' : 'none';

    if (!query || query.length < 2) {
        if (resultsBox) resultsBox.style.display = 'none';
        cartMapSearchResults = [];
        return;
    }

    clearTimeout(cartMapDebounceTimer);
    cartMapDebounceTimer = setTimeout(function() {
        executeCartMapSearch();
    }, 280);
}

function clearCartMapSearch() {
    var inEl = document.getElementById('cartMapSearchInput');
    if (inEl) { inEl.value = ''; inEl.focus(); }
    var clearBtn = document.getElementById('cartMapSearchClear');
    if (clearBtn) clearBtn.style.display = 'none';
    var resultsBox = document.getElementById('cartMapSearchResults');
    if (resultsBox) resultsBox.style.display = 'none';
    cartMapSearchResults = [];
}

function executeCartMapSearch() {
    var inEl = document.getElementById('cartMapSearchInput');
    var query = (inEl ? inEl.value : '').trim();
    if (!query) return;

    var resultsBox = document.getElementById('cartMapSearchResults');
    if (!resultsBox) return;

    var norm = query.toLowerCase();
    var localMatches = CART_ORMOC_LANDMARKS.filter(function(it) {
        return it.name.toLowerCase().indexOf(norm) !== -1 || it.desc.toLowerCase().indexOf(norm) !== -1;
    });

    var html = '';
    cartMapSearchResults = [];

    localMatches.forEach(function(item) {
        cartMapSearchResults.push(item);
        var idx = cartMapSearchResults.length - 1;
        html += '<div onclick="selectCartSearchResult(' + idx + ')" style="padding:8px 12px; cursor:pointer; border-bottom:1px solid #f1f5f9; font-size:12px;">' +
            '<strong style="color:var(--olive-dark); display:block;">' + item.name + '</strong>' +
            '<small style="color:#6b7280;">' + item.desc + '</small>' +
            '</div>';
    });

    if (html) {
        resultsBox.innerHTML = html;
        resultsBox.style.display = 'block';
    }

    var osmQ = (!norm.includes('ormoc') && !norm.includes('leyte')) ? (query + ', Ormoc City, Leyte') : query;
    fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&addressdetails=1&limit=4&q=' + encodeURIComponent(osmQ), {
        headers: { 'Accept': 'application/json' }
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (Array.isArray(data) && data.length) {
            data.forEach(function(d) {
                var lat = parseFloat(d.lat);
                var lon = parseFloat(d.lon);
                var item = { name: d.name || d.display_name.split(',')[0], desc: d.display_name, lat: lat, lon: lon };
                cartMapSearchResults.push(item);
                var idx = cartMapSearchResults.length - 1;
                html += '<div onclick="selectCartSearchResult(' + idx + ')" style="padding:8px 12px; cursor:pointer; border-bottom:1px solid #f1f5f9; font-size:12px;">' +
                    '<strong style="color:var(--olive-dark); display:block;">' + item.name + '</strong>' +
                    '<small style="color:#6b7280;">' + item.desc + '</small>' +
                    '</div>';
            });
            resultsBox.innerHTML = html;
            resultsBox.style.display = 'block';
        }
    })
    .catch(function() {});
}

function selectCartSearchResult(index) {
    if (cartMapSearchResults && cartMapSearchResults[index]) {
        var item = cartMapSearchResults[index];
        cartPinnedLat = item.lat;
        cartPinnedLng = item.lon;

        if (cartLeafletMap) {
            cartLeafletMap.setView([item.lat, item.lon], 16);
            if (cartLeafletMarker) cartLeafletMarker.setLatLng([item.lat, item.lon]);
        }
        var resultsBox = document.getElementById('cartMapSearchResults');
        if (resultsBox) resultsBox.style.display = 'none';

        var statusEl = document.getElementById('cartModalCoordsStatus');
        var textEl = document.getElementById('cartModalCoordsText');
        if (statusEl && textEl) {
            statusEl.style.display = 'flex';
            textEl.textContent = 'Pinned: ' + item.lat.toFixed(5) + ', ' + item.lon.toFixed(5);
        }
        applyCartPinnedArea(item.name || item.desc);
    }
}

function confirmCartMapLocation() {
    var modalAddr = document.getElementById('cartModalAddressInput');
    var finalAddress = modalAddr ? modalAddr.value.trim() : '';

    var mainAddr = document.getElementById('delivery_address');
    if (mainAddr) {
        mainAddr.value = finalAddress;
        handleCartAddressTyping(finalAddress);
    }

    if (cartPinnedLat && cartPinnedLng) {
        var latLngInput = document.getElementById('delivery_lat_lng');
        if (latLngInput) latLngInput.value = cartPinnedLat + ', ' + cartPinnedLng;
        var latIn = document.getElementById('delivery_lat');
        if (latIn) latIn.value = cartPinnedLat;
        var lngIn = document.getElementById('delivery_lng');
        if (lngIn) lngIn.value = cartPinnedLng;
        var cLatIn = document.getElementById('customer_lat');
        if (cLatIn) cLatIn.value = cartPinnedLat;
        var cLngIn = document.getElementById('customer_lng');
        if (cLngIn) cLngIn.value = cartPinnedLng;

        var coordsDisp = document.getElementById('coordsDisplay');
        if (coordsDisp) {
            coordsDisp.textContent = cartPinnedLat.toFixed(5) + ', ' + cartPinnedLng.toFixed(5);
        }
    }

    closeCartMapModal();
}

// Aliases
var updatePaymentTheme = selectMethod;
var setPayment = selectMethod;
var triggerCheckoutFlow = openPaymentPopup;
var onCheckoutButtonClick = openPaymentPopup;
var handlePayAction = openPaymentPopup;
var submitDynamicOrder = finalizeOrder;
var submitOrderToBackend = finalizeOrder;
var processOrderAJAX = finalizeOrder;
var confirmAndPlaceOrder = finalizeOrder;

window.selectMethod = selectMethod;
window.updatePaymentTheme = selectMethod;
window.setPayment = selectMethod;
window.openPaymentPopup = openPaymentPopup;
window.handleCheckoutClick = openPaymentPopup;
window.triggerCheckout = openPaymentPopup;
window.finalizeOrder = finalizeOrder;
window.dispatchOrderPlacement = finalizeOrder;
window.openCartMapModal = openCartMapModal;
window.closeCartMapModal = closeCartMapModal;
window.handleCartAddressTyping = handleCartAddressTyping;
window.executeCartMapSearch = executeCartMapSearch;
window.handleCartMapSearchInput = handleCartMapSearchInput;
window.clearCartMapSearch = clearCartMapSearch;
window.selectCartSearchResult = selectCartSearchResult;
window.confirmCartMapLocation = confirmCartMapLocation;
window.locateCartCurrentGps = locateCartCurrentGps;
window.useMyExactLocation = locateCartCurrentGps;
window.detectCustomerGps = locateCartCurrentGps;
window.showCartToast = showCartToast;

document.addEventListener('DOMContentLoaded', function() {
    window._cartUserHasTypedAddress = false;
    var addrEl = document.getElementById('delivery_address');
    var addrSec = document.getElementById('deliveryAddressInput');
    var modalAddr = document.getElementById('cartModalAddressInput');
    if (addrEl) addrEl.value = '';
    if (addrSec) addrSec.value = '';
    if (modalAddr) modalAddr.value = '';
    cartPinnedLat = null;
    cartPinnedLng = null;
    syncCartFromLocalStorage();
    selectMethod('gcash');
});
</script>
</body>
</html>


