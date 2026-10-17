<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/payment_helper.php';
require_once __DIR__ . '/notification_lib.php';

// Auth Guard: Ensure customer is logged in
if (!isset($_SESSION['user_id'])) {
    if (isset($_GET['action']) || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in.'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$customer_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Customer';

// If this is an API call (POST or action query), delegate to order_api.php
$isApi = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') 
    || (isset($_GET['action']) && $_GET['action'] !== 'view')
    || (isset($_GET['format']) && $_GET['format'] === 'json')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);

// 1. STRICT ACCOUNT-SPECIFIC ORDER SCOPING:
// Query strictly scopes to logged-in customer (or demo accounts):
$is_demo = ($user_id === 15 || $user_id === 26 || strtolower($customer_name) === 'customer');
$scope_orders = $is_demo ? "(customer_id = ? OR customer_id IN (15, 26, 0))" : "(customer_id = ?)";
$stmt = $conn->prepare("SELECT * FROM orders WHERE {$scope_orders} ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$raw_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Also fetch corresponding details from food_orders table for complete item/store details
$scope_food = $is_demo 
    ? "(customer_id = ? OR user_id = ? OR customer_id IN (15, 26, 0) OR user_id IN (15, 26, 0))" 
    : "(customer_id = ? OR user_id = ?)";
$f_stmt = $conn->prepare("SELECT * FROM food_orders WHERE {$scope_food} ORDER BY order_id DESC");
$f_stmt->execute([$user_id, $user_id]);
$food_orders_raw = $f_stmt->fetchAll(PDO::FETCH_ASSOC);
$fo_map = [];
foreach ($food_orders_raw as $fo) {
    $fo['order_ref'] = '#' . ltrim($fo['order_ref'] ?: ('FD-' . $fo['order_id']), '#');
    $fo_map[(int)$fo['order_id']] = $fo;
}

$orders = [];
if (!empty($raw_orders)) {
    foreach ($raw_orders as $ro) {
        $oid = (int)($ro['order_id'] ?? $ro['id'] ?? 0);
        $merged = isset($fo_map[$oid]) ? array_merge($fo_map[$oid], $ro) : $ro;
        $merged['order_ref'] = '#' . ltrim($merged['order_ref'] ?? ('FD-' . $oid), '#');
        $merged['payment_meta'] = get_payment_method_meta($merged['payment_method'] ?? 'Cash on Delivery (COD)');
        $orders[] = $merged;
    }
} elseif (!empty($food_orders_raw)) {
    foreach ($food_orders_raw as $fo) {
        $fo['order_ref'] = '#' . ltrim($fo['order_ref'] ?: ('FD-' . $fo['order_id']), '#');
        $fo['payment_meta'] = get_payment_method_meta($fo['payment_method'] ?? 'Cash on Delivery (COD)');
        $orders[] = $fo;
    }
}

if ($isApi) {
    header('Content-Type: application/json; charset=utf-8');
    $isEmpty = empty($orders);
    echo json_encode([
        'success'     => true,
        'count'       => count($orders),
        'message'     => $isEmpty ? 'No orders placed yet!' : 'Orders retrieved successfully.',
        'empty_state' => $isEmpty ? 'No orders placed yet! [Browse Stores]' : null,
        'orders'      => $orders
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
  <title>My Orders - Food Dash</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script>
  // Strictly enforce in-app notifications only; disable browser/OS level notification prompts
  (function() {
    if (typeof window !== 'undefined') {
      if ('Notification' in window) {
        try {
          window.Notification.requestPermission = function() {
            return Promise.resolve('denied');
          };
          Object.defineProperty(window.Notification, 'permission', {
            get: function() { return 'denied'; },
            configurable: true
          });
        } catch (e) {}
      }
    }
  })();
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Playfair+Display:ital,wght@0,600;0,700;1,600&display=swap" rel="stylesheet">
  <style>
    :root {
      --harvest-sun: #c5a059;
      --harvest-sun-hover: #b08d46;
      --olive-dark: #3b4124;
      --olive-shadow: #2f341d;
      --sagebrush: #52634f;
      --card-bg: #ffffff;
      --border-color: #e2e8f0;
      --text-main: #1e293b;
      --text-muted: #64748b;
    }
    *, *:before, *:after {
      box-sizing: border-box !important;
      -webkit-tap-highlight-color: transparent;
    }
    html, body {
      max-width: 100% !important;
      overflow-x: hidden !important;
      width: 100% !important;
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      min-height: 100%;
      -webkit-text-size-adjust: 100%;
    }
    body {
      font-family: 'Plus Jakarta Sans', -apple-system, sans-serif;
      background: #fdfbf7;
      color: var(--text-main);
      padding-bottom: 60px;
    }
    .header-bar {
      position: sticky;
      top: 0;
      z-index: 100;
      background: #ffffff;
      border-bottom: 1px solid #e5e7eb;
      padding: 10px 14px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      box-shadow: 0 1px 6px rgba(0,0,0,0.03);
      width: 100% !important;
      max-width: 100% !important;
      box-sizing: border-box !important;
    }
    .brand-wrap {
      display: flex;
      align-items: center;
      gap: 10px;
      text-decoration: none;
      color: inherit;
    }
    .brand-logo {
      width: 32px;
      height: 32px;
      border-radius: 8px;
      object-fit: cover;
    }
    .brand-name {
      font-family: 'Playfair Display', serif;
      font-size: 1.15rem;
      font-weight: 700;
      color: var(--olive-dark);
    }
    .nav-actions {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .btn-nav {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 12px;
      border-radius: 8px;
      font-size: 0.78rem;
      font-weight: 600;
      text-decoration: none;
      color: var(--olive-dark);
      background: #f4f0df;
      border: 1px solid rgba(197, 160, 89, 0.3);
      transition: all 0.2s;
    }
    .btn-nav:hover {
      background: #ebd8aa;
    }
    .container {
      width: 100% !important;
      max-width: 100% !important;
      padding: 0 12px !important;
      margin: 14px auto 0 !important;
      overflow-x: hidden !important;
      box-sizing: border-box !important;
    }
    @media (min-width: 600px) {
      .container {
        max-width: 580px !important;
      }
    }
    .page-title {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 14px;
    }
    .page-title h2 {
      font-size: 1.25rem;
      font-weight: 800;
      color: var(--olive-dark);
    }
    /* Empty State Box */
    .empty-state-box {
      background: #ffffff;
      border: 1.5px dashed #cbd5e1;
      border-radius: 16px;
      padding: 36px 18px;
      text-align: center;
      box-shadow: 0 4px 14px rgba(0,0,0,0.03);
      margin-top: 10px;
      width: 100% !important;
      max-width: 100% !important;
      box-sizing: border-box !important;
    }
    .empty-state-icon {
      font-size: 48px;
      margin-bottom: 12px;
      line-height: 1;
    }
    .empty-state-title {
      font-size: 1.15rem;
      font-weight: 800;
      color: var(--olive-dark);
      margin-bottom: 8px;
    }
    .empty-state-subtitle {
      font-size: 0.85rem;
      color: var(--text-muted);
      max-width: 340px;
      margin: 0 auto 18px;
      line-height: 1.5;
    }
    .btn-browse-stores {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: var(--harvest-sun);
      color: #ffffff;
      font-weight: 700;
      font-size: 0.88rem;
      padding: 10px 22px;
      border-radius: 10px;
      text-decoration: none;
      border: none;
      cursor: pointer;
      box-shadow: 0 4px 14px rgba(197, 160, 89, 0.4);
      transition: all 0.2s;
    }
    .btn-browse-stores:hover {
      background: var(--harvest-sun-hover);
      transform: translateY(-1px);
    }
    /* Orders List & Cards - Sleek & Compact */
    .orders-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
      width: 100% !important;
      max-width: 100% !important;
      padding: 0 !important;
      margin: 0 auto !important;
      overflow-x: hidden !important;
      box-sizing: border-box !important;
    }
    .order-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 16px !important;
      padding: 12px 14px !important;
      box-shadow: 0 2px 8px rgba(0,0,0,0.03);
      transition: box-shadow 0.2s;
      width: 100% !important;
      max-width: 100% !important;
      box-sizing: border-box !important;
      overflow-x: hidden !important;
    }
    .order-card:hover {
      box-shadow: 0 6px 16px rgba(0,0,0,0.06);
    }
    .order-card-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 6px;
      gap: 8px;
    }
    .order-ref {
      font-weight: 700 !important;
      font-size: 1.05rem !important;
      font-size: 16px !important;
      color: var(--olive-dark);
      line-height: 1.25 !important;
    }
    .order-store {
      font-size: 12.5px !important;
      font-weight: 600;
      color: #64748b !important;
      margin-top: 2px;
      line-height: 1.35;
    }
    .order-meta {
      font-size: 12.5px !important;
      color: #64748b !important;
      margin-top: 3px;
      line-height: 1.35;
    }
    .order-amount {
      font-weight: 700 !important;
      font-size: 1.05rem !important;
      font-size: 16px !important;
      color: var(--olive-dark);
      white-space: nowrap;
    }
    .order-card-badges {
      display: flex;
      align-items: center;
      gap: 6px;
      flex-wrap: wrap;
      margin: 8px 0;
    }
    /* Status Badges - Compact & Clean */
    .status-badge {
      display: inline-flex !important;
      align-items: center !important;
      gap: 4px !important;
      padding: 6px 12px !important;
      border-radius: 8px !important;
      font-size: 12px !important;
      font-weight: 700 !important;
      text-transform: capitalize;
    }
    .status-pending { background: #FEF3C7; color: #D97706; border: 1px solid #F59E0B; }
    .status-preparing { background: #E0E7FF; color: #4338CA; border: 1px solid #6366F1; }
    .status-ready_for_pickup { background: #ECFDF5; color: #047857; border: 1px solid #10B981; }
    .status-on_delivery { background: #FAF6EE; color: var(--harvest-sun); border: 1px solid var(--harvest-sun); }
    .status-near_customer { background: #FFF8E1; color: #E65100; border: 1px solid #FFA000; animation: pulseNear 2s infinite; }
    .status-arrived { background: #E8F5E9; color: #2E7D32; border: 1px solid #4CAF50; animation: pulseArrived 1.5s infinite; }
    .status-delivered { background: #F0FDF4; color: #166534; border: 1px solid #22C55E; }
    .status-cancelled { background: #FEF2F2; color: #DC2626; border: 1px solid #EF4444; }
    @keyframes pulseNear {
      0%, 100% { box-shadow: 0 0 0 0 rgba(255, 160, 0, 0.4); }
      50% { box-shadow: 0 0 0 5px rgba(255, 160, 0, 0); }
    }
    @keyframes pulseArrived {
      0%, 100% { box-shadow: 0 0 0 0 rgba(76, 175, 80, 0.5); }
      50% { box-shadow: 0 0 0 6px rgba(76, 175, 80, 0); }
    }
    /* Card Action Buttons - Compact & Flex-wrap */
    .card-actions {
      display: flex !important;
      align-items: center !important;
      gap: 6px !important;
      flex-wrap: wrap !important;
      margin-top: 8px !important;
      padding-top: 8px !important;
      border-top: 1px solid #f1f5f9;
    }
    .btn-cancel-order, .cust-track-btn, .confirm-btn {
      padding: 6px 12px !important;
      font-size: 12px !important;
      border-radius: 8px !important;
      font-weight: 700 !important;
      display: inline-flex !important;
      align-items: center !important;
      gap: 5px !important;
      cursor: pointer;
      line-height: 1.2;
      transition: all 0.2s;
    }
    .btn-cancel-order {
      background: #fee2e2;
      color: #dc2626;
      border: 1px solid #fca5a5;
    }
    .btn-cancel-order:hover {
      background: #fecaca;
    }
    .cust-track-btn {
      background: var(--harvest-sun);
      color: #ffffff;
      border: none;
      text-decoration: none;
    }
    .cust-track-btn:hover {
      background: var(--harvest-sun-hover);
    }
    .confirm-btn {
      background: var(--olive-shadow);
      color: #ffffff;
      border: none;
    }
    .confirm-btn:hover {
      opacity: 0.9;
    }

    /* In-App Floating Toast Banner (Food Dash Palette) */
    .orders-toast {
      position: fixed;
      top: 72px;
      left: 50%;
      transform: translate(-50%, -20px);
      background: linear-gradient(135deg, #1e293b, #0f172a);
      color: #ffffff;
      padding: 11px 18px;
      border-radius: 20px;
      font-size: 0.8rem;
      font-weight: 600;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.32s cubic-bezier(0.16, 1, 0.3, 1), transform 0.32s cubic-bezier(0.16, 1, 0.3, 1);
      z-index: 99999;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.35), 0 0 0 1.5px rgba(216, 143, 42, 0.4);
      display: flex;
      align-items: center;
      gap: 10px;
      max-width: min(440px, calc(100vw - 32px));
    }
    .orders-toast.show {
      opacity: 1;
      transform: translate(-50%, 0);
      pointer-events: auto;
    }
    .orders-toast.err {
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.35), 0 0 0 1.5px rgba(239, 68, 68, 0.6);
    }

    /* In-App Modal Dialogs */
    .orders-modal {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.65);
      backdrop-filter: blur(4px);
      z-index: 99990;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 16px;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.25s ease;
    }
    .orders-modal.show {
      opacity: 1;
      pointer-events: auto;
    }
    .orders-modal-box {
      background: #ffffff;
      border-radius: 20px;
      padding: 24px 22px;
      max-width: 380px;
      width: 100%;
      box-shadow: 0 20px 40px rgba(0,0,0,0.25);
      border: 1px solid var(--border-color);
      animation: ordersModalSlide 0.28s cubic-bezier(0.16, 1, 0.3, 1);
    }
    @keyframes ordersModalSlide {
      from { transform: translateY(-16px) scale(0.96); opacity: 0; }
      to { transform: translateY(0) scale(1); opacity: 1; }
    }
    .orders-modal-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 12px;
      padding-bottom: 8px;
      border-bottom: 1px solid #f1f5f9;
    }
    .orders-modal-header h3 {
      margin: 0;
      font-size: 1rem;
      font-weight: 700;
      color: var(--olive-dark);
    }
    .orders-modal-close {
      background: transparent;
      border: none;
      font-size: 1.25rem;
      line-height: 1;
      color: #94a3b8;
      cursor: pointer;
    }
    .orders-modal-body p {
      font-size: 0.85rem;
      color: #475569;
      line-height: 1.45;
      margin-bottom: 14px;
    }
    .orders-modal-input {
      width: 100%;
      box-sizing: border-box;
      padding: 10px 12px;
      border: 1.5px solid var(--border-color);
      border-radius: 10px;
      font-size: 0.85rem;
      font-family: inherit;
      margin-bottom: 16px;
      outline: none;
    }
    .orders-modal-input:focus {
      border-color: var(--harvest-sun);
    }
    .orders-modal-actions {
      display: flex;
      gap: 10px;
      justify-content: flex-end;
    }
    .btn-modal-cancel {
      background: #f1f5f9;
      color: #475569;
      border: 1px solid #cbd5e1;
      padding: 8px 16px;
      border-radius: 10px;
      font-weight: 600;
      font-size: 0.82rem;
      cursor: pointer;
    }
    .btn-modal-confirm {
      background: var(--olive-dark);
      color: #ffffff;
      border: none;
      padding: 8px 18px;
      border-radius: 10px;
      font-weight: 700;
      font-size: 0.82rem;
      cursor: pointer;
    }
  </style>
</head>
<body>

  <header class="header-bar">
    <a href="customer_dashboard.php" class="brand-wrap">
      <img src="logo.jpg" alt="Logo" class="brand-logo" onerror="this.style.display='none'">
      <span class="brand-name">Food Dash</span>
    </a>
    <div class="nav-actions">
      <a href="customer_dashboard.php?screen=stores" class="btn-nav"><i class="fa-solid fa-store"></i> Browse Stores</a>
      <a href="customer_dashboard.php" class="btn-nav"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
      <a href="logout.php" class="btn-nav"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
    </div>
  </header>

  <main class="container">
    <div class="page-title">
      <h2>My Orders</h2>
      <span style="font-size: 0.8rem; color: var(--text-muted);">Customer: <strong><?= htmlspecialchars($customer_name) ?></strong></span>
    </div>

    <?php if (empty($orders)): ?>
      <!-- EMPTY STATE: No orders placed yet! [Browse Stores] -->
      <div class="empty-state-box" id="emptyOrdersState">
        <div class="empty-state-icon">🛍️</div>
        <h3 class="empty-state-title">No orders placed yet!</h3>
        <p class="empty-state-subtitle">Your order history is clean. Discover our partner stores and order delicious meals right to your door!</p>
        <a href="customer_dashboard.php?screen=stores" class="btn-browse-stores" id="btnBrowseStores">
          <i class="fa-solid fa-store"></i> Browse Stores
        </a>
      </div>
    <?php else: ?>
      <div class="orders-list" id="ordersList">
        <?php foreach ($orders as $ord): 
          $oid = (int)($ord['order_id'] ?? $ord['id'] ?? 0);
          $raw_status = strtoupper(trim(str_replace([' ', '-'], '_', (string)($ord['status'] ?? 'PENDING'))));
          $del_status = strtolower(trim((string)($ord['delivery_status'] ?? '')));
          $status_lower = strtolower(trim((string)($ord['status'] ?? '')));
          
          $is_arrived = ($raw_status === 'ARRIVED' || $del_status === 'arrived' || $status_lower === 'arrived');
          $is_near = ($raw_status === 'NEAR_CUSTOMER' || $del_status === 'near_customer' || $status_lower === 'near_customer');
          $is_ontheway = in_array($raw_status, ['OUT_FOR_DELIVERY', 'ON_THE_WAY', 'ON_DELIVERY'])
                      || in_array($del_status, ['on_the_way', 'on_delivery', 'picked_up'])
                      || in_array($status_lower, ['out_for_delivery', 'on_the_way', 'on_delivery'])
                      || $is_near || $is_arrived;
                      
          $is_delivered = ($raw_status === 'DELIVERED' || $raw_status === 'COMPLETED' || $del_status === 'delivered' || $status_lower === 'delivered' || $status_lower === 'completed');
          $is_prep = in_array($raw_status, ['PREPARING', 'ACCEPTED', 'COOKING', 'IN_KITCHEN', 'PREP'])
                  || in_array($status_lower, ['preparing', 'accepted', 'cooking', 'in_kitchen', 'prep'])
                  || in_array($del_status, ['preparing', 'cooking', 'in_kitchen', 'accepted']);
          $is_pending = in_array($raw_status, ['PENDING', 'SUBMITTED']) || in_array($status_lower, ['pending', 'submitted']);
          $is_cancelled = ($raw_status === 'CANCELLED' || $del_status === 'cancelled' || $status_lower === 'cancelled');
          
          $can_cancel = ($is_pending || $is_prep) && !$is_ontheway && !$is_delivered && !$is_cancelled;
          
          if ($is_arrived) {
              $status_label = 'Arrived';
              $norm_status = 'arrived';
          } elseif ($is_near) {
              $status_label = 'Near Customer';
              $norm_status = 'near_customer';
          } elseif ($is_ontheway) {
              $status_label = 'On Delivery';
              $norm_status = 'on_delivery';
          } elseif ($raw_status === 'READY_FOR_PICKUP' || $status_lower === 'ready_for_pickup' || $status_lower === 'ready') {
              $status_label = 'Ready for Pickup';
              $norm_status = 'ready_for_pickup';
          } elseif ($is_prep) {
              $status_label = 'Preparing';
              $norm_status = 'preparing';
          } elseif ($is_pending) {
              $status_label = 'Pending';
              $norm_status = 'pending';
          } else {
              $status_label = ucwords(strtolower(str_replace('_', ' ', $raw_status)));
              $norm_status = strtolower($raw_status);
          }
          
          $order_ref = '#' . ltrim($ord['order_ref'] ?? ('FD-' . $oid), '#');
          $store_name = $ord['store_name'] ?? 'Food Dash Store';
          $items_summary = $ord['items_summary'] ?? 'Delicious Food Items';
          $total_amount = (float)($ord['total_amount'] ?? $ord['total_price'] ?? 0);
          $created_date = !empty($ord['created_at']) ? date('M d, Y • g:i A', strtotime($ord['created_at'])) : 'Recent';
        ?>
          <div class="order-card" id="order-card-<?= $oid ?>">
            <div class="order-card-header">
              <div>
                <div class="order-ref"><?= htmlspecialchars($order_ref) ?></div>
                <div class="order-store"><?= htmlspecialchars($store_name) ?> • <?= htmlspecialchars($items_summary) ?></div>
                <div class="order-meta"><?= htmlspecialchars($created_date) ?></div>
              </div>
              <div class="order-amount">₱<?= number_format($total_amount, 2) ?></div>
            </div>

            <!-- Badges: Active Status Pill & Authentic Payment Logo Badge -->
            <div class="order-card-badges">
              <span class="status-badge status-<?= htmlspecialchars($norm_status) ?>">
                <?php if ($is_arrived): ?>
                  <i class="fa-solid fa-bell fa-shake"></i>
                <?php elseif ($is_near): ?>
                  <i class="fa-solid fa-person-biking fa-beat"></i>
                <?php endif; ?>
                <?= htmlspecialchars($status_label) ?>
              </span>
              <?= render_payment_badge($ord['payment_method'] ?? 'Cash on Delivery (COD)') ?>
            </div>

            <!-- Consistent Action Buttons -->
            <div class="card-actions">
              <?php if ($can_cancel): ?>
                <!-- a. Status 'pending' / 'preparing': Show Cancel Order button -->
                <button type="button" class="btn-cancel-order" onclick="cancelOrderAction(<?= $oid ?>, '<?= htmlspecialchars(addslashes($order_ref)) ?>')">
                  <i class="fa-solid fa-ban"></i> Cancel Order
                </button>
              <?php endif; ?>

              <?php if ($is_ontheway || $is_near || $is_arrived): ?>
                <!-- b. Status 'on_delivery' / 'near_customer' / 'arrived': Show Live Track Rider & Received buttons -->
                <a href="customer_dashboard.php?track=<?= $oid ?>" class="cust-track-btn" title="Open live rider GPS tracking">
                  <i class="fa-solid fa-location-dot"></i> 📍 Live Track Rider
                </a>
                <button type="button" class="confirm-btn" onclick="confirmReceivedAction(<?= $oid ?>)">
                  <i class="fa-solid fa-box-open"></i> 📦 Received
                </button>
              <?php endif; ?>

              <?php if ($is_delivered): ?>
                <span style="font-size: 0.78rem; font-weight: 700; color: #166534;"><i class="fa-solid fa-circle-check"></i> Delivered</span>
              <?php elseif ($is_cancelled): ?>
                <span style="font-size: 0.78rem; font-weight: 700; color: #dc2626;"><i class="fa-solid fa-circle-xmark"></i> Cancelled</span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>

  <!-- Floating In-App Toast Alert -->
  <div class="orders-toast" id="ordersToast"></div>

  <!-- In-App Cancel Order Modal -->
  <div class="orders-modal" id="ordersCancelModal" onclick="if(event.target===this)closeOrdersCancelModal()">
    <div class="orders-modal-box">
      <div class="orders-modal-header">
        <h3 style="color:#b91c1c; display:flex; align-items:center; gap:8px;">
          <i class="fa-solid fa-triangle-exclamation"></i> Cancel Order
        </h3>
        <button type="button" class="orders-modal-close" onclick="closeOrdersCancelModal()">&times;</button>
      </div>
      <div class="orders-modal-body">
        <p id="cancelModalOrderRef">Are you sure you want to cancel this order?</p>
        <label for="cancelReasonInput" style="display:block; font-size:0.75rem; font-weight:700; color:#64748b; margin-bottom:6px;">Cancellation Reason:</label>
        <select id="cancelReasonInput" class="orders-modal-input" style="background:#fff;">
          <option value="Changed mind">Changed mind</option>
          <option value="Duplicate order">Duplicate order</option>
          <option value="Wrong delivery address">Wrong delivery address</option>
          <option value="Taking too long to prepare">Taking too long to prepare</option>
          <option value="Other reason">Other reason</option>
        </select>
        <input type="text" id="cancelReasonCustom" class="orders-modal-input" placeholder="Additional details (optional)..." style="margin-top:-8px;">
      </div>
      <div class="orders-modal-actions">
        <button type="button" class="btn-modal-cancel" onclick="closeOrdersCancelModal()">Keep Order</button>
        <button type="button" class="btn-modal-confirm" id="btnConfirmSubmitCancel" style="background:#dc2626;" onclick="submitOrderCancellation()">Confirm Cancel</button>
      </div>
    </div>
  </div>

  <!-- In-App Confirm Received Modal -->
  <div class="orders-modal" id="ordersConfirmModal" onclick="if(event.target===this)closeOrdersConfirmModal()">
    <div class="orders-modal-box" style="text-align:center;">
      <div style="font-size:36px; color:#16a34a; margin-bottom:10px;">
        <i class="fa-solid fa-circle-check"></i>
      </div>
      <h3 style="color:var(--olive-dark); font-size:1.1rem; font-weight:700; margin-bottom:8px;">Confirm Order Delivery</h3>
      <p style="font-size:0.85rem; color:#475569; line-height:1.45; margin-bottom:20px;">
        Have you received your order in good condition? This will finalize your delivery.
      </p>
      <div class="orders-modal-actions" style="justify-content:center;">
        <button type="button" class="btn-modal-cancel" style="flex:1;" onclick="closeOrdersConfirmModal()">Not Yet</button>
        <button type="button" class="btn-modal-confirm" id="btnConfirmSubmitReceived" style="flex:1; background:var(--olive-shadow);" onclick="submitConfirmReceived()">Yes, Received!</button>
      </div>
    </div>
  </div>

  <script>
    let _ordersToastTimer = null;
    function showOrdersToast(msg, isError = false) {
      const el = document.getElementById('ordersToast');
      if (!el) return;
      el.innerHTML = `<i class="fa-solid fa-bell" style="color:var(--harvest-sun, #D88F2A);"></i> <span>${escapeHtml(msg)}</span>`;
      el.classList.toggle('err', !!isError);
      el.classList.add('show');
      clearTimeout(_ordersToastTimer);
      _ordersToastTimer = setTimeout(() => {
        el.classList.remove('show');
      }, 4000); // Auto-dismiss strictly after 4 seconds
    }

    // Redirect all window.alert calls to the FoodDash in-app toast
    window.alert = function(msg) {
      showOrdersToast(String(msg || ''));
    };

    function escapeHtml(str) {
      return String(str || '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
    }

    let _pendingCancelOrderId = null;
    function cancelOrderAction(orderId, ref) {
      _pendingCancelOrderId = orderId;
      const refEl = document.getElementById('cancelModalOrderRef');
      if (refEl) refEl.textContent = `Are you sure you want to cancel order ${ref}?`;
      const modal = document.getElementById('ordersCancelModal');
      if (modal) modal.classList.add('show');
    }

    function closeOrdersCancelModal() {
      const modal = document.getElementById('ordersCancelModal');
      if (modal) modal.classList.remove('show');
      _pendingCancelOrderId = null;
    }

    function submitOrderCancellation() {
      if (!_pendingCancelOrderId) return;
      const reasonSelect = document.getElementById('cancelReasonInput');
      const reasonCustom = document.getElementById('cancelReasonCustom');
      const reason = (reasonSelect ? reasonSelect.value : '') + (reasonCustom && reasonCustom.value.trim() ? ' - ' + reasonCustom.value.trim() : '');
      const btn = document.getElementById('btnConfirmSubmitCancel');
      if (btn) { btn.disabled = true; btn.textContent = 'Cancelling...'; }

      fetch('order_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'cancel_order', order_id: _pendingCancelOrderId, reason: reason || 'Changed mind' })
      })
      .then(res => res.json())
      .then(data => {
        closeOrdersCancelModal();
        if (data.success) {
          showOrdersToast('Order cancelled successfully.');
          setTimeout(() => location.reload(), 1200);
        } else {
          showOrdersToast(data.message || 'Cancellation failed.', true);
        }
      })
      .catch(() => {
        closeOrdersCancelModal();
        showOrdersToast('Network error while cancelling order.', true);
      })
      .finally(() => {
        if (btn) { btn.disabled = false; btn.textContent = 'Confirm Cancel'; }
      });
    }

    let _pendingReceivedOrderId = null;
    function confirmReceivedAction(orderId) {
      _pendingReceivedOrderId = orderId;
      const modal = document.getElementById('ordersConfirmModal');
      if (modal) modal.classList.add('show');
    }

    function closeOrdersConfirmModal() {
      const modal = document.getElementById('ordersConfirmModal');
      if (modal) modal.classList.remove('show');
      _pendingReceivedOrderId = null;
    }

    function submitConfirmReceived() {
      if (!_pendingReceivedOrderId) return;
      const btn = document.getElementById('btnConfirmSubmitReceived');
      if (btn) { btn.disabled = true; btn.textContent = 'Confirming...'; }

      fetch('customer_dashboard.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'confirm_received', order_id: _pendingReceivedOrderId })
      })
      .then(res => res.json())
      .then(data => {
        closeOrdersConfirmModal();
        if (data.success) {
          showOrdersToast('Order delivery confirmed! Thank you.');
          setTimeout(() => location.reload(), 1200);
        } else {
          showOrdersToast(data.message || 'Error confirming receipt.', true);
        }
      })
      .catch(() => {
        closeOrdersConfirmModal();
        showOrdersToast('Network error confirming order.', true);
      })
      .finally(() => {
        if (btn) { btn.disabled = false; btn.textContent = 'Yes, Received!'; }
      });
    }
  </script>
</body>
</html>
