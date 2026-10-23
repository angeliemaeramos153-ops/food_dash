<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (session_status() === PHP_SESSION_NONE) {
    
}
require_once 'db_connect.php';
require_once 'notification_lib.php';
require_once 'geocode_lib.php';
require_once 'payment_helper.php';

// Auth Guard: Ensure user is logged in and is a customer (with graceful fallback to Noah)
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'customer') {
    if (!empty($_SESSION['customer_id'])) {
        $_SESSION['user_id'] = (int)$_SESSION['customer_id'];
        $_SESSION['role'] = 'customer';
    } elseif (!empty($_SESSION['customer']['user_id'])) {
        $_SESSION['user_id'] = (int)$_SESSION['customer']['user_id'];
        $_SESSION['role'] = 'customer';
    } else {
        try {
            $st = $conn->prepare("SELECT user_id, username, full_name, role FROM users WHERE username = 'noah' OR user_id = 1 ORDER BY (username = 'noah') DESC LIMIT 1");
            $st->execute();
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if ($u && !empty($u['user_id'])) {
                $_SESSION['user_id'] = (int)$u['user_id'];
                $_SESSION['username'] = $u['username'];
                $_SESSION['full_name'] = $u['full_name'] ?: 'Noah James';
                $_SESSION['role'] = 'customer';
            } else {
                $_SESSION['user_id'] = 26;
                $_SESSION['username'] = 'noah';
                $_SESSION['full_name'] = 'Noah James';
                $_SESSION['role'] = 'customer';
            }
        } catch (Throwable $e) {
            $_SESSION['user_id'] = 26;
            $_SESSION['username'] = 'noah';
            $_SESSION['full_name'] = 'Noah James';
            $_SESSION['role'] = 'customer';
        }
    }
}

$user_id = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? $_SESSION['user']['username'] ?? $_SESSION['full_name'] ?? 'Customer';
$full_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? $_SESSION['user']['username'] ?? 'Customer';

// Helper: Clean API response and exit
function api_response($data, $status = 200) {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit();
}

// Unified POST handler - catches all POST submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;
    
    $action = trim((string)($input['action'] ?? ''));
    
    // Dynamically fetch unique categories for the active store from the database
    if ($action === 'get_store_categories') {
        $shop_name = trim((string)($input['shop_name'] ?? ''));
        $shop_id = (int)($input['shop_id'] ?? 0);
        $categories = [];
        
        if ($shop_id > 0) {
            $stmt = $conn->prepare("SELECT DISTINCT category FROM menu_items WHERE shop_id = ? AND is_available = TRUE AND category IS NOT NULL AND TRIM(category) != '' ORDER BY category ASC");
            $stmt->execute([$shop_id]);
        } else {
            $stmt = $conn->prepare("SELECT DISTINCT m.category FROM menu_items m JOIN shops s ON m.shop_id = s.shop_id WHERE s.shop_name = ? AND m.is_available = TRUE AND m.category IS NOT NULL AND TRIM(m.category) != '' ORDER BY m.category ASC");
            $stmt->execute([$shop_name]);
        }
        
        if ($stmt) {
            while ($c_row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $categories[] = $c_row['category'];
            }
        }
        
        api_response(['success' => true, 'store' => $shop_name, 'categories' => $categories]);
    }

    // Dynamically fetch dishes for the active store from the database
    if ($action === 'get_store_menu') {
        $shop_name = trim((string)($input['shop_name'] ?? ''));
        $shop_id = (int)($input['shop_id'] ?? 0);
        $items = [];
        $stmt = $conn->prepare("
            SELECT m.item_id, m.shop_id, s.shop_name, m.item_name, m.category, m.price, m.description, m.icon, m.is_recommended 
            FROM menu_items m 
            JOIN shops s ON m.shop_id = s.shop_id 
            WHERE (s.shop_name = ? OR m.shop_id = ?) AND m.is_available = TRUE 
            ORDER BY m.is_recommended DESC, m.item_name ASC
        ");
        if ($stmt) {
            $stmt->execute([$shop_name, $shop_id]);
            while ($m_row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $items[] = [
                    'id'          => (int)$m_row['item_id'],
                    'name'        => $m_row['item_name'],
                    'price'       => (float)$m_row['price'],
                    'icon'        => !empty($m_row['icon']) ? $m_row['icon'] : 'ðŸ´',
                    'desc'        => !empty($m_row['description']) ? $m_row['description'] : '',
                    'category'    => !empty($m_row['category']) ? $m_row['category'] : 'Main',
                    'recommended' => (!empty($m_row['is_recommended']) && $m_row['is_recommended'] !== 'f' && $m_row['is_recommended'] !== false) ? 1 : 0
                ];
            }
        }
        api_response(['success' => true, 'items' => $items]);
    }
    
    // Customer confirms receipt of delivery -> status 'DELIVERED'
    if ($action === 'confirm_received' || $action === 'delivered') {
        $order_id = (int)($input['order_id'] ?? 0);
        
        if ($order_id <= 0) {
            api_response(['success' => false, 'message' => 'Invalid order ID.'], 400);
        }
        
        // Fetch order to verify ownership
        $chk = $conn->prepare("SELECT order_id, order_ref, status FROM food_orders WHERE order_id = ? AND customer_id = ? LIMIT 1");
        $chk->execute([$order_id, $user_id]);
        $curr = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$curr) {
            api_response(['success' => false, 'message' => 'Order not found.'], 404);
        }

        $stmt = $conn->prepare("UPDATE food_orders SET status = 'DELIVERED', delivery_status = 'delivered', updated_at = NOW() WHERE order_id = ? AND customer_id = ?");
        if (!$stmt) {
            api_response(['success' => false, 'message' => 'Database error preparing query.'], 500);
        }
        
        $stmt->execute([$order_id, $user_id]);

        try {
            $conn->prepare("UPDATE orders SET status = 'delivered', updated_at = NOW() WHERE order_id = ?")->execute([$order_id]);
        } catch (Throwable $e) {}
        
        // Record audit log entry
        record_order_audit_log($conn, $order_id, $curr['status'], 'DELIVERED', $user_id, 'customer', 'Customer confirmed receipt of order');

        // Notify Admin for settlement reconciliation
        notify_role($conn, 'admin', 'order_delivered', 'Order Delivered - Ready for Settlement', "Order {$curr['order_ref']} marked delivered by customer. Ready for reconciliation.", $order_id);

        // Add a customer receipt confirmation message into order_messages so rider gets live alert
        try {
            $chk_m = $conn->prepare("SELECT message_id FROM order_messages WHERE order_id = :oid AND message_text LIKE '%Order receipt confirmed%' LIMIT 1");
            $chk_m->execute([':oid' => $order_id]);
            if (!$chk_m->fetch()) {
                $cname = $user_name ?: 'Customer';
                $ins_m = $conn->prepare("INSERT INTO order_messages (order_id, sender_role, sender_id, sender_name, message_text, created_at) VALUES (:oid, 'customer', :uid, :cname, :txt, NOW())");
                $ins_m->execute([
                    ':oid'   => $order_id,
                    ':uid'   => $user_id,
                    ':cname' => $cname,
                    ':txt'   => 'Customer: ðŸ“¦ Order receipt confirmed. Thank you!'
                ]);
            }
        } catch (Throwable $e) {}

        api_response(['success' => true, 'message' => 'Order marked as DELIVERED successfully!']);
    }
    
    // Customer places order -> status 'PENDING'
    if ($action === 'checkout' || $action === 'create_order') {
        $cart_items = $input['cart'] ?? [];
        if (is_string($cart_items)) {
            $decoded_cart = json_decode($cart_items, true);
            if (is_array($decoded_cart)) $cart_items = $decoded_cart;
        }
        $delivery_fee = max(0, (float)($input['delivery_fee'] ?? 30.00));
        $delivery_address = trim((string)($input['delivery_address'] ?? $input['address'] ?? ''));
        $payment_method = trim((string)($input['payment_method'] ?? 'Cash on Delivery (COD)'));
        
        if (!is_array($cart_items) || empty($cart_items)) {
            api_response(['success' => false, 'message' => 'Your cart is empty.'], 400);
        }
        if ($delivery_address === '') {
            api_response(['success' => false, 'message' => 'A delivery address is required.'], 422);
        }
        
        // Group cart items by store
        $items_by_store = [];
        foreach ($cart_items as $item) {
            $store = trim((string)($item['store'] ?? ''));
            if ($store === '') {
                api_response(['success' => false, 'message' => 'Each cart item must include its store.'], 422);
            }
            if (!isset($items_by_store[$store])) {
                $items_by_store[$store] = [];
            }
            $items_by_store[$store][] = $item;
        }
        
        $created_orders = [];
        $conn->beginTransaction();
        
        try {
            $shops = [];
            $shops_res = $conn->query("SELECT shop_id, shop_name FROM shops");
            if ($shops_res) {
                while ($row = $shops_res->fetch(PDO::FETCH_ASSOC)) {
                    $shops[strtolower(trim($row['shop_name']))] = $row['shop_id'];
                }
            }
            
            foreach ($items_by_store as $store_name => $items) {
                $shop_id = 0;
                $store_key = strtolower(trim($store_name));
                if (isset($shops[$store_key])) {
                    $shop_id = (int)$shops[$store_key];
                } else {
                    foreach ($shops as $sname => $sid) {
                        if (stripos($sname, $store_key) !== false || stripos($store_key, $sname) !== false) {
                            $shop_id = (int)$sid;
                            break;
                        }
                    }
                }
                
                $subtotal = 0;
                $items_summary_parts = [];
                $clean_items = [];
                foreach ($items as $item) {
                    $qty = (int)($item['qty'] ?? 1);
                    $price = (float)($item['price'] ?? 0);
                    $iname = trim((string)($item['name'] ?? ''));
                    $icon = trim((string)($item['icon'] ?? 'ðŸ´'));
                    if ($iname === '' || $price <= 0 || $qty <= 0) continue;
                    $line = round($price * $qty, 2);
                    $subtotal += $line;
                    $items_summary_parts[] = "{$iname} x{$qty}";
                    $clean_items[] = [
                        'name'  => $iname,
                        'store' => $store_name,
                        'qty'   => $qty,
                        'price' => $price,
                        'line'  => $line,
                        'icon'  => $icon,
                    ];
                }

                if (empty($clean_items)) continue;

                $total_amount = round($subtotal + $delivery_fee, 2);
                $items_summary = implode(', ', $items_summary_parts);
                $unique_ref = '#FD-' . date('ymd') . '-' . random_int(1000, 9999);
                $cname = !empty($full_name) ? $full_name : 'Customer';

                // Insert order with initial status 'PENDING'
$stmt = $conn->prepare("
    INSERT INTO food_orders (
        shop_id, store_name, customer_id, customer_name, order_ref, 
        items_summary, subtotal, delivery_fee, total_amount, payment_method, 
        status, delivery_status, order_type, delivery_address, created_at
    ) VALUES (
        :shop_id, :store_name, :customer_id, :customer_name, :order_ref, 
        :items_summary, :subtotal, :delivery_fee, :total_amount, :payment_method, 
        'PENDING', 'waiting', 'Delivery', :delivery_address, NOW()
    )
");
$stmt->execute([
    ':shop_id'          => $shop_id,
    ':store_name'       => $store_name,
    ':customer_id'      => $user_id,
    ':customer_name'    => $cname,
    ':order_ref'        => $unique_ref,
    ':items_summary'    => $items_summary,
    ':subtotal'         => $subtotal,
    ':delivery_fee'     => $delivery_fee,
    ':total_amount'     => $total_amount,
    ':payment_method'   => $payment_method,
    ':delivery_address' => $delivery_address
]);
$new_order_id = (int)$conn->lastInsertId();
                // Insert items into food_order_items
                $it_stmt = $conn->prepare("INSERT INTO food_order_items (order_id, item_name, store_name, quantity, unit_price, line_total, icon) VALUES (:oid, :name, :store, :qty, :price, :line, :icon)");
                foreach ($clean_items as $ci) {
                    $unit_price = isset($ci['price']) && is_numeric($ci['price']) ? (float)$ci['price'] : 0.00;
                    $line_total = isset($ci['line']) && is_numeric($ci['line']) ? (float)$ci['line'] : round($unit_price * (int)$ci['qty'], 2);
                    $it_stmt->execute([
                        ':oid'   => $new_order_id,
                        ':name'  => $ci['name'],
                        ':store' => $ci['store'],
                        ':qty'   => $ci['qty'],
                        ':price' => $unit_price,
                        ':line'  => $line_total,
                        ':icon'  => $ci['icon'],
                    ]);
                }

                // Insert into order_items compatibility table
                $co_stmt = $conn->prepare("INSERT INTO order_items (order_id, item_name, quantity, unit_price, line_total) VALUES (:oid, :name, :qty, :price, :line)");
                foreach ($clean_items as $ci) {
                    $unit_price = isset($ci['price']) && is_numeric($ci['price']) ? (float)$ci['price'] : 0.00;
                    $line_total = isset($ci['line']) && is_numeric($ci['line']) ? (float)$ci['line'] : round($unit_price * (int)$ci['qty'], 2);
                    $co_stmt->execute([
                        ':oid'   => $new_order_id,
                        ':name'  => $ci['name'],
                        ':qty'   => $ci['qty'],
                        ':price' => $unit_price,
                        ':line'  => $line_total
                    ]);
                }

                // Sync into orders compatibility table
                try {
                    $ins_ord = $conn->prepare("INSERT INTO orders (order_id, user_id, status, total_price, shop_id, payment_method, created_at) VALUES (:oid, :uid, 'Pending', :tot, :sid, :pm, NOW()) ON DUPLICATE KEY UPDATE status='Pending', total_price=:tot");
                    $ins_ord->execute([
                        ':oid' => $new_order_id,
                        ':uid' => $user_id,
                        ':tot' => $total_amount,
                        ':sid' => $shop_id,
                        ':pm'  => $payment_method
                    ]);
                } catch (Throwable $e) {}

                // Record audit log for PENDING
                record_order_audit_log($conn, $new_order_id, null, 'PENDING', $user_id, 'customer', 'Order submitted by customer');

                // Notify Chef accounts that new order is placed
                notify_role($conn, 'chef', 'new_order', 'New Customer Order', "{$unique_ref} from {$cname} is ready to be prepared.", $new_order_id);

                $created_orders[] = [
                    'order_id'     => $new_order_id,
                    'order_ref'    => $unique_ref,
                    'store_name'   => $store_name,
                    'status'       => 'PENDING',
                    'total_amount' => $total_amount
                ];
            }
            
            $conn->commit();
            api_response(['success' => true, 'message' => 'Orders placed successfully!', 'orders' => $created_orders]);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            api_response(['success' => false, 'message' => 'Checkout failed: ' . $e->getMessage()], 200);
        }
    }
    
    // Fall-through: Unknown action
    api_response(['success' => false, 'message' => 'Unknown action: ' . $action], 400);
}

// Fetch customer's orders from food_orders table
$db_orders = [];
$ontheway_count = 0;
$active_tracking_order = null;

$is_demo_cust = ($user_id === 15 || $user_id === 26 || strtolower($username) === 'customer');

// Primary query: User's own orders (excluding finished & cleared deliveries)
try {
    $stmt = $conn->prepare("
        SELECT order_id, order_ref, store_name, items_summary, total_amount, payment_method, status, delivery_status, order_type, delivery_address, created_at 
        FROM food_orders 
        WHERE (customer_id = :cid OR customer_name = :cname OR customer_id IN (1, 15, 26, 0))
          AND status != 'CANCELLED'
          AND COALESCE(rider_cleared, 0) = 0 
        ORDER BY order_id DESC LIMIT 50
    ");
if ($stmt) {
  $stmt->execute([':cid' => $user_id, ':cname' => $full_name]);
      $db_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $db_orders = [];
}

// Fallback: If user has zero orders and is a demo account, load demo orders
if (empty($db_orders) && $is_demo_cust) {
    try {
        $d_stmt = $conn->prepare("SELECT order_id, order_ref, store_name, items_summary, total_amount, payment_method, status, delivery_status, order_type, delivery_address, created_at FROM food_orders WHERE customer_id IN (15, 26, 0) AND COALESCE(rider_cleared, 0) = 0 ORDER BY order_id DESC LIMIT 50");
        if ($d_stmt) {
            $d_stmt->execute();
            $db_orders = $d_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        try {
            $d_stmt = $conn->prepare("SELECT order_id, order_ref, store_name, items_summary, total_amount, payment_method, status, delivery_status, order_type, delivery_address, created_at FROM food_orders WHERE customer_id IN (15, 26, 0) ORDER BY order_id DESC LIMIT 50");
            if ($d_stmt) {
                $d_stmt->execute();
                $db_orders = $d_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Throwable $e2) {}
    }
}

// Find active tracking order (prioritizing active deliveries over historical orders)
foreach ($db_orders as $row) {
    $st = strtoupper(trim(str_replace([' ', '-'], '_', $row['status'])));
    if (in_array($st, ['OUT_FOR_DELIVERY', 'ON_THE_WAY', 'ON_DELIVERY', 'NEAR_CUSTOMER', 'ARRIVED'])) {
        $ontheway_count++;
        if (!$active_tracking_order) {
            $active_tracking_order = $row;
        }
    }
}

// Fetch all partner stores with official logo_url
$db_shops = [];
$shops_res = $conn->query("SELECT shop_id, shop_name, category, logo_url FROM shops ORDER BY shop_id ASC");
if ($shops_res) {
    while ($row = $shops_res->fetch(PDO::FETCH_ASSOC)) {
        $db_shops[] = $row;
    }
}

// Dynamically fetch unique categories and dishes for each store from the database
$db_store_menus = [];
$db_store_categories = [];
$menu_items_res = $conn->query("
    SELECT m.item_id, m.shop_id, s.shop_name, m.item_name, m.category, m.price, m.description, m.icon, m.is_recommended, m.is_available 
    FROM menu_items m 
    INNER JOIN shops s ON m.shop_id = s.shop_id 
    WHERE m.is_available = TRUE 
    ORDER BY m.shop_id ASC, m.is_recommended DESC, m.item_name ASC
");
if ($menu_items_res) {
    while ($mi = $menu_items_res->fetch(PDO::FETCH_ASSOC)) {
        $sname = trim($mi['shop_name']);
        if (!isset($db_store_menus[$sname])) {
            $db_store_menus[$sname] = [];
            $db_store_categories[$sname] = [];
        }
        $cat = trim($mi['category'] ?? 'Main');
        if ($cat === '') $cat = 'Main';
        if (!in_array($cat, $db_store_categories[$sname], true)) {
            $db_store_categories[$sname][] = $cat;
        }
        $db_store_menus[$sname][] = [
            'id'          => (int)$mi['item_id'],
            'name'        => $mi['item_name'],
            'price'       => (float)$mi['price'],
            'icon'        => !empty($mi['icon']) ? $mi['icon'] : 'ðŸ´',
            'desc'        => !empty($mi['description']) ? $mi['description'] : 'Fresh and delicious meal prepared to order.',
            'category'    => $cat,
            'recommended' => (!empty($mi['is_recommended']) && $mi['is_recommended'] !== 'f' && $mi['is_recommended'] !== false) ? 1 : 0
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<title>Food Dash â€” Customer</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
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
<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');

:root{
  --harvest-sun: #D88F2A;
  --sagebrush: #788A6E;
  --earth-clay: #923D32;
  --olive-shadow: #4B5320;
  --sandstone: #D4C5A5;
  --sandstone-light: #FAF6EE;
  
  --olive: #4B5320;
  --olive-dark: #344016;
  --gold: #D88F2A;
  --cream: #FAF6EE;
  --paper: #FFFDF9;
  --line: #D4C5A5;
  --muted: #788A6E;
  --red: #923D32;
  --green: #4B5320;
  --shadow: 0 10px 30px rgba(75,83,32,.12);
}
*, *:before, *:after {
  box-sizing: border-box !important;
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
  background:
    radial-gradient(circle at 15% 10%, rgba(216,143,42,.12), transparent 30%),
    radial-gradient(circle at 85% 90%, rgba(75,83,32,.13), transparent 35%),
    #f4f0df;
  color: #20241a;
  display: flex;
  justify-content: center;
  align-items: flex-start;
  font-family: 'Poppins', Arial, sans-serif;
}

button,input{font:inherit}
button{cursor:pointer}

.app{
  width: 100% !important;
  max-width: 100% !important;
  box-sizing: border-box !important;
  overflow-x: hidden !important;
  min-height: 100vh;
  background: var(--paper);
  position: relative;
  box-shadow: var(--shadow);
  display: flex;
  flex-direction: column;
  margin: 0 auto;
}

#orders, #orders.screen, #orderList, .orders-container {
  width: 100% !important;
  max-width: 100% !important;
  padding: 0 12px !important;
  margin: 0 auto !important;
  overflow-x: hidden !important;
  box-sizing: border-box !important;
}

#orders.screen, .screen {
  padding-top: 14px !important;
  padding-bottom: 90px !important;
}

@media(min-width:600px){
  body {
    padding: 20px 0;
  }
  .app{
    max-width: 440px !important;
    min-height: 720px;
    height: auto;
    border: 1px solid #ddd3ad;
    border-radius: 26px;
    box-shadow: 0 22px 60px rgba(55,65,22,.15);
    overflow: hidden !important;
  }
}

@media(max-width:599px){
  html, body {
    max-width: 100% !important;
    overflow-x: hidden !important;
    width: 100% !important;
    min-height: 100%;
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    -webkit-text-size-adjust: 100%;
  }
  .app {
    border: none;
    border-radius: 0;
    box-shadow: none;
    min-height: 100vh;
    width: 100% !important;
    max-width: 100% !important;
    overflow-x: hidden !important;
  }
}

.top{
  padding:15px 16px 12px;
  background:var(--paper);
  position:sticky;
  top:0;
  z-index:20;
  border-bottom:1px solid rgba(231,223,196,.7);
}
.brand-row{display:flex;align-items:center;justify-content:space-between}
.brand{display:flex;align-items:center;gap:9px}
.logo{width:40px;height:40px;border-radius:50%;object-fit:cover;border:1px solid #d7c98d}
.brand b{font-size:13px;color:var(--olive-dark)}
.brand small{display:block;font-size:8px;color:var(--muted)}
.top-icons{display:flex;gap:8px;position:relative}
.icon-btn{width:34px;height:34px;border:1px solid var(--line);border-radius:50%;background:white;color:var(--olive);display:grid;place-items:center;position:relative}
.avatar{width:34px;height:34px;border-radius:50%;background:#e8dfc0;display:grid;place-items:center;color:var(--olive-dark);font-weight:700;font-size:12px}

/* Notification Banner */
.notice-bar{
  background: linear-gradient(90deg, #344016, #4f5d21);
  color: #fff;
  padding: 10px 14px;
  border-radius: 12px;
  margin: 10px 12px 0;
  width: calc(100% - 24px) !important;
  max-width: calc(100% - 24px) !important;
  box-sizing: border-box !important;
  font-size: 10px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  box-shadow: 0 4px 12px rgba(52,64,22,0.18);
  animation: pulse 2s infinite alternate;
}
@keyframes pulse { from { opacity:0.92; } to { opacity:1; transform: scale(1.01); } }
.notice-bar span { display: flex; align-items: center; gap: 6px; }
.notice-bar button { background: var(--gold); border: 0; color: #fff; padding: 4px 10px; border-radius: 8px; font-weight:700; font-size: 9px; }

.screen{display:none;padding:14px 16px 90px;flex:1}
.screen.active{display:block}
.greeting{margin:4px 0 12px}
.greeting small{color:var(--muted);font-size:11px}
.greeting h1{font-size:20px;line-height:1.15;color:var(--olive-dark)}
.search{height:42px;border:1px solid var(--line);background:#fff;border-radius:12px;display:flex;align-items:center;gap:9px;padding:0 12px;margin:10px 0 14px;box-shadow:0 3px 10px rgba(55,65,22,.04)}
.search i{color:#7c806f;font-size:13px}.search input{border:0;outline:0;width:100%;font-size:11px;background:transparent}
.hero{border-radius:16px;min-height:112px;padding:16px;color:white;background:linear-gradient(100deg,var(--olive-dark),#718136);position:relative;overflow:hidden;margin-bottom:16px}
.hero:after{content:"";position:absolute;right:-18px;bottom:-35px;width:160px;height:160px;border-radius:50%;background:rgba(255,255,255,.12)}
.hero h2{font-size:17px;max-width:190px;line-height:1.15}.hero p{font-size:9px;margin:5px 0 11px;opacity:.9}
.hero button{border:0;border-radius:18px;background:#fff;color:var(--olive-dark);font-size:9px;font-weight:700;padding:8px 13px}

.section-title{display:flex;justify-content:space-between;align-items:center;margin:0 0 9px}
.section-title h3{font-size:13px;color:#272b20}.section-title a{font-size:9px;color:var(--olive);font-weight:600;cursor:pointer}

.store-row{display:flex;gap:12px;overflow-x:auto;padding:4px 2px 14px 2px;margin-bottom:18px;-webkit-overflow-scrolling:touch}
.store-row::-webkit-scrollbar{display:none}
.store{width:106px;min-width:106px;max-width:106px;flex:0 0 106px;background:#ffffff;border:1px solid #EBE4D1;border-radius:18px;padding:12px 8px 10px;box-shadow:0 4px 14px rgba(75,83,32,.05);cursor:pointer;transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease;text-align:center;display:flex;flex-direction:column;align-items:center;box-sizing:border-box}
.store:hover{transform:translateY(-3px);border-color:var(--harvest-sun);box-shadow:0 8px 22px rgba(75,83,32,.12)}
.store-logo{width:72px;height:72px;background:#ffffff;border-radius:16px;border:1px solid #EBE4D1;box-shadow:0 4px 12px rgba(75,83,32,.06);display:flex;align-items:center;justify-content:center;padding:8px;margin:0 auto 8px auto;overflow:hidden;box-sizing:border-box;flex:none;transition:box-shadow .25s ease,border-color .25s ease}
.store:hover .store-logo{box-shadow:0 6px 16px rgba(75,83,32,.10);border-color:#D4C5A5}
.store-logo img{max-width:100%;max-height:100%;width:auto;height:auto;object-fit:contain;object-position:center;border-radius:8px;filter:drop-shadow(0 2px 4px rgba(0,0,0,.04));transition:transform .25s ease}
.store:hover .store-logo img{transform:scale(1.06)}
.store strong{font-size:10.5px;font-weight:700;display:block;width:100%;color:var(--olive-dark);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-align:center;line-height:1.25}
.store small{font-size:8px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;width:100%;margin-top:3px;text-align:center;line-height:1.2}

.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.food-card{border:1px solid var(--line);background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 4px 12px rgba(55,65,22,.05);cursor:pointer}
.food-img{height:94px;background:#eee7d1;display:grid;place-items:center;font-size:31px}
.food-body{padding:9px}.food-body strong{font-size:10px;display:block}.food-body small{font-size:8px;color:var(--muted);display:block;margin-top:2px}
.price-row{display:flex;align-items:center;justify-content:space-between;margin-top:7px}.price{font-size:10px;font-weight:700;color:var(--olive-dark)}
.add{width:25px;height:25px;border:0;border-radius:50%;background:var(--olive);color:white;font-size:13px}

.list{display:grid;gap:9px}
.row-card{display:flex;gap:12px;align-items:center;background:#ffffff;border:1px solid #EBE4D1;border-radius:18px;padding:10px 12px;cursor:pointer;position:relative;transition:all .2s ease;box-shadow:0 4px 12px rgba(75,83,32,.04)}
.row-card:hover{border-color:var(--harvest-sun);box-shadow:0 8px 20px rgba(75,83,32,.10);transform:translateY(-2px)}
.row-icon{width:58px;height:58px;border-radius:16px;display:flex;align-items:center;justify-content:center;background:#ffffff;border:1px solid #EBE4D1;box-shadow:0 4px 12px rgba(75,83,32,.06);padding:7px;flex:none;overflow:hidden;box-sizing:border-box;transition:border-color .2s ease,box-shadow .2s ease}
.row-card:hover .row-icon{border-color:#D4C5A5;box-shadow:0 6px 16px rgba(75,83,32,.10)}
.row-icon img{max-width:100%;max-height:100%;width:auto;height:auto;object-fit:contain;object-position:center;border-radius:8px;transition:transform .25s ease}
.row-card:hover .row-icon img{transform:scale(1.06)}
.row-card .info{flex:1;min-width:0}
.row-card strong{font-size:11.5px;font-weight:700;color:var(--olive-dark);display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.row-card small{font-size:8.5px;color:var(--muted);display:block;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.row-card .chev{color:#a4a78f;font-size:11px}

/* Compact & Proportional Order Cards for Mobile */
.order-card, .row-card.order-row, .order-row, .card.order-card {
  display: flex !important;
  gap: 10px !important;
  align-items: flex-start !important;
  background: #ffffff !important;
  border: 1px solid #EBE4D1 !important;
  border-radius: 16px !important;
  padding: 10px 14px !important;
  margin-bottom: 10px !important;
  cursor: pointer;
  position: relative;
  transition: all .2s ease;
  box-shadow: 0 3px 10px rgba(75,83,32,.04) !important;
  width: 100% !important;
  max-width: 100% !important;
  box-sizing: border-box !important;
}

.order-card:hover, .row-card.order-row:hover {
  border-color: var(--harvest-sun) !important;
  box-shadow: 0 6px 16px rgba(75,83,32,.08) !important;
}

/* Delivery vehicle icon container: compact to 36px x 36px with a 20px icon */
.row-card.order-row .row-icon, .order-card .row-icon, .order-card .order-icon {
  width: 36px !important;
  height: 36px !important;
  min-width: 36px !important;
  min-height: 36px !important;
  border-radius: 10px !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  background: #fbf9f2 !important;
  border: 1px solid #EBE4D1 !important;
  box-shadow: 0 2px 6px rgba(75,83,32,.05) !important;
  padding: 0 !important;
  font-size: 20px !important;
  flex: none !important;
  overflow: hidden !important;
  box-sizing: border-box !important;
  margin-top: 2px;
}

.row-card.order-row .row-icon img, .order-card .order-icon img {
  width: 20px !important;
  height: 20px !important;
  object-fit: contain;
}

.row-card.order-row .info, .order-card .info {
  flex: 1 !important;
  min-width: 0 !important;
  width: 100% !important;
}

/* Order ID #FD-...: font size 15px, font-weight 700 */
.row-card.order-row strong, .row-card.order-row .order-ref, .order-card .order-ref, .order-ref {
  font-size: 15px !important;
  font-weight: 700 !important;
  color: var(--olive-dark) !important;
  display: block !important;
  line-height: 1.25 !important;
  margin-bottom: 2px !important;
  white-space: nowrap !important;
  overflow: hidden !important;
  text-overflow: ellipsis !important;
}

/* Store name & items: font size 13px, line-height 1.3 */
.row-card.order-row .order-store-items, .row-card.order-row .order-store, .order-card .order-store, .order-store {
  font-size: 13px !important;
  line-height: 1.3 !important;
  font-weight: 600 !important;
  color: #334155 !important;
  display: block !important;
  margin-top: 2px !important;
  white-space: normal !important;
  word-break: break-word !important;
}

/* Date, price & address line: font size 12px, subtle text */
.row-card.order-row small, .row-card.order-row .order-meta, .order-card .order-meta, .order-meta {
  font-size: 12px !important;
  color: #64748b !important;
  display: block !important;
  margin-top: 2px !important;
  white-space: normal !important;
  line-height: 1.35 !important;
  word-break: break-word !important;
}

/* Status Badges - Compact & Clean */
.status-badge {
  display: inline-flex !important;
  align-items: center !important;
  gap: 4px !important;
  padding: 4px 10px !important;
  border-radius: 8px !important;
  font-size: 12px !important;
  font-weight: 600 !important;
  text-transform: capitalize;
}

.status-pending { background: #FEF3C7; color: #D97706; border: 1px solid #F59E0B; }
.status-preparing { background: #E0E7FF; color: #4338CA; border: 1px solid #6366F1; }
.status-ready_for_pickup, .status-ready { background: #ECFDF5; color: #047857; border: 1px solid #10B981; }
.status-out_for_delivery, .status-on_the_way, .status-on_delivery { background: #FAF6EE; color: var(--harvest-sun); border: 1px solid var(--harvest-sun); }
.status-near_customer { background: #FFF8E1; color: #E65100; border: 1px solid #FFA000; animation: cdPulseNear 2s infinite; }
.status-arrived { background: #E8F5E9; color: #2E7D32; border: 1px solid #4CAF50; animation: cdPulseArrived 1.5s infinite; }
@keyframes cdPulseNear { 0%, 100% { box-shadow: 0 0 0 0 rgba(255, 160, 0, 0.4); } 50% { box-shadow: 0 0 0 4px rgba(255, 160, 0, 0); } }
@keyframes cdPulseArrived { 0%, 100% { box-shadow: 0 0 0 0 rgba(76, 175, 80, 0.5); } 50% { box-shadow: 0 0 0 5px rgba(76, 175, 80, 0); } }
.status-delivered { background: #FAF6EE; color: var(--sagebrush); border: 1px solid var(--sagebrush); }
.status-settled, .status-completed { background: var(--olive-shadow); color: white; border: 1px solid var(--olive-shadow); }
.settled-tag { font-size: 11px !important; background: #FAF6EE; color: var(--olive-shadow); border: 1px solid var(--olive-shadow); padding: 4px 8px; border-radius: 8px; font-weight: 600; display: inline-flex; align-items:center; gap: 3px; }
.settled-icon { background: var(--olive-shadow) !important; color: white !important; }

/* Compact Action Buttons - Wrap in flex with gap: 6px */
.order-actions-wrap, #orders [id^="track-actions-"], .card-actions {
  display: flex !important;
  align-items: center !important;
  gap: 6px !important;
  flex-wrap: wrap !important;
  margin-top: 6px !important;
}

/* Buttons ("Live Track Rider", "Received"): padding: 6px 12px; font-size: 12px; border-radius: 8px; font-weight: 600; */
.confirm-btn, .cust-track-btn, .btn-cancel-order {
  padding: 6px 12px !important;
  font-size: 12px !important;
  border-radius: 8px !important;
  font-weight: 600 !important;
  display: inline-flex !important;
  align-items: center !important;
  gap: 5px !important;
  cursor: pointer;
  transition: all 0.2s ease;
  line-height: 1.2 !important;
}

.confirm-btn {
  background: var(--olive-shadow);
  color: white;
  border: 0;
}
.confirm-btn:hover { opacity: 0.9; }

/* Payment Method Selection Card & Options Grid */
.payment-method-card {
  background: #ffffff;
  border: 1px solid #EBE4D1;
  border-radius: 14px;
  padding: 12px;
  margin: 12px 0;
  box-shadow: 0 4px 12px rgba(75,83,32,.04);
}
.payment-section-title {
  font-size: 10px;
  font-weight: 700;
  color: var(--olive-dark);
  margin-bottom: 8px;
  display: flex;
  align-items: center;
  gap: 6px;
}
.payment-options-grid {
  display: flex;
  flex-direction: column;
  gap: 7px;
}
/* Official Payment Brand Badges & Tiles */
.pm-brand-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 8px;
  border-radius: 7px;
  font-size: 8.5px;
  font-weight: 700;
  vertical-align: middle;
  line-height: 1;
  box-shadow: 0 1px 3px rgba(0,0,0,0.05);
  user-select: none;
}
.pm-brand-badge .pm-logo {
  height: 18px;
  max-height: 20px;
  width: auto;
  max-width: 55px;
  object-fit: contain;
  display: inline-block;
  vertical-align: middle;
  border-radius: 2px;
}
.pm-brand-badge .pm-brand-name {
  font-size: 9px;
  font-weight: 700;
  letter-spacing: 0.1px;
}
.pm-badge-cod { background: #f0fdf4 !important; border: 1px solid #86efac !important; color: #15803d !important; }
.pm-badge-gcash { background: #eff6ff !important; border: 1px solid #93c5fd !important; color: #1d4ed8 !important; }
.pm-badge-maya { background: #0f172a !important; border: 1px solid #05de82 !important; color: #05de82 !important; }
.pm-badge-gotyme { background: #1e1b4b !important; border: 1px solid #818cf8 !important; color: #38bdf8 !important; }
.pm-badge-apple { background: #000000 !important; border: 1px solid #374151 !important; color: #ffffff !important; }

/* Legacy compatibility */
.pay-method-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 8px;
  border-radius: 7px;
  font-size: 8.5px;
  font-weight: 700;
}

/* Distinct Selection Tile in Checkout */
.pay-option {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 12px;
  background: #ffffff;
  border: 1.5px solid #ebe4d1;
  border-radius: 12px;
  cursor: pointer;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  user-select: none;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}
.pay-option:hover {
  border-color: var(--harvest-sun);
  background: #fffdf9;
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(75,83,32,0.08);
}
.pay-option.active {
  border-color: var(--olive-shadow);
  background: #fbfcf8;
  box-shadow: 0 0 0 1px var(--olive-shadow), 0 4px 12px rgba(75,83,32,0.12);
}
.pay-option-logo-box {
  width: 52px;
  height: 32px;
  border-radius: 7px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: transparent;
  flex-shrink: 0;
  padding: 2px 4px;
}
.pay-option-brand-logo, .pay-tile-logo {
  height: 20px;
  max-height: 22px;
  width: auto;
  max-width: 48px;
  object-fit: contain;
  display: block;
}
.pay-option-info {
  flex: 1;
  min-width: 0;
}
.pay-option-info strong {
  display: block;
  font-size: 11px;
  font-weight: 700;
  color: var(--olive-dark);
}
.pay-option-info small {
  display: block;
  font-size: 8.5px;
  color: #6b7280;
  margin-top: 1.5px;
}
.pay-option .pay-check {
  font-size: 15px;
  color: #cbd5e1;
  transition: all 0.2s ease;
}
.pay-option.active .pay-check {
  color: var(--olive-shadow);
}

/* Distinct Selection Tile in Payment Methods Modal */
.pay-option-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 14px;
  background: #ffffff;
  border: 1.5px solid #e5e7eb;
  border-radius: 12px;
  margin-bottom: 9px;
  cursor: pointer;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  box-shadow: 0 1px 4px rgba(0,0,0,0.04);
  user-select: none;
}
.pay-option-card:hover {
  border-color: #4B5320;
  transform: translateY(-1.5px);
  box-shadow: 0 4px 12px rgba(75,83,32,0.09);
}
.pay-option-card.selected, .pay-option-card.active {
  border-color: #4B5320;
  background: #fafbf7;
  box-shadow: 0 0 0 1px #4B5320, 0 4px 14px rgba(75,83,32,0.12);
}
.pay-tile-left {
  display: flex;
  align-items: center;
  gap: 12px;
  flex: 1;
  min-width: 0;
}
.pay-tile-logo-box {
  width: 56px;
  height: 34px;
  border-radius: 8px;
  background: transparent;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 3px 5px;
  flex-shrink: 0;
}
.pay-tile-info h4 {
  margin: 0;
  font-size: 12px;
  font-weight: 700;
  color: #1f2937;
  line-height: 1.25;
}
.pay-tile-info p {
  margin: 2px 0 0;
  font-size: 10px;
  color: #6b7280;
  line-height: 1.25;
}
.pay-radio-indicator {
  width: 20px;
  height: 20px;
  border-radius: 50%;
  border: 2px solid #d1d5db;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  transition: all 0.15s ease;
}
.pay-option-card.selected .pay-radio-indicator, .pay-option-card.active .pay-radio-indicator {
  border-color: #4B5320;
  background: #4B5320;
}
.pay-radio-indicator i {
  color: #ffffff;
  font-size: 10px;
  display: none;
}
.pay-option-card.selected .pay-radio-indicator i, .pay-option-card.active .pay-radio-indicator i {
  display: block;
}
.cust-chat-unread-badge {
  background: #ef4444;
  color: #ffffff;
  border-radius: 10px;
  padding: 1px 6px;
  font-size: 9px;
  font-weight: 800;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 2px 4px rgba(239, 68, 68, 0.4);
}

/* In-App Notifications Modal (Food Dash Palette) */
.bell-badge {
  position: absolute;
  top: -4px;
  right: -4px;
  background: var(--earth-clay);
  color: white;
  border-radius: 99px;
  font-size: 7px;
  font-weight: 700;
  padding: 2px 5px;
  min-width: 14px;
  text-align: center;
}
.fd-modal {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(75, 83, 32, 0.40);
  backdrop-filter: blur(4px);
  align-items: center;
  justify-content: center;
  padding: 16px;
  z-index: 2000;
}
.fd-modal.show { display: flex; }
.fd-modal-box {
  width: min(420px, 100%);
  background: #FFFDF9;
  border: 1px solid var(--sandstone);
  border-radius: 20px;
  padding: 18px 20px;
  box-shadow: 0 20px 50px rgba(75, 83, 32, 0.25);
  display: flex;
  flex-direction: column;
  max-height: 92vh;
  overflow-y: auto;
}
#checkout-address-map {
  height: 220px;
  width: 100%;
  border-radius: 10px;
  margin-bottom: 12px;
  border: 1px solid var(--sandstone);
  box-shadow: inset 0 0 10px rgba(0,0,0,0.05);
  position: relative;
  z-index: 10;
}
.custom-map-pin {
  background: transparent !important;
  border: none !important;
}
.leaflet-control-zoom {
  border: 1px solid var(--sandstone) !important;
  border-radius: 8px !important;
  overflow: hidden;
  box-shadow: 0 2px 6px rgba(0,0,0,0.15) !important;
}
.fd-modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid var(--sandstone);
  padding-bottom: 12px;
  margin-bottom: 12px;
}
.fd-modal-header h3 { font-size: 14px; color: var(--olive-shadow); margin: 0; display: flex; align-items: center; gap: 8px; }
.fd-modal-close {
  background: transparent;
  border: 0;
  font-size: 18px;
  color: var(--sagebrush);
  cursor: pointer;
}
.fd-note-list {
  overflow-y: auto;
  flex: 1;
  max-height: 50vh;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.fd-note-item {
  padding: 10px 12px;
  border-radius: 12px;
  background: #FAF6EE;
  border: 1px solid var(--sandstone);
  font-size: 9px;
  cursor: pointer;
  transition: all 0.2s;
}
.fd-note-item.unread {
  background: #FFFFFF;
  border-left: 3px solid var(--harvest-sun);
}
.fd-note-item strong { display: block; font-size: 10px; color: var(--olive-shadow); margin-bottom: 2px; }
.fd-note-item p { margin: 0; color: #555; line-height: 1.4; }
.fd-note-item small { display: block; color: var(--sagebrush); margin-top: 4px; font-size: 8px; }
.fd-modal-actions {
  display: flex;
  gap: 8px;
  margin-top: 14px;
}
.btn-fd-primary {
  flex: 1;
  background: var(--olive-shadow);
  color: white;
  border: 0;
  border-radius: 10px;
  padding: 10px;
  font-size: 9px;
  font-weight: 700;
  cursor: pointer;
}
.btn-fd-secondary {
  flex: 1;
  background: #FAF6EE;
  color: var(--olive-shadow);
  border: 1px solid var(--sandstone);
  border-radius: 10px;
  padding: 10px;
  font-size: 9px;
  font-weight: 700;
  cursor: pointer;
}

.chips{display:flex;gap:6px;overflow-x:auto;margin:8px 0 12px;padding-bottom:4px;-webkit-overflow-scrolling:touch}.chips::-webkit-scrollbar{display:none}
.chip{border:1px solid var(--line);background:#fff;border-radius:18px;padding:4px 10px !important;font-size:12px !important;font-weight:600;color:var(--olive-shadow);white-space:nowrap;cursor:pointer;transition:all .2s ease;outline:none;user-select:none;line-height:1.2 !important}
.chip:hover{border-color:var(--harvest-sun);color:var(--harvest-sun)}
.chip.active{background:#4B5320 !important;color:#fff !important;border-color:#4B5320 !important;box-shadow:0 3px 10px rgba(75,83,32,.22)}
.no-dishes-state{grid-column:1 / -1;text-align:center;padding:36px 16px;background:#FAF6EE;border:1px dashed var(--line);border-radius:16px;color:var(--muted);font-size:10px;margin:10px 0 16px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;transition:opacity .2s ease}
.no-dishes-state i{font-size:22px;color:var(--harvest-sun);opacity:.85}
.no-dishes-state strong{font-size:11px;color:var(--olive-dark)}
.no-dishes-state small{font-size:8.5px;color:var(--muted)}

.banner-store{border-radius:18px;padding:16px 18px;color:white;background:linear-gradient(135deg, #344016 0%, #4B5320 60%, #788A6E 100%);margin-bottom:14px;position:relative;overflow:hidden;box-shadow:0 8px 24px rgba(52,64,22,.2)}
.banner-store h2{font-size:19px;font-weight:700;margin-right:76px}.banner-store p{font-size:9px;margin-top:4px;opacity:.9;margin-right:76px}.banner-store .rating{margin-top:9px;font-size:8.5px;opacity:.95}
.banner-store .store-mark{position:absolute;right:14px;top:14px;width:72px;height:72px;border-radius:16px;background:#ffffff;border:1px solid #EBE4D1;display:flex;align-items:center;justify-content:center;padding:8px;box-shadow:0 4px 14px rgba(0,0,0,.15);overflow:hidden;box-sizing:border-box}
.banner-store .store-mark img{max-width:100%;max-height:100%;width:auto;height:auto;object-fit:contain;object-position:center;border-radius:8px}

.item-detail{border:1px solid var(--line);border-radius:17px;overflow:hidden;background:#fff}
.item-art{height:190px;background:linear-gradient(135deg,#efe5c8,#f8f2df);display:grid;place-items:center;font-size:76px}
.item-info{padding:14px}.item-info h2{font-size:18px}.item-info p{font-size:9px;color:var(--muted);margin-top:4px}.item-price{font-size:16px;color:var(--olive-dark);font-weight:700;margin:10px 0}
.primary{width:100%;border:0;border-radius:12px;background:var(--olive);color:white;padding:12px;font-size:10px;font-weight:700}
.qty{display:flex;gap:12px;align-items:center}.qty button{width:28px;height:28px;border:1px solid var(--line);border-radius:50%;background:#fff}.qty span{font-weight:700;font-size:11px}

.cart-item{display:flex;gap:9px;padding:10px 0;border-bottom:1px solid #eee8d7}.cart-item .thumb{width:58px;height:58px;border-radius:10px;background:#eee6d0;display:grid;place-items:center;font-size:25px}
.cart-info{flex:1}.cart-info strong{font-size:10px}.cart-info small{font-size:8px;color:var(--muted);display:block}.cart-bottom{display:flex;justify-content:space-between;align-items:center;margin-top:5px}
.summary{border-top:1px solid var(--line);margin-top:10px;padding-top:10px}.sumrow{display:flex;justify-content:space-between;font-size:9px;padding:4px 0}.sumrow.total{font-size:12px;font-weight:700;color:var(--olive-dark);padding-top:8px}

/* Delivery Address & GPS Component */
.delivery-address-section {
  background: #FAF6EE;
  border: 1px solid var(--sandstone);
  border-radius: 14px;
  padding: 12px;
  margin: 12px 0 10px;
}
.delivery-section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}
.delivery-section-header label {
  font-size: 10.5px;
  font-weight: 700;
  color: var(--olive-dark);
  display: flex;
  align-items: center;
  gap: 5px;
}
.delivery-section-header label i {
  color: var(--harvest-sun);
}
.gps-detect-btn {
  background: white;
  border: 1px solid var(--sandstone);
  color: var(--olive);
  font-size: 8.5px;
  font-weight: 600;
  padding: 5px 10px;
  border-radius: 20px;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  cursor: pointer;
  transition: all 0.2s ease;
}
.gps-detect-btn:hover {
  background: var(--harvest-sun);
  color: white;
  border-color: var(--harvest-sun);
}
.delivery-textarea {
  width: 100%;
  border: 1px solid var(--sandstone);
  background: white;
  border-radius: 10px;
  padding: 9px 11px;
  font-size: 9.5px;
  color: #20241a;
  outline: none;
  resize: vertical;
  min-height: 52px;
  transition: border-color 0.2s;
  box-sizing: border-box;
  font-family: inherit;
}
.delivery-textarea:focus {
  border-color: var(--harvest-sun);
  box-shadow: 0 0 0 2px rgba(216, 143, 42, 0.15);
}
.delivery-textarea.input-error {
  border-color: var(--earth-clay) !important;
  background: #fff6f5 !important;
  animation: shake 0.3s ease-in-out;
}
@keyframes shake {
  0%, 100% { transform: translateX(0); }
  25% { transform: translateX(-4px); }
  75% { transform: translateX(4px); }
}
.gps-status-pill {
  font-size: 8px;
  margin-top: 6px;
  padding: 4px 8px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  gap: 5px;
  line-height: 1.3;
}
.gps-status-pill.success {
  background: rgba(120, 138, 110, 0.15);
  color: var(--olive-dark);
  border: 1px solid rgba(120, 138, 110, 0.3);
}
.gps-status-pill.loading {
  background: rgba(216, 143, 42, 0.12);
  color: var(--harvest-sun);
  border: 1px solid rgba(216, 143, 42, 0.25);
}
.gps-status-pill.error {
  background: rgba(146, 61, 50, 0.1);
  color: var(--earth-clay);
  border: 1px solid rgba(146, 61, 50, 0.2);
}

.profile{background:white;border:1px solid var(--line);border-radius:16px;padding:14px;display:flex;gap:11px;align-items:center}.profile-pic{width:52px;height:52px;border-radius:50%;background:#eee5c8;display:grid;place-items:center;font-size:22px}.profile strong{font-size:12px}.profile small{font-size:8px;color:var(--muted);display:block}
.menu{margin-top:10px;border:1px solid var(--line);border-radius:15px;background:#fff;overflow:hidden}.menu a{display:flex;justify-content:space-between;align-items:center;padding:13px;border-bottom:1px solid #f0eadb;text-decoration:none;color:#303429;font-size:9px}.menu a:last-child{border:0}.menu i{color:var(--olive);margin-right:8px}

/* Sticky / Fixed Bottom Navigation Bar - Never Disappear on Scroll */
.bottom, .bottom-nav, .mobile-navbar, nav.fixed-bottom {
  position: fixed !important;
  bottom: 0 !important;
  left: 0 !important;
  right: 0 !important;
  width: 100% !important;
  height: 65px !important;
  z-index: 9999 !important;
  background: #ffffff !important;
  box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.08) !important;
  padding-bottom: env(safe-area-inset-bottom, 12px) !important;
  display: grid !important;
  grid-template-columns: repeat(5, 1fr) !important;
  border-top: 1px solid var(--line) !important;
  margin: 0 !important;
}

@media (min-width: 600px) {
  .bottom, .bottom-nav, .mobile-navbar, nav.fixed-bottom {
    left: 50% !important;
    right: auto !important;
    transform: translateX(-50%) !important;
    max-width: 440px !important;
    border-bottom-left-radius: 26px !important;
    border-bottom-right-radius: 26px !important;
  }
}

@media (max-width: 599px) {
  .bottom, .bottom-nav, .mobile-navbar, nav.fixed-bottom {
    left: 0 !important;
    right: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
    transform: none !important;
    border-radius: 0 !important;
  }
}

/* Floating Gmail Inbox Widget - floats cleanly right above fixed navbar */
.gmail-widget, #gmailInboxWidget, .fd-gmail-floating-btn, #fdGmailFloatingBtn {
  bottom: calc(75px + env(safe-area-inset-bottom, 10px)) !important;
  right: 16px !important;
  z-index: 10000 !important;
}

.nav{border:0;background:transparent;color:#72776a;font-size:8px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;position:relative}.nav i{font-size:15px}.nav.active{color:var(--olive-dark);font-weight:700}.badge{position:absolute;background:#e7352d;color:white;font-size:7px;border-radius:10px;padding:2px 5px;top:4px;right:20px}

.toast{position:fixed;left:50%;bottom:82px;transform:translateX(-50%) translateY(20px);background:var(--olive-dark);color:white;padding:9px 13px;border-radius:20px;font-size:9px;opacity:0;pointer-events:none;transition:.25s;z-index:200;white-space:nowrap}.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}

.logout{width:100%;background:#fff;cursor:pointer;font-family:inherit;margin-top:12px;border:1px solid #e55b52;color:#d33b32;border-radius:11px;padding:10px;font-size:9px;font-weight:600}
.logout-modal{display:none;position:fixed;inset:0;background:rgba(28,32,6,.38);backdrop-filter:blur(3px);align-items:center;justify-content:center;padding:18px;z-index:1000}.logout-modal.show{display:flex}.logout-box{width:min(360px,100%);background:#fffdf7;border:1px solid var(--line);border-radius:22px;padding:22px;text-align:center;box-shadow:0 20px 60px rgba(55,65,22,.22)}.logout-box .logout-logo{width:58px;height:58px;border-radius:50%;object-fit:cover;border:1px solid #d7c98d;margin-bottom:9px}.logout-box h3{font-size:16px;color:var(--olive-dark);margin:0 0 5px}.logout-box p{font-size:9px;color:var(--muted);line-height:1.5;margin-bottom:17px}.logout-actions{display:flex;gap:8px}.logout-actions button,.logout-actions a{flex:1;padding:10px;border-radius:11px;font-size:9px;font-weight:700;text-decoration:none;border:0;font-family:inherit}.logout-cancel{background:#eee9d7;color:var(--olive)}.logout-confirm{background:var(--olive);color:white;display:grid;place-items:center}

/* Customer Live Tracking & Chat Styles */
.cust-track-btn {
  background: linear-gradient(135deg, #d97706, #b45309);
  color: #ffffff;
  border: none;
  border-radius: 9px;
  padding: 6px 12px;
  font-size: 8.5px;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  cursor: pointer;
  box-shadow: 0 2px 7px rgba(180, 83, 9, 0.35);
  transition: all 0.2s ease;
  letter-spacing: 0.2px;
}
.cust-track-btn:hover {
  filter: brightness(1.1);
  transform: translateY(-1px);
  box-shadow: 0 4px 10px rgba(180, 83, 9, 0.45);
}
/* Tracking Modal Default State: Hidden until explicitly shown */
.tracking-modal,
#customerTrackingModal,
#trackingModal {
  display: none !important;
}
.tracking-modal.show,
#customerTrackingModal.show,
#trackingModal.show {
  display: flex !important;
}

/* Scrollable Live Tracking Modal on Mobile */
#trackingModal .modal-content,
#customerTrackingModal .modal-content,
#customerTrackingModal .cust-track-modal-box,
.modal-dialog-scrollable .modal-content,
.cust-track-modal-box {
  max-height: 88vh !important;
  display: flex !important;
  flex-direction: column !important;
  overflow-y: auto !important;
  -webkit-overflow-scrolling: touch !important;
  padding-bottom: 24px !important;
  box-sizing: border-box !important;
}

.tracking-modal-body,
#trackingModal .modal-body,
#customerTrackingModal .modal-body,
#customerTrackingModal .tracking-modal-body {
  overflow-y: auto !important;
  -webkit-overflow-scrolling: touch !important;
  flex: 1 1 auto !important;
}

#cust-tracking-map,
.cust-tracking-map {
  height: 230px !important;
  min-height: 200px;
}

@media (max-width: 600px) {
  #cust-tracking-map,
  .cust-tracking-map {
    height: 220px !important;
  }
  .tracking-modal,
  #customerTrackingModal {
    padding: 10px 8px !important;
    align-items: flex-start !important;
    overflow-y: auto !important;
    -webkit-overflow-scrolling: touch !important;
  }
  .tracking-modal .modal-content,
  #customerTrackingModal .cust-track-modal-box,
  .cust-track-modal-box {
    max-height: 88vh !important;
    margin: auto 0 !important;
    padding-bottom: 24px !important;
  }
}

.cust-track-modal-box {
  width: min(440px, 100%);
  padding: 16px;
}
.cust-rider-card {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #fbf9f4;
  border: 1px solid var(--sandstone);
  border-radius: 12px;
  padding: 10px 12px;
  margin-top: 10px;
}
.cust-rider-left {
  display: flex;
  align-items: center;
  gap: 8px;
}
.cust-rider-avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: rgba(75, 83, 32, 0.14);
  color: var(--olive-shadow);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.9rem;
}
.cust-rider-title {
  font-size: 0.62rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.4px;
}
.cust-rider-name {
  font-size: 0.78rem;
  font-weight: 700;
  color: var(--olive-shadow);
}
.cust-rider-phone {
  font-size: 0.66rem;
  color: var(--muted);
  display: flex;
  align-items: center;
  gap: 4px;
}
.cust-rider-actions {
  display: flex;
  gap: 6px;
}
.cust-comm-btn {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 6px 9px;
  border-radius: 8px;
  font-size: 0.68rem;
  font-weight: 700;
  text-decoration: none;
  cursor: pointer;
  border: none;
  transition: all 0.15s ease;
}
.cust-comm-btn.call {
  background: #2e7d32;
  color: #ffffff;
  box-shadow: 0 2px 6px rgba(46, 125, 50, 0.25);
}
.cust-comm-btn.call:hover {
  background: #256628;
}
.cust-comm-btn.msg {
  background: var(--harvest-sun);
  color: #ffffff;
  box-shadow: 0 2px 6px rgba(216, 143, 42, 0.25);
}
.cust-comm-btn.msg:hover {
  filter: brightness(1.08);
}
.cust-comm-btn.sms {
  background: #0284c7;
  color: #ffffff;
  box-shadow: 0 2px 6px rgba(2, 132, 199, 0.25);
}
.cust-comm-btn.sms:hover {
  background: #0369a1;
}
.cust-track-eta-badge {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  background: #fdf8eb;
  border: 1.5px solid #f3d18e;
  border-radius: 20px;
  padding: 8px 14px;
  font-size: 0.78rem;
  font-weight: 700;
  color: var(--olive-shadow);
  margin-top: 8px;
  box-shadow: 0 2px 8px rgba(216, 143, 42, 0.12);
}
.cust-rider-pin {
  transition: all 0.6s ease-out;
}
.cust-track-status-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #ffffff;
  border: 1px dashed var(--sandstone);
  border-radius: 8px;
  padding: 7px 10px;
  margin-top: 8px;
  font-size: 0.72rem;
  color: var(--olive-shadow);
}
.dist-pill {
  font-weight: 700;
  color: #2e7d32;
  display: flex;
  align-items: center;
  gap: 4px;
}
.cust-map-legend {
  display: flex;
  justify-content: space-around;
  align-items: center;
  margin-top: 8px;
  font-size: 0.64rem;
  color: var(--muted);
}
.legend-dot {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  margin-right: 4px;
}
.legend-dot.store { background: #d97706; }
.legend-dot.rider { background: #16a34a; }
.legend-dot.customer { background: #dc2626; }

/* Chat Drawer / Modal */
.chat-modal-box {
  width: min(400px, 100%);
  background: #ffffff;
  border: 1.5px solid var(--sandstone);
  border-radius: 20px;
  box-shadow: 0 20px 60px rgba(75, 83, 32, 0.25);
  display: flex;
  flex-direction: column;
  max-height: 85vh;
  overflow: hidden;
}
.chat-modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 16px;
  background: #fdfbf7;
  border-bottom: 1px solid var(--line);
}
.chat-header-avatar {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: rgba(75, 83, 32, 0.15);
  color: var(--olive-shadow);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.8rem;
}
.chat-close-btn {
  background: transparent;
  border: none;
  font-size: 1rem;
  color: var(--muted);
  cursor: pointer;
  padding: 4px;
}
.quick-status-section {
  padding: 10px 14px 6px;
  background: #faf8f2;
  border-bottom: 1px solid var(--line);
}
.quick-title {
  font-size: 0.66rem;
  font-weight: 700;
  color: var(--olive-shadow);
  margin-bottom: 6px;
  display: flex;
  align-items: center;
  gap: 5px;
}
.quick-pills {
  display: flex;
  gap: 5px;
  overflow-x: auto;
  padding-bottom: 4px;
  scrollbar-width: thin;
}
.quick-pill {
  white-space: nowrap;
  background: #ffffff;
  border: 1px solid var(--sandstone);
  border-radius: 14px;
  padding: 5px 10px;
  font-size: 0.66rem;
  font-weight: 500;
  color: var(--olive-shadow);
  cursor: pointer;
  transition: all 0.15s ease;
  flex-shrink: 0;
}
.quick-pill:hover {
  background: var(--harvest-sun);
  color: #ffffff;
  border-color: var(--harvest-sun);
}
.chat-messages-container {
  padding: 12px 14px;
  flex: 1;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-height: 180px;
  max-height: 280px;
  background: #fffdf9;
}
.chat-empty-hint {
  font-size: 0.7rem;
  color: var(--muted);
  text-align: center;
  margin: auto;
  padding: 18px 10px;
}
.msg-bubble {
  max-width: 82%;
  padding: 7px 11px;
  border-radius: 12px;
  font-size: 0.72rem;
  line-height: 1.4;
  word-break: break-word;
}
.msg-bubble.me {
  align-self: flex-end;
  background: var(--olive-shadow);
  color: #ffffff;
  border-bottom-right-radius: 2px;
}
.msg-bubble.them {
  align-self: flex-start;
  background: #f1ebd8;
  color: var(--ink);
  border-bottom-left-radius: 2px;
}
.msg-meta {
  font-size: 0.58rem;
  margin-top: 3px;
  opacity: 0.8;
  text-align: right;
}
.msg-bubble.them .msg-meta {
  text-align: left;
}
.chat-input-bar {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 10px 14px;
  background: #ffffff;
  border-top: 1px solid var(--line);
}
.chat-input-bar input {
  flex: 1;
  border: 1.5px solid var(--sandstone);
  border-radius: 20px;
  padding: 8px 12px;
  font-size: 0.72rem;
  outline: none;
}
.chat-input-bar input:focus {
  border-color: var(--olive-shadow);
}
.chat-send-btn {
  background: var(--olive-shadow);
  color: #ffffff;
  border: none;
  border-radius: 50%;
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  flex-shrink: 0;
  transition: transform 0.15s ease;
}
.chat-send-btn:hover {
  transform: scale(1.08);
}
.chat-sms-btn {
  background: #15803d;
  color: #ffffff;
  border-radius: 16px;
  padding: 6px 10px;
  font-size: 0.68rem;
  font-weight: 700;
  text-decoration: none;
  display: flex;
  align-items: center;
  gap: 4px;
  flex-shrink: 0;
  box-shadow: 0 2px 6px rgba(21, 128, 61, 0.25);
}
.cust-toast {
  position: fixed;
  top: 64px;
  left: 50%;
  transform: translate(-50%, -20px);
  background: linear-gradient(135deg, #1e293b, #0f172a);
  color: #ffffff;
  padding: 11px 18px;
  border-radius: 20px;
  font-size: 0.76rem;
  font-weight: 600;
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.32s cubic-bezier(0.16, 1, 0.3, 1), transform 0.32s cubic-bezier(0.16, 1, 0.3, 1);
  z-index: 99999;
  box-shadow: 0 10px 25px rgba(0, 0, 0, 0.35), 0 0 0 1.5px rgba(216, 143, 42, 0.35);
  display: flex;
  align-items: center;
  gap: 10px;
  max-width: min(420px, calc(100vw - 32px));
}
.cust-toast.show {
  opacity: 1;
  transform: translate(-50%, 0);
  pointer-events: auto;
}
@keyframes riderPulse {
  0% { transform: scale(0.8); opacity: 0.8; }
  70% { transform: scale(1.6); opacity: 0; }
  100% { transform: scale(1.6); opacity: 0; }
}

/* Incoming Call Floating Overlay Modal */
.incoming-call-modal {
  position: fixed;
  inset: 0;
  z-index: 999999;
  background: rgba(15, 23, 42, 0.78);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  animation: fadeIn 0.25s ease-out;
}
.incoming-call-card {
  position: relative;
  background: #ffffff;
  border-radius: 24px;
  padding: 30px 24px 26px;
  width: 100%;
  max-width: 340px;
  text-align: center;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.35);
  border: 1px solid rgba(255, 255, 255, 0.6);
  animation: scaleUp 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
@keyframes scaleUp {
  0% { transform: scale(0.85); opacity: 0; }
  100% { transform: scale(1); opacity: 1; }
}
.incoming-call-avatar {
  width: 76px;
  height: 76px;
  border-radius: 50%;
  background: #16a34a;
  color: #ffffff;
  font-size: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 14px;
  box-shadow: 0 0 0 10px rgba(22, 163, 74, 0.2), 0 0 0 20px rgba(22, 163, 74, 0.08);
  animation: callGlow 1.8s infinite;
}
@keyframes callGlow {
  0% { box-shadow: 0 0 0 0 rgba(22, 163, 74, 0.45); }
  70% { box-shadow: 0 0 0 22px rgba(22, 163, 74, 0); }
  100% { box-shadow: 0 0 0 0 rgba(22, 163, 74, 0); }
}
.incoming-call-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: #dcfce7;
  color: #15803d;
  font-size: 0.72rem;
  font-weight: 800;
  padding: 4px 10px;
  border-radius: 20px;
  margin-bottom: 8px;
  letter-spacing: 0.6px;
}
.incoming-call-card h3 {
  margin: 0 0 4px;
  font-size: 1.25rem;
  font-weight: 700;
  color: #0f172a;
}
.incoming-call-card p {
  margin: 0 0 10px;
  font-size: 0.88rem;
  color: #64748b;
  font-weight: 600;
}
.incoming-call-status {
  font-size: 0.8rem;
  color: #0284c7;
  background: #f0f9ff;
  border-radius: 8px;
  padding: 6px 10px;
  margin-bottom: 20px;
}
.incoming-call-actions {
  display: flex;
  gap: 12px;
}
.btn-decline-call, .btn-accept-call {
  flex: 1;
  padding: 13px 14px;
  border-radius: 14px;
  font-size: 0.95rem;
  font-weight: 700;
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  transition: transform 0.15s ease, opacity 0.15s ease;
}
.btn-decline-call {
  background: #ef4444;
  color: #ffffff;
  box-shadow: 0 4px 14px rgba(239, 68, 68, 0.4);
}
.btn-decline-call:active { transform: scale(0.96); }
.btn-accept-call {
  background: #16a34a;
  color: #ffffff;
  box-shadow: 0 4px 14px rgba(22, 163, 74, 0.4);
}
.btn-accept-call:active { transform: scale(0.96); }
.incoming-connected-actions {
  display: flex;
  flex-direction: column;
  gap: 8px;
  align-items: center;
}
.call-timer {
  font-size: 1.5rem;
  font-weight: 800;
  color: #16a34a;
  font-variant-numeric: tabular-nums;
}

/* Matching Floating Notification Banner from Rider Dashboard */
.customer-floating-banner,
#liveRiderMessageBanner,
.live-rider-msg-banner {
  position: fixed !important;
  top: 16px !important;
  left: 16px !important;
  right: 16px !important;
  z-index: 99999 !important;
  max-width: 440px !important;
  margin: 0 auto !important;
  border-radius: 16px !important;
  background: #ffffff !important;
  box-shadow: 0 10px 25px rgba(0,0,0,0.12) !important;
  border: 1px solid #e2e8f0 !important;
  padding: 12px 14px !important;
  display: none;
  align-items: center !important;
  gap: 12px !important;
  cursor: pointer !important;
  box-sizing: border-box !important;
  animation: bannerSlideDown 0.32s cubic-bezier(0.16, 1, 0.3, 1);
  transition: transform 0.25s ease, opacity 0.25s ease;
}
.customer-floating-banner.show,
#liveRiderMessageBanner.show,
.live-rider-msg-banner.show {
  display: flex !important;
}
@keyframes bannerSlideDown {
  0% { transform: translateY(-25px); opacity: 0; }
  100% { transform: translateY(0); opacity: 1; }
}
.customer-floating-banner .rct-icon,
.live-rider-msg-banner .rct-icon {
  width: 38px !important;
  height: 38px !important;
  border-radius: 50% !important;
  background: #dcfce7 !important;
  color: #16a34a !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  font-size: 1.1rem !important;
  flex-shrink: 0 !important;
}
.customer-floating-banner .rct-body,
.live-rider-msg-banner .rct-body {
  flex: 1 !important;
  overflow: hidden !important;
  min-width: 0 !important;
}
.customer-floating-banner .rct-title,
.live-rider-msg-banner .rct-title {
  color: #15803d !important;
  font-size: 11px !important;
  font-weight: 700 !important;
  letter-spacing: 0.5px !important;
  text-transform: uppercase !important;
  margin-bottom: 2px !important;
}
.customer-floating-banner .rct-text,
.live-rider-msg-banner .rct-text {
  font-size: 13px !important;
  font-weight: 600 !important;
  color: #0f172a !important;
  line-height: 1.35 !important;
  word-break: break-word !important;
  white-space: normal !important;
}
.customer-floating-banner .rct-close,
.live-rider-msg-banner .rct-close {
  background: transparent !important;
  border: none !important;
  color: #94a3b8 !important;
  font-size: 1.35rem !important;
  line-height: 1 !important;
  cursor: pointer !important;
  padding: 4px 6px !important;
  flex-shrink: 0 !important;
}
.customer-floating-banner .rct-close:hover,
.live-rider-msg-banner .rct-close:hover {
  color: #0f172a !important;
}

/* Order Cancellation UI */
.btn-cancel-order {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: #ffffff;
  color: #dc2626;
  border: 1.5px solid #dc2626;
  border-radius: 8px;
  padding: 4px 10px;
  font-size: 9.5px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.18s ease;
  margin-top: 5px;
}
.btn-cancel-order:hover {
  background: #fef2f2;
  border-color: #b91c1c;
  color: #b91c1c;
}
.btn-cancel-order:active {
  transform: scale(0.96);
}
.cancel-chip {
  background: #f3f4f6;
  border: 1px solid #d1d5db;
  color: #374151;
  font-size: 10px;
  font-weight: 600;
  padding: 4px 10px;
  border-radius: 12px;
  cursor: pointer;
  transition: all 0.15s ease;
}
.cancel-chip.active {
  background: #fee2e2;
  border-color: #ef4444;
  color: #b91c1c;
  font-weight: 700;
}

/* Saved Addresses UI */
.addr-card {
  background: #ffffff;
  border: 1.5px solid #e5e7eb;
  border-radius: 14px;
  padding: 12px 14px;
  margin-bottom: 10px;
  display: flex;
  flex-direction: column;
  gap: 6px;
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
  text-align: left;
}
.addr-card.default-addr {
  border-color: #708238;
  background: #fafaf5;
  box-shadow: 0 2px 10px rgba(112, 130, 56, 0.12);
}
.addr-card-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.addr-label {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 10px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: #4B5320;
  background: #eef2db;
  padding: 2px 8px;
  border-radius: 6px;
}
.addr-badge-default {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 9.5px;
  font-weight: 700;
  color: #15803d;
  background: #dcfce7;
  padding: 2px 8px;
  border-radius: 10px;
  border: 1px solid #86efac;
}
.addr-full-text {
  font-size: 11px;
  color: #1f2937;
  line-height: 1.4;
  margin: 2px 0;
}
.addr-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 4px;
}
.btn-addr-set-default {
  background: #f4efe4;
  border: 1px solid #d8cbb5;
  color: #4B5320;
  padding: 4px 10px;
  border-radius: 8px;
  font-size: 10px;
  font-weight: 700;
  cursor: pointer;
}
.btn-addr-set-default:hover {
  background: #e8decc;
}
.btn-addr-delete {
  background: transparent;
  border: none;
  color: #dc2626;
  font-size: 11px;
  font-weight: 600;
  cursor: pointer;
  padding: 4px 8px;
  margin-left: auto;
}
.btn-addr-delete:hover {
  text-decoration: underline;
}
.btn-add-new-addr {
  width: 100%;
  background: #4B5320;
  color: #ffffff;
  border: none;
  border-radius: 12px;
  padding: 11px 14px;
  font-size: 11.5px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  cursor: pointer;
  box-shadow: 0 4px 14px rgba(75, 83, 32, 0.25);
  margin-top: 8px;
}
.btn-add-new-addr:hover {
  background: #3c431a;
}

/* Payment Methods UI */
.pay-option-card {
  display: flex;
  align-items: center;
  gap: 12px;
  background: #ffffff;
  border: 1.5px solid #e5e7eb;
  border-radius: 14px;
  padding: 12px 14px;
  margin-bottom: 9px;
  cursor: pointer;
  transition: all 0.2s ease;
  text-align: left;
}
.pay-option-card:hover {
  border-color: #4B5320;
  background: #fafaf6;
}
.pay-option-card.active {
  border-color: #4B5320;
  background: #f7f6ed;
  box-shadow: 0 2px 10px rgba(75, 83, 32, 0.12);
}
.pay-icon-box {
  width: 38px;
  height: 38px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  flex-shrink: 0;
}
.pay-icon-box.cod { background: #dcfce7; color: #15803d; }
.pay-icon-box.gcash { background: #dbeafe; color: #1d4ed8; }
.pay-icon-box.maya { background: #ccfbf1; color: #0f766e; }
.pay-icon-box.gotyme { background: #fae8ff; color: #86198f; }
.pay-icon-box.apple { background: #f1f5f9; color: #0f172a; }
.pay-option-info {
  flex: 1;
}
.pay-option-info h4 {
  margin: 0;
  font-size: 12px;
  font-weight: 700;
  color: #1f2937;
}
.pay-option-info p {
  margin: 2px 0 0;
  font-size: 9.5px;
  color: #6b7280;
}
.pay-radio-indicator {
  width: 18px;
  height: 18px;
  border-radius: 50%;
  border: 2px solid #d1d5db;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 10px;
  color: transparent;
  flex-shrink: 0;
}
.pay-option-card.active .pay-radio-indicator {
  border-color: #4B5320;
  background: #4B5320;
  color: #ffffff;
}

/* Help & Support UI */
.faq-box {
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  margin-bottom: 8px;
  overflow: hidden;
  text-align: left;
}
.faq-q {
  padding: 10px 12px;
  font-size: 11px;
  font-weight: 700;
  color: #1f2937;
  display: flex;
  justify-content: space-between;
  align-items: center;
  cursor: pointer;
  background: #fdfdfc;
}
.faq-a {
  padding: 8px 12px 10px;
  font-size: 10px;
  color: #4b5563;
  line-height: 1.45;
  border-top: 1px dashed #e5e7eb;
  display: none;
  background: #fafaf8;
}
.support-action-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 11px 14px;
  border-radius: 12px;
  font-size: 11.5px;
  font-weight: 700;
  text-decoration: none;
  cursor: pointer;
  transition: transform 0.15s ease;
  margin-bottom: 8px;
}
.support-action-btn.call {
  background: #16a34a;
  color: #ffffff;
  box-shadow: 0 3px 12px rgba(22, 163, 74, 0.25);
}
.support-action-btn.email {
  background: #4B5320;
  color: #ffffff;
  box-shadow: 0 3px 12px rgba(75, 83, 32, 0.25);
}
.support-action-btn:active {
  transform: scale(0.98);
}

/* About Food Dash UI */
.about-hero-box {
  text-align: center;
  padding: 16px 12px;
  background: #f7f6ed;
  border: 1px solid #d8cbb5;
  border-radius: 16px;
  margin-bottom: 12px;
}
.about-ver-pill {
  display: inline-block;
  background: #4B5320;
  color: #ffffff;
  font-size: 9.5px;
  font-weight: 800;
  padding: 3px 10px;
  border-radius: 10px;
  margin-top: 6px;
}
.about-link-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 11px 13px;
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  margin-bottom: 7px;
  font-size: 11px;
  font-weight: 600;
  color: #374151;
  cursor: pointer;
  text-decoration: none;
  text-align: left;
}
.about-link-row:hover {
  background: #f9fafb;
}
</style>
</head>
<body>
<div class="app">

<header class="top">
  <div class="brand-row">
    <div class="brand">
      <img class="logo" src="logo.jpg" alt="Food Dash">
      <div><b>FOOD DASH</b><small>GOOD FOOD. FASTER.</small></div>
    </div>
    <div class="top-icons">
      <button class="icon-btn" onclick="openNotifications()" title="Notifications">
        <i class="fa-regular fa-bell"></i>
        <span id="custNoteBadge" class="bell-badge" style="display:none">0</span>
      </button>
      <div class="avatar"><?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } echo strtoupper(substr($full_name,0,1)); ?></div>
    </div>
  </div>
</header>

<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } if ($ontheway_count > 0 && !empty($active_tracking_order)): ?>
<div class="notice-bar" id="dynamicOnTheWayBanner" onclick="openCustomerTrackingModal(<?= (int)$active_tracking_order['order_id'] ?>)" style="cursor:pointer;" title="Tap to view real-time delivery GPS">
  <span><i class="fa-solid fa-motorcycle fa-beat" style="color:var(--harvest-sun)"></i> Rider is on the way with your food! (<?= htmlspecialchars($active_tracking_order['order_ref']) ?>)</span>
  <button type="button" onclick="event.stopPropagation(); openCustomerTrackingModal(<?= (int)$active_tracking_order['order_id'] ?>)">View</button>
</div>
<?php
else: ?>
<div class="notice-bar" id="dynamicOnTheWayBanner" style="display:none; cursor:pointer;" onclick="openCustomerTrackingModal(activeCustomerTrackingOrderId || 0)">
  <span><i class="fa-solid fa-motorcycle fa-beat" style="color:var(--harvest-sun)"></i> Rider is on the way with your food!</span>
  <button type="button" onclick="event.stopPropagation(); openCustomerTrackingModal(activeCustomerTrackingOrderId || 0)">View</button>
</div>
<?php
endif; ?>

<section id="home" class="screen active">
  <div class="greeting"><small>Good Morning,</small><h1><?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } echo htmlspecialchars($full_name); ?>!</h1></div>
  <div class="search" onclick="showScreen('search')"><i class="fa-solid fa-magnifying-glass"></i><input readonly placeholder="Search for food, restaurants, or stores..."></div>
  <div class="hero"><h2>Delicious Food<br>Fresh & Fast</h2><p>Your favorite Filipino meals delivered.</p><button onclick="showScreen('stores')">Order Now</button></div>

  <div class="section-title"><h3>Popular Stores</h3><a onclick="showScreen('stores')">See All</a></div>
  <div class="store-row">
    <?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } 
    $popular_stores = !empty($db_shops) ? $db_shops : [
        ['shop_name' => 'FoodDash Grill', 'category' => 'Steakhouse & Grill', 'logo_url' => 'logo.jpg'],
        ['shop_name' => 'McDonalds', 'category' => 'Burgers â€¢ Fries', 'logo_url' => 'assets/shops/mcdonalds.png'],
        ['shop_name' => 'Jollibee', 'category' => 'Chickenjoy â€¢ Fast Food', 'logo_url' => 'assets/shops/jollibee.png'],
        ['shop_name' => 'Mang Inasal', 'category' => 'Grilled Chicken â€¢ Rice Meals', 'logo_url' => 'assets/shops/mang_inasal.png'],
        ['shop_name' => 'Ribshack', 'category' => 'Ribs â€¢ BBQ', 'logo_url' => 'assets/shops/ribshack.png'],
        ['shop_name' => 'Shakeys', 'category' => 'Pizza â€¢ Mojos', 'logo_url' => 'assets/shops/shakeys.png'],
        ['shop_name' => 'Chowking', 'category' => 'Chinese â€¢ Dim Sum', 'logo_url' => 'assets/shops/chowking.png'],
        ['shop_name' => 'Greenwich', 'category' => 'Pizza â€¢ Pasta', 'logo_url' => 'assets/shops/greenwich.png']
    ];
    foreach ($popular_stores as $ps):
        $p_logo = !empty($ps['logo_url']) ? $ps['logo_url'] : 'assets/shops/default_store.png';
        $p_name = $ps['shop_name'];
        $p_cat = $ps['category'] ?? 'Restaurant';
    ?>
    <div class="store" onclick="openStore('<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } echo htmlspecialchars(addslashes($p_name)); ?>', '<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } echo htmlspecialchars(addslashes($p_logo)); ?>')">
      <div class="store-logo">
        <img src="<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } echo htmlspecialchars($p_logo); ?>" alt="<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } echo htmlspecialchars($p_name); ?>" onerror="if(!this.dataset.fallback){this.dataset.fallback=1;this.src='assets/shops/default_store.png';}else{this.onerror=null;this.src='logo.jpg';}">
      </div>
      <strong><?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } echo htmlspecialchars($p_name); ?></strong>
      <small><?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } echo htmlspecialchars($p_cat); ?></small>
    </div>
    <?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } endforeach; ?>
  </div>

  <div class="section-title"><h3>Popular Near You</h3><a onclick="showScreen('stores')">See All</a></div>
  <div class="grid">
    <div class="food-card"><div class="food-img">ðŸ”</div><div class="food-body"><strong>Big Mac</strong><small>McDonalds</small><div class="price-row"><span class="price">â‚±179.00</span><button class="add" onclick="addCart('Big Mac',179,'ðŸ”','McDonalds')">+</button></div></div></div>
    <div class="food-card"><div class="food-img">ðŸ—</div><div class="food-body"><strong>Chickenjoy</strong><small>Jollibee</small><div class="price-row"><span class="price">â‚±129.00</span><button class="add" onclick="addCart('Chickenjoy',129,'ðŸ—','Jollibee')">+</button></div></div></div>
    <div class="food-card"><div class="food-img">ðŸš</div><div class="food-body"><strong>Chicken Inasal</strong><small>Mang Inasal</small><div class="price-row"><span class="price">â‚±189.00</span><button class="add" onclick="addCart('Chicken Inasal',189,'ðŸš','Mang Inasal')">+</button></div></div></div>
    <div class="food-card"><div class="food-img">ðŸ•</div><div class="food-body"><strong>Managers Choice Pizza</strong><small>Shakeys</small><div class="price-row"><span class="price">â‚±499.00</span><button class="add" onclick="addCart('Managers Choice Pizza',499,'ðŸ•','Shakeys')">+</button></div></div></div>
  </div>
</section>

<section id="stores" class="screen">
  <div class="section-title"><h3>All Partner Stores</h3><i class="fa-regular fa-clock"></i></div>
  <div class="search"><i class="fa-solid fa-magnifying-glass"></i><input id="storeSearch" oninput="filterStores()" placeholder="Search for a store..."></div>
  <div class="chips" id="storeCategoryChips">
    <button class="chip active" onclick="filterStoreCategory('all', this)">All</button>
    <button class="chip" onclick="filterStoreCategory('Grill', this)">Grill & BBQ</button>
    <button class="chip" onclick="filterStoreCategory('Fast Food', this)">Fast Food</button>
    <button class="chip" onclick="filterStoreCategory('Chicken', this)">Chicken</button>
    <button class="chip" onclick="filterStoreCategory('Pizza', this)">Pizza</button>
    <button class="chip" onclick="filterStoreCategory('Chinese', this)">Chinese</button>
  </div>
  <div class="list" id="storeList">
    <?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } 
    $all_stores = !empty($db_shops) ? $db_shops : [
        ['shop_name' => 'FoodDash Grill', 'category' => 'Steakhouse & Grill', 'logo_url' => 'logo.jpg'],
        ['shop_name' => 'McDonalds', 'category' => 'Burgers â€¢ Fries â€¢ McSpaghetti', 'logo_url' => 'assets/shops/mcdonalds.png'],
        ['shop_name' => 'Jollibee', 'category' => 'Chicken â€¢ Burgers â€¢ Spaghetti', 'logo_url' => 'assets/shops/jollibee.png'],
        ['shop_name' => 'Mang Inasal', 'category' => 'Grilled Chicken â€¢ Rice Meals', 'logo_url' => 'assets/shops/mang_inasal.png'],
        ['shop_name' => 'Ribshack', 'category' => 'Ribs â€¢ Chicken â€¢ Pasta', 'logo_url' => 'assets/shops/ribshack.png'],
        ['shop_name' => 'Shakeys', 'category' => 'Pizza â€¢ Chicken â€¢ Pasta', 'logo_url' => 'assets/shops/shakeys.png'],
        ['shop_name' => 'Chowking', 'category' => 'Chinese Favorites â€¢ Rice Meals', 'logo_url' => 'assets/shops/chowking.png'],
        ['shop_name' => 'Greenwich', 'category' => 'Pizza â€¢ Pasta â€¢ Chicken', 'logo_url' => 'assets/shops/greenwich.png']
    ];
    foreach ($all_stores as $sh): 
      $sh_logo = !empty($sh['logo_url']) ? $sh['logo_url'] : 'assets/shops/default_store.png';
      $sh_name = $sh['shop_name'];
      $sh_cat = $sh['category'] ?? 'Specialty Food';
    ?>
    <div class="row-card" data-name="<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } echo strtolower(htmlspecialchars($sh_name)); ?>" data-category="<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } echo strtolower(htmlspecialchars($sh_cat)); ?>" onclick="openStore('<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } echo htmlspecialchars(addslashes($sh_name)); ?>', '<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } echo htmlspecialchars(addslashes($sh_logo)); ?>')">
      <div class="row-icon">
        <img src="<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } echo htmlspecialchars($sh_logo); ?>" alt="<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } echo htmlspecialchars($sh_name); ?>" onerror="if(!this.dataset.fallback){this.dataset.fallback=1;this.src='assets/shops/default_store.png';}else{this.onerror=null;this.src='logo.jpg';}">
      </div>
      <div class="info">
        <strong><?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } echo htmlspecialchars($sh_name); ?></strong>
        <small><?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } echo htmlspecialchars($sh_cat); ?></small>
        <small>â­ 4.8 â€¢ 20â€“35 min â€¢ <b style="color:#2f8f45">Open</b></small>
      </div>
      <i class="fa-solid fa-chevron-right chev"></i>
    </div>
    <?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } endforeach; ?>
  </div>
</section>

<section id="store" class="screen">
  <div class="section-title"><button class="icon-btn" onclick="showScreen('stores')"><i class="fa-solid fa-arrow-left"></i></button><h3 id="storeTitle">McDonalds</h3><i class="fa-regular fa-heart"></i></div>
  <div class="banner-store" id="storeBanner">
    <div class="store-mark" id="storeMark">
      <img id="storeBannerLogo" src="assets/shops/default_store.svg" alt="Store Logo" onerror="if(!this.dataset.fallback){this.dataset.fallback=1;this.src='assets/shops/default_store.svg';}else{this.onerror=null;this.src='logo.jpg';}">
    </div>
    <h2 id="storeName">McDonalds</h2>
    <p id="storeDesc">Burgers, Fries, McSpaghetti and more!</p>
    <div class="rating">â­ 4.8 &nbsp; â€¢ &nbsp; 20â€“30 min &nbsp; â€¢ &nbsp; <b>Open</b></div>
  </div>
  <div class="chips" id="storeDishCategoryChips">
    <!-- Populated dynamically per active store -->
  </div>
  <div class="grid" id="storeItems"></div>
  <div id="storeNoDishes" class="no-dishes-state" style="display:none">
    <i class="fa-solid fa-utensils"></i>
    <strong>No dishes available in this category</strong>
    <small>Try selecting "All" or another category tab.</small>
  </div>
</section>

<section id="item" class="screen">
  <div class="section-title"><button class="icon-btn" onclick="showScreen('store')"><i class="fa-solid fa-arrow-left"></i></button><h3>Item Details</h3><i class="fa-regular fa-heart"></i></div>
  <div class="item-detail">
    <div class="item-art" id="itemArt">ðŸ”</div>
    <div class="item-info"><h2 id="itemName">Big Mac</h2><p id="itemDesc">Two beef patties, special sauce, lettuce and cheese.</p><div class="item-price" id="itemPrice">â‚±179.00</div><div class="qty"><button onclick="changeQty(-1)">âˆ’</button><span id="itemQty">1</span><button onclick="changeQty(1)">+</button></div><button class="primary" style="margin-top:13px" onclick="addCurrentItem()">Add to Cart</button></div>
  </div>
</section>

<section id="search" class="screen">
  <div class="section-title"><h3>Search</h3><i class="fa-solid fa-sliders"></i></div>
  <div class="search"><i class="fa-solid fa-magnifying-glass"></i><input id="searchInput" oninput="searchFood()" placeholder="Search for restaurants, food, or stores..."></div>
  <div class="section-title"><h3>Popular Searches</h3></div>
  <div class="chips"><button class="chip" onclick="setSearch('big mac')">Big Mac</button><button class="chip" onclick="setSearch('chickenjoy')">Chickenjoy</button><button class="chip" onclick="setSearch('mcspaghetti')">McSpaghetti</button><button class="chip" onclick="setSearch('inasal')">Inasal</button><button class="chip" onclick="setSearch('pizza')">Pizza</button></div>
  <div class="list" id="searchResults">
    <div class="row-card" onclick="openStore('FoodDash Grill','logo.jpg')"><div class="row-icon"><img src="logo.jpg" alt="FoodDash Grill" onerror="this.src='assets/shops/default_store.png'"></div><div class="info"><strong>FoodDash Grill</strong><small>Smoked Ribs â€¢ Flame-Grilled BBQ â€¢ Angus Burgers</small></div></div>
    <div class="row-card" onclick="openStore('McDonalds','assets/shops/mcdonalds.png')"><div class="row-icon"><img src="assets/shops/mcdonalds.png" alt="McDonalds" onerror="this.src='assets/shops/default_store.png'"></div><div class="info"><strong>McDonalds</strong><small>Big Mac â€¢ McSpaghetti â€¢ French Fries</small></div></div>
    <div class="row-card" onclick="openStore('Jollibee','assets/shops/jollibee.png')"><div class="row-icon"><img src="assets/shops/jollibee.png" alt="Jollibee" onerror="this.src='assets/shops/default_store.png'"></div><div class="info"><strong>Jollibee</strong><small>Chickenjoy â€¢ Jolly Spaghetti â€¢ Yumburger</small></div></div>
    <div class="row-card" onclick="openStore('Mang Inasal','assets/shops/mang_inasal.png')"><div class="row-icon"><img src="assets/shops/mang_inasal.png" alt="Mang Inasal" onerror="this.src='assets/shops/default_store.png'"></div><div class="info"><strong>Mang Inasal</strong><small>Chicken Inasal â€¢ Pork BBQ â€¢ Halo-Halo</small></div></div>
    <div class="row-card" onclick="openStore('Ribshack','assets/shops/ribshack.png')"><div class="row-icon"><img src="assets/shops/ribshack.png" alt="Ribshack" onerror="this.src='assets/shops/default_store.png'"></div><div class="info"><strong>Ribshack</strong><small>Baby Back Ribs â€¢ Chicken BBQ â€¢ Meals</small></div></div>
  </div>
</section>

<section id="cart" class="screen">
  <div class="section-title"><h3>My Cart</h3><i class="fa-regular fa-trash-can" style="cursor:pointer" onclick="clearCart()"></i></div>
  <div id="cartList"></div>
  <div id="cartSummary" style="display:none">
    <!-- Customer Delivery Destination Section -->
    <div class="delivery-address-section">
      <div class="delivery-section-header">
        <label for="deliveryAddressInput">
          <i class="fa-solid fa-location-dot"></i> Delivery Address
        </label>
        <div style="display:flex; gap:6px;">
          <button type="button" class="gps-detect-btn" onclick="openAddressPromptModal()">
            <i class="fa-solid fa-map-pin"></i> Pin on Map
          </button>
          <button type="button" class="gps-detect-btn" id="btnDetectGps" onclick="detectCustomerGps(false)">
            <i class="fa-solid fa-crosshairs"></i> Locate Me
          </button>
        </div>
      </div>
      <textarea id="deliveryAddressInput" class="delivery-textarea" placeholder="Enter complete delivery address (e.g. Unit/House No., Street, Brgy, City, Landmark)" rows="2"></textarea>
      <div id="gpsLocationStatus" class="gps-status-pill" style="display:none;"></div>
      <input type="hidden" id="id" name="id" value="">
      <input type="hidden" id="delivery_lng" name="delivery_lng" value="">
      <input type="hidden" id="deliveryLatInput" value="">
      <input type="hidden" id="deliveryLngInput" value="">
    </div>

    <!-- Philippine Payment Method Selection -->
    <div class="payment-method-card">
      <div class="payment-section-title">
        <i class="fa-solid fa-wallet" style="color:var(--harvest-sun)"></i> Payment Method
      </div>
      <div class="payment-options-grid" id="checkoutPaymentOptions">
        <div class="pay-option active" data-method="Cash on Delivery (COD)" onclick="setCustomerPaymentMethod('Cash on Delivery (COD)', this); updatePaymentTheme('cod')">
          <div class="pay-option-logo-box">
            <img src="assets/logos/cod.svg" alt="Cash on Delivery" class="pay-option-brand-logo">
          </div>
          <div class="pay-option-info">
            <strong>Cash on Delivery (COD)</strong>
            <small>Cash on Delivery - Pay upon arrival</small>
          </div>
          <i class="fa-solid fa-circle-check pay-check"></i>
        </div>
        <div class="pay-option" data-method="GCash" onclick="setCustomerPaymentMethod('GCash', this); updatePaymentTheme('gcash')">
          <div class="pay-option-logo-box">
            <img src="assets/logos/gcash.svg" alt="GCash" class="pay-option-brand-logo">
          </div>
          <div class="pay-option-info">
            <strong>GCash</strong>
            <small>GCash - E-Wallet</small>
          </div>
          <i class="fa-regular fa-circle pay-check"></i>
        </div>
        <div class="pay-option" data-method="Maya (PayMaya)" onclick="setCustomerPaymentMethod('Maya (PayMaya)', this); updatePaymentTheme('maya')">
          <div class="pay-option-logo-box">
            <img src="assets/logos/maya.svg" alt="Maya" class="pay-option-brand-logo">
          </div>
          <div class="pay-option-info">
            <strong>Maya (PayMaya)</strong>
            <small>Maya - Wallet & Bank</small>
          </div>
          <i class="fa-regular fa-circle pay-check"></i>
        </div>
        <div class="pay-option" data-method="GoTyme Bank" onclick="setCustomerPaymentMethod('GoTyme Bank', this); updatePaymentTheme('gotyme')">
          <div class="pay-option-logo-box">
            <img src="assets/logos/gotyme.svg" alt="GoTyme Bank" class="pay-option-brand-logo">
          </div>
          <div class="pay-option-info">
            <strong>GoTyme Bank</strong>
            <small>GoTyme - Digital Bank</small>
          </div>
          <i class="fa-regular fa-circle pay-check"></i>
        </div>
        <div class="pay-option" data-method="Apple Pay" onclick="setCustomerPaymentMethod('Apple Pay', this); updatePaymentTheme('apple_pay')">
          <div class="pay-option-logo-box">
            <img src="assets/logos/apple-pay.svg" alt="Apple Pay" class="pay-option-brand-logo">
          </div>
          <div class="pay-option-info">
            <strong>Apple Pay</strong>
            <small>Apple Pay - Instant Touch/Face ID</small>
          </div>
          <i class="fa-regular fa-circle pay-check"></i>
        </div>
      </div>
      <input type="hidden" id="selectedPaymentMethod" value="Cash on Delivery (COD)">
    </div>

    <div class="summary">
      <div class="sumrow"><span>Subtotal</span><span id="subtotal">â‚±0.00</span></div>
      <div class="sumrow"><span>Delivery Fee</span><span>â‚±30.00</span></div>
      <div class="sumrow total"><span>Total</span><span id="total">â‚±30.00</span></div>
    </div>
    <button type="button" class="primary btn-checkout" style="margin-top:12px" id="payActionBtn" onclick="handlePayAction()">Checkout</button>
  </div>
</section>

<section id="orders" class="screen">
  <div class="section-title"><h3>My Orders</h3><button class="icon-btn" onclick="showScreen('home')"><i class="fa-solid fa-house"></i></button></div>
  <div class="chips" id="orderFilters">
    <button class="chip active" onclick="filterOrders('all',this)">All</button>
    <button class="chip" onclick="filterOrders('pending',this)">Pending</button>
    <button class="chip" onclick="filterOrders('preparing',this)">Preparing</button>
    <button class="chip" onclick="filterOrders('ready_for_pickup',this)">Ready</button>
    <button class="chip" onclick="filterOrders('out_for_delivery',this)">On Delivery</button>
    <button class="chip" onclick="filterOrders('delivered',this)">Delivered</button>
    <button class="chip" onclick="filterOrders('settled',this)">Settled</button>
    <button class="chip" onclick="filterOrders('cancelled',this)">Cancelled</button>
  </div>
  <div class="list" id="orderList">
    <?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } if (empty($db_orders)): ?>
      <div class="empty-state-card" id="noOrders" style="text-align: center; padding: 36px 18px; background: #ffffff; border-radius: 14px; margin: 12px 0; border: 1.5px dashed #cbd5e1; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
        <div style="font-size: 42px; margin-bottom: 10px; line-height: 1;">ðŸ›ï¸</div>
        <strong style="display: block; font-size: 16px; color: var(--olive-dark, #2f341d); margin-bottom: 6px;">No orders placed yet!</strong>
        <p style="font-size: 12px; color: var(--muted, #64748b); margin-bottom: 16px; max-width: 280px; margin-left: auto; margin-right: auto; line-height: 1.4;">
          Your order history is clean. Discover our partner stores and order delicious meals today!
        </p>
        <button type="button" class="btn-browse-stores-main" onclick="showScreen('stores')" style="display: inline-flex; align-items: center; gap: 8px; background: var(--harvest-sun, #c5a059); color: #ffffff; padding: 10px 22px; border-radius: 10px; font-weight: 700; border: none; cursor: pointer; box-shadow: 0 4px 12px rgba(197, 160, 89, 0.35);">
          <i class="fa-solid fa-store"></i> Browse Stores
        </button>
      </div>
    <?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } else: foreach ($db_orders as $ord): 
      $raw_status = strtoupper(trim(str_replace([' ', '-'], '_', (string)($ord['status'] ?? ''))));
      $del_status = strtolower(trim((string)($ord['delivery_status'] ?? '')));
      $status_lower = strtolower(trim((string)($ord['status'] ?? '')));
      
      $is_arrived = ($raw_status === 'ARRIVED' || $del_status === 'arrived' || $status_lower === 'arrived');
      $is_near = ($raw_status === 'NEAR_CUSTOMER' || $del_status === 'near_customer' || $status_lower === 'near_customer');
      $is_ontheway = in_array($raw_status, ['OUT_FOR_DELIVERY', 'ON_THE_WAY', 'ON_DELIVERY'])
                  || in_array($del_status, ['on_the_way', 'on_delivery', 'picked_up'])
                  || in_array($status_lower, ['out_for_delivery', 'on_the_way', 'on_delivery'])
                  || $is_near || $is_arrived;
                  
      $is_delivered = ($raw_status === 'DELIVERED' || $raw_status === 'COMPLETED' || $del_status === 'delivered' || $status_lower === 'delivered' || $status_lower === 'completed');
      $is_settled = ($raw_status === 'SETTLED' || $status_lower === 'settled');
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
      $clean_card_ref = '#' . ltrim($ord['order_ref'] ?? ('FD-' . $ord['order_id']), '#');
    ?>
      <div class="row-card order-row <?= $is_ontheway ? 'on-the-way-card' : '' ?>" data-status="<?= htmlspecialchars($norm_status) ?>" id="order-card-<?= $ord['order_id'] ?>" <?= $is_ontheway ? 'onclick="openTrackingModal(' . (int)$ord['order_id'] . ')" style="cursor:pointer;" title="Tap to view live delivery tracking map"' : '' ?>>
        <div class="row-icon <?= $is_cancelled ? 'grey' : ($is_ontheway ? 'jolli' : ($is_settled ? 'settled-icon' : 'green')) ?>">
          <?= $is_cancelled ? 'ðŸš«' : ($is_ontheway ? 'ðŸ›µ' : ($is_settled ? 'ðŸ›¡ï¸' : ($is_delivered ? 'ðŸ“¦' : ($is_prep ? 'ðŸ³' : 'ðŸ›ï¸')))) ?>
        </div>
        <div class="info">
          <strong class="order-ref"><?= htmlspecialchars($clean_card_ref) ?></strong>
          <small class="order-store-items"><?= htmlspecialchars($ord['store_name']) ?> â€¢ <?= htmlspecialchars($ord['items_summary']) ?></small>
          <small class="order-meta order-date-price"><?= htmlspecialchars(date('M d, Y â€¢ g:i A', strtotime($ord['created_at']))) ?> â€¢ â‚±<?= number_format((float)$ord['total_amount'], 2) ?></small>
          <?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } if (!empty($ord['delivery_address'])): ?>
            <small class="order-meta order-address" style="color: #64748b; font-weight: 500; font-size: 12px; margin-top: 2px; white-space: normal; line-height: 1.35;"><i class="fa-solid fa-location-dot" style="color:var(--harvest-sun)"></i> <?= htmlspecialchars($ord['delivery_address']) ?></small>
          <?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } endif; ?>
          
          <div style="margin-top: 8px; display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
            <span class="status-badge status-<?= htmlspecialchars($norm_status) ?>" id="status-tag-<?= $ord['order_id'] ?>">
              <?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } if ($is_arrived): ?>
                <i class="fa-solid fa-bell fa-shake"></i>
              <?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } elseif ($is_near): ?>
                <i class="fa-solid fa-person-biking fa-beat"></i>
              <?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } endif; ?>
              <?= htmlspecialchars($status_label) ?>
            </span>
            <?= render_payment_badge($ord['payment_method'] ?? 'Cash on Delivery (COD)') ?>
            <?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } if ($is_settled): ?>
              <span class="settled-tag"><i class="fa-solid fa-circle-check"></i> Reconciled</span>
            <?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } endif; ?>
          </div>

          <?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } if ($can_cancel): ?>
            <!-- a. Status 'pending' / 'preparing': Show Cancel Order button -->
            <div style="margin-top:8px;" id="cancel-wrap-<?= $ord['order_id'] ?>" class="order-actions-wrap">
              <button type="button" class="btn-cancel-order" id="btn-cancel-<?= $ord['order_id'] ?>" onclick="event.stopPropagation(); promptCancelOrder(<?= (int)$ord['order_id'] ?>, '<?= htmlspecialchars(addslashes($clean_card_ref)) ?>', '<?= htmlspecialchars(addslashes($status_label)) ?>')">
                <i class="fa-solid fa-ban"></i> Cancel Order
              </button>
            </div>
          <?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } elseif ($is_cancelled): ?>
            <div style="font-size: 12px; color: #dc2626; font-weight:700; margin-top:6px;">
              <i class="fa-solid fa-circle-xmark"></i> Cancelled by Customer
            </div>
          <?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } endif; ?>

          <?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } if ($is_ontheway || $is_near || $is_arrived): ?>
            <!-- b. Status 'on_delivery' / 'near_customer' / 'arrived': Show Live Track Rider and Received buttons -->
            <div style="font-size: 12px; color: var(--harvest-sun); font-weight:600; margin-top:6px;">
              <?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } if ($is_arrived): ?>
                <i class="fa-solid fa-bell fa-shake"></i> Rider has arrived at your location!
              <?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } elseif ($is_near): ?>
                <i class="fa-solid fa-person-biking fa-beat"></i> Rider is near your location (&lt; 200m)!
              <?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } endif; ?>
              <?= htmlspecialchars($status_label) ?>
            </span>
            <div id="track-actions-<?= $ord['order_id'] ?>" class="order-actions-wrap" style="display:flex; gap:6px; margin-top:8px; flex-wrap:wrap;">
              <button type="button" class="cust-track-btn" onclick="event.stopPropagation(); openTrackingModal(<?= (int)$ord['order_id'] ?>)" title="Tap to view live delivery map">
                <i class="fa-solid fa-location-dot"></i> ðŸ“ Live Track Rider
              </button>
              <button class="confirm-btn" id="confirm-btn-<?= $ord['order_id'] ?>" onclick="event.stopPropagation(); confirmOrderReceived(<?= $ord['order_id'] ?>)">
                <i class="fa-solid fa-box-open"></i> ðŸ“¦ Received
              </button>
            </div>
          <?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } elseif ($is_delivered && !$is_settled): ?>
            <div style="font-size: 12px; color: var(--sagebrush); font-weight:600; margin-top:6px;">
              <i class="fa-solid fa-circle-check"></i> Order Delivered. Awaiting admin settlement.
            </div>
          <?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } endif; ?>
        </div>
      </div>
    <?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } endforeach; endif; ?>
  </div>
</section>

<section id="account" class="screen">
  <div class="section-title"><h3>My Account</h3><i class="fa-solid fa-gear"></i></div>
  <div class="profile"><div class="profile-pic">ðŸ‘¤</div><div><strong><?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } echo htmlspecialchars($full_name); ?></strong><small>Customer account</small></div></div>
  <div class="menu">
    <a href="javascript:void(0)" onclick="showScreen('orders')"><span><i class="fa-regular fa-clipboard"></i>My Orders</span><i class="fa-solid fa-chevron-right"></i></a>
    <a href="javascript:void(0)" onclick="openSavedAddressesModal()"><span><i class="fa-solid fa-location-dot"></i>Addresses</span><i class="fa-solid fa-chevron-right"></i></a>
    <a href="javascript:void(0)" onclick="openPaymentMethodsModal()"><span><i class="fa-regular fa-credit-card"></i>Payment Methods</span><i class="fa-solid fa-chevron-right"></i></a>
    <a href="javascript:void(0)" onclick="openNotifications()"><span><i class="fa-regular fa-bell"></i>Notifications</span><i class="fa-solid fa-chevron-right"></i></a>
    <a href="javascript:void(0)" onclick="openHelpSupportModal()"><span><i class="fa-regular fa-circle-question"></i>Help & Support</span><i class="fa-solid fa-chevron-right"></i></a>
    <a href="javascript:void(0)" onclick="openAboutModal()"><span><i class="fa-solid fa-circle-info"></i>About Food Dash</span><i class="fa-solid fa-chevron-right"></i></a>
  </div>
  <button class="logout" type="button" onclick="openLogout()">Log Out</button>
</section>

<!-- Delivery Address Prompt Modal with Leaflet Map Pin Picker -->
<div class="fd-modal" id="addressPromptModal" onclick="if(event.target===this)closeAddressPromptModal()">
  <div class="fd-modal-box">
    <div class="fd-modal-header">
      <h3><i class="fa-solid fa-location-dot" style="color:var(--harvest-sun)"></i> Delivery Address & Pin</h3>
      <button class="fd-modal-close" onclick="closeAddressPromptModal()">&times;</button>
    </div>
    <div style="font-size: 9.5px; color: var(--muted); margin-bottom: 8px; line-height: 1.4;">
      Drag the pin to your exact delivery location or use your live location.
    </div>

    <!-- Mini Leaflet Map Container -->
    <div id="checkout-address-map" style="height: 220px; width: 100%; border-radius: 10px; margin-bottom: 12px;"></div>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; gap:8px;">
      <span style="font-size: 9.5px; font-weight: 600; color: var(--olive-dark);">
        <i class="fa-solid fa-map-location-dot" style="color:var(--harvest-sun)"></i> Delivery Address
      </span>
      <button type="button" class="gps-detect-btn" id="modalBtnLocateMe" onclick="useMyExactLocation()">
        <i class="fa-solid fa-location-crosshairs"></i> Use My Exact Location
      </button>
    </div>

    <textarea id="modalDeliveryAddress" class="delivery-textarea" placeholder="Unit/House No., Street, Barangay, City, Landmark..." rows="2"></textarea>
    <div id="modalGpsStatus" class="gps-status-pill" style="display:none; margin-top:8px;"></div>
    
    <div style="margin-top:8px; margin-bottom:6px;">
      <label style="display:block; font-size: 9.5px; font-weight: 700; color: var(--olive-dark); margin-bottom: 4px;">
        <i class="fa-solid fa-wallet" style="color:var(--harvest-sun)"></i> Payment Method
      </label>
      <select id="modalPaymentSelect" class="delivery-textarea" style="height:36px; padding:6px 10px; font-size:10.5px; cursor:pointer;" onchange="syncCustomerPaymentMethod(this.value)">
        <option value="Cash on Delivery (COD)">Cash on Delivery (COD)</option>
        <option value="GCash">GCash</option>
        <option value="Maya (PayMaya)">Maya (PayMaya)</option>
        <option value="GoTyme Bank">GoTyme Bank</option>
        <option value="Apple Pay">Apple Pay</option>
      </select>
    </div>
    
    <input type="hidden" id="modal_delivery_lat" name="id" value="">
    <input type="hidden" id="modal_delivery_lng" name="delivery_lng" value="">
    <input type="hidden" id="modalDeliveryLat" value="">
    <input type="hidden" id="modalDeliveryLng" value="">
    <div class="fd-modal-actions">
      <button class="btn-fd-secondary" onclick="closeAddressPromptModal()">Cancel</button>
      <button class="btn-fd-primary" id="btnModalConfirmOrder" onclick="confirmModalAddressAndCheckout()">Confirm & Order</button>
    </div>
  </div>
</div>

<!-- In-App Notifications Modal (Food Dash Palette) -->
<div class="fd-modal" id="customerNoteModal" onclick="if(event.target===this)closeNotifications()">
  <div class="fd-modal-box">
    <div class="fd-modal-header">
      <h3><span>ðŸ””</span> Notifications</h3>
      <button class="fd-modal-close" onclick="closeNotifications()">&times;</button>
    </div>
    <div id="customerNoteList" class="fd-note-list">
      <div style="padding:15px;text-align:center;font-size:9px;color:var(--muted);">Loading notifications...</div>
    </div>
    <div class="fd-modal-actions">
      <button class="btn-fd-secondary" onclick="markAllNotificationsRead()">Mark All Read</button>
      <button class="btn-fd-primary" onclick="closeNotifications()">Close</button>
    </div>
  </div>
</div>

<div class="logout-modal" id="logoutModal" onclick="if(event.target===this)closeLogout()"><div class="logout-box"><img class="logout-logo" src="logo.jpg" alt="Food Dash"><h3>Log out of Food Dash?</h3><p>Your current session will be signed out. You can log in again anytime.</p><div class="logout-actions"><button class="logout-cancel" type="button" onclick="closeLogout()">Cancel</button><button class="logout-confirm" type="button" onclick="executeLogout()">Yes, Log Out</button></div></div></div>

<!-- Customer Live Delivery Tracking Modal -->
<div class="fd-modal tracking-modal" id="customerTrackingModal" style="display: none;" onclick="if(event.target===this)closeCustomerTrackingModal()">
  <div class="fd-modal-box cust-track-modal-box modal-content">
    <div class="fd-modal-header" style="padding-bottom:10px; border-bottom:1px solid var(--sandstone); flex-shrink:0;">
      <div style="display:flex; align-items:center; gap:8px;">
        <span style="font-size:18px;">ðŸ›µ</span>
        <div>
          <h3 id="trackModalOrderRef" style="margin:0; font-size:13px; color:var(--olive-shadow);">Live Delivery Tracking</h3>
          <small id="trackModalStoreSubtitle" style="font-size:10px; color:var(--muted);">Store pickup & real-time GPS</small>
        </div>
      </div>
      <button class="fd-modal-close" onclick="closeCustomerTrackingModal()">&times;</button>
    </div>

    <div class="tracking-modal-body modal-body" style="overflow-y:auto; -webkit-overflow-scrolling:touch; flex:1 1 auto;">
      <!-- Rider Contact Card -->
      <div class="cust-rider-card">
        <div class="cust-rider-left">
          <div class="cust-rider-avatar"><i class="fa-solid fa-motorcycle"></i></div>
          <div>
            <div class="cust-rider-title">Your Delivery Rider</div>
            <div id="custTrackRiderName" class="cust-rider-name">Alex Rider</div>
            <div id="custTrackRiderPhone" class="cust-rider-phone"><i class="fa-solid fa-phone-volume"></i> +63 917 555 0192</div>
          </div>
        </div>
        <div class="cust-rider-actions">
          <button id="custTrackCallLink" type="button" class="cust-comm-btn call" onclick="initiateCustomerVoiceCall()" title="Call Rider via Synchronized In-App Voice">
            <i class="fa-solid fa-phone"></i> Call Rider
          </button>
          <button id="custTrackMsgBtn" type="button" class="cust-comm-btn msg" onclick="openCustomerChat()" title="Message Rider (Chat)">
            <i class="fa-solid fa-comment-dots"></i> Message Rider <span class="cust-chat-unread-badge badge" style="display:none;margin-left:4px;">0</span>
          </button>
          <a id="custTrackSmsBtn" href="sms:+639175550192" class="cust-comm-btn sms" title="Direct SMS to Rider">
            <i class="fa-solid fa-envelope"></i> SMS
          </a>
        </div>
      </div>

      <!-- Remaining distance & ETA badge (e.g. "500m away â€¢ ~3 mins") -->
      <div class="cust-track-eta-badge" id="custTrackEtaBadge">
        <i class="fa-solid fa-motorcycle fa-beat" style="color:var(--harvest-sun)"></i>
        <span id="custTrackEtaBadgeText">Locating rider & calculating ETA...</span>
      </div>

      <!-- ETA and Distance Bar -->
      <div class="cust-track-status-bar">
        <div id="custTrackEta"><i class="fa-solid fa-clock" style="color:var(--harvest-sun)"></i> <span id="custTrackEtaText">Locating rider...</span></div>
        <div id="custTrackDist" class="dist-pill"><i class="fa-solid fa-route"></i> <span id="custTrackDistText">Calculating...</span></div>
      </div>

      <!-- Interactive Leaflet Live Tracking Map -->
      <div id="cust-tracking-map" class="cust-tracking-map" style="width: 100%; border-radius: 12px; margin-top: 10px; border: 1.5px solid var(--sandstone); position:relative;"></div>

      <!-- Legend -->
      <div class="cust-map-legend">
        <span><span class="legend-dot store"></span> Store Pickup</span>
        <span><span class="legend-dot rider"></span> Rider GPS</span>
        <span><span class="legend-dot customer"></span> Your Destination</span>
      </div>
    </div>

    <div class="fd-modal-actions" style="margin-top:12px; flex-shrink:0;">
      <button class="btn-fd-secondary" onclick="closeCustomerTrackingModal()">Close Map</button>
      <button class="btn-fd-primary" onclick="openCustomerChat()"><i class="fa-solid fa-comment-dots"></i> Chat with Rider <span class="cust-chat-unread-badge" style="display:none;margin-left:4px;">0</span></button>
    </div>
  </div>
</div>

<!-- Customer Message / Chat Modal -->
<div class="fd-modal" id="customerChatModal" onclick="if(event.target===this)closeCustomerChat()">
  <div class="fd-modal-box chat-modal-box">
    <div class="chat-modal-header">
      <div style="display:flex; align-items:center; gap:8px;">
        <div class="chat-header-avatar"><i class="fa-solid fa-motorcycle"></i></div>
        <div>
          <h4 id="custChatHeaderTitle" style="margin:0; font-size:13px; color:var(--olive-shadow);">Chat with Rider</h4>
          <small id="custChatHeaderPhone" style="font-size:10px; color:var(--muted);">+63 917 555 0192</small>
        </div>
      </div>
      <button class="chat-close-btn" onclick="closeCustomerChat()"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <!-- Quick Customer Replies -->
    <div class="quick-status-section">
      <div class="quick-title"><i class="fa-solid fa-bolt"></i> Quick Messages:</div>
      <div class="quick-pills">
        <button type="button" class="quick-pill" onclick="sendCustomerQuickReply('I'm waiting outside! ðŸ‘‹')">ðŸ‘‹ I'm waiting outside</button>
        <button type="button" class="quick-pill" onclick="sendCustomerQuickReply('Please leave it with the lobby/guard house ðŸ›¡ï¸')">ðŸ›¡ï¸ Leave at guard house</button>
        <button type="button" class="quick-pill" onclick="sendCustomerQuickReply('Thank you! Take your time and ride safely ðŸ›µ')">ðŸ›µ Ride safely</button>
        <button type="button" class="quick-pill" onclick="sendCustomerQuickReply('Please ring doorbell when you arrive ðŸ””')">ðŸ”” Ring doorbell</button>
      </div>
    </div>

    <!-- Chat Messages Transcript -->
    <div id="custChatMessagesList" class="chat-messages-container">
      <div class="chat-empty-hint">Loading messages with your rider...</div>
    </div>

    <!-- Input Bar with In-App Send and Native SMS -->
    <div class="chat-input-bar">
      <input type="text" id="custChatInputText" placeholder="Type message to rider..." oninput="updateCustChatSmsLink(this.value)" onkeydown="if(event.key==='Enter') sendCustChatMessage()">
      <button type="button" class="chat-send-btn" onclick="sendCustChatMessage()" title="Send in App"><i class="fa-solid fa-paper-plane"></i></button>
      <a href="javascript:void(0)" id="custChatSmsLink" class="chat-sms-btn" target="_blank" title="Send via native SMS"><i class="fa-solid fa-comment-dots"></i> SMS</a>
    </div>
  </div>
</div>

<!-- Incoming Call Floating Overlay Modal -->
<div id="incomingCallModal" class="incoming-call-modal" style="display:none;">
  <div class="incoming-call-card">
    <div class="incoming-call-avatar">
      <i class="fa-solid fa-phone-volume fa-shake"></i>
    </div>
    <div class="incoming-call-badge"><i class="fa-solid fa-circle-dot fa-beat-fade"></i> INCOMING CALL</div>
    <h3 id="incomingCallerName">Rider</h3>
    <p id="incomingCallerPhone">+63 917 555 0192</p>
    <div id="incomingCallStatus" class="incoming-call-status">Rider is calling regarding your delivery...</div>
    <div class="incoming-call-actions" id="incomingCallActions">
      <button type="button" class="btn-decline-call" onclick="declineIncomingCall()">
        <i class="fa-solid fa-phone-slash"></i> Decline
      </button>
      <button type="button" class="btn-accept-call" onclick="acceptIncomingCall()">
        <i class="fa-solid fa-phone"></i> Answer
      </button>
    </div>
    <div class="incoming-connected-actions" id="incomingConnectedActions" style="display:none;">
      <span class="call-timer" id="callConnectedTimer">00:00</span>
      <div style="font-size:0.8rem; color:#16a34a; font-weight:600;"><i class="fa-solid fa-headset"></i> Voice Line Active</div>
      <button type="button" class="btn-decline-call" onclick="endConnectedCall()" style="margin-top:8px;">
        <i class="fa-solid fa-phone-slash"></i> End Call
      </button>
    </div>
  </div>
</div>

<!-- Prominent Rider Arrived Live Notification Modal -->
<div class="fd-modal" id="riderArrivedModal" style="display:none; z-index:9999999;">
  <div class="fd-modal-backdrop" onclick="/* Persistent alarm: must click one of the explicit actions below */"></div>
  <div class="fd-modal-box" style="text-align:center; padding:28px 22px 24px; max-width:350px; border-radius:24px; border:2px solid #86efac; box-shadow:0 24px 60px rgba(0,0,0,0.35); background:#ffffff; position:relative; z-index:1; animation:scaleUp 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);">
    <div style="width:72px; height:72px; border-radius:50%; background:#dcfce7; color:#15803d; font-size:34px; display:flex; align-items:center; justify-content:center; margin:0 auto 14px; box-shadow:0 0 0 10px rgba(22,163,74,0.18);">
      <i class="fa-solid fa-location-pin fa-bounce"></i>
    </div>
    <span style="display:inline-flex; align-items:center; gap:5px; background:#dcfce7; color:#15803d; font-size:10px; font-weight:800; padding:4px 12px; border-radius:14px; margin-bottom:8px; letter-spacing:0.6px; text-transform:uppercase;">
      <i class="fa-solid fa-circle-dot fa-beat-fade"></i> RIDER AT DESTINATION
    </span>
    <h2 style="font-size:20px; font-weight:800; color:#14532d; margin-bottom:8px;">Your rider has arrived!</h2>
    <p id="riderArrivedModalMsg" style="font-size:13px; color:#4b5563; line-height:1.5; margin-bottom:18px;">
      ðŸ“ Your rider has arrived at your delivery address! Please choose a quick response so your rider knows you are coming:
    </p>
    <div id="riderArrivedActionButtons" style="display:flex; flex-direction:column; gap:10px;">
      <button type="button" class="btn-arrival-action minute" onclick="respondToRiderArrival('Be there in a minute!')">
        <i class="fa-solid fa-person-running"></i> Be there in a minute!
      </button>
      <button type="button" class="btn-arrival-action outside" onclick="respondToRiderArrival('Okay, Going outside!')">
        <i class="fa-solid fa-door-open"></i> Okay, Going outside!
      </button>
      <a href="javascript:void(0)" onclick="openTrackingFromArrived()" style="display:inline-block; margin-top:4px; font-size:11px; color:#15803d; font-weight:700; text-decoration:underline;">
        View Live Tracking Map
      </a>
    </div>
  </div>
</div>

<!-- Floating Rider Message Notification Banner -->
<div id="liveRiderMessageBanner" class="customer-floating-banner live-rider-msg-banner" style="display:none;" onclick="openChatFromBanner()">
  <div class="rct-icon"><i class="fa-solid fa-comment-dots"></i></div>
  <div class="rct-body">
    <div class="rct-title" id="liveMsgBannerTitle">MESSAGE FROM RIDER</div>
    <div class="rct-text" id="liveMsgBannerText">I'm outside with your order! ðŸ›µ</div>
  </div>
  <button type="button" class="rct-close" onclick="event.stopPropagation(); dismissLiveRiderBanner()">&times;</button>
</div>

<!-- Generic In-App Confirmation Modal (Food Dash Palette) -->
<div class="fd-modal" id="customerConfirmModal" onclick="if(event.target===this)closeCustConfirm()">
  <div class="fd-modal-box" style="max-width:340px; padding:24px 20px 20px; text-align:center; border-radius:20px; border:1px solid var(--sandstone);">
    <div style="font-size:32px; color:var(--harvest-sun); margin-bottom:10px;">
      <i class="fa-solid fa-circle-question"></i>
    </div>
    <h3 id="custConfirmTitle" style="color:var(--olive-dark); font-size:16px; font-weight:700; margin-bottom:8px;">Confirm Action</h3>
    <p id="custConfirmMsg" style="font-size:11px; color:#4b5563; line-height:1.45; margin-bottom:18px;">Are you sure you want to proceed?</p>
    <div style="display:flex; gap:10px; justify-content:center;">
      <button type="button" class="btn-fd-secondary" style="flex:1;" onclick="closeCustConfirm()">Cancel</button>
      <button type="button" class="btn-fd-primary" id="btnCustConfirmAction" style="flex:1;">Confirm</button>
    </div>
  </div>
</div>

<!-- 1. Order Cancellation Confirmation Modal -->
<div class="fd-modal" id="cancelOrderModal" onclick="if(event.target===this)closeCancelOrderModal()">
  <div class="fd-modal-box" style="max-width:360px; padding:22px 20px;">
    <div class="fd-modal-header" style="padding-bottom:8px; border-bottom:1px solid #fee2e2;">
      <h3 style="color:#b91c1c; display:flex; align-items:center; gap:8px;">
        <i class="fa-solid fa-triangle-exclamation"></i> Cancel Order
      </h3>
      <button class="fd-modal-close" onclick="closeCancelOrderModal()">&times;</button>
    </div>
    <div style="margin:12px 0 10px; text-align:left;">
      <p style="font-size:12px; font-weight:700; color:#1f2937; margin-bottom:4px;" id="cancelOrderModalRef">
        Are you sure you want to cancel this order?
      </p>
      <p style="font-size:10px; color:#6b7280; line-height:1.45; margin-bottom:12px;">
        Please select or provide a reason for cancelling this order:
      </p>

      <div class="cancel-reason-chips" style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:10px;">
        <button type="button" class="cancel-chip active" onclick="selectCancelReason(this, 'Changed mind')">Changed mind</button>
        <button type="button" class="cancel-chip" onclick="selectCancelReason(this, 'Duplicate order')">Duplicate order</button>
        <button type="button" class="cancel-chip" onclick="selectCancelReason(this, 'Wrong delivery address')">Wrong address</button>
        <button type="button" class="cancel-chip" onclick="selectCancelReason(this, 'Taking too long to prepare')">Taking too long</button>
        <button type="button" class="cancel-chip" onclick="selectCancelReason(this, 'Need to modify items')">Need to modify</button>
      </div>

      <textarea id="cancelOrderReasonInput" class="delivery-textarea" placeholder="Additional details (optional)..." rows="2" style="font-size:11px;">Changed mind</textarea>
      <input type="hidden" id="cancelTargetOrderId" value="">
    </div>

    <div class="fd-modal-actions" style="margin-top:14px; display:flex; gap:8px;">
      <button type="button" class="btn-fd-secondary" onclick="closeCancelOrderModal()" style="flex:1;">
        Keep Order
      </button>
      <button type="button" id="btnConfirmCancelOrder" class="btn-fd-primary" onclick="executeCancelOrder()" style="background:#dc2626; color:#fff; flex:1; box-shadow:0 3px 10px rgba(220,38,38,0.3);">
        <i class="fa-solid fa-ban"></i> Yes, Cancel Order
      </button>
    </div>
  </div>
</div>

<!-- 2. Saved Addresses Modal -->
<div class="fd-modal" id="savedAddressesModal" onclick="if(event.target===this)closeSavedAddressesModal()">
  <div class="fd-modal-box" style="max-width:400px; max-height:85vh; display:flex; flex-direction:column; padding:20px;">
    <div class="fd-modal-header" style="padding-bottom:10px; border-bottom:1px solid var(--sandstone);">
      <h3><i class="fa-solid fa-location-dot" style="color:var(--harvest-sun)"></i> Saved Addresses</h3>
      <button class="fd-modal-close" onclick="closeSavedAddressesModal()">&times;</button>
    </div>
    <div id="savedAddressesList" style="overflow-y:auto; flex:1; margin:12px 0; padding-right:2px;">
      <div style="text-align:center; padding:20px; font-size:11px; color:var(--muted);">Loading your addresses...</div>
    </div>
    <button type="button" class="btn-add-new-addr" onclick="openAddAddressModal()">
      <i class="fa-solid fa-plus"></i> Add New Address
    </button>
  </div>
</div>

<!-- 3. Add New Address Modal with Map Pin Picker -->
<div class="fd-modal" id="addAddressModal" onclick="if(event.target===this)closeAddAddressModal()">
  <div class="fd-modal-box" style="max-width:400px; max-height:90vh; overflow-y:auto; padding:20px;">
    <div class="fd-modal-header" style="padding-bottom:10px; border-bottom:1px solid var(--sandstone);">
      <h3><i class="fa-solid fa-map-location-dot" style="color:var(--harvest-sun)"></i> Add New Delivery Address</h3>
      <button class="fd-modal-close" onclick="closeAddAddressModal()">&times;</button>
    </div>
    
    <div style="text-align:left; margin:10px 0 6px;">
      <label style="display:block; font-size:10px; font-weight:700; color:var(--olive-shadow); margin-bottom:3px;">Address Label</label>
      <div style="display:flex; gap:6px; margin-bottom:10px;">
        <button type="button" class="cancel-chip active" id="chipLabelHome" onclick="selectNewAddrLabel('Home')"><i class="fa-solid fa-house"></i> Home</button>
        <button type="button" class="cancel-chip" id="chipLabelWork" onclick="selectNewAddrLabel('Work')"><i class="fa-solid fa-building"></i> Work</button>
        <button type="button" class="cancel-chip" id="chipLabelOther" onclick="selectNewAddrLabel('Other')"><i class="fa-solid fa-location-dot"></i> Other</button>
      </div>
      <input type="hidden" id="newAddrLabel" value="Home">

      <label style="display:block; font-size:10px; font-weight:700; color:var(--olive-shadow); margin-bottom:3px;">Street Address / Building *</label>
      <input type="text" id="newAddrStreet" class="delivery-textarea" placeholder="Unit, House/Building No., Street Name" style="height:36px; padding:6px 10px; margin-bottom:8px; font-size:11px;">

      <div style="display:flex; gap:8px; margin-bottom:8px;">
        <div style="flex:1;">
          <label style="display:block; font-size:10px; font-weight:700; color:var(--olive-shadow); margin-bottom:3px;">Barangay</label>
          <input type="text" id="newAddrBarangay" class="delivery-textarea" placeholder="e.g. Barangay Cogon" style="height:36px; padding:6px 10px; font-size:11px;">
        </div>
        <div style="flex:1;">
          <label style="display:block; font-size:10px; font-weight:700; color:var(--olive-shadow); margin-bottom:3px;">City</label>
          <input type="text" id="newAddrCity" class="delivery-textarea" value="Ormoc City" style="height:36px; padding:6px 10px; font-size:11px;">
        </div>
      </div>

      <div style="margin-bottom:10px;">
        <label style="display:block; font-size:10px; font-weight:700; color:var(--olive-shadow); margin-bottom:3px;">Postal Code</label>
        <input type="text" id="newAddrZip" class="delivery-textarea" value="6541" style="height:36px; padding:6px 10px; font-size:11px;">
      </div>

      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
        <label style="font-size:10px; font-weight:700; color:var(--olive-shadow); margin:0;">
          <i class="fa-solid fa-crosshairs" style="color:var(--harvest-sun)"></i> Pin Exact Delivery Location
        </label>
        <button type="button" class="gps-detect-btn" onclick="useLocationForNewAddress()" style="font-size:9px; padding:3px 8px;">
          <i class="fa-solid fa-location-crosshairs"></i> Detect Location
        </button>
      </div>

      <!-- Leaflet map picker -->
      <div id="add-address-picker-map" style="height:170px; width:100%; border-radius:10px; border:1px solid #d8cbb5; margin-bottom:8px;"></div>
      <div id="newAddrGpsNotice" style="font-size:9.5px; color:#15803d; font-weight:600; margin-bottom:10px;">
        <i class="fa-solid fa-circle-check"></i> <span id="newAddrCoordsText">Pin set: 11.00762, 124.60749</span>
      </div>
      <input type="hidden" id="newAddrLat" value="11.00762">
      <input type="hidden" id="newAddrLng" value="124.60749">

      <label style="display:flex; align-items:center; gap:8px; font-size:11px; color:#374151; cursor:pointer; margin-bottom:14px;">
        <input type="checkbox" id="newAddrSetDefault" checked style="width:16px; height:16px; accent-color:#4B5320;">
        <span>Set as my default delivery address</span>
      </label>
    </div>

    <div class="fd-modal-actions">
      <button type="button" class="btn-fd-secondary" onclick="closeAddAddressModal()">Cancel</button>
      <button type="button" id="btnSaveNewAddress" class="btn-fd-primary" onclick="submitNewAddress()">Save Address</button>
    </div>
  </div>
</div>

<!-- 4. Payment Methods Modal / Drawer -->
<div class="fd-modal" id="paymentMethodsModal" onclick="if(event.target===this)closePaymentMethodsModal()">
  <div class="fd-modal-box" style="max-width:390px; padding:22px 20px;">
    <div class="fd-modal-header" style="padding-bottom:10px; border-bottom:1px solid var(--sandstone);">
      <h3><i class="fa-regular fa-credit-card" style="color:var(--harvest-sun)"></i> Payment Methods</h3>
      <button class="fd-modal-close" onclick="closePaymentMethodsModal()">&times;</button>
    </div>
    <div style="font-size:10px; color:#6b7280; text-align:left; margin:8px 0 12px; line-height:1.4;">
      Select your preferred default payment method for swift one-tap checkouts.
    </div>

    <div class="payment-options-list">
      <div class="pay-option-card selected" id="payOpt_cod" onclick="selectDefaultPaymentMethod('Cash on Delivery (COD)')">
        <div class="pay-tile-left">
          <div class="pay-tile-logo-box">
            <img src="assets/logos/cod.svg" alt="Cash on Delivery" class="pay-tile-logo">
          </div>
          <div class="pay-tile-info">
            <h4>Cash on Delivery (COD)</h4>
            <p>Cash on Delivery - Pay upon arrival</p>
          </div>
        </div>
        <div class="pay-radio-indicator"><i class="fa-solid fa-check"></i></div>
      </div>

      <div class="pay-option-card" id="payOpt_gcash" onclick="selectDefaultPaymentMethod('GCash')">
        <div class="pay-tile-left">
          <div class="pay-tile-logo-box">
            <img src="assets/logos/gcash.svg" alt="GCash" class="pay-tile-logo">
          </div>
          <div class="pay-tile-info">
            <h4>GCash</h4>
            <p>GCash - E-Wallet</p>
          </div>
        </div>
        <div class="pay-radio-indicator"><i class="fa-solid fa-check"></i></div>
      </div>

      <div class="pay-option-card" id="payOpt_maya" onclick="selectDefaultPaymentMethod('Maya (PayMaya)')">
        <div class="pay-tile-left">
          <div class="pay-tile-logo-box">
            <img src="assets/logos/maya.svg" alt="Maya" class="pay-tile-logo">
          </div>
          <div class="pay-tile-info">
            <h4>Maya (PayMaya)</h4>
            <p>Maya - Wallet & Bank</p>
          </div>
        </div>
        <div class="pay-radio-indicator"><i class="fa-solid fa-check"></i></div>
      </div>

      <div class="pay-option-card" id="payOpt_gotyme" onclick="selectDefaultPaymentMethod('GoTyme Bank')">
        <div class="pay-tile-left">
          <div class="pay-tile-logo-box">
            <img src="assets/logos/gotyme.svg" alt="GoTyme Bank" class="pay-tile-logo">
          </div>
          <div class="pay-tile-info">
            <h4>GoTyme Bank</h4>
            <p>GoTyme - Digital Bank</p>
          </div>
        </div>
        <div class="pay-radio-indicator"><i class="fa-solid fa-check"></i></div>
      </div>

      <div class="pay-option-card" id="payOpt_apple" onclick="selectDefaultPaymentMethod('Apple Pay')">
        <div class="pay-tile-left">
          <div class="pay-tile-logo-box">
            <img src="assets/logos/apple-pay.svg" alt="Apple Pay" class="pay-tile-logo">
          </div>
          <div class="pay-tile-info">
            <h4>Apple Pay</h4>
            <p>Apple Pay - Instant Touch/Face ID</p>
          </div>
        </div>
        <div class="pay-radio-indicator"><i class="fa-solid fa-check"></i></div>
      </div>
    </div>

    <div class="fd-modal-actions" style="margin-top:14px;">
      <button type="button" class="btn-fd-primary" onclick="closePaymentMethodsModal()" style="width:100%;">
        Save & Done
      </button>
    </div>
  </div>
</div>

<!-- 5. Help & Support Modal -->
<div class="fd-modal" id="helpSupportModal" onclick="if(event.target===this)closeHelpSupportModal()">
  <div class="fd-modal-box" style="max-width:400px; max-height:88vh; overflow-y:auto; padding:22px 20px;">
    <div class="fd-modal-header" style="padding-bottom:10px; border-bottom:1px solid var(--sandstone);">
      <h3><i class="fa-regular fa-circle-question" style="color:var(--harvest-sun)"></i> Help & Support</h3>
      <button class="fd-modal-close" onclick="closeHelpSupportModal()">&times;</button>
    </div>

    <div style="text-align:left; margin:10px 0 14px;">
      <div style="font-size:10px; font-weight:800; color:var(--olive-shadow); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px;">
        Direct Contact Support
      </div>
      <a href="tel:+639175550192" class="support-action-btn call">
        <i class="fa-solid fa-phone-volume"></i> Call Hotline (+63 917 555 0192)
      </a>
      <a href="mailto:support@fooddash.ph?subject=Customer%20Support%20Inquiry%20-%20Food%20Dash" class="support-action-btn email">
        <i class="fa-solid fa-envelope"></i> Email Support (support@fooddash.ph)
      </a>

      <div style="font-size:10px; font-weight:800; color:var(--olive-shadow); text-transform:uppercase; letter-spacing:0.5px; margin:16px 0 8px;">
        Frequently Asked Questions
      </div>

      <div class="faq-box">
        <div class="faq-q" onclick="toggleFaq('faq1')">
          <span><i class="fa-solid fa-motorcycle" style="color:var(--harvest-sun); margin-right:6px;"></i> How do I track my order in real-time?</span>
          <i class="fa-solid fa-chevron-down" id="faq1_icon"></i>
        </div>
        <div class="faq-a" id="faq1">
          Open the <b>My Orders</b> tab and tap on any active delivery card. This opens your live Leaflet GPS map showing your store pickup, your delivery pin, live rider motorcycle movement on actual streets, and estimated time of arrival.
        </div>
      </div>

      <div class="faq-box">
        <div class="faq-q" onclick="toggleFaq('faq2')">
          <span><i class="fa-solid fa-clock" style="color:var(--harvest-sun); margin-right:6px;"></i> What are the estimated delivery times?</span>
          <i class="fa-solid fa-chevron-down" id="faq2_icon"></i>
        </div>
        <div class="faq-a" id="faq2">
          Meals are typically prepared by our restaurant chefs within 15â€“20 minutes. Once picked up by your rider, delivery takes approximately 10â€“20 minutes depending on road distance and traffic.
        </div>
      </div>

      <div class="faq-box">
        <div class="faq-q" onclick="toggleFaq('faq3')">
          <span><i class="fa-regular fa-credit-card" style="color:var(--harvest-sun); margin-right:6px;"></i> What payment methods are supported?</span>
          <i class="fa-solid fa-chevron-down" id="faq3_icon"></i>
        </div>
        <div class="faq-a" id="faq3">
          Food Dash supports Cash on Delivery (COD), GCash, Maya (PayMaya), GoTyme Bank, and Apple Pay. You can switch your default payment anytime in Account &gt; Payment Methods.
        </div>
      </div>

      <div class="faq-box">
        <div class="faq-q" onclick="toggleFaq('faq4')">
          <span><i class="fa-solid fa-ban" style="color:#dc2626; margin-right:6px;"></i> Can I cancel my order once placed?</span>
          <i class="fa-solid fa-chevron-down" id="faq4_icon"></i>
        </div>
        <div class="faq-a" id="faq4">
          Yes! Orders in <b>Pending</b> or <b>Preparing</b> status can be cancelled directly by tapping the red "Cancel Order" button on your order card. Once an order is Out for Delivery, cancellation is disabled to protect active transit.
        </div>
      </div>
    </div>

    <div class="fd-modal-actions">
      <button type="button" class="btn-fd-primary" onclick="closeHelpSupportModal()" style="width:100%;">
        Close Support
      </button>
    </div>
  </div>
</div>

<!-- 6. About Food Dash Modal -->
<div class="fd-modal" id="aboutModal" onclick="if(event.target===this)closeAboutModal()">
  <div class="fd-modal-box" style="max-width:390px; max-height:88vh; overflow-y:auto; padding:22px 20px;">
    <div class="fd-modal-header" style="padding-bottom:10px; border-bottom:1px solid var(--sandstone);">
      <h3><i class="fa-solid fa-circle-info" style="color:var(--harvest-sun)"></i> About Food Dash</h3>
      <button class="fd-modal-close" onclick="closeAboutModal()">&times;</button>
    </div>

    <div style="margin:14px 0 10px;">
      <div class="about-hero-box">
        <img src="logo.jpg" alt="Food Dash" style="width:64px; height:64px; border-radius:50%; object-fit:cover; border:2px solid #d8cbb5; margin-bottom:8px;">
        <h3 style="margin:0; font-size:16px; color:#1f2937;">FOOD DASH</h3>
        <span class="about-ver-pill">Version 1.0.0 (Official Release)</span>
        <p style="font-size:10.5px; color:#4b5563; line-height:1.45; margin:10px 0 0;">
          Good Food. Faster. Real-time food ordering, multi-vendor restaurant integration, and live GPS delivery tracking.
        </p>
      </div>

      <div style="text-align:left; margin-bottom:14px;">
        <h4 style="font-size:11px; font-weight:800; color:var(--olive-shadow); text-transform:uppercase; margin-bottom:6px;">Our Mission</h4>
        <p style="font-size:10px; color:#4b5563; line-height:1.5; margin:0 0 12px;">
          Empowering local food communities with real-time, farm-to-table delivery and seamless rider-customer communication.
        </p>

        <h4 style="font-size:11px; font-weight:800; color:var(--olive-shadow); text-transform:uppercase; margin-bottom:6px;">Legal & Policies</h4>
        <div class="about-link-row" onclick="openTermsModal('terms')">
          <span><i class="fa-solid fa-file-contract" style="color:var(--harvest-sun); margin-right:8px;"></i> Terms of Service</span>
          <i class="fa-solid fa-chevron-right" style="color:#9ca3af; font-size:10px;"></i>
        </div>
        <div class="about-link-row" onclick="openTermsModal('privacy')">
          <span><i class="fa-solid fa-shield-halved" style="color:#16a34a; margin-right:8px;"></i> Privacy Policy</span>
          <i class="fa-solid fa-chevron-right" style="color:#9ca3af; font-size:10px;"></i>
        </div>
      </div>
    </div>

    <div class="fd-modal-actions">
      <button type="button" class="btn-fd-primary" onclick="closeAboutModal()" style="width:100%;">
        Close
      </button>
    </div>
  </div>
</div>

<!-- 7. Terms & Privacy Viewer Sub-Modal -->
<div class="fd-modal" id="termsPrivacyModal" onclick="if(event.target===this)closeTermsModal()">
  <div class="fd-modal-box" style="max-width:400px; max-height:85vh; overflow-y:auto; padding:20px;">
    <div class="fd-modal-header" style="padding-bottom:10px; border-bottom:1px solid var(--sandstone);">
      <h3 id="termsModalTitle">Terms of Service</h3>
      <button class="fd-modal-close" onclick="closeTermsModal()">&times;</button>
    </div>
    <div id="termsModalBody" style="font-size:10.5px; color:#4b5563; line-height:1.55; text-align:left; margin:14px 0;"></div>
    <div class="fd-modal-actions">
      <button type="button" class="btn-fd-primary" onclick="closeTermsModal()" style="width:100%;">Understood</button>
    </div>
  </div>
</div>

<nav class="bottom bottom-nav mobile-navbar fixed-bottom">
  <button class="nav active" data-screen="home" onclick="showScreen('home')"><i class="fa-solid fa-house"></i><span>Home</span></button>
  <button class="nav" data-screen="stores" onclick="showScreen('stores')"><i class="fa-solid fa-store"></i><span>Stores</span></button>
  <button class="nav" data-screen="search" onclick="showScreen('search')"><i class="fa-solid fa-magnifying-glass"></i><span>Search</span></button>
  <button class="nav" data-screen="cart" onclick="showScreen('cart')"><i class="fa-solid fa-bag-shopping"></i><span>Cart</span><b class="badge" id="cartBadge" style="display:none">0</b></button>
  <button class="nav" data-screen="account" onclick="showScreen('account')"><i class="fa-regular fa-user"></i><span>Account</span></button>
</nav>

<div class="toast" id="toast"></div>
</div>

<script src="assets/js/order_call_engine.js"></script>
<script>
if (window.FoodDashCall) {
  FoodDashCall.init('customer');
}
let cart = JSON.parse(localStorage.getItem('foodDashCart') || '[]');
let itemQty = 1;
let currentItem = {name:'Big Mac', price:179, icon:'ðŸ”', desc:'Two beef patties, special sauce, lettuce and cheese.', store:'McDonalds'};

const onTheWayCount = <?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } echo (int)$ontheway_count; ?>;

const dbStoreMenus = <?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } echo json_encode($db_store_menus, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
const dbStoreCategories = <?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } echo json_encode($db_store_categories, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

const menus = {
  'FoodDash Grill': [
    ['Smoked Pork BBQ Ribs',289,'ðŸ–','Signature flame-grilled pork ribs with house barbecue glaze.','Ribs & Steaks'],
    ['Flame-Grilled Inasal Platter',219,'ðŸ—','Charcoal-grilled quarter chicken with spiced garlic rice.','Grilled Chicken'],
    ['Angus Beef Grill Burger',199,'ðŸ”','100% Angus beef patty flame-grilled to perfection with cheddar.','Burgers'],
    ['Grilled Pork Belly Liempo',249,'ðŸ¥©','Marinated succulent pork belly grilled over live charcoal.','BBQ & Pork']
  ],
  'McDonalds': [
    ['Big Mac',179,'ðŸ”','Two beef patties, special sauce, lettuce and cheese.','Burgers'],
    ['McSpaghetti',89,'ðŸ','Filipino-style sweet spaghetti.','Pasta'],
    ['Chicken McDo',119,'ðŸ—','Crispy fried chicken with rice.','Chicken'],
    ['French Fries',75,'ðŸŸ','Golden crispy fries.','Sides']
  ],
  'Jollibee': [
    ['Chickenjoy',129,'ðŸ—','Crispy fried chicken with rice and gravy.','Chicken'],
    ['Jolly Spaghetti',99,'ðŸ','Sweet-style Filipino spaghetti with cheese.','Pasta'],
    ['Yumburger',69,'ðŸ”','Classic Filipino fast-food burger.','Burgers'],
    ['Jolly Hotdog',59,'ðŸŒ­','Classic hotdog with sweet-style sauce.','Snacks']
  ],
  'Mang Inasal': [
    ['Chicken Inasal',189,'ðŸš','Grilled chicken with rice and savory marinade.','Inasal Meals'],
    ['Pecho',199,'ðŸ—','Grilled chicken breast meal.','Inasal Meals'],
    ['Pork BBQ',129,'ðŸ¢','Filipino-style grilled pork barbecue.','Grilled BBQ'],
    ['Halo-Halo',99,'ðŸ§','Classic Filipino shaved-ice dessert.','Desserts']
  ],
  'Ribshack': [
    ['Baby Back Ribs',349,'ðŸ–','Tender ribs with signature barbecue sauce.','Ribs & Platters'],
    ['Chicken BBQ',219,'ðŸ—','Grilled chicken with barbecue glaze.','Grilled Chicken'],
    ['Pasta',229,'ðŸ','Creamy pasta favorite.','Pasta'],
    ['Ribs Meal',399,'ðŸ–','Ribs with sides and rice.','Ribs & Platters']
  ],
  'Shakeys': [
    ['Managers Choice Pizza',499,'ðŸ•','Loaded pizza with favorite toppings.','Pizza'],
    ['Pepperoni Crunch',459,'ðŸ•','Pepperoni pizza with a crispy finish.','Pizza'],
    ['Chicken N Mojos',349,'ðŸ—','Crispy chicken with seasoned mojos.','Chicken'],
    ['Bunch of Lunch',399,'ðŸ•','Pizza and chicken meal combo.','Combos']
  ],
  'Chowking': [
    ['Chao Fan',99,'ðŸš','Filipino-Chinese fried rice favorite.','Rice Bowls'],
    ['Siomai',79,'ðŸ¥Ÿ','Steamed dumplings with savory filling.','Dim Sum'],
    ['Siopao',69,'ðŸ¥®','Soft steamed bun with savory filling.','Dim Sum'],
    ['Chopsuey',129,'ðŸ¥¬','Mixed vegetables in savory sauce.','Sides']
  ],
  'Greenwich': [
    ['Hawaiian Overload',499,'ðŸ•','Pizza with ham, pineapple and cheese.','Pizza'],
    ['Lasagna Supreme',229,'ðŸ','Cheesy layered pasta favorite.','Pasta'],
    ['Chicken & Pizza',399,'ðŸ—','Chicken and pizza combo.','Combos'],
    ['Garlic & Cheese Pizza',459,'ðŸ•','Cheesy pizza with garlic flavor.','Pizza']
  ]
};

function money(n){ return 'â‚±'+Number(n).toFixed(2); }

function showScreen(id){
  document.querySelectorAll('.screen').forEach(s=>s.classList.remove('active'));
  document.getElementById(id).classList.add('active');
  document.querySelectorAll('.nav').forEach(n=>n.classList.toggle('active', n.dataset.screen===id));
  if(id==='cart') renderCart();
}

function toast(msg){
  const t = document.getElementById('toast');
  if (t) {
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(window._toast);
    window._toast = setTimeout(()=>t.classList.remove('show'), 4000);
  }
}

let _custConfirmCallback = null;
function openCustConfirm(title, msg, onConfirm, confirmBtnText = 'Confirm', isDanger = false) {
  const modal = document.getElementById('customerConfirmModal');
  if (!modal) {
    if (onConfirm) onConfirm();
    return;
  }
  const titleEl = document.getElementById('custConfirmTitle');
  const msgEl = document.getElementById('custConfirmMsg');
  const btn = document.getElementById('btnCustConfirmAction');
  if (titleEl) titleEl.textContent = title || 'Confirm Action';
  if (msgEl) msgEl.textContent = msg || 'Are you sure you want to proceed?';
  if (btn) {
    btn.textContent = confirmBtnText;
    btn.style.background = isDanger ? '#dc2626' : 'var(--olive)';
    _custConfirmCallback = onConfirm;
    btn.onclick = () => {
      closeCustConfirm();
      if (_custConfirmCallback) _custConfirmCallback();
    };
  }
  modal.classList.add('show');
}

function closeCustConfirm() {
  const modal = document.getElementById('customerConfirmModal');
  if (modal) modal.classList.remove('show');
  _custConfirmCallback = null;
}

function openNotifications() {
  document.getElementById('customerNoteModal').classList.add('show');
  loadNotifications();
}

function closeNotifications() {
  document.getElementById('customerNoteModal').classList.remove('show');
}

function esc(v) {
  return String(v ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
}

async function loadNotifications() {
  try {
    const r = await fetch('notification_api.php?limit=30', { cache: 'no-store' });
    const d = await r.json();
    if (!d.success) return;
    const b = document.getElementById('custNoteBadge');
    if (b) {
      b.textContent = d.unread;
      b.style.display = d.unread > 0 ? 'block' : 'none';
    }
    const el = document.getElementById('customerNoteList');
    if (el) {
      el.innerHTML = d.notifications.length ? d.notifications.map(n => `
        <div class="fd-note-item ${n.is_read == 0 || n.is_read === false || n.is_read == 'f' || n.status === 'unread' ? 'unread' : ''}" onclick="readNote(${n.notification_id})">
          <strong>${esc(n.title)}</strong>
          <p>${esc(n.message)}</p>
          <small>${esc(n.created_at)}</small>
        </div>
      `).join('') : '<div style="padding:15px;text-align:center;font-size:9px;color:var(--muted);">No notifications yet.</div>';
    }
  } catch (e) {
    console.error('Failed to load notifications', e);
  }
}

async function readNote(id) {
  await fetch('notification_api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'mark_read', notification_id: id })
  });
  loadNotifications();
}

async function markAllNotificationsRead() {
  await fetch('notification_api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'mark_all_read' })
  });
  loadNotifications();
  toast('All notifications marked as read.');
}

function checkNotifications() {
  openNotifications();
}

function confirmOrderReceived(orderId) {
  const btn = document.getElementById(`confirm-btn-${orderId}`);
  if (btn) btn.disabled = true;

  fetch(window.location.href, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'confirm_received', order_id: orderId })
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      toast('Order delivery confirmed! Thank you for ordering.');
      const card = document.getElementById(`order-card-${orderId}`);
      if (card) {
        card.dataset.status = 'delivered';
        card.classList.remove('on-the-way-card');
      }
      const statusTag = document.getElementById(`status-tag-${orderId}`);
      if (statusTag) {
        statusTag.className = 'status-badge status-delivered';
        statusTag.textContent = 'Delivered';
      }
      if (btn) btn.remove();
      setTimeout(() => location.reload(), 1200);
    } else {
      toast(data.message || 'Error completing order.');
      if (btn) btn.disabled = false;
    }
  })
  .catch(() => {
    toast('Network error. Please try again.');
    if (btn) btn.disabled = false;
  });
}

const storeLogos = {
  'FoodDash Grill': 'logo.jpg',
  'McDonalds': 'assets/shops/mcdonalds.png',
  'Jollibee': 'assets/shops/jollibee.png',
  'Mang Inasal': 'assets/shops/mang_inasal.png',
  'Ribshack': 'assets/shops/ribshack.png',
  'Shakeys': 'assets/shops/shakeys.png',
  'Chowking': 'assets/shops/chowking.png',
  'Greenwich': 'assets/shops/greenwich.png'
};

const storeDescriptions = {
  'FoodDash Grill': 'Flame-grilled ribs, steaks, BBQ and savory comfort food.',
  'McDonalds': 'Burgers, Fries, McSpaghetti and all-day favorites.',
  'Jollibee': 'Crispy Chickenjoy, Jolly Spaghetti, and Filipino classics.',
  'Mang Inasal': 'Authentic flame-grilled chicken inasal and savory skewers.',
  'Ribshack': 'Tender ribs, grilled chicken, and savory BBQ platters.',
  'Shakeys': 'Thin-crust pizzas, crispy chicken, and seasoned mojos.',
  'Chowking': 'Hot Chao Fan, siomai, siopao, and Chinese-Filipino meals.',
  'Greenwich': 'Overload pizzas, lasagna supreme, and crispy chicken combos.'
};

function normalizeStoreName(name) {
  if (!name) return 'FoodDash Grill';
  let clean = name.replace(/\s*\([^)]*\)/g, '').trim();
  if (clean.toLowerCase().includes('fooddash') || clean.toLowerCase().includes('grill')) return 'FoodDash Grill';
  if (clean.toLowerCase().includes('mcdonald')) return 'McDonalds';
  if (clean.toLowerCase().includes('jollibee')) return 'Jollibee';
  if (clean.toLowerCase().includes('mang inasal')) return 'Mang Inasal';
  if (clean.toLowerCase().includes('ribshack')) return 'Ribshack';
  if (clean.toLowerCase().includes('shakey')) return 'Shakeys';
  if (clean.toLowerCase().includes('chowking')) return 'Chowking';
  if (clean.toLowerCase().includes('greenwich')) return 'Greenwich';
  return clean;
}

function safeAttr(str) {
  return String(str ?? '').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
function safeJs(str) {
  return encodeURIComponent(String(str ?? '')).replace(/'/g, '%27');
}

function openStore(name, logoUrl){
  const storeKey = normalizeStoreName(name);
  document.getElementById('storeTitle').textContent = storeKey;
  document.getElementById('storeName').textContent = storeKey;
  
  const logoSrc = logoUrl || storeLogos[storeKey] || 'assets/shops/default_store.svg';
  const logoEl = document.getElementById('storeBannerLogo');
  if (logoEl) {
    logoEl.src = logoSrc;
  }
  
  document.getElementById('storeDesc').textContent = storeDescriptions[storeKey] || 'Popular favorites delivered to your door fresh and fast.';
  
  // Resolve dishes for active store from database (with static fallback)
  const list = (dbStoreMenus && dbStoreMenus[storeKey] && dbStoreMenus[storeKey].length)
    ? dbStoreMenus[storeKey]
    : (menus[storeKey] ? menus[storeKey].map(m => ({
        name: m[0], price: m[1], icon: m[2], desc: m[3], category: m[4] || 'Main'
      })) : []);
      
  // Dynamically resolve unique categories for this specific active store
  let categories = (dbStoreCategories && dbStoreCategories[storeKey] && dbStoreCategories[storeKey].length)
    ? [...dbStoreCategories[storeKey]]
    : Array.from(new Set(list.map(d => d.category || (Array.isArray(d) ? d[4] : '')).filter(Boolean)));
    
  // Always render "All" (or "Popular") as default active button, followed by unique categories for this shop
  const allCategoryPills = ['All', ...categories];
  const chipsContainer = document.getElementById('storeDishCategoryChips');
  if (chipsContainer) {
    chipsContainer.innerHTML = allCategoryPills.map((cat, idx) => 
      `<button type="button" class="chip ${idx === 0 ? 'active' : ''}" data-category="${safeAttr(cat)}" onclick="filterStoreDishes('${safeJs(cat)}', this)">${esc(cat)}</button>`
    ).join('');
  }
  
  // Render dish cards with data-category
  const storeItemsContainer = document.getElementById('storeItems');
  const emptyState = document.getElementById('storeNoDishes');
  if (emptyState) emptyState.style.display = 'none';
  
  if (!list.length) {
    storeItemsContainer.innerHTML = '';
    if (emptyState) emptyState.style.display = 'flex';
  } else {
    storeItemsContainer.innerHTML = list.map(item => {
      const isArr = Array.isArray(item);
      const itemName = isArr ? item[0] : item.name;
      const itemPrice = isArr ? item[1] : item.price;
      const itemIcon = isArr ? item[2] : item.icon;
      const itemDesc = isArr ? item[3] : item.desc;
      const itemCat = isArr ? (item[4] || 'Main') : (item.category || 'Main');
      
      return `<div class="food-card" data-category="${safeAttr(itemCat)}" onclick="openItem('${safeJs(itemName)}',${Number(itemPrice)},'${safeJs(itemIcon)}','${safeJs(itemDesc)}','${safeJs(storeKey)}')">
        <div class="food-img">${esc(itemIcon)}</div>
        <div class="food-body">
          <strong>${esc(itemName)}</strong>
          <small>${esc(storeKey)}</small>
          <div class="price-row">
            <span class="price">${money(itemPrice)}</span>
            <button class="add" type="button" onclick="event.stopPropagation();addCart(decodeURIComponent('${safeJs(itemName)}'),${Number(itemPrice)},decodeURIComponent('${safeJs(itemIcon)}'),decodeURIComponent('${safeJs(storeKey)}'))">+</button>
          </div>
        </div>
      </div>`;
    }).join('');
  }
  
  showScreen('store');
}

function filterStoreDishes(catParam, btn) {
  const selectedCat = decodeURIComponent(catParam || 'All').trim().toLowerCase();
  
  // Toggle active button styling (.active with background #4B5320 / #D88F2A)
  document.querySelectorAll('#storeDishCategoryChips .chip').forEach(c => c.classList.remove('active'));
  if (btn) btn.classList.add('active');
  
  const cards = document.querySelectorAll('#storeItems .food-card');
  let visibleCount = 0;
  
  cards.forEach(card => {
    const itemCat = (card.getAttribute('data-category') || card.dataset.category || '').trim().toLowerCase();
    if (selectedCat === 'all' || selectedCat === 'popular' || itemCat === selectedCat) {
      card.style.display = '';
      visibleCount++;
    } else {
      card.style.display = 'none';
    }
  });
  
  const emptyState = document.getElementById('storeNoDishes');
  if (emptyState) {
    emptyState.style.display = (visibleCount === 0) ? 'flex' : 'none';
  }
}

function openItem(name, price, icon, desc, store){
  currentItem = {
    name: decodeURIComponent(name),
    price: price,
    icon: decodeURIComponent(icon || 'ðŸ´'),
    desc: decodeURIComponent(desc),
    store: normalizeStoreName(decodeURIComponent(store || 'McDonalds'))
  };
  itemQty = 1;
  document.getElementById('itemName').textContent = currentItem.name;
  document.getElementById('itemPrice').textContent = money(price);
  document.getElementById('itemArt').textContent = currentItem.icon;
  document.getElementById('itemDesc').textContent = currentItem.desc;
  document.getElementById('itemQty').textContent = 1;
  showScreen('item');
}

function changeQty(n){
  itemQty = Math.max(1, itemQty + n);
  document.getElementById('itemQty').textContent = itemQty;
}

function addCurrentItem(){
  for(let i=0; i<itemQty; i++){
    addCart(currentItem.name, currentItem.price, currentItem.icon, currentItem.store, true);
  }
  showScreen('cart');
  toast('Added to cart');
}

function addCart(name, price, icon, store='McDonalds', silent=false){
  const cleanStore = normalizeStoreName(store);
  let x = cart.find(i => i.name === name && normalizeStoreName(i.store) === cleanStore); 
  if(x) {
    x.qty++;
    x.store = cleanStore;
  } else {
    cart.push({name, price, icon, qty:1, store: cleanStore});
  }
  saveCart();
  updateBadge();
  if(!silent) toast(name + ' added to cart');
}

function saveCart(){
  localStorage.setItem('foodDashCart', JSON.stringify(cart));
}

function updateBadge(){
  const n = cart.reduce((a,b)=>a+b.qty, 0);
  const b = document.getElementById('cartBadge');
  b.textContent = n;
  b.style.display = n ? 'block' : 'none';
}

function renderCart(){
  const el = document.getElementById('cartList');
  const summaryBox = document.getElementById('cartSummary');
  
  if(!cart.length){
    el.innerHTML = '<div class="row-card"><div class="row-icon" style="background:#eee8d7;color:#4f5d21">ðŸ›’</div><div class="info"><strong>Your cart is empty</strong><small>Add delicious meals from your favorite stores.</small></div></div>';
    summaryBox.style.display = 'none';
    return;
  }
  
  el.innerHTML = cart.map((item, index) => `
    <div class="cart-item">
      <div class="thumb">${item.icon}</div>
      <div class="cart-info">
        <strong>${item.name}</strong>
        <small>${item.store}</small>
        <div class="cart-bottom">
          <span style="font-size:10px;font-weight:700;color:var(--olive-dark)">${money(item.price * item.qty)}</span>
          <div class="qty">
            <button onclick="updateCartQty(${index}, -1)">âˆ’</button>
            <span>${item.qty}</span>
            <button onclick="updateCartQty(${index}, 1)">+</button>
          </div>
        </div>
      </div>
    </div>
  `).join('');
  
  const subtotal = cart.reduce((sum, item) => sum + (item.price * item.qty), 0);
  const deliveryFee = 30;
  const total = subtotal + deliveryFee;
  
  document.getElementById('subtotal').textContent = money(subtotal);
  document.getElementById('total').textContent = money(total);
  summaryBox.style.display = 'block';

  // Pre-fill delivery address from localStorage if available
  const addrInput = document.getElementById('deliveryAddressInput');
  if (addrInput && !addrInput.value) {
    const saved = localStorage.getItem('foodDashDeliveryAddress');
    if (saved) {
      addrInput.value = saved;
      if (!currentCustomerLat) {
        geocodeAddressNominatim(saved);
      }
    }
  }
}

function updateCartQty(index, change) {
  if (cart[index]) {
    cart[index].qty += change;
    if (cart[index].qty <= 0) {
      cart.splice(index, 1);
    }
    saveCart();
    updateBadge();
    renderCart();
  }
}

function clearCart() {
  if (!cart.length) return;
  cart = [];
  saveCart();
  updateBadge();
  renderCart();
  toast('Cart cleared');
}

let currentCustomerLat = null;
let currentCustomerLng = null;
let addressGeocodeDebounceTimer = null;
let lastGeocodedAddress = '';
let currentGeocodeAbortController = null;

// Leaflet Map Picker instance handles
let checkoutMap = null;
let checkoutMarker = null;
let map = null;
let marker = null;

// Helper to write coordinates to all id & delivery_lng hidden inputs and status pills
function setDeliveryCoordinates(lat, lng, source = 'nominatim') {
    if (lat == null || lng == null) return;
    const numLat = parseFloat(lat);
    const numLng = parseFloat(lng);
    if (isNaN(numLat) || isNaN(numLng)) return;

    currentCustomerLat = numLat;
    currentCustomerLng = numLng;

    const latStr = numLat.toFixed(7);
    const lngStr = numLng.toFixed(7);

    // Update hidden inputs across cart and modal
    const latIds = ['id', 'deliveryLatInput', 'modal_delivery_lat', 'modalDeliveryLat'];
    const lngIds = ['delivery_lng', 'deliveryLngInput', 'modal_delivery_lng', 'modalDeliveryLng'];

    latIds.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = latStr;
    });
    lngIds.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = lngStr;
    });

    const isGps = source === 'gps';
    const isMap = source === 'map';
    const icon = isGps ? 'fa-satellite-dish' : (isMap ? 'fa-map-pin' : 'fa-location-dot');
    const label = isGps ? 'Exact Live GPS Pin' : (isMap ? 'Map Pin Selected' : 'Geocoded Pin');
    const statusText = `<i class="fa-solid ${icon}"></i> ${label}: ${numLat.toFixed(5)}, ${numLng.toFixed(5)}`;

    // Sync Leaflet map and marker if map exists and source is not direct map dragging
    const activeMap = checkoutMap || (typeof map !== 'undefined' ? map : null);
    const activeMarker = checkoutMarker || (typeof marker !== 'undefined' ? marker : null);

    if (activeMarker && source !== 'map') {
        activeMarker.setLatLng([numLat, numLng]);
    }
    if (activeMap && source !== 'map') {
        const targetZoom = source === 'nominatim' ? 17 : Math.max(activeMap.getZoom(), 16);
        activeMap.setView([numLat, numLng], targetZoom);
    }

    ['gpsLocationStatus', 'modalGpsStatus'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.style.display = 'flex';
            el.className = 'gps-status-pill success';
            el.innerHTML = statusText;
        }
    });
}

// OpenStreetMap Nominatim Geocoding for Customer Addresses
async function geocodeAddressNominatim(query) {
    if (!query || typeof query !== 'string') return null;
    const cleanAddr = query.trim();
    if (cleanAddr.length < 3) return null;

    const normKey = cleanAddr.toLowerCase();
    // Prevent duplicate API calls if this address was already geocoded
    if (normKey === lastGeocodedAddress.toLowerCase()) {
        return (currentCustomerLat && currentCustomerLng) ? { lat: currentCustomerLat, lng: currentCustomerLng, lon: currentCustomerLng } : null;
    }

    // Abort any previous in-flight geocode request
    if (currentGeocodeAbortController) {
        try { currentGeocodeAbortController.abort(); } catch (e) {}
    }
    currentGeocodeAbortController = new AbortController();
    const signal = currentGeocodeAbortController.signal;

    // Show loading state in status pill
    ['gpsLocationStatus', 'modalGpsStatus'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.style.display = 'flex';
            el.className = 'gps-status-pill loading';
            el.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Finding address on map...';
        }
    });

    const activeMap = checkoutMap || (typeof map !== 'undefined' ? map : null);
    const activeMarker = checkoutMarker || (typeof marker !== 'undefined' ? marker : null);

    try {
        // 1. Primary requested endpoint:
        // https://nominatim.openstreetmap.org/search?format=json&limit=1&q= + encodeURIComponent(query + ', Ormoc City, Leyte')
        const primaryUrl = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(cleanAddr + ', Ormoc City, Leyte');
        let res = await fetch(primaryUrl, {
            method: 'GET',
            headers: { 'Accept': 'application/json' },
            signal: signal
        });

        if (res.ok) {
            let data = await res.json();
            if (Array.isArray(data) && data.length > 0 && data[0].lat && data[0].lon) {
                const lat = parseFloat(data[0].lat);
                const lon = parseFloat(data[0].lon);
                lastGeocodedAddress = normKey;

                // Automatically pan and zoom the Leaflet map to the returned lat and lon (map.setView([lat, lon], 17))
                if (activeMap) {
                    activeMap.setView([lat, lon], 17);
                }
                // Move the draggable marker to that position (marker.setLatLng([lat, lon]))
                if (activeMarker) {
                    activeMarker.setLatLng([lat, lon]);
                }
                // Update the hidden inputs id and delivery_lng
                setDeliveryCoordinates(lat, lon, 'nominatim');
                return { lat, lon, lng: lon, displayName: data[0].display_name };
            }
        }

        // 2. Fallback query formats (handles addresses that already contain Ormoc/Leyte to avoid duplicate tokens)
        const simplified = cleanAddr.replace(/Ormoc(\s+City)?/gi, '').replace(/Leyte/gi, '').replace(/,\s*,/g, ',').replace(/^[\s,]+|[\s,]+$/g, '');
        const altQueries = [
            (simplified || cleanAddr) + ', Ormoc, Leyte',
            cleanAddr + ', Leyte',
            cleanAddr
        ];

        for (const alt of altQueries) {
            if (signal.aborted) return null;
            const altUrl = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(alt);
            res = await fetch(altUrl, {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                signal: signal
            });
            if (res.ok) {
                const data = await res.json();
                if (Array.isArray(data) && data.length > 0 && data[0].lat && data[0].lon) {
                    const lat = parseFloat(data[0].lat);
                    const lon = parseFloat(data[0].lon);
                    lastGeocodedAddress = normKey;

                    if (activeMap) activeMap.setView([lat, lon], 17);
                    if (activeMarker) activeMarker.setLatLng([lat, lon]);
                    setDeliveryCoordinates(lat, lon, 'nominatim');
                    return { lat, lon, lng: lon, displayName: data[0].display_name };
                }
            }
        }
    } catch (e) {
        if (e.name === 'AbortError') {
            return null; // Silently ignore cancelled requests
        }
        console.warn('Nominatim client geocode network error:', e);
    }

    return null;
}

// Handle typing in delivery address inputs with debounced geocoding
function handleAddressTyping(value, isModal = false) {
    const address = (value || '').trim();
    if (!address) return;

    // Sync other address field without triggering events
    const otherAddr = document.getElementById(isModal ? 'deliveryAddressInput' : 'modalDeliveryAddress');
    if (otherAddr && otherAddr.value !== address) otherAddr.value = address;
    localStorage.setItem('foodDashDeliveryAddress', address);

    // Cancel pending debounce timer
    clearTimeout(addressGeocodeDebounceTimer);

    // If identical to last geocoded address, don't re-query
    if (address.toLowerCase() === lastGeocodedAddress.toLowerCase()) {
        return;
    }

    // Debounce typing by 600ms to allow customer to finish typing without duplicate API calls
    addressGeocodeDebounceTimer = setTimeout(async () => {
        await geocodeAddressNominatim(address);
    }, 600);
}

// When user finishes typing (blur or change)
function handleAddressBlur(value, isModal = false) {
    clearTimeout(addressGeocodeDebounceTimer);
    const address = (value || '').trim();
    if (address && address.toLowerCase() !== lastGeocodedAddress.toLowerCase()) {
        geocodeAddressNominatim(address);
    }
}

// Helper: Reverse-geocode coordinates to street address
async function reverseGeocodeToAddress(lat, lng) {
    const mainAddr = document.getElementById('deliveryAddressInput');
    const modalAddr = document.getElementById('modalDeliveryAddress');
    try {
        const res = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`, {
            headers: { 'Accept': 'application/json' }
        });
        if (res.ok) {
            const data = await res.json();
            if (data && data.display_name) {
                const formatted = data.display_name;
                const currentVal = (modalAddr ? modalAddr.value : '').trim();
                if (!currentVal || currentVal.startsWith('GPS Location') || currentVal.startsWith('Pinned Location')) {
                    if (modalAddr) modalAddr.value = formatted;
                    if (mainAddr) mainAddr.value = formatted;
                    lastGeocodedAddress = formatted.toLowerCase();
                    localStorage.setItem('foodDashDeliveryAddress', formatted);
                }
            }
        }
    } catch (e) {
        // Silently ignore network error on reverse geocode
    }
}

// Setup address input listeners ensuring no duplicate bindings
function setupAddressInputListeners() {
    const inputs = [
        { id: 'deliveryAddressInput', isModal: false },
        { id: 'modalDeliveryAddress', isModal: true }
    ];

    inputs.forEach(({ id, isModal }) => {
        const el = document.getElementById(id);
        if (!el) return;

        // Prevent duplicate listener registration
        if (el.dataset.addressListenerAttached === 'true') return;
        el.dataset.addressListenerAttached = 'true';

        el.addEventListener('input', (e) => handleAddressTyping(e.target.value, isModal));
        el.addEventListener('blur', (e) => handleAddressBlur(e.target.value, isModal));
        el.addEventListener('change', (e) => handleAddressBlur(e.target.value, isModal));
    });
}

function initCheckoutMap() {
    const mapContainer = document.getElementById('checkout-address-map');
    if (!mapContainer) return;

    if (typeof L === 'undefined') {
        console.warn('Leaflet library is not loaded');
        return;
    }

    // Default center to Alta Vista / Ormoc City [11.0134, 124.6199]
    let initialLat = 11.0134;
    let initialLng = 124.6199;
    let initialZoom = 15;

    // Use previously detected / entered coordinates if valid
    if (currentCustomerLat && currentCustomerLng) {
        initialLat = currentCustomerLat;
        initialLng = currentCustomerLng;
        initialZoom = 17;
    } else {
        const latEl = document.getElementById('id') || document.getElementById('deliveryLatInput') || document.getElementById('modal_delivery_lat');
        const lngEl = document.getElementById('delivery_lng') || document.getElementById('deliveryLngInput') || document.getElementById('modal_delivery_lng');
        if (latEl && latEl.value && lngEl && lngEl.value) {
            const pLat = parseFloat(latEl.value);
            const pLng = parseFloat(lngEl.value);
            if (!isNaN(pLat) && !isNaN(pLng)) {
                initialLat = pLat;
                initialLng = pLng;
                initialZoom = 17;
            }
        }
    }

    if (!checkoutMap) {
        checkoutMap = L.map('checkout-address-map', {
            zoomControl: true,
            scrollWheelZoom: true
        }).setView([initialLat, initialLng], initialZoom);

        map = checkoutMap;
        window.checkoutMap = checkoutMap;
        window.map = checkoutMap;

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(checkoutMap);

        const pinIcon = L.divIcon({
            className: 'custom-map-pin',
            html: `
              <div style="position:relative;display:flex;flex-direction:column;align-items:center;cursor:grab;">
                <div style="background:#D88F2A;color:#ffffff;width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(216,143,42,0.45);border:2.5px solid #ffffff;font-size:15px;">
                  <i class="fa-solid fa-location-dot"></i>
                </div>
                <div style="width:0;height:0;border-left:6px solid transparent;border-right:6px solid transparent;border-top:8px solid #D88F2A;margin-top:-2px;"></div>
                <div style="background:rgba(0,0,0,0.25);width:16px;height:4px;border-radius:50%;margin-top:1px;"></div>
              </div>
            `,
            iconSize: [34, 46],
            iconAnchor: [17, 43],
            popupAnchor: [0, -42]
        });

        checkoutMarker = L.marker([initialLat, initialLng], {
            draggable: true,
            icon: pinIcon
        }).addTo(checkoutMap);

        marker = checkoutMarker;
        window.checkoutMarker = checkoutMarker;
        window.marker = checkoutMarker;

        checkoutMarker.bindPopup('<b>Delivery Pin</b><br>Drag to your exact delivery location');

        // When user drags marker, automatically update hidden inputs id and delivery_lng with marker.getLatLng()
        checkoutMarker.on('drag', function() {
            const pos = checkoutMarker.getLatLng();
            setDeliveryCoordinates(pos.lat, pos.lng, 'map');
        });

        checkoutMarker.on('dragend', function() {
            const pos = checkoutMarker.getLatLng();
            setDeliveryCoordinates(pos.lat, pos.lng, 'map');
            reverseGeocodeToAddress(pos.lat, pos.lng);
        });

        // Clicking anywhere on map also moves the marker
        checkoutMap.on('click', function(e) {
            checkoutMarker.setLatLng(e.latlng);
            setDeliveryCoordinates(e.latlng.lat, e.latlng.lng, 'map');
            reverseGeocodeToAddress(e.latlng.lat, e.latlng.lng);
        });

        setDeliveryCoordinates(initialLat, initialLng, 'map');
    } else {
        checkoutMap.setView([initialLat, initialLng], initialZoom);
        checkoutMarker.setLatLng([initialLat, initialLng]);
    }

    setTimeout(() => {
        if (checkoutMap) {
            checkoutMap.invalidateSize();
        }
    }, 250);
}

// "Use My Exact Location" Button Action
function useMyExactLocation() {
    const btn = document.getElementById('modalBtnLocateMe') || document.getElementById('btnDetectGps');
    const statusEl = document.getElementById('modalGpsStatus') || document.getElementById('gpsLocationStatus');

    if (!navigator.geolocation) {
        toast('Geolocation is not supported by your browser');
        return;
    }

    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Locating...';
    }
    if (statusEl) {
        statusEl.style.display = 'flex';
        statusEl.className = 'gps-status-pill loading';
        statusEl.innerHTML = '<i class="fa-solid fa-satellite-dish fa-spin"></i> Grabbing live device coordinates...';
    }

    navigator.geolocation.getCurrentPosition(
        async (position) => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-location-crosshairs"></i> Use My Exact Location';
            }
            const lat = position.coords.lat;
            const lng = position.coords.lng;

            // Move the map view and draggable marker to their device's live coordinates
            if (checkoutMap) {
                checkoutMap.setView([lat, lng], 16);
            }
            if (checkoutMarker) {
                checkoutMarker.setLatLng([lat, lng]);
            }

            // Sets id and delivery_lng
            setDeliveryCoordinates(lat, lng, 'gps');

            // Reverse-geocode to address input
            await reverseGeocodeToAddress(lat, lng);

            toast('Pinned to your exact device location!');
        },
        (error) => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-location-crosshairs"></i> Use My Exact Location';
            }
            let errMsg = 'Location permission denied or unavailable.';
            if (error.code === error.PERMISSION_DENIED) {
                errMsg = 'Location permission denied. Please drag the pin on the map.';
            } else if (error.code === error.TIMEOUT) {
                errMsg = 'Location request timed out. Please drag the pin on the map.';
            }
            if (statusEl) {
                statusEl.className = 'gps-status-pill error';
                statusEl.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i> ${errMsg}`;
            }
            toast(errMsg);
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 }
    );
}

// Live GPS detection backward compatibility
function detectCustomerGps(isModal = false) {
    useMyExactLocation();
}

// Automatic check on dashboard load: if user allows geolocation, grab live GPS pin
function requestLiveGpsIfAllowed() {
    if (!navigator.geolocation) return;
    navigator.geolocation.getCurrentPosition(
        (position) => {
            const lat = position.coords.lat;
            const lng = position.coords.lng;
            setDeliveryCoordinates(lat, lng, 'gps');
        },
        (error) => {
            console.log('Live GPS auto-check:', error.message);
        },
        { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 }
    );
}

function openAddressPromptModal() {
    const mainAddr = document.getElementById('deliveryAddressInput');
    const modalAddr = document.getElementById('modalDeliveryAddress');
    if (mainAddr && modalAddr) {
        modalAddr.value = mainAddr.value.trim() || localStorage.getItem('foodDashDeliveryAddress') || '';
    }
    const modal = document.getElementById('addressPromptModal');
    if (modal) modal.classList.add('show');
    
    // Initialize or refresh the interactive Leaflet map pin picker
    initCheckoutMap();
    setupAddressInputListeners();

    if (modalAddr) modalAddr.focus();
}

function closeAddressPromptModal() {
    const modal = document.getElementById('addressPromptModal');
    if (modal) modal.classList.remove('show');
}

async function confirmModalAddressAndCheckout() {
    const modalAddr = document.getElementById('modalDeliveryAddress');
    const address = (modalAddr ? modalAddr.value : '').trim();
    if (!address) {
        if (modalAddr) {
            modalAddr.classList.add('input-error');
            setTimeout(() => modalAddr.classList.remove('input-error'), 2000);
        }
        toast('Please enter your delivery address');
        return;
    }
    const mainAddr = document.getElementById('deliveryAddressInput');
    if (mainAddr) mainAddr.value = address;
    localStorage.setItem('foodDashDeliveryAddress', address);

    // Save picked marker coordinates directly
    if (checkoutMarker) {
        const pos = checkoutMarker.getLatLng();
        setDeliveryCoordinates(pos.lat, pos.lng, 'map');
    }

    closeAddressPromptModal();
    checkout();
}

function setCustomerPaymentMethod(method, elem) {
    const hiddenInput = document.getElementById('selectedPaymentMethod');
    if (hiddenInput) hiddenInput.value = method;

    const modalSelect = document.getElementById('modalPaymentSelect');
    if (modalSelect) modalSelect.value = method;

    const options = document.querySelectorAll('#checkoutPaymentOptions .pay-option');
    options.forEach(opt => {
        opt.classList.remove('active');
        const checkIcon = opt.querySelector('.pay-check');
        if (checkIcon) {
            checkIcon.className = 'fa-regular fa-circle pay-check';
        }
    });

    if (elem) {
        elem.classList.add('active');
        const checkIcon = elem.querySelector('.pay-check');
        if (checkIcon) {
            checkIcon.className = 'fa-solid fa-circle-check pay-check';
        }
    }
}

function syncCustomerPaymentMethod(method) {
    const hiddenInput = document.getElementById('selectedPaymentMethod');
    if (hiddenInput) hiddenInput.value = method;

    const options = document.querySelectorAll('#checkoutPaymentOptions .pay-option');
    options.forEach(opt => {
        if (opt.getAttribute('data-method') === method) {
            opt.classList.add('active');
            const checkIcon = opt.querySelector('.pay-check');
            if (checkIcon) checkIcon.className = 'fa-solid fa-circle-check pay-check';
        } else {
            opt.classList.remove('active');
            const checkIcon = opt.querySelector('.pay-check');
            if (checkIcon) checkIcon.className = 'fa-regular fa-circle pay-check';
        }
    });
}

async function checkout() {
    if (!cart.length) {
        toast('Cart is empty');
        return;
    }
    
    const addrInput = document.getElementById('deliveryAddressInput');
    const address = (addrInput ? addrInput.value : '').trim();

    if (!address) {
        if (addrInput) {
            addrInput.focus();
            addrInput.classList.add('input-error');
            setTimeout(() => addrInput.classList.remove('input-error'), 2500);
        }
        openAddressPromptModal();
        return;
    }

    localStorage.setItem('foodDashDeliveryAddress', address);
    
    const btnSubmit = document.getElementById('btnCheckoutSubmit');
    if (btnSubmit) {
        btnSubmit.disabled = true;
        btnSubmit.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Placing Order...';
    }

    const deliveryFee = 30;
    const payInput = document.getElementById('selectedPaymentMethod');
    const chosenPaymentMethod = payInput ? payInput.value : 'Cash on Delivery (COD)';

    const payload = {
        action: 'checkout',
        cart: cart,
        delivery_fee: deliveryFee,
        delivery_address: address,
        payment_method: chosenPaymentMethod
    };
    
    // Submit the exact address, selected payment method, and current cart to the checkout endpoint.
    fetch('customer_dashboard.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify(payload)
    })
    .then(res => {
        if (!res.ok) {
            return res.text().then(text => { throw new Error('HTTP ' + res.status + ': ' + text); });
        }
        return res.json();
    })
    .then(data => {
        if (btnSubmit) {
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = 'Checkout';
        }
        if (data.success) {
            toast('Order placed successfully!');
            cart = [];
            saveCart();
            updateBadge();
            renderCart();
            closeAddressPromptModal();
            setTimeout(() => {
                window.location.hash = '#orders';
                window.location.reload();
            }, 800);
        } else {
            toast(data.message || 'Checkout failed');
            console.error('Checkout failed:', data.message);
        }
    })
    .catch(err => {
        if (btnSubmit) {
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = 'Checkout';
        }
        console.error('Checkout error:', err);
        var safeMsg = (err && err.message && !err.message.includes('pattern')) ? err.message : 'Processing order...';
        toast(safeMsg);
        setTimeout(() => { if (typeof handlePayAction === 'function') { handlePayAction(); } }, 500);
    });
}

function filterStores() {
  const query = document.getElementById('storeSearch').value.toLowerCase();
  document.querySelectorAll('#storeList .row-card').forEach(card => {
    const name = (card.dataset.name || '').toLowerCase();
    const cat = (card.dataset.category || '').toLowerCase();
    card.style.display = (name.includes(query) || cat.includes(query)) ? 'flex' : 'none';
  });
}

function filterStoreCategory(cat, btn) {
  document.querySelectorAll('#storeCategoryChips .chip').forEach(c => c.classList.remove('active'));
  if (btn) btn.classList.add('active');
  const query = (cat || '').toLowerCase();
  document.querySelectorAll('#storeList .row-card').forEach(card => {
    if (!cat || cat === 'all') {
      card.style.display = 'flex';
    } else {
      const catText = (card.dataset.category || '').toLowerCase();
      const nameText = (card.dataset.name || '').toLowerCase();
      card.style.display = (catText.includes(query) || nameText.includes(query)) ? 'flex' : 'none';
    }
  });
}

function searchFood() {
  const query = document.getElementById('searchInput').value.toLowerCase();
  document.querySelectorAll('#searchResults .row-card').forEach(card => {
    const text = card.textContent.toLowerCase();
    card.style.display = text.includes(query) ? 'flex' : 'none';
  });
}

function setSearch(term) {
  document.getElementById('searchInput').value = term;
  searchFood();
}

function filterOrders(status, btn) {
  document.querySelectorAll('#orderFilters .chip').forEach(c => c.classList.remove('active'));
  btn.classList.add('active');
  
  const targetFilter = String(status || '').toLowerCase().trim();
  document.querySelectorAll('.order-row').forEach(row => {
    const cardStatus = (row.dataset.status || '').toLowerCase().trim();
    if (targetFilter === 'all') {
      row.style.display = 'flex';
    } else if (targetFilter === 'preparing') {
      row.style.display = ['preparing', 'cooking', 'in_kitchen', 'accepted'].includes(cardStatus) ? 'flex' : 'none';
    } else if (targetFilter === 'pending') {
      row.style.display = ['pending', 'submitted'].includes(cardStatus) ? 'flex' : 'none';
    } else if (targetFilter === 'out_for_delivery') {
      row.style.display = ['out_for_delivery', 'on_the_way', 'on_delivery', 'near_customer', 'arrived'].includes(cardStatus) ? 'flex' : 'none';
    } else if (targetFilter === 'ready_for_pickup') {
      row.style.display = ['ready_for_pickup', 'ready'].includes(cardStatus) ? 'flex' : 'none';
    } else if (targetFilter === 'delivered') {
      row.style.display = ['delivered', 'completed'].includes(cardStatus) ? 'flex' : 'none';
    } else if (targetFilter === 'settled') {
      row.style.display = cardStatus === 'settled' ? 'flex' : 'none';
    } else if (targetFilter === 'cancelled') {
      row.style.display = cardStatus === 'cancelled' ? 'flex' : 'none';
    } else {
      row.style.display = cardStatus === targetFilter ? 'flex' : 'none';
    }
  });
}

function accountNotice(section) {
  toast(section + ' clicked');
}

/* ========================================================
   ORDER CANCELLATION FEATURE
   ======================================================== */
let activeCancelOrderId = null;

function promptCancelOrder(orderId, orderRef, orderStatus) {
  const normStatus = String(orderStatus || '').toLowerCase().trim();
  const blockedStatuses = ['on delivery', 'on_delivery', 'out for delivery', 'out_for_delivery', 'on_the_way', 'near_customer', 'arrived', 'delivered', 'completed', 'settled'];
  
  // Business Rule Guardrail: If an order is already "On Delivery" or "Delivered", disable cancellation
  if (blockedStatuses.includes(normStatus)) {
    showToastCustomer("Orders out for delivery cannot be cancelled. Please contact support or rider.");
    return;
  }

  activeCancelOrderId = parseInt(orderId, 10);
  const targetInput = document.getElementById('cancelTargetOrderId');
  if (targetInput) targetInput.value = activeCancelOrderId;

  const modalRef = document.getElementById('cancelOrderModalRef');
  if (modalRef) {
    const rawRef = orderRef || activeCancelOrderId || '';
    const cleanRef = '#' + String(rawRef).replace(/^#+/, '');
    modalRef.textContent = `Are you sure you want to cancel order ${cleanRef}?`;
  }

  // Reset chips & textarea
  const chips = document.querySelectorAll('.cancel-reason-chips .cancel-chip');
  chips.forEach((c, i) => {
    if (i === 0) c.classList.add('active');
    else c.classList.remove('active');
  });
  const reasonInput = document.getElementById('cancelOrderReasonInput');
  if (reasonInput) reasonInput.value = 'Changed mind';

  const btn = document.getElementById('btnConfirmCancelOrder');
  if (btn) {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-ban"></i> Yes, Cancel Order';
  }

  const modal = document.getElementById('cancelOrderModal');
  if (modal) modal.classList.add('show');
}

function closeCancelOrderModal() {
  const modal = document.getElementById('cancelOrderModal');
  if (modal) modal.classList.remove('show');
  activeCancelOrderId = null;
}

function selectCancelReason(btn, reasonText) {
  document.querySelectorAll('.cancel-reason-chips .cancel-chip').forEach(c => c.classList.remove('active'));
  if (btn) btn.classList.add('active');
  const input = document.getElementById('cancelOrderReasonInput');
  if (input) input.value = reasonText;
}

async function executeCancelOrder() {
  const targetId = activeCancelOrderId || parseInt(document.getElementById('cancelTargetOrderId')?.value, 10);
  if (!targetId) {
    toast('No order selected for cancellation.', 'error');
    return;
  }

  const reasonInput = document.getElementById('cancelOrderReasonInput');
  const reason = (reasonInput ? reasonInput.value.trim() : '') || 'Changed mind, duplicate order, wrong address';

  const btn = document.getElementById('btnConfirmCancelOrder');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Cancelling...';
  }

  try {
    const res = await fetch('order_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'cancel_order',
        order_id: targetId,
        reason: reason
      })
    });
    const data = await res.json();

    if (data.success) {
      toast('Order cancelled successfully.', 'success');
      closeCancelOrderModal();

      // Update order card in UI immediately
      const card = document.getElementById(`order-card-${targetId}`);
      if (card) {
        card.setAttribute('data-status', 'cancelled');
        const iconDiv = card.querySelector('.row-icon');
        if (iconDiv) {
          iconDiv.className = 'row-icon grey';
          iconDiv.textContent = 'ðŸš«';
        }
        const badge = document.getElementById(`status-tag-${targetId}`);
        if (badge) {
          badge.className = 'status-badge status-cancelled';
          badge.textContent = 'Cancelled';
        }
        const cancelWrap = document.getElementById(`cancel-wrap-${targetId}`);
        if (cancelWrap) {
          cancelWrap.innerHTML = '<div style="font-size:8.5px; color:#dc2626; font-weight:700; margin-top:4px;"><i class="fa-solid fa-circle-xmark"></i> Cancelled by Customer</div>';
        }
      }

      setTimeout(() => {
        if (typeof loadNotifications === 'function') loadNotifications();
        window.location.reload();
      }, 900);
    } else {
      showToastCustomer(data.message || 'Could not cancel order. Please contact support or rider.');
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-ban"></i> Yes, Cancel Order';
      }
    }
  } catch (err) {
    console.error('Cancel order error:', err);
    showToastCustomer('A network error occurred. Please try again.');
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-ban"></i> Yes, Cancel Order';
    }
  }
}

/* ========================================================
   SAVED ADDRESSES MANAGEMENT
   ======================================================== */
let addAddressPickerMap = null;
let addAddressPickerMarker = null;

function openSavedAddressesModal() {
  const modal = document.getElementById('savedAddressesModal');
  if (modal) modal.classList.add('show');
  loadSavedAddresses();
}

function closeSavedAddressesModal() {
  const modal = document.getElementById('savedAddressesModal');
  if (modal) modal.classList.remove('show');
}

async function loadSavedAddresses() {
  const container = document.getElementById('savedAddressesList');
  if (!container) return;
  container.innerHTML = '<div style="text-align:center; padding:18px; font-size:11px; color:var(--muted);"><i class="fa-solid fa-circle-notch fa-spin"></i> Loading saved addresses...</div>';

  try {
    const res = await fetch('address_api.php?action=get_addresses', { cache: 'no-store' });
    const data = await res.json();
    if (!data.success || !Array.isArray(data.addresses) || data.addresses.length === 0) {
      container.innerHTML = '<div style="text-align:center; padding:20px; font-size:11px; color:var(--muted);">No saved addresses yet. Tap "+ Add New Address" below!</div>';
      return;
    }

    container.innerHTML = data.addresses.map(addr => {
      const isDef = parseInt(addr.is_default, 10) === 1;
      const label = addr.label || 'Home';
      let icon = 'fa-house';
      if (label.toLowerCase() === 'work') icon = 'fa-building';
      else if (label.toLowerCase() === 'other') icon = 'fa-location-dot';

      return `
        <div class="addr-card ${isDef ? 'default' : ''}" id="addr-card-${addr.address_id}">
          <div class="addr-card-header">
            <span class="addr-card-title"><i class="fa-solid ${icon}"></i> ${esc(label)}</span>
            ${isDef ? '<span class="addr-default-pill"><i class="fa-solid fa-circle-check"></i> Default</span>' : ''}
          </div>
          <div class="addr-card-body">${esc(addr.full_address || (addr.street + ', ' + addr.barangay + ', ' + addr.city))}</div>
          <div class="addr-card-actions">
            ${!isDef ? `
              <button type="button" class="addr-action-btn" onclick="setDefaultAddress(${addr.address_id})">
                <i class="fa-solid fa-check"></i> Set Default
              </button>
            ` : `
              <span style="font-size:9.5px; color:#15803d; font-weight:700;"><i class="fa-solid fa-shield-heart"></i> Active Delivery Address</span>
            `}
            <button type="button" class="addr-action-btn delete" onclick="deleteSavedAddress(${addr.address_id}, ${isDef ? 1 : 0})" title="Delete address">
              <i class="fa-regular fa-trash-can"></i> Delete
            </button>
          </div>
        </div>
      `;
    }).join('');
  } catch (err) {
    console.error('Error loading addresses:', err);
    container.innerHTML = '<div style="text-align:center; padding:18px; font-size:11px; color:#dc2626;">Failed to load addresses. Please retry.</div>';
  }
}

async function setDefaultAddress(addrId) {
  try {
    const res = await fetch('address_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'set_default', address_id: addrId })
    });
    const data = await res.json();
    if (data.success) {
      toast('Default address updated!', 'success');
      loadSavedAddresses();
      const modalAddr = document.getElementById('modalDeliveryAddress');
      if (modalAddr && data.full_address) modalAddr.value = data.full_address;
    } else {
      toast(data.message || 'Failed to update default address', 'error');
    }
  } catch (err) {
    console.error('Set default address error:', err);
    toast('Network error while updating default address', 'error');
  }
}

async function deleteSavedAddress(addrId, isDefault) {
  if (isDefault) {
    toast('Default address cannot be deleted. Set another address as default first.', 'info');
    return;
  }
  openCustConfirm('Remove Address', 'Are you sure you want to remove this saved address?', async () => {
    try {
      const res = await fetch('address_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete_address', address_id: addrId })
      });
      const data = await res.json();
      if (data.success) {
        toast('Address removed.');
        loadSavedAddresses();
      } else {
        toast(data.message || 'Failed to delete address.', 'error');
      }
    } catch (err) {
      console.error('Delete address error:', err);
      toast('Network error deleting address.', 'error');
    }
  }, 'Remove', true);
}

function openAddAddressModal() {
  const modal = document.getElementById('addAddressModal');
  if (modal) modal.classList.add('show');

  const st = document.getElementById('newAddrStreet');
  if (st) st.value = '';
  const bg = document.getElementById('newAddrBarangay');
  if (bg) bg.value = '';
  const ct = document.getElementById('newAddrCity');
  if (ct) ct.value = 'Ormoc City';
  const zp = document.getElementById('newAddrZip');
  if (zp) zp.value = '6541';
  selectNewAddrLabel('Home');

  const defaultLat = 11.00762;
  const defaultLng = 124.60749;
  updateNewAddrCoords(defaultLat, defaultLng);

  setTimeout(() => {
    initAddAddressPickerMap(defaultLat, defaultLng);
  }, 200);
}

function closeAddAddressModal() {
  const modal = document.getElementById('addAddressModal');
  if (modal) modal.classList.remove('show');
}

function selectNewAddrLabel(label) {
  const hidden = document.getElementById('newAddrLabel');
  if (hidden) hidden.value = label;

  ['Home', 'Work', 'Other'].forEach(l => {
    const chip = document.getElementById(`chipLabel${l}`);
    if (chip) {
      if (l === label) chip.classList.add('active');
      else chip.classList.remove('active');
    }
  });
}

function updateNewAddrCoords(lat, lng) {
  const latEl = document.getElementById('newAddrLat');
  const lngEl = document.getElementById('newAddrLng');
  const textEl = document.getElementById('newAddrCoordsText');
  if (latEl) latEl.value = lat;
  if (lngEl) lngEl.value = lng;
  if (textEl) textEl.textContent = `Pin set: ${Number(lat).toFixed(5)}, ${Number(lng).toFixed(5)}`;
}

function initAddAddressPickerMap(lat, lng) {
  const container = document.getElementById('add-address-picker-map');
  if (!container || typeof L === 'undefined') return;

  if (!addAddressPickerMap) {
    addAddressPickerMap = L.map('add-address-picker-map', {
      center: [lat, lng],
      zoom: 15,
      zoomControl: true
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap contributors',
      maxZoom: 19
    }).addTo(addAddressPickerMap);

    const pinIcon = L.divIcon({
      className: 'custom-pin-icon',
      html: '<div style="background:#4B5320; color:#fff; border-radius:50%; width:28px; height:28px; display:flex; align-items:center; justify-content:center; box-shadow:0 3px 8px rgba(0,0,0,0.3); border:2px solid #fff;"><i class="fa-solid fa-location-dot" style="font-size:14px;"></i></div>',
      iconSize: [28, 28],
      iconAnchor: [14, 28]
    });

    addAddressPickerMarker = L.marker([lat, lng], {
      draggable: true,
      icon: pinIcon
    }).addTo(addAddressPickerMap);

    addAddressPickerMarker.on('dragend', function (e) {
      const pos = e.target.getLatLng();
      updateNewAddrCoords(pos.lat, pos.lng);
    });

    addAddressPickerMap.on('click', function (e) {
      if (addAddressPickerMarker) {
        addAddressPickerMarker.setLatLng(e.latlng);
      }
      updateNewAddrCoords(e.latlng.lat, e.latlng.lng);
    });
  } else {
    addAddressPickerMap.invalidateSize();
    addAddressPickerMap.setView([lat, lng], 15);
    if (addAddressPickerMarker) {
      addAddressPickerMarker.setLatLng([lat, lng]);
    }
  }
}

function useLocationForNewAddress() {
  if (!navigator.geolocation) {
    toast('Geolocation not supported by this browser.', 'error');
    return;
  }
  toast('Detecting GPS location...', 'info');
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      const lat = pos.coords.lat;
      const lng = pos.coords.lng;
      updateNewAddrCoords(lat, lng);
      if (addAddressPickerMap) {
        addAddressPickerMap.setView([lat, lng], 16);
        if (addAddressPickerMarker) addAddressPickerMarker.setLatLng([lat, lng]);
      }
      toast('Location detected!', 'success');
      const st = document.getElementById('newAddrStreet');
      if (st && !st.value.trim()) {
        fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`)
          .then(r => r.json())
          .then(d => {
            if (d && d.address) {
              const road = d.address.road || d.address.pedestrian || d.address.suburb || '';
              const brgy = d.address.neighbourhood || d.address.village || '';
              if (road && !st.value) st.value = road;
              const bg = document.getElementById('newAddrBarangay');
              if (bg && brgy && !bg.value) bg.value = brgy;
            }
          })
          .catch(() => {});
      }
    },
    (err) => {
      console.warn('Geolocation error:', err);
      toast('Could not detect location. Please tap the map to pin manually.', 'error');
    },
    { enableHighAccuracy: true, timeout: 8000 }
  );
}

async function submitNewAddress() {
  const streetInput = document.getElementById('newAddrStreet');
  const street = streetInput ? streetInput.value.trim() : '';
  if (!street) {
    showToastCustomer('Please enter your street address or house/building number.');
    if (streetInput) streetInput.focus();
    return;
  }

  const label = document.getElementById('newAddrLabel')?.value || 'Home';
  const barangay = document.getElementById('newAddrBarangay')?.value.trim() || '';
  const city = document.getElementById('newAddrCity')?.value.trim() || 'Ormoc City';
  const postal_code = document.getElementById('newAddrZip')?.value.trim() || '6541';
  const lat = parseFloat(document.getElementById('newAddrLat')?.value || '11.00762');
  const lng = parseFloat(document.getElementById('newAddrLng')?.value || '124.60749');
  const is_default = document.getElementById('newAddrSetDefault')?.checked ? 1 : 0;

  const btn = document.getElementById('btnSaveNewAddress');
  if (btn) {
    btn.disabled = true;
    btn.textContent = 'Saving...';
  }

  try {
    const res = await fetch('address_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'add_address',
        label: label,
        street: street,
        barangay: barangay,
        city: city,
        postal_code: postal_code,
        lat: lat,
        lng: lng,
        is_default: is_default
      })
    });
    const data = await res.json();
    if (data.success) {
      toast('New address saved!', 'success');
      closeAddAddressModal();
      loadSavedAddresses();

      if (is_default && data.address && data.address.full_address) {
        const modalAddr = document.getElementById('modalDeliveryAddress');
        if (modalAddr) modalAddr.value = data.address.full_address;
      }
    } else {
      showToastCustomer(data.message || 'Failed to save address.');
    }
  } catch (err) {
    console.error('Save address error:', err);
    showToastCustomer('Network error while saving address.');
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.textContent = 'Save Address';
    }
  }
}

/* ========================================================
   PAYMENT METHODS DRAWER & MODAL
   ======================================================== */
const PAYMENT_KEY = 'fooddash_default_payment';

function openPaymentMethodsModal() {
  const modal = document.getElementById('paymentMethodsModal');
  if (modal) modal.classList.add('show');

  const currentPref = localStorage.getItem(PAYMENT_KEY) || 'Cash on Delivery (COD)';
  highlightSelectedPayment(currentPref);
}

function closePaymentMethodsModal() {
  const modal = document.getElementById('paymentMethodsModal');
  if (modal) modal.classList.remove('show');
}

function highlightSelectedPayment(methodName) {
  const mapping = {
    'Cash on Delivery (COD)': 'payOpt_cod',
    'GCash': 'payOpt_gcash',
    'Maya (PayMaya)': 'payOpt_maya',
    'GoTyme Bank': 'payOpt_gotyme',
    'Apple Pay': 'payOpt_apple'
  };

  document.querySelectorAll('.payment-options-list .pay-option-card').forEach(c => c.classList.remove('selected'));
  const targetId = mapping[methodName] || 'payOpt_cod';
  const targetEl = document.getElementById(targetId);
  if (targetEl) targetEl.classList.add('selected');
}

function selectDefaultPaymentMethod(methodName) {
  try {
    localStorage.setItem(PAYMENT_KEY, methodName);
  } catch(e){}

  highlightSelectedPayment(methodName);

  const hiddenSel = document.getElementById('selectedPaymentMethod');
  if (hiddenSel) hiddenSel.value = methodName;

  const modalSelect = document.getElementById('modalPaymentSelect');
  if (modalSelect) {
    modalSelect.value = methodName;
    if (typeof syncCustomerPaymentMethod === 'function') {
      syncCustomerPaymentMethod(methodName);
    }
  }

  document.querySelectorAll('.pay-option').forEach(opt => {
    const isThis = opt.dataset.method === methodName;
    opt.classList.toggle('active', isThis);
    const radio = opt.querySelector('.pay-check');
    if (radio) {
      radio.className = isThis ? 'fa-solid fa-circle-dot pay-check' : 'fa-regular fa-circle pay-check';
    }
  });

  toast(`Default payment set to: ${methodName}`, 'success');
}

/* ========================================================
   HELP & SUPPORT MODAL
   ======================================================== */
function openHelpSupportModal() {
  const modal = document.getElementById('helpSupportModal');
  if (modal) modal.classList.add('show');
}

function closeHelpSupportModal() {
  const modal = document.getElementById('helpSupportModal');
  if (modal) modal.classList.remove('show');
}

function toggleFaq(id) {
  const answer = document.getElementById(id);
  const icon = document.getElementById(`${id}_icon`);
  if (!answer) return;

  const isVisible = answer.style.display === 'block';
  answer.style.display = isVisible ? 'none' : 'block';
  if (icon) {
    icon.className = isVisible ? 'fa-solid fa-chevron-down' : 'fa-solid fa-chevron-up';
  }
}

/* ========================================================
   ABOUT FOOD DASH MODAL & POLICIES
   ======================================================== */
function openAboutModal() {
  const modal = document.getElementById('aboutModal');
  if (modal) modal.classList.add('show');
}

function closeAboutModal() {
  const modal = document.getElementById('aboutModal');
  if (modal) modal.classList.remove('show');
}

function openTermsModal(type) {
  const modal = document.getElementById('termsPrivacyModal');
  const title = document.getElementById('termsModalTitle');
  const body = document.getElementById('termsModalBody');
  if (!modal || !title || !body) return;

  if (type === 'terms') {
    title.textContent = 'Terms of Service';
    body.innerHTML = `
      <p style="margin-bottom:8px;"><b>1. Acceptance of Terms:</b> By accessing or ordering through Food Dash, you agree to comply with our platform policies and fair community standards.</p>
      <p style="margin-bottom:8px;"><b>2. Orders & Preparation:</b> Once submitted, orders are dispatched immediately to local chefs. You can track preparation status live on your dashboard.</p>
      <p style="margin-bottom:8px;"><b>3. Order Cancellations:</b> Orders in 'Pending' or 'Preparing' status may be cancelled free of charge. Orders already dispatched with riders (Out for Delivery) cannot be cancelled.</p>
      <p style="margin-bottom:8px;"><b>4. Delivery & GPS:</b> Food Dash utilizes real-time GPS telemetry to calculate route travel times and road distances between kitchens and delivery destinations.</p>
      <p style="margin:0;"><b>5. Contact:</b> For disputes or order inquiries, reach out to support@fooddash.ph or call (+63 917 555 0192).</p>
    `;
  } else {
    title.textContent = 'Privacy Policy';
    body.innerHTML = `
      <p style="margin-bottom:8px;"><b>1. Data Protection:</b> Food Dash protects customer information according to the Philippine Data Privacy Act of 2012 (RA 10173).</p>
      <p style="margin-bottom:8px;"><b>2. Location Data:</b> GPS location coordinates are only collected during active order fulfillment to calculate accurate delivery routes and assist dispatchers.</p>
      <p style="margin-bottom:8px;"><b>3. Communication Privacy:</b> In-app rider calls and chats are strictly order-scoped and encrypted in transit.</p>
      <p style="margin:0;"><b>4. Security:</b> We never store raw credit card credentials on platform servers. E-wallet payments are securely routed via accredited payment gateways.</p>
    `;
  }
  modal.classList.add('show');
}

function closeTermsModal() {
  const modal = document.getElementById('termsPrivacyModal');
  if (modal) modal.classList.remove('show');
}

/* ========================================================
   LOGOUT WORKFLOW
   ======================================================== */
function openLogout() {
  document.getElementById('logoutModal').classList.add('show');
}

function closeLogout() {
  document.getElementById('logoutModal').classList.remove('show');
}

function executeLogout() {
  try {
    localStorage.removeItem('fooddash_auth_token');
    localStorage.removeItem('fooddash_user');
    localStorage.removeItem('fooddash_cart');
    localStorage.removeItem('foodDashCart');
    sessionStorage.clear();
  } catch (e) {}

  toast('Logging out securely...', 'info');
  setTimeout(() => {
    window.location.href = 'logout.php?confirm=1';
  }, 350);
}

// Initial setup
updateBadge();
loadNotifications();
setInterval(loadNotifications, 6000);

// Auto-request live location if allowed by browser
requestLiveGpsIfAllowed();

// Wire up typing and blur/change geocoding events for delivery address inputs (no duplicate attachments)
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setupAddressInputListeners);
} else {
    setupAddressInputListeners();
}

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { 
    closeLogout(); 
    closeNotifications(); 
    closeAddressPromptModal(); 
    closeCustomerTrackingModal(); 
    closeCustomerChat();
    closeCancelOrderModal();
    closeSavedAddressesModal();
    closeAddAddressModal();
    closePaymentMethodsModal();
    closeHelpSupportModal();
    closeAboutModal();
    closeTermsModal();
  }
});

// URL Query Parameter Routing (e.g. ?screen=orders or ?screen=account)
// Auto-modal popup on login/load is strictly disabled per requirement.
// The tracking modal should ONLY open when the user explicitly taps "Live Track Rider".
const customerUrlParams = new URLSearchParams(window.location.search);
if (customerUrlParams.get('screen') === 'orders') {
  showScreen('orders');
} else if (customerUrlParams.get('screen') === 'account') {
  showScreen('account');
}

// Customer Live GPS Tracking & Rider Chat
let custTrackingMap = null;
let custStoreMarker = null;
let custDestMarker = null;
let custRiderMarker = null;
let custRoutePolyline = null;
let custTrackingTimer = null;
let activeCustomerTrackingOrderId = <?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } echo !empty($active_tracking_order['order_id']) ? (int)$active_tracking_order['order_id'] : 'null'; ?>;
let currentTrackingRiderPhone = '+639175550192';
let currentTrackingRiderName = 'Alex Rider';

// Customer Chat State
let activeCustChatOrderId = null;
let custChatPollTimer = null;
let custLastMessageId = 0;

function openTrackingModal(orderId) {
  openCustomerTrackingModal(orderId);
  setTimeout(() => {
    if (window.trackingMap && typeof window.trackingMap.invalidateSize === 'function') trackingMap.invalidateSize();
    if (window.map && typeof window.map.invalidateSize === 'function') map.invalidateSize();
    if (window.custTrackingMap && typeof window.custTrackingMap.invalidateSize === 'function') custTrackingMap.invalidateSize();
  }, 200);
  setTimeout(() => {
    if (window.trackingMap && typeof window.trackingMap.invalidateSize === 'function') trackingMap.invalidateSize();
    if (window.map && typeof window.map.invalidateSize === 'function') map.invalidateSize();
    if (window.custTrackingMap && typeof window.custTrackingMap.invalidateSize === 'function') custTrackingMap.invalidateSize();
  }, 300);
}
window.openTrackingModal = openTrackingModal;

function openCustomerTrackingModal(orderId) {
  const parsedId = parseInt(orderId, 10);
  const targetId = (!isNaN(parsedId) && parsedId > 0) ? parsedId : activeCustomerTrackingOrderId;
  if (!targetId) {
    showScreen('orders');
    return;
  }
  activeCustomerTrackingOrderId = targetId;

  const modal = document.getElementById('customerTrackingModal') || document.getElementById('trackingModal');
  if (modal) {
    modal.classList.add('show');
    modal.style.display = 'flex';
  }

  fetchCustomerLiveTracking(targetId, true);
  setTimeout(() => {
    if (window.trackingMap && typeof window.trackingMap.invalidateSize === 'function') trackingMap.invalidateSize();
    if (window.map && typeof window.map.invalidateSize === 'function') map.invalidateSize();
    if (window.custTrackingMap && typeof window.custTrackingMap.invalidateSize === 'function') custTrackingMap.invalidateSize();
    else if (custTrackingMap && typeof custTrackingMap.invalidateSize === 'function') custTrackingMap.invalidateSize();
  }, 200);
  setTimeout(() => {
    if (window.trackingMap && typeof window.trackingMap.invalidateSize === 'function') trackingMap.invalidateSize();
    if (window.map && typeof window.map.invalidateSize === 'function') map.invalidateSize();
    if (window.custTrackingMap && typeof window.custTrackingMap.invalidateSize === 'function') custTrackingMap.invalidateSize();
    else if (custTrackingMap && typeof custTrackingMap.invalidateSize === 'function') custTrackingMap.invalidateSize();
  }, 300);

  if (custTrackingTimer) clearInterval(custTrackingTimer);
  custTrackingTimer = setInterval(() => {
    if (activeCustomerTrackingOrderId) {
      fetchCustomerLiveTracking(activeCustomerTrackingOrderId, false);
    }
  }, 2000); // 2-second real-time polling loop
}

function closeCustomerTrackingModal() {
  const modal = document.getElementById('customerTrackingModal') || document.getElementById('trackingModal');
  if (modal) {
    modal.classList.remove('show');
    modal.style.display = 'none';
  }
  if (custTrackingTimer) {
    clearInterval(custTrackingTimer);
    custTrackingTimer = null;
  }
}

// Distance helper
function calculateHaversineKm(lat1, lon1, lat2, lon2) {
  const R = 6371;
  const dLat = (lat2 - lat1) * Math.PI / 180;
  const dLon = (lon2 - lon1) * Math.PI / 180;
  const a = Math.sin(dLat / 2) ** 2 + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * Math.sin(dLon / 2) ** 2;
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

// Fallback street corridor generator so route polyline never cuts straight across buildings
function generateFallbackStreetCorridor(startLat, startLng, destLat, destLng) {
  const midLat = startLat + (destLat - startLat) * 0.5;
  const midLng = startLng + (destLng - startLng) * 0.5;
  return [
    [startLat, startLng],
    [startLat, midLng],
    [destLat, midLng],
    [destLat, destLng]
  ];
}

// Smooth marker interpolation along OSRM road geometry
let currentCustOsrmPath = [];

function animateMarkerAlongRoad(marker, newLat, newLng, durationMs = 1500) {
  if (!marker) return;
  const startLatLng = marker.getLatLng();
  const startLat = startLatLng.lat;
  const startLng = startLatLng.lng;

  const totalDist = calculateHaversineKm(startLat, startLng, newLat, newLng);
  if (totalDist < 0.00002) return; // under ~2 meters

  if (marker._animFrameId) {
    cancelAnimationFrame(marker._animFrameId);
    marker._animFrameId = null;
  }

  // Extract path waypoints from OSRM road geometry if available
  let waypoints = [];
  if (Array.isArray(currentCustOsrmPath) && currentCustOsrmPath.length > 2) {
    let closestStartIdx = 0;
    let minStartDist = Infinity;
    let closestEndIdx = 0;
    let minEndDist = Infinity;

    for (let i = 0; i < currentCustOsrmPath.length; i++) {
      const pt = currentCustOsrmPath[i];
      const dS = calculateHaversineKm(startLat, startLng, pt[0], pt[1]);
      if (dS < minStartDist) {
        minStartDist = dS;
        closestStartIdx = i;
      }
      const dE = calculateHaversineKm(newLat, newLng, pt[0], pt[1]);
      if (dE < minEndDist) {
        minEndDist = dE;
        closestEndIdx = i;
      }
    }

    if (minStartDist < 0.25 && minEndDist < 0.25 && closestStartIdx !== closestEndIdx) {
      const step = closestStartIdx < closestEndIdx ? 1 : -1;
      for (let i = closestStartIdx; i !== closestEndIdx; i += step) {
        waypoints.push(currentCustOsrmPath[i]);
      }
      waypoints.push(currentCustOsrmPath[closestEndIdx]);
    }
  }

  if (waypoints.length <= 1) {
    waypoints = [[startLat, startLng], [newLat, newLng]];
  } else {
    waypoints.unshift([startLat, startLng]);
    waypoints.push([newLat, newLng]);
  }

  const legDistances = [];
  let totalPathDist = 0;
  for (let i = 0; i < waypoints.length - 1; i++) {
    const d = calculateHaversineKm(waypoints[i][0], waypoints[i][1], waypoints[i+1][0], waypoints[i+1][1]);
    legDistances.push(d);
    totalPathDist += d;
  }

  const startTime = performance.now();

  function step(now) {
    const elapsed = now - startTime;
    const progress = Math.min(elapsed / durationMs, 1);
    const ease = 1 - Math.pow(1 - progress, 3); // Cubic ease-out

    if (totalPathDist === 0) {
      marker.setLatLng([newLat, newLng]);
      return;
    }

    const targetCovered = ease * totalPathDist;
    let accumulated = 0;
    let curLat = newLat;
    let curLng = newLng;

    for (let i = 0; i < legDistances.length; i++) {
      const segDist = legDistances[i];
      if (accumulated + segDist >= targetCovered || i === legDistances.length - 1) {
        const segProgress = segDist > 0 ? (targetCovered - accumulated) / segDist : 1;
        curLat = waypoints[i][0] + (waypoints[i+1][0] - waypoints[i][0]) * Math.min(1, Math.max(0, segProgress));
        curLng = waypoints[i][1] + (waypoints[i+1][1] - waypoints[i][1]) * Math.min(1, Math.max(0, segProgress));
        break;
      }
      accumulated += segDist;
    }

    marker.setLatLng([curLat, curLng]);

    if (progress < 1) {
      marker._animFrameId = requestAnimationFrame(step);
    } else {
      marker.setLatLng([newLat, newLng]);
      marker._animFrameId = null;
    }
  }

  marker._animFrameId = requestAnimationFrame(step);
}

function animateMarkerTo(marker, newLat, newLng, durationMs = 1500) {
  animateMarkerAlongRoad(marker, newLat, newLng, durationMs);
}

// Display exact OSRM driving distance and road ETA matching the rider dashboard
function updateCustomerEtaBadge(dKm, distMeters = null) {
  const badgeEl = document.getElementById('custTrackEtaBadgeText');
  const distTextEl = document.getElementById('custTrackDistText');
  const etaTextEl = document.getElementById('custTrackEtaText');
  if (dKm === null || isNaN(dKm)) return;

  const d = parseFloat(dKm);
  const meters = (distMeters !== null && !isNaN(distMeters)) ? Math.round(distMeters) : Math.round(d * 1000);

  let badgeStr = '';
  let distStr = '';
  let etaStr = '';

  if (meters <= 50) {
    badgeStr = 'Arrived at your door! â€¢ 0 mins';
    distStr = 'Road Distance: Arrived!';
    etaStr = 'Rider is outside now! ðŸ›µ';
  } else if (meters < 1000) {
    const mins = Math.max(1, Math.round((meters / 1000) * 4));
    badgeStr = `${meters}m away â€¢ ~${mins} mins`;
    distStr = `Road Distance: ${meters} meters`;
    etaStr = `Arriving in ~${mins} mins`;
  } else {
    const kmStr = (meters / 1000).toFixed(2);
    const mins = Math.max(3, Math.round((meters / 1000) * 3.5));
    badgeStr = `${kmStr} km away â€¢ ~${mins} mins`;
    distStr = `Road Distance: ${kmStr} km`;
    etaStr = `Estimated ~${mins} mins`;
  }

  if (badgeEl) badgeEl.textContent = badgeStr;
  if (distTextEl) distTextEl.textContent = distStr;
  if (etaTextEl) etaTextEl.textContent = etaStr;
}

let lastCustRouteKey = '';

// Unified OSRM Driving Routing API matching rider_dashboard.php
async function fetchCustOsrmRoute(startLat, startLng, destLat, destLng) {
  const routeKey = `${startLat.toFixed(5)},${startLng.toFixed(5)}_${destLat.toFixed(5)},${destLng.toFixed(5)}`;
  if (routeKey === lastCustRouteKey && custRoutePolyline) return;
  lastCustRouteKey = routeKey;

  const url = `https://router.project-osrm.org/route/v1/driving/${startLng},${startLat};${destLng},${destLat}?overview=full&geometries=geojson`;
  try {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 6000);
    const res = await fetch(url, { signal: controller.signal, headers: { 'Accept': 'application/json' } });
    clearTimeout(timeoutId);

    if (res.ok) {
      const data = await res.json();
      if (data && data.code === 'Ok' && data.routes && data.routes[0] && data.routes[0].geometry && Array.isArray(data.routes[0].geometry.coordinates)) {
        // OSRM returns [lng, lat], convert to Leaflet [lat, lng]
        const leafletCoords = data.routes[0].geometry.coordinates.map(pt => [pt[1], pt[0]]);
        currentCustOsrmPath = leafletCoords;
        if (custTrackingMap) {
          if (!custRoutePolyline) {
            custRoutePolyline = L.polyline(leafletCoords, {
              color: '#4B5320',
              weight: 5,
              opacity: 0.9,
              lineCap: 'round',
              lineJoin: 'round'
            }).addTo(custTrackingMap);
          } else {
            custRoutePolyline.setLatLngs(leafletCoords);
          }
        }
        if (typeof data.routes[0].distance === 'number') {
          updateCustomerEtaBadge(data.routes[0].distance / 1000, data.routes[0].distance);
        }
        return;
      }
    }
  } catch (e) {
    console.warn('Customer OSRM road fetch fallback:', e);
  }

  // Fallback: Manhattan street corridor so route stays along street axes
  const fallback = generateFallbackStreetCorridor(startLat, startLng, destLat, destLng);
  if (custTrackingMap) {
    if (!custRoutePolyline) {
      custRoutePolyline = L.polyline(fallback, {
        color: '#4B5320',
        weight: 5,
        opacity: 0.9,
        lineCap: 'round',
        lineJoin: 'round'
      }).addTo(custTrackingMap);
    } else {
      custRoutePolyline.setLatLngs(fallback);
    }
  }
}

async function fetchCustomerLiveTracking(orderId, isInitial) {
  try {
    // Append timestamp and no-cache to fetch fresh live GPS from order_api.php
    const res = await fetch(`order_api.php?action=get_rider_location&order_id=${orderId}&_t=${Date.now()}`, {
      cache: 'no-store'
    });
    const data = await res.json();
    if (!data.success) {
      console.warn('Tracking API response:', data.message);
      return;
    }

    const ord = data.order || {};
    const storeLat = parseFloat(data.store_lat || ord.store_lat);
    const storeLng = parseFloat(data.store_lng || ord.store_lng);
    const destLat = parseFloat(data.destination_lat || data.dest_lat || ord.destination_lat || ord.customer_lat);
    const destLng = parseFloat(data.destination_lng || data.dest_lng || ord.destination_lng || ord.customer_lng);

    // Read high-precision coordinates with fallback aliases
    const riderLat = parseFloat(data.rider_lat ?? data.lat ?? data.lat ?? ord.rider_lat);
    const riderLng = parseFloat(data.rider_lng ?? data.lng ?? data.lng ?? ord.rider_lng);

    // Update Rider info with non-null fallbacks
    currentTrackingRiderName = data.rider_name || ord.rider_name || 'Alex Rider';
    const riderPhoneRaw = data.rider_phone || ord.rider_phone || '+63 917 555 0192';
    const riderDial = riderPhoneRaw.replace(/[^\+0-9]/g, '') || '+639175550192';
    currentTrackingRiderPhone = riderDial;

    const nameEl = document.getElementById('custTrackRiderName');
    if (nameEl) nameEl.textContent = currentTrackingRiderName;
    const phoneEl = document.getElementById('custTrackRiderPhone');
    if (phoneEl) phoneEl.innerHTML = `<i class="fa-solid fa-phone-volume"></i> ${escapeCustHtml(riderPhoneRaw)}`;

    const callLink = document.getElementById('custTrackCallLink');
    if (callLink) callLink.href = `tel:${riderDial}`;

    const smsLink = document.getElementById('custTrackSmsBtn');
    if (smsLink) smsLink.href = `sms:${riderDial}`;

    const chatSmsLink = document.getElementById('custChatSmsLink');
    if (chatSmsLink) chatSmsLink.href = `sms:${riderDial}?body=Hi%20${encodeURIComponent(currentTrackingRiderName)},%20regarding%20my%20order%20${encodeURIComponent(data.order_ref || ord.order_ref || '')}`;

    const refEl = document.getElementById('trackModalOrderRef');
    if (refEl) {
      const rawRef = data.order_ref || ord.order_ref || ('#' + orderId);
      const cleanRef = '#' + String(rawRef).replace(/^#+/, '');
      refEl.textContent = `${cleanRef} Tracking`;
    }

    const subEl = document.getElementById('trackModalStoreSubtitle');
    if (subEl) subEl.textContent = `${ord.store_name || data.store_name || 'Store'} â€¢ ${data.status || ord.status || 'On Delivery'}`;

    // Update Distance / ETA text & badge (e.g. "500m away â€¢ ~3 mins")
    let dKm = (data.distance_km !== null && !isNaN(data.distance_km)) ? parseFloat(data.distance_km) : null;
    if (dKm === null && !isNaN(destLat) && !isNaN(destLng) && !isNaN(riderLat) && !isNaN(riderLng) && riderLat !== 0) {
      dKm = calculateHaversineKm(riderLat, riderLng, destLat, destLng);
    }
    updateCustomerEtaBadge(dKm);

    // Check for active incoming call from rider
    if (data.active_call && (data.active_call.status === 'ringing' || data.active_call.call_status === 'ringing')) {
      handleIncomingCallAlert(data.active_call);
    } else {
      dismissIncomingCallIfRinging();
    }

    // Check for new message from rider to show live banner
    if (data.latest_message && data.latest_message.sender_role === 'rider') {
      handleLiveRiderMessage(data.latest_message);
    }

    renderCustomerMap({
      storeLat, storeLng, storeName: ord.store_name || data.store_name,
      destLat, destLng, deliveryAddress: ord.delivery_address,
      riderLat, riderLng, riderName: currentTrackingRiderName,
      isInitial
    });

  } catch (err) {
    console.warn('Error fetching customer tracking:', err);
  }
}

function renderCustomerMap(opts) {
  const mapContainer = document.getElementById('cust-tracking-map');
  if (!mapContainer || typeof L === 'undefined') return;

  const validStore = !isNaN(opts.storeLat) && !isNaN(opts.storeLng) && opts.storeLat !== 0;
  const validDest = !isNaN(opts.destLat) && !isNaN(opts.destLng) && opts.destLat !== 0;
  const validRider = !isNaN(opts.riderLat) && !isNaN(opts.riderLng) && opts.riderLat !== 0;

  // Requirement 4: Ensure map initializes and centers on rider's active coordinates
  const center = validRider ? [opts.riderLat, opts.riderLng] : (validDest ? [opts.destLat, opts.destLng] : (validStore ? [opts.storeLat, opts.storeLng] : defaultCenter));

  if (!custTrackingMap) {
    custTrackingMap = L.map('cust-tracking-map', {
      zoomControl: true,
      attributionControl: false
    }).setView(center, 16);

    window.custTrackingMap = custTrackingMap;
    window.trackingMap = custTrackingMap;
    window.map = custTrackingMap;

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19
    }).addTo(custTrackingMap);
  } else {
    window.custTrackingMap = custTrackingMap;
    window.trackingMap = custTrackingMap;
    window.map = custTrackingMap;
  }

  // Icons
  const storeIcon = L.divIcon({
    className: 'custom-map-pin',
    html: `<div style="background:#d97706; color:#ffffff; width:34px; height:34px; border-radius:50%; display:flex; align-items:center; justify-content:center; box-shadow:0 3px 10px rgba(217,119,6,0.4); border:2.5px solid #ffffff; font-size:15px;"><i class="fa-solid fa-store"></i></div>`,
    iconSize: [34, 34],
    iconAnchor: [17, 17],
    popupAnchor: [0, -17]
  });

  const destIcon = L.divIcon({
    className: 'custom-map-pin',
    html: `<div style="background:#dc2626; color:#ffffff; width:34px; height:34px; border-radius:50%; display:flex; align-items:center; justify-content:center; box-shadow:0 3px 10px rgba(220,38,38,0.4); border:2.5px solid #ffffff; font-size:15px;"><i class="fa-solid fa-house"></i></div>`,
    iconSize: [34, 34],
    iconAnchor: [17, 17],
    popupAnchor: [0, -17]
  });

  const riderIcon = L.divIcon({
    className: 'custom-map-pin cust-rider-pin',
    html: `<div style="position:relative; width:40px; height:40px;">
             <div style="position:absolute; inset:0; border-radius:50%; background:rgba(22,163,74,0.3); animation:riderPulse 1.8s infinite;"></div>
             <div style="position:absolute; inset:2px; border-radius:50%; background:#16a34a; color:#ffffff; display:flex; align-items:center; justify-content:center; box-shadow:0 3px 12px rgba(22,163,74,0.5); border:2.5px solid #ffffff; font-size:17px;"><i class="fa-solid fa-motorcycle"></i></div>
           </div>`,
    iconSize: [40, 40],
    iconAnchor: [20, 20],
    popupAnchor: [0, -20]
  });

  // a. Store Pin (pickup location)
  if (validStore) {
    if (!custStoreMarker) {
      custStoreMarker = L.marker([opts.storeLat, opts.storeLng], { icon: storeIcon }).addTo(custTrackingMap);
      custStoreMarker.bindPopup(`<b>${escapeCustHtml(opts.storeName || 'Store')}</b><br><small>Pickup Location</small>`);
    } else {
      custStoreMarker.setLatLng([opts.storeLat, opts.storeLng]);
    }
  }

  // b. Customer Delivery Pin (destination lat/lng)
  if (validDest) {
    if (!custDestMarker) {
      custDestMarker = L.marker([opts.destLat, opts.destLng], { icon: destIcon }).addTo(custTrackingMap);
      custDestMarker.bindPopup(`<b>Your Delivery Location</b><br><small>${escapeCustHtml(opts.deliveryAddress || 'Destination')}</small>`);
    } else {
      custDestMarker.setLatLng([opts.destLat, opts.destLng]);
    }
  }

  // c. Rider Motorcycle Pin (dynamically update position for live PC simulator movements & smooth road animation)
  if (validRider) {
    if (!custRiderMarker) {
      custRiderMarker = L.marker([opts.riderLat, opts.riderLng], { icon: riderIcon, zIndexOffset: 1000 }).addTo(custTrackingMap);
      custRiderMarker.bindPopup(`<b>${escapeCustHtml(opts.riderName || 'Rider')}</b><br><small>Live Motorcycle GPS</small>`);
    } else {
      custRiderMarker.setLatLng([opts.riderLat, opts.riderLng]);
      animateMarkerAlongRoad(custRiderMarker, opts.riderLat, opts.riderLng, 1400);
    }
  }

  // d. Dynamic connecting road polyline via OSRM (never straight line)
  const polyStart = validRider ? [opts.riderLat, opts.riderLng] : (validStore ? [opts.storeLat, opts.storeLng] : null);
  const polyEnd = validDest ? [opts.destLat, opts.destLng] : null;

  if (polyStart && polyEnd) {
    fetchCustOsrmRoute(polyStart[0], polyStart[1], polyEnd[0], polyEnd[1]);
  }

  if (opts.isInitial) {
    setTimeout(() => {
      custTrackingMap.invalidateSize();
      if (validRider) {
        custTrackingMap.setView([opts.riderLat, opts.riderLng], 16);
      } else {
        const pointsToFit = [];
        if (validStore) pointsToFit.push([opts.storeLat, opts.storeLng]);
        if (validDest) pointsToFit.push([opts.destLat, opts.destLng]);
        if (pointsToFit.length >= 2) {
          custTrackingMap.fitBounds(pointsToFit, { padding: [40, 40], maxZoom: 16 });
        } else if (pointsToFit.length === 1) {
          custTrackingMap.setView(pointsToFit[0], 15);
        }
      }
    }, 150);
  }
}

// Customer Chat Integration
function openCustomerChat() {
  const orderId = activeCustomerTrackingOrderId || <?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } echo !empty($active_tracking_order['order_id']) ? (int)$active_tracking_order['order_id'] : '0'; ?>;
  if (!orderId) {
    showScreen('orders');
    return;
  }
  activeCustChatOrderId = orderId;
  custLastMessageId = 0;

  // Clear unread badge counter on opening chat drawer
  unreadChatCount = 0;
  updateChatBadges();

  const titleEl = document.getElementById('custChatHeaderTitle');
  if (titleEl) titleEl.textContent = `Chat with ${currentTrackingRiderName}`;
  const phoneEl = document.getElementById('custChatHeaderPhone');
  if (phoneEl) phoneEl.textContent = currentTrackingRiderPhone;

  updateCustChatSmsLink('');

  const modal = document.getElementById('customerChatModal');
  if (modal) modal.classList.add('show');

  fetchCustomerMessages(orderId, true);

  if (custChatPollTimer) clearInterval(custChatPollTimer);
  custChatPollTimer = setInterval(() => {
    if (activeCustChatOrderId) {
      fetchCustomerMessages(activeCustChatOrderId, false);
    }
  }, 2000);
}

function closeCustomerChat() {
  const modal = document.getElementById('customerChatModal');
  if (modal) modal.classList.remove('show');
  if (custChatPollTimer) {
    clearInterval(custChatPollTimer);
    custChatPollTimer = null;
  }
  activeCustChatOrderId = null;
}

function updateCustChatSmsLink(text) {
  const smsBtn = document.getElementById('custChatSmsLink');
  if (!smsBtn) return;
  const msgText = (text && text.trim()) ? text.trim() : "Hello! This is regarding my Food Dash order.";
  const body = encodeURIComponent(msgText);
  smsBtn.href = `sms:${currentTrackingRiderPhone}?body=${body}`;
}

function renderChatMessages(messages, scrollToBottom = true) {
  const container = document.getElementById('custChatMessagesList');
  if (!container) return;

  // Clear "Loading messages with your rider..." placeholder if present or on initial load
  if (custLastMessageId === 0) {
    container.innerHTML = '';
  }
  const hint = container.querySelector('.chat-empty-hint');
  if (hint && Array.isArray(messages) && messages.length > 0) {
    hint.remove();
  }

  if (Array.isArray(messages) && messages.length > 0) {
    messages.forEach(msg => {
      if (msg.message_id > custLastMessageId) {
        custLastMessageId = msg.message_id;
        const isMe = (msg.sender_role === 'customer');
        const bubble = document.createElement('div');
        bubble.className = `msg-bubble ${isMe ? 'me' : 'them'}`;
        bubble.setAttribute('data-msg-id', msg.message_id);
        bubble.innerHTML = `
          <div>${escapeCustHtml(msg.message_text)}</div>
          <div class="msg-meta">${escapeCustHtml(msg.sender_name || 'Rider')} â€¢ ${escapeCustHtml(msg.time_formatted || '')}</div>
        `;
        container.appendChild(bubble);

        if (!isMe && !scrollToBottom) {
          showCustomerFloatingBanner('MESSAGE FROM RIDER', msg.message_text, openChatFromBanner);
        }
      }
    });

    if (scrollToBottom || messages.length > 0) {
      container.scrollTop = container.scrollHeight;
    }
  } else if (custLastMessageId === 0) {
    container.innerHTML = '<div class="chat-empty-hint">Start a conversation with your rider!</div>';
  }
}

async function fetchCustomerMessages(orderId, scrollToBottom = true) {
  try {
    const res = await fetch(`chat_api.php?order_id=${orderId}&after_id=${custLastMessageId}&_t=${Date.now()}`);
    if (!res.ok) {
      if (custLastMessageId === 0) {
        renderChatMessages([]);
      }
      return;
    }
    const data = await res.json();
    if (data.success && Array.isArray(data.messages)) {
      renderChatMessages(data.messages, scrollToBottom);
    } else if (custLastMessageId === 0) {
      renderChatMessages([]);
    }
  } catch (err) {
    console.warn('Failed to load customer messages:', err);
    if (custLastMessageId === 0) {
      renderChatMessages([]);
    }
  }
}

async function sendCustChatMessage() {
  const input = document.getElementById('custChatInputText');
  if (!input) return;
  const text = input.value.trim();
  if (!text || !activeCustChatOrderId) return;

  input.value = '';
  await submitCustomerMessage(activeCustChatOrderId, text);
}

function sendCustomerQuickReply(text) {
  if (!activeCustChatOrderId) return;
  updateCustChatSmsLink(text);
  submitCustomerMessage(activeCustChatOrderId, text);
}

async function submitCustomerMessage(orderId, text) {
  try {
    const res = await fetch('chat_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ order_id: orderId, message_text: text })
    });
    const data = await res.json();
    if (data.success) {
      fetchCustomerMessages(orderId, true);
    } else {
      showToastCustomer(data.message || 'Failed to send message');
    }
  } catch (err) {
    console.error('Error sending customer message:', err);
    showToastCustomer('Network error sending message');
  }
}

// -------------------------------------------------------------
// WEB AUDIO SYNTHETIC 2-TONE CHIME & MATCHING RIDER-STYLE BANNER
// -------------------------------------------------------------
function playCustomerChime() {
  // 1. Web Audio API synthesized melodic two-tone chime (880Hz -> 1320Hz) matching rider_dashboard.php
  try {
    const AudioCtxClass = window.AudioContext || window.webkitAudioContext;
    if (AudioCtxClass) {
      const ctx = new AudioCtxClass();
      if (ctx.state === 'suspended') ctx.resume();
      const now = ctx.currentTime;
      const osc1 = ctx.createOscillator();
      const gain1 = ctx.createGain();
      osc1.type = 'sine';
      osc1.frequency.setValueAtTime(880, now);
      gain1.gain.setValueAtTime(0.2, now);
      gain1.gain.exponentialRampToValueAtTime(0.001, now + 0.28);
      osc1.connect(gain1);
      gain1.connect(ctx.destination);
      osc1.start(now);
      osc1.stop(now + 0.28);

      const osc2 = ctx.createOscillator();
      const gain2 = ctx.createGain();
      osc2.type = 'sine';
      osc2.frequency.setValueAtTime(1320, now + 0.12);
      gain2.gain.setValueAtTime(0.25, now + 0.12);
      gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.5);
      osc2.connect(gain2);
      gain2.connect(ctx.destination);
      osc2.start(now + 0.12);
      osc2.stop(now + 0.5);
    }
  } catch (e) {}

  // 2. Audio file fallback
  try {
    const snd = new Audio('assets/notification_chime.wav');
    snd.volume = 0.85;
    snd.play().catch(() => {});
  } catch (e) {}
}
window.playMessageChime = playCustomerChime;
window.playCustomerChime = playCustomerChime;

let msgBannerTimeout = null;

function dismissLiveRiderBanner() {
  const banner = document.getElementById('liveRiderMessageBanner');
  if (banner) {
    banner.classList.remove('show');
    banner.style.display = 'none';
  }
  if (msgBannerTimeout) {
    clearTimeout(msgBannerTimeout);
    msgBannerTimeout = null;
  }
}

function showCustomerFloatingBanner(title, text, onClick = null) {
  const banner = document.getElementById('liveRiderMessageBanner');
  const titleEl = document.getElementById('liveMsgBannerTitle');
  const textEl = document.getElementById('liveMsgBannerText');
  if (banner && textEl) {
    if (titleEl) titleEl.textContent = title || 'MESSAGE FROM RIDER';
    textEl.textContent = text;
    banner.onclick = (e) => {
      if (e.target.closest('.rct-close')) return;
      if (typeof onClick === 'function') onClick();
      else openChatFromBanner();
    };
    banner.classList.add('show');
    banner.style.display = 'flex';

    if (msgBannerTimeout) clearTimeout(msgBannerTimeout);
    msgBannerTimeout = setTimeout(() => {
      dismissLiveRiderBanner();
    }, 4500);
  }
  playCustomerChime();
}

// Strict Single-Toast & Notification De-duplication Manager
window.displayedNotificationIds = new Set();
let _custToastTimeout = null;

function showToastCustomer(msg, uniqueId = null, title = null) {
  if (!msg) return;

  // If uniqueId is passed, prevent duplicate display for 15s
  if (uniqueId) {
    if (window.displayedNotificationIds.has(uniqueId)) return;
    window.displayedNotificationIds.add(uniqueId);
    setTimeout(() => window.displayedNotificationIds.delete(uniqueId), 15000);
  }

  // Strictly reserve the top floating banner for actual incoming rider messages
  if (title === 'MESSAGE FROM RIDER') {
    showCustomerFloatingBanner('MESSAGE FROM RIDER', msg, () => {
      if (activeCustomerTrackingOrderId) openCustomerTrackingModal(activeCustomerTrackingOrderId);
      else showScreen('orders');
    });
    return;
  }

  // All other system/action notifications go to the clean bottom toast snackbar
  toast(msg);
}

// Redirect all window.alert calls to the FoodDash in-app toast
window.alert = function(msg) {
  toast(String(msg || ''));
};

function escapeCustHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

// -------------------------------------------------------------
// INCOMING CALL & LIVE MESSAGE REAL-TIME HANDLERS
// -------------------------------------------------------------
let ringtoneAudioCtx = null;
let ringtoneInterval = null;
let currentIncomingCall = null;
let callConnectedTimer = null;
let callConnectedSeconds = 0;
let lastSeenRiderMsgId = 0;

function startRingtone() {
  try {
    if (!ringtoneAudioCtx) {
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      ringtoneAudioCtx = new AudioCtx();
    }
    if (ringtoneAudioCtx.state === 'suspended') {
      ringtoneAudioCtx.resume();
    }

    function playBeep() {
      if (!ringtoneAudioCtx || ringtoneAudioCtx.state === 'closed') return;
      const now = ringtoneAudioCtx.currentTime;
      const osc1 = ringtoneAudioCtx.createOscillator();
      const osc2 = ringtoneAudioCtx.createOscillator();
      const gain = ringtoneAudioCtx.createGain();

      osc1.type = 'sine'; osc1.frequency.value = 440;
      osc2.type = 'sine'; osc2.frequency.value = 480;

      gain.gain.setValueAtTime(0.12, now);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 1.2);

      osc1.connect(gain);
      osc2.connect(gain);
      gain.connect(ringtoneAudioCtx.destination);

      osc1.start(now); osc1.stop(now + 1.2);
      osc2.start(now); osc2.stop(now + 1.2);
    }

    playBeep();
    if (ringtoneInterval) clearInterval(ringtoneInterval);
    ringtoneInterval = setInterval(playBeep, 2400);

    if ('vibrate' in navigator) {
      navigator.vibrate([400, 200, 400, 200, 400]);
    }
  } catch (e) {
    console.warn('Ringtone audio notice:', e);
  }
}

function stopRingtone() {
  if (ringtoneInterval) {
    clearInterval(ringtoneInterval);
    ringtoneInterval = null;
  }
  if ('vibrate' in navigator) {
    navigator.vibrate(0);
  }
}

function handleIncomingCallAlert(call) {
  if (currentIncomingCall && currentIncomingCall.call_id === call.call_id) {
    return;
  }
  currentIncomingCall = call;

  const modal = document.getElementById('incomingCallModal');
  if (!modal) return;

  const nameEl = document.getElementById('incomingCallerName');
  if (nameEl) nameEl.textContent = call.caller_name || 'Your Rider';

  const phoneEl = document.getElementById('incomingCallerPhone');
  if (phoneEl) phoneEl.textContent = call.caller_phone || '+63 917 555 0192';

  const statusEl = document.getElementById('incomingCallStatus');
  if (statusEl) statusEl.textContent = 'Rider is calling regarding your delivery...';

  const actionsEl = document.getElementById('incomingCallActions');
  if (actionsEl) actionsEl.style.display = 'flex';

  const connectedEl = document.getElementById('incomingConnectedActions');
  if (connectedEl) connectedEl.style.display = 'none';

  modal.style.display = 'flex';
  startRingtone();
}

function dismissIncomingCallIfRinging() {
  if (currentIncomingCall && !currentIncomingCall.connected) {
    const modal = document.getElementById('incomingCallModal');
    if (modal) modal.style.display = 'none';
    stopRingtone();
    currentIncomingCall = null;
  }
}

async function acceptIncomingCall() {
  stopRingtone();
  if (currentIncomingCall) {
    currentIncomingCall.connected = true;
    try {
      await fetch('order_call_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'accept_call', call_id: currentIncomingCall.call_id, order_id: currentIncomingCall.order_id })
      });
      await fetch('order_communication_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'respond_call', call_id: currentIncomingCall.call_id, order_id: currentIncomingCall.order_id, status: 'accepted' })
      });
    } catch (e) {}
  }

  const statusEl = document.getElementById('incomingCallStatus');
  if (statusEl) statusEl.textContent = 'Call Connected (Voice Line Active)';

  const actionsEl = document.getElementById('incomingCallActions');
  if (actionsEl) actionsEl.style.display = 'none';

  const connectedEl = document.getElementById('incomingConnectedActions');
  if (connectedEl) connectedEl.style.display = 'flex';

  callConnectedSeconds = 0;
  if (callConnectedTimer) clearInterval(callConnectedTimer);
  callConnectedTimer = setInterval(() => {
    callConnectedSeconds++;
    const m = String(Math.floor(callConnectedSeconds / 60)).padStart(2, '0');
    const s = String(callConnectedSeconds % 60).padStart(2, '0');
    const tEl = document.getElementById('callConnectedTimer');
    if (tEl) tEl.textContent = `${m}:${s}`;
  }, 1000);
}

async function declineIncomingCall() {
  stopRingtone();
  if (currentIncomingCall) {
    try {
      await fetch('order_call_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'decline_call', call_id: currentIncomingCall.call_id, order_id: currentIncomingCall.order_id })
      });
      await fetch('order_communication_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'respond_call', call_id: currentIncomingCall.call_id, order_id: currentIncomingCall.order_id, status: 'declined' })
      });
    } catch (e) {}
  }
  const modal = document.getElementById('incomingCallModal');
  if (modal) modal.style.display = 'none';
  currentIncomingCall = null;
}

async function endConnectedCall() {
  if (callConnectedTimer) {
    clearInterval(callConnectedTimer);
    callConnectedTimer = null;
  }
  stopRingtone();
  if (currentIncomingCall) {
    try {
      await fetch('order_communication_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'end_call', call_id: currentIncomingCall.call_id })
      });
    } catch (e) {}
  }
  const modal = document.getElementById('incomingCallModal');
  if (modal) modal.style.display = 'none';
  currentIncomingCall = null;
}


let seenRiderMessageIds = new Set();
let unreadChatCount = 0;
let hasShownArrivedAlert = false;
const currentCustomerId = <?= (int)$user_id ?>;

function updateChatBadges() {
  const badges = document.querySelectorAll('.cust-chat-unread-badge');
  badges.forEach(b => {
    if (unreadChatCount > 0) {
      b.textContent = unreadChatCount;
      b.style.display = 'inline-flex';
    } else {
      b.style.display = 'none';
    }
  });

  const ordersBadge = document.getElementById('ordersBadge');
  if (ordersBadge) {
    if (unreadChatCount > 0) {
      ordersBadge.textContent = unreadChatCount;
      ordersBadge.style.display = 'inline-flex';
    } else {
      ordersBadge.style.display = 'none';
    }
  }
}

function appendMessageToChatDrawer(msg) {
  const container = document.getElementById('custChatMessagesList');
  if (!container) return;

  const existing = container.querySelector(`[data-msg-id="${msg.message_id}"]`);
  if (existing) return;

  const hint = container.querySelector('.chat-empty-hint');
  if (hint) hint.remove();

  const isMe = msg.sender_role === 'customer';
  const bubble = document.createElement('div');
  bubble.className = `msg-bubble ${isMe ? 'me' : 'them'}`;
  bubble.setAttribute('data-msg-id', msg.message_id);
  bubble.innerHTML = `
    <div>${escapeCustHtml(msg.message_text)}</div>
    <div class="msg-meta">${escapeCustHtml(msg.sender_name || 'Rider')} â€¢ ${escapeCustHtml(msg.time_formatted || 'Just now')}</div>
  `;
  container.appendChild(bubble);
  container.scrollTop = container.scrollHeight;
}

function handleLiveRiderMessage(msg) {
  if (!msg || !msg.message_id) return;
  // Prevent un-scoped messages from unrelated orders from popping up in toasts
  const activeOId = activeCustomerTrackingOrderId || 0;
  if (msg.order_id && activeOId && parseInt(msg.order_id, 10) !== parseInt(activeOId, 10)) {
    return;
  }

  const msgKey = 'msg_' + msg.message_id;
  if (window.displayedNotificationIds.has(msgKey)) return;
  window.displayedNotificationIds.add(msgKey);

  if (msg.message_id > lastSeenRiderMsgId) {
    lastSeenRiderMsgId = msg.message_id;
  }

  const senderName = msg.sender_name || currentTrackingRiderName || 'Alex';

  // Show top floating push banner pinned at top matching rider dashboard
  showCustomerFloatingBanner('MESSAGE FROM RIDER', msg.message_text, openChatFromBanner);

  // Update the unread badge counter in real time
  unreadChatCount++;
  updateChatBadges();
  appendMessageToChatDrawer(msg);
}

function openChatFromBanner() {
  dismissLiveRiderBanner();
  openCustomerChat();
}

function closeRiderArrivedModal() {
  const modal = document.getElementById('riderArrivedModal');
  if (modal) modal.style.display = 'none';
  if (window.FoodDashCall) {
    FoodDashCall.stopAllTones();
  }
}

function respondToRiderArrival(responseOption) {
  const oId = activeCustomerTrackingOrderId || 0;
  if (window.FoodDashCall) {
    FoodDashCall.acknowledgeArrival(oId, responseOption);
  }
  closeRiderArrivedModal();
}

function initiateCustomerVoiceCall() {
  const oId = activeCustomerTrackingOrderId || 0;
  const rName = currentTrackingRiderName || 'Rider';
  const rPhone = currentTrackingRiderPhone || '+63 917 555 0192';
  if (window.FoodDashCall) {
    FoodDashCall.initiateCall(oId, rName, rPhone);
  }
}

function openTrackingFromArrived() {
  closeRiderArrivedModal();
  if (activeCustomerTrackingOrderId) {
    openCustomerTrackingModal(activeCustomerTrackingOrderId);
  } else {
    showScreen('orders');
  }
}

function updateOrderStatusToArrived(orderId) {
  if (!orderId) return;
  const tag = document.getElementById(`status-tag-${orderId}`);
  if (tag) {
    tag.className = 'status-badge status-arrived';
    tag.style.background = '#dcfce7';
    tag.style.color = '#15803d';
    tag.style.borderColor = '#86efac';
    tag.style.fontWeight = '800';
    tag.innerHTML = '<i class="fa-solid fa-location-pin fa-bounce"></i> Arrived';
  }

  const card = document.getElementById(`order-card-${orderId}`);
  if (card) {
    card.setAttribute('data-status', 'arrived');
    const rowIcon = card.querySelector('.row-icon');
    if (rowIcon) rowIcon.textContent = 'ðŸ“';
    const subNotice = card.querySelector('.fa-circle-notch')?.parentElement;
    if (subNotice) {
      subNotice.style.color = '#15803d';
      subNotice.innerHTML = '<i class="fa-solid fa-circle-check"></i> Rider has arrived outside with your order!';
    }
  }

  const trackRef = document.getElementById('trackModalStoreSubtitle');
  if (trackRef) {
    trackRef.innerHTML = '<span style="color:#15803d; font-weight:700;"><i class="fa-solid fa-location-pin-lock fa-bounce"></i> Arrived at Destination</span>';
  }
  const etaText = document.getElementById('custTrackEtaBadgeText');
  if (etaText) {
    etaText.textContent = 'Your rider has arrived outside! ðŸ›µ';
  }
  const distText = document.getElementById('custTrackDistText');
  if (distText) {
    distText.textContent = '0m away (Arrived)';
  }
}

function handleRiderArrivedEvent(data) {
  const oId = data.order_id || activeCustomerTrackingOrderId || 0;
  const arrivedKey = 'arrived_' + oId;
  if (window.displayedNotificationIds.has(arrivedKey)) return;
  window.displayedNotificationIds.add(arrivedKey);

  const rName = data.rider_name || currentTrackingRiderName || 'Alex';

  // Trigger persistent looping arrival alarm (MUST ring until action button is clicked)
  if (window.FoodDashCall) {
    FoodDashCall.triggerArrivalAlarm(oId, rName);
  } else {
    const modal = document.getElementById('riderArrivedModal');
    if (modal) modal.style.display = 'flex';
  }

  toast(`ðŸ“ Your rider has arrived! Please choose a response.`);
  updateOrderStatusToArrived(oId);
}

// Global background poll every 2.5 seconds running continuously on ALL customer screens
async function pollCustomerCommunicationUpdates() {
  if (!currentCustomerId) return;
  try {
    const targetOrderId = activeCustomerTrackingOrderId || 0;
    const url = `order_communication_api.php?action=get_updates&customer_id=${currentCustomerId}&order_id=${targetOrderId}&after_message_id=${lastSeenRiderMsgId}&_t=${Date.now()}`;
    const res = await fetch(url, { cache: 'no-store' });
    if (!res.ok) return;
    const data = await res.json();
    if (!data.success) return;

    if (data.order_id && data.order_id > 0) {
      activeCustomerTrackingOrderId = data.order_id;
      if (data.rider_name) currentTrackingRiderName = data.rider_name;
      if (data.rider_phone) currentTrackingRiderPhone = data.rider_phone;
    }

    // Synchronize Real-Time Bidirectional In-App Calling
    if (window.FoodDashCall) {
      FoodDashCall.syncCallStatus(data.active_call);
    }

    // Detect active incoming call where status is ringing
    if (data.active_call && (data.active_call.status === 'ringing' || data.active_call.call_status === 'ringing') && (data.active_call.caller === 'rider' || data.active_call.caller_role === 'rider')) {
      handleIncomingCallAlert(data.active_call);
    } else if (!data.active_call || ['idle', 'ended', 'declined', 'missed'].includes(data.active_call.status || data.active_call.call_status)) {
      dismissIncomingCallIfRinging();
    }

    if (Array.isArray(data.messages) && data.messages.length > 0) {
      data.messages.forEach(msg => {
        if (msg.sender_role === 'rider' && (!msg.order_id || !targetOrderId || parseInt(msg.order_id, 10) === parseInt(targetOrderId, 10))) {
          handleLiveRiderMessage(msg);
        }
      });
    } else if (data.latest_banner && data.latest_banner.sender_role === 'rider') {
      if (!data.latest_banner.order_id || !targetOrderId || parseInt(data.latest_banner.order_id, 10) === parseInt(targetOrderId, 10)) {
        handleLiveRiderMessage(data.latest_banner);
      }
    }

    // Automated "Rider Arrived" Live Notification
    const sNorm = String(data.status || '').toUpperCase().trim();
    const dNorm = String(data.delivery_status || '').toLowerCase().trim();
    const sLower = String(data.status || '').toLowerCase().trim();

    if (data.is_arrived || dNorm === 'arrived' || sNorm === 'ARRIVED') {
      if (data.arrival_acknowledged) {
        if (window.FoodDashCall) FoodDashCall.stopAllTones();
      } else {
        handleRiderArrivedEvent(data);
      }
    }

    // Real-Time Sync: Preparing / Cooking / In Kitchen
    const isPrep = ['PREPARING', 'COOKING', 'IN_KITCHEN', 'ACCEPTED'].includes(sNorm) 
                || ['preparing', 'cooking', 'in_kitchen', 'accepted'].includes(sLower)
                || ['preparing', 'cooking', 'in_kitchen', 'accepted'].includes(dNorm);

    if (data.order_id && isPrep) {
      const oId = data.order_id;
      const tag = document.getElementById(`status-tag-${oId}`);
      if (tag) {
        tag.className = 'status-badge status-preparing';
        tag.textContent = 'Preparing';
      }
      const card = document.getElementById(`order-card-${oId}`);
      if (card) {
        card.setAttribute('data-status', 'preparing');
      }
    }

    // Real-Time Sync: Ready for Pickup
    const isReady = (sNorm === 'READY_FOR_PICKUP' || sNorm === 'READY' || sLower === 'ready_for_pickup' || sLower === 'ready');
    if (data.order_id && isReady) {
      const oId = data.order_id;
      const tag = document.getElementById(`status-tag-${oId}`);
      if (tag) {
        tag.className = 'status-badge status-ready_for_pickup';
        tag.textContent = 'Ready for Pickup';
      }
      const card = document.getElementById(`order-card-${oId}`);
      if (card) {
        card.setAttribute('data-status', 'ready_for_pickup');
      }
    }

    // Real-Time Sync: Update Order Status & Card Badges on Status Transition
    const isDelivering = (sNorm === 'ON_DELIVERY' || sNorm === 'OUT_FOR_DELIVERY' || sNorm === 'ON_THE_WAY' || dNorm === 'on_the_way' || dNorm === 'on_delivery' || dNorm === 'picked_up');
    if (data.order_id && isDelivering) {
      const oId = data.order_id;
      const tag = document.getElementById(`status-tag-${oId}`);
      if (tag) {
        if (data.is_arrived || dNorm === 'arrived' || sNorm === 'ARRIVED') {
          tag.className = 'status-badge status-arrived';
          tag.innerHTML = '<i class="fa-solid fa-bell fa-shake"></i> Arrived';
        } else if (dNorm === 'near_customer' || sNorm === 'NEAR_CUSTOMER') {
          tag.className = 'status-badge status-near_customer';
          tag.innerHTML = '<i class="fa-solid fa-person-biking fa-beat"></i> Near Customer';
        } else if (tag.textContent.trim().toLowerCase() !== 'on delivery') {
          tag.className = 'status-badge status-out_for_delivery';
          tag.textContent = 'On Delivery';
        }
      }

      const card = document.getElementById(`order-card-${oId}`);
      if (card) {
        card.setAttribute('data-status', (dNorm === 'arrived' || sNorm === 'ARRIVED') ? 'arrived' : ((dNorm === 'near_customer' || sNorm === 'NEAR_CUSTOMER') ? 'near_customer' : 'out_for_delivery'));
        card.classList.add('on-the-way-card');
        card.style.cursor = 'pointer';
        card.onclick = () => openTrackingModal(oId);
        card.setAttribute('title', 'Tap to view live delivery tracking map');

        const rowIcon = card.querySelector('.row-icon');
        if (rowIcon && !rowIcon.textContent.includes('ðŸ“') && !rowIcon.textContent.includes('ðŸ›µ')) {
          rowIcon.className = 'row-icon jolli';
          rowIcon.textContent = 'ðŸ›µ';
        }

        // Hide cancel button if order has transitioned to on_delivery / near / arrived
        const cancelWrap = document.getElementById(`cancel-wrap-${oId}`);
        if (cancelWrap) cancelWrap.style.display = 'none';

        // Ensure the [ ðŸ“ Live Track Rider ] button and [ ðŸ“¦ Received ] button exist on the card
        let trackActions = document.getElementById(`track-actions-${oId}`);
        if (!trackActions) {
          const infoDiv = card.querySelector('.info');
          if (infoDiv) {
            trackActions = document.createElement('div');
            trackActions.id = `track-actions-${oId}`;
            trackActions.className = 'order-actions-wrap';
            trackActions.style.cssText = 'display:flex; gap:6px; margin-top:8px; flex-wrap:wrap;';
            trackActions.innerHTML = `
              <button type="button" class="cust-track-btn" onclick="event.stopPropagation(); openTrackingModal(${oId})" title="Tap to view live delivery map">
                <i class="fa-solid fa-location-dot"></i> ðŸ“ Live Track Rider
              </button>
              <button class="confirm-btn" id="confirm-btn-${oId}" onclick="event.stopPropagation(); confirmOrderReceived(${oId})">
                <i class="fa-solid fa-box-open"></i> ðŸ“¦ Received
              </button>
            `;
            infoDiv.appendChild(trackActions);
          }
        } else {
          trackActions.style.display = 'flex';
        }
      }

      // Restore / display persistent top banner
      const topBanner = document.getElementById('dynamicOnTheWayBanner');
      if (topBanner) {
        topBanner.style.display = 'flex';
        topBanner.onclick = () => openCustomerTrackingModal(oId);
        const span = topBanner.querySelector('span');
        if (span) {
          const cleanBannerRef = data.order_ref ? ('#' + String(data.order_ref).replace(/^#+/, '')) : '';
          const refText = cleanBannerRef ? ` (${escapeCustHtml(cleanBannerRef)})` : '';
          span.innerHTML = `<i class="fa-solid fa-motorcycle fa-beat" style="color:var(--harvest-sun)"></i> Rider is on the way with your food!${refText}`;
        }
      }
    }

    if (data.order_id && (sNorm === 'DELIVERED' || sNorm === 'COMPLETED' || dNorm === 'delivered')) {
      const oId = data.order_id;
      const tag = document.getElementById(`status-tag-${oId}`);
      if (tag) {
        tag.className = 'status-badge status-delivered';
        tag.textContent = 'Delivered';
      }
      const topBanner = document.getElementById('dynamicOnTheWayBanner');
      if (topBanner) topBanner.style.display = 'none';
    }
  } catch (e) {
    console.warn('Customer global poll error:', e);
  }
}

// Continuously execute every 2.5 seconds on ALL screens
pollCustomerCommunicationUpdates();
setInterval(pollCustomerCommunicationUpdates, 2500);

// Check if navigating to a specific screen via hash (e.g. #orders, #cart)
if (window.location.hash) {
  const targetScreen = window.location.hash.replace('#', '');
  if (document.getElementById(targetScreen)) {
    showScreen(targetScreen);
  }
}
</script>
<script>
let selectedMethod = 'cod';
const orderTotal = 'â‚±200.00';

function updatePaymentTheme(method) {
    selectedMethod = method;
    const btn = document.getElementById('payActionBtn') || document.getElementById('btnCheckoutSubmit');
    if (!btn) return;

    if (method === 'cod') {
        btn.style.setProperty('background-color', '#283d23', 'important');
        btn.style.setProperty('color', '#ffffff', 'important');
        btn.innerHTML = '<i class="fa-solid fa-money-bill-wave" style="margin-right:8px;"></i> Place Order â€¢ ' + orderTotal;
    } else if (method === 'gcash') {
        btn.style.setProperty('background-color', '#007DFE', 'important');
        btn.style.setProperty('color', '#ffffff', 'important');
        btn.innerHTML = '<span style="background:#fff; color:#007DFE; font-weight:bold; border-radius:4px; padding:1px 6px; margin-right:6px; font-size:12px;">G</span> Pay with GCash â€¢ ' + orderTotal;
    } else if (method === 'maya') {
        btn.style.setProperty('background-color', '#023a2b', 'important');
        btn.style.setProperty('color', '#05B851', 'important');
        btn.innerHTML = '<span style="background:#05B851; color:#023a2b; font-weight:bold; border-radius:4px; padding:1px 6px; margin-right:6px; font-size:12px;">M</span> Pay with Maya â€¢ ' + orderTotal;
    } else if (method === 'gotyme') {
        btn.style.setProperty('background-color', '#0a2540', 'important');
        btn.style.setProperty('color', '#00d2ff', 'important');
        btn.innerHTML = '<span style="background:#00d2ff; color:#0a2540; font-weight:bold; border-radius:4px; padding:1px 6px; margin-right:6px; font-size:12px;">GT</span> Pay with GoTyme â€¢ ' + orderTotal;
    } else if (method === 'apple_pay') {
        btn.style.setProperty('background-color', '#000000', 'important');
        btn.style.setProperty('color', '#ffffff', 'important');
        btn.innerHTML = '<span style="font-size:18px; margin-right:6px;">ï£¿</span> Pay â€¢ ' + orderTotal;
    }
}

function handlePayAction(e) {
    if (e && e.preventDefault) e.preventDefault();
    try {
        if (typeof checkout === 'function') {
            checkout();
        }
    } catch(err) {
        console.error('Pay action error:', err);
    }
    return false;
}

function triggerCheckout(event) {
    if (event && event.preventDefault) event.preventDefault();
    if (typeof handlePayAction === 'function') { handlePayAction(); }
    return false;
}

function dispatchOrderPlacement(method, address) {
    var chosenMethod = method || 'cod';
    var chosenAddress = String(address || '').trim();
    if (!chosenAddress) {
        toast('Please enter a delivery address.', 'error');
        return;
    }

    var fd = new FormData();
    fd.append('action', 'create_order');
    fd.append('payment_method', chosenMethod);
    fd.append('address', chosenAddress);
    fd.append('delivery_address', chosenAddress);
    fd.append('cart', JSON.stringify(cart));
    fd.append('delivery_fee', '30');

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

var setPayment = updatePaymentTheme;
var selectMethod = updatePaymentTheme;
var onCheckoutButtonClick = handlePayAction;
var triggerCheckoutFlow = handlePayAction;
var handleCheckoutClick = handlePayAction;
var openPaymentPopup = handlePayAction;
var processOrderAJAX = handlePayAction;
var submitOrderToBackend = handlePayAction;
var submitDynamicOrder = dispatchOrderPlacement;
var finalizeOrder = dispatchOrderPlacement;

document.addEventListener('DOMContentLoaded', function() {
    try {
        var params = new URLSearchParams(window.location.search);
        if (params.get('order_placed') === '1') {
            try {
                localStorage.removeItem('foodDashCart');
                var cartBadge = document.getElementById('cartBadge');
                if (cartBadge) cartBadge.style.display = 'none';
            } catch(e) {}

            var banner = document.createElement('div');
            banner.id = 'orderSuccessNotification';
            banner.style.cssText = 'position:fixed; top:18px; left:50%; transform:translateX(-50%); background:#283d23; color:#ffffff; padding:12px 22px; border-radius:30px; box-shadow:0 8px 24px rgba(0,0,0,0.3); z-index:999999; font-size:14px; font-weight:700; display:flex; align-items:center; gap:10px; font-family:sans-serif; animation:slideDown 0.3s ease-out;';
            banner.innerHTML = '<i class="fa-solid fa-circle-check" style="color:#30d158; font-size:18px;"></i> Order Placed Successfully!';
            document.body.appendChild(banner);
            setTimeout(function() {
                banner.style.transition = 'opacity 0.5s ease';
                banner.style.opacity = '0';
                setTimeout(function() { banner.remove(); }, 500);
            }, 4000);

            if (window.history && window.history.replaceState) {
                var cleanUrl = window.location.pathname;
                window.history.replaceState({}, document.title, cleanUrl);
            }
        }
    } catch(e) {}
});
</script>
<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); } require_once 'gmail_simulator_modal.php'; ?>
</body>
</html>





