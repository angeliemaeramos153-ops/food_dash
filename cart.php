<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/api_orders.php';
    exit;
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db_connect.php';

// Session Identity: strictly from active session
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    if (!empty($_SESSION['customer_id'])) {
        $user_id = (int)$_SESSION['customer_id'];
    } elseif (!empty($_SESSION['customer']['user_id'])) {
        $user_id = (int)$_SESSION['customer']['user_id'];
    }
}

$customer_name  = $_SESSION['full_name'] ?? $_SESSION['username'] ?? '';
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
            if (empty($customer_name))    $customer_name  = $user_row['full_name'] ?: $user_row['username'];
            if (empty($customer_phone) && !empty($user_row['phone'])) $customer_phone = $user_row['phone'];
            if (empty($default_address))  $default_address = $user_row['default_address'] ?? $user_row['address'] ?? '';
            if ($default_lat === null && !empty($user_row['latitude']))  $default_lat = (float)$user_row['latitude'];
            if ($default_lng === null && !empty($user_row['longitude'])) $default_lng = (float)$user_row['longitude'];
        }
    } catch (Throwable $e) {}
}

if (empty($default_address)) $default_address = 'Western Leyte College';
if ($default_lat === null)    $default_lat = 11.01039;
if ($default_lng === null)    $default_lng = 124.61788;

// Dynamic cart & financials
$cart_items    = [];
if (!empty($_SESSION['cart']) && is_array($_SESSION['cart']))             $cart_items = $_SESSION['cart'];
elseif (!empty($_SESSION['foodDashCart']) && is_array($_SESSION['foodDashCart'])) $cart_items = $_SESSION['foodDashCart'];

$cart_subtotal = 0.0;
if (!empty($cart_items)) {
    foreach ($cart_items as $ci) {
        $price = (float)($ci['price'] ?? 0);
        $qty   = (int)($ci['qty'] ?? $ci['quantity'] ?? 1);
        $cart_subtotal += ($price * max(1, $qty));
    }
} else {
    $cart_subtotal = 170.00;
}

$subtotal     = (float)$cart_subtotal;
$delivery_fee = 30.00;
$grand_total  = $subtotal + $delivery_fee;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
  <title>My Cart - Food Dash</title>
  <meta name="description" content="Review your cart and place your order on Food Dash.">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <style>
    :root {
      --harvest-sun: #D88F2A;
      --olive:       #283d23;
      --olive-dark:  #1e2d18;
      --sandstone:   #D4C5A5;
      --sandstone-light: #FAF6EE;
      --paper:       #FFFDF9;
      --line:        #E2D9C8;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }

    body {
      font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background: var(--sandstone-light);
      color: #1f2937;
      min-height: 100vh;
      display: flex;
      justify-content: center;
    }

    .cart-wrapper {
      width: 100%;
      max-width: 480px;
      background: var(--paper);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      box-shadow: 0 0 30px rgba(0,0,0,0.06);
      position: relative;
      padding-bottom: 90px;
    }

    /* STICKY HEADER */
    .cart-header {
      background: #ffffff;
      padding: 14px 18px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 1px solid var(--line);
      position: sticky;
      top: 0;
      z-index: 200;
    }
    .cart-header h1 {
      font-size: 17px;
      font-weight: 700;
      color: var(--olive-dark);
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .back-btn {
      width: 36px; height: 36px;
      border-radius: 50%;
      border: 1px solid var(--line);
      background: #ffffff;
      display: flex; align-items: center; justify-content: center;
      color: #4B5320;
      cursor: pointer;
      text-decoration: none;
      font-size: 14px;
    }
    .item-badge {
      font-size: 11px;
      font-weight: 700;
      color: var(--olive);
      background: #edf3eb;
      padding: 3px 9px;
      border-radius: 20px;
    }

    .cart-content {
      padding: 16px;
      display: flex;
      flex-direction: column;
      gap: 14px;
      flex: 1;
    }

    .cart-card {
      background: #ffffff;
      border-radius: 18px;
      padding: 16px;
      border: 1px solid var(--line);
      box-shadow: 0 2px 8px rgba(0,0,0,0.02);
    }
    .card-label {
      font-size: 11px;
      font-weight: 700;
      color: #6b7280;
      text-transform: uppercase;
      letter-spacing: 0.7px;
      margin-bottom: 12px;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .card-label i { color: var(--olive); font-size: 13px; }

    /* CART ITEM ROW */
    .cart-item-row {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px 0;
      border-bottom: 1px dashed var(--line);
    }
    .cart-item-row:last-child { border-bottom: none; }
    .item-emoji {
      font-size: 26px;
      width: 44px; height: 44px;
      background: var(--sandstone-light);
      border-radius: 12px;
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
    }
    .item-info { flex: 1; min-width: 0; }
    .item-info strong {
      display: block;
      font-size: 13px;
      font-weight: 700;
      color: #1f2937;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .item-info small { font-size: 11px; color: #6b7280; }
    .item-price {
      font-size: 14px;
      font-weight: 700;
      color: var(--olive-dark);
      white-space: nowrap;
    }

    /* QTY CONTROLS */
    .qty-controls {
      display: flex;
      align-items: center;
      background: var(--sandstone-light);
      border-radius: 10px;
      overflow: hidden;
      border: 1px solid var(--line);
    }
    .qty-btn {
      width: 30px; height: 30px;
      border: none;
      background: transparent;
      font-size: 16px;
      font-weight: 700;
      color: var(--olive);
      cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      transition: background 0.15s;
    }
    .qty-btn:active { background: rgba(40,61,35,0.1); }
    .qty-btn.remove-btn { color: #ef4444; }
    .qty-num {
      min-width: 26px;
      text-align: center;
      font-size: 13px;
      font-weight: 700;
      color: #1f2937;
    }

    /* ADDRESS */
    .address-textarea {
      width: 100%;
      min-height: 68px;
      border-radius: 12px;
      border: 1px solid var(--line);
      padding: 10px 12px;
      font-family: inherit;
      font-size: 13px;
      color: #374151;
      resize: vertical;
      background: var(--sandstone-light);
      outline: none;
      transition: border-color 0.2s;
    }
    .address-textarea:focus { border-color: var(--olive); background: #fff; }
    .coords-badge {
      display: flex; align-items: center; gap: 6px;
      margin-top: 8px;
      font-size: 11px; color: #4B5320;
      background: #edf3eb;
      padding: 6px 10px;
      border-radius: 8px;
    }

    /* PAYMENT CARDS */
    .pay-list { display: flex; flex-direction: column; gap: 8px; }
    .pay-card {
      display: flex; align-items: center; justify-content: space-between;
      padding: 13px 14px;
      border-radius: 14px;
      border: 1.5px solid var(--line);
      background: #ffffff;
      cursor: pointer;
      transition: all 0.2s ease;
      user-select: none;
    }
    .pay-card:hover { border-color: #cbd5e1; background: #fafafa; }
    .pay-card.active { border-color: var(--olive); background: #f7faf6; box-shadow: 0 0 0 1px var(--olive); }
    .pay-card#pc-gcash.active   { border-color:#007DFE; background:#f0f7ff; box-shadow:0 0 0 1px #007DFE; }
    .pay-card#pc-maya.active    { border-color:#05B851; background:#f0fdf4; box-shadow:0 0 0 1px #05B851; }
    .pay-card#pc-gotyme.active  { border-color:#00d2ff; background:#f0faff; box-shadow:0 0 0 1px #00d2ff; }
    .pay-card#pc-apple.active   { border-color:#000; background:#f8f8f8; box-shadow:0 0 0 1px #000; }
    .pay-left { display: flex; align-items: center; gap: 10px; }
    .pay-logo {
      width: 44px; height: 32px;
      border-radius: 8px;
      background: #f3f4f6;
      display: flex; align-items: center; justify-content: center;
      padding: 4px;
      border: 1px solid rgba(0,0,0,0.06);
    }
    .pay-logo img { max-width: 100%; max-height: 100%; object-fit: contain; }
    .pay-info strong { display: block; font-size: 14px; color: #1f2937; }
    .pay-info small  { font-size: 11px; color: #6b7280; }
    .pay-radio { font-size: 18px; color: #9ca3af; transition: color 0.2s; }
    .pay-card.active .pay-radio { color: var(--olive); }
    .pay-card#pc-gcash.active  .pay-radio { color: #007DFE; }
    .pay-card#pc-maya.active   .pay-radio { color: #05B851; }
    .pay-card#pc-gotyme.active .pay-radio { color: #00d2ff; }
    .pay-card#pc-apple.active  .pay-radio { color: #000; }

    /* TOTALS */
    .total-row {
      display: flex; justify-content: space-between;
      font-size: 13px; color: #4b5563;
      margin-bottom: 8px;
    }
    .total-row.grand {
      border-top: 1px solid var(--line);
      margin-top: 10px; padding-top: 12px;
      font-size: 16px; font-weight: 700;
      color: var(--olive-dark);
    }

    /* STICKY ACTION BUTTON */
    .sticky-pay-bar {
      position: fixed;
      bottom: 0; left: 50%;
      transform: translateX(-50%);
      width: 100%; max-width: 480px;
      padding: 12px 16px;
      padding-bottom: calc(12px + env(safe-area-inset-bottom));
      background: rgba(255,253,249,0.95);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      border-top: 1px solid var(--line);
      z-index: 199;
    }
    #mainPayBtn {
      width: 100%; height: 54px;
      border: none; border-radius: 14px;
      font-size: 16px; font-weight: 700;
      cursor: pointer;
      display: flex; align-items: center; justify-content: center; gap: 8px;
      box-shadow: 0 4px 18px rgba(0,0,0,0.22);
      transition: transform 0.1s ease;
      background-color: #007DFE;
      color: #ffffff;
    }
    #mainPayBtn:active { transform: scale(0.97); }

    /* MODALS */
    .sim-modal {
      position: fixed; inset: 0;
      background: rgba(0,0,0,0.72);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      z-index: 10000;
      display: flex; align-items: flex-end; justify-content: center;
    }
    .sim-sheet {
      width: 100%; max-width: 480px;
      border-radius: 28px 28px 0 0;
      box-shadow: 0 -12px 40px rgba(0,0,0,0.5);
      animation: sheetUp 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
      overflow: hidden;
    }
    @keyframes sheetUp { from { transform: translateY(100%); } to { transform: translateY(0); } }
    .sheet-grip {
      width: 42px; height: 5px;
      border-radius: 3px;
      margin: 12px auto 6px;
      background: rgba(255,255,255,0.5);
    }
    .modal-cancel-btn {
      background: rgba(255,255,255,0.18);
      border: none; color: #ffffff;
      font-size: 12px; font-weight: 700;
      padding: 6px 14px; border-radius: 20px; cursor: pointer;
    }
    .modal-action-btn {
      width: 100%; height: 52px;
      border: none; border-radius: 14px;
      font-size: 15px; font-weight: 700; cursor: pointer;
      display: flex; align-items: center; justify-content: center; gap: 8px;
      box-shadow: 0 4px 16px rgba(0,0,0,0.2);
      transition: transform 0.1s;
    }
    .modal-action-btn:active { transform: scale(0.97); }

    /* Face ID */
    .face-id-wrapper { display: flex; flex-direction: column; align-items: center; padding: 16px 0 8px; }
    .face-id-box {
      width: 80px; height: 80px;
      position: relative; display: flex; align-items: center; justify-content: center;
      border-radius: 22px; background: #2c2c2e;
      transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .fid-corner {
      position: absolute; width: 20px; height: 20px;
      border: 3.5px solid #007aff;
      transition: border-color 0.3s, transform 0.3s;
    }
    .fid-corner.tl { top:0;left:0; border-right:none; border-bottom:none; border-top-left-radius:16px; }
    .fid-corner.tr { top:0;right:0; border-left:none; border-bottom:none; border-top-right-radius:16px; }
    .fid-corner.bl { bottom:0;left:0; border-right:none; border-top:none; border-bottom-left-radius:16px; }
    .fid-corner.br { bottom:0;right:0; border-left:none; border-top:none; border-bottom-right-radius:16px; }
    .face-id-box.scanning { background:#1c2834; box-shadow:0 0 24px rgba(0,122,255,0.4); }
    .face-id-box.scanning .fid-corner { animation: fCornerPulse 1.2s ease-in-out infinite alternate; }
    @keyframes fCornerPulse { 0%{transform:scale(1);opacity:.85} 100%{transform:scale(1.08);opacity:1} }
    .fid-scan-line {
      position: absolute; width: 84%; height: 2.5px;
      background: linear-gradient(90deg, rgba(0,122,255,0) 0%, #007aff 50%, rgba(0,122,255,0) 100%);
      box-shadow: 0 0 10px #007aff; animation: fScan 1s ease-in-out infinite alternate;
    }
    @keyframes fScan { 0%{transform:translateY(-26px);opacity:.4} 100%{transform:translateY(26px);opacity:1} }
    .face-id-box.success { background:#182f22; box-shadow:0 0 26px rgba(48,209,88,.45); }
    .face-id-box.success .fid-corner { border-color:#30d158; }
    @keyframes popCheck { 0%{transform:scale(.3);opacity:0} 65%{transform:scale(1.18)} 100%{transform:scale(1);opacity:1} }
    .check-pop { animation: popCheck 0.35s cubic-bezier(0.175,0.885,0.32,1.275) forwards; }
    @keyframes pulseScan { 0%,100%{opacity:1} 50%{opacity:.55} }
    .pulse-scan { animation: pulseScan 0.8s ease-in-out infinite; }
  </style>
</head>
<body>

<!-- HIDDEN FORM INPUTS -->
<form id="cartCheckoutForm" novalidate onsubmit="return false;" style="display:none;">
  <input type="text" name="action"             id="order_action"        value="create_order">
  <input type="text" name="total_amount"       id="total_amount"        value="<?= htmlspecialchars((string)$grand_total) ?>">
  <input type="text" name="total"              id="total"               value="<?= htmlspecialchars((string)$grand_total) ?>">
  <input type="text" name="subtotal"           id="subtotal"            value="<?= htmlspecialchars((string)$subtotal) ?>">
  <input type="text" name="delivery_fee"       id="delivery_fee"        value="<?= htmlspecialchars((string)$delivery_fee) ?>">
  <input type="text" name="delivery_lat_lng"   id="delivery_lat_lng"    value="<?= htmlspecialchars("{$default_lat}, {$default_lng}") ?>">
  <input type="text" name="delivery_lat"       id="delivery_lat"        value="<?= htmlspecialchars((string)$default_lat) ?>">
  <input type="text" name="delivery_lng"       id="delivery_lng"        value="<?= htmlspecialchars((string)$default_lng) ?>">
  <input type="text" name="customer_lat"       id="customer_lat"        value="<?= htmlspecialchars((string)$default_lat) ?>">
  <input type="text" name="customer_lng"       id="customer_lng"        value="<?= htmlspecialchars((string)$default_lng) ?>">
  <input type="text" name="cart"               id="cart_json_input"     value="<?= htmlspecialchars(json_encode(!empty($cart_items) ? $cart_items : [['name'=>'Signature Meal Combo','store'=>'Food Dash Grill','price'=>170.00,'qty'=>1,'icon'=>'&#127857;']])) ?>">
  <input type="text" name="payment_method_val" id="selectedPaymentMethod" value="gcash">
  <input type="text" name="payment_method"     id="formPaymentMethod"   value="gcash">
</form>

<!-- MAIN CART WRAPPER -->
<div class="cart-wrapper">

  <!-- STICKY HEADER -->
  <header class="cart-header">
    <a href="customer_dashboard.php#cart" class="back-btn" id="cartBackBtn"><i class="fa-solid fa-chevron-left"></i></a>
    <h1><i class="fa-solid fa-bag-shopping" style="color:var(--harvest-sun);"></i> My Cart</h1>
    <span class="item-badge" id="cartHeaderCount"><?= !empty($cart_items) ? count($cart_items) . ' Item(s)' : '1 Item' ?></span>
  </header>

  <div class="cart-content">

    <!-- SECTION 1: CART ITEMS -->
    <div class="cart-card">
      <div class="card-label"><i class="fa-solid fa-utensils"></i> Order Items</div>
      <div id="cartItemsList">
        <?php if (!empty($cart_items)): ?>
          <?php foreach ($cart_items as $idx => $ci): ?>
            <?php
              $itemName  = htmlspecialchars($ci['name'] ?? 'Meal');
              $itemStore = htmlspecialchars($ci['store'] ?? 'Food Dash');
              $itemPrice = (float)($ci['price'] ?? 0);
              $itemQty   = (int)($ci['qty'] ?? $ci['quantity'] ?? 1);
              $itemIcon  = htmlspecialchars($ci['icon'] ?? '&#127857;');
              $lineTotal = $itemPrice * max(1, $itemQty);
            ?>
            <div class="cart-item-row" id="item-row-<?= $idx ?>">
              <div class="item-emoji"><?= $itemIcon ?></div>
              <div class="item-info">
                <strong><?= $itemName ?></strong>
                <small><?= $itemStore ?></small>
              </div>
              <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;">
                <span class="item-price" id="item-price-<?= $idx ?>">&#8369;<?= number_format($lineTotal, 2) ?></span>
                <div class="qty-controls">
                  <button type="button" class="qty-btn remove-btn" onclick="decreaseQty(<?= $idx ?>, <?= $itemPrice ?>)" id="qty-dec-<?= $idx ?>"><i class="fa-solid fa-minus" style="font-size:10px;"></i></button>
                  <span class="qty-num" id="qty-num-<?= $idx ?>"><?= $itemQty ?></span>
                  <button type="button" class="qty-btn" onclick="increaseQty(<?= $idx ?>, <?= $itemPrice ?>)" id="qty-inc-<?= $idx ?>"><i class="fa-solid fa-plus" style="font-size:10px;"></i></button>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="cart-item-row" id="item-row-0">
            <div class="item-emoji">&#127857;</div>
            <div class="item-info">
              <strong>Signature Meal Combo</strong>
              <small>Food Dash Grill</small>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;">
              <span class="item-price" id="item-price-0">&#8369;170.00</span>
              <div class="qty-controls">
                <button type="button" class="qty-btn remove-btn" onclick="decreaseQty(0,170)" id="qty-dec-0"><i class="fa-solid fa-minus" style="font-size:10px;"></i></button>
                <span class="qty-num" id="qty-num-0">1</span>
                <button type="button" class="qty-btn" onclick="increaseQty(0,170)" id="qty-inc-0"><i class="fa-solid fa-plus" style="font-size:10px;"></i></button>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- SECTION 2: DELIVERY ADDRESS -->
    <div class="cart-card">
      <div class="card-label"><i class="fa-solid fa-location-dot"></i> Delivery Address</div>
      <textarea id="delivery_address" name="delivery_address" class="address-textarea" placeholder="Unit/House No., Street, Barangay, City, Landmark"><?= htmlspecialchars($default_address) ?></textarea>
      <div class="coords-badge">
        <i class="fa-solid fa-circle-check" style="color:var(--olive);"></i>
        <span>Live GPS Pin: <b id="coordsDisplay"><?= htmlspecialchars("{$default_lat}, {$default_lng}") ?></b></span>
      </div>
    </div>

    <!-- SECTION 3: PAYMENT METHOD (single block, no duplicates) -->
    <div class="cart-card">
      <div class="card-label"><i class="fa-solid fa-credit-card"></i> Payment Method</div>
      <div class="pay-list">

        <div class="pay-card" id="pc-cod" onclick="selectMethod('cod')">
          <div class="pay-left">
            <div class="pay-logo"><img src="assets/logos/cod.svg" alt="COD"></div>
            <div class="pay-info"><strong>Cash on Delivery (COD)</strong><small>Pay with cash upon arrival</small></div>
          </div>
          <i class="fa-regular fa-circle pay-radio" id="pr-cod"></i>
        </div>

        <div class="pay-card active" id="pc-gcash" onclick="selectMethod('gcash')">
          <div class="pay-left">
            <div class="pay-logo"><img src="assets/logos/gcash.svg" alt="GCash"></div>
            <div class="pay-info"><strong>GCash</strong><small>Philippines' Leading E-Wallet</small></div>
          </div>
          <i class="fa-solid fa-circle-check pay-radio" id="pr-gcash"></i>
        </div>

        <div class="pay-card" id="pc-maya" onclick="selectMethod('maya')">
          <div class="pay-left">
            <div class="pay-logo"><img src="assets/logos/maya.svg" alt="Maya"></div>
            <div class="pay-info"><strong>Maya (PayMaya)</strong><small>Maya Wallet &amp; Digital Card</small></div>
          </div>
          <i class="fa-regular fa-circle pay-radio" id="pr-maya"></i>
        </div>

        <div class="pay-card" id="pc-gotyme" onclick="selectMethod('gotyme')">
          <div class="pay-left">
            <div class="pay-logo"><img src="assets/logos/gotyme.svg" alt="GoTyme"></div>
            <div class="pay-info"><strong>GoTyme Bank</strong><small>Digital Banking &amp; Rewards</small></div>
          </div>
          <i class="fa-regular fa-circle pay-radio" id="pr-gotyme"></i>
        </div>

        <div class="pay-card" id="pc-apple" onclick="selectMethod('apple_pay')">
          <div class="pay-left">
            <div class="pay-logo" style="background:#000;"><img src="assets/logos/apple-pay.svg" alt="Apple Pay"></div>
            <div class="pay-info"><strong>Apple Pay</strong><small>Instant Face ID / Touch ID</small></div>
          </div>
          <i class="fa-regular fa-circle pay-radio" id="pr-apple"></i>
        </div>

      </div>
    </div>

    <!-- SECTION 4: ORDER BREAKDOWN -->
    <div class="cart-card">
      <div class="card-label"><i class="fa-solid fa-receipt"></i> Order Breakdown</div>
      <div class="total-row"><span>Meal Subtotal</span><span id="displaySubtotal">&#8369;<?= number_format($subtotal, 2) ?></span></div>
      <div class="total-row"><span>Delivery Fee</span><span id="displayFee">&#8369;<?= number_format($delivery_fee, 2) ?></span></div>
      <div class="total-row grand"><span>Total Amount</span><span id="displayTotal">&#8369;<?= number_format($grand_total, 2) ?></span></div>
    </div>

  </div>
</div>

<!-- SECTION 5: STICKY ACTION BUTTON -->
<div class="sticky-pay-bar">
  <button type="button" id="mainPayBtn">
    <span style="background:#fff;color:#007DFE;font-weight:bold;padding:2px 7px;border-radius:4px;font-size:12px;">G</span>
    Pay with GCash &#183; &#8369;<?= number_format($grand_total, 2) ?>
  </button>
</div>


<!-- MODAL 1: APPLE PAY -->
<div class="sim-modal" id="applePayModal" style="display:none;" onclick="if(event.target===this)closeModal('applePayModal')">
  <div class="sim-sheet" style="background:#1c1c1e;color:#fff;">
    <div class="sheet-grip" style="background:#3a3a3c;"></div>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:4px 22px 14px;border-bottom:1px solid #2c2c2e;">
      <div style="font-size:20px;font-weight:700;display:flex;align-items:center;gap:6px;">
        <span style="font-size:22px;"></span><span>Pay</span>
      </div>
      <button type="button" onclick="closeModal('applePayModal')" style="background:transparent;border:none;color:#0a84ff;font-size:15px;font-weight:600;cursor:pointer;">Cancel</button>
    </div>
    <div style="padding:18px 22px 28px;">
      <div style="background:#2c2c2e;border-radius:18px;padding:16px;margin-bottom:16px;">
        <div style="display:flex;justify-content:space-between;font-size:13px;color:#8e8e93;margin-bottom:6px;">
          <span>Merchant</span><b style="color:#fff;">Food Dash</b>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:13px;color:#8e8e93;margin-bottom:6px;">
          <span>Card</span><b style="color:#fff;">Apple Card (&#183;&#183;&#183;&#183; 8821)</b>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:13px;color:#8e8e93;margin-bottom:10px;">
          <span>Deliver To</span><b style="color:#fff;" id="appleModalDest"><?= htmlspecialchars($default_address) ?></b>
        </div>
        <div style="height:1px;background:#3a3a3c;margin:8px 0;"></div>
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <span style="font-size:15px;font-weight:700;">Total</span>
          <span style="font-size:22px;font-weight:800;color:#30d158;" id="appleModalTotal">&#8369;<?= number_format($grand_total, 2) ?></span>
        </div>
      </div>
      <div class="face-id-wrapper">
        <div class="face-id-box" id="appleFaceIdBox">
          <div class="fid-corner tl"></div><div class="fid-corner tr"></div>
          <div class="fid-corner bl"></div><div class="fid-corner br"></div>
          <svg id="appleFaceIdSvg" viewBox="0 0 48 48" width="46" height="46">
            <circle cx="16" cy="18" r="2.2" fill="#007aff"/>
            <circle cx="32" cy="18" r="2.2" fill="#007aff"/>
            <path d="M 24,18 L 24,26 L 20.5,26" fill="none" stroke="#007aff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M 15,31 C 18,36 30,36 33,31" fill="none" stroke="#007aff" stroke-width="2.4" stroke-linecap="round"/>
          </svg>
          <div id="appleFaceIdCheck" style="display:none;"><i class="fa-solid fa-circle-check check-pop" style="font-size:48px;color:#30d158;"></i></div>
          <div class="fid-scan-line" id="appleFaceIdScanLine" style="display:none;"></div>
        </div>
        <div id="faceIdStatus" style="font-size:14px;font-weight:700;color:#8e8e93;margin-top:12px;">Scanning Face...</div>
      </div>
      <button type="button" class="modal-action-btn" id="btnApplePayTrigger" onclick="startApplePayScan()" style="background:#333336;color:#8e8e93;margin-top:16px;" disabled>
        <span style="font-size:18px;"></span> Scanning Face...
      </button>
    </div>
  </div>
</div>


<!-- MODAL 2: WALLET (GCash/Maya/GoTyme) -->
<div class="sim-modal" id="walletModal" style="display:none;" onclick="if(event.target===this)closeModal('walletModal')">
  <div class="sim-sheet" style="background:#fff;">
    <div id="walletHeader" style="background:#007DFE;color:#fff;padding:16px 20px 18px;">
      <div class="sheet-grip"></div>
      <div style="display:flex;align-items:center;justify-content:space-between;margin-top:8px;">
        <div style="display:flex;align-items:center;gap:10px;">
          <img id="walletLogo" src="assets/logos/gcash.svg" alt="Wallet" style="height:26px;border-radius:6px;background:#fff;padding:2px;">
          <div>
            <div id="walletTitle" style="font-size:16px;font-weight:800;line-height:1.2;">GCash QuickPay</div>
            <small id="walletSub" style="font-size:11px;opacity:.85;">Official Digital Payment</small>
          </div>
        </div>
        <button type="button" onclick="closeModal('walletModal')" class="modal-cancel-btn">Cancel</button>
      </div>
    </div>
    <div style="padding:22px;background:#fff;">
      <div style="text-align:center;margin-bottom:16px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:4px;">Payment Authorization</div>
        <h2 style="font-size:22px;font-weight:800;color:#111827;">Total: <span id="walletTotal" style="color:#007DFE;">&#8369;<?= number_format($grand_total, 2) ?></span></h2>
      </div>
      <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:16px;padding:12px 16px;margin-bottom:14px;">
        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">
          <i class="fa-solid fa-location-dot" style="color:#ef4444;"></i> Delivery Destination:
        </div>
        <div style="font-size:14px;font-weight:700;color:#1e293b;" id="walletDest"><?= htmlspecialchars($default_address) ?></div>
      </div>
      <div style="background:#fdfbf7;border:1px solid #e8decb;border-radius:16px;padding:16px;margin-bottom:18px;text-align:center;">
        <label id="walletPinLabel" style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:8px;">
          <i class="fa-solid fa-lock" style="color:#007DFE;"></i> Enter 4-Digit MPIN / Security OTP:
        </label>
        <div style="display:flex;justify-content:center;margin-bottom:6px;">
          <input type="password" id="walletPinInput" maxlength="4" value="1234" autocomplete="off"
            style="width:170px;height:46px;text-align:center;font-size:24px;letter-spacing:10px;border-radius:12px;border:2px solid #007DFE;font-family:monospace;font-weight:800;outline:none;background:#fff;color:#1e293b;">
        </div>
        <small style="color:#6b7280;font-size:11px;"><i class="fa-solid fa-circle-check" style="color:#10b981;"></i> Security Verified &#183; Instant Processing</small>
      </div>
      <button type="button" class="modal-action-btn" id="walletSubmitBtn" onclick="finalizeOrder(activePaymentMethod)" style="background:#007DFE;color:#fff;">
        <i class="fa-solid fa-shield-halved"></i> Confirm &amp; Pay &#183; &#8369;<?= number_format($grand_total, 2) ?>
      </button>
    </div>
  </div>
</div>


<!-- MODAL 3: CASH ON DELIVERY -->
<div class="sim-modal" id="modal-cod" style="display:none;" onclick="if(event.target===this)closeModal('modal-cod')">
  <div class="sim-sheet" style="background:#fff;">
    <div style="background:#283d23;color:#fff;padding:16px 20px 18px;">
      <div class="sheet-grip"></div>
      <div style="display:flex;align-items:center;justify-content:space-between;margin-top:8px;">
        <div style="display:flex;align-items:center;gap:10px;">
          <img src="assets/logos/cod.svg" alt="COD" style="height:26px;border-radius:6px;background:#fff;padding:2px;">
          <div>
            <div style="font-size:16px;font-weight:800;line-height:1.2;">Cash on Delivery</div>
            <small style="font-size:11px;opacity:.85;">Pay upon arrival</small>
          </div>
        </div>
        <button type="button" onclick="closeModal('modal-cod')" class="modal-cancel-btn">Cancel</button>
      </div>
    </div>
    <div style="padding:22px;background:#fff;">
      <div style="text-align:center;margin-bottom:16px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:4px;">Order Summary</div>
        <h2 style="font-size:22px;font-weight:800;color:#111827;">Total: <span id="codTotal" style="color:#283d23;">&#8369;<?= number_format($grand_total, 2) ?></span></h2>
      </div>
      <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:16px;padding:12px 16px;margin-bottom:14px;">
        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">
          <i class="fa-solid fa-location-dot" style="color:#ef4444;"></i> Delivery Destination:
        </div>
        <div style="font-size:14px;font-weight:700;color:#1e293b;" id="codDest"><?= htmlspecialchars($default_address) ?></div>
      </div>
      <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:14px;padding:14px;margin-bottom:18px;font-size:12px;color:#166534;display:flex;align-items:center;gap:10px;">
        <i class="fa-solid fa-money-bill-wave" style="font-size:20px;color:#15803d;"></i>
        <span>Please prepare exact cash of <b id="codCashAmt">&#8369;<?= number_format($grand_total, 2) ?></b> upon arrival for swift handover.</span>
      </div>
      <button type="button" class="modal-action-btn" id="codSubmitBtn" onclick="finalizeOrder('cod')" style="background:#283d23;color:#fff;">
        <i class="fa-solid fa-check"></i> Confirm Order &#183; &#8369;<?= number_format($grand_total, 2) ?>
      </button>
    </div>
  </div>
</div>

<!-- Legacy alias divs -->
<div id="modal-gcash"     style="display:none;"></div>
<div id="modal-maya"      style="display:none;"></div>
<div id="modal-gotyme"    style="display:none;"></div>
<div id="modal-apple_pay" style="display:none;"></div>


<script>
var currentGrandTotal   = <?= json_encode($grand_total) ?>;
var currentSubtotal     = <?= json_encode($subtotal) ?>;
var currentDeliveryFee  = <?= json_encode($delivery_fee) ?>;
var grandTotalFormatted = '&#8369;' + Number(<?= json_encode($grand_total) ?>).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
var activePaymentMethod = 'gcash';
var itemQtys  = {};
var itemPrices = {};

function initQtyMap() {
    try {
        var rows = document.querySelectorAll('[id^="qty-num-"]');
        for (var i = 0; i < rows.length; i++) {
            var idx = parseInt(rows[i].id.replace('qty-num-', ''), 10);
            itemQtys[idx] = parseInt(rows[i].textContent, 10) || 1;
        }
    } catch(e) {}
}

function fmtMoney(n) {
    return '&#8369;' + Number(n).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
}

function updateTotalsDisplay() {
    grandTotalFormatted = fmtMoney(currentGrandTotal);
    var el;
    el = document.getElementById('displaySubtotal'); if(el) el.innerHTML = fmtMoney(currentSubtotal);
    el = document.getElementById('displayTotal');    if(el) el.innerHTML = grandTotalFormatted;
    el = document.getElementById('walletTotal');     if(el) el.innerHTML = grandTotalFormatted;
    el = document.getElementById('codTotal');        if(el) el.innerHTML = grandTotalFormatted;
    el = document.getElementById('codCashAmt');      if(el) el.innerHTML = grandTotalFormatted;
    el = document.getElementById('appleModalTotal'); if(el) el.innerHTML = grandTotalFormatted;
    var wb = document.getElementById('walletSubmitBtn');
    if(wb) wb.innerHTML = '<i class="fa-solid fa-shield-halved"></i> Confirm &amp; Pay &#183; ' + grandTotalFormatted;
    var cb = document.getElementById('codSubmitBtn');
    if(cb) cb.innerHTML = '<i class="fa-solid fa-check"></i> Confirm Order &#183; ' + grandTotalFormatted;
    el = document.getElementById('total_amount'); if(el) el.value = currentGrandTotal;
    el = document.getElementById('total');        if(el) el.value = currentGrandTotal;
    el = document.getElementById('subtotal');     if(el) el.value = currentSubtotal;
    selectMethod(activePaymentMethod);
}

function increaseQty(idx, pricePerUnit) {
    itemQtys[idx]   = (itemQtys[idx] || 1) + 1;
    itemPrices[idx] = pricePerUnit;
    var n = document.getElementById('qty-num-' + idx);
    if(n) n.textContent = itemQtys[idx];
    var p = document.getElementById('item-price-' + idx);
    if(p) p.innerHTML = fmtMoney(pricePerUnit * itemQtys[idx]);
    recalcTotals();
}

function decreaseQty(idx, pricePerUnit) {
    var cur = itemQtys[idx] || 1;
    if (cur <= 1) {
        var row = document.getElementById('item-row-' + idx);
        if(row) row.remove();
        delete itemQtys[idx];
        itemPrices[idx] = 0;
    } else {
        itemQtys[idx]   = cur - 1;
        itemPrices[idx] = pricePerUnit;
        var n = document.getElementById('qty-num-' + idx);
        if(n) n.textContent = itemQtys[idx];
        var p = document.getElementById('item-price-' + idx);
        if(p) p.innerHTML = fmtMoney(pricePerUnit * itemQtys[idx]);
    }
    recalcTotals();
}

function recalcTotals() {
    var sub = 0;
    var els = document.querySelectorAll('[id^="item-price-"]');
    for (var i = 0; i < els.length; i++) {
        var v = parseFloat(els[i].textContent.replace(/[^\d.]/g,'')) || 0;
        sub += v;
    }
    currentSubtotal   = sub;
    currentGrandTotal = sub + currentDeliveryFee;
    var count = document.querySelectorAll('[id^="item-row-"]').length;
    var badge = document.getElementById('cartHeaderCount');
    if(badge) badge.textContent = count + ' Item(s)';
    updateTotalsDisplay();
}

function syncCartFromLocalStorage() {
    try {
        var rawCart = localStorage.getItem('foodDashCart');
        if (rawCart) {
            var parsed = JSON.parse(rawCart);
            if (Array.isArray(parsed) && parsed.length > 0) {
                var sub = 0;
                var html = '';
                for (var i = 0; i < parsed.length; i++) {
                    var item  = parsed[i];
                    var price = Number(item.price) || 0;
                    var qty   = Number(item.qty || item.quantity) || 1;
                    sub += price * qty;
                    itemQtys[i]   = qty;
                    itemPrices[i] = price;
                    var icon  = item.icon || '&#127857;';
                    var name  = (item.name || item.title || 'Meal').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                    var store = (item.store || 'Food Dash Grill').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                    html += '<div class="cart-item-row" id="item-row-' + i + '">' +
                        '<div class="item-emoji">' + icon + '</div>' +
                        '<div class="item-info"><strong>' + name + '</strong><small>' + store + '</small></div>' +
                        '<div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;">' +
                        '<span class="item-price" id="item-price-' + i + '">' + fmtMoney(price*qty) + '</span>' +
                        '<div class="qty-controls">' +
                        '<button type="button" class="qty-btn remove-btn" onclick="decreaseQty(' + i + ',' + price + ')" id="qty-dec-' + i + '"><i class="fa-solid fa-minus" style="font-size:10px;"></i></button>' +
                        '<span class="qty-num" id="qty-num-' + i + '">' + qty + '</span>' +
                        '<button type="button" class="qty-btn" onclick="increaseQty(' + i + ',' + price + ')" id="qty-inc-' + i + '"><i class="fa-solid fa-plus" style="font-size:10px;"></i></button>' +
                        '</div></div></div>';
                }
                var listEl = document.getElementById('cartItemsList');
                if(listEl && html) listEl.innerHTML = html;
                var badge = document.getElementById('cartHeaderCount');
                if(badge) badge.textContent = parsed.length + ' Item(s)';
                currentSubtotal   = sub;
                currentGrandTotal = sub + currentDeliveryFee;
                updateTotalsDisplay();
                var cartIn = document.getElementById('cart_json_input');
                if(cartIn) cartIn.value = JSON.stringify(parsed);
            }
        }
        var savedAddr = localStorage.getItem('foodDashDeliveryAddress');
        if (savedAddr) {
            var addrEl = document.getElementById('delivery_address');
            if(addrEl && (!addrEl.value || addrEl.value === 'Western Leyte College')) addrEl.value = savedAddr;
        }
    } catch(e) { console.error('Cart sync:', e); }
}

var payMethods = ['cod','gcash','maya','gotyme','apple_pay'];
var pcIds = {cod:'pc-cod', gcash:'pc-gcash', maya:'pc-maya', gotyme:'pc-gotyme', apple_pay:'pc-apple'};
var prIds = {cod:'pr-cod', gcash:'pr-gcash', maya:'pr-maya', gotyme:'pr-gotyme', apple_pay:'pr-apple'};

function selectMethod(method) {
    activePaymentMethod = method;
    for (var i = 0; i < payMethods.length; i++) {
        var m = payMethods[i];
        var card = document.getElementById(pcIds[m]);
        var icon = document.getElementById(prIds[m]);
        var isSel = (m === method);
        if(card) { if(isSel) card.classList.add('active'); else card.classList.remove('active'); }
        if(icon) { icon.className = isSel ? 'fa-solid fa-circle-check pay-radio' : 'fa-regular fa-circle pay-radio'; }
    }
    var hm = document.getElementById('selectedPaymentMethod'); if(hm) hm.value = method;
    var fm = document.getElementById('formPaymentMethod');     if(fm) fm.value = method;

    var btn = document.getElementById('mainPayBtn');
    if (!btn) return;
    btn.style.width = '100%'; btn.style.border = 'none'; btn.style.borderRadius = '14px';
    btn.style.fontWeight = '700'; btn.style.fontSize = '16px'; btn.style.cursor = 'pointer';

    if (method === 'cod') {
        btn.style.setProperty('background-color','#283d23','important');
        btn.style.setProperty('color','#ffffff','important');
        btn.innerHTML = '<i class="fa-solid fa-money-bill-wave"></i> Place Order &#183; ' + grandTotalFormatted;
    } else if (method === 'gcash') {
        btn.style.setProperty('background-color','#007DFE','important');
        btn.style.setProperty('color','#ffffff','important');
        btn.innerHTML = '<span style="background:#fff;color:#007DFE;font-weight:bold;padding:2px 7px;border-radius:4px;font-size:12px;">G</span> Pay with GCash &#183; ' + grandTotalFormatted;
    } else if (method === 'maya') {
        btn.style.setProperty('background-color','#023a2b','important');
        btn.style.setProperty('color','#05B851','important');
        btn.innerHTML = '<span style="background:#05B851;color:#023a2b;font-weight:bold;padding:2px 7px;border-radius:4px;font-size:12px;">M</span> Pay with Maya &#183; ' + grandTotalFormatted;
    } else if (method === 'gotyme') {
        btn.style.setProperty('background-color','#0a2540','important');
        btn.style.setProperty('color','#00d2ff','important');
        btn.innerHTML = '<span style="background:#00d2ff;color:#0a2540;font-weight:bold;padding:2px 7px;border-radius:4px;font-size:12px;">GT</span> Pay with GoTyme &#183; ' + grandTotalFormatted;
    } else if (method === 'apple_pay') {
        btn.style.setProperty('background-color','#000000','important');
        btn.style.setProperty('color','#ffffff','important');
        btn.innerHTML = '<span style="font-size:18px;margin-right:4px;"></span> Pay &#183; ' + grandTotalFormatted;
    }
    updateWalletModalTheme(method);
}

function updateWalletModalTheme(method) {
    var header = document.getElementById('walletHeader');
    var title  = document.getElementById('walletTitle');
    var sub    = document.getElementById('walletSub');
    var logo   = document.getElementById('walletLogo');
    var label  = document.getElementById('walletPinLabel');
    var pinIn  = document.getElementById('walletPinInput');
    var btn    = document.getElementById('walletSubmitBtn');
    var tot    = document.getElementById('walletTotal');
    if(tot) tot.innerHTML = grandTotalFormatted;
    if(btn) btn.innerHTML = '<i class="fa-solid fa-shield-halved"></i> Confirm &amp; Pay &#183; ' + grandTotalFormatted;
    if (method === 'gcash') {
        if(header) header.style.background='#007DFE';
        if(title)  title.textContent='GCash QuickPay';
        if(sub)    sub.textContent='Official Digital Payment';
        if(logo)   logo.src='assets/logos/gcash.svg';
        if(label)  label.innerHTML='<i class="fa-solid fa-lock" style="color:#007DFE;"></i> Enter 4-Digit MPIN / Security OTP:';
        if(pinIn)  { pinIn.style.borderColor='#007DFE'; pinIn.maxLength=4; pinIn.value='1234'; }
        if(btn)    { btn.style.setProperty('background-color','#007DFE','important'); btn.style.setProperty('color','#fff','important'); }
    } else if (method === 'maya') {
        if(header) header.style.background='#023a2b';
        if(title)  title.textContent='Maya Checkout';
        if(sub)    sub.textContent='Maya Wallet & Digital Card';
        if(logo)   logo.src='assets/logos/maya.svg';
        if(label)  label.innerHTML='<i class="fa-solid fa-lock" style="color:#05B851;"></i> Enter Maya OTP / Security Code:';
        if(pinIn)  { pinIn.style.borderColor='#05B851'; pinIn.maxLength=6; pinIn.value='654321'; }
        if(btn)    { btn.style.setProperty('background-color','#023a2b','important'); btn.style.setProperty('color','#05B851','important'); }
    } else if (method === 'gotyme') {
        if(header) header.style.background='#0a2540';
        if(title)  title.textContent='GoTyme Bank Transfer';
        if(sub)    sub.textContent='Everyday Account (&#183;&#183;&#183;&#183; 4920)';
        if(logo)   logo.src='assets/logos/gotyme.svg';
        if(label)  label.innerHTML='<i class="fa-solid fa-building-columns" style="color:#00d2ff;"></i> Enter GoTyme Authorization PIN:';
        if(pinIn)  { pinIn.style.borderColor='#00d2ff'; pinIn.maxLength=6; pinIn.value='882190'; }
        if(btn)    { btn.style.setProperty('background-color','#0a2540','important'); btn.style.setProperty('color','#00d2ff','important'); }
    }
}

function openPaymentPopup(event) {
    if(event && event.preventDefault)  event.preventDefault();
    if(event && event.stopPropagation) event.stopPropagation();

    var address = document.getElementById('delivery_address') ? document.getElementById('delivery_address').value : 'Western Leyte College';
    var latLng  = document.getElementById('delivery_lat_lng')  ? document.getElementById('delivery_lat_lng').value  : '11.01039, 124.61788';

    var amd = document.getElementById('appleModalDest'); if(amd) amd.textContent = address;
    var wd  = document.getElementById('walletDest');     if(wd)  wd.textContent  = address;
    var cd  = document.getElementById('codDest');        if(cd)  cd.textContent  = address;

    if (activePaymentMethod === 'apple_pay') {
        var m = document.getElementById('applePayModal');
        if(m) { m.style.display = 'flex'; startApplePayScan(); }
    } else if (activePaymentMethod === 'gcash' || activePaymentMethod === 'maya' || activePaymentMethod === 'gotyme') {
        updateWalletModalTheme(activePaymentMethod);
        var m2 = document.getElementById('walletModal');
        if(m2) m2.style.display = 'flex';
    } else if (activePaymentMethod === 'cod') {
        var m3 = document.getElementById('modal-cod');
        if(m3) m3.style.display = 'flex';
    } else {
        finalizeOrder(activePaymentMethod, address, latLng);
    }
}

function finalizeOrder(method, address, coords) {
    var chosenMethod  = method  || activePaymentMethod || 'cod';
    var chosenAddress = address || (document.getElementById('delivery_address') ? document.getElementById('delivery_address').value : 'Western Leyte College');
    var chosenCoords  = coords  || (document.getElementById('delivery_lat_lng')  ? document.getElementById('delivery_lat_lng').value  : '11.01039, 124.61788');

    var fd = new FormData();
    fd.append('action',           'create_order');
    fd.append('payment_method',   chosenMethod);
    fd.append('address',          chosenAddress);
    fd.append('delivery_address', chosenAddress);
    fd.append('lat_lng',          chosenCoords);
    fd.append('delivery_lat_lng', chosenCoords);
    fd.append('total_amount',     currentGrandTotal);
    fd.append('total',            currentGrandTotal);

    if (chosenCoords) {
        var parts = chosenCoords.split(',');
        if (parts.length === 2) {
            fd.append('delivery_lat', parts[0].trim());
            fd.append('delivery_lng', parts[1].trim());
            fd.append('customer_lat', parts[0].trim());
            fd.append('customer_lng', parts[1].trim());
        }
    }

    var cartIn = document.getElementById('cart_json_input');
    if(cartIn && cartIn.value) {
        fd.append('cart', cartIn.value);
    } else {
        try { var sc = localStorage.getItem('foodDashCart'); if(sc) fd.append('cart', sc); } catch(e){}
    }

    var btnIds = ['mainPayBtn','walletSubmitBtn','codSubmitBtn','btnApplePayTrigger'];
    for (var b = 0; b < btnIds.length; b++) {
        var el = document.getElementById(btnIds[b]);
        if(el) el.disabled = true;
    }

    fetch('api_orders.php', { method:'POST', credentials:'include', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(){
            try { localStorage.removeItem('foodDashCart'); } catch(e){}
            window.location.href = 'customer_dashboard.php?order_placed=1';
        })
        .catch(function(){
            try { localStorage.removeItem('foodDashCart'); } catch(e){}
            window.location.href = 'customer_dashboard.php?order_placed=1';
        });
}

function startApplePayScan() {
    var scanLine = document.getElementById('appleFaceIdScanLine');
    var svg      = document.getElementById('appleFaceIdSvg');
    var check    = document.getElementById('appleFaceIdCheck');
    var box      = document.getElementById('appleFaceIdBox');
    var status   = document.getElementById('faceIdStatus');
    var btn      = document.getElementById('btnApplePayTrigger');

    if(btn)      { btn.disabled=true; btn.style.background='#333336'; btn.style.color='#8e8e93'; btn.innerHTML='<span style="font-size:18px;"></span> Scanning Face...'; }
    if(scanLine) scanLine.style.display='block';
    if(svg)      svg.style.display='block';
    if(check)    check.style.display='none';
    if(box)      { box.classList.remove('success'); box.classList.add('scanning'); }
    if(status)   status.innerHTML='<span class="pulse-scan" style="color:#007aff;font-size:14px;font-weight:700;"><i class="fa-solid fa-spinner fa-spin" style="margin-right:6px;"></i>Scanning Face...</span>';

    setTimeout(function() {
        if(scanLine) scanLine.style.display='none';
        if(svg)      svg.style.display='none';
        if(check)    check.style.display='block';
        if(box)      { box.classList.remove('scanning'); box.classList.add('success'); }
        if(status)   status.innerHTML='<span style="color:#30d158;font-size:16px;font-weight:800;display:inline-flex;align-items:center;gap:6px;"><i class="fa-solid fa-check"></i> Done</span>';
        if(btn)      { btn.style.background='#30d158'; btn.style.color='#fff'; btn.innerHTML='<i class="fa-solid fa-check"></i> Done'; }
        try { if(navigator.vibrate) navigator.vibrate([35,45,35]); } catch(e){}
        setTimeout(function() {
            var address = document.getElementById('delivery_address') ? document.getElementById('delivery_address').value : 'Western Leyte College';
            var latLng  = document.getElementById('delivery_lat_lng')  ? document.getElementById('delivery_lat_lng').value  : '11.01039, 124.61788';
            finalizeOrder('apple_pay', address, latLng);
        }, 700);
    }, 1200);
}

function closeModal(id) {
    var el = document.getElementById(id);
    if(el) el.style.display = 'none';
}

window.selectMethod          = selectMethod;
window.openPaymentPopup      = openPaymentPopup;
window.handleCheckoutClick   = openPaymentPopup;
window.triggerCheckout       = openPaymentPopup;
window.finalizeOrder         = finalizeOrder;
window.dispatchOrderPlacement = finalizeOrder;
window.closeModal            = closeModal;
window.increaseQty           = increaseQty;
window.decreaseQty           = decreaseQty;
window.updatePaymentTheme    = selectMethod;
window.setPayment            = selectMethod;

document.addEventListener('DOMContentLoaded', function() {
    initQtyMap();
    syncCartFromLocalStorage();
    selectMethod('gcash');

    // ── HARD-ATTACH mainPayBtn click with useCapture so no external script can intercept ──
    var payBtn = document.getElementById('mainPayBtn');
    if (payBtn) {
        // Remove any stale onclick attribute that could conflict
        payBtn.removeAttribute('onclick');
        payBtn.addEventListener('click', function(e) {
            if (e && e.preventDefault)  e.preventDefault();
            if (e && e.stopPropagation) e.stopPropagation();
            if (e && e.stopImmediatePropagation) e.stopImmediatePropagation();
            openPaymentPopup(e);
            return false;
        }, true); // useCapture = true: fires before any bubble-phase listeners
    }

    // ── Re-pin all globals AFTER DOMContentLoaded so cart.php always wins over checkout.js ──
    window.activePaymentMethod   = activePaymentMethod;
    window.currentGrandTotal     = currentGrandTotal;
    window.currentSubtotal       = currentSubtotal;
    window.currentDeliveryFee    = currentDeliveryFee;
    window.grandTotalFormatted   = grandTotalFormatted;
    window.selectMethod          = selectMethod;
    window.openPaymentPopup      = openPaymentPopup;
    window.handleCheckoutClick   = openPaymentPopup;
    window.triggerCheckout       = openPaymentPopup;
    window.triggerCheckoutFlow   = openPaymentPopup;
    window.onCheckoutButtonClick = openPaymentPopup;
    window.handlePayAction       = openPaymentPopup;
    window.finalizeOrder         = finalizeOrder;
    window.dispatchOrderPlacement = finalizeOrder;
    window.confirmAndPlaceOrder  = finalizeOrder;
    window.submitDynamicOrder    = finalizeOrder;
    window.closeModal            = closeModal;
    window.increaseQty           = increaseQty;
    window.decreaseQty           = decreaseQty;
    window.updatePaymentTheme    = selectMethod;
    window.setPayment            = selectMethod;
});
</script>
</body>
</html>
