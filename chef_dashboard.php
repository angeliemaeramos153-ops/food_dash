<?php
session_start();
require_once 'db_connect.php';
require_once 'notification_lib.php';

if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['role'] ?? ''), ['chef', 'manager', 'admin'], true)) {
    header('Location: index.php');
    exit();
}

$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'home';
$selected_shop_id = isset($_GET['shop_id']) ? (int)$_GET['shop_id'] : 0;
$user_id = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? $_SESSION['user']['username'] ?? $_SESSION['full_name'] ?? 'Chef';

// POST Handlers for Menu & Orders
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json');
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'update_order_status') {
        $order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
        $raw_status = isset($_POST['status']) ? trim($_POST['status']) : '';
        $new_status = strtoupper(trim(str_replace([' ', '-'], '_', $raw_status)));
        if ($new_status === 'READY') $new_status = 'READY_FOR_PICKUP';

        if ($order_id <= 0 || !in_array($new_status, ['PREPARING', 'READY_FOR_PICKUP'], true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid status transition.']);
            exit();
        }

        $chk = $conn->prepare("SELECT order_id, customer_id, order_ref, store_name, status FROM food_orders WHERE order_id = ? LIMIT 1");
        $chk->bind_param('i', $order_id);
        $chk->execute();
        $ord_data = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$ord_data) {
            echo json_encode(['success' => false, 'message' => 'Order not found.']);
            exit();
        }

        $old_st = strtoupper(trim(str_replace([' ', '-'], '_', $ord_data['status'])));
        $new_delivery_status = ($new_status === 'PREPARING') ? 'preparing' : 'ready_for_pickup';
        $notes = ($new_status === 'PREPARING') ? 'Chef accepted order and started kitchen preparation' : 'Food prepared and packed, ready for rider pickup';

        $stmt = $conn->prepare("UPDATE food_orders SET status = ?, delivery_status = ?, chef_id = ?, updated_at = NOW() WHERE order_id = ?");
        if ($stmt) {
            $stmt->bind_param('ssii', $new_status, $new_delivery_status, $user_id, $order_id);
            $stmt->execute();
            $stmt->close();

            // Record audit log
            record_order_audit_log($conn, $order_id, $old_st, $new_status, $user_id, 'chef', $notes);

            // Contextual notifications
            if ($new_status === 'PREPARING') {
                create_notification($conn, (int)$ord_data['customer_id'], 'chef_preparing', 'Kitchen Preparing Order', "Your order {$ord_data['order_ref']} is now being prepared by the Chef.", $order_id);
            } elseif ($new_status === 'READY_FOR_PICKUP') {
                create_notification($conn, (int)$ord_data['customer_id'], 'order_ready', 'Order Ready for Pickup', "Your order {$ord_data['order_ref']} is freshly prepared and ready for rider pickup.", $order_id);
                notify_role($conn, 'rider', 'pickup_ready', 'Order Ready for Pickup', "{$ord_data['order_ref']} at {$ord_data['store_name']} is ready for dispatch.", $order_id);
            }

            echo json_encode(['success' => true, 'message' => 'Status updated to ' . $new_status]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        }
        exit();
    }

    if ($action === 'toggle_stock') {
        $item_id = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
        $is_available = isset($_POST['is_available']) ? (int)$_POST['is_available'] : 1;
        $stmt = $conn->prepare("UPDATE menu_items SET is_available = ? WHERE item_id = ?");
        if ($stmt) {
            $stmt->bind_param('ii', $is_available, $item_id);
            $stmt->execute();
            if (ob_get_level()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode(array('success' => true, 'message' => 'Availability updated!'));
            $stmt->close();
        } else {
            if (ob_get_level()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode(array('success' => false, 'message' => 'Database error'));
        }
        exit();
    }

    if ($action === 'save_menu_item') {
        $item_id = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
        $shop_id = isset($_POST['shop_id']) ? (int)$_POST['shop_id'] : 0;
        $item_name = trim($_POST['item_name']);
        $price = (float)$_POST['price'];

        if ($item_id > 0) {
            $stmt = $conn->prepare("UPDATE menu_items SET shop_id = ?, item_name = ?, price = ? WHERE item_id = ?");
            $stmt->bind_param('isdi', $shop_id, $item_name, $price, $item_id);
        } else {
            $stmt = $conn->prepare("INSERT INTO menu_items (shop_id, item_name, price, is_available) VALUES (?, ?, ?, 1)");
            $stmt->bind_param('isd', $shop_id, $item_name, $price);
        }
        if ($stmt && $stmt->execute()) {
            if (ob_get_level()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode(array('success' => true, 'message' => 'Menu saved successfully!'));
        } else {
            if (ob_get_level()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode(array('success' => false, 'message' => $stmt ? $stmt->error : 'Database error'));
        }
        $stmt->close();
        exit();
    }

    if ($action === 'delete_menu_item') {
        $item_id = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
        $stmt = $conn->prepare("DELETE FROM menu_items WHERE item_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $item_id);
            $stmt->execute();
            if (ob_get_level()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode(array('success' => true, 'message' => 'Item deleted!'));
            $stmt->close();
        } else {
            if (ob_get_level()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode(array('success' => false, 'message' => 'Database error'));
        }
        exit();
    }
    
    // Unknown action
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json');
    echo json_encode(array('success' => false, 'message' => 'Unknown action'));
    exit();
}

// 1. Fetch Shops
$shops = array();
$shops_res = $conn->query("SELECT * FROM shops ORDER BY shop_id ASC");
if ($shops_res) {
    while ($row = $shops_res->fetch_assoc()) {
        $shops[$row['shop_id']] = $row;
    }
}

$selected_shop_name = '';
if ($selected_shop_id > 0 && isset($shops[$selected_shop_id])) {
    $selected_shop_name = $shops[$selected_shop_id]['shop_name'];
}

// Dynamic column detection for orders table
function getOrderCustomerColumn($conn) {
    $res = $conn->query("SHOW COLUMNS FROM food_orders LIKE 'customer_id'");
    if ($res && $res->num_rows > 0) return 'customer_id';
    $res = $conn->query("SHOW COLUMNS FROM food_orders LIKE 'user_id'");
    if ($res && $res->num_rows > 0) return 'user_id';
    return 'customer_id';
}

$order_customer_col = getOrderCustomerColumn($conn);

// 2. Fetch Order Counts grouped by shop_id
$store_counts = array();
$total_all_orders = 0;

// Query orders with shop_id for proper grouping
$count_query = $conn->query("SELECT shop_id, COUNT(*) as total FROM food_orders WHERE UPPER(status) NOT IN ('COMPLETED', 'DELIVERED', 'SETTLED', 'CANCELLED') GROUP BY shop_id");
if ($count_query) {
    while ($r = $count_query->fetch_assoc()) {
        $sid = (int)$r['shop_id'];
        $cnt = (int)$r['total'];
        $store_counts[$sid] = $cnt;
        $total_all_orders += $cnt;
    }
}

// Also get counts for orders without shop_id (legacy) grouped by store_name
$legacy_count_query = $conn->query("SELECT store_name, COUNT(*) as total FROM food_orders WHERE (shop_id = 0 OR shop_id IS NULL) AND UPPER(status) NOT IN ('COMPLETED', 'DELIVERED', 'SETTLED', 'CANCELLED') GROUP BY store_name");
if ($legacy_count_query) {
    while ($r = $legacy_count_query->fetch_assoc()) {
        $store_name = $r['store_name'];
        $cnt = (int)$r['total'];
        // Try to match with shops
        $matched = false;
        foreach ($shops as $sid => $s) {
            if (stripos($s['shop_name'], $store_name) !== false || stripos($store_name, $s['shop_name']) !== false) {
                $store_counts[$sid] = ($store_counts[$sid] ?? 0) + $cnt;
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            // Store as legacy with key 0
            $store_counts[0] = ($store_counts[0] ?? 0) + $cnt;
        }
        $total_all_orders += $cnt;
    }
}

// 3. Fetch Orders
$orders = array();
if ($active_tab === 'orders') {
    if ($selected_shop_id === 0) {
        // All stores - show all active and recent orders
        $res = $conn->query("SELECT * FROM food_orders WHERE UPPER(status) IN ('PENDING', 'PREPARING', 'READY_FOR_PICKUP', 'READY', 'OUT_FOR_DELIVERY', 'DELIVERED', 'SETTLED') ORDER BY order_id DESC");
    } else {
        // Specific store - filter by shop_id
        $res = $conn->query("SELECT * FROM food_orders WHERE shop_id = " . (int)$selected_shop_id . " AND UPPER(status) IN ('PENDING', 'PREPARING', 'READY_FOR_PICKUP', 'READY', 'OUT_FOR_DELIVERY', 'DELIVERED', 'SETTLED') ORDER BY order_id DESC");
    }
    if ($res) {
        while ($row = $res->fetch_assoc()) { $orders[] = $row; }
    }
}

// 4. Fetch Menu Items
$menu_items = array();
if ($active_tab === 'menu') {
    if ($selected_shop_id === 0) {
        $res = $conn->query("SELECT m.*, s.shop_name FROM menu_items m LEFT JOIN shops s ON m.shop_id = s.shop_id ORDER BY m.item_id DESC");
    } else {
        $res = $conn->query("SELECT m.*, s.shop_name FROM menu_items m LEFT JOIN shops s ON m.shop_id = s.shop_id WHERE m.shop_id = " . (int)$selected_shop_id . " ORDER BY m.item_id DESC");
    }
    if ($res) {
        while ($row = $res->fetch_assoc()) { $menu_items[] = $row; }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Chef & Manager Portal — Food Dash</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
:root {
  --harvest-sun: #D88F2A;
  --sagebrush: #788A6E;
  --earth-clay: #923D32;
  --olive-shadow: #4B5320;
  --sandstone: #D4C5A5;
  --olive: #4B5320;
  --olive-dark: #373e16;
  --cream: #f6f4e8;
  --paper: #ffffff;
  --line: #e5e0cf;
  --muted: #727568;
  --green: #2e7d32;
  --red: #923D32;
}

* {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
  font-family: 'Poppins', sans-serif;
}

/* Page Outer Background Canvas */
html, body {
  min-height: 100vh;
  width: 100%;
  background:
    radial-gradient(circle at 15% 10%, rgba(216,143,42,.12), transparent 30%),
    radial-gradient(circle at 85% 90%, rgba(75,83,32,.13), transparent 35%),
    #f4f0df;
  color: #20241a;
  display: flex;
  justify-content: center;
  align-items: flex-start;
  margin: 0;
  padding: 20px 0;
}

/* Centered Mobile Frame Container (Standardized 440px) */
.app {
  width: 100%;
  max-width: 440px;
  min-height: 100vh;
  background: var(--cream);
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  position: relative;
  box-shadow: 0 10px 30px rgba(75,83,32,.12);
}

@media(min-width: 600px){
  .app {
    min-height: 720px;
    height: auto;
    border: 1px solid #ddd3ad;
    border-radius: 26px;
    box-shadow: 0 22px 60px rgba(55,65,22,.15);
    overflow: hidden;
  }
}

@media(max-width: 599px){
  html, body { padding: 0; }
  .app { border: none; border-radius: 0; box-shadow: none; min-height: 100vh; }
}

.top-header {
  background: var(--olive);
  color: white;
  padding: 20px 18px 14px;
  border-bottom-left-radius: 18px;
  border-bottom-right-radius: 18px;
}
.top-header h2 {
  font-size: 18px;
  font-weight: 800;
  display: flex;
  align-items: center;
  gap: 8px;
  letter-spacing: 0.5px;
}
.top-header p {
  font-size: 10px;
  opacity: 0.8;
}
.top-header h1 {
  font-size: 19px;
  margin-top: 10px;
  font-weight: 700;
}
.store-select {
  width: 100%;
  padding: 10px 14px;
  border-radius: 12px;
  border: none;
  margin-top: 12px;
  font-size: 12px;
  font-weight: 600;
  background: white;
  color: var(--olive-dark);
  outline: none;
  cursor: pointer;
}

/* Main Content Area */
.content {
  padding: 14px;
  flex: 1;
}

.section-title {
  font-size: 13px;
  font-weight: 700;
  margin-bottom: 10px;
  color: var(--olive-dark);
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.store-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.store-card {
  background: white;
  border: 1px solid var(--line);
  border-radius: 16px;
  padding: 12px;
  cursor: pointer;
  text-align: left;
  transition: transform .2s, box-shadow .2s;
}
.store-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 18px rgba(75, 83, 32, 0.12);
}
.store-card-logo-box {
  width: 100%;
  height: 64px;
  border-radius: 12px;
  background: #fdfbf7;
  border: 1px solid var(--sandstone);
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 8px;
  padding: 6px;
  overflow: hidden;
}
.store-card-logo-img {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
  transition: transform 0.25s ease;
}
.store-card:hover .store-card-logo-img {
  transform: scale(1.08);
}
.store-card h4 {
  font-size: 12px;
  color: #1e2410;
  font-weight: 700;
}
.store-card p {
  font-size: 9px;
  color: var(--muted);
}
.order-count-badge {
  display: inline-block;
  margin-top: 6px;
  padding: 3px 8px;
  border-radius: 10px;
  background: #e8f5e9;
  color: var(--green);
  font-size: 9px;
  font-weight: 700;
}

.card {
  background: white;
  border: 1px solid var(--line);
  border-radius: 14px;
  padding: 12px;
  margin-bottom: 10px;
}
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 6px;
  padding-bottom: 4px;
}
.status-badge {
  padding: 3px 8px;
  border-radius: 10px;
  font-size: 8px;
  font-weight: 700;
  text-transform: uppercase;
}
.status-available { background: #e8f5e9; color: #2e7d32; }
.status-out { background: #ffebee; color: var(--earth-clay); }
.status-pending { background: #fff4e5; color: var(--harvest-sun); border: 1px solid var(--harvest-sun); }
.status-preparing { background: #eef3ec; color: var(--sagebrush); border: 1px solid var(--sagebrush); }
.status-ready_for_pickup, .status-ready { background: #faf6ee; color: #7a6332; border: 1px solid var(--sandstone); }
.status-out_for_delivery { background: #f3f5ea; color: var(--olive-shadow); border: 1px solid var(--olive-shadow); }
.status-delivered { background: #e8f5e9; color: var(--green); border: 1px solid var(--green); }
.status-settled { background: #e6ece3; color: #283a24; border: 1px solid var(--sagebrush); }

.btn-add {
  background: var(--olive);
  color: white;
  border: none;
  padding: 5px 10px;
  border-radius: 8px;
  font-size: 10px;
  font-weight: 600;
  cursor: pointer;
}
.menu-actions {
  display: flex;
  gap: 6px;
  margin-top: 8px;
}
.menu-btn {
  flex: 1;
  padding: 6px;
  border: none;
  border-radius: 6px;
  font-size: 9px;
  font-weight: 700;
  cursor: pointer;
}
.btn-toggle-out {
  background: #ffebee;
  color: var(--earth-clay);
}
.btn-toggle-in {
  background: #e8f5e9;
  color: #2e7d32;
}
.btn-edit {
  background: #e3f2fd;
  color: #1565c0;
}
.btn-delete {
  background: #fbe9e7;
  color: var(--earth-clay);
}

.action-btn {
  width: 100%;
  padding: 8px;
  border: none;
  border-radius: 8px;
  background: var(--olive);
  color: white;
  font-size: 10px;
  font-weight: 700;
  cursor: pointer;
  margin-top: 6px;
  transition: .2s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
}
.action-btn.btn-accept {
  background: var(--harvest-sun);
}
.action-btn.btn-accept:hover {
  background: #bd791e;
}
.action-btn.btn-ready {
  background: var(--sagebrush);
}
.action-btn.btn-ready:hover {
  background: #627259;
}

/* Toast Notifications */
.toast-chef {
  position: fixed; left: 50%; bottom: 80px; transform: translateX(-50%) translateY(20px);
  background: var(--olive); color: white; padding: 9px 16px; border-radius: 20px;
  font-size: 10px; font-weight: 600; opacity: 0; pointer-events: none;
  transition: .25s; z-index: 200; white-space: nowrap;
  box-shadow: 0 4px 15px rgba(56, 73, 24, .30);
}
.toast-chef.show { opacity: 1; transform: translateX(-50%) translateY(0); }
.toast-chef.error { background: var(--earth-clay); }

/* In-App Notifications Modal */
.notif-modal {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.55);
  backdrop-filter: blur(2px);
  z-index: 350;
  justify-content: center;
  align-items: center;
  padding: 16px;
}
.notif-box {
  background: white;
  border-radius: 20px;
  width: 100%;
  max-width: 360px;
  max-height: 80vh;
  display: flex;
  flex-direction: column;
  box-shadow: 0 20px 45px rgba(0, 0, 0, 0.25);
  overflow: hidden;
  border: 2px solid var(--sandstone);
}
.notif-header {
  padding: 14px 16px;
  background: var(--olive-shadow);
  color: white;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.notif-header h3 {
  font-size: 13px;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 8px;
}
.notif-close-btn {
  background: transparent;
  border: none;
  color: white;
  font-size: 16px;
  cursor: pointer;
}
.notif-list {
  padding: 12px;
  overflow-y: auto;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.notif-item {
  background: #faf8f3;
  border-left: 4px solid var(--harvest-sun);
  border-radius: 8px;
  padding: 10px;
  font-size: 11px;
}
.notif-item.unread {
  background: #fdf6ec;
  border-left-color: var(--earth-clay);
}
.notif-title {
  font-weight: 700;
  color: var(--olive-dark);
  margin-bottom: 2px;
}
.notif-time {
  font-size: 9px;
  color: var(--muted);
  margin-top: 4px;
}

/* Confirmation Modal */
.confirm-modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5);
  justify-content: center; align-items: center; z-index: 300; padding: 16px; }
.confirm-modal .confirm-box { background: white; border-radius: 18px; padding: 22px; width: 100%;
  max-width: 300px; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,.2); }
.confirm-modal h3 { font-size: 15px; margin-bottom: 12px; }
.confirm-modal p { font-size: 11px; color: #777; margin-bottom: 18px; }
.confirm-modal .confirm-actions { display: flex; gap: 10px; }
.confirm-modal button { flex: 1; padding: 10px; border: none; border-radius: 10px; font-size: 10px; font-weight: 700; cursor: pointer; }
.confirm-modal .confirm-cancel { background: #eee; color: #333; }
.confirm-modal .confirm-submit { background: #d84315; color: white; }

/* Bottom Nav locked inside frame (Standardized 64px) */
.bottom-nav {
  height: 64px;
  background: rgba(255, 255, 255, 0.98);
  backdrop-filter: blur(8px);
  border-top: 1px solid var(--line);
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  position: sticky;
  bottom: 0;
  width: 100%;
  z-index: 100;
  margin-top: auto;
}
.nav-item {
  border: 0;
  background: transparent;
  color: var(--muted);
  font-size: 8px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 3px;
  cursor: pointer;
  text-decoration: none;
  font-weight: 500;
}
.nav-item i { font-size: 15px; }
.nav-item.active {
  color: var(--olive-dark);
  font-weight: 700;
}

.table-responsive {
  width: 100%;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}

/* Modal Window */
.modal {
  display: none;
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0,0,0,0.5);
  z-index: 99;
  justify-content: center;
  align-items: center;
  padding: 16px;
}
.modal-content {
  background: white;
  border-radius: 16px;
  padding: 16px;
  width: 100%;
  max-width: 320px;
}
.modal-content input, .modal-content select {
  width: 100%;
  padding: 8px;
  margin: 6px 0;
  border-radius: 8px;
  border: 1px solid var(--line);
  font-size: 11px;
}
</style>
</head>
<body>

<div class="app">
  <div class="top-header">
    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
      <div>
        <h2><i class="fa-solid fa-utensils"></i> FOOD DASH</h2>
        <p>Chef & Manager Portal</p>
      </div>
      <button id="notifBellBtn" onclick="openNotifModal()" style="position:relative; background:rgba(255,255,255,0.18); border:none; color:white; width:36px; height:36px; border-radius:50%; cursor:pointer; font-size:15px; display:grid; place-items:center;">
        <i class="fa-solid fa-bell"></i>
        <span id="notifBadge" style="display:none; position:absolute; top:-3px; right:-3px; background:var(--earth-clay); color:white; font-size:9px; font-weight:700; width:17px; height:17px; border-radius:50%; display:flex; align-items:center; justify-content:center; border:2px solid var(--olive-shadow);">0</span>
      </button>
    </div>
    <h1>Hello, <?php echo htmlspecialchars($username); ?> 👋</h1>
    <p>Overview orders, menus, and item readiness.</p>
    
    <select class="store-select" onchange="location.href='?tab=<?php echo $active_tab; ?>&shop_id='+this.value">
      <option value="0" <?php echo $selected_shop_id === 0 ? 'selected' : ''; ?>>
        All Stores — (<?php echo $total_all_orders; ?> active orders)
      </option>
      <?php foreach ($shops as $sid => $s): 
        $cnt = isset($store_counts[$sid]) ? $store_counts[$sid] : 0;
      ?>
        <option value="<?php echo $sid; ?>" <?php echo $selected_shop_id === $sid ? 'selected' : ''; ?>>
          <?php echo htmlspecialchars($s['shop_name']); ?> — (<?php echo $cnt; ?> orders)
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="content">
    <?php if ($active_tab === 'home'): ?>
      <h3 class="section-title">All Database Shops</h3>
      <div class="store-grid">
        <?php foreach ($shops as $sid => $s): 
          $cnt = isset($store_counts[$sid]) ? $store_counts[$sid] : 0;
          $cat = isset($s['category']) ? $s['category'] : 'Fast Food';
          $logo = !empty($s['logo_url']) ? $s['logo_url'] : 'assets/shops/default_store.svg';
        ?>
          <div class="store-card" onclick="location.href='?tab=orders&shop_id=<?php echo $sid; ?>'">
            <div class="store-card-logo-box">
              <img src="<?php echo htmlspecialchars($logo); ?>" alt="<?php echo htmlspecialchars($s['shop_name']); ?>" class="store-card-logo-img" onerror="if(!this.dataset.fallback){this.dataset.fallback=1;this.src='assets/shops/default_store.svg';}else{this.onerror=null;this.src='logo.jpg';}">
            </div>
            <h4><?php echo htmlspecialchars($s['shop_name']); ?></h4>
            <p><?php echo htmlspecialchars($cat); ?></p>
            <span class="order-count-badge"><i class="fa-solid fa-bell"></i> <?php echo $cnt; ?> Orders</span>
          </div>
        <?php endforeach; ?>
      </div>

    <?php elseif ($active_tab === 'orders'): ?>
      <h3 class="section-title">Customer Orders</h3>
      <?php if (empty($orders)): ?>
        <div class="card"><p style="font-size:10px;color:var(--muted);text-align:center;">No orders found.</p></div>
      <?php else: foreach ($orders as $ord): 
        $st = isset($ord['status']) ? strtoupper(trim($ord['status'])) : 'PENDING';
      ?>
        <div class="card">
          <div class="card-header">
            <strong style="font-size:11px;">#<?php echo htmlspecialchars(isset($ord['order_ref']) ? $ord['order_ref'] : $ord['order_id']); ?> — <?php echo htmlspecialchars(isset($ord['store_name']) ? $ord['store_name'] : 'Shop'); ?></strong>
            <span class="status-badge status-<?php echo strtolower($st); ?>"><?php echo htmlspecialchars($st); ?></span>
          </div>
          <p style="font-size:10px;color:var(--muted);">Items: <strong><?php echo htmlspecialchars(isset($ord['items_summary']) ? $ord['items_summary'] : 'Order'); ?></strong></p>
          <p style="font-size:11px;font-weight:700;color:var(--olive);margin-top:4px;">₱<?php echo number_format((float)(isset($ord['total_amount']) ? $ord['total_amount'] : 0), 2); ?></p>
          <?php if ($st === 'PENDING'): ?>
            <button class="action-btn btn-accept" onclick="updateOrderStatus(<?php echo $ord['order_id']; ?>, 'PREPARING')"><i class="fa-solid fa-fire-burner"></i> Accept & Prepare</button>
          <?php elseif ($st === 'PREPARING'): ?>
            <button class="action-btn btn-ready" onclick="updateOrderStatus(<?php echo $ord['order_id']; ?>, 'READY_FOR_PICKUP')"><i class="fa-solid fa-circle-check"></i> Mark Ready for Pickup</button>
          <?php elseif ($st === 'READY' || $st === 'READY_FOR_PICKUP'): ?>
            <div style="font-size:10px;color:var(--harvest-sun);font-weight:600;margin-top:6px;background:#fdf9ed;padding:6px;border-radius:6px;text-align:center;"><i class="fa-solid fa-clock"></i> Ready for Pickup — Waiting for Rider</div>
          <?php elseif ($st === 'OUT_FOR_DELIVERY'): ?>
            <div style="font-size:10px;color:var(--olive-shadow);font-weight:600;margin-top:6px;background:#f5f7ee;padding:6px;border-radius:6px;text-align:center;"><i class="fa-solid fa-motorcycle"></i> Out for Delivery (Rider en route)</div>
          <?php elseif ($st === 'DELIVERED'): ?>
            <div style="font-size:10px;color:var(--sagebrush);font-weight:600;margin-top:6px;background:#eef3ec;padding:6px;border-radius:6px;text-align:center;"><i class="fa-solid fa-house-chimney-check"></i> Delivered to Customer</div>
          <?php elseif ($st === 'SETTLED'): ?>
            <div style="font-size:10px;color:var(--olive-dark);font-weight:600;margin-top:6px;background:#f0ece1;padding:6px;border-radius:6px;text-align:center;"><i class="fa-solid fa-file-invoice-dollar"></i> Settled & Audited by Admin</div>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>

    <?php elseif ($active_tab === 'menu'): ?>
      <div class="section-title">
        <span>Shop Menu Items</span>
        <button class="btn-add" onclick="openMenuModal()"><i class="fa-solid fa-plus"></i> Add Item</button>
      </div>

      <?php if (empty($menu_items)): ?>
        <div class="card"><p style="font-size:10px;color:var(--muted);text-align:center;">No menu items listed.</p></div>
      <?php else: foreach ($menu_items as $item): 
        $avail = !isset($item['is_available']) || (int)$item['is_available'] === 1;
      ?>
        <div class="card">
          <div class="card-header">
            <strong style="font-size:11px;"><?php echo htmlspecialchars($item['item_name']); ?></strong>
            <span class="status-badge <?php echo $avail ? 'status-available' : 'status-out'; ?>">
              ₱<?php echo number_format((float)$item['price'], 2); ?>
            </span>
          </div>
          <p style="font-size:9px;color:var(--muted);"><?php echo htmlspecialchars(isset($item['shop_name']) ? $item['shop_name'] : 'General Menu'); ?></p>
          
          <div class="menu-actions">
            <button class="menu-btn <?php echo $avail ? 'btn-toggle-out' : 'btn-toggle-in'; ?>" onclick="toggleStock(<?php echo $item['item_id']; ?>, <?php echo $avail ? 0 : 1; ?>)">
              <?php echo $avail ? 'Mark Out of Stock' : 'Mark Available'; ?>
            </button>
            <button class="menu-btn btn-edit" onclick='editMenu(<?php echo json_encode($item); ?>)'>Edit</button>
            <button class="menu-btn btn-delete" onclick="deleteMenu(<?php echo $item['item_id']; ?>)">Delete</button>
          </div>
        </div>
      <?php endforeach; endif; ?>
    <?php endif; ?>
  </div>

  <nav class="bottom-nav">
    <a href="?tab=home&shop_id=<?php echo $selected_shop_id; ?>" class="nav-item <?php echo $active_tab === 'home' ? 'active' : ''; ?>"><i class="fa-solid fa-house"></i>Home</a>
    <a href="?tab=orders&shop_id=<?php echo $selected_shop_id; ?>" class="nav-item <?php echo $active_tab === 'orders' ? 'active' : ''; ?>"><i class="fa-solid fa-bell"></i>Orders</a>
    <a href="?tab=menu&shop_id=<?php echo $selected_shop_id; ?>" class="nav-item <?php echo $active_tab === 'menu' ? 'active' : ''; ?>"><i class="fa-solid fa-utensils"></i>Menu</a>
    <a href="logout.php" class="nav-item"><i class="fa-solid fa-right-from-bracket"></i>Logout</a>
  </nav>

  <!-- Add/Edit Modal -->
  <div class="modal" id="menuModal">
    <div class="modal-content">
      <h4 id="modalTitle" style="font-size:13px;margin-bottom:8px;">Add Menu Item</h4>
      <input type="hidden" id="menu_id">
      <label style="font-size:9px;">Shop:</label>
      <select id="modal_shop_id">
        <?php foreach ($shops as $sid => $s): ?>
          <option value="<?php echo $sid; ?>"><?php echo htmlspecialchars($s['shop_name']); ?></option>
        <?php endforeach; ?>
      </select>
      <label style="font-size:9px;">Item Name:</label>
      <input type="text" id="modal_item_name" placeholder="Item Name">
      <label style="font-size:9px;">Price (₱):</label>
      <input type="number" step="0.01" id="modal_price" placeholder="Price">
      <div style="display:flex;gap:6px;margin-top:10px;">
        <button class="action-btn" style="background:#888;" onclick="closeModal()">Cancel</button>
        <button class="action-btn" onclick="saveMenu()">Save</button>
      </div>
    </div>
  </div>
</div>

    <!-- Toast Notification -->
  <div class="toast-chef" id="chefToast"></div>

  <!-- Confirmation Modal -->
  <div class="confirm-modal" id="confirmModal">
    <div class="confirm-box">
      <h3 id="confirmTitle">Confirm Action</h3>
      <p id="confirmMessage">Are you sure?</p>
      <div class="confirm-actions">
        <button class="confirm-cancel" onclick="closeConfirmModal()">Cancel</button>
        <button class="confirm-submit" onclick="confirmAction()">Confirm</button>
      </div>
    </div>
  </div>

  <!-- Notification Center Modal -->
  <div class="notif-modal" id="notifModal">
    <div class="notif-box">
      <div class="notif-header">
        <h3><i class="fa-solid fa-bell"></i> Kitchen Notifications</h3>
        <button class="notif-close-btn" onclick="closeNotifModal()">&times;</button>
      </div>
      <div class="notif-list" id="notifList">
        <div style="text-align:center; padding:20px; color:var(--muted); font-size:11px;">Loading alerts...</div>
      </div>
    </div>
  </div>
</div>

<script>
var _pendingConfirm = null;

function toastChef(msg, isError) {
  const t = document.getElementById('chefToast');
  t.textContent = msg;
  t.classList.toggle('error', !!isError);
  t.classList.add('show');
  clearTimeout(window._tcToast);
  window._tcToast = setTimeout(() => t.classList.remove('show'), 3000);
}

function showConfirm(title, message, callback) {
  document.getElementById('confirmTitle').textContent = title;
  document.getElementById('confirmMessage').textContent = message;
  _pendingConfirm = callback;
  document.getElementById('confirmModal').style.display = 'flex';
}

function closeConfirmModal() {
  document.getElementById('confirmModal').style.display = 'none';
  _pendingConfirm = null;
}

function confirmAction() {
  if (_pendingConfirm) _pendingConfirm();
  closeConfirmModal();
}

function postData(data) {
  const fd = new FormData();
  for (let k in data) fd.append(k, data[k]);
  return fetch(window.location.href, { method: 'POST', body: fd }).then(r => r.json());
}

function updateOrderStatus(orderId, status) {
  postData({ action: 'update_order_status', order_id: orderId, status: status }).then(data => {
    if (data.success) { toastChef('Order status updated'); location.reload(); }
    else { toastChef(data.message || 'Update failed', true); }
  });
}

function toggleStock(itemId, availability) {
  postData({ action: 'toggle_stock', item_id: itemId, is_available: availability }).then(data => {
    if (data.success) location.reload();
    else toastChef(data.message || 'Failed to update', true);
  });
}

function deleteMenu(itemId) {
  showConfirm('Delete Menu Item', 'Are you sure you want to delete this menu item?', function() {
    postData({ action: 'delete_menu_item', item_id: itemId }).then(data => {
      if (data.success) location.reload();
      else toastChef(data.message || 'Delete failed', true);
    });
  });
}

function openMenuModal() {
  document.getElementById('modalTitle').innerText = 'Add Menu Item';
  document.getElementById('menu_id').value = 0;
  document.getElementById('modal_item_name').value = '';
  document.getElementById('modal_price').value = '';
  document.getElementById('menuModal').style.display = 'flex';
}

function editMenu(item) {
  document.getElementById('modalTitle').innerText = 'Edit Menu Item';
  document.getElementById('menu_id').value = item.item_id;
  document.getElementById('modal_shop_id').value = item.shop_id;
  document.getElementById('modal_item_name').value = item.item_name;
  document.getElementById('modal_price').value = item.price;
  document.getElementById('menuModal').style.display = 'flex';
}

function closeModal() {
  document.getElementById('menuModal').style.display = 'none';
}

function saveMenu() {
  const data = {
    action: 'save_menu_item',
    item_id: document.getElementById('menu_id').value,
    shop_id: document.getElementById('modal_shop_id').value,
    item_name: document.getElementById('modal_item_name').value,
    price: document.getElementById('modal_price').value
  };
  postData(data).then(resp => {
    if (resp.success) { toastChef('Menu saved'); closeModal(); location.reload(); }
    else { toastChef(resp.message || 'Save failed', true); }
  }).catch(() => toastChef('Network error', true));
}

function openNotifModal() {
  document.getElementById('notifModal').style.display = 'flex';
  loadNotifications();
}

function closeNotifModal() {
  document.getElementById('notifModal').style.display = 'none';
}

function loadNotifications() {
  fetch('notification_api.php?action=get_notifications')
    .then(r => r.json())
    .then(data => {
      const list = document.getElementById('notifList');
      if (!data.notifications || data.notifications.length === 0) {
        list.innerHTML = '<div style="text-align:center; padding:20px; color:var(--muted); font-size:11px;">No notifications yet.</div>';
        return;
      }
      let html = '';
      data.notifications.forEach(n => {
        const isUnread = n.status === 'unread';
        html += `<div class="notif-item ${isUnread ? 'unread' : ''}">
          <div class="notif-title">${escapeHtml(n.title)}</div>
          <div>${escapeHtml(n.message)}</div>
          <div class="notif-time">${escapeHtml(n.created_at || '')}</div>
        </div>`;
      });
      list.innerHTML = html;
      fetch('notification_api.php?action=mark_all_read', { method: 'POST' }).then(() => {
        const b = document.getElementById('notifBadge');
        if (b) b.style.display = 'none';
      });
    })
    .catch(() => {
      document.getElementById('notifList').innerHTML = '<div style="text-align:center; padding:20px; color:var(--earth-clay); font-size:11px;">Failed to load alerts.</div>';
    });
}

function pollUnreadCount() {
  fetch('notification_api.php?action=get_unread_count')
    .then(r => r.json())
    .then(data => {
      const b = document.getElementById('notifBadge');
      if (b) {
        if (data.unread_count > 0) {
          b.textContent = data.unread_count > 9 ? '9+' : data.unread_count;
          b.style.display = 'flex';
        } else {
          b.style.display = 'none';
        }
      }
    })
    .catch(() => {});
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

pollUnreadCount();
setInterval(pollUnreadCount, 6000);

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { closeConfirmModal(); closeNotifModal(); }
});
</script>
</body>
</html>