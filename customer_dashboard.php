<?php
session_start();
require_once 'db_connect.php';
require_once 'notification_lib.php';
require_once 'geocode_lib.php';

// Auth Guard: Ensure user is logged in and is a customer
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'customer') {
    header("Location: index.php");
    exit();
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
                    'icon'        => !empty($m_row['icon']) ? $m_row['icon'] : '🍴',
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
        
        // Record audit log entry
        record_order_audit_log($conn, $order_id, $curr['status'], 'DELIVERED', $user_id, 'customer', 'Customer confirmed receipt of order');

        // Notify Admin for settlement reconciliation
        notify_role($conn, 'admin', 'order_delivered', 'Order Delivered - Ready for Settlement', "Order {$curr['order_ref']} marked delivered by customer. Ready for reconciliation.", $order_id);

        api_response(['success' => true, 'message' => 'Order marked as DELIVERED successfully!']);
    }
    
    // Customer places order -> status 'PENDING'
    if ($action === 'checkout' || $action === 'create_order') {
        $cart_items = $input['cart'] ?? [];
        $delivery_fee = (float)($input['delivery_fee'] ?? 30.00);
        $delivery_address = trim((string)($input['delivery_address'] ?? ''));
        $delivery_lat = isset($input['delivery_lat']) && is_numeric($input['delivery_lat']) ? (float)$input['delivery_lat'] : (isset($input['customer_lat']) && is_numeric($input['customer_lat']) ? (float)$input['customer_lat'] : null);
        $delivery_lng = isset($input['delivery_lng']) && is_numeric($input['delivery_lng']) ? (float)$input['delivery_lng'] : (isset($input['customer_lng']) && is_numeric($input['customer_lng']) ? (float)$input['customer_lng'] : null);

        if ($delivery_lat !== null && ($delivery_lat < -90 || $delivery_lat > 90)) $delivery_lat = null;
        if ($delivery_lng !== null && ($delivery_lng < -180 || $delivery_lng > 180)) $delivery_lng = null;

        // Fallback geocoding lookup using address string instead of hardcoding downtown Ormoc coordinates
        if (($delivery_lat === null || $delivery_lng === null) && !empty($delivery_address)) {
            $fallback_geo = geocode_address_fallback($delivery_address);
            if ($fallback_geo && isset($fallback_geo['lat'], $fallback_geo['lng'])) {
                $delivery_lat = $fallback_geo['lat'];
                $delivery_lng = $fallback_geo['lng'];
            }
        }

        $customer_lat = $delivery_lat;
        $customer_lng = $delivery_lng;
        
        if (empty($cart_items)) {
            api_response(['success' => false, 'message' => 'Your cart is empty.'], 400);
        }
        
        // Group cart items by store
        $items_by_store = [];
        foreach ($cart_items as $item) {
            $store = trim((string)($item['store'] ?? 'Food Dash'));
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
                    $icon = trim((string)($item['icon'] ?? '🍴'));
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

                // Snapshot store coordinates from shops table
                $store_lat = null;
                $store_lng = null;
                if ($shop_id > 0) {
                    $sc_stmt = $conn->prepare("SELECT latitude, longitude, lat, lng FROM shops WHERE shop_id = :sid LIMIT 1");
                    $sc_stmt->execute([':sid' => $shop_id]);
                    $sc_row = $sc_stmt->fetch(PDO::FETCH_ASSOC);
                    if ($sc_row) {
                        $store_lat = (!empty($sc_row['latitude']) && is_numeric($sc_row['latitude'])) ? (float)$sc_row['latitude'] : ((!empty($sc_row['lat']) && is_numeric($sc_row['lat'])) ? (float)$sc_row['lat'] : null);
                        $store_lng = (!empty($sc_row['longitude']) && is_numeric($sc_row['longitude'])) ? (float)$sc_row['longitude'] : ((!empty($sc_row['lng']) && is_numeric($sc_row['lng'])) ? (float)$sc_row['lng'] : null);
                    }
                }
                if (($store_lat === null || $store_lng === null) && !empty($store_name)) {
                    $sc_stmt2 = $conn->prepare("SELECT latitude, longitude, lat, lng FROM shops WHERE LOWER(TRIM(shop_name)) = LOWER(TRIM(:sname)) OR LOWER(shop_name) LIKE :slike LIMIT 1");
                    $sc_stmt2->execute([':sname' => $store_name, ':slike' => '%' . strtolower(trim($store_name)) . '%']);
                    $sc_row2 = $sc_stmt2->fetch(PDO::FETCH_ASSOC);
                    if ($sc_row2) {
                        $store_lat = (!empty($sc_row2['latitude']) && is_numeric($sc_row2['latitude'])) ? (float)$sc_row2['latitude'] : ((!empty($sc_row2['lat']) && is_numeric($sc_row2['lat'])) ? (float)$sc_row2['lat'] : null);
                        $store_lng = (!empty($sc_row2['longitude']) && is_numeric($sc_row2['longitude'])) ? (float)$sc_row2['longitude'] : ((!empty($sc_row2['lng']) && is_numeric($sc_row2['lng'])) ? (float)$sc_row2['lng'] : null);
                    }
                }
                
                // Insert order with initial status 'PENDING'
                $stmt = $conn->prepare("INSERT INTO food_orders (shop_id, store_name, store_lat, store_lng, customer_id, customer_name, order_ref, items_summary, subtotal, delivery_fee, total_amount, status, delivery_status, order_type, delivery_address, delivery_lat, delivery_lng, customer_lat, customer_lng, created_at) VALUES (:shop_id, :store_name, :store_lat, :store_lng, :customer_id, :customer_name, :order_ref, :items_summary, :subtotal, :delivery_fee, :total_amount, 'PENDING', 'waiting', 'Delivery', :delivery_address, :delivery_lat, :delivery_lng, :customer_lat, :customer_lng, NOW())");
                $stmt->execute([
                    ':shop_id'          => $shop_id,
                    ':store_name'       => $store_name,
                    ':store_lat'        => $store_lat,
                    ':store_lng'        => $store_lng,
                    ':customer_id'      => $user_id,
                    ':customer_name'    => $cname,
                    ':order_ref'        => $unique_ref,
                    ':items_summary'    => $items_summary,
                    ':subtotal'         => $subtotal,
                    ':delivery_fee'     => $delivery_fee,
                    ':total_amount'     => $total_amount,
                    ':delivery_address' => !empty($delivery_address) ? $delivery_address : null,
                    ':delivery_lat'     => $delivery_lat,
                    ':delivery_lng'     => $delivery_lng,
                    ':customer_lat'     => $customer_lat,
                    ':customer_lng'     => $customer_lng,
                ]);
                $new_order_id = (int)$conn->lastInsertId();
                
                // Insert items into food_order_items
                $it_stmt = $conn->prepare("INSERT INTO food_order_items (order_id, item_name, store_name, quantity, unit_price, line_total, icon) VALUES (:oid, :name, :store, :qty, :price, :line, :icon)");
                foreach ($clean_items as $ci) {
                    $it_stmt->execute([
                        ':oid'   => $new_order_id,
                        ':name'  => $ci['name'],
                        ':store' => $ci['store'],
                        ':qty'   => $ci['qty'],
                        ':price' => $ci['price'],
                        ':line'  => $ci['line'],
                        ':icon'  => $ci['icon'],
                    ]);
                }

                // Insert into order_items compatibility table
                $co_stmt = $conn->prepare("INSERT INTO order_items (order_id, item_name, store_name, quantity, unit_price, line_total, icon) VALUES (:oid, :name, :store, :qty, :price, :line, :icon)");
                foreach ($clean_items as $ci) {
                    $co_stmt->execute([
                        ':oid'   => $new_order_id,
                        ':name'  => $ci['name'],
                        ':store' => $ci['store'],
                        ':qty'   => $ci['qty'],
                        ':price' => $ci['price'],
                        ':line'  => $ci['line'],
                        ':icon'  => $ci['icon'],
                    ]);
                }

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

$stmt = $conn->prepare("SELECT order_id, order_ref, store_name, items_summary, total_amount, status, delivery_status, order_type, delivery_address, delivery_lat, delivery_lng, customer_lat, customer_lng, created_at FROM food_orders WHERE customer_id = :cid ORDER BY order_id DESC LIMIT 40");
if ($stmt) {
    $stmt->execute([':cid' => $user_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { 
        $db_orders[] = $row; 
        $st = strtoupper(trim(str_replace([' ', '-'], '_', $row['status'])));
        if (in_array($st, ['OUT_FOR_DELIVERY', 'ON_THE_WAY', 'ON_DELIVERY', 'NEAR_CUSTOMER'])) {
            $ontheway_count++;
            if (!$active_tracking_order) {
                $active_tracking_order = $row;
            }
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
            'icon'        => !empty($mi['icon']) ? $mi['icon'] : '🍴',
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
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Food Dash — Customer</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
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
*{box-sizing:border-box;margin:0;padding:0;font-family:Poppins,Arial,sans-serif}

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

button,input{font:inherit}
button{cursor:pointer}

.app{
  width:100%;
  max-width:440px;
  min-height:100vh;
  background:var(--paper);
  position:relative;
  box-shadow:var(--shadow);
  display:flex;
  flex-direction:column;
  margin: 0 auto;
}

@media(min-width:600px){
  .app{
    min-height:720px;
    height: auto;
    border:1px solid #ddd3ad;
    border-radius:26px;
    box-shadow:0 22px 60px rgba(55,65,22,.15);
    overflow: hidden;
  }
}

@media(max-width:599px){
  html, body { padding: 0; }
  .app { border: none; border-radius: 0; box-shadow: none; min-height: 100vh; }
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
  margin: 10px 16px 0;
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

.screen{display:none;padding:14px 16px 30px;flex:1}
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

/* Status Badges - Food Dash Palette */
.status-badge { display:inline-flex; align-items:center; gap:3px; padding: 2.5px 8px; border-radius: 99px; font-size: 8px; font-weight:700; text-transform: capitalize; }
.status-pending { background: #FAF6EE; color: var(--harvest-sun); border: 1px solid var(--harvest-sun); }
.status-preparing { background: #FAF6EE; color: var(--sagebrush); border: 1px solid var(--sagebrush); }
.status-ready_for_pickup, .status-ready { background: #FAF6EE; color: var(--olive-shadow); border: 1px solid var(--olive-shadow); }
.status-out_for_delivery, .status-on_the_way, .status-on_delivery, .status-near_customer { background: #FAF6EE; color: var(--harvest-sun); border: 1px solid var(--harvest-sun); }
.status-delivered { background: #FAF6EE; color: var(--sagebrush); border: 1px solid var(--sagebrush); }
.status-settled, .status-completed { background: var(--olive-shadow); color: white; border: 1px solid var(--olive-shadow); }
.settled-tag { font-size: 7.5px; background: #FAF6EE; color: var(--olive-shadow); border: 1px solid var(--olive-shadow); padding: 2px 6px; border-radius: 8px; font-weight: 600; display: inline-flex; align-items:center; gap: 3px; }
.settled-icon { background: var(--olive-shadow) !important; color: white !important; }

.confirm-btn {
  margin-top: 8px;
  background: var(--olive-shadow);
  color: white;
  border: 0;
  padding: 7px 12px;
  border-radius: 8px;
  font-size: 9px;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  transition: opacity 0.2s;
}
.confirm-btn:hover { opacity: 0.9; }

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

.chips{display:flex;gap:7px;overflow-x:auto;margin:10px 0 14px;padding-bottom:4px;-webkit-overflow-scrolling:touch}.chips::-webkit-scrollbar{display:none}
.chip{border:1px solid var(--line);background:#fff;border-radius:18px;padding:7px 13px;font-size:8.5px;font-weight:600;color:var(--olive-shadow);white-space:nowrap;cursor:pointer;transition:all .2s ease;outline:none;user-select:none}
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

.bottom{
  position:sticky;
  bottom:0;
  width:100%;
  height:65px;
  background:rgba(255,255,255,.98);
  border-top:1px solid var(--line);
  display:grid;
  grid-template-columns:repeat(5,1fr);
  z-index:100;
  margin-top:auto;
}
.nav{border:0;background:transparent;color:#72776a;font-size:8px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;position:relative}.nav i{font-size:15px}.nav.active{color:var(--olive-dark);font-weight:700}.badge{position:absolute;background:#e7352d;color:white;font-size:7px;border-radius:10px;padding:2px 5px;top:4px;right:20px}

.toast{position:fixed;left:50%;bottom:82px;transform:translateX(-50%) translateY(20px);background:var(--olive-dark);color:white;padding:9px 13px;border-radius:20px;font-size:9px;opacity:0;pointer-events:none;transition:.25s;z-index:200;white-space:nowrap}.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}

.logout{width:100%;background:#fff;cursor:pointer;font-family:inherit;margin-top:12px;border:1px solid #e55b52;color:#d33b32;border-radius:11px;padding:10px;font-size:9px;font-weight:600}
.logout-modal{display:none;position:fixed;inset:0;background:rgba(28,32,6,.38);backdrop-filter:blur(3px);align-items:center;justify-content:center;padding:18px;z-index:1000}.logout-modal.show{display:flex}.logout-box{width:min(360px,100%);background:#fffdf7;border:1px solid var(--line);border-radius:22px;padding:22px;text-align:center;box-shadow:0 20px 60px rgba(55,65,22,.22)}.logout-box .logout-logo{width:58px;height:58px;border-radius:50%;object-fit:cover;border:1px solid #d7c98d;margin-bottom:9px}.logout-box h3{font-size:16px;color:var(--olive-dark);margin:0 0 5px}.logout-box p{font-size:9px;color:var(--muted);line-height:1.5;margin-bottom:17px}.logout-actions{display:flex;gap:8px}.logout-actions button,.logout-actions a{flex:1;padding:10px;border-radius:11px;font-size:9px;font-weight:700;text-decoration:none;border:0;font-family:inherit}.logout-cancel{background:#eee9d7;color:var(--olive)}.logout-confirm{background:var(--olive);color:white;display:grid;place-items:center}

/* Customer Live Tracking & Chat Styles */
.cust-track-btn {
  background: var(--harvest-sun);
  color: #ffffff;
  border: none;
  border-radius: 8px;
  padding: 6px 10px;
  font-size: 8px;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 5px;
  cursor: pointer;
  box-shadow: 0 2px 6px rgba(216, 143, 42, 0.25);
  transition: all 0.2s;
}
.cust-track-btn:hover {
  filter: brightness(1.08);
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
  top: 20px;
  left: 50%;
  transform: translateX(-50%) translateY(-20px);
  background: var(--olive-shadow);
  color: #ffffff;
  padding: 10px 16px;
  border-radius: 20px;
  font-size: 0.72rem;
  font-weight: 600;
  opacity: 0;
  pointer-events: none;
  transition: all 0.25s ease;
  z-index: 3000;
  box-shadow: 0 4px 16px rgba(75, 83, 32, 0.35);
  display: flex;
  align-items: center;
  gap: 8px;
  max-width: 90vw;
}
.cust-toast.show {
  opacity: 1;
  transform: translateX(-50%) translateY(0);
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

/* Live Rider Message Banner */
.live-rider-msg-banner {
  position: fixed;
  top: 16px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 999998;
  width: calc(100% - 32px);
  max-width: 440px;
  background: #1e293b;
  color: #ffffff;
  border-radius: 16px;
  padding: 12px 14px;
  display: flex;
  align-items: center;
  gap: 12px;
  box-shadow: 0 12px 30px rgba(0, 0, 0, 0.35);
  border: 1.5px solid rgba(255, 255, 255, 0.12);
  cursor: pointer;
  animation: bannerSlideDown 0.35s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
@keyframes bannerSlideDown {
  0% { transform: translate(-50%, -40px); opacity: 0; }
  100% { transform: translate(-50%, 0); opacity: 1; }
}
.msg-banner-avatar {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: #16a34a;
  color: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  flex-shrink: 0;
  box-shadow: 0 2px 8px rgba(22, 163, 74, 0.4);
}
.msg-banner-content {
  flex: 1;
  min-width: 0;
}
.msg-banner-title {
  display: flex;
  justify-content: space-between;
  font-size: 0.72rem;
  font-weight: 700;
  color: #94a3b8;
  text-transform: uppercase;
  margin-bottom: 2px;
}
.msg-banner-body {
  font-size: 0.88rem;
  font-weight: 600;
  color: #ffffff;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.tap-reply-pill {
  background: rgba(255, 255, 255, 0.12);
  color: #ffffff;
  font-size: 0.72rem;
  font-weight: 700;
  padding: 5px 9px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  gap: 4px;
  flex-shrink: 0;
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
      <div class="avatar"><?php echo strtoupper(substr($full_name,0,1)); ?></div>
    </div>
  </div>
</header>

<?php if ($ontheway_count > 0 && !empty($active_tracking_order)): ?>
<div class="notice-bar" onclick="openCustomerTrackingModal(<?= (int)$active_tracking_order['order_id'] ?>)" style="cursor:pointer;" title="Tap to view real-time delivery GPS">
  <span><i class="fa-solid fa-motorcycle fa-beat" style="color:var(--harvest-sun)"></i> Rider is on the way with <?= htmlspecialchars($active_tracking_order['order_ref']) ?>!</span>
  <button type="button" onclick="event.stopPropagation(); openCustomerTrackingModal(<?= (int)$active_tracking_order['order_id'] ?>)">View</button>
</div>
<?php elseif ($ontheway_count > 0): ?>
<div class="notice-bar" onclick="showScreen('orders')" style="cursor:pointer;">
  <span><i class="fa-solid fa-motorcycle"></i> Your rider is on the way with your food!</span>
  <button type="button" onclick="showScreen('orders')">View</button>
</div>
<?php endif; ?>

<section id="home" class="screen active">
  <div class="greeting"><small>Good Morning,</small><h1><?php echo htmlspecialchars($full_name); ?>!</h1></div>
  <div class="search" onclick="showScreen('search')"><i class="fa-solid fa-magnifying-glass"></i><input readonly placeholder="Search for food, restaurants, or stores..."></div>
  <div class="hero"><h2>Delicious Food<br>Fresh & Fast</h2><p>Your favorite Filipino meals delivered.</p><button onclick="showScreen('stores')">Order Now</button></div>

  <div class="section-title"><h3>Popular Stores</h3><a onclick="showScreen('stores')">See All</a></div>
  <div class="store-row">
    <?php 
    $popular_stores = !empty($db_shops) ? $db_shops : [
        ['shop_name' => 'FoodDash Grill', 'category' => 'Steakhouse & Grill', 'logo_url' => 'logo.jpg'],
        ['shop_name' => 'McDonalds', 'category' => 'Burgers • Fries', 'logo_url' => 'assets/shops/mcdonalds.png'],
        ['shop_name' => 'Jollibee', 'category' => 'Chickenjoy • Fast Food', 'logo_url' => 'assets/shops/jollibee.png'],
        ['shop_name' => 'Mang Inasal', 'category' => 'Grilled Chicken • Rice Meals', 'logo_url' => 'assets/shops/mang_inasal.png'],
        ['shop_name' => 'Ribshack', 'category' => 'Ribs • BBQ', 'logo_url' => 'assets/shops/ribshack.png'],
        ['shop_name' => 'Shakeys', 'category' => 'Pizza • Mojos', 'logo_url' => 'assets/shops/shakeys.png'],
        ['shop_name' => 'Chowking', 'category' => 'Chinese • Dim Sum', 'logo_url' => 'assets/shops/chowking.png'],
        ['shop_name' => 'Greenwich', 'category' => 'Pizza • Pasta', 'logo_url' => 'assets/shops/greenwich.png']
    ];
    foreach ($popular_stores as $ps):
        $p_logo = !empty($ps['logo_url']) ? $ps['logo_url'] : 'assets/shops/default_store.png';
        $p_name = $ps['shop_name'];
        $p_cat = $ps['category'] ?? 'Restaurant';
    ?>
    <div class="store" onclick="openStore('<?php echo htmlspecialchars(addslashes($p_name)); ?>', '<?php echo htmlspecialchars(addslashes($p_logo)); ?>')">
      <div class="store-logo">
        <img src="<?php echo htmlspecialchars($p_logo); ?>" alt="<?php echo htmlspecialchars($p_name); ?>" onerror="if(!this.dataset.fallback){this.dataset.fallback=1;this.src='assets/shops/default_store.png';}else{this.onerror=null;this.src='logo.jpg';}">
      </div>
      <strong><?php echo htmlspecialchars($p_name); ?></strong>
      <small><?php echo htmlspecialchars($p_cat); ?></small>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="section-title"><h3>Popular Near You</h3><a onclick="showScreen('stores')">See All</a></div>
  <div class="grid">
    <div class="food-card"><div class="food-img">🍔</div><div class="food-body"><strong>Big Mac</strong><small>McDonalds</small><div class="price-row"><span class="price">₱179.00</span><button class="add" onclick="addCart('Big Mac',179,'🍔','McDonalds')">+</button></div></div></div>
    <div class="food-card"><div class="food-img">🍗</div><div class="food-body"><strong>Chickenjoy</strong><small>Jollibee</small><div class="price-row"><span class="price">₱129.00</span><button class="add" onclick="addCart('Chickenjoy',129,'🍗','Jollibee')">+</button></div></div></div>
    <div class="food-card"><div class="food-img">🍚</div><div class="food-body"><strong>Chicken Inasal</strong><small>Mang Inasal</small><div class="price-row"><span class="price">₱189.00</span><button class="add" onclick="addCart('Chicken Inasal',189,'🍚','Mang Inasal')">+</button></div></div></div>
    <div class="food-card"><div class="food-img">🍕</div><div class="food-body"><strong>Managers Choice Pizza</strong><small>Shakeys</small><div class="price-row"><span class="price">₱499.00</span><button class="add" onclick="addCart('Managers Choice Pizza',499,'🍕','Shakeys')">+</button></div></div></div>
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
    $all_stores = !empty($db_shops) ? $db_shops : [
        ['shop_name' => 'FoodDash Grill', 'category' => 'Steakhouse & Grill', 'logo_url' => 'logo.jpg'],
        ['shop_name' => 'McDonalds', 'category' => 'Burgers • Fries • McSpaghetti', 'logo_url' => 'assets/shops/mcdonalds.png'],
        ['shop_name' => 'Jollibee', 'category' => 'Chicken • Burgers • Spaghetti', 'logo_url' => 'assets/shops/jollibee.png'],
        ['shop_name' => 'Mang Inasal', 'category' => 'Grilled Chicken • Rice Meals', 'logo_url' => 'assets/shops/mang_inasal.png'],
        ['shop_name' => 'Ribshack', 'category' => 'Ribs • Chicken • Pasta', 'logo_url' => 'assets/shops/ribshack.png'],
        ['shop_name' => 'Shakeys', 'category' => 'Pizza • Chicken • Pasta', 'logo_url' => 'assets/shops/shakeys.png'],
        ['shop_name' => 'Chowking', 'category' => 'Chinese Favorites • Rice Meals', 'logo_url' => 'assets/shops/chowking.png'],
        ['shop_name' => 'Greenwich', 'category' => 'Pizza • Pasta • Chicken', 'logo_url' => 'assets/shops/greenwich.png']
    ];
    foreach ($all_stores as $sh): 
      $sh_logo = !empty($sh['logo_url']) ? $sh['logo_url'] : 'assets/shops/default_store.png';
      $sh_name = $sh['shop_name'];
      $sh_cat = $sh['category'] ?? 'Specialty Food';
    ?>
    <div class="row-card" data-name="<?php echo strtolower(htmlspecialchars($sh_name)); ?>" data-category="<?php echo strtolower(htmlspecialchars($sh_cat)); ?>" onclick="openStore('<?php echo htmlspecialchars(addslashes($sh_name)); ?>', '<?php echo htmlspecialchars(addslashes($sh_logo)); ?>')">
      <div class="row-icon">
        <img src="<?php echo htmlspecialchars($sh_logo); ?>" alt="<?php echo htmlspecialchars($sh_name); ?>" onerror="if(!this.dataset.fallback){this.dataset.fallback=1;this.src='assets/shops/default_store.png';}else{this.onerror=null;this.src='logo.jpg';}">
      </div>
      <div class="info">
        <strong><?php echo htmlspecialchars($sh_name); ?></strong>
        <small><?php echo htmlspecialchars($sh_cat); ?></small>
        <small>⭐ 4.8 • 20–35 min • <b style="color:#2f8f45">Open</b></small>
      </div>
      <i class="fa-solid fa-chevron-right chev"></i>
    </div>
    <?php endforeach; ?>
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
    <div class="rating">⭐ 4.8 &nbsp; • &nbsp; 20–30 min &nbsp; • &nbsp; <b>Open</b></div>
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
    <div class="item-art" id="itemArt">🍔</div>
    <div class="item-info"><h2 id="itemName">Big Mac</h2><p id="itemDesc">Two beef patties, special sauce, lettuce and cheese.</p><div class="item-price" id="itemPrice">₱179.00</div><div class="qty"><button onclick="changeQty(-1)">−</button><span id="itemQty">1</span><button onclick="changeQty(1)">+</button></div><button class="primary" style="margin-top:13px" onclick="addCurrentItem()">Add to Cart</button></div>
  </div>
</section>

<section id="search" class="screen">
  <div class="section-title"><h3>Search</h3><i class="fa-solid fa-sliders"></i></div>
  <div class="search"><i class="fa-solid fa-magnifying-glass"></i><input id="searchInput" oninput="searchFood()" placeholder="Search for restaurants, food, or stores..."></div>
  <div class="section-title"><h3>Popular Searches</h3></div>
  <div class="chips"><button class="chip" onclick="setSearch('big mac')">Big Mac</button><button class="chip" onclick="setSearch('chickenjoy')">Chickenjoy</button><button class="chip" onclick="setSearch('mcspaghetti')">McSpaghetti</button><button class="chip" onclick="setSearch('inasal')">Inasal</button><button class="chip" onclick="setSearch('pizza')">Pizza</button></div>
  <div class="list" id="searchResults">
    <div class="row-card" onclick="openStore('FoodDash Grill','logo.jpg')"><div class="row-icon"><img src="logo.jpg" alt="FoodDash Grill" onerror="this.src='assets/shops/default_store.png'"></div><div class="info"><strong>FoodDash Grill</strong><small>Smoked Ribs • Flame-Grilled BBQ • Angus Burgers</small></div></div>
    <div class="row-card" onclick="openStore('McDonalds','assets/shops/mcdonalds.png')"><div class="row-icon"><img src="assets/shops/mcdonalds.png" alt="McDonalds" onerror="this.src='assets/shops/default_store.png'"></div><div class="info"><strong>McDonalds</strong><small>Big Mac • McSpaghetti • French Fries</small></div></div>
    <div class="row-card" onclick="openStore('Jollibee','assets/shops/jollibee.png')"><div class="row-icon"><img src="assets/shops/jollibee.png" alt="Jollibee" onerror="this.src='assets/shops/default_store.png'"></div><div class="info"><strong>Jollibee</strong><small>Chickenjoy • Jolly Spaghetti • Yumburger</small></div></div>
    <div class="row-card" onclick="openStore('Mang Inasal','assets/shops/mang_inasal.png')"><div class="row-icon"><img src="assets/shops/mang_inasal.png" alt="Mang Inasal" onerror="this.src='assets/shops/default_store.png'"></div><div class="info"><strong>Mang Inasal</strong><small>Chicken Inasal • Pork BBQ • Halo-Halo</small></div></div>
    <div class="row-card" onclick="openStore('Ribshack','assets/shops/ribshack.png')"><div class="row-icon"><img src="assets/shops/ribshack.png" alt="Ribshack" onerror="this.src='assets/shops/default_store.png'"></div><div class="info"><strong>Ribshack</strong><small>Baby Back Ribs • Chicken BBQ • Meals</small></div></div>
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
      <input type="hidden" id="delivery_lat" name="delivery_lat" value="">
      <input type="hidden" id="delivery_lng" name="delivery_lng" value="">
      <input type="hidden" id="deliveryLatInput" value="">
      <input type="hidden" id="deliveryLngInput" value="">
    </div>

    <div class="summary">
      <div class="sumrow"><span>Subtotal</span><span id="subtotal">₱0.00</span></div>
      <div class="sumrow"><span>Delivery Fee</span><span>₱30.00</span></div>
      <div class="sumrow total"><span>Total</span><span id="total">₱30.00</span></div>
    </div>
    <button class="primary" style="margin-top:12px" id="btnCheckoutSubmit" onclick="checkout()">Checkout</button>
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
  </div>
  <div class="list" id="orderList">
    <?php if (empty($db_orders)): ?>
      <div class="row-card" id="noOrders"><div class="row-icon green">🛍️</div><div class="info"><strong>No database orders yet</strong><small>Your completed checkout will appear here and be sent to the Chef.</small></div></div>
    <?php else: foreach ($db_orders as $ord): 
      $raw_status = strtoupper(trim(str_replace([' ', '-'], '_', $ord['status'])));
      $norm_status = strtolower($raw_status);
      $is_ontheway = in_array($raw_status, ['OUT_FOR_DELIVERY', 'ON_THE_WAY', 'ON_DELIVERY', 'NEAR_CUSTOMER']);
      $is_delivered = ($raw_status === 'DELIVERED' || $raw_status === 'COMPLETED');
      $is_settled = ($raw_status === 'SETTLED');
      $is_prep = ($raw_status === 'PREPARING');
      $is_ready = in_array($raw_status, ['READY_FOR_PICKUP', 'READY']);
      
      $status_label = ucwords(strtolower(str_replace('_', ' ', $raw_status)));
      if ($raw_status === 'READY_FOR_PICKUP') $status_label = 'Ready for Pickup';
      elseif ($raw_status === 'OUT_FOR_DELIVERY') $status_label = 'Out for Delivery';
    ?>
      <div class="row-card order-row <?= $is_ontheway ? 'on-the-way-card' : '' ?>" data-status="<?= htmlspecialchars($norm_status) ?>" id="order-card-<?= $ord['order_id'] ?>" <?= $is_ontheway ? 'onclick="openCustomerTrackingModal(' . (int)$ord['order_id'] . ')" style="cursor:pointer;" title="Tap to view live delivery tracking map"' : '' ?>>
        <div class="row-icon <?= $is_ontheway ? 'jolli' : ($is_settled ? 'settled-icon' : 'green') ?>">
          <?= $is_ontheway ? '🛵' : ($is_settled ? '🛡️' : ($is_delivered ? '📦' : ($is_prep ? '🍳' : '🛍️'))) ?>
        </div>
        <div class="info">
          <strong><?= htmlspecialchars($ord['order_ref']) ?></strong>
          <small><?= htmlspecialchars($ord['store_name']) ?> • <?= htmlspecialchars($ord['items_summary']) ?></small>
          <small><?= htmlspecialchars(date('M d, Y • g:i A', strtotime($ord['created_at']))) ?> • ₱<?= number_format((float)$ord['total_amount'], 2) ?></small>
          <?php if (!empty($ord['delivery_address'])): ?>
            <small style="color: var(--olive-dark); font-weight: 500; margin-top: 3px; white-space: normal; line-height: 1.35;"><i class="fa-solid fa-location-dot" style="color:var(--harvest-sun)"></i> <?= htmlspecialchars($ord['delivery_address']) ?></small>
          <?php endif; ?>
          
          <div style="margin-top: 5px; display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
            <span class="status-badge status-<?= htmlspecialchars($norm_status) ?>" id="status-tag-<?= $ord['order_id'] ?>">
              <?= htmlspecialchars($status_label) ?>
            </span>
            <?php if ($is_settled): ?>
              <span class="settled-tag"><i class="fa-solid fa-circle-check"></i> Reconciled</span>
            <?php endif; ?>
          </div>

          <?php if ($is_ontheway): ?>
            <div style="font-size: 8px; color: var(--harvest-sun); font-weight:600; margin-top:4px;">
              <i class="fa-solid fa-circle-notch fa-spin"></i> Rider is on the way with your order!
            </div>
            <div style="display:flex; gap:6px; margin-top:6px; flex-wrap:wrap;">
              <button type="button" class="cust-track-btn" onclick="event.stopPropagation(); openCustomerTrackingModal(<?= (int)$ord['order_id'] ?>)">
                <i class="fa-solid fa-location-crosshairs"></i> Live GPS Tracking
              </button>
              <button class="confirm-btn" id="confirm-btn-<?= $ord['order_id'] ?>" onclick="event.stopPropagation(); confirmOrderReceived(<?= $ord['order_id'] ?>)">
                <i class="fa-solid fa-box-open"></i> Received
              </button>
            </div>
          <?php elseif ($is_delivered && !$is_settled): ?>
            <div style="font-size: 8px; color: var(--sagebrush); font-weight:600; margin-top:4px;">
              <i class="fa-solid fa-circle-check"></i> Order Delivered. Awaiting admin settlement.
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</section>

<section id="account" class="screen">
  <div class="section-title"><h3>My Account</h3><i class="fa-solid fa-gear"></i></div>
  <div class="profile"><div class="profile-pic">👤</div><div><strong><?php echo htmlspecialchars($full_name); ?></strong><small>Customer account</small></div></div>
  <div class="menu">
    <a href="#" onclick="showScreen('orders');return false;"><span><i class="fa-regular fa-clipboard"></i>My Orders</span><i class="fa-solid fa-chevron-right"></i></a>
    <a href="#" onclick="accountNotice('Addresses');return false;"><span><i class="fa-solid fa-location-dot"></i>Addresses</span><i class="fa-solid fa-chevron-right"></i></a>
    <a href="#" onclick="accountNotice('Payment Methods');return false;"><span><i class="fa-regular fa-credit-card"></i>Payment Methods</span><i class="fa-solid fa-chevron-right"></i></a>
    <a href="#" onclick="openNotifications();return false;"><span><i class="fa-regular fa-bell"></i>Notifications</span><i class="fa-solid fa-chevron-right"></i></a>
    <a href="#" onclick="accountNotice('Help & Support');return false;"><span><i class="fa-regular fa-circle-question"></i>Help & Support</span><i class="fa-solid fa-chevron-right"></i></a>
    <a href="#" onclick="accountNotice('About Food Dash');return false;"><span><i class="fa-solid fa-circle-info"></i>About Food Dash</span><i class="fa-solid fa-chevron-right"></i></a>
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
    
    <input type="hidden" id="modal_delivery_lat" name="delivery_lat" value="">
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
      <h3><span>🔔</span> Notifications</h3>
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

<div class="logout-modal" id="logoutModal" onclick="if(event.target===this)closeLogout()"><div class="logout-box"><img class="logout-logo" src="logo.jpg" alt="Food Dash"><h3>Log out of Food Dash?</h3><p>Your current session will be signed out. You can log in again anytime.</p><div class="logout-actions"><button class="logout-cancel" type="button" onclick="closeLogout()">Cancel</button><a class="logout-confirm" href="logout.php?confirm=1">Yes, Log Out</a></div></div></div>

<!-- Customer Live Delivery Tracking Modal -->
<div class="fd-modal" id="customerTrackingModal" onclick="if(event.target===this)closeCustomerTrackingModal()">
  <div class="fd-modal-box cust-track-modal-box">
    <div class="fd-modal-header" style="padding-bottom:10px; border-bottom:1px solid var(--sandstone);">
      <div style="display:flex; align-items:center; gap:8px;">
        <span style="font-size:18px;">🛵</span>
        <div>
          <h3 id="trackModalOrderRef" style="margin:0; font-size:13px; color:var(--olive-shadow);">Live Delivery Tracking</h3>
          <small id="trackModalStoreSubtitle" style="font-size:10px; color:var(--muted);">Store pickup & real-time GPS</small>
        </div>
      </div>
      <button class="fd-modal-close" onclick="closeCustomerTrackingModal()">&times;</button>
    </div>

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
        <a id="custTrackCallLink" href="tel:+639175550192" class="cust-comm-btn call" title="Call Rider">
          <i class="fa-solid fa-phone"></i> Call Rider
        </a>
        <button id="custTrackMsgBtn" type="button" class="cust-comm-btn msg" onclick="openCustomerChat()" title="Message Rider (Chat)">
          <i class="fa-solid fa-comment-dots"></i> Message Rider
        </button>
        <a id="custTrackSmsBtn" href="sms:+639175550192" class="cust-comm-btn sms" title="Direct SMS to Rider">
          <i class="fa-solid fa-envelope"></i> SMS
        </a>
      </div>
    </div>

    <!-- Remaining distance & ETA badge (e.g. "500m away • ~3 mins") -->
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
    <div id="cust-tracking-map" style="height: 310px; width: 100%; border-radius: 12px; margin-top: 10px; border: 1.5px solid var(--sandstone); position:relative;"></div>

    <!-- Legend -->
    <div class="cust-map-legend">
      <span><span class="legend-dot store"></span> Store Pickup</span>
      <span><span class="legend-dot rider"></span> Rider GPS</span>
      <span><span class="legend-dot customer"></span> Your Destination</span>
    </div>

    <div class="fd-modal-actions" style="margin-top:12px;">
      <button class="btn-fd-secondary" onclick="closeCustomerTrackingModal()">Close Map</button>
      <button class="btn-fd-primary" onclick="openCustomerChat()"><i class="fa-solid fa-comment-dots"></i> Chat with Rider</button>
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
        <button type="button" class="quick-pill" onclick="sendCustomerQuickReply('I\'m waiting outside! 👋')">👋 I'm waiting outside</button>
        <button type="button" class="quick-pill" onclick="sendCustomerQuickReply('Please leave it with the lobby/guard house 🛡️')">🛡️ Leave at guard house</button>
        <button type="button" class="quick-pill" onclick="sendCustomerQuickReply('Thank you! Take your time and ride safely 🛵')">🛵 Ride safely</button>
        <button type="button" class="quick-pill" onclick="sendCustomerQuickReply('Please ring doorbell when you arrive 🔔')">🔔 Ring doorbell</button>
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
      <a href="#" id="custChatSmsLink" class="chat-sms-btn" target="_blank" title="Send via native SMS"><i class="fa-solid fa-comment-dots"></i> SMS</a>
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
        <i class="fa-solid fa-phone"></i> Accept
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

<!-- Live Rider Message Banner -->
<div id="liveRiderMessageBanner" class="live-rider-msg-banner" style="display:none;" onclick="openChatFromBanner()">
  <div class="msg-banner-avatar"><i class="fa-solid fa-motorcycle"></i></div>
  <div class="msg-banner-content">
    <div class="msg-banner-title">
      <span>New message from your rider</span>
      <span class="msg-banner-time">Just now</span>
    </div>
    <div class="msg-banner-body" id="liveMsgBannerText">I'm outside with your order! 🛵</div>
  </div>
  <div class="msg-banner-action">
    <span class="tap-reply-pill">Reply <i class="fa-solid fa-chevron-right"></i></span>
  </div>
</div>

<nav class="bottom">
  <button class="nav active" data-screen="home" onclick="showScreen('home')"><i class="fa-solid fa-house"></i><span>Home</span></button>
  <button class="nav" data-screen="stores" onclick="showScreen('stores')"><i class="fa-solid fa-store"></i><span>Stores</span></button>
  <button class="nav" data-screen="search" onclick="showScreen('search')"><i class="fa-solid fa-magnifying-glass"></i><span>Search</span></button>
  <button class="nav" data-screen="cart" onclick="showScreen('cart')"><i class="fa-solid fa-bag-shopping"></i><span>Cart</span><b class="badge" id="cartBadge" style="display:none">0</b></button>
  <button class="nav" data-screen="account" onclick="showScreen('account')"><i class="fa-regular fa-user"></i><span>Account</span></button>
</nav>

<div class="toast" id="toast"></div>
</div>

<script>
let cart = JSON.parse(localStorage.getItem('foodDashCart') || '[]');
let itemQty = 1;
let currentItem = {name:'Big Mac', price:179, icon:'🍔', desc:'Two beef patties, special sauce, lettuce and cheese.', store:'McDonalds'};

const onTheWayCount = <?php echo (int)$ontheway_count; ?>;

const dbStoreMenus = <?php echo json_encode($db_store_menus, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
const dbStoreCategories = <?php echo json_encode($db_store_categories, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

const menus = {
  'FoodDash Grill': [
    ['Smoked Pork BBQ Ribs',289,'🍖','Signature flame-grilled pork ribs with house barbecue glaze.','Ribs & Steaks'],
    ['Flame-Grilled Inasal Platter',219,'🍗','Charcoal-grilled quarter chicken with spiced garlic rice.','Grilled Chicken'],
    ['Angus Beef Grill Burger',199,'🍔','100% Angus beef patty flame-grilled to perfection with cheddar.','Burgers'],
    ['Grilled Pork Belly Liempo',249,'🥩','Marinated succulent pork belly grilled over live charcoal.','BBQ & Pork']
  ],
  'McDonalds': [
    ['Big Mac',179,'🍔','Two beef patties, special sauce, lettuce and cheese.','Burgers'],
    ['McSpaghetti',89,'🍝','Filipino-style sweet spaghetti.','Pasta'],
    ['Chicken McDo',119,'🍗','Crispy fried chicken with rice.','Chicken'],
    ['French Fries',75,'🍟','Golden crispy fries.','Sides']
  ],
  'Jollibee': [
    ['Chickenjoy',129,'🍗','Crispy fried chicken with rice and gravy.','Chicken'],
    ['Jolly Spaghetti',99,'🍝','Sweet-style Filipino spaghetti with cheese.','Pasta'],
    ['Yumburger',69,'🍔','Classic Filipino fast-food burger.','Burgers'],
    ['Jolly Hotdog',59,'🌭','Classic hotdog with sweet-style sauce.','Snacks']
  ],
  'Mang Inasal': [
    ['Chicken Inasal',189,'🍚','Grilled chicken with rice and savory marinade.','Inasal Meals'],
    ['Pecho',199,'🍗','Grilled chicken breast meal.','Inasal Meals'],
    ['Pork BBQ',129,'🍢','Filipino-style grilled pork barbecue.','Grilled BBQ'],
    ['Halo-Halo',99,'🍧','Classic Filipino shaved-ice dessert.','Desserts']
  ],
  'Ribshack': [
    ['Baby Back Ribs',349,'🍖','Tender ribs with signature barbecue sauce.','Ribs & Platters'],
    ['Chicken BBQ',219,'🍗','Grilled chicken with barbecue glaze.','Grilled Chicken'],
    ['Pasta',229,'🍝','Creamy pasta favorite.','Pasta'],
    ['Ribs Meal',399,'🍖','Ribs with sides and rice.','Ribs & Platters']
  ],
  'Shakeys': [
    ['Managers Choice Pizza',499,'🍕','Loaded pizza with favorite toppings.','Pizza'],
    ['Pepperoni Crunch',459,'🍕','Pepperoni pizza with a crispy finish.','Pizza'],
    ['Chicken N Mojos',349,'🍗','Crispy chicken with seasoned mojos.','Chicken'],
    ['Bunch of Lunch',399,'🍕','Pizza and chicken meal combo.','Combos']
  ],
  'Chowking': [
    ['Chao Fan',99,'🍚','Filipino-Chinese fried rice favorite.','Rice Bowls'],
    ['Siomai',79,'🥟','Steamed dumplings with savory filling.','Dim Sum'],
    ['Siopao',69,'🥮','Soft steamed bun with savory filling.','Dim Sum'],
    ['Chopsuey',129,'🥬','Mixed vegetables in savory sauce.','Sides']
  ],
  'Greenwich': [
    ['Hawaiian Overload',499,'🍕','Pizza with ham, pineapple and cheese.','Pizza'],
    ['Lasagna Supreme',229,'🍝','Cheesy layered pasta favorite.','Pasta'],
    ['Chicken & Pizza',399,'🍗','Chicken and pizza combo.','Combos'],
    ['Garlic & Cheese Pizza',459,'🍕','Cheesy pizza with garlic flavor.','Pizza']
  ]
};

function money(n){ return '₱'+Number(n).toFixed(2); }

function showScreen(id){
  document.querySelectorAll('.screen').forEach(s=>s.classList.remove('active'));
  document.getElementById(id).classList.add('active');
  document.querySelectorAll('.nav').forEach(n=>n.classList.toggle('active', n.dataset.screen===id));
  if(id==='cart') renderCart();
}

function toast(msg){
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(window._toast);
  window._toast = setTimeout(()=>t.classList.remove('show'), 2200);
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
    icon: decodeURIComponent(icon || '🍴'),
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
    el.innerHTML = '<div class="row-card"><div class="row-icon" style="background:#eee8d7;color:#4f5d21">🛒</div><div class="info"><strong>Your cart is empty</strong><small>Add delicious meals from your favorite stores.</small></div></div>';
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
            <button onclick="updateCartQty(${index}, -1)">−</button>
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

// Helper to write coordinates to all delivery_lat & delivery_lng hidden inputs and status pills
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
    const latIds = ['delivery_lat', 'deliveryLatInput', 'modal_delivery_lat', 'modalDeliveryLat'];
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
                // Update the hidden inputs delivery_lat and delivery_lng
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

    // 3. Client-side Ormoc landmarks & barangay dictionary fallback if network is restricted
    const localDict = {
        'western leyte college': [11.0093834, 124.6092918],
        'wlc': [11.0093834, 124.6092918],
        'alta vista': [11.0118026, 124.6203237],
        'altavista': [11.0118026, 124.6203237],
        'cogon': [11.0233014, 124.6040868],
        'valencia': [11.1071389, 124.5782215],
        'ipil': [10.9754111, 124.6331304],
        'san pedro': [11.0057362, 124.6108008],
        'linao': [11.0159798, 124.5908042],
        'camp downes': [10.9966183, 124.6182589],
        'bantigue': [10.9895862, 124.6222361],
        'tambulilid': [11.0231637, 124.5846183],
        'can-adieng': [11.0025430, 124.6139237],
        'can adieng': [11.0025430, 124.6139237],
        'canadieng': [11.0025430, 124.6139237],
        'punta': [11.0092018, 124.6003072],
        'alegria': [11.0055905, 124.6038316],
        'san pablo': [11.0150000, 124.6050000],
        'don felipe': [11.0120000, 124.6150000],
        'sabang': [11.0030000, 124.6090000],
        'ormoc': [11.0090349, 124.6093940],
    };

    for (const [name, coords] of Object.entries(localDict)) {
        if (normKey.includes(name)) {
            lastGeocodedAddress = normKey;
            if (activeMap) activeMap.setView([coords[0], coords[1]], 17);
            if (activeMarker) activeMarker.setLatLng([coords[0], coords[1]]);
            setDeliveryCoordinates(coords[0], coords[1], 'nominatim');
            return { lat: coords[0], lon: coords[1], lng: coords[1] };
        }
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
        const latEl = document.getElementById('delivery_lat') || document.getElementById('deliveryLatInput') || document.getElementById('modal_delivery_lat');
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

        // When user drags marker, automatically update hidden inputs delivery_lat and delivery_lng with marker.getLatLng()
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
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;

            // Move the map view and draggable marker to their device's live coordinates
            if (checkoutMap) {
                checkoutMap.setView([lat, lng], 16);
            }
            if (checkoutMarker) {
                checkoutMarker.setLatLng([lat, lng]);
            }

            // Sets delivery_lat and delivery_lng
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
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
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

    const latInput = document.getElementById('delivery_lat') || document.getElementById('deliveryLatInput') || document.getElementById('modal_delivery_lat');
    const lngInput = document.getElementById('delivery_lng') || document.getElementById('deliveryLngInput') || document.getElementById('modal_delivery_lng');

    let dLat = (latInput && latInput.value) ? parseFloat(latInput.value) : currentCustomerLat;
    let dLng = (lngInput && lngInput.value) ? parseFloat(lngInput.value) : currentCustomerLng;

    // Only if coordinates are STILL missing, fallback to geocoding
    if ((dLat == null || dLng == null || isNaN(dLat) || isNaN(dLng)) && address) {
        try {
            const geo = await geocodeAddressNominatim(address);
            if (geo && geo.lat && geo.lng) {
                dLat = geo.lat;
                dLng = geo.lng;
            }
        } catch (e) {
            console.warn('Pre-checkout geocoding failed:', e);
        }
    }

    const deliveryFee = 30;
    const payload = {
        action: 'checkout',
        cart: cart,
        delivery_fee: deliveryFee,
        delivery_address: address,
        delivery_lat: (dLat !== null && !isNaN(dLat)) ? dLat : null,
        delivery_lng: (dLng !== null && !isNaN(dLng)) ? dLng : null,
        customer_lat: (dLat !== null && !isNaN(dLat)) ? dLat : null,
        customer_lng: (dLng !== null && !isNaN(dLng)) ? dLng : null
    };
    
    // Pass picked coordinates directly to order_transaction.php without text geocoding guesswork
    fetch('order_transaction.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
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
        toast(err.message || 'Network error during checkout');
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
  
  document.querySelectorAll('.order-row').forEach(row => {
    const cardStatus = (row.dataset.status || '').toLowerCase();
    if (status === 'all') {
      row.style.display = 'flex';
    } else if (status === 'out_for_delivery') {
      row.style.display = ['out_for_delivery', 'on_the_way', 'on_delivery', 'near_customer'].includes(cardStatus) ? 'flex' : 'none';
    } else if (status === 'ready_for_pickup') {
      row.style.display = ['ready_for_pickup', 'ready'].includes(cardStatus) ? 'flex' : 'none';
    } else if (status === 'delivered') {
      row.style.display = ['delivered', 'completed'].includes(cardStatus) ? 'flex' : 'none';
    } else if (status === 'settled') {
      row.style.display = cardStatus === 'settled' ? 'flex' : 'none';
    } else {
      row.style.display = cardStatus === status.toLowerCase() ? 'flex' : 'none';
    }
  });
}

function accountNotice(section) {
  toast(section + ' clicked');
}

function openLogout() {
  document.getElementById('logoutModal').classList.add('show');
}

function closeLogout() {
  document.getElementById('logoutModal').classList.remove('show');
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
  }
});

// URL Query Parameter Routing (e.g. ?track=123 or ?screen=orders)
const customerUrlParams = new URLSearchParams(window.location.search);
if (customerUrlParams.has('track') || customerUrlParams.has('track_order') || customerUrlParams.has('order_id')) {
  const trackId = parseInt(customerUrlParams.get('track') || customerUrlParams.get('track_order') || customerUrlParams.get('order_id'));
  if (trackId) {
    setTimeout(() => openCustomerTrackingModal(trackId), 250);
  }
} else if (customerUrlParams.get('screen') === 'orders') {
  showScreen('orders');
}

// Customer Live GPS Tracking & Rider Chat
let custTrackingMap = null;
let custStoreMarker = null;
let custDestMarker = null;
let custRiderMarker = null;
let custRoutePolyline = null;
let custTrackingTimer = null;
let activeCustomerTrackingOrderId = <?php echo !empty($active_tracking_order['order_id']) ? (int)$active_tracking_order['order_id'] : 'null'; ?>;
let currentTrackingRiderPhone = '+639175550192';
let currentTrackingRiderName = 'Alex Rider';

// Customer Chat State
let activeCustChatOrderId = null;
let custChatPollTimer = null;
let custLastMessageId = 0;

function openCustomerTrackingModal(orderId) {
  const targetId = orderId || activeCustomerTrackingOrderId;
  if (!targetId) {
    showScreen('orders');
    return;
  }
  activeCustomerTrackingOrderId = targetId;

  const modal = document.getElementById('customerTrackingModal');
  if (modal) modal.classList.add('show');

  fetchCustomerLiveTracking(targetId, true);

  if (custTrackingTimer) clearInterval(custTrackingTimer);
  custTrackingTimer = setInterval(() => {
    if (activeCustomerTrackingOrderId) {
      fetchCustomerLiveTracking(activeCustomerTrackingOrderId, false);
    }
  }, 2000); // 2-second real-time polling loop
}

function closeCustomerTrackingModal() {
  const modal = document.getElementById('customerTrackingModal');
  if (modal) modal.classList.remove('show');
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

// Smooth marker interpolation so motorcycle pin glides without abrupt snapping
function animateMarkerTo(marker, newLat, newLng, durationMs = 1500) {
  if (!marker) return;
  const startLatLng = marker.getLatLng();
  const startLat = startLatLng.lat;
  const startLng = startLatLng.lng;

  const deltaLat = newLat - startLat;
  const deltaLng = newLng - startLng;

  if (Math.abs(deltaLat) < 0.0000001 && Math.abs(deltaLng) < 0.0000001) {
    return;
  }
  if (Math.abs(deltaLat) > 0.05 || Math.abs(deltaLng) > 0.05) {
    marker.setLatLng([newLat, newLng]);
    return;
  }

  if (marker._animFrameId) {
    cancelAnimationFrame(marker._animFrameId);
    marker._animFrameId = null;
  }

  const startTime = performance.now();

  function step(now) {
    const elapsed = now - startTime;
    const progress = Math.min(elapsed / durationMs, 1);
    const ease = 1 - Math.pow(1 - progress, 3); // Cubic ease-out

    const curLat = startLat + deltaLat * ease;
    const curLng = startLng + deltaLng * ease;
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
    badgeStr = 'Arrived at your door! • 0 mins';
    distStr = 'Road Distance: Arrived!';
    etaStr = 'Rider is outside now! 🛵';
  } else if (meters < 1000) {
    const mins = Math.max(1, Math.round((meters / 1000) * 4));
    badgeStr = `${meters}m away • ~${mins} mins`;
    distStr = `Road Distance: ${meters} meters`;
    etaStr = `Arriving in ~${mins} mins`;
  } else {
    const kmStr = (meters / 1000).toFixed(2);
    const mins = Math.max(3, Math.round((meters / 1000) * 3.5));
    badgeStr = `${kmStr} km away • ~${mins} mins`;
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
        // OSRM returns [longitude, latitude], convert to Leaflet [latitude, longitude]
        const leafletCoords = data.routes[0].geometry.coordinates.map(pt => [pt[1], pt[0]]);
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
    // Append timestamp and no-cache to prevent browser / proxy caching
    const res = await fetch(`tracking_api.php?action=get_location&order_id=${orderId}&_t=${Date.now()}`, {
      cache: 'no-store'
    });
    const data = await res.json();
    if (!data.success || !data.order) {
      console.warn('Tracking API response:', data.message);
      return;
    }

    const ord = data.order;
    const storeLat = parseFloat(data.store_lat || ord.store_lat);
    const storeLng = parseFloat(data.store_lng || ord.store_lng);
    const destLat = parseFloat(data.destination_lat || ord.destination_lat || ord.customer_lat);
    const destLng = parseFloat(data.destination_lng || ord.destination_lng || ord.customer_lng);

    // Read high-precision coordinates with fallback aliases
    const riderLat = parseFloat(data.latitude ?? data.lat ?? data.rider_lat ?? ord.rider_lat);
    const riderLng = parseFloat(data.longitude ?? data.lng ?? data.rider_lng ?? ord.rider_lng);

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
    if (chatSmsLink) chatSmsLink.href = `sms:${riderDial}?body=Hi%20${encodeURIComponent(currentTrackingRiderName)},%20regarding%20my%20order%20${encodeURIComponent(ord.order_ref || '')}`;

    const refEl = document.getElementById('trackModalOrderRef');
    if (refEl) refEl.textContent = `${ord.order_ref || 'Order #' + orderId} Tracking`;

    const subEl = document.getElementById('trackModalStoreSubtitle');
    if (subEl) subEl.textContent = `${ord.store_name || 'Store'} • ${ord.status || 'On Delivery'}`;

    // Update Distance / ETA text & badge (e.g. "500m away • ~3 mins")
    let dKm = (data.distance_km !== null && !isNaN(data.distance_km)) ? parseFloat(data.distance_km) : null;
    if (dKm === null && !isNaN(destLat) && !isNaN(destLng) && !isNaN(riderLat) && !isNaN(riderLng) && riderLat !== 0) {
      dKm = calculateHaversineKm(riderLat, riderLng, destLat, destLng);
    }
    updateCustomerEtaBadge(dKm);

    // Check for active incoming call from rider
    if (data.active_call && data.active_call.status === 'ringing') {
      handleIncomingCallAlert(data.active_call);
    } else {
      dismissIncomingCallIfRinging();
    }

    // Check for new message from rider to show live banner
    if (data.latest_message && data.latest_message.sender_role === 'rider') {
      handleLiveRiderMessage(data.latest_message);
    }

    renderCustomerMap({
      storeLat, storeLng, storeName: ord.store_name,
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

  // Fallback center: Ormoc City center
  const defaultCenter = [11.0050, 124.6075];
  const center = validRider ? [opts.riderLat, opts.riderLng] : (validDest ? [opts.destLat, opts.destLng] : (validStore ? [opts.storeLat, opts.storeLng] : defaultCenter));

  if (!custTrackingMap) {
    custTrackingMap = L.map('cust-tracking-map', {
      zoomControl: true,
      attributionControl: false
    }).setView(center, 15);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19
    }).addTo(custTrackingMap);
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

  // c. Rider Motorcycle Pin (smooth gliding interpolation)
  if (validRider) {
    if (!custRiderMarker) {
      custRiderMarker = L.marker([opts.riderLat, opts.riderLng], { icon: riderIcon, zIndexOffset: 1000 }).addTo(custTrackingMap);
      custRiderMarker.bindPopup(`<b>${escapeCustHtml(opts.riderName || 'Rider')}</b><br><small>Live Motorcycle GPS</small>`);
    } else {
      // Smoothly animate marker without abrupt snapping
      animateMarkerTo(custRiderMarker, opts.riderLat, opts.riderLng, 1400);
    }
  }

  // d. Dynamic connecting road polyline via OSRM (never straight line)
  const polyStart = validRider ? [opts.riderLat, opts.riderLng] : (validStore ? [opts.storeLat, opts.storeLng] : null);
  const polyEnd = validDest ? [opts.destLat, opts.destLng] : null;

  if (polyStart && polyEnd) {
    // Snap route polyline strictly to OSRM real streets
    fetchCustOsrmRoute(polyStart[0], polyStart[1], polyEnd[0], polyEnd[1]);
  }

  if (opts.isInitial) {
    setTimeout(() => {
      custTrackingMap.invalidateSize();
      const pointsToFit = [];
      if (validStore) pointsToFit.push([opts.storeLat, opts.storeLng]);
      if (validDest) pointsToFit.push([opts.destLat, opts.destLng]);
      if (validRider) pointsToFit.push([opts.riderLat, opts.riderLng]);
      if (pointsToFit.length >= 2) {
        custTrackingMap.fitBounds(pointsToFit, { padding: [40, 40], maxZoom: 16 });
      } else if (pointsToFit.length === 1) {
        custTrackingMap.setView(pointsToFit[0], 15);
      }
    }, 150);
  }
}

// Customer Chat Integration
function openCustomerChat() {
  const orderId = activeCustomerTrackingOrderId || <?php echo !empty($active_tracking_order['order_id']) ? (int)$active_tracking_order['order_id'] : '0'; ?>;
  if (!orderId) {
    showScreen('orders');
    return;
  }
  activeCustChatOrderId = orderId;
  custLastMessageId = 0;

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
  }, 2500);
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

async function fetchCustomerMessages(orderId, scrollToBottom) {
  try {
    const res = await fetch(`order_messages_api.php?order_id=${orderId}&after_id=${custLastMessageId}`);
    const data = await res.json();
    if (data.success && Array.isArray(data.messages)) {
      const container = document.getElementById('custChatMessagesList');
      if (!container) return;

      if (custLastMessageId === 0) {
        container.innerHTML = '';
      }

      if (data.messages.length > 0) {
        const hint = container.querySelector('.chat-empty-hint');
        if (hint) hint.remove();

        data.messages.forEach(msg => {
          if (msg.message_id > custLastMessageId) {
            custLastMessageId = msg.message_id;
            const isMe = msg.sender_role === 'customer';
            const bubble = document.createElement('div');
            bubble.className = `msg-bubble ${isMe ? 'me' : 'them'}`;
            bubble.innerHTML = `
              <div>${escapeCustHtml(msg.message_text)}</div>
              <div class="msg-meta">${escapeCustHtml(msg.sender_name)} • ${escapeCustHtml(msg.time_formatted || '')}</div>
            `;
            container.appendChild(bubble);

            if (!isMe && !scrollToBottom) {
              showToastCustomer(`Rider: ${msg.message_text}`);
            }
          }
        });

        if (scrollToBottom || data.messages.length > 0) {
          container.scrollTop = container.scrollHeight;
        }
      } else if (custLastMessageId === 0) {
        container.innerHTML = '<div class="chat-empty-hint">No messages yet. Send a quick message to your rider!</div>';
      }
    }
  } catch (err) {
    console.warn('Failed to load customer messages:', err);
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
    const res = await fetch('order_messages_api.php', {
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

function showToastCustomer(msg) {
  let toast = document.getElementById('customerLiveToast');
  if (!toast) {
    toast = document.createElement('div');
    toast.id = 'customerLiveToast';
    toast.className = 'cust-toast';
    document.body.appendChild(toast);
  }
  toast.innerHTML = `<i class="fa-solid fa-bell"></i> <span>${escapeCustHtml(msg)}</span>`;
  toast.classList.add('show');
  setTimeout(() => toast.classList.remove('show'), 4000);
}

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
let msgBannerTimeout = null;

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
      await fetch('order_communication_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'respond_call', call_id: currentIncomingCall.call_id, status: 'accepted' })
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
      await fetch('order_communication_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'respond_call', call_id: currentIncomingCall.call_id, status: 'declined' })
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

function handleLiveRiderMessage(msg) {
  if (!msg || !msg.message_id) return;
  if (lastSeenRiderMsgId === 0) {
    lastSeenRiderMsgId = msg.message_id;
    return;
  }

  if (msg.message_id > lastSeenRiderMsgId) {
    lastSeenRiderMsgId = msg.message_id;

    // If chat modal is open, message will be loaded directly
    const chatModal = document.getElementById('customerChatModal');
    if (chatModal && chatModal.classList.contains('show')) {
      return;
    }

    const banner = document.getElementById('liveRiderMessageBanner');
    const textEl = document.getElementById('liveMsgBannerText');
    if (banner && textEl) {
      textEl.textContent = msg.message_text;
      banner.style.display = 'flex';

      playMessageChime();

      if (msgBannerTimeout) clearTimeout(msgBannerTimeout);
      msgBannerTimeout = setTimeout(() => {
        banner.style.display = 'none';
      }, 7000);
    }
  }
}

function openChatFromBanner() {
  const banner = document.getElementById('liveRiderMessageBanner');
  if (banner) banner.style.display = 'none';
  openCustomerChat();
}

function playMessageChime() {
  try {
    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    const ctx = new AudioCtx();
    const now = ctx.currentTime;
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();

    osc.type = 'sine';
    osc.frequency.setValueAtTime(587.33, now);
    osc.frequency.exponentialRampToValueAtTime(880, now + 0.15);

    gain.gain.setValueAtTime(0.1, now);
    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.35);

    osc.connect(gain);
    gain.connect(ctx.destination);

    osc.start(now);
    osc.stop(now + 0.35);
  } catch (e) {}
}

// Background poller for incoming calls & delivery messages across all screens
if (activeCustomerTrackingOrderId) {
  setInterval(async () => {
    // Only poll here if tracking modal is closed (if modal is open, fetchCustomerLiveTracking handles it)
    const trackingModal = document.getElementById('customerTrackingModal');
    if (trackingModal && trackingModal.classList.contains('show')) return;

    try {
      const res = await fetch(`order_communication_api.php?action=get_updates&order_id=${activeCustomerTrackingOrderId}&after_message_id=${lastSeenRiderMsgId}&_t=${Date.now()}`, {
        cache: 'no-store'
      });
      const data = await res.json();
      if (!data.success) return;

      if (data.active_call && data.active_call.status === 'ringing') {
        handleIncomingCallAlert(data.active_call);
      } else {
        dismissIncomingCallIfRinging();
      }

      if (data.latest_banner && data.latest_banner.sender_role === 'rider') {
        handleLiveRiderMessage(data.latest_banner);
      }
    } catch (e) {}
  }, 2500);
}

// Check if navigating to a specific screen via hash (e.g. #orders, #cart)
if (window.location.hash) {
  const targetScreen = window.location.hash.replace('#', '');
  if (document.getElementById(targetScreen)) {
    showScreen(targetScreen);
  }
}
</script>
</body>
</html>