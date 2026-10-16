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
// Query strictly scopes to logged-in customer:
$stmt = $conn->prepare("SELECT * FROM orders WHERE customer_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$raw_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Also fetch corresponding details from food_orders table for complete item/store details
$f_stmt = $conn->prepare("SELECT * FROM food_orders WHERE customer_id = ? ORDER BY order_id DESC");
$f_stmt->execute([$user_id]);
$food_orders_raw = $f_stmt->fetchAll(PDO::FETCH_ASSOC);
$fo_map = [];
foreach ($food_orders_raw as $fo) {
    $fo_map[(int)$fo['order_id']] = $fo;
}

$orders = [];
if (!empty($raw_orders)) {
    foreach ($raw_orders as $ro) {
        $oid = (int)($ro['order_id'] ?? $ro['id'] ?? 0);
        $merged = isset($fo_map[$oid]) ? array_merge($fo_map[$oid], $ro) : $ro;
        $merged['payment_meta'] = get_payment_method_meta($merged['payment_method'] ?? 'Cash on Delivery (COD)');
        $orders[] = $merged;
    }
} elseif (!empty($food_orders_raw)) {
    foreach ($food_orders_raw as $fo) {
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
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>My Orders - Food Dash</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
    * { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
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
      padding: 12px 18px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      box-shadow: 0 1px 6px rgba(0,0,0,0.03);
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
      max-width: 680px;
      margin: 20px auto;
      padding: 0 16px;
    }
    .page-title {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 16px;
    }
    .page-title h2 {
      font-size: 1.3rem;
      font-weight: 800;
      color: var(--olive-dark);
    }
    /* Empty State Box */
    .empty-state-box {
      background: #ffffff;
      border: 1.5px dashed #cbd5e1;
      border-radius: 16px;
      padding: 44px 24px;
      text-align: center;
      box-shadow: 0 4px 14px rgba(0,0,0,0.03);
      margin-top: 10px;
    }
    .empty-state-icon {
      font-size: 52px;
      margin-bottom: 14px;
      line-height: 1;
    }
    .empty-state-title {
      font-size: 1.25rem;
      font-weight: 800;
      color: var(--olive-dark);
      margin-bottom: 8px;
    }
    .empty-state-subtitle {
      font-size: 0.88rem;
      color: var(--text-muted);
      max-width: 360px;
      margin: 0 auto 20px;
      line-height: 1.5;
    }
    .btn-browse-stores {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: var(--harvest-sun);
      color: #ffffff;
      font-weight: 700;
      font-size: 0.92rem;
      padding: 12px 26px;
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
    /* Orders List & Cards */
    .orders-list {
      display: flex;
      flex-direction: column;
      gap: 14px;
    }
    .order-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      padding: 16px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.03);
      transition: box-shadow 0.2s;
    }
    .order-card:hover {
      box-shadow: 0 6px 16px rgba(0,0,0,0.06);
    }
    .order-card-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 8px;
      gap: 8px;
    }
    .order-ref {
      font-weight: 800;
      font-size: 0.95rem;
      color: var(--olive-dark);
    }
    .order-store {
      font-size: 0.82rem;
      font-weight: 600;
      color: var(--text-muted);
      margin-top: 2px;
    }
    .order-meta {
      font-size: 0.76rem;
      color: #94a3b8;
      margin-top: 4px;
    }
    .order-amount {
      font-weight: 800;
      font-size: 1.05rem;
      color: var(--olive-dark);
    }
    .order-card-badges {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      margin: 10px 0;
    }
    /* Status Badges */
    .status-badge {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 4px 10px;
      border-radius: 99px;
      font-size: 0.72rem;
      font-weight: 700;
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
    /* Card Action Buttons */
    .card-actions {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      margin-top: 10px;
      padding-top: 10px;
      border-top: 1px solid #f1f5f9;
    }
    .btn-cancel-order {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #fee2e2;
      color: #dc2626;
      border: 1px solid #fca5a5;
      padding: 6px 14px;
      border-radius: 8px;
      font-size: 0.78rem;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.2s;
    }
    .btn-cancel-order:hover {
      background: #fecaca;
    }
    .cust-track-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: var(--harvest-sun);
      color: #ffffff;
      border: none;
      padding: 7px 14px;
      border-radius: 8px;
      font-size: 0.78rem;
      font-weight: 700;
      text-decoration: none;
      cursor: pointer;
      transition: all 0.2s;
    }
    .cust-track-btn:hover {
      background: var(--harvest-sun-hover);
    }
    .confirm-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: var(--olive-shadow);
      color: #ffffff;
      border: none;
      padding: 7px 14px;
      border-radius: 8px;
      font-size: 0.78rem;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.2s;
    }
    .confirm-btn:hover {
      opacity: 0.9;
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
          
          $is_arrived = ($raw_status === 'ARRIVED' || $del_status === 'arrived');
          $is_near = ($raw_status === 'NEAR_CUSTOMER' || $del_status === 'near_customer');
          $is_ontheway = in_array($raw_status, ['OUT_FOR_DELIVERY', 'ON_THE_WAY', 'ON_DELIVERY'])
                      || in_array($del_status, ['on_the_way', 'on_delivery', 'picked_up'])
                      || $is_near || $is_arrived;
                      
          $is_delivered = ($raw_status === 'DELIVERED' || $raw_status === 'COMPLETED' || $del_status === 'delivered');
          $is_prep = in_array($raw_status, ['PREPARING', 'ACCEPTED']);
          $is_pending = in_array($raw_status, ['PENDING', 'SUBMITTED']);
          $is_cancelled = ($raw_status === 'CANCELLED' || $del_status === 'cancelled');
          
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
          } elseif ($raw_status === 'READY_FOR_PICKUP') {
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
          
          $order_ref = $ord['order_ref'] ?? ('#FD-' . $oid);
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

  <script>
    function cancelOrderAction(orderId, ref) {
      const reason = prompt(`Reason for cancelling order ${ref}:`, 'Changed mind / duplicate order');
      if (!reason) return;

      fetch('order_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'cancel_order', order_id: orderId, reason: reason })
      })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          alert('Order cancelled successfully.');
          location.reload();
        } else {
          alert(data.message || 'Cancellation failed.');
        }
      })
      .catch(() => alert('Network error while cancelling order.'));
    }

    function confirmReceivedAction(orderId) {
      if (!confirm('Have you received your order in good condition?')) return;

      fetch('customer_dashboard.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'confirm_received', order_id: orderId })
      })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          alert('Order delivery confirmed! Thank you.');
          location.reload();
        } else {
          alert(data.message || 'Error confirming receipt.');
        }
      })
      .catch(() => alert('Network error confirming order.'));
    }
  </script>
</body>
</html>
