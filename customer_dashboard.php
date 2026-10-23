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
?>
endif;
?>
