<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: text/html; charset=utf-8');
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
        header('Location: login.php');
        exit;
    }
}

$user_id = (int)$_SESSION['user_id'];
try {
    $u_stmt = $conn->prepare("SELECT user_id, username, full_name FROM users WHERE user_id = :uid LIMIT 1");
    $u_stmt->execute([':uid' => $user_id]);
    $u_row = $u_stmt->fetch(PDO::FETCH_ASSOC);
    $username = $u_row['username'] ?? ($_SESSION['username'] ?? 'Customer');
    $full_name = $u_row['full_name'] ?: ($username ?: 'Customer');
} catch (Throwable $e) {
    $username = $_SESSION['username'] ?? 'Customer';
    $full_name = $_SESSION['full_name'] ?? $username;
}

// Detect Mobile WebView (React Native Expo app, ?mobile query param, custom User-Agent, or session flag)
$is_mobile_webview = (
    isset($_GET['mobile']) ||
    !empty($_SESSION['is_mobile_app']) ||
    (!empty($_SERVER['HTTP_USER_AGENT']) && (
        stripos($_SERVER['HTTP_USER_AGENT'], 'FoodDashMobile') !== false ||
        stripos($_SERVER['HTTP_USER_AGENT'], 'ReactNativeWebView') !== false ||
        stripos($_SERVER['HTTP_USER_AGENT'], 'Expo') !== false
    )) ||
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && stripos($_SERVER['HTTP_X_REQUESTED_WITH'], 'fooddash') !== false) ||
    !empty($_SERVER['HTTP_X_FOOD_DASH_MOBILE'])
);

if (isset($_GET['mobile']) || $is_mobile_webview) {
    $_SESSION['is_mobile_app'] = true;
}


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
        } elseif ($shop_name !== '') {
            $stmt = $conn->prepare("SELECT DISTINCT m.category FROM menu_items m JOIN shops s ON m.shop_id = s.shop_id WHERE (LOWER(s.shop_name) = LOWER(?) OR s.shop_name LIKE CONCAT('%', ?, '%')) AND m.is_available = TRUE AND m.category IS NOT NULL AND TRIM(m.category) != '' ORDER BY m.category ASC");
            $stmt->execute([$shop_name, $shop_name]);
        } else {
            $stmt = $conn->prepare("SELECT DISTINCT category FROM menu_items WHERE is_available = TRUE AND category IS NOT NULL AND TRIM(category) != '' ORDER BY category ASC");
            $stmt->execute();
        }
        
        if ($stmt) {
            while ($c_row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $categories[] = $c_row['category'];
            }
        }
        
        api_response(['success' => true, 'status' => 'success', 'store' => $shop_name, 'categories' => $categories]);
    }

    // Dynamically fetch dishes for the active store from the database
    if ($action === 'get_store_menu') {
        $shop_name = trim((string)($input['shop_name'] ?? ''));
        $shop_id = (int)($input['shop_id'] ?? 0);
        $items = [];
        
        if ($shop_id > 0) {
            $stmt = $conn->prepare("
                SELECT m.item_id, m.shop_id, s.shop_name, m.item_name, m.category, m.price, m.description, m.icon, m.image_url, m.is_recommended 
                FROM menu_items m 
                JOIN shops s ON m.shop_id = s.shop_id 
                WHERE m.shop_id = ? AND m.is_available = TRUE 
                ORDER BY m.is_recommended DESC, m.item_name ASC
            ");
            $stmt->execute([$shop_id]);
        } elseif ($shop_name !== '') {
            $stmt = $conn->prepare("
                SELECT m.item_id, m.shop_id, s.shop_name, m.item_name, m.category, m.price, m.description, m.icon, m.image_url, m.is_recommended 
                FROM menu_items m 
                JOIN shops s ON m.shop_id = s.shop_id 
                WHERE (LOWER(s.shop_name) = LOWER(?) OR s.shop_name LIKE CONCAT('%', ?, '%')) AND m.is_available = TRUE 
                ORDER BY m.is_recommended DESC, m.item_name ASC
            ");
            $stmt->execute([$shop_name, $shop_name]);
        } else {
            $stmt = $conn->prepare("
                SELECT m.item_id, m.shop_id, s.shop_name, m.item_name, m.category, m.price, m.description, m.icon, m.image_url, m.is_recommended 
                FROM menu_items m 
                JOIN shops s ON m.shop_id = s.shop_id 
                WHERE m.is_available = TRUE 
                ORDER BY m.is_recommended DESC, m.item_name ASC
            ");
            $stmt->execute();
        }
        
        if ($stmt) {
            while ($m_row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $items[] = [
                    'id'          => (int)$m_row['item_id'],
                    'item_id'     => (int)$m_row['item_id'],
                    'shop_id'     => (int)$m_row['shop_id'],
                    'store'       => $m_row['shop_name'],
                    'shop_name'   => $m_row['shop_name'],
                    'name'        => $m_row['item_name'],
                    'price'       => (float)$m_row['price'],
                    'icon'        => !empty($m_row['icon']) ? $m_row['icon'] : '🍽️',
                    'desc'        => !empty($m_row['description']) ? $m_row['description'] : 'Fresh and delicious meal prepared to order.',
                    'description' => !empty($m_row['description']) ? $m_row['description'] : 'Fresh and delicious meal prepared to order.',
                    'category'    => !empty($m_row['category']) ? $m_row['category'] : 'Main',
                    'image_url'   => $m_row['image_url'] ?? null,
                    'recommended' => (!empty($m_row['is_recommended']) && $m_row['is_recommended'] !== 'f' && $m_row['is_recommended'] !== false) ? 1 : 0
                ];
            }
        }
        api_response(['success' => true, 'status' => 'success', 'items' => $items, 'data' => $items]);
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
                    ':txt'   => 'Customer: 📦 Order receipt confirmed. Thank you!'
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

        $input_del_lat = isset($input['delivery_lat']) && is_numeric($input['delivery_lat']) ? (float)$input['delivery_lat'] : (isset($input['customer_lat']) && is_numeric($input['customer_lat']) ? (float)$input['customer_lat'] : null);
        $input_del_lng = isset($input['delivery_lng']) && is_numeric($input['delivery_lng']) ? (float)$input['delivery_lng'] : (isset($input['customer_lng']) && is_numeric($input['customer_lng']) ? (float)$input['customer_lng'] : null);
        if (file_exists(__DIR__ . '/geocode_lib.php')) {
            require_once __DIR__ . '/geocode_lib.php';
            $dest_res = resolve_ormoc_dest_coords($delivery_address, $input_del_lat, $input_del_lng);
            $input_del_lat = $dest_res['lat'];
            $input_del_lng = $dest_res['lng'];
        }
        
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
                    $icon = trim((string)($item['icon'] ?? '🍽️'));
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
        status, delivery_status, order_type, delivery_address, delivery_lat, delivery_lng, customer_lat, customer_lng, created_at
    ) VALUES (
        :shop_id, :store_name, :customer_id, :customer_name, :order_ref, 
        :items_summary, :subtotal, :delivery_fee, :total_amount, :payment_method, 
        'PENDING', 'waiting', 'Delivery', :delivery_address, :dlat, :dlng, :clat, :clng, NOW()
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
    ':delivery_address' => $delivery_address,
    ':dlat'             => $input_del_lat,
    ':dlng'             => $input_del_lng,
    ':clat'             => $input_del_lat,
    ':clng'             => $input_del_lng
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

                // 1. Sync into orders compatibility table FIRST so foreign keys in order_items are satisfied!
                $is_pgsql = ($conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql');
                if ($is_pgsql) {
                    $ins_ord = $conn->prepare("INSERT INTO orders (id, order_id, order_number, user_id, status, total_amount, payment_method, delivery_address, created_at) " .
                        "VALUES (:id, :oid, :onum, :uid, 'pending', :tot, :pm, :addr, NOW()) " .
                        "ON CONFLICT (id) DO UPDATE SET total_amount = EXCLUDED.total_amount, order_number = EXCLUDED.order_number, payment_method = EXCLUDED.payment_method");
                    $ins_ord->execute([
                        ':id'   => $new_order_id,
                        ':oid'  => $new_order_id,
                        ':onum' => $unique_ref,
                        ':uid'  => $user_id,
                        ':tot'  => $total_amount,
                        ':pm'   => $payment_method,
                        ':addr' => $delivery_address
                    ]);
                } else {
                    $ins_ord = $conn->prepare("INSERT INTO orders (order_id, user_id, status, total_price, shop_id, payment_method, order_number, delivery_address, delivery_lat, delivery_lng, store_name, items_summary, created_at) " .
                        "VALUES (:oid, :uid, 'Pending', :tot, :sid, :pm, :onum, :addr, :dlat, :dlng, :store, :summary, NOW()) " .
                        "ON DUPLICATE KEY UPDATE status='Pending', total_price=VALUES(total_price), delivery_address=VALUES(delivery_address), delivery_lat=VALUES(delivery_lat), delivery_lng=VALUES(delivery_lng)");
                    $ins_ord->execute([
                        ':oid'     => $new_order_id,
                        ':uid'     => $user_id,
                        ':tot'     => $total_amount,
                        ':sid'     => $shop_id,
                        ':pm'      => $payment_method,
                        ':onum'    => $unique_ref,
                        ':addr'    => $delivery_address,
                        ':dlat'    => $input_del_lat,
                        ':dlng'    => $input_del_lng,
                        ':store'   => $store_name,
                        ':summary' => $items_summary
                    ]);
                }

                // 2. NOW insert into order_items compatibility table with explicit unit 'price' and valid order_id reference
                try {
                    $co_stmt = $conn->prepare("INSERT INTO order_items (order_id, item_name, price, unit_price, quantity, line_total, store_name) VALUES (:oid, :name, :price, :uprice, :qty, :line, :store)");
                    foreach ($clean_items as $ci) {
                        $unit_price = isset($ci['price']) && is_numeric($ci['price']) ? (float)$ci['price'] : 0.00;
                        $line_total = isset($ci['line']) && is_numeric($ci['line']) ? (float)$ci['line'] : round($unit_price * (int)$ci['qty'], 2);
                        $co_stmt->execute([
                            ':oid'    => $new_order_id,
                            ':name'   => $ci['name'],
                            ':price'  => $unit_price,
                            ':uprice' => $unit_price,
                            ':qty'    => $ci['qty'],
                            ':line'   => $line_total,
                            ':store'  => $ci['store']
                        ]);
                    }
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
            foreach ($created_orders as $co) {
                try {
                    require_once __DIR__ . '/supabase_lib.php';
                    sync_order_to_supabase((int)$co['order_id'], $conn);
                } catch (Throwable $e) {}
            }
            api_response(['success' => true, 'message' => 'Orders placed successfully!', 'orders' => $created_orders]);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            api_response(['success' => false, 'message' => 'Checkout failed: ' . $e->getMessage()], 200);
        }
    }
    
    // Fall-through: Unknown action
    api_response(['success' => false, 'message' => 'Unknown action: ' . $action], 400);
}

// Fetch customer's orders dynamically from orders and food_orders tables
$db_orders = [];
$ontheway_count = 0;
$active_tracking_order = null;

try {
    $driver = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'pgsql') {
        $stmt = $conn->prepare("
            SELECT 
                o.id AS order_id,
                o.id,
                o.order_number AS order_ref,
                COALESCE(s.shop_name, 'Partner Store') AS store_name,
                COALESCE(
                    (SELECT string_agg(CONCAT(oi.quantity, 'x ', oi.item_name), ', ') FROM order_items oi WHERE oi.order_id = o.id),
                    'Food Dash Items'
                ) AS items_summary,
                COALESCE(o.total_amount, 0.00) AS total_amount,
                COALESCE(o.payment_method, 'Cash on Delivery (COD)') AS payment_method,
                COALESCE(o.status, 'pending') AS status,
                COALESCE(o.status, 'pending') AS delivery_status,
                'delivery' AS order_type,
                COALESCE(o.delivery_address, '') AS delivery_address,
                o.delivery_lat,
                o.delivery_lng,
                o.rider_name,
                o.rider_phone,
                o.current_lat,
                o.current_lng,
                o.created_at,
                COALESCE(o.rider_cleared, 0) AS rider_cleared
            FROM orders o
            LEFT JOIN shops s ON o.store_id = s.shop_id
            WHERE (o.user_id = :uid OR o.customer_id::text = :uid_str)
              AND UPPER(COALESCE(o.status, '')) != 'CANCELLED'
              AND COALESCE(o.rider_cleared, 0) = 0
            ORDER BY o.id DESC LIMIT 50
        ");
        $stmt->execute([':uid' => $user_id, ':uid_str' => (string)$user_id]);
        $db_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $conn->prepare("
            SELECT 
                COALESCE(fo.order_id, o.order_id, o.id) AS order_id,
                COALESCE(fo.order_id, o.order_id, o.id) AS id,
                COALESCE(fo.order_ref, o.order_number, CONCAT('#FD-', COALESCE(fo.order_id, o.order_id, o.id))) AS order_ref,
                COALESCE(fo.store_name, s.shop_name, o.store_name, 'Partner Store') AS store_name,
                COALESCE(fo.items_summary, o.items_summary, 'Food Dash Items') AS items_summary,
                COALESCE(fo.total_amount, o.total_price, 0.00) AS total_amount,
                COALESCE(fo.payment_method, o.payment_method, 'Cash on Delivery (COD)') AS payment_method,
                COALESCE(fo.status, o.status, 'Pending') AS status,
                COALESCE(fo.delivery_status, o.status, 'waiting') AS delivery_status,
                'delivery' AS order_type,
                COALESCE(fo.delivery_address, o.delivery_address, '') AS delivery_address,
                COALESCE(fo.delivery_lat, o.delivery_lat) AS delivery_lat,
                COALESCE(fo.delivery_lng, o.delivery_lng) AS delivery_lng,
                COALESCE(r.rider_name, ru.full_name, o.rider_name, '') AS rider_name,
                COALESCE(r.phone, ru.phone, fo.rider_phone, o.rider_phone, '') AS rider_phone,
                COALESCE(fo.rider_lat, o.current_lat) AS current_lat,
                COALESCE(fo.rider_lng, o.current_lng) AS current_lng,
                COALESCE(fo.created_at, o.created_at) AS created_at,
                COALESCE(fo.rider_cleared, o.rider_cleared, 0) AS rider_cleared
            FROM food_orders fo
            LEFT JOIN orders o ON (fo.order_id = o.order_id OR fo.order_id = o.id)
            LEFT JOIN shops s ON (fo.shop_id = s.shop_id OR (fo.shop_id = 0 AND LOWER(TRIM(fo.store_name)) = LOWER(TRIM(s.shop_name))))
            LEFT JOIN riders r ON (fo.rider_id = r.rider_id OR fo.rider_id = r.user_id)
            LEFT JOIN users ru ON (r.user_id = ru.user_id)
            WHERE (fo.customer_id = :uid OR fo.user_id = :uid2)
              AND COALESCE(fo.rider_cleared, 0) = 0
            ORDER BY fo.order_id DESC LIMIT 50
        ");
        $stmt->execute([':uid' => $user_id, ':uid2' => $user_id]);
        $db_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fallback to legacy orders table if food_orders had zero results
        if (empty($db_orders)) {
            $stmt_leg = $conn->prepare("
                SELECT 
                    COALESCE(o.id, o.order_id) AS order_id,
                    COALESCE(o.id, o.order_id) AS id,
                    COALESCE(o.order_number, CONCAT('#FD-', COALESCE(o.id, o.order_id))) AS order_ref,
                    COALESCE(s.shop_name, o.store_name, 'Partner Store') AS store_name,
                    COALESCE(o.items_summary, 'Food Dash Items') AS items_summary,
                    COALESCE(o.total_price, 0.00) AS total_amount,
                    COALESCE(o.payment_method, 'Cash on Delivery (COD)') AS payment_method,
                    COALESCE(o.status, 'Pending') AS status,
                    COALESCE(o.status, 'Pending') AS delivery_status,
                    'delivery' AS order_type,
                    COALESCE(o.delivery_address, '') AS delivery_address,
                    o.delivery_lat,
                    o.delivery_lng,
                    o.rider_name,
                    o.rider_phone,
                    o.current_lat,
                    o.current_lng,
                    o.created_at,
                    COALESCE(o.rider_cleared, 0) AS rider_cleared
                FROM orders o
                LEFT JOIN shops s ON (o.store_id = s.shop_id OR o.shop_id = s.shop_id)
                WHERE (o.user_id = :uid OR o.customer_id = :uid2)
                  AND COALESCE(o.rider_cleared, 0) = 0
                ORDER BY o.order_id DESC LIMIT 50
            ");
            $stmt_leg->execute([':uid' => $user_id, ':uid2' => $user_id]);
            $db_orders = $stmt_leg->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Throwable $e) {
    $db_orders = [];
}

// Normalize order_ref for display (e.g., #FD-261024-8117)
foreach ($db_orders as &$ord_ref_item) {
    $ord_ref_item['order_ref'] = '#' . ltrim($ord_ref_item['order_ref'] ?? ('FD-' . ($ord_ref_item['order_id'] ?? $ord_ref_item['id'])), '#');
}
unset($ord_ref_item);

// Find active tracking order (prioritizing active deliveries over historical orders)
foreach ($db_orders as $row) {
    $st = strtoupper(trim(str_replace([' ', '-'], '_', $row['status'] ?? '')));
    $del_st = strtoupper(trim(str_replace([' ', '-'], '_', $row['delivery_status'] ?? '')));
    if (in_array($st, ['OUT_FOR_DELIVERY', 'ON_THE_WAY', 'ON_DELIVERY', 'NEAR_CUSTOMER', 'ARRIVED', 'ARRIVED_DESTINATION']) ||
        in_array($del_st, ['OUT_FOR_DELIVERY', 'ON_THE_WAY', 'ON_DELIVERY', 'NEAR_CUSTOMER', 'ARRIVED', 'ARRIVED_DESTINATION'])) {
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

// Dynamically fetch recommended dishes from database (falling back to top available items)
$popular_dishes = [];
try {
    $p_stmt = $conn->query("
        SELECT m.item_id, m.shop_id, s.shop_name, m.item_name, m.price, m.icon, m.description, m.category 
        FROM menu_items m 
        INNER JOIN shops s ON m.shop_id = s.shop_id 
        WHERE m.is_available = TRUE 
        ORDER BY m.is_recommended DESC, m.item_id ASC 
        LIMIT 8
    ");
    if ($p_stmt) {
        $popular_dishes = $p_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $popular_dishes = [];
}

// Dynamically fetch unique categories and dishes for each store from the database
$db_store_menus = [];
$db_store_categories = [];
$unique_shop_categories = [];
if (!empty($db_shops)) {
    foreach ($db_shops as $sh) {
        $sc = trim($sh['category'] ?? '');
        if ($sc !== '' && !in_array($sc, $unique_shop_categories, true)) {
            $unique_shop_categories[] = $sc;
        }
    }
}

try {
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
            $sid = (string)$mi['shop_id'];
            $snorm = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $sname));
            $cat = trim($mi['category'] ?? 'Main');
            if ($cat === '') $cat = 'Main';

            $dish = [
                'id'          => (int)$mi['item_id'],
                'item_id'     => (int)$mi['item_id'],
                'shop_id'     => (int)$mi['shop_id'],
                'store'       => $sname,
                'name'        => $mi['item_name'],
                'price'       => (float)$mi['price'],
                'icon'        => !empty($mi['icon']) ? $mi['icon'] : '🍽️',
                'desc'        => !empty($mi['description']) ? $mi['description'] : 'Fresh and delicious meal prepared to order.',
                'description' => !empty($mi['description']) ? $mi['description'] : 'Fresh and delicious meal prepared to order.',
                'category'    => $cat,
                'recommended' => (!empty($mi['is_recommended']) && $mi['is_recommended'] !== 'f' && $mi['is_recommended'] !== false) ? 1 : 0
            ];

            foreach (array_unique([$sname, $sid, $snorm]) as $lookupKey) {
                if (!isset($db_store_menus[$lookupKey])) {
                    $db_store_menus[$lookupKey] = [];
                    $db_store_categories[$lookupKey] = [];
                }
                if (!in_array($cat, $db_store_categories[$lookupKey], true)) {
                    $db_store_categories[$lookupKey][] = $cat;
                }
                $db_store_menus[$lookupKey][] = $dish;
            }
        }
    }
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<title>Food Dash — Customer</title>
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

html {
  width: 100%;
  height: 100%;
  margin: 0;
  padding: 0;
  overflow-x: hidden;
  -webkit-text-size-adjust: 100%;
}

body {
  width: 100%;
  min-height: 100%;
  margin: 0;
  padding: 0;
  box-sizing: border-box;
  overflow-x: hidden;
  background:
    radial-gradient(circle at 15% 10%, rgba(216,143,42,.12), transparent 30%),
    radial-gradient(circle at 85% 90%, rgba(75,83,32,.13), transparent 35%),
    #f4f0df;
  color: #20241a;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-start;
  font-family: 'Poppins', Arial, sans-serif;
  touch-action: manipulation;
  pointer-events: auto !important;
  position: relative !important;
  z-index: 1 !important;
}

button, input { font: inherit; }
button { cursor: pointer; touch-action: manipulation; }

/* Ensure all interactive buttons, store cards, chips, and touch targets are responsive and clickable */
button, a, .card, .add-to-cart-btn, .store, .food-card, .add, .btn-qty-minus, .btn-qty-plus, .nav, .chip, .row-card, .hero button, .icon-btn, [onclick], [data-action], [data-screen], [data-category],
.banner-store *, .bottom-nav, nav,
#fdGmailHeaderBtn, .fd-gmail-header-btn,
.cancel-chip, .btn-fd-primary, .btn-fd-secondary, .btn-arrival-action, .logout-cancel, .logout-confirm {
  pointer-events: auto !important;
  cursor: pointer !important;
  touch-action: manipulation !important;
  user-select: none;
  -webkit-user-select: none;
  -webkit-touch-callout: none;
  -webkit-tap-highlight-color: transparent !important;
}

/* Active state tactile styles for instant touch feedback */
button:active,
a:active,
.store:active,
.food-card:active,
.row-card:active,
.chip:active,
.cancel-chip:active,
.add:active,
.btn-qty-minus:active,
.btn-qty-plus:active,
.nav:active,
.icon-btn:active,
.hero button:active,
.is-touch-active {
  transform: scale(0.96) !important;
  opacity: 0.88 !important;
  transition: transform 0.08s ease, opacity 0.08s ease !important;
}

/* Ensure child icons, badges, SVG paths, and decorative text never swallow parent card clicks */
.icon-btn svg, .icon-btn path, .icon-btn i,
#fdGmailHeaderBtn svg, #fdGmailHeaderBtn path,
.nav i, .nav span, .nav b,
.store-logo, .store-logo *, .store strong, .store small,
.row-icon, .row-icon *, .row-details strong, .row-details small, .row-card i,
.food-card .food-img, .food-body strong, .food-body small, .price-row .price,
.search i, .search input[readonly],
.chip *, .hero button * {
  pointer-events: none !important;
}

/* Re-enable explicit pointer events on child action buttons */
.add, .btn-qty-minus, .btn-qty-plus, .food-card .add {
  pointer-events: auto !important;
}

/* Guarantee inactive modals, backdrops, and hidden floating elements never block clicks */
.sim-modal:not(.show):not(.active):not([style*="display: flex"]):not([style*="display:flex"]),
.sim-modal[style*="display:none"],
.sim-modal[style*="display: none"],
.fd-modal:not(.show):not(.active):not([style*="display: flex"]):not([style*="display:flex"]),
.fd-modal[style*="display:none"],
.fd-modal[style*="display: none"],
.logout-modal:not(.show):not(.active):not([style*="display: flex"]):not([style*="display:flex"]),
.logout-modal[style*="display:none"],
.logout-modal[style*="display: none"],
.incoming-call-modal:not(.show):not(.active):not([style*="display: flex"]):not([style*="display:flex"]),
.incoming-call-modal[style*="display:none"],
.incoming-call-modal[style*="display: none"],
.customer-floating-banner:not(.show):not(.slide-down),
#liveRiderMessageBanner:not(.show):not(.slide-down),
.live-rider-msg-banner:not(.show):not(.slide-down),
.customer-floating-banner[style*="display:none"],
.customer-floating-banner[style*="display: none"],
.fd-gmail-modal-backdrop:not(.active),
#fdGmailModal:not(.active),
.toast:not(.show),
.cust-toast:not(.show),
.fd-modal-backdrop:not(.active) {
  display: none !important;
  visibility: hidden !important;
  pointer-events: none !important;
  opacity: 0 !important;
  z-index: -9999 !important;
}

/* Explicit active modal rules */
.sim-modal.show,
.sim-modal.active,
.sim-modal[style*="display: flex"],
.sim-modal[style*="display:flex"],
.fd-modal.show,
.fd-modal.active,
.fd-modal[style*="display: flex"],
.fd-modal[style*="display:flex"],
.logout-modal.show,
.logout-modal.active,
.logout-modal[style*="display: flex"],
.logout-modal[style*="display:flex"],
.incoming-call-modal.show,
.incoming-call-modal.active,
.incoming-call-modal[style*="display: flex"],
.incoming-call-modal[style*="display:flex"],
.fd-gmail-modal-backdrop.active,
#fdGmailModal.active {
  display: flex !important;
  visibility: visible !important;
  pointer-events: auto !important;
  opacity: 1 !important;
  z-index: 10000 !important;
}

.app {
  width: 100% !important;
  max-width: 440px;
  min-height: 100vh;
  box-sizing: border-box !important;
  overflow-x: hidden !important;
  background: var(--paper);
  position: relative !important;
  z-index: 1 !important;
  pointer-events: auto !important;
  box-shadow: var(--shadow);
  display: flex;
  flex-direction: column;
  flex-shrink: 0;
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
  width: 100% !important;
  box-sizing: border-box !important;
  padding-top: 14px !important;
  padding-bottom: 90px !important;
  touch-action: pan-y;
  -webkit-overflow-scrolling: touch;
}

@media(min-width:600px){
  body {
    padding: 20px 0;
  }
  .app {
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
    width: 100% !important;
    max-width: 100% !important;
    min-height: 100%;
    margin: 0;
    padding: 0;
    display: block !important;
  }
  .app {
    border: none;
    border-radius: 0;
    box-shadow: none;
    min-height: 100vh;
    width: 100% !important;
    max-width: 100% !important;
    margin: 0 !important;
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

/* Notification Banner - Permanently Removed */
.notice-bar, #dynamicOnTheWayBanner {
  display: none !important;
  opacity: 0 !important;
  visibility: hidden !important;
  height: 0 !important;
  margin: 0 !important;
  padding: 0 !important;
  pointer-events: none !important;
}

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
  border-radius: 99px;
  min-width: 17px;
  height: 17px;
  padding: 0 4px;
  font-size: 10px;
  font-weight: 800;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  line-height: 1;
  box-shadow: 0 2px 5px rgba(239, 68, 68, 0.5);
  border: 1.5px solid #ffffff;
  margin-left: 5px;
  vertical-align: middle;
}

/* In-App Notifications Modal (Food Dash Palette) */
.bell-badge {
  position: absolute;
  top: -3px;
  right: -3px;
  background: #ea4335;
  color: #ffffff;
  border-radius: 99px;
  font-size: 8px;
  font-weight: 700;
  padding: 1px 4px;
  min-width: 15px;
  height: 15px;
  line-height: 13px;
  text-align: center;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 2px 5px rgba(234, 67, 53, 0.45);
  border: 1.5px solid #ffffff;
  transition: transform 0.2s ease, opacity 0.2s ease;
}
.bell-badge.has-unread {
  display: inline-flex !important;
  animation: bellPulse 1.8s infinite;
}
@keyframes bellPulse {
  0% { transform: scale(1); }
  50% { transform: scale(1.15); }
  100% { transform: scale(1); }
}
.fd-modal {
  display: none;
  visibility: hidden;
  pointer-events: none;
  position: fixed;
  inset: 0;
  background: rgba(75, 83, 32, 0.40);
  backdrop-filter: blur(4px);
  align-items: center;
  justify-content: center;
  padding: 16px;
  z-index: 2000;
}
.fd-modal.show, .fd-modal.active, .fd-modal[style*="display: flex"], .fd-modal[style*="display:flex"] {
  display: flex !important;
  visibility: visible !important;
  pointer-events: auto !important;
}
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
.gps-status-pill.info {
  background: rgba(75, 83, 32, 0.12);
  color: var(--olive);
  border: 1px solid rgba(75, 83, 32, 0.25);
}
.gps-status-pill.error {
  background: rgba(146, 61, 50, 0.1);
  color: var(--earth-clay);
  border: 1px solid rgba(146, 61, 50, 0.2);
}

.map-suggestion-item {
  padding: 8px 12px;
  cursor: pointer;
  display: flex;
  align-items: flex-start;
  gap: 8px;
  border-bottom: 1px solid #f3f4f6;
  transition: background 0.15s ease;
}
.map-suggestion-item:last-child {
  border-bottom: none;
}
.map-suggestion-item:hover {
  background: #fdf8ee;
}
.map-suggestion-item strong {
  font-size: 11px;
  color: #1f2937;
  display: block;
}
.map-suggestion-item small {
  font-size: 9.5px;
  color: #6b7280;
  display: block;
  line-height: 1.3;
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
  pointer-events: auto !important;
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

.top-icons {
  display: flex !important;
  align-items: center !important;
  gap: 8px !important;
  position: relative !important;
}

.nav{border:0;background:transparent;color:#72776a;font-size:8px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;position:relative}.nav i{font-size:15px}.nav.active{color:var(--olive-dark);font-weight:700}.badge{position:absolute;background:#e7352d;color:white;font-size:7px;border-radius:10px;padding:2px 5px;top:4px;right:20px}

/* Obsolete bottom toasts hidden in favor of standardized top banner */
.toast, .cust-toast { position: fixed !important; display: none !important; opacity: 0 !important; visibility: hidden !important; pointer-events: none !important; z-index: -9999 !important; }

.logout{width:100%;background:#fff;cursor:pointer;font-family:inherit;margin-top:12px;border:1px solid #e55b52;color:#d33b32;border-radius:11px;padding:10px;font-size:9px;font-weight:600}
.logout-modal{display:none;visibility:hidden;pointer-events:none;position:fixed;inset:0;background:rgba(28,32,6,.38);backdrop-filter:blur(3px);align-items:center;justify-content:center;padding:18px;z-index:1000}.logout-modal.show,.logout-modal.active,.logout-modal[style*="display: flex"],.logout-modal[style*="display:flex"]{display:flex !important;visibility:visible !important;pointer-events:auto !important;}.logout-box{width:min(360px,100%);background:#fffdf7;border:1px solid var(--line);border-radius:22px;padding:22px;text-align:center;box-shadow:0 20px 60px rgba(55,65,22,.22)}.logout-box .logout-logo{width:58px;height:58px;border-radius:50%;object-fit:cover;border:1px solid #d7c98d;margin-bottom:9px}.logout-box h3{font-size:16px;color:var(--olive-dark);margin:0 0 5px}.logout-box p{font-size:9px;color:var(--muted);line-height:1.5;margin-bottom:17px}.logout-actions{display:flex;gap:8px}.logout-actions button,.logout-actions a{flex:1;padding:10px;border-radius:11px;font-size:9px;font-weight:700;text-decoration:none;border:0;font-family:inherit}.logout-cancel{background:#eee9d7;color:var(--olive)}.logout-confirm{background:var(--olive);color:white;display:grid;place-items:center}

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
  display: none;
  pointer-events: none;
  align-items: center;
  justify-content: center;
  padding: 16px;
  animation: fadeIn 0.25s ease-out;
}
.incoming-call-modal.show,
.incoming-call-modal.active,
.incoming-call-modal[style*="display: flex"],
.incoming-call-modal[style*="display:flex"] {
  display: flex !important;
  visibility: visible !important;
  pointer-events: auto !important;
  opacity: 1 !important;
  z-index: 999999 !important;
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
  display: none !important;
  pointer-events: none !important;
  align-items: center !important;
  gap: 12px !important;
  cursor: pointer !important;
  box-sizing: border-box !important;
  animation: bannerSlideDown 0.32s cubic-bezier(0.16, 1, 0.3, 1);
  transition: transform 0.25s ease, opacity 0.25s ease;
}
.customer-floating-banner.show,
#liveRiderMessageBanner.show,
.live-rider-msg-banner.show,
.customer-floating-banner.slide-down,
#liveRiderMessageBanner.slide-down,
.live-rider-msg-banner.slide-down,
.slide-down {
  display: flex !important;
  visibility: visible !important;
  opacity: 1 !important;
  pointer-events: auto !important;
  animation: bannerSlideDown 0.35s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards !important;
}
.customer-floating-banner:not(.show):not(.slide-down),
#liveRiderMessageBanner:not(.show):not(.slide-down),
.live-rider-msg-banner:not(.show):not(.slide-down) {
  display: none !important;
  pointer-events: none !important;
}
@keyframes bannerSlideDown {
  0% { transform: translateY(-30px); opacity: 0; }
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

.sim-modal {
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(0,0,0,0.65);
  backdrop-filter: blur(8px);
  z-index: 999999;
  display: none;
  pointer-events: none;
  align-items: flex-end;
  justify-content: center;
  box-sizing: border-box;
}
.sim-modal.show,
.sim-modal.active,
.sim-modal[style*="display: flex"],
.sim-modal[style*="display:flex"] {
  display: flex !important;
  pointer-events: auto !important;
}
.sim-sheet {
  width: 100%;
  max-width: 480px;
  max-height: 92vh;
  border-radius: 26px 26px 0 0;
  box-shadow: 0 -10px 40px rgba(0,0,0,0.35);
  animation: slideUpSheet 0.3s cubic-bezier(0.16, 1, 0.3, 1);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  box-sizing: border-box;
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
.btn-submit-order, .checkout-btn {
  width: 100% !important;
  min-height: 52px !important;
  height: 52px !important;
  border: none !important;
  border-radius: 14px !important;
  font-size: 15px !important;
  font-weight: 700 !important;
  cursor: pointer !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  gap: 8px !important;
  transition: all 0.2s ease !important;
  box-shadow: 0 4px 14px rgba(0,0,0,0.18) !important;
  margin-top: 14px !important;
  margin-bottom: 6px !important;
  flex-shrink: 0 !important;
  box-sizing: border-box !important;
}
.btn-submit-order:active, .checkout-btn:active {
  transform: scale(0.98);
}
@keyframes slideUpSheet {
  from { transform: translateY(100%); }
  to { transform: translateY(0); }
}
.sheet-grabber {
  width: 40px; height: 5px;
  background: #d1d5db;
  border-radius: 10px;
  margin: 10px auto 4px auto;
}
.face-id-wrapper {
  display: flex;
  flex-direction: column;
  align-items: center;
  margin: 18px 0;
}
.face-id-box {
  width: 90px; height: 90px;
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 22px;
  background: #2c2c2e;
}
.face-id-corner {
  position: absolute;
  width: 16px; height: 16px;
  border-color: #007aff;
  border-style: solid;
}
.face-id-corner.top-left { top: 6px; left: 6px; border-width: 3px 0 0 3px; border-top-left-radius: 8px; }
.face-id-corner.top-right { top: 6px; right: 6px; border-width: 3px 3px 0 0; border-top-right-radius: 8px; }
.face-id-corner.bottom-left { bottom: 6px; left: 6px; border-width: 0 0 3px 3px; border-bottom-left-radius: 8px; }
.face-id-corner.bottom-right { bottom: 6px; right: 6px; border-width: 0 3px 3px 0; border-bottom-right-radius: 8px; }
.face-id-box.success .face-id-corner { border-color: #30d158; }
.face-id-scan-line {
  position: absolute;
  top: 10px; left: 10px; right: 10px;
  height: 2px;
  background: linear-gradient(90deg, transparent, #007aff, transparent);
  box-shadow: 0 0 10px #007aff;
  animation: scanRadar 1.2s infinite alternate ease-in-out;
}
@keyframes scanRadar {
  0% { transform: translateY(0px); opacity: 0.3; }
  100% { transform: translateY(68px); opacity: 1; }
}
.btn-qty-minus {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 22px; height: 22px;
  border-radius: 50%;
  border: 1px solid #d1d5db;
  background: #ffffff;
  color: #374151;
  font-size: 11px;
  cursor: pointer;
  line-height: 1;
}
.btn-qty-minus:hover {
  background: #f3f4f6;
  border-color: #9ca3af;
}
.cust-tracking-map, #cust-tracking-map {
  height: 250px !important;
  min-height: 250px !important;
  width: 100% !important;
  display: block !important;
  background: #f3f4f6 !important;
}
</style>
</head>
<body class="<?php echo !empty($is_mobile_webview) ? 'is-mobile-webview' : ''; ?>" style="pointer-events: auto !important; position: relative; z-index: 1;">
<div class="app" style="pointer-events: auto !important; position: relative; z-index: 1;">

<header class="top">
  <div class="brand-row">
    <div class="brand">
      <img class="logo" src="logo.jpg" alt="Food Dash">
      <div><b>FOOD DASH</b><small>GOOD FOOD. FASTER.</small></div>
    </div>
    <div class="top-icons">
      <button type="button" class="icon-btn" onclick="openNotifications()" title="Notifications">
        <i class="fa-regular fa-bell"></i>
        <span id="custNoteBadge" class="bell-badge" style="display:none">0</span>
      </button>
      <button type="button" id="fdGmailHeaderBtn" class="icon-btn fd-gmail-header-btn" onclick="openGmailModal()" title="Gmail Inbox" aria-label="Gmail Inbox">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
          <path d="M20 4H4C2.9 4 2.01 4.9 2.01 6L2 18C2 19.1 2.9 20 4 20H20C21.1 20 22 19.1 22 18V6C22 4.9 21.1 4 20 4Z" fill="#EA4335" opacity="0.18"/>
          <path d="M20 4H4C2.9 4 2 4.9 2 6V18C2 19.1 2.9 20 4 20H20C21.1 20 22 19.1 22 18V6C22 4.9 21.1 4 20 4ZM20 8L12 13L4 8V6L12 11L20 6V8Z" fill="#EA4335"/>
        </svg>
        <span id="fdGmailUnreadBadge" class="bell-badge" style="display:none; background:#ea4335;">0</span>
      </button>
      <div class="avatar"><?php echo strtoupper(substr($full_name,0,1)); ?></div>
    </div>
  </div>
</header>


<section id="home" class="screen active">
  <div class="greeting"><small>Good Morning,</small><h1><?php echo htmlspecialchars($full_name); ?>!</h1></div>
  <div class="search" onclick="showScreen('search')"><i class="fa-solid fa-magnifying-glass"></i><input readonly placeholder="Search for food, restaurants, or stores..."></div>
  <div class="hero"><h2>Delicious Food<br>Fresh & Fast</h2><p>Your favorite Filipino meals delivered.</p><button onclick="showScreen('stores')">Order Now</button></div>

  <div class="section-title"><h3>Popular Stores</h3><a onclick="showScreen('stores')">See All</a></div>
  <div class="store-row">
    <?php
    $popular_stores = !empty($db_shops) ? array_slice($db_shops, 0, 8) : [];
    if (empty($popular_stores)): ?>
      <div style="color:var(--muted); font-size:12px; padding:12px 6px;">No partner stores available.</div>
    <?php else: foreach ($popular_stores as $ps):
        $p_logo = !empty($ps['logo_url']) ? $ps['logo_url'] : 'assets/shops/default_store.png';
        $p_name = $ps['shop_name'];
        $p_cat = $ps['category'] ?? 'Restaurant';
    ?>
    <div class="store" onclick="openStore('<?php echo htmlspecialchars(addslashes($p_name)); ?>', '<?php echo htmlspecialchars(addslashes($p_logo)); ?>', <?php echo (int)($ps['shop_id'] ?? 0); ?>)">
      <div class="store-logo">
        <img src="<?php echo htmlspecialchars($p_logo); ?>" alt="<?php echo htmlspecialchars($p_name); ?>" onerror="if(!this.dataset.fallback){this.dataset.fallback=1;this.src='assets/shops/default_store.png';}else{this.onerror=null;this.src='logo.jpg';}">
      </div>
      <strong><?php echo htmlspecialchars($p_name); ?></strong>
      <small><?php echo htmlspecialchars($p_cat); ?></small>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <div class="section-title"><h3>Popular Near You</h3><a onclick="showScreen('stores')">See All</a></div>
  <div class="grid" id="popularNearYouGrid">
    <?php
    $disp_popular = !empty($popular_dishes) ? $popular_dishes : [];
    if (empty($disp_popular)): ?>
      <div style="color:var(--muted); font-size:12px; padding:12px 6px;">No popular dishes currently available.</div>
    <?php else: foreach ($disp_popular as $pd): 
        $pd_name = $pd['item_name'];
        $pd_price = (float)$pd['price'];
        $pd_icon = !empty($pd['icon']) ? $pd['icon'] : '🍽️';
        $pd_desc = !empty($pd['description']) ? $pd['description'] : 'Fresh and delicious meal prepared to order.';
        $pd_store = !empty($pd['shop_name']) ? $pd['shop_name'] : 'FoodDash Grill';
    ?>
    <div class="food-card" data-category="Popular" onclick="openItem('<?php echo htmlspecialchars(addslashes($pd_name)); ?>',<?php echo $pd_price; ?>,'<?php echo htmlspecialchars(addslashes($pd_icon)); ?>','<?php echo htmlspecialchars(addslashes($pd_desc)); ?>','<?php echo htmlspecialchars(addslashes($pd_store)); ?>')">
      <div class="food-img"><?php echo htmlspecialchars($pd_icon); ?></div>
      <div class="food-body">
        <strong><?php echo htmlspecialchars($pd_name); ?></strong>
        <small><?php echo htmlspecialchars($pd_store); ?></small>
        <div class="price-row">
          <span class="price">₱<?php echo number_format($pd_price, 2); ?></span>
          <button class="add" type="button" data-name="<?php echo htmlspecialchars($pd_name); ?>" data-price="<?php echo $pd_price; ?>" data-icon="<?php echo htmlspecialchars($pd_icon); ?>" data-store="<?php echo htmlspecialchars($pd_store); ?>" onclick="if(window.event){window.event.stopPropagation();if(window.event.stopImmediatePropagation)window.event.stopImmediatePropagation();}addCart('<?php echo htmlspecialchars(addslashes($pd_name)); ?>',<?php echo $pd_price; ?>,'<?php echo htmlspecialchars(addslashes($pd_icon)); ?>','<?php echo htmlspecialchars(addslashes($pd_store)); ?>',false,1,event)">+</button>
        </div>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
</section>

<section id="stores" class="screen">
  <div class="section-title"><h3>All Partner Stores</h3><i class="fa-regular fa-clock"></i></div>
  <div class="search"><i class="fa-solid fa-magnifying-glass"></i><input id="storeSearch" oninput="filterStores()" placeholder="Search for a store..."></div>
  <div class="chips" id="storeCategoryChips">
    <button class="chip active" data-category="all" onclick="filterStoreCategory('all', this)">All</button>
    <?php if (!empty($unique_shop_categories)): foreach ($unique_shop_categories as $scat): ?>
      <button class="chip" data-category="<?php echo htmlspecialchars(strtolower($scat)); ?>" onclick="filterStoreCategory('<?php echo htmlspecialchars(addslashes($scat)); ?>', this)"><?php echo htmlspecialchars($scat); ?></button>
    <?php endforeach; else: ?>
      <button class="chip" data-category="grill" onclick="filterStoreCategory('Grill', this)">Grill & BBQ</button>
      <button class="chip" data-category="fast food" onclick="filterStoreCategory('Fast Food', this)">Fast Food</button>
      <button class="chip" data-category="chicken" onclick="filterStoreCategory('Chicken', this)">Chicken</button>
      <button class="chip" data-category="pizza" onclick="filterStoreCategory('Pizza', this)">Pizza</button>
      <button class="chip" data-category="chinese" onclick="filterStoreCategory('Chinese', this)">Chinese</button>
    <?php endif; ?>
  </div>
  <div class="list" id="storeList">
    <?php
    $all_stores = !empty($db_shops) ? $db_shops : [];
    if (empty($all_stores)): ?>
      <div style="color:var(--muted); font-size:12px; padding:12px 6px;">No partner stores available.</div>
    <?php else: foreach ($all_stores as $sh): 
      $sh_logo = !empty($sh['logo_url']) ? $sh['logo_url'] : 'assets/shops/default_store.png';
      $sh_name = $sh['shop_name'];
      $sh_cat = $sh['category'] ?? 'Specialty Food';
      $sh_id = (int)($sh['shop_id'] ?? 0);
    ?>
    <div class="row-card" data-name="<?php echo strtolower(htmlspecialchars($sh_name)); ?>" data-category="<?php echo strtolower(htmlspecialchars($sh_cat)); ?>" onclick="openStore('<?php echo htmlspecialchars(addslashes($sh_name)); ?>', '<?php echo htmlspecialchars(addslashes($sh_logo)); ?>', <?php echo $sh_id; ?>)">
      <div class="row-icon">
        <img src="<?php echo htmlspecialchars($sh_logo); ?>" alt="<?php echo htmlspecialchars($sh_name); ?>" onerror="if(!this.dataset.fallback){this.dataset.fallback=1;this.src='assets/shops/default_store.png';}else{this.onerror=null;this.src='logo.jpg';}">
      </div>
      <div class="info">
        <strong><?php echo htmlspecialchars($sh_name); ?></strong>
        <small><?php echo htmlspecialchars($sh_cat); ?></small>
        <small>⭐ 4.8 • 20–35 min • <b style="color:#2f8f45">Open</b></small>
      </div>
      <i class="fa-solid fa-chevron-right chev"></i>
    </div>
    <?php endforeach; endif; ?>
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
    <div class="rating">⭐ 4.8 &nbsp; • &nbsp; 20–30 min &nbsp; • &nbsp; <b>Open</b></div>
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
    <div class="item-info"><h2 id="itemName">Big Mac</h2><p id="itemDesc">Two beef patties, special sauce, lettuce and cheese.</p><div class="item-price" id="itemPrice">₱179.00</div><div class="qty"><button type="button" class="btn-qty-minus" onclick="changeQty(-1)" aria-label="Decrease quantity"><i class="fa-solid fa-minus"></i></button><span id="itemQty">1</span><button onclick="changeQty(1)">+</button></div><button class="primary" style="margin-top:13px" onclick="addCurrentItem()">Add to Cart</button></div>
  </div>
</section>

<section id="search" class="screen">
  <div class="section-title"><h3>Search</h3><i class="fa-solid fa-sliders"></i></div>
  <div class="search"><i class="fa-solid fa-magnifying-glass"></i><input id="searchInput" oninput="searchFood()" placeholder="Search for restaurants, food, or stores..."></div>
  <div class="section-title"><h3>Popular Searches</h3></div>
  <div class="chips"><button class="chip" onclick="setSearch('big mac')">Big Mac</button><button class="chip" onclick="setSearch('chickenjoy')">Chickenjoy</button><button class="chip" onclick="setSearch('mcspaghetti')">McSpaghetti</button><button class="chip" onclick="setSearch('inasal')">Inasal</button><button class="chip" onclick="setSearch('pizza')">Pizza</button></div>
  <div class="list" id="searchResults">
    <?php if (!empty($db_shops)): foreach ($db_shops as $sh): 
        $sh_logo = !empty($sh['logo_url']) ? $sh['logo_url'] : 'assets/shops/default_store.png';
        $sh_name = $sh['shop_name'];
        $sh_cat = $sh['category'] ?? 'Specialty Food';
        $sh_id = (int)($sh['shop_id'] ?? 0);
    ?>
    <div class="row-card" data-name="<?php echo strtolower(htmlspecialchars($sh_name)); ?>" data-category="<?php echo strtolower(htmlspecialchars($sh_cat)); ?>" onclick="openStore('<?php echo htmlspecialchars(addslashes($sh_name)); ?>', '<?php echo htmlspecialchars(addslashes($sh_logo)); ?>', <?php echo $sh_id; ?>)">
      <div class="row-icon"><img src="<?php echo htmlspecialchars($sh_logo); ?>" alt="<?php echo htmlspecialchars($sh_name); ?>" onerror="if(!this.dataset.fallback){this.dataset.fallback=1;this.src='assets/shops/default_store.png';}else{this.onerror=null;this.src='logo.jpg';}"></div>
      <div class="info"><strong><?php echo htmlspecialchars($sh_name); ?></strong><small><?php echo htmlspecialchars($sh_cat); ?></small></div>
    </div>
    <?php endforeach; else: ?>
    <div class="row-card" onclick="openStore('FoodDash Grill','logo.jpg', 1)"><div class="row-icon"><img src="logo.jpg" alt="FoodDash Grill" onerror="this.src='assets/shops/default_store.png'"></div><div class="info"><strong>FoodDash Grill</strong><small>Smoked Ribs • Flame-Grilled BBQ • Angus Burgers</small></div></div>
    <?php endif; ?>
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
      <textarea id="deliveryAddressInput" class="delivery-textarea" placeholder="Enter complete delivery address (e.g. Purok, Street, Barangay, Landmark)" rows="2"></textarea>
      <div id="gpsLocationStatus" class="gps-status-pill" style="display:none;"></div>
      <input type="hidden" id="delivery_lat" name="delivery_lat" value="">
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
      <div class="sumrow"><span>Subtotal</span><span id="subtotal">₱0.00</span></div>
      <div class="sumrow"><span>Delivery Fee</span><span>₱30.00</span></div>
      <div class="sumrow total"><span>Total</span><span id="total">₱30.00</span></div>
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
    <?php if (empty($db_orders)): ?>
      <div class="empty-state-card" id="noOrders" style="text-align: center; padding: 36px 18px; background: #ffffff; border-radius: 14px; margin: 12px 0; border: 1.5px dashed #cbd5e1; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
        <div style="font-size: 42px; margin-bottom: 10px; line-height: 1;">🛍️</div>
        <strong style="display: block; font-size: 16px; color: var(--olive-dark, #2f341d); margin-bottom: 6px;">No orders placed yet!</strong>
        <p style="font-size: 12px; color: var(--muted, #64748b); margin-bottom: 16px; max-width: 280px; margin-left: auto; margin-right: auto; line-height: 1.4;">
          Your order history is clean. Discover our partner stores and order delicious meals today!
        </p>
        <button type="button" class="btn-browse-stores-main" onclick="showScreen('stores')" style="display: inline-flex; align-items: center; gap: 8px; background: var(--harvest-sun, #c5a059); color: #ffffff; padding: 10px 22px; border-radius: 10px; font-weight: 700; border: none; cursor: pointer; box-shadow: 0 4px 12px rgba(197, 160, 89, 0.35);">
          <i class="fa-solid fa-store"></i> Browse Stores
        </button>
      </div>
    <?php else: foreach ($db_orders as $ord): 
      $raw_status = strtoupper(trim(str_replace([' ', '-'], '_', (string)($ord['status'] ?? ''))));
      $del_status = strtolower(trim((string)($ord['delivery_status'] ?? '')));
      $status_lower = strtolower(trim((string)($ord['status'] ?? '')));
      
      $is_arrived = (in_array($raw_status, ['ARRIVED', 'ARRIVED_DESTINATION']) || in_array($del_status, ['arrived', 'arrived_destination']) || in_array($status_lower, ['arrived', 'arrived_destination']));
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
      <div class="row-card order-row <?= $is_ontheway ? 'on-the-way-card' : '' ?>" 
           data-status="<?= htmlspecialchars($norm_status) ?>" 
           data-order-id="<?= (int)$ord['order_id'] ?>"
           data-order-ref="<?= htmlspecialchars($clean_card_ref) ?>"
           data-store-name="<?= htmlspecialchars($ord['store_name']) ?>"
           data-items-summary="<?= htmlspecialchars($ord['items_summary']) ?>"
           data-delivery-address="<?= htmlspecialchars($ord['delivery_address'] ?? '') ?>"
           data-total-amount="<?= htmlspecialchars(number_format((float)$ord['total_amount'], 2)) ?>"
           data-delivery-lat="<?= !empty($ord['delivery_lat']) ? (float)$ord['delivery_lat'] : '' ?>"
           data-delivery-lng="<?= !empty($ord['delivery_lng']) ? (float)$ord['delivery_lng'] : '' ?>"
            data-store-lat="11.0062"
            data-store-lng="124.6068"
           data-rider-name="<?= htmlspecialchars($ord['rider_name'] ?? '') ?>"
           data-rider-phone="<?= htmlspecialchars($ord['rider_phone'] ?? '') ?>"
           id="order-card-<?= $ord['order_id'] ?>" 
           onclick="openTrackingModal(<?= (int)$ord['order_id'] ?>, { orderId: <?= (int)$ord['order_id'] ?>, orderNum: '<?= htmlspecialchars(addslashes($clean_card_ref)) ?>', storeName: '<?= htmlspecialchars(addslashes($ord['store_name'])) ?>', storeLat: '11.0062', storeLng: '124.6068', itemsSummary: '<?= htmlspecialchars(addslashes($ord['items_summary'])) ?>', deliveryAddress: '<?= htmlspecialchars(addslashes($ord['delivery_address'] ?? '')) ?>', totalAmount: '<?= htmlspecialchars(addslashes((string)$ord['total_amount'])) ?>', orderStatus: '<?= htmlspecialchars(addslashes($norm_status)) ?>', deliveryLat: '<?= !empty($ord['delivery_lat']) ? (float)$ord['delivery_lat'] : '' ?>', deliveryLng: '<?= !empty($ord['delivery_lng']) ? (float)$ord['delivery_lng'] : '' ?>', riderName: '<?= htmlspecialchars(addslashes($ord['rider_name'] ?? '')) ?>', riderPhone: '<?= htmlspecialchars(addslashes($ord['rider_phone'] ?? '')) ?>' })" style="cursor:pointer;" title="Tap to view live delivery tracking map">
        <div class="row-icon <?= $is_cancelled ? 'grey' : ($is_ontheway ? 'jolli' : ($is_settled ? 'settled-icon' : 'green')) ?>">
          <?= $is_cancelled ? '🚫' : ($is_ontheway ? '🛵' : ($is_settled ? '🛡️' : ($is_delivered ? '📦' : ($is_prep ? '🍳' : '🛍️')))) ?>
        </div>
        <div class="info">
          <strong class="order-ref"><?= htmlspecialchars($clean_card_ref) ?></strong>
          <small class="order-store-items"><?= htmlspecialchars($ord['store_name']) ?> • <?= htmlspecialchars($ord['items_summary']) ?></small>
          <small class="order-meta order-date-price"><?= htmlspecialchars(date('M d, Y • g:i A', strtotime($ord['created_at']))) ?> • ₱<?= number_format((float)$ord['total_amount'], 2) ?></small>
          <?php if (!empty($ord['delivery_address'])): ?>
            <small class="order-meta order-address" style="color: #64748b; font-weight: 500; font-size: 12px; margin-top: 2px; white-space: normal; line-height: 1.35;"><i class="fa-solid fa-location-dot" style="color:var(--harvest-sun)"></i> <?= htmlspecialchars($ord['delivery_address']) ?></small>
          <?php endif; ?>
          
          <div style="margin-top: 8px; display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
            <span class="status-badge status-<?= htmlspecialchars($norm_status) ?>" id="status-tag-<?= $ord['order_id'] ?>">
              <?php if ($is_arrived): ?>
                <i class="fa-solid fa-bell fa-shake"></i>
              <?php elseif ($is_near): ?>
                <i class="fa-solid fa-person-biking fa-beat"></i>
              <?php endif; ?>
              <?= htmlspecialchars($status_label) ?>
            </span>
            <?= render_payment_badge($ord['payment_method'] ?? 'Cash on Delivery (COD)') ?>
            <?php if ($is_settled): ?>
              <span class="settled-tag"><i class="fa-solid fa-circle-check"></i> Reconciled</span>
            <?php endif; ?>
          </div>

          <?php if ($can_cancel): ?>
            <!-- a. Status 'pending' / 'preparing': Show Cancel Order button -->
            <div style="margin-top:8px;" id="cancel-wrap-<?= $ord['order_id'] ?>" class="order-actions-wrap">
              <button type="button" class="btn-cancel-order" id="btn-cancel-<?= $ord['order_id'] ?>" onclick="event.stopPropagation(); promptCancelOrder(<?= (int)$ord['order_id'] ?>, '<?= htmlspecialchars(addslashes($clean_card_ref)) ?>', '<?= htmlspecialchars(addslashes($status_label)) ?>')">
                <i class="fa-solid fa-ban"></i> Cancel Order
              </button>
            </div>
          <?php elseif ($is_cancelled): ?>
            <div style="font-size: 12px; color: #dc2626; font-weight:700; margin-top:6px;">
              <i class="fa-solid fa-circle-xmark"></i> Cancelled by Customer
            </div>
          <?php endif; ?>

          <?php if ($is_ontheway || $is_near || $is_arrived): ?>
            <!-- b. Status 'on_delivery' / 'near_customer' / 'arrived': Show Live Track Rider and Received buttons -->
            <div style="font-size: 12px; color: var(--harvest-sun); font-weight:600; margin-top:6px;">
              <?php if ($is_arrived): ?>
                <i class="fa-solid fa-bell fa-shake"></i> Rider has arrived at your location!
              <?php elseif ($is_near): ?>
                <i class="fa-solid fa-person-biking fa-beat"></i> Rider is near your location (&lt; 200m)!
              <?php endif; ?>
              <?= htmlspecialchars($status_label) ?>
            </span>
            <div id="track-actions-<?= $ord['order_id'] ?>" class="order-actions-wrap" style="display:flex; gap:6px; margin-top:8px; flex-wrap:wrap;">
              <button type="button" class="cust-track-btn" onclick="event.stopPropagation(); openTrackingModal(<?= (int)$ord['order_id'] ?>, { orderId: <?= (int)$ord['order_id'] ?>, orderNum: '<?= htmlspecialchars(addslashes($clean_card_ref)) ?>', storeName: '<?= htmlspecialchars(addslashes($ord['store_name'])) ?>', storeLat: '11.0062', storeLng: '124.6068', itemsSummary: '<?= htmlspecialchars(addslashes($ord['items_summary'])) ?>', deliveryAddress: '<?= htmlspecialchars(addslashes($ord['delivery_address'] ?? '')) ?>', totalAmount: '<?= htmlspecialchars(addslashes((string)$ord['total_amount'])) ?>', orderStatus: '<?= htmlspecialchars(addslashes($norm_status)) ?>' })" title="Tap to view live delivery map">
                <i class="fa-solid fa-location-dot"></i> 📍 Live Track Rider
              </button>
              <button class="confirm-btn" id="confirm-btn-<?= $ord['order_id'] ?>" onclick="event.stopPropagation(); confirmOrderReceived(<?= $ord['order_id'] ?>)">
                <i class="fa-solid fa-box-open"></i> 📦 Received
              </button>
            </div>
          <?php elseif ($is_delivered && !$is_settled): ?>
            <div style="font-size: 12px; color: var(--sagebrush); font-weight:600; margin-top:6px;">
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
<div class="fd-modal" id="addressPromptModal" style="display:none; pointer-events:none; visibility:hidden;" onclick="if(event.target===this)closeAddressPromptModal()">
  <div class="fd-modal-box">
    <div class="fd-modal-header">
      <h3><i class="fa-solid fa-location-dot" style="color:var(--harvest-sun)"></i> Delivery Address & Pin</h3>
      <button class="fd-modal-close" onclick="closeAddressPromptModal()">&times;</button>
    </div>
    <div style="font-size: 9.5px; color: var(--muted); margin-bottom: 8px; line-height: 1.4;">
      Search your street, subdivision, or village, or drag the pin to your exact delivery location.
    </div>

    <!-- OpenStreetMap Nominatim Search / Autocomplete Geocoder -->
    <div class="map-search-wrapper" style="position:relative; margin-bottom:10px;">
      <div style="display:flex; align-items:center; gap:6px;">
        <div style="position:relative; flex:1;">
          <i class="fa-solid fa-magnifying-glass" style="position:absolute; left:10px; top:50%; transform:translateY(-50%); font-size:11px; color:#8c7b64; pointer-events:none;"></i>
          <input type="text" 
                 id="modalMapSearchInput" 
                 class="delivery-textarea" 
                 placeholder="Search street, subdivision, village (e.g. Alta Vista, Ormoc)..." 
                 style="height:36px; padding:6px 28px 6px 30px; font-size:11px; border-radius:8px; width:100%; box-sizing:border-box;"
                 autocomplete="off"
                 oninput="handleMapSearchInput(this.value)"
                 onkeydown="handleMapSearchKeydown(event)">
          <button type="button" 
                  id="modalMapSearchClear" 
                  onclick="clearMapSearch()" 
                  style="display:none; position:absolute; right:8px; top:50%; transform:translateY(-50%); background:transparent; border:none; color:#9ca3af; font-size:13px; cursor:pointer; padding:2px; line-height:1;">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>
        <button type="button" 
                class="btn-fd-primary" 
                id="modalMapSearchBtn" 
                onclick="executeMapSearch()" 
                style="height:36px; padding:0 12px; font-size:11px; border-radius:8px; white-space:nowrap; display:inline-flex; align-items:center; gap:5px; margin:0; flex-shrink:0;">
          <i class="fa-solid fa-search"></i> Search
        </button>
      </div>

      <!-- Live Autocomplete Suggestions Dropdown -->
      <div id="mapSearchResults" 
           style="display:none; position:absolute; top:40px; left:0; right:0; background:#ffffff; border:1px solid #d8cbb5; border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,0.16); max-height:210px; overflow-y:auto; z-index:99999;">
      </div>
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

    <textarea id="modalDeliveryAddress" class="delivery-textarea" placeholder="Enter complete delivery address (e.g. Purok, Street, Barangay, Landmark)" rows="2"></textarea>
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
<div class="fd-modal" id="customerNoteModal" style="display:none; pointer-events:none; visibility:hidden;" onclick="if(event.target===this)closeNotifications()">
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

<div class="logout-modal" id="logoutModal" style="display:none; pointer-events:none; visibility:hidden;" onclick="if(event.target===this)closeLogout()"><div class="logout-box"><img class="logout-logo" src="logo.jpg" alt="Food Dash"><h3>Log out of Food Dash?</h3><p>Your current session will be signed out. You can log in again anytime.</p><div class="logout-actions"><button class="logout-cancel" type="button" onclick="closeLogout()">Cancel</button><button class="logout-confirm" type="button" onclick="executeLogout()">Yes, Log Out</button></div></div></div>

<!-- Customer Live Delivery Tracking Modal -->
<div class="fd-modal tracking-modal" id="customerTrackingModal" style="display:none; pointer-events:none; visibility:hidden;" onclick="if(event.target===this)closeCustomerTrackingModal()">
  <div class="fd-modal-box cust-track-modal-box modal-content">
    <div class="fd-modal-header" style="padding-bottom:10px; border-bottom:1px solid var(--sandstone); flex-shrink:0;">
      <div style="display:flex; align-items:center; gap:8px;">
        <span style="font-size:18px;">🛵</span>
        <div>
          <div style="display:flex; align-items:center; gap:6px;">
            <h3 id="trackModalOrderRef" style="margin:0; font-size:13px; color:var(--olive-shadow);">Live Delivery Tracking</h3>
            <span id="trackModalStatusBadge" style="background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:0.5px; padding:2px 6px; border-radius:5px;">ON THE WAY</span>
          </div>
          <small id="trackModalStoreSubtitle" style="font-size:10px; color:var(--muted);">Store pickup & real-time GPS</small>
        </div>
      </div>
      <button class="fd-modal-close" onclick="closeCustomerTrackingModal()">&times;</button>
    </div>

    <div class="tracking-modal-body modal-body" style="overflow-y:auto; -webkit-overflow-scrolling:touch; flex:1 1 auto;">
      <!-- Explicit Delivery Destination Card -->
      <div class="cust-dest-card" id="custTrackDestCard" style="background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:12px; padding:10px 14px; margin-bottom:10px;">
        <div style="display:flex; align-items:center; gap:6px; margin-bottom:3px;">
          <span style="font-size:14px;">📍</span>
          <span style="font-size:9.5px; font-weight:800; color:#dc2626; text-transform:uppercase; letter-spacing:0.6px;">DELIVERY DESTINATION</span>
        </div>
        <div id="custTrackDestAddress" style="font-size:13px; font-weight:700; color:#1e293b; line-height:1.35;">Delivery Destination</div>
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
      <div id="cust-tracking-map" class="cust-tracking-map" style="width: 100%; height: 250px; min-height: 250px; border-radius: 12px; margin-top: 10px; border: 1.5px solid var(--sandstone); position:relative;"></div>

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
<div class="fd-modal" id="customerChatModal" style="display:none; pointer-events:none; visibility:hidden;" onclick="if(event.target===this)closeCustomerChat()">
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
        <button type="button" class="quick-pill" onclick="sendCustomerQuickReply('I'm waiting outside! 👋')">👋 I'm waiting outside</button>
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
      <a href="javascript:void(0)" id="custChatSmsLink" class="chat-sms-btn" target="_blank" title="Send via native SMS"><i class="fa-solid fa-comment-dots"></i> SMS</a>
    </div>
  </div>
</div>

<!-- Incoming Call Floating Overlay Modal -->
<div id="incomingCallModal" class="incoming-call-modal" style="display:none; pointer-events:none; visibility:hidden;">
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
<div class="fd-modal" id="riderArrivedModal" style="display:none; pointer-events:none; visibility:hidden; z-index:9999999;">
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
      📍 Your rider has arrived at your delivery address! Please choose a quick response so your rider knows you are coming:
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
    <div class="rct-text" id="liveMsgBannerText">I'm outside with your order! 🛵</div>
  </div>
  <button type="button" class="rct-close" onclick="event.stopPropagation(); dismissLiveRiderBanner()">&times;</button>
</div>

<!-- Generic In-App Confirmation Modal (Food Dash Palette) -->
<div class="fd-modal" id="customerConfirmModal" style="display:none; pointer-events:none; visibility:hidden;" onclick="if(event.target===this)closeCustConfirm()">
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
<div class="fd-modal" id="cancelOrderModal" style="display:none; pointer-events:none; visibility:hidden;" onclick="if(event.target===this)closeCancelOrderModal()">
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
<div class="fd-modal" id="savedAddressesModal" style="display:none; pointer-events:none; visibility:hidden;" onclick="if(event.target===this)closeSavedAddressesModal()">
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
<div class="fd-modal" id="addAddressModal" style="display:none; pointer-events:none; visibility:hidden;" onclick="if(event.target===this)closeAddAddressModal()">
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
<div class="fd-modal" id="paymentMethodsModal" style="display:none; pointer-events:none; visibility:hidden;" onclick="if(event.target===this)closePaymentMethodsModal()">
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
<div class="fd-modal" id="helpSupportModal" style="display:none; pointer-events:none; visibility:hidden;" onclick="if(event.target===this)closeHelpSupportModal()">
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
          Meals are typically prepared by our restaurant chefs within 15–20 minutes. Once picked up by your rider, delivery takes approximately 10–20 minutes depending on road distance and traffic.
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
<div class="fd-modal" id="aboutModal" style="display:none; pointer-events:none; visibility:hidden;" onclick="if(event.target===this)closeAboutModal()">
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
<div class="fd-modal" id="termsPrivacyModal" style="display:none; pointer-events:none; visibility:hidden;" onclick="if(event.target===this)closeTermsModal()">
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

<div class="toast" id="toast" style="display:none !important;" aria-hidden="true"></div>
</div>

<script src="assets/js/order_call_engine.js"></script>
<script>
if (window.FoodDashCall) {
  FoodDashCall.init('customer');
}
let cart = [];
try {
  const _savedCart = JSON.parse(localStorage.getItem('foodDashCart') || '[]');
  if (Array.isArray(_savedCart)) {
    cart = _savedCart.map(function(item) {
      return {
        ...item,
        qty: Math.max(1, parseInt(item.qty || item.quantity, 10) || 1)
      };
    });
  }
} catch (e) {
  cart = [];
}
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
    ['Chopsuey',129,'🥗','Mixed vegetables in savory sauce.','Sides']
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
  if(id==='orders' && typeof loadCustomerOrdersLive === 'function') loadCustomerOrdersLive();
}

function toast(msg, type = null){
  if (!msg) return;
  let title = 'NOTIFICATION';
  const str = String(msg).toLowerCase();
  if (typeof type === 'string' && type) {
    title = type.toUpperCase();
  } else if (str.includes('cart')) {
    title = 'SHOPPING CART';
  } else if (str.includes('order')) {
    title = 'ORDER UPDATE';
  } else if (str.includes('address') || str.includes('location') || str.includes('gps') || str.includes('pinned')) {
    title = 'LOCATION UPDATE';
  } else if (str.includes('arrived')) {
    title = 'RIDER ARRIVED';
  } else if (str.includes('fail') || str.includes('error')) {
    title = 'NOTICE';
  }
  showCustomerFloatingBanner(title, msg);
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
      const unreadCount = parseInt(d.unread ?? d.unread_count ?? 0, 10);
      b.textContent = unreadCount > 99 ? '99+' : unreadCount;
      b.style.display = unreadCount > 0 ? 'inline-flex' : 'none';
      if (unreadCount > 0) {
        b.classList.add('has-unread');
      } else {
        b.classList.remove('has-unread');
      }
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

    // Dynamic on-screen structured banner display for unread pickup / out-for-delivery notifications
    if (Array.isArray(d.notifications)) {
      d.notifications.forEach(n => {
        const isUnread = (n.is_read == 0 || n.is_read === false || n.is_read === 'f' || n.status === 'unread');
        if (isUnread) {
          const type = String(n.notification_type || '').toLowerCase();
          const title = String(n.title || '').toLowerCase();
          const msg = String(n.message || '');
          const isPickupNote = type === 'rider_assigned' || type === 'order_on_the_way' || type === 'rider_on_way' 
                            || title.includes('out for delivery') || title.includes('picked up') || title.includes('on delivery')
                            || msg.toLowerCase().includes('picked up');
          if (isPickupNote && n.order_id) {
            const notifKey = 'fd_notif_seen_' + n.notification_id;
            const pickupKey = 'fd_pickup_alert_seen_' + n.order_id;
            if (sessionStorage.getItem(notifKey) !== '1' && sessionStorage.getItem(pickupKey) !== '1') {
              sessionStorage.setItem(notifKey, '1');
              sessionStorage.setItem(pickupKey, '1');
              showCustomerFloatingBanner('ORDER PICKED UP - READY FOR DELIVERY', msg, () => {
                if (typeof openTrackingModal === 'function') openTrackingModal(n.order_id);
                else if (typeof showScreen === 'function') showScreen('orders');
              });
            }
          }
        }
      });
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

function renderStoreDishes(storeKey, list) {
  // Dynamically resolve unique categories for this specific active store
  let categories = (typeof dbStoreCategories !== 'undefined' && dbStoreCategories && dbStoreCategories[storeKey] && dbStoreCategories[storeKey].length)
    ? [...dbStoreCategories[storeKey]]
    : Array.from(new Set((list || []).map(d => d.category || (Array.isArray(d) ? d[4] : '')).filter(Boolean)));
    
  // Always render "All" as default active button, followed by unique categories for this shop
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
  
  if (!list || !list.length) {
    if (storeItemsContainer) storeItemsContainer.innerHTML = '';
    if (emptyState) emptyState.style.display = 'flex';
  } else {
    if (storeItemsContainer) {
      storeItemsContainer.innerHTML = list.map(item => {
        const isArr = Array.isArray(item);
        const itemName = isArr ? item[0] : (item.name || item.item_name);
        const itemPrice = isArr ? item[1] : item.price;
        const itemIcon = isArr ? item[2] : (item.icon || '🍽️');
        const itemDesc = isArr ? item[3] : (item.desc || item.description || '');
        const itemCat = isArr ? (item[4] || 'Main') : (item.category || 'Main');
        
        return `<div class="food-card" data-category="${safeAttr(itemCat)}" onclick="openItem('${safeJs(itemName)}',${Number(itemPrice)},'${safeJs(itemIcon)}','${safeJs(itemDesc)}','${safeJs(storeKey)}')">
          <div class="food-img">${esc(itemIcon)}</div>
          <div class="food-body">
            <strong>${esc(itemName)}</strong>
            <small>${esc(storeKey)}</small>
            <div class="price-row">
              <span class="price">${money(itemPrice)}</span>
              <button class="add" type="button" data-name="${safeAttr(itemName)}" data-price="${Number(itemPrice)}" data-icon="${safeAttr(itemIcon)}" data-store="${safeAttr(storeKey)}" onclick="if(window.event){window.event.stopPropagation();if(window.event.stopImmediatePropagation)window.event.stopImmediatePropagation();}addCart(decodeURIComponent('${safeJs(itemName)}'),${Number(itemPrice)},decodeURIComponent('${safeJs(itemIcon)}'),decodeURIComponent('${safeJs(storeKey)}'),false,1,event)">+</button>
            </div>
          </div>
        </div>`;
      }).join('');
    }
  }
}

function openStore(name, logoUrl, shopId){
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
  let list = [];
  const normKey = (storeKey || '').toLowerCase().replace(/[^a-z0-9]/g, '');
  const rawKey = (name || '').toLowerCase().replace(/[^a-z0-9]/g, '');
  
  if (typeof dbStoreMenus !== 'undefined' && dbStoreMenus) {
    if (dbStoreMenus[storeKey] && dbStoreMenus[storeKey].length) list = dbStoreMenus[storeKey];
    else if (name && dbStoreMenus[name] && dbStoreMenus[name].length) list = dbStoreMenus[name];
    else if (shopId && dbStoreMenus[shopId] && dbStoreMenus[shopId].length) list = dbStoreMenus[shopId];
    else if (shopId && dbStoreMenus[String(shopId)] && dbStoreMenus[String(shopId)].length) list = dbStoreMenus[String(shopId)];
    else if (normKey && dbStoreMenus[normKey] && dbStoreMenus[normKey].length) list = dbStoreMenus[normKey];
    else if (rawKey && dbStoreMenus[rawKey] && dbStoreMenus[rawKey].length) list = dbStoreMenus[rawKey];
  }
  if (!list.length && typeof menus !== 'undefined' && menus && menus[storeKey]) {
    list = menus[storeKey].map(m => ({
      name: m[0], price: m[1], icon: m[2], desc: m[3], category: m[4] || 'Main'
    }));
  }
  
  renderStoreDishes(storeKey, list);
  showScreen('store');

  // If menu list was empty, asynchronously query api_menu.php to ensure zero blank states
  if (!list.length) {
    const queryParam = shopId ? ('shop_id=' + encodeURIComponent(shopId)) : ('shop_name=' + encodeURIComponent(name || storeKey));
    fetch('api_menu.php?' + queryParam)
      .then(function(r) { return r.json(); })
      .then(function(res) {
        var fetchedItems = (res && (res.items || res.data)) ? (res.items || res.data) : [];
        if (fetchedItems.length) {
          if (typeof dbStoreMenus !== 'undefined' && dbStoreMenus) {
            dbStoreMenus[storeKey] = fetchedItems;
            if (shopId) dbStoreMenus[shopId] = fetchedItems;
          }
          renderStoreDishes(storeKey, fetchedItems);
        }
      })
      .catch(function(e) { console.warn('Could not load menu dynamically:', e); });
  }
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
      card.style.display = 'block';
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
    icon: decodeURIComponent(icon || '🍽️'),
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

let _lastChangeQtyActionTime = 0;
function changeQty(n, event){
  if (event) {
    if (event.preventDefault && event.cancelable) event.preventDefault();
    if (event.stopPropagation) event.stopPropagation();
  }
  const now = Date.now();
  if (now - _lastChangeQtyActionTime < 300) return;
  _lastChangeQtyActionTime = now;

  itemQty = Math.max(1, itemQty + n);
  document.getElementById('itemQty').textContent = itemQty;
}

function addCurrentItem(){
  const count = Math.max(1, parseInt(itemQty, 10) || 1);
  addCart(currentItem.name, currentItem.price, currentItem.icon, currentItem.store, true, count);
  showScreen('cart');
  toast('Added to cart');
}

let _lastAddCartTime = 0;
let _lastAddCartSignature = '';

function addCart(name, price, icon, store='McDonalds', silent=false, qtyToAdd=1, event=null){
  if (event) {
    if (event.preventDefault && event.cancelable) event.preventDefault();
    if (event.stopPropagation) event.stopPropagation();
    if (event.stopImmediatePropagation) event.stopImmediatePropagation();
  }
  if (!name) return;

  const cleanStore = normalizeStoreName(store || 'McDonalds');
  const addQty = Math.max(1, parseInt(qtyToAdd, 10) || 1);
  const now = Date.now();
  const signature = `${name}::${cleanStore}`;

  // Debounce rapid duplicate invocations (touch + ghost click or double event listeners) within 350ms
  if (now - _lastAddCartTime < 350 && _lastAddCartSignature === signature) {
    return;
  }
  _lastAddCartTime = now;
  _lastAddCartSignature = signature;

  let x = cart.find(i => i.name === name && normalizeStoreName(i.store) === cleanStore); 
  if (x) {
    x.qty = (parseInt(x.qty, 10) || 0) + addQty;
    x.store = cleanStore;
  } else {
    // Strictly default initial quantity to 1 (or explicit addQty)
    cart.push({
      name: name,
      price: parseFloat(price) || 0,
      icon: icon || '🍽️',
      qty: addQty,
      store: cleanStore
    });
  }
  saveCart();
  updateBadge();
  if (!silent) toast(name + ' added to cart');
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
            <button type="button" class="btn-qty-minus" onclick="updateCartQty(${index}, -1, event)" aria-label="Decrease quantity"><i class="fa-solid fa-minus"></i></button>
            <span>${item.qty}</span>
            <button type="button" class="btn-qty-plus" onclick="updateCartQty(${index}, 1, event)" aria-label="Increase quantity">+</button>
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

  // Delivery address input starts completely blank on page load per user requirement
  const addrInput = document.getElementById('deliveryAddressInput');
  if (addrInput && !addrInput.dataset.userHasTyped) {
    addrInput.value = '';
  }
}

let _isCartQtyUpdating = false;
let _lastCartQtyActionTime = 0;

function updateCartQty(index, change, event) {
  if (event) {
    if (event.preventDefault && event.cancelable) event.preventDefault();
    if (event.stopPropagation) event.stopPropagation();
    if (event.stopImmediatePropagation) event.stopImmediatePropagation();
  }
  const now = Date.now();
  if (now - _lastCartQtyActionTime < 300 || _isCartQtyUpdating) {
    return;
  }
  _isCartQtyUpdating = true;
  _lastCartQtyActionTime = now;

  try {
    if (cart[index]) {
      cart[index].qty += change;
      if (cart[index].qty <= 0) {
        cart.splice(index, 1);
      }
      saveCart();
      updateBadge();
      renderCart();
    }
  } finally {
    setTimeout(() => {
      _isCartQtyUpdating = false;
    }, 300);
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
    const isIp = source === 'ip';
    const isMap = source === 'map';
    const icon = isGps ? 'fa-satellite-dish' : (isIp ? 'fa-tower-broadcast' : (isMap ? 'fa-map-pin' : 'fa-location-dot'));
    const label = isGps ? 'Exact Live GPS Pin' : (isIp ? 'Approximate Location (IP)' : (isMap ? 'Map Pin Selected' : 'Geocoded Pin'));
    const statusText = `<i class="fa-solid ${icon}"></i> ${label}: ${numLat.toFixed(5)}, ${numLng.toFixed(5)}`;

    // Sync Leaflet map and marker if map exists and source is not direct map dragging
    const activeMap = checkoutMap || (typeof map !== 'undefined' ? map : null);
    const activeMarker = checkoutMarker || (typeof marker !== 'undefined' ? marker : null);

    if (activeMarker && source !== 'map') {
        activeMarker.setLatLng([numLat, numLng]);
    }
    if (activeMap && source !== 'map') {
        const targetZoom = source === 'nominatim' ? 17 : (source === 'ip' ? 15 : Math.max(activeMap.getZoom(), 16));
        activeMap.setView([numLat, numLng], targetZoom);
    }

    ['gpsLocationStatus', 'modalGpsStatus'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.style.display = 'flex';
            el.className = 'gps-status-pill ' + (isIp ? 'info' : 'success');
            el.innerHTML = statusText;
        }
    });
}

// OpenStreetMap Nominatim Geocoding & Granular Address Component Parser
function parseGranularAddressComponents(data) {
    if (!data) return null;
    const addr = data.address || {};

    // 1. Street / Road / Landmark component
    let street = '';
    const houseNumber = addr.house_number || addr.building || addr.house_name || '';
    const road = addr.road || addr.pedestrian || addr.street || addr.footway || addr.path || addr.highway || '';
    const landmark = data.name || addr.amenity || addr.shop || addr.tourism || addr.office || addr.leisure || '';

    if (houseNumber && road) {
        street = `${houseNumber} ${road}`.trim();
    } else if (road) {
        street = road.trim();
    } else if (landmark && landmark !== addr.village && landmark !== addr.suburb && landmark !== addr.city) {
        street = landmark.trim();
    } else {
        street = (addr.residential || addr.suburb || addr.neighbourhood || addr.village || '').trim();
    }

    // 2. Barangay / Subdivision / District component (OSM Philippines structure)
    const rawBrgy = (addr.village || addr.neighbourhood || addr.subdivision || addr.residential || addr.suburb || addr.quarter || addr.city_district || addr.hamlet || '').trim();
    let barangay = rawBrgy;

    const isPrefixed = /^(barangay|brgy\.?|subdivision|subd\.?|purok|sitio|zone|district|village)\b/i.test(barangay);
    const isSuffixed = /(subdivision|subd\.?|village|homes|heights|valley|hills|park|compound|estate)$/i.test(barangay);
    const isDirectionalOnly = /^(north|south|east|west|central)$/i.test(barangay);

    if (barangay && !isPrefixed && !isSuffixed && !isDirectionalOnly) {
        barangay = `Barangay ${barangay}`;
    }

    // 3. City / Municipality component
    let city = (addr.city || addr.town || addr.municipality || addr.county || 'Ormoc City').trim();
    if (!/city$/i.test(city) && city.toLowerCase() === 'ormoc') {
        city = 'Ormoc City';
    }

    // 4. Postal Code & Province
    const postal = (addr.postcode || '6541').trim();
    const province = (addr.state || addr.province || 'Leyte').trim();

    // 5. Build clean, non-redundant formatted string for delivery riders
    const parts = [];
    if (landmark && landmark !== street && landmark !== rawBrgy && landmark !== barangay && landmark !== city) {
        parts.push(landmark);
    }
    if (street) parts.push(street);
    if (barangay && barangay.toLowerCase() !== street.toLowerCase()) parts.push(barangay);
    if (city) parts.push(city);
    if (postal) parts.push(postal);

    const uniqueParts = [];
    const seen = new Set();
    parts.forEach(p => {
        const norm = p.trim().toLowerCase();
        if (norm && !seen.has(norm)) {
            seen.add(norm);
            uniqueParts.push(p.trim());
        }
    });

    const formatted = uniqueParts.join(', ') || data.display_name || '';

    return {
        street: street,
        barangay: barangay,
        rawBarangay: rawBrgy,
        city: city,
        postal: postal,
        province: province,
        landmark: landmark,
        formatted: formatted,
        displayName: data.display_name || formatted
    };
}

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
        // 1. Primary requested endpoint with addressdetails=1 for granular street and barangay components
        const primaryUrl = 'https://nominatim.openstreetmap.org/search?format=jsonv2&addressdetails=1&limit=1&q=' + encodeURIComponent(cleanAddr + ', Ormoc City, Leyte');
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

                // Automatically pan and zoom the Leaflet map to the returned lat and lon
                if (activeMap) {
                    activeMap.setView([lat, lon], 17);
                }
                // Move the draggable marker to that position
                if (activeMarker) {
                    activeMarker.setLatLng([lat, lon]);
                }
                // Update the hidden inputs id and delivery_lng
                setDeliveryCoordinates(lat, lon, 'nominatim');

                const parsed = parseGranularAddressComponents(data[0]);
                const formatted = parsed ? parsed.formatted : data[0].display_name;

                // Populate granular address form fields if present in DOM
                if (parsed) {
                    const st = document.getElementById('newAddrStreet');
                    const bg = document.getElementById('newAddrBarangay');
                    const ct = document.getElementById('newAddrCity');
                    const zp = document.getElementById('newAddrZip');
                    if (st && parsed.street) st.value = parsed.street;
                    if (bg && parsed.barangay) bg.value = parsed.barangay;
                    if (ct && parsed.city) ct.value = parsed.city;
                    if (zp && parsed.postal) zp.value = parsed.postal;
                }

                return { lat, lon, lng: lon, displayName: formatted, components: parsed };
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
            const altUrl = 'https://nominatim.openstreetmap.org/search?format=jsonv2&addressdetails=1&limit=1&q=' + encodeURIComponent(alt);
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

                    const parsed = parseGranularAddressComponents(data[0]);
                    const formatted = parsed ? parsed.formatted : data[0].display_name;

                    if (parsed) {
                        const st = document.getElementById('newAddrStreet');
                        const bg = document.getElementById('newAddrBarangay');
                        const ct = document.getElementById('newAddrCity');
                        const zp = document.getElementById('newAddrZip');
                        if (st && parsed.street) st.value = parsed.street;
                        if (bg && parsed.barangay) bg.value = parsed.barangay;
                        if (ct && parsed.city) ct.value = parsed.city;
                        if (zp && parsed.postal) zp.value = parsed.postal;
                    }

                    return { lat, lon, lng: lon, displayName: formatted, components: parsed };
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

// Local Ormoc City subdivisions, villages, and landmarks directory for instant zero-latency lookup
const ORMOC_LOCAL_LANDMARKS = [
    { name: 'Alta Vista', desc: 'Alta Vista, Ormoc, Leyte', lat: 11.0118026, lon: 124.6203237 },
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
    { name: 'Ipil', desc: 'Barangay Ipil, Ormoc City, Leyte', lat: 10.9754111, lon: 124.6331304 },
    { name: 'Alegria', desc: 'Barangay Alegria, Ormoc City, Leyte', lat: 11.0055905, lon: 124.6038316 },
    { name: 'San Pablo', desc: 'Barangay San Pablo, Ormoc City, Leyte', lat: 11.0150, lon: 124.6050 },
    { name: 'Naungan', desc: 'Barangay Naungan, Ormoc City, Leyte', lat: 10.9980, lon: 124.5820 },
    { name: 'Dolores', desc: 'Barangay Dolores, Ormoc City, Leyte', lat: 11.0800, lon: 124.6300 },
    { name: 'Ormoc City Hall / Plaza', desc: 'Ormoc City Central, Leyte', lat: 11.0090349, lon: 124.6093940 }
];

let mapSearchDebounceTimer = null;
let mapSearchAbortController = null;
let currentMapSearchResults = [];

function handleMapSearchInput(value) {
    const clearBtn = document.getElementById('modalMapSearchClear');
    const resultsContainer = document.getElementById('mapSearchResults');
    const query = (value || '').trim();

    if (clearBtn) {
        clearBtn.style.display = query ? 'block' : 'none';
    }

    if (!query || query.length < 2) {
        if (resultsContainer) resultsContainer.style.display = 'none';
        currentMapSearchResults = [];
        return;
    }

    clearTimeout(mapSearchDebounceTimer);
    mapSearchDebounceTimer = setTimeout(() => {
        searchNominatimAddresses(query);
    }, 280);
}

function handleMapSearchKeydown(event) {
    if (event.key === 'Enter') {
        event.preventDefault();
        executeMapSearch();
    } else if (event.key === 'Escape') {
        const resultsContainer = document.getElementById('mapSearchResults');
        if (resultsContainer) resultsContainer.style.display = 'none';
    }
}

function clearMapSearch() {
    const input = document.getElementById('modalMapSearchInput');
    const clearBtn = document.getElementById('modalMapSearchClear');
    const resultsContainer = document.getElementById('mapSearchResults');
    if (input) {
        input.value = '';
        input.focus();
    }
    if (clearBtn) clearBtn.style.display = 'none';
    if (resultsContainer) resultsContainer.style.display = 'none';
    currentMapSearchResults = [];
}

async function searchNominatimAddresses(query) {
    const resultsContainer = document.getElementById('mapSearchResults');
    if (!resultsContainer) return;

    resultsContainer.style.display = 'block';
    resultsContainer.innerHTML = '<div style="padding:10px 12px; font-size:11px; color:#6b7280; display:flex; align-items:center; gap:8px;"><i class="fa-solid fa-spinner fa-spin" style="color:var(--harvest-sun)"></i> Searching OpenStreetMap...</div>';

    if (mapSearchAbortController) {
        try { mapSearchAbortController.abort(); } catch(e) {}
    }
    mapSearchAbortController = new AbortController();
    const signal = mapSearchAbortController.signal;

    const norm = query.toLowerCase();
    const localMatches = ORMOC_LOCAL_LANDMARKS.filter(item => 
        item.name.toLowerCase().includes(norm) || item.desc.toLowerCase().includes(norm)
    ).map(item => {
        const parsed = parseGranularAddressComponents({
            name: item.name,
            display_name: item.desc,
            address: {
                road: item.name,
                village: item.name,
                city: 'Ormoc City',
                state: 'Leyte',
                postcode: '6541'
            }
        });
        return {
            lat: item.lat,
            lon: item.lon,
            name: item.name,
            display_name: parsed ? parsed.formatted : item.desc,
            components: parsed,
            is_local: true
        };
    });

    const cleanQ = query.trim();
    const augmentedQuery = (!norm.includes('ormoc') && !norm.includes('leyte')) ? `${cleanQ}, Ormoc City, Leyte` : cleanQ;

    try {
        const url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&addressdetails=1&limit=6&q=' + encodeURIComponent(augmentedQuery);
        const res = await fetch(url, {
            headers: { 'Accept': 'application/json' },
            signal: signal
        });

        let osmItems = [];
        if (res.ok) {
            const data = await res.json();
            if (Array.isArray(data)) {
                osmItems = data.map(d => {
                    const parsed = parseGranularAddressComponents(d);
                    const title = parsed ? (parsed.street || parsed.landmark || d.name || parsed.barangay) : (d.name || d.display_name.split(',')[0]);
                    return {
                        lat: parseFloat(d.lat),
                        lon: parseFloat(d.lon),
                        name: title,
                        display_name: (parsed && parsed.formatted) ? parsed.formatted : d.display_name,
                        components: parsed,
                        is_local: false
                    };
                });
            }
        }

        // Combine local fast matches and OSM items, removing duplicate coordinate locations
        const combined = [];
        const seenCoords = new Set();

        [...localMatches, ...osmItems].forEach(item => {
            const key = item.lat.toFixed(4) + '_' + item.lon.toFixed(4);
            if (!seenCoords.has(key)) {
                seenCoords.add(key);
                combined.push(item);
            }
        });

        currentMapSearchResults = combined;

        if (!combined.length) {
            resultsContainer.innerHTML = '<div style="padding:10px 12px; font-size:10.5px; color:#6b7280; text-align:center;"><i class="fa-regular fa-circle-question" style="color:var(--harvest-sun)"></i> No exact street or subdivision found. You can drag the pin on the map.</div>';
            return;
        }

        let html = '';
        combined.forEach((item, index) => {
            const safeName = item.name.replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            const safeDesc = item.display_name.replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            const brgyBadge = item.components && item.components.barangay 
                ? `<span style="font-size:10px; font-weight:600; color:#0369a1; background:#e0f2fe; padding:1px 6px; border-radius:4px; margin-left:4px;">${item.components.barangay.replace(/</g, '&lt;')}</span>` 
                : '';
            html += `
                <div class="map-suggestion-item" data-index="${index}" onclick="selectMapSearchResultByIndex(${index})">
                    <i class="fa-solid fa-location-dot" style="color:var(--harvest-sun); font-size:13px; margin-top:2px;"></i>
                    <div style="flex:1; overflow:hidden;">
                        <div style="display:flex; align-items:center; flex-wrap:wrap; gap:4px;">
                            <strong>${safeName}</strong>
                            ${item.is_local ? '<span style="font-size:8.5px; font-weight:700; color:#15803d; background:#dcfce7; padding:1px 5px; border-radius:4px;">Verified Local</span>' : ''}
                            ${brgyBadge}
                        </div>
                        <small style="text-overflow:ellipsis; overflow:hidden; white-space:nowrap; display:block;">${safeDesc}</small>
                    </div>
                </div>
            `;
        });
        resultsContainer.innerHTML = html;
    } catch(err) {
        if (err.name === 'AbortError') return;
        console.warn('Nominatim geocoder error:', err);
        if (localMatches.length) {
            currentMapSearchResults = localMatches;
            let html = '';
            localMatches.forEach((item, index) => {
                const safeName = item.name.replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                const safeDesc = item.display_name.replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                const brgyBadge = item.components && item.components.barangay 
                    ? `<span style="font-size:10px; font-weight:600; color:#0369a1; background:#e0f2fe; padding:1px 6px; border-radius:4px; margin-left:4px;">${item.components.barangay.replace(/</g, '&lt;')}</span>` 
                    : '';
                html += `
                    <div class="map-suggestion-item" data-index="${index}" onclick="selectMapSearchResultByIndex(${index})">
                        <i class="fa-solid fa-location-dot" style="color:var(--harvest-sun); font-size:13px; margin-top:2px;"></i>
                        <div style="flex:1; overflow:hidden;">
                            <div style="display:flex; align-items:center; flex-wrap:wrap; gap:4px;">
                                <strong>${safeName}</strong>
                                <span style="font-size:8.5px; font-weight:700; color:#15803d; background:#dcfce7; padding:1px 5px; border-radius:4px;">Verified Local</span>
                                ${brgyBadge}
                            </div>
                            <small>${safeDesc}</small>
                        </div>
                    </div>
                `;
            });
            resultsContainer.innerHTML = html;
        } else {
            resultsContainer.innerHTML = '<div style="padding:10px 12px; font-size:10.5px; color:#ef4444; text-align:center;"><i class="fa-solid fa-triangle-exclamation"></i> Search network error. Please drag the pin on the map.</div>';
        }
    }
}

function selectMapSearchResultByIndex(index) {
    if (currentMapSearchResults && currentMapSearchResults[index]) {
        const item = currentMapSearchResults[index];
        selectMapSearchResult(item.lat, item.lon, item.display_name, item.name, item.components);
    }
}

// Helper: apply pinned address to inputs while preserving any specific Purok or custom landmark details the user typed
function applyPinnedAddressToInputs(formattedArea, rawName) {
    const modalAddr = document.getElementById('modalDeliveryAddress');
    const mainAddr = document.getElementById('deliveryAddressInput');
    const currentVal = ((modalAddr ? modalAddr.value : '') || (mainAddr ? mainAddr.value : '')).trim();

    let finalAddress = formattedArea;
    if (currentVal && !currentVal.startsWith('GPS Location') && !currentVal.startsWith('Pinned Location') && !currentVal.startsWith('Approximate') && !currentVal.startsWith('Network Location')) {
        // If the user already typed specific local details (e.g. Purok, Sitio, House/Block No., or Landmark),
        // preserve them and append the pinned area so they don't have to retype
        const hasPurokOrSpecifics = /\b(purok|prk|sitio|unit|house|lot|blk|block|near|tapad|atbang|gate|corner)\b/i.test(currentVal);
        const lowerCurr = currentVal.toLowerCase();
        const lowerArea = formattedArea.toLowerCase();

        if (hasPurokOrSpecifics && !lowerCurr.includes(lowerArea)) {
            finalAddress = `${currentVal}, ${formattedArea}`;
        } else if (hasPurokOrSpecifics) {
            finalAddress = currentVal;
        }
    }

    if (modalAddr) modalAddr.value = finalAddress;
    if (mainAddr) mainAddr.value = finalAddress;
    lastGeocodedAddress = finalAddress.toLowerCase();
    localStorage.setItem('foodDashDeliveryAddress', finalAddress);
}

function selectMapSearchResult(lat, lon, displayName, name, components = null) {
    const input = document.getElementById('modalMapSearchInput');
    const resultsContainer = document.getElementById('mapSearchResults');

    // Retrieve components if not passed directly
    if (!components && currentMapSearchResults && currentMapSearchResults.length) {
        const match = currentMapSearchResults.find(it => Math.abs(it.lat - lat) < 0.0001 && Math.abs(it.lon - lon) < 0.0001);
        if (match && match.components) components = match.components;
    }

    const formatted = (components && components.formatted) ? components.formatted : (displayName || name);

    if (input) input.value = name || formatted;
    if (resultsContainer) resultsContainer.style.display = 'none';

    const activeMap = checkoutMap || (typeof map !== 'undefined' ? map : null);
    const activeMarker = checkoutMarker || (typeof marker !== 'undefined' ? marker : null);

    if (activeMap) {
        activeMap.setView([lat, lon], 17);
        setTimeout(() => { if (activeMap) activeMap.invalidateSize(); }, 150);
    }
    if (activeMarker) {
        activeMarker.setLatLng([lat, lon]);
    }

    setDeliveryCoordinates(lat, lon, 'nominatim');

    // Populate general area while preserving any user-typed Purok/landmark
    applyPinnedAddressToInputs(formatted, name);

    // Sync granular address form fields if present in DOM
    if (components) {
        const st = document.getElementById('newAddrStreet');
        const bg = document.getElementById('newAddrBarangay');
        const ct = document.getElementById('newAddrCity');
        const zp = document.getElementById('newAddrZip');
        if (st && components.street) st.value = components.street;
        if (bg && components.barangay) bg.value = components.barangay;
        if (ct && components.city) ct.value = components.city;
        if (zp && components.postal) zp.value = components.postal;
    }

    const pill = document.getElementById('modalGpsStatus');
    if (pill) {
        pill.style.display = 'flex';
        pill.className = 'gps-status-pill success';
        const brgyLabel = components && components.barangay ? ` • ${components.barangay}` : '';
        pill.innerHTML = `<i class="fa-solid fa-circle-check"></i> Pinned Area: ${lat.toFixed(5)}, ${lon.toFixed(5)} • ${name}${brgyLabel} <span style="font-size:10px; opacity:0.85; margin-left:4px;">(Editable below)</span>`;
    }

    toast(`Area pinned: ${name}! You can add your Purok/details below.`);
}

async function executeMapSearch() {
    const input = document.getElementById('modalMapSearchInput');
    const query = (input ? input.value : '').trim();
    if (!query) {
        toast('Please enter a street, subdivision, or landmark to search');
        if (input) input.focus();
        return;
    }

    if (currentMapSearchResults && currentMapSearchResults.length > 0) {
        const top = currentMapSearchResults[0];
        selectMapSearchResult(top.lat, top.lon, top.display_name, top.name);
        return;
    }

    const btn = document.getElementById('modalMapSearchBtn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
    }

    try {
        await searchNominatimAddresses(query);
        if (currentMapSearchResults && currentMapSearchResults.length > 0) {
            const top = currentMapSearchResults[0];
            selectMapSearchResult(top.lat, top.lon, top.display_name, top.name);
        }
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-search"></i> Search';
        }
    }
}

// Close suggestions dropdown when clicking outside
document.addEventListener('click', function(e) {
    const wrapper = document.querySelector('.map-search-wrapper');
    const results = document.getElementById('mapSearchResults');
    if (results && wrapper && !wrapper.contains(e.target)) {
        results.style.display = 'none';
    }
});

// Handle typing in delivery address inputs: fully editable, unlocked, and free to type Purok/landmarks
function handleAddressTyping(value, isModal = false) {
    const address = value || '';
    const mainAddr = document.getElementById('deliveryAddressInput');
    const modalAddr = document.getElementById('modalDeliveryAddress');
    if (mainAddr) mainAddr.dataset.userHasTyped = 'true';
    if (modalAddr) modalAddr.dataset.userHasTyped = 'true';

    // Sync other address field seamlessly without locking or interfering with user's typing
    const otherAddr = document.getElementById(isModal ? 'deliveryAddressInput' : 'modalDeliveryAddress');
    if (otherAddr && otherAddr.value !== address) {
        otherAddr.value = address;
    }

    if (address.trim()) {
        localStorage.setItem('foodDashDeliveryAddress', address.trim());
    } else {
        localStorage.removeItem('foodDashDeliveryAddress');
    }

    // Cancel debounce timer so background geocoder never interrupts or overwrites user's typing
    clearTimeout(addressGeocodeDebounceTimer);
}

// When user finishes typing (blur or change): fully unlocked, no aggressive API queries overriding user's text
function handleAddressBlur(value, isModal = false) {
    clearTimeout(addressGeocodeDebounceTimer);
    const address = (value || '').trim();
    if (address) {
        localStorage.setItem('foodDashDeliveryAddress', address);
    }
}

// Helper: Reverse-geocode coordinates to street address with granular parsing (optional helper on pin drag)
async function reverseGeocodeToAddress(lat, lng) {
    try {
        const res = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&lat=${lat}&lon=${lng}`, {
            headers: { 'Accept': 'application/json' }
        });
        if (res.ok) {
            const data = await res.json();
            if (data) {
                const parsed = parseGranularAddressComponents(data);
                const formatted = (parsed && parsed.formatted) ? parsed.formatted : data.display_name;
                applyPinnedAddressToInputs(formatted, data.name || '');

                // Sync granular address form fields if present in DOM
                if (parsed) {
                    const st = document.getElementById('newAddrStreet');
                    const bg = document.getElementById('newAddrBarangay');
                    const ct = document.getElementById('newAddrCity');
                    const zp = document.getElementById('newAddrZip');
                    if (st && parsed.street && !st.value.trim()) st.value = parsed.street;
                    if (bg && parsed.barangay && !bg.value.trim()) bg.value = parsed.barangay;
                    if (ct && parsed.city && (!ct.value.trim() || ct.value === 'Ormoc City')) ct.value = parsed.city;
                    if (zp && parsed.postal && (!zp.value.trim() || zp.value === '6541')) zp.value = parsed.postal;
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

// Default location fallback strictly centered on Alta Vista, Ormoc City (no network IP lookup that resolves to Tanjay)
async function fetchApproximateIpLocation() {
    return {
        lat: 11.0134,
        lng: 124.6199,
        city: 'Ormoc City',
        region: 'Leyte',
        postal: '6541',
        label: 'Alta Vista, Ormoc City',
        source: 'ormoc_alta_vista'
    };
}

// "Use My Exact Location" / "Locate Me" Button Action
async function useMyExactLocation() {
    if (window._isLocatingGps) return;
    window._isLocatingGps = true;

    const btnModal = document.getElementById('modalBtnLocateMe');
    const btnDetect = document.getElementById('btnDetectGps');
    const statusPillIds = ['modalGpsStatus', 'gpsLocationStatus'];

    function setButtonsLoading(isLoading) {
        if (btnModal) {
            btnModal.disabled = isLoading;
            btnModal.innerHTML = isLoading 
                ? '<i class="fa-solid fa-spinner fa-spin"></i> Locating...' 
                : '<i class="fa-solid fa-location-crosshairs"></i> Use My Exact Location';
        }
        if (btnDetect) {
            btnDetect.disabled = isLoading;
            btnDetect.innerHTML = isLoading 
                ? '<i class="fa-solid fa-spinner fa-spin"></i> Locating...' 
                : '<i class="fa-solid fa-crosshairs"></i> Locate Me';
        }
    }

    function setStatusPill(state, html) {
        statusPillIds.forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.style.display = 'flex';
                el.className = 'gps-status-pill ' + state;
                el.innerHTML = html;
            }
        });
    }

    setButtonsLoading(true);
    setStatusPill('loading', '<i class="fa-solid fa-satellite-dish fa-spin"></i> Detecting location via device GPS...');

    async function handleIpFallback(reasonMsg) {
        const lat = 11.0134;
        const lng = 124.6199;

        if (checkoutMap) {
            checkoutMap.setView([lat, lng], 15);
        }
        if (checkoutMarker) {
            checkoutMarker.setLatLng([lat, lng]);
        }

        setDeliveryCoordinates(lat, lng, 'manual');
        await reverseGeocodeToAddress(lat, lng);

        setStatusPill('info', '<i class="fa-solid fa-map-pin"></i> Alta Vista, Ormoc City');
        toast((reasonMsg ? reasonMsg + ' ' : '') + 'Centered strictly on Alta Vista, Ormoc City. Drag pin to adjust.', 'info');
    }

    // Check if browser supports Geolocation API
    if (!navigator.geolocation) {
        await handleIpFallback('Browser Geolocation restricted on local HTTP.');
        setButtonsLoading(false);
        window._isLocatingGps = false;
        return;
    }

    let gpsResolved = false;
    // Safety timer in case browser geolocation hangs on non-secure contexts
    const safetyTimer = setTimeout(async () => {
        if (!gpsResolved) {
            gpsResolved = true;
            console.warn('navigator.geolocation safety timer triggered, falling back to IP geolocation.');
            await handleIpFallback('GPS timed out.');
            setButtonsLoading(false);
            window._isLocatingGps = false;
        }
    }, 8500);

    try {
        navigator.geolocation.getCurrentPosition(
            async (position) => {
                if (gpsResolved) return;
                gpsResolved = true;
                clearTimeout(safetyTimer);

                const lat = position.coords.latitude || position.coords.lat;
                const lng = position.coords.longitude || position.coords.lng;

                if (checkoutMap) {
                    checkoutMap.setView([lat, lng], 16);
                }
                if (checkoutMarker) {
                    checkoutMarker.setLatLng([lat, lng]);
                }

                setDeliveryCoordinates(lat, lng, 'gps');
                await reverseGeocodeToAddress(lat, lng);

                toast('Pinned to your exact device location!');
                setButtonsLoading(false);
                window._isLocatingGps = false;
            },
            async (error) => {
                if (gpsResolved) return;
                gpsResolved = true;
                clearTimeout(safetyTimer);

                let reason = 'GPS unavailable.';
                if (error.code === error.PERMISSION_DENIED) {
                    reason = 'GPS permission denied or restricted on HTTP.';
                } else if (error.code === error.TIMEOUT) {
                    reason = 'GPS request timed out.';
                } else if (error.code === error.POSITION_UNAVAILABLE) {
                    reason = 'GPS satellite signal unavailable.';
                }

                console.warn('navigator.geolocation error (' + reason + '), seamlessly falling back to IP geolocation...');
                await handleIpFallback(reason);
                setButtonsLoading(false);
                window._isLocatingGps = false;
            },
            { enableHighAccuracy: true, timeout: 7000, maximumAge: 30000 }
        );
    } catch (e) {
        if (!gpsResolved) {
            gpsResolved = true;
            clearTimeout(safetyTimer);
            console.warn('Exception during navigator.geolocation:', e);
            await handleIpFallback('GPS unavailable on this network.');
            setButtonsLoading(false);
            window._isLocatingGps = false;
        }
    }
}

// Live GPS detection backward compatibility
function detectCustomerGps(isModal = false) {
    useMyExactLocation();
}

// Geolocation only triggers on-demand when user explicitly clicks 'Locate Me' or 'Pin on Map'.
// Automatic IP/GPS geolocation on startup is strictly disabled per requirement.
function requestLiveGpsIfAllowed() {
    // Disabled on startup. Geolocation runs solely on user interaction.
}

function openAddressPromptModal() {
    const mainAddr = document.getElementById('deliveryAddressInput');
    const modalAddr = document.getElementById('modalDeliveryAddress');
    if (mainAddr && modalAddr) {
        modalAddr.value = mainAddr.value.trim();
    }
    const modal = document.getElementById('addressPromptModal');
    if (modal) modal.classList.add('show');
    
    // Initialize or refresh the interactive Leaflet map pin picker
    initCheckoutMap();
    setupAddressInputListeners();

    const searchInput = document.getElementById('modalMapSearchInput');
    const clearBtn = document.getElementById('modalMapSearchClear');
    const resultsContainer = document.getElementById('mapSearchResults');
    if (resultsContainer) resultsContainer.style.display = 'none';
    if (searchInput) {
        searchInput.value = '';
        if (clearBtn) clearBtn.style.display = 'none';
    }

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
    openCustomerPaymentConfirmationModal();
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

async function executeOrderSubmission() {
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
    });
}
const checkout = executeOrderSubmission;

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

function escapeCustHtml(str) {
  return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function escapeCustAttr(str) {
  return String(str || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

async function loadCustomerOrdersLive() {
  try {
    const res = await fetch(`order_api.php?action=get_orders&_t=${Date.now()}`, { cache: 'no-store' });
    const data = await res.json();
    if (!data.success || !Array.isArray(data.orders)) return;

    const orders = data.orders;
    const listEl = document.getElementById('orderList');
    if (!listEl) return;

    // Check if active delivery exists to update banner
    let activeDeliveryOrder = null;
    let onTheWayCount = 0;

    orders.forEach(ord => {
      const st = String(ord.status || '').toUpperCase().replace(/[ -]/g, '_');
      const delSt = String(ord.delivery_status || '').toUpperCase().replace(/[ -]/g, '_');
      if (['OUT_FOR_DELIVERY', 'ON_THE_WAY', 'ON_DELIVERY', 'NEAR_CUSTOMER', 'ARRIVED', 'ARRIVED_DESTINATION'].includes(st) ||
          ['OUT_FOR_DELIVERY', 'ON_THE_WAY', 'ON_DELIVERY', 'NEAR_CUSTOMER', 'ARRIVED', 'ARRIVED_DESTINATION'].includes(delSt)) {
        onTheWayCount++;
        if (!activeDeliveryOrder) activeDeliveryOrder = ord;
      }
    });

    // Top banner removed per UX specifications
    const banner = document.getElementById('dynamicOnTheWayBanner');
    if (banner) {
      banner.style.display = 'none';
      banner.remove();
    }

    if (orders.length === 0) {
      listEl.innerHTML = `
        <div class="empty-state-card" id="noOrders" style="text-align: center; padding: 36px 18px; background: #ffffff; border-radius: 14px; margin: 12px 0; border: 1.5px dashed #cbd5e1; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
          <div style="font-size: 42px; margin-bottom: 10px; line-height: 1;">🛍️</div>
          <strong style="display: block; font-size: 16px; color: var(--olive-dark, #2f341d); margin-bottom: 6px;">No orders placed yet!</strong>
          <p style="font-size: 12px; color: var(--muted, #64748b); margin-bottom: 16px; max-width: 280px; margin-left: auto; margin-right: auto; line-height: 1.4;">
            Your order history is clean. Discover our partner stores and order delicious meals today!
          </p>
          <button type="button" class="btn-browse-stores-main" onclick="showScreen('stores')" style="display: inline-flex; align-items: center; gap: 8px; background: var(--harvest-sun, #c5a059); color: #ffffff; padding: 10px 22px; border-radius: 10px; font-weight: 700; border: none; cursor: pointer; box-shadow: 0 4px 12px rgba(197, 160, 89, 0.35);">
            <i class="fa-solid fa-store"></i> Browse Stores
          </button>
        </div>`;
      return;
    }

    // Determine currently active filter chip
    const activeChip = document.querySelector('#orderFilters .chip.active');
    const currentFilter = activeChip ? (activeChip.getAttribute('onclick') || '').replace(/.*filterOrders\('([^']+)'[^\)]*\).*/, '$1') || 'all' : 'all';

    let html = '';
    orders.forEach(ord => {
      const raw_status = String(ord.status || '').toUpperCase().replace(/[ -]/g, '_');
      const del_status = String(ord.delivery_status || '').toLowerCase().trim();
      const status_lower = String(ord.status || '').toLowerCase().trim();

      const is_arrived = (['ARRIVED', 'ARRIVED_DESTINATION'].includes(raw_status) || ['arrived', 'arrived_destination'].includes(del_status) || ['arrived', 'arrived_destination'].includes(status_lower));
      const is_near = (raw_status === 'NEAR_CUSTOMER' || del_status === 'near_customer' || status_lower === 'near_customer');
      const is_ontheway = ['OUT_FOR_DELIVERY', 'ON_THE_WAY', 'ON_DELIVERY'].includes(raw_status)
                  || ['on_the_way', 'on_delivery', 'picked_up'].includes(del_status)
                  || ['out_for_delivery', 'on_the_way', 'on_delivery'].includes(status_lower)
                  || is_near || is_arrived;

      const is_delivered = (raw_status === 'DELIVERED' || raw_status === 'COMPLETED' || del_status === 'delivered' || status_lower === 'delivered' || status_lower === 'completed');
      const is_settled = (raw_status === 'SETTLED' || status_lower === 'settled');
      const is_prep = ['PREPARING', 'ACCEPTED', 'COOKING', 'IN_KITCHEN', 'PREP'].includes(raw_status)
                  || ['preparing', 'accepted', 'cooking', 'in_kitchen', 'prep'].includes(status_lower)
                  || ['preparing', 'cooking', 'in_kitchen', 'accepted'].includes(del_status);
      const is_pending = ['PENDING', 'SUBMITTED'].includes(raw_status) || ['pending', 'submitted'].includes(status_lower);
      const is_cancelled = (raw_status === 'CANCELLED' || del_status === 'cancelled' || status_lower === 'cancelled');
      const can_cancel = (is_pending || is_prep) && !is_ontheway && !is_delivered && !is_cancelled;

      let status_label = '';
      let norm_status = '';
      if (is_arrived) {
        status_label = 'Arrived';
        norm_status = 'arrived';
      } else if (is_near) {
        status_label = 'Near Customer';
        norm_status = 'near_customer';
      } else if (is_ontheway) {
        status_label = 'On Delivery';
        norm_status = 'on_delivery';
      } else if (raw_status === 'READY_FOR_PICKUP' || status_lower === 'ready_for_pickup' || status_lower === 'ready') {
        status_label = 'Ready for Pickup';
        norm_status = 'ready_for_pickup';
      } else if (is_prep) {
        status_label = 'Preparing';
        norm_status = 'preparing';
      } else if (is_pending) {
        status_label = 'Pending';
        norm_status = 'pending';
      } else {
        status_label = raw_status.replace(/_/g, ' ').toLowerCase().replace(/\b\w/g, c => c.toUpperCase());
        norm_status = raw_status.toLowerCase();
      }

      const clean_card_ref = '#' + String(ord.order_ref || ('FD-' + (ord.order_id || ord.id))).replace(/^#+/, '');
      const storeNameSafe = escapeCustHtml(ord.store_name || 'Store');
      const itemsSummarySafe = escapeCustHtml(ord.items_summary || 'Food Dash Items');
      const totalAmountNum = parseFloat(ord.total_amount || ord.total_price || 0).toFixed(2);
      const formattedTotal = '₱' + Number(totalAmountNum).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
      const orderDateStr = ord.created_at ? new Date(ord.created_at).toLocaleString('en-US', { month: 'short', day: '2-digit', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true }) : '';
      const orderId = parseInt(ord.order_id || ord.id, 10);
      const paymentMethod = ord.payment_method || 'Cash on Delivery (COD)';

      let iconHtml = '🛍️';
      let iconClass = 'green';
      if (is_cancelled) { iconHtml = '🚫'; iconClass = 'grey'; }
      else if (is_ontheway) { iconHtml = '🛵'; iconClass = 'jolli'; }
      else if (is_settled) { iconHtml = '🛡️'; iconClass = 'settled-icon'; }
      else if (is_delivered) { iconHtml = '📦'; iconClass = 'green'; }
      else if (is_prep) { iconHtml = '🍳'; iconClass = 'green'; }

      let actionsHtml = '';
      if (can_cancel) {
        actionsHtml = `
          <div style="margin-top:8px;" id="cancel-wrap-${orderId}" class="order-actions-wrap">
            <button type="button" class="btn-cancel-order" id="btn-cancel-${orderId}" onclick="event.stopPropagation(); promptCancelOrder(${orderId}, '${escapeCustAttr(clean_card_ref)}', '${escapeCustAttr(status_label)}')">
              <i class="fa-solid fa-ban"></i> Cancel Order
            </button>
          </div>`;
      } else if (is_cancelled) {
        actionsHtml = `
          <div style="font-size: 12px; color: #dc2626; font-weight:700; margin-top:6px;">
            <i class="fa-solid fa-circle-xmark"></i> Cancelled by Customer
          </div>`;
      }

      if (is_ontheway || is_near || is_arrived) {
        let subMsg = '';
        if (is_arrived) subMsg = '<i class="fa-solid fa-bell fa-shake"></i> Rider has arrived at your location!';
        else if (is_near) subMsg = '<i class="fa-solid fa-person-biking fa-beat"></i> Rider is near your location (&lt; 200m)!';
        
        actionsHtml += `
          <div style="font-size: 12px; color: var(--harvest-sun); font-weight:600; margin-top:6px;">
            ${subMsg}
          </div>
          <div id="track-actions-${orderId}" class="order-actions-wrap" style="display:flex; gap:6px; margin-top:8px; flex-wrap:wrap;">
            <button type="button" class="cust-track-btn" onclick="event.stopPropagation(); openTrackingModal(${orderId}, { orderId: ${orderId}, orderNum: '${escapeCustAttr(clean_card_ref)}', storeName: '${escapeCustAttr(ord.store_name || '')}', storeLat: '11.0062', storeLng: '124.6068', itemsSummary: '${escapeCustAttr(ord.items_summary || '')}', deliveryAddress: '${escapeCustAttr(ord.delivery_address || '')}', totalAmount: '${escapeCustAttr(ord.total_amount || ord.total_price || '')}', orderStatus: '${escapeCustAttr(norm_status)}' })" title="Tap to view live delivery map">
              <i class="fa-solid fa-location-dot"></i> 📍 Live Track Rider
            </button>
            <button class="confirm-btn" id="confirm-btn-${orderId}" onclick="event.stopPropagation(); confirmOrderReceived(${orderId})">
              <i class="fa-solid fa-box-open"></i> 📦 Received
            </button>
          </div>`;
      } else if (is_delivered && !is_settled) {
        actionsHtml += `
          <div style="font-size: 12px; color: var(--sagebrush); font-weight:600; margin-top:6px;">
            <i class="fa-solid fa-circle-check"></i> Order Delivered. Awaiting admin settlement.
          </div>`;
      }

      const rowClick = `onclick="openTrackingModal(${orderId}, { orderId: ${orderId}, orderNum: '${escapeCustAttr(clean_card_ref)}', storeName: '${escapeCustAttr(ord.store_name || '')}', storeLat: '11.0062', storeLng: '124.6068', itemsSummary: '${escapeCustAttr(ord.items_summary || '')}', deliveryAddress: '${escapeCustAttr(ord.delivery_address || '')}', totalAmount: '${escapeCustAttr(ord.total_amount || ord.total_price || '')}', orderStatus: '${escapeCustAttr(norm_status)}', deliveryLat: '${ord.delivery_lat || ord.customer_lat || ''}', deliveryLng: '${ord.delivery_lng || ord.customer_lng || ''}', riderName: '${escapeCustAttr(ord.rider_name || '')}', riderPhone: '${escapeCustAttr(ord.rider_phone || '')}' })" style="cursor:pointer;" title="Tap to view live delivery tracking map"`;

      html += `
        <div class="row-card order-row ${is_ontheway ? 'on-the-way-card' : ''}" 
             data-status="${escapeCustAttr(norm_status)}" 
             data-order-id="${orderId}"
             data-order-ref="${escapeCustAttr(clean_card_ref)}"
             data-store-name="${escapeCustAttr(ord.store_name || '')}"
             data-items-summary="${escapeCustAttr(ord.items_summary || '')}"
             data-delivery-address="${escapeCustAttr(ord.delivery_address || '')}"
             data-total-amount="${escapeCustAttr(ord.total_amount || ord.total_price || '')}"
             data-delivery-lat="${ord.delivery_lat || ord.customer_lat || ''}"
             data-delivery-lng="${ord.delivery_lng || ord.customer_lng || ''}"
             data-store-lat="11.0062"
             data-store-lng="124.6068"
             data-rider-name="${escapeCustAttr(ord.rider_name || '')}"
             data-rider-phone="${escapeCustAttr(ord.rider_phone || '')}"
             id="order-card-${orderId}" ${rowClick}>
          <div class="row-icon ${iconClass}">${iconHtml}</div>
          <div class="info">
            <strong class="order-ref">${escapeCustHtml(clean_card_ref)}</strong>
            <small class="order-store-items">${storeNameSafe} • ${itemsSummarySafe}</small>
            <small class="order-meta order-date-price">${escapeCustHtml(orderDateStr)} • ${formattedTotal}</small>
            ${ord.delivery_address ? `<small class="order-meta order-address" style="color: #64748b; font-weight: 500; font-size: 12px; margin-top: 2px; white-space: normal; line-height: 1.35;"><i class="fa-solid fa-location-dot" style="color:var(--harvest-sun)"></i> ${escapeCustHtml(ord.delivery_address)}</small>` : ''}
            <div style="margin-top: 8px; display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
              <span class="status-badge status-${escapeCustAttr(norm_status)}" id="status-tag-${orderId}">
                ${is_arrived ? '<i class="fa-solid fa-bell fa-shake"></i> ' : (is_near ? '<i class="fa-solid fa-person-biking fa-beat"></i> ' : '')}${escapeCustHtml(status_label)}
              </span>
              <span class="status-badge" style="background:#f1f5f9; color:#475569; font-size:11px;">
                <i class="fa-solid fa-credit-card"></i> ${escapeCustHtml(paymentMethod)}
              </span>
              ${is_settled ? '<span class="settled-tag"><i class="fa-solid fa-circle-check"></i> Reconciled</span>' : ''}
            </div>
            ${actionsHtml}
          </div>
        </div>`;
    });

    listEl.innerHTML = html;

    // Reapply filter if one was selected
    if (currentFilter && currentFilter !== 'all' && activeChip) {
      filterOrders(currentFilter, activeChip);
    }
  } catch (e) {
    console.warn('loadCustomerOrdersLive error:', e);
  }
}
window.loadCustomerOrdersLive = loadCustomerOrdersLive;

// Lightweight Client-Side Polling Mechanism (every 5 seconds)
// Keeps customer order tracking view and status badges synchronized in real time without manual browser refresh
let _customerLastOrderStates = {};
let _isCustomerPollingRunning = false;

async function pollCustomerOrdersLive() {
  if (_isCustomerPollingRunning) return;
  _isCustomerPollingRunning = true;

  try {
    const res = await fetch(`order_api.php?action=get_orders&_t=${Date.now()}`, {
      cache: 'no-store',
      headers: {
        'Cache-Control': 'no-cache, no-store, must-revalidate',
        'Pragma': 'no-cache'
      }
    });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();
    if (!data.success || !Array.isArray(data.orders)) return;

    const orders = data.orders;

    // Detect status transitions for real-time notifications
    orders.forEach(ord => {
      const oId = parseInt(ord.order_id || ord.id, 10);
      const raw_status = String(ord.status || '').toUpperCase().replace(/[ -]/g, '_');
      const del_status = String(ord.delivery_status || '').toLowerCase().trim();
      const status_lower = String(ord.status || '').toLowerCase().trim();

      const isDelivering = ['OUT_FOR_DELIVERY', 'ON_THE_WAY', 'ON_DELIVERY'].includes(raw_status)
                  || ['on_the_way', 'on_delivery', 'picked_up'].includes(del_status)
                  || ['out_for_delivery', 'on_the_way', 'on_delivery'].includes(status_lower);

      const pickupKey = 'fd_pickup_alert_seen_' + oId;
      const alreadyAlerted = (sessionStorage.getItem(pickupKey) === '1');
      const prevStatus = _customerLastOrderStates[oId];
      const wasDelivering = prevStatus && ['OUT_FOR_DELIVERY', 'ON_THE_WAY', 'ON_DELIVERY'].includes(prevStatus);

      const isArrived = ['ARRIVED', 'ARRIVED_DESTINATION'].includes(raw_status)
                  || ['arrived', 'arrived_destination'].includes(del_status)
                  || ['arrived', 'arrived_destination'].includes(status_lower);

      const arrivedKey = 'fd_arrived_alert_seen_' + oId;
      const modal = document.getElementById('riderArrivedModal');
      const isModalOpen = modal && (modal.style.display === 'flex' || modal.classList.contains('show'));
      const isArrivalAck = (sessionStorage.getItem('fd_arrival_ack_' + oId) === '1') || (sessionStorage.getItem('fd_arrival_acknowledged_all') === '1');

      // Instant Modal Pop-up on Arrival: if status is arrived and not acknowledged, display immediately
      if (isArrived && !isArrivalAck) {
        sessionStorage.setItem(arrivedKey, '1');
        window.displayedNotificationIds.add('arrived_' + oId);

        // Dismiss top notification banner completely upon arrival: ONLY render center arrival modal
        dismissLiveRiderBanner();

        // Prominently trigger arrival popup modal immediately
        if (modal && !isModalOpen) {
          modal.classList.add('show', 'active');
          modal.style.display = 'flex';
          modal.style.visibility = 'visible';
          modal.style.pointerEvents = 'auto';
        }
        if (window.FoodDashCall && typeof FoodDashCall.triggerArrivalAlarm === 'function') {
          FoodDashCall.triggerArrivalAlarm(oId, ord.rider_name || 'Rider');
        } else if (typeof playArrivalAlertSound === 'function') {
          playArrivalAlertSound();
        }

        // Update UI badges and cards to Arrived
        if (typeof updateOrderStatusToArrived === 'function') {
          updateOrderStatusToArrived(oId);
        }

        if (typeof loadNotifications === 'function') loadNotifications();
      } else if (isDelivering && (!alreadyAlerted || (prevStatus && !wasDelivering))) {
        sessionStorage.setItem(pickupKey, '1');
        const cleanRef = '#' + String(ord.order_ref || ('FD-' + oId)).replace(/^#+/, '');
        const totalNum = parseFloat(ord.total_amount || ord.total_price || 0).toFixed(2);
        const formattedTotal = '₱' + Number(totalNum).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        const pickupMsg = `Order ${cleanRef} has been picked up! Total due: ${formattedTotal}`;

        // Top Floating Banner Alert (Structured Notification with Sound)
        showCustomerFloatingBanner('ORDER PICKED UP - READY FOR DELIVERY', pickupMsg, () => {
          if (typeof openTrackingModal === 'function') {
            openTrackingModal(oId);
          } else if (typeof showScreen === 'function') {
            showScreen('orders');
          }
        });

        // Dynamically update notification badge on header
        if (typeof loadNotifications === 'function') loadNotifications();
      } else if (prevStatus && prevStatus !== raw_status && !isDelivering && !isArrived) {
        // Status updated (e.g. Preparing, Ready for Pickup):
        // Clean interface: old floating green toast removed entirely.
        // Dynamically update notification badge on customer dashboard header.
        if (typeof loadNotifications === 'function') loadNotifications();
      }

      _customerLastOrderStates[oId] = raw_status;
    });

    // 1. Update the customer's order list if the element exists in DOM
    const listEl = document.getElementById('orderList');
    if (listEl) {
      loadCustomerOrdersLive();
    }

    // 2. If Customer Tracking Modal is open, update the tracking view dynamically (guarded from duplicate polling)
    const trackingModal = document.getElementById('customerTrackingModal') || document.getElementById('trackingModal');
    if (trackingModal && (trackingModal.classList.contains('show') || trackingModal.style.display === 'flex')) {
      const trackingOrderId = activeCustomerTrackingOrderId || (orders.length > 0 ? parseInt(orders[0].order_id || orders[0].id, 10) : null);
      if (trackingOrderId && !custTrackingTimer) {
        fetchCustomerLiveTracking(trackingOrderId, false);
      }
    }
  } catch (err) {
    console.debug('Order tracking poll error:', err);
  } finally {
    _isCustomerPollingRunning = false;
  }
}
window.pollCustomerOrdersLive = pollCustomerOrdersLive;

// Continuous 500ms ultra-responsive polling loop for instant telemetry sync
setInterval(pollCustomerOrdersLive, 500);

document.addEventListener('DOMContentLoaded', () => {
  setTimeout(pollCustomerOrdersLive, 500);
});

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
          iconDiv.textContent = '🚫';
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

async function useLocationForNewAddress() {
  toast('Detecting location...', 'info');

  async function applyNewAddrPosition(lat, lng, isIp = false, ipInfo = null) {
    updateNewAddrCoords(lat, lng);
    if (addAddressPickerMap) {
      addAddressPickerMap.setView([lat, lng], isIp ? 15 : 16);
      if (addAddressPickerMarker) addAddressPickerMarker.setLatLng([lat, lng]);
    }
    const notice = document.getElementById('newAddrGpsNotice');
    if (notice) {
      notice.innerHTML = `<i class="fa-solid fa-circle-check"></i> <span>${isIp ? 'Network Pin' : 'GPS Pin'}: ${lat.toFixed(5)}, ${lng.toFixed(5)}</span>`;
    }
    toast(isIp ? 'Estimated location via network IP! Drag pin to refine.' : 'Location detected!', 'success');

    const st = document.getElementById('newAddrStreet');
    const bg = document.getElementById('newAddrBarangay');
    const ct = document.getElementById('newAddrCity');
    const zp = document.getElementById('newAddrZip');

    if (isIp && ipInfo) {
      if (ct && (!ct.value || ct.value === 'Ormoc City') && ipInfo.city) ct.value = ipInfo.city;
      if (zp && (!zp.value || zp.value === '6541') && ipInfo.postal) zp.value = ipInfo.postal;
    }

    try {
      const r = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&lat=${lat}&lon=${lng}`);
      const d = await r.json();
      if (d) {
        const parsed = parseGranularAddressComponents(d);
        if (parsed) {
          if (st && parsed.street && !st.value.trim()) st.value = parsed.street;
          if (bg && parsed.barangay && !bg.value.trim()) bg.value = parsed.barangay;
          if (ct && parsed.city && (!ct.value.trim() || ct.value === 'Ormoc City')) ct.value = parsed.city;
          if (zp && parsed.postal && (!zp.value.trim() || zp.value === '6541')) zp.value = parsed.postal;
        } else if (d.address) {
          const road = d.address.road || d.address.pedestrian || d.address.suburb || '';
          const brgy = d.address.neighbourhood || d.address.village || d.address.quarter || '';
          const city = d.address.city || d.address.town || d.address.municipality || '';
          const post = d.address.postcode || '';

          if (st && road && !st.value.trim()) st.value = road;
          if (bg && brgy && !bg.value.trim()) bg.value = brgy;
          if (ct && city && (!ct.value.trim() || ct.value === 'Ormoc City')) ct.value = city;
          if (zp && post && (!zp.value.trim() || zp.value === '6541')) zp.value = post;
        }
      }
    } catch (e) {}
  }

  async function fallbackToIp() {
    toast('GPS unavailable. Centered strictly on Alta Vista, Ormoc City.', 'info');
    await applyNewAddrPosition(11.0134, 124.6199, false);
  }

  if (!navigator.geolocation) {
    await fallbackToIp();
    return;
  }

  let resolved = false;
  const timeoutId = setTimeout(async () => {
    if (!resolved) {
      resolved = true;
      await fallbackToIp();
    }
  }, 7500);

  navigator.geolocation.getCurrentPosition(
    async (pos) => {
      if (resolved) return;
      resolved = true;
      clearTimeout(timeoutId);
      const lat = pos.coords.latitude || pos.coords.lat;
      const lng = pos.coords.longitude || pos.coords.lng;
      await applyNewAddrPosition(lat, lng, false);
    },
    async (err) => {
      if (resolved) return;
      resolved = true;
      clearTimeout(timeoutId);
      console.warn('Geolocation error in new address:', err);
      await fallbackToIp();
    },
    { enableHighAccuracy: true, timeout: 6000 }
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
// Geolocation only runs on-demand when user clicks 'Locate Me' or 'Pin on Map'

// Ensure delivery address input starts completely blank on page load and remove any automatic startup geolocation
function resetDeliveryAddressOnLoad() {
    const mainAddr = document.getElementById('deliveryAddressInput');
    const modalAddr = document.getElementById('modalDeliveryAddress');
    if (mainAddr) {
        mainAddr.value = '';
        delete mainAddr.dataset.userHasTyped;
    }
    if (modalAddr) {
        modalAddr.value = '';
        delete modalAddr.dataset.userHasTyped;
    }

    const latFieldIds = ['id', 'delivery_lat', 'delivery_lng', 'deliveryLatInput', 'deliveryLngInput', 'modal_delivery_lat', 'modal_delivery_lng', 'modalDeliveryLat', 'modalDeliveryLng'];
    latFieldIds.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });

    const gpsPill = document.getElementById('gpsLocationStatus');
    if (gpsPill) gpsPill.style.display = 'none';
    const modalPill = document.getElementById('modalGpsStatus');
    if (modalPill) modalPill.style.display = 'none';
}

// Wire up typing and blur/change geocoding events for delivery address inputs (no duplicate attachments)
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        resetDeliveryAddressOnLoad();
        setupAddressInputListeners();
    });
} else {
    resetDeliveryAddressOnLoad();
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
let activeCustomerTrackingOrderId = <?php echo !empty($active_tracking_order['order_id']) ? (int)$active_tracking_order['order_id'] : 'null'; ?>;
let currentTrackingRiderPhone = '+639175550192';
let currentTrackingRiderName = 'Alex Rider';

// Customer Chat State
let activeCustChatOrderId = null;
let custChatPollTimer = null;
let custLastMessageId = 0;

function openTrackingModal(orderId, meta) {
  var payload = (typeof meta === 'object' && meta !== null) ? Object.assign({}, meta) : {};
  if (orderId && !payload.orderId) payload.orderId = orderId;

  // Extract all metadata dynamically from the clicked card or active row
  var card = (orderId ? document.getElementById('order-card-' + orderId) : null) ||
             (document.activeElement ? document.activeElement.closest('.order-row, [id^="order-card-"]') : null);
  if (card && card.dataset) {
    if (!payload.orderId && card.dataset.orderId) payload.orderId = parseInt(card.dataset.orderId, 10);
    if (!payload.orderNum && card.dataset.orderRef) payload.orderNum = card.dataset.orderRef;
    if (!payload.storeName && card.dataset.storeName) payload.storeName = card.dataset.storeName;
    if (!payload.itemsSummary && card.dataset.itemsSummary) payload.itemsSummary = card.dataset.itemsSummary;
    if (!payload.deliveryAddress && card.dataset.deliveryAddress) payload.deliveryAddress = card.dataset.deliveryAddress;
    if (!payload.totalAmount && card.dataset.totalAmount) payload.totalAmount = card.dataset.totalAmount;
    if (!payload.orderStatus && card.dataset.status) payload.orderStatus = card.dataset.status;
    if (!payload.deliveryLat && card.dataset.deliveryLat) payload.deliveryLat = card.dataset.deliveryLat;
    if (!payload.deliveryLng && card.dataset.deliveryLng) payload.deliveryLng = card.dataset.deliveryLng;
    if (!payload.destinationLat && (card.dataset.destinationLat || card.dataset.deliveryLat)) payload.destinationLat = card.dataset.destinationLat || card.dataset.deliveryLat;
    if (!payload.destinationLng && (card.dataset.destinationLng || card.dataset.deliveryLng)) payload.destinationLng = card.dataset.destinationLng || card.dataset.deliveryLng;
    if (!payload.currentLat && (card.dataset.currentLat || card.dataset.riderLat)) payload.currentLat = card.dataset.currentLat || card.dataset.riderLat;
    if (!payload.currentLng && (card.dataset.currentLng || card.dataset.riderLng)) payload.currentLng = card.dataset.currentLng || card.dataset.riderLng;
    if (!payload.riderName && card.dataset.riderName) payload.riderName = card.dataset.riderName;
    if (!payload.riderPhone && card.dataset.riderPhone) payload.riderPhone = card.dataset.riderPhone;
    if (!payload.storeLat && card.dataset.storeLat) payload.storeLat = card.dataset.storeLat;
    if (!payload.storeLng && card.dataset.storeLng) payload.storeLng = card.dataset.storeLng;
  }

  // If inside Expo mobile app WebView, post message to native bridge and do not open web modal
  payload.storeLat = 11.0062;
  payload.storeLng = 124.6068;

  if (window.ReactNativeWebView && window.ReactNativeWebView.postMessage) {
    payload.type = 'OPEN_TRACKING_MODAL';
    try {
      window.ReactNativeWebView.postMessage(JSON.stringify(payload));
    } catch(e) {}
    return;
  }

  openCustomerTrackingModal(orderId || (payload && payload.orderId));
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

function openCustomerTrackingModal(orderId, meta) {
  if (window.ReactNativeWebView && window.ReactNativeWebView.postMessage) {
    openTrackingModal(orderId, meta);
    return;
  }
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
  if (typeof syncCustomerChatUnreadBadge === 'function') {
    syncCustomerChatUnreadBadge(targetId);
  }
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
  }, 500); // 500ms ultra-responsive real-time tracking loop
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

function animateMarkerAlongRoad(marker, newLat, newLng, durationMs = 700) {
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

// Unified OSRM Driving Routing API matching rider_dashboard.php with high-frequency telemetry sync
async function fetchCustOsrmRoute(startLat, startLng, destLat, destLng) {
  // If we already have road waypoints from OSRM, slice along geometry & compute remaining road distance in 0ms
  if (Array.isArray(currentCustOsrmPath) && currentCustOsrmPath.length > 1) {
    let closestIdx = 0;
    let minD = Infinity;
    for (let i = 0; i < currentCustOsrmPath.length; i++) {
      const pt = currentCustOsrmPath[i];
      const d = calculateHaversineKm(startLat, startLng, pt[0], pt[1]);
      if (d < minD) {
        minD = d;
        closestIdx = i;
      }
    }
    // If rider is close to existing corridor (within ~150 meters), slice and compute remaining distance synchronously
    if (minD < 0.15) {
      const remainingCoords = currentCustOsrmPath.slice(closestIdx);
      if (remainingCoords.length > 0) {
        if (custRoutePolyline) {
          custRoutePolyline.setLatLngs(remainingCoords);
        }
        let totalRemKm = 0;
        for (let i = 0; i < remainingCoords.length - 1; i++) {
          totalRemKm += calculateHaversineKm(remainingCoords[i][0], remainingCoords[i][1], remainingCoords[i+1][0], remainingCoords[i+1][1]);
        }
        updateCustomerEtaBadge(totalRemKm, totalRemKm * 1000);
        return;
      }
    }
  }

  const routeKey = `${startLat.toFixed(4)},${startLng.toFixed(4)}_${destLat.toFixed(4)},${destLng.toFixed(4)}`;
  if (routeKey === lastCustRouteKey && custRoutePolyline) return;
  lastCustRouteKey = routeKey;

  const url = `https://router.project-osrm.org/route/v1/driving/${startLng},${startLat};${destLng},${destLat}?overview=full&geometries=geojson`;
  try {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 4000);
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
  currentCustOsrmPath = fallback;
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

let _isFetchingCustomerTracking = false;
async function fetchCustomerLiveTracking(orderId, isInitial) {
  if (_isFetchingCustomerTracking && !isInitial) return;
  _isFetchingCustomerTracking = true;
  try {
    // Append timestamp and no-cache to fetch fresh live GPS from get_order_status.php (with fallback to order_api.php)
    let data = null;
    try {
      const res = await fetch(`get_order_status.php?order_id=${orderId}&_t=${Date.now()}`, {
        cache: 'no-store',
        headers: {
          'Cache-Control': 'no-cache, no-store, must-revalidate',
          'Pragma': 'no-cache'
        }
      });
      if (res.ok) data = await res.json();
    } catch (e) {}

    if (!data || !data.success) {
      const res = await fetch(`order_api.php?action=get_rider_location&order_id=${orderId}&_t=${Date.now()}`, {
        cache: 'no-store',
        headers: {
          'Cache-Control': 'no-cache, no-store, must-revalidate',
          'Pragma': 'no-cache'
        }
      });
      data = await res.json();
    }
    if (!data || !data.success) {
      console.warn('Tracking API response:', data?.message);
      return;
    }

    const ord = data.order || {};
    // Dynamic Store Location: Fetched dynamically from database row via order_api
    const storeLat = parseFloat(data.store_lat || ord.store_lat);
    const storeLng = parseFloat(data.store_lng || ord.store_lng);
    const destLat = parseFloat(data.destination_lat || data.dest_lat || ord.destination_lat || ord.customer_lat);
    const destLng = parseFloat(data.destination_lng || data.dest_lng || ord.destination_lng || ord.customer_lng);

    // Read high-precision coordinates with fallback aliases
    const riderLat = parseFloat(data.rider_lat ?? data.lat ?? data.lat ?? ord.rider_lat);
    const riderLng = parseFloat(data.rider_lng ?? data.lng ?? data.lng ?? ord.rider_lng);

    // Update Rider info dynamically from database or joined riders table data
    const rawRiderName = String(data.rider_name || ord.rider_name || data.rider?.name || data.rider_fullname || '').trim();
    const isRiderAssigned = Boolean(rawRiderName && rawRiderName.toLowerCase() !== 'delivery partner' && rawRiderName.toLowerCase() !== 'assigned rider' && rawRiderName.toLowerCase() !== 'none' && rawRiderName.toLowerCase() !== 'null');
    currentTrackingRiderName = isRiderAssigned ? rawRiderName : 'Waiting for Rider Assignment';
    const riderPhoneRaw = String(data.rider_phone || ord.rider_phone || data.rider?.phone || '').trim();
    const riderDial = riderPhoneRaw ? riderPhoneRaw.replace(/[^\+0-9]/g, '') : '';
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
    if (subEl) subEl.textContent = `${ord.store_name || data.store_name || 'Store'} • Live GPS Telemetry`;

    const statusBadgeEl = document.getElementById('trackModalStatusBadge');
    if (statusBadgeEl) {
      const rawSt = String(data.status || ord.status || 'ON THE WAY').toUpperCase().replace(/[ -]/g, ' ');
      statusBadgeEl.textContent = rawSt;
    }

    // Detect arrived status & exact state-transition in live tracking modal
    if (!window._customerTrackingLastStates) window._customerTrackingLastStates = {};
    const prevTrackStatus = window._customerTrackingLastStates[orderId];
    const wasTrackArrived = prevTrackStatus && ['ARRIVED', 'ARRIVED_DESTINATION', 'arrived', 'arrived_destination'].includes(prevTrackStatus);

    const ordRawSt = String(ord.status || '').toUpperCase().trim();
    const dataRawSt = String(data.status || '').toUpperCase().trim();
    const ordDelSt = String(ord.delivery_status || '').toLowerCase().trim();
    const dataDelSt = String(data.delivery_status || '').toLowerCase().trim();
    const isLiveArrived = Boolean(data.is_arrived)
                       || ['ARRIVED', 'ARRIVED_DESTINATION'].includes(ordRawSt)
                       || ['ARRIVED', 'ARRIVED_DESTINATION'].includes(dataRawSt)
                       || ['arrived', 'arrived_destination'].includes(ordDelSt)
                       || ['arrived', 'arrived_destination'].includes(dataDelSt);

    const isTrackTransitionToArrived = isLiveArrived && !wasTrackArrived;
    const arrivedAlertKey = 'fd_arrived_alert_seen_' + orderId;
    const isAck = (sessionStorage.getItem('fd_arrival_ack_' + orderId) === '1') || (sessionStorage.getItem('fd_arrival_acknowledged_all') === '1');
    const modal = document.getElementById('riderArrivedModal');
    const isModalOpen = modal && (modal.style.display === 'flex' || modal.classList.contains('show'));

    // Instant Modal Pop-up on Arrival: if status is arrived and not acknowledged, display immediately
    if (isLiveArrived && !isAck) {
      sessionStorage.setItem(arrivedAlertKey, '1');
      window.displayedNotificationIds.add('arrived_' + orderId);

      // Dismiss top notification banner completely upon arrival: ONLY render center arrival modal
      dismissLiveRiderBanner();

      // Prominently trigger arrival popup modal immediately
      if (modal && !isModalOpen) {
        modal.classList.add('show', 'active');
        modal.style.display = 'flex';
        modal.style.visibility = 'visible';
        modal.style.pointerEvents = 'auto';
      }
      if (window.FoodDashCall && typeof FoodDashCall.triggerArrivalAlarm === 'function') {
        FoodDashCall.triggerArrivalAlarm(orderId, currentTrackingRiderName || 'Rider');
      } else if (typeof playArrivalAlertSound === 'function') {
        playArrivalAlertSound();
      }

      updateOrderStatusToArrived(orderId);
    }
    window._customerTrackingLastStates[orderId] = data.delivery_status || ord.delivery_status || data.status || ord.status || 'unknown';

    const destAddrEl = document.getElementById('custTrackDestAddress');
    if (destAddrEl) {
      destAddrEl.textContent = ord.delivery_address || data.delivery_address || 'Delivery Destination';
    }

    // Update Distance / ETA text & badge (e.g. "500m away • ~3 mins")
    let dKm = (data.distance_km !== null && !isNaN(data.distance_km)) ? parseFloat(data.distance_km) : null;
    if (dKm === null && !isNaN(destLat) && !isNaN(destLng) && !isNaN(riderLat) && !isNaN(riderLng) && riderLat !== 0) {
      dKm = calculateHaversineKm(riderLat, riderLng, destLat, destLng);
    }
    updateCustomerEtaBadge(dKm);

    // Check for active incoming call from rider
    if (data.active_call && (data.active_call.status === 'ringing' || data.active_call.call_status === 'ringing') && (data.active_call.caller === 'rider' || data.active_call.caller_role === 'rider')) {
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
  } finally {
    _isFetchingCustomerTracking = false;
  }
}

function renderCustomerMap(opts) {
  const mapContainer = document.getElementById('cust-tracking-map');
  if (!mapContainer || typeof L === 'undefined') return;

  // Ormoc City default center
  const defaultCenter = [11.00938, 124.60929];

  const validStore = !isNaN(opts.storeLat) && !isNaN(opts.storeLng) && opts.storeLat !== 0;
  const validDest = !isNaN(opts.destLat) && !isNaN(opts.destLng) && opts.destLat !== 0;
  const validRider = !isNaN(opts.riderLat) && !isNaN(opts.riderLng) && opts.riderLat !== 0;

  // Requirement: Ensure map initializes and centers on rider's active coordinates or Ormoc City
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

    // Staggered size invalidation to guarantee full tile rendering
    [30, 100, 250, 500, 1000, 1800].forEach(delay => {
      setTimeout(() => { if (custTrackingMap) custTrackingMap.invalidateSize(); }, delay);
    });
  } else {
    window.custTrackingMap = custTrackingMap;
    window.trackingMap = custTrackingMap;
    window.map = custTrackingMap;
    setTimeout(() => { if (custTrackingMap) custTrackingMap.invalidateSize(); }, 50);
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
      animateMarkerAlongRoad(custRiderMarker, opts.riderLat, opts.riderLng, 850);
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
async function syncCustomerChatUnreadBadge(orderId) {
  if (!orderId) return;
  const modal = document.getElementById('customerChatModal');
  const isChatOpen = modal && modal.classList.contains('show');
  if (isChatOpen) {
    unreadChatCount = 0;
    updateChatBadges();
    return;
  }

  try {
    const res = await fetch(`chat_api.php?order_id=${orderId}&_t=${Date.now()}`, { cache: 'no-store' });
    if (!res.ok) return;
    const data = await res.json();
    if (data.success && Array.isArray(data.messages)) {
      const lastReadId = parseInt(sessionStorage.getItem('fd_chat_last_read_' + orderId) || '0', 10);
      const unreadList = data.messages.filter(m => m.sender_role !== 'customer' && m.message_id > lastReadId);
      unreadChatCount = unreadList.length;
      updateChatBadges();
    }
  } catch (e) {}
}

function openCustomerChat(explicitOrderId = null) {
  let orderId = explicitOrderId || activeCustomerTrackingOrderId || <?php echo !empty($active_tracking_order['order_id']) ? (int)$active_tracking_order['order_id'] : '0'; ?>;
  if (!orderId && window._customerLastOrderStates) {
    const keys = Object.keys(window._customerLastOrderStates);
    if (keys.length > 0) {
      orderId = parseInt(keys[keys.length - 1], 10);
    }
  }
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
  if (titleEl) titleEl.textContent = `Chat with ${currentTrackingRiderName || 'Rider'}`;
  const phoneEl = document.getElementById('custChatHeaderPhone');
  if (phoneEl) phoneEl.textContent = currentTrackingRiderPhone || '';

  updateCustChatSmsLink('');

  const modal = document.getElementById('customerChatModal');
  if (modal) {
    modal.style.display = 'flex';
    modal.style.visibility = 'visible';
    modal.style.pointerEvents = 'auto';
    modal.classList.add('show');
  }

  const container = document.getElementById('custChatMessagesList');
  if (container) {
    container.innerHTML = '<div class="chat-loading-hint" style="padding:24px 16px;text-align:center;color:#64748b;font-size:12px;font-weight:600;"><i class="fa-solid fa-spinner fa-spin" style="margin-right:6px;color:var(--harvest-sun);"></i> Loading conversation with your rider...</div>';
  }

  fetchCustomerMessages(orderId, true);

  if (custChatPollTimer) clearInterval(custChatPollTimer);
  custChatPollTimer = setInterval(() => {
    if (activeCustChatOrderId) {
      fetchCustomerMessages(activeCustChatOrderId, false);
    }
  }, 1500);
}

function closeCustomerChat() {
  const modal = document.getElementById('customerChatModal');
  if (modal) {
    modal.style.display = 'none';
    modal.style.visibility = 'hidden';
    modal.style.pointerEvents = 'none';
    modal.classList.remove('show');
  }
  if (custChatPollTimer) {
    clearInterval(custChatPollTimer);
    custChatPollTimer = null;
  }
  if (activeCustChatOrderId && custLastMessageId > 0) {
    sessionStorage.setItem('fd_chat_last_read_' + activeCustChatOrderId, custLastMessageId);
  }
  activeCustChatOrderId = null;
  unreadChatCount = 0;
  updateChatBadges();
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

  const validMessages = Array.isArray(messages) ? messages : [];

  if (validMessages.length > 0) {
    // Remove loading placeholder or empty hint
    const hints = container.querySelectorAll('.chat-empty-hint, .chat-loading-hint');
    hints.forEach(h => h.remove());

    let hasNew = false;
    validMessages.forEach(msg => {
      const existing = container.querySelector(`[data-msg-id="${msg.message_id}"]`);
      if (!existing) {
        if (msg.message_id > custLastMessageId) {
          custLastMessageId = msg.message_id;
        }
        hasNew = true;
        const isMe = (msg.sender_role === 'customer');
        const bubble = document.createElement('div');
        bubble.className = `msg-bubble ${isMe ? 'me' : 'them'}`;
        bubble.setAttribute('data-msg-id', msg.message_id);
        bubble.innerHTML = `
          <div>${escapeCustHtml(msg.message_text)}</div>
          <div class="msg-meta">${escapeCustHtml(msg.sender_name || 'Rider')} • ${escapeCustHtml(msg.time_formatted || '')}</div>
        `;
        container.appendChild(bubble);

        if (!isMe && !scrollToBottom) {
          showCustomerFloatingBanner('MESSAGE FROM RIDER', msg.message_text, openChatFromBanner);
        }
      }
    });

    if (hasNew || scrollToBottom) {
      container.scrollTop = container.scrollHeight;
    }

    // Save as read since user is actively viewing this open chat modal
    if (activeCustChatOrderId && custLastMessageId > 0) {
      sessionStorage.setItem('fd_chat_last_read_' + activeCustChatOrderId, custLastMessageId);
      unreadChatCount = 0;
      updateChatBadges();
    }
  } else if (custLastMessageId === 0 && !container.querySelector('.msg-bubble')) {
    container.innerHTML = '<div class="chat-empty-hint">Start a conversation with your rider!</div>';
  }
}

async function fetchCustomerMessages(orderId, scrollToBottom = true) {
  try {
    const res = await fetch(`chat_api.php?order_id=${orderId}&after_id=${custLastMessageId}&_t=${Date.now()}`);
    if (!res.ok) {
      console.warn('chat_api returned status', res.status);
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
// NOTIFICATION CHIME & TOP MESSAGE BANNER
// -------------------------------------------------------------
const CUSTOMER_CHIME_B64_URI = 'data:audio/wav;base64,UklGRkIYAABXQVZFZm10IBAAAAABAAEAESsAACJWAAACABAAZGF0YR4YAAAAADkZoy/CQK9KUUx8RfA2TiLeCVrwlNgwxVW4b7MJt7zCOtV27NkFlR7qM3lDiks5S45CgTLbHAMEverU09TBvbbIs0i5ocZX2jnynwu5I9o3x0XzS7FJQj/OLUcXLv5K5V/P3L6VtZK07bvWyqTfB/hJEZ8ocDunR+lLvkedO+AomxFl+AngPMtLvOC0y7XzvlLPF+XW/c8WPi2mPhlJbUtjRaY3vSPfC7PyAttvxyS6nLRyt1XCD9So6pwDKByRMXhBGkqBSqRCYjNuHhsGH+081v7DarjKtIO5DsYF2U/wUwlNIZI140OqSidJhj/aLvsYWgCx573R7cAft2i1+bsWyi3eA/bxDjYmOjnjRclKYkcPPBMqbhOj+nPijM1BvkO2dbbRvmfOfeO7+28U3SqGPHdHeEo1RUU4FyXNDf/0at2vyf272LXvtwXC+9Lu6G4Bwxk6L3E/nUi4SaVCLTTsHyIIde+f2CrGIrrdtdK5kMXJ13juFQfnHkkz+EFTSYpItT/OL5sadgIP6hjUA8O0uFC2GrxsycvcEfSmDNMjAzcWRJpJ8kZrPDErLRXS/NTk3M89wLS3MrfEvpLN+OGx+RoSfyhjOstFc0nzRM04WyapDz33zN/vy929Ird/uMrB/dFI51D/aBfmLGY9E0fdSJBC4TRVIRgKv/H92lnI5Lv/tjS6KcWj1rTs5QSJHAExBkDvR9tHzj+tMCccgwRi7HDWHcVVukm3T7zYyH/bMfJoCnYhyzRCQl1IcEayPDgs2Bby/iznKdI/wjG5ALjMvtTMiOC599EPJiY+OBVEXUidREA5iSdyEW35JeIwzsS/erghuaXBFNG35UL9GBWVKlY7f0XxR2dCfzWpIvsL/fNV3YnKrb0wuKq618ST1QPrxAI1GrouDz5+RhpH0j92MZ0dfgap7sPYOcf/u1G4mbxbyEnaZPA3CCAfkjJlQBJH2kXiPCotbxgBAXrpdNRFxLm637jpvivMLd/S9ZQN0yMWNldCOUc0RJ05oignE4/7d+Rv0LHB3rnVuZbBQtA65ET70hJHKEI54UP2RipCBzbnI80NLfan37nMf79uuTO7m8SZ1GbpsgDpF3YsEjwCRUhGwT8oMv8eaAjl8BHbWMmyvWm59rzzxyfZqu4VBtIcWjCCPrlFMkX+PAUu8xkBA77ru9ZPxky8zrkav5nL593982QLhiHuM5BAB0a3Q+Q5pinJFKL9v+as0qPDT7ubuprBhs/R4lf5mBD/JSw3OULqRdlBejYQJYsPUPjx4erOV8G7us+7dMS0093nsf6oFTUqEjp6Q2VFnD/FMkwgQAoU81ndeMtuv4+6Zr2hxxzYAu0BBI0aJC6aPFVEeUQFPcsuYhvxBPbt/thcyOm9zbpevxzLt9w68kIJQR/FMcI+xkQoQxY6kypYFqX//ujn1JvFy7xxu7TB38594Xz3aQ68IxU1iEDQRHVB1zYkJjcRZPoy5BnRNsMTvHu8YsTl0mjmwPxxE/gnDjjpQXNEZD9MM4UhBww39Zrfmc0xwcO76b1jxybXbuv9AVEY8CusOuRCr0P3PHsvvBzPBiTwPNtsyo+/27u2v7TKm9uK8C0HAh2dL+48eUOIQjQ6ayvTF5gBM+se15XHUb5YvOHBTs4+4LL1Rwx/Ifwyzz6oQ/9AHzcjJ9ASavxs5kbTGsV4vTm9ZMQr0gfl3/pFEb8lBzZOQHFDFz++M6giuw1L99Xhuc/8wgW9fb47x0XW7ukHAB4Wvym7OGlB1ULVPBYwAx6cCEXydd18zD3B97whwGLKlNrs7iYFzBp3LRM7IELWQT06Liw6GXsDXe1R2ZPJ4b9OvSHC0s0T3/rzMwpIH+MwDz1yQnZAUjcMKFUUYf6b6HHVAsfovgi+esSH0brjD/klD4wj/zOqPmBCuD4bNLYjXQ9S+Qfk2dHMxFK+JL8nx3nVgegj/vcTkSfGNuQ/60GgPJwwNB9YCln0pt+NzvPCIb6ewCTKo9li7S8DoBhSKzQ5vEATQTI63CyNGk4Fe++A25PLesFSvnXCa83+3VTyLAgaHcsuSDswQds/cTfgKMgVRgDB6pnX7shiwOa+pMT40ILiUPcSDV8h9TH/PEJBRz5jNK8k7BBL+zHm99Oixqy/278ox8PUKedO/NoRaCXONFY+8UBYPA0xUSABDGD20eGf0LDEWL8twfvJx9jr60YBfBYxKVE3TD9AQBM6dC3LGw8HjvGo3ZXNG8Nlv9vCGc393MHwMwbzGrQsfDniPzA/ezefKSYXHQLc7LzZ3crmwdS/4cR90F7ho/ULCzgf7C9MOxZAwz2WNJQlaBI0/VHoEtZ8yBDBosA7xyHU5OWK+sgPRSPVMr886j/9O2gxWSGZDVn49OOv0nLGm8DNwebJ/9eH6m//YxQTJ2s10z1eP+E59y32HL8IlPPL35fPxMSGwFPD3MwQ3EDvSATWGJ8qqzeIPnQ+cjdJKnEY5APs7tvbz8xzw9DAMcUY0E/gCPQSCRkd4y2TOd4+Lz21NGQm0RMO/2jqKthZyn/CecFjx5XTs+TX+MMNJyHaMCA71T6QO7AxTSIdD0T6D+a91DrI6sF9wuXJTNc36ab9VRL6JIIzUTxtPpw5Zi4MHl4KjfXm4ZnRdMazwd3Dssw529LtbQLCFo0o1jUkPak9VTffKqgZmgXw8PTdwc4HxdrBk8XHz1Tff/InBwIb2yvUN5o9iTzBNB4nJhXYAHTsPto6zPfDXsKdxx3TluM198sLEB/gLno5sz0SO+MxLCOQECD8IOjJ1gbKQ8M+w/fJrtb65+77UhDmIpcxxjpuPUU5wS4OH+sLePf645rTKcjswnfEncx22njsoQC3FH8m/jO3O848JjdfK8oaPgfo8gfgtNCjxvHCBsaKz23eCfFKBfMY1SkRNks81Du5NMUnaRaSAnXuTdwdznjFU8Ppx7nSjuKl9d8JAB3mLM43hDyCOgIy9yPvEe39KOrR2NbLp8QOxBzKJdbR5kf6XA7XIKsvNDlhPNw4By/7H2UNVvkF5pnV48kxxCLFm8zI2TDr5v64EnQkIzJAOuQ75DbMK9kb0gjT9BPip9JGyBXEi8Zhz5vdpe98A+4W0ydKNPI6DzueNFcolxc8BGvwV94B0AHHVMRIyGrSmeEn9AII+BrtKh02SjviOQ4yrSQ8E6v/JezW2qnNE8btxFTKsNW85bH4cQzPHsAtmjdIO2E4OS/VIM0OJfsH6JbXosuAxdzFrMwu2fzpOv3EEG8iRzDBOO06jzYkLNQcUwqw9hfkmdTvyUXFIcdMz93cU+69AfMU0yV/Mo85OzpwNNQoshjUBVXyWuDm0ZDIY8W4yC7SueC78jIG+Bj2KGc0BToyOQcyTyV1FFgBGO7W3H7PicfZxZ7KT9W75Cz3lArOHNQr+zUjOtY3WC+aISMQ5fwA6o/ZZM3ZxqbG0Myo2Nzon/vbDnAgaS46N+k5KjZpLLsdwwuA+BPmitacy4DGxsdKzzTcFe0NAAIT2COyMCM4WDkwND4puhnd0qS6irNEv+Pakf9CJPA/00sGRUQtbgof5YTGIbb0t3/L5+srER8yqUbESbg6RB2x+AHWvb3VtTDAONp5/UIhxzxGSbdDgy0sDO3nsMnkuKC5q8uL6pwO/y6+Q8VHIjo7Hvj6B9nDwB24K8Go2YT7Yx6zOcBGXUKqLcgNmerDzJu7Ubvty03pLgz7K+JAxUV7ORYfHf3v27bDYboywjPZr/mkG7Y2QkT7QLotRQ8k7bzPRr4HvUHMLejhCRMpFj7FQ8U41R8i/7relsahvEbD1tj69wMZ0DPNQZE/tC2kEJDvnNLlwL++qMwp57QHRiZZO8ZBADh7IAcBaOFjyd2+Y8SQ2GT2gBYAMWA/IT6aLeYR3vFk1XfDe8AgzUHmpgWUI644yT8vNwchzgL54xzMEsGKxWHY6/QbFEUu/TyrPG0tCxMN9BLY/MU3wqfNc+W1A/wgEjbOPVE2fCF4BG7mwc5Bw7nGRtiO89IRoSulOjE7Li0XFB/2qdp0yPTDPc695OIBfh6IM9c7aDXaIQYGyehU0WnF8Mc/2EzypQ8TKVY4tDneLAgVFvgo3d3KscXgzh/kKgAaHA4x4zl1NCQieAcI69LTiscsyUvYJPGUDZsmEzY0OIAs4RXx+Y/fOc1tx4/Pl+OO/s4ZpS70N3ozWSLQCC7tPtaiyW7KZ9gV8JwLOCTbM7I2EyyjFrH73+GHzyfJSdAk4wv9nBdNLAs2dzJ8IhAKOu+W2LLLtcuU2B7vvwnqIa4xLzWZK08XWP0Y5MbR38oO0cXiovuBFQUqJzRtMY0iNwsu8dvauc3+zNDYPe77B7EfjS+sMxIr5hfm/jvm9tOTzNvReuJR+n0TzydJMl0wjSJGDArzDd23z0vOGtlz7U4GjR14LSkygSpoGFsAR+gY1kTOsdJA4hb5kRGqJXIwSC9+IkANzvQs36zRmc9w2b3sugR+G3ArqDDlKdgYugE+6izY8c+O0xfi8/e6D5Ujoi4vLmAiJA589jnhltPp0NPZG+w8A4IZdCkpL0ApNhkCAyDsMNqZ0XHU/+Hl9voNkSHaLBItNSL0DhP4M+N31TrSQdqN69QBmheEJ6stkiiCGTUE7u0m3DzTWtX14ev1TwyeHxkr8yv8IbAPlfkb5U3XitO52hDrgQDGFaElMSzdJ74ZUwWn7w3e2dRI1vnhBvW5CrsdYSnRKrghWhAC+/LmGNna1DvbpOpE/wQUyyO6KiEn6xldBkzx5t9w1jrXC+I09DYJ6BuxJ64paSHyEFv8tujZ2ijWxttJ6hr+VRICIkYpXyYJGlQH3vKw4QHYMNgp4nTzyAcmGgkmiigQIXgRoP1p6o/cdddY3P3pA/24EEUg1yeYJRoaOAhd9GvjjNko2VPixfJsBnMYayRlJ60g7xHT/gzsOt7A2PHcv+n/+y4PlR5tJswkHhoLCcr1GOUP2yPaiOIn8iMF0BbVIkEmQSBWEvP/ne3a3wjakd2Q6Qz7tA3yHAcl+yMWGs0JJfe35ozcINvH4pnx7AM9FUkhHSXOH68SAAEe72/hTds23m3pK/pMDFwbpyMoIwMafgpv+EjoAd4d3A/jGvHGArkTxh/7I1Mf+hL9AY/w+OKO3ODeV+la+fQK0hlMIlEi5RkfC6j5y+lu3xvdX+Oq8LEBRBJNHtoi0h43E+oC8fF35Mzdj99M6Zn4rQlVGPcgeCG9GbIL0fpA69TgGd6440jwrQDdEN0cuyFLHmgTxwND8+rlBt9C4E3p5/d1COQWqR+eIIwZNgzp+6fsMeIX3xjk8++5/4UPdxufIL4djhOUBIX0U+c74PjgV+lE90wHgBVgHsIfUxmtDPL8Ae6H4xXgf+Sq79P+Ow4aGoYfLR2oE1MFuvWw6GvhseFr6a72MwYoFB4d5R4SGRcN7f1O79TkEeHr5G3v/P3/DMcYbx6XHLcTBAbg9gPql+Js4ojpJ/YoBdwS4xsIHskYdA3Y/o7wGeYL4l7lO+8z/dELfhdcHf4bvROnBvf3Suu+4ynjrumr9SsEnBGvGisdehjGDbb/wfFW5wTj1eUU73j8sAo+Fk0cYRu6Ez0HAvmH7N/k6OPb6T31PANnEIEZTxwkGAwOhQDo8ovo+uNR5vfuyfubCQgVQRvCGq4Txwf/+bnt+uWn5A/q2fRaAj4PWxhzG8kXRw5JAQL0t+nu5NDm5O4o+5QI3BM6GiAamhNFCO/64e4Q52flSuqB9IUBIQ47F5gaaRd5Dv8BEfXb6t/lVOfZ7Lp6mQe5EjcZfRl+E7cI0/v+7yDoJ+aM6jT0vAAPDSMWvxkEF6EOqgIU9vbrzeba59fuCPqpBqARORjYGFsTHgmr/BDxKunn5tLq8fMAAAcMEhXoGJsWvw5JAwv3Ce2452Po3e6K+cYFjxA/FzIYMRN7CXb9GfIu6qfnH+u380//CwsIFBIYLhbWDtwD9/cU7p/o7ujq7hb57gSJD0oWjBcCE80JN/4X8yzrZuhv64bzqv4ZCgYTPxe+FeQOZQTZ+Bbvgul76f7urPghBIsOWhXlFswSFwrs/gz0JOwj6cXrXvMP/jEJCxJvFksV6w7jBK/5EPBi6gnqGO9M+F8Dlg1wFD4WkhJXCpf/9/QV7eDpHuw+8379VAgXEaEV1RTqDlgFfPoC8T7rmeo57/b3pwKrDIoTlxVTEo4KNwDZ9QDumup67Cbz9/yABysQ1hRdFOMOwgU+++vxFewq61/vqPf5AcgLqhLxFA8SvgrNALH25e5T69rsFfN6/LYGRg8OFOQT1Q4kBvf7zfLp7Lvriu9j91UB7QrPEUsUxxHlCloBgPfD7wrsPO0L8wf89gVoDkkTaRPCDn0Gpfyn87jtTOy67yb3uwAcCvoQpxN8EQYL3QFH+Jvwv+yh7QjznPs+BZENiBLsEqkOzQZL/Xn0gu7d7O7v8fYpAFIJKhADEy0RHwtYAgT5bfFx7QjuCvM6+5AEwQzKEW8Siw4WB+j9Q/VI727tJvDD9qL/kQhgD2IS3BAyC8oCufk48iDuce4T8+D66gP4CxAR8RFoDlYHfP4G9gnw/u1j8J32Iv/YB5sOwRGIED4LNANm+v7yze7b7iDzjvpMAzYLWRBzEUEOkAcH/8H2xvCO7qLwffaq/iYH2w0jETIQRQuWAwv7vPN370bvM/ND+rcCewqnD/UQFg7CB4v/dfd+8R3v5PBj9jv+fQYhDYcQ2Q9GC/ADp/t19B7ws+9K8//5KgLHCfgOdxDoDe4HBQAh+DHyqu8q8U/20/3aBW0M7Q9/D0ILQwQ8/Cf1wvAg8GXzw/mkARoJTQ75D7UNFAh5AMf43/I28HHxQPZy/UAFvgtVDyQPOQuPBMr81PVj8Y3whfON+SYBcwinDXwPgA00COYAZfmJ88Hwu/E39hn9rAQVC78Oxw4sC9QEUP169gDy+/Co8135rwDSBwQN/w5IDU4ITAH9+S30SvEH8jP2xvwfBHEKLQ5pDhoLEgXQ/Rv3mvJp8c/zM/k/ADgHZgyDDg0NYgiqAY/6zfTR8VTyNPZ6/JkD0gmcDQsOBQtLBUj+tfcx89bx+PMP+df/pAbMCwkO0QxyCAICGfto9Vbyo/I59jT8GgM5CQ8NrA3sCn0Fuv5K+MTzQ/Il9PD4df8XBjYLjw2SDH0IVAKe+//12vLz8kL29PuhAqUIhAxNDc8KqwUl/9n4VPSw8lT01vgY/48FpAoXDVEMgwigAhz8kPZb80TzT/a6+y4CFgj8C+0MsArSBYr/Y/ng9BzzhvTB+ML+DQUWCqEMDwyFCOYClPwd99rzlfNf9ob7wgGMB3cLjgyNCvUF6v/n+Wj1h/O59LH4cv6QBIwJLAzLC4MIJgMH/ab3VvTo83P2VvtbAQgH9govDGgKEwZCAGb67fXy8+/0pfgn/hoEBwm4C4YLfghhA3P9KfjQ9Dr0ivYs+/oAiAZ3CtALQAosBpYA3/pu9lv0JvWd+OL9qQOGCEcLQAt1CJcD2v2o+Ej1jfSk9gb7nwANBvsJcgsXCkEG5ABT++z2w/Rf9Zn4ov09AwkI1wr6CmgIxwM8/iP5vfXg9MD25fpJAJcFggkUC+sJUgYtAcP7Zvcp9Zn1mPhn/dYCjwdqCrIKWQjzA5n+mfkw9jP13/bJ+vn/JgUNCbcKvQlfBnEBLfzc94/11PWb+DH9dAIaB/4JawpHCBsE8P4L+qD2hvUA97D6rf+6BJoIWwqOCWgGsAGS/E/48vUR9qH4//wXAqkGlQkjCjIIPgRD/3j6DffY9SP3nPpm/1IEKwgACl4JbgbrAfP8vvhU9k72qvjS/L8BPAYtCdsJGwheBJH/4vp49yr2SPeL+iP/7gO/B6YJLAlwBiECT/0p+bX2i/a1+Kn8bAHTBcgIkwkBCHkE2v9H++D3e/Zv93365f6PA1YHTQn5CHAGUwKn/ZH5FPfJ9sP4hPwdAW4FZQhLCeYHkQQeAKj7RfjM9pf3c/qr/jQD8Ab2CMUIbAaBAvv99vlw9wj31Phj/NIADAUFCAMJyAelBF4ABfyo+Bv3wPds+nb+3QKOBp8IkQhmBqoCSv5X+sv3Rvfn+Eb8jACuBKcHuwipB7UEmwBe/Af5avfr92j6RP6KAi4GSwhbCF4G0AKV/rX6JfiF9/z4LPxKAFQESwd0CIkHwwTTALP8ZPm49xb4Z/oX/jsC0gX3ByYIUwbzAtz+D/t8+MT3E/kV/AwA/QPxBi4IZwc=';

function playCustomerChime() {
  // 1. Subtle notification sound effect via HTML5 Audio object (lightweight base64 audio data URI)
  try {
    const audio = new Audio(CUSTOMER_CHIME_B64_URI);
    audio.volume = 0.85;
    const playPromise = audio.play();
    if (playPromise !== undefined) {
      playPromise.catch(() => {});
    }
  } catch (e) {}

  // 2. Synthesized Web Audio API two-tone melodic chime (880Hz -> 1320Hz)
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
      gain1.gain.setValueAtTime(0.18, now);
      gain1.gain.exponentialRampToValueAtTime(0.001, now + 0.28);
      osc1.connect(gain1);
      gain1.connect(ctx.destination);
      osc1.start(now);
      osc1.stop(now + 0.28);

      const osc2 = ctx.createOscillator();
      const gain2 = ctx.createGain();
      osc2.type = 'sine';
      osc2.frequency.setValueAtTime(1320, now + 0.12);
      gain2.gain.setValueAtTime(0.22, now + 0.12);
      gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.5);
      osc2.connect(gain2);
      gain2.connect(ctx.destination);
      osc2.start(now + 0.12);
      osc2.stop(now + 0.5);
    }
  } catch (e) {}
}
window.playMessageChime = playCustomerChime;
window.playCustomerChime = playCustomerChime;

let arrivalAlertAudio = null;
let customerSynthLoopInterval = null;
let customerSynthAudioCtx = null;
let customerActiveOscillators = [];

function playSynthArrivalAlarm() {
  if (window.FoodDashCall && typeof window.FoodDashCall.playSynthArrivalAlarm === 'function') {
    window.FoodDashCall.playSynthArrivalAlarm();
    return;
  }
  try {
    const AudioCtxClass = window.AudioContext || window.webkitAudioContext;
    if (!AudioCtxClass) return;
    if (!customerSynthAudioCtx) {
      customerSynthAudioCtx = new AudioCtxClass();
    }
    if (customerSynthAudioCtx.state === 'suspended') {
      customerSynthAudioCtx.resume().catch(() => {});
    }

    if (customerSynthLoopInterval) {
      clearInterval(customerSynthLoopInterval);
      customerSynthLoopInterval = null;
    }
    if (customerActiveOscillators && customerActiveOscillators.length > 0) {
      customerActiveOscillators.forEach(osc => {
        try { osc.stop(); osc.disconnect(); } catch (e) {}
      });
      customerActiveOscillators = [];
    }

    function chimeTick() {
      try {
        if (!customerSynthAudioCtx) return;
        if (customerSynthAudioCtx.state === 'suspended') {
          customerSynthAudioCtx.resume().catch(() => {});
        }
        const now = customerSynthAudioCtx.currentTime;
        // 4-note ascending chord sequence: C5 (523.25Hz) -> E5 (659.25Hz) -> G5 (783.99Hz) -> C6 (1046.50Hz)
        const notes = [
          { freq: 523.25, time: 0.0, dur: 0.6 }, // Note 1: 523.25 Hz (C5) at t = 0.0s, duration 0.6s
          { freq: 659.25, time: 0.3, dur: 0.6 }, // Note 2: 659.25 Hz (E5) at t = 0.3s, duration 0.6s
          { freq: 783.99, time: 0.6, dur: 0.6 }, // Note 3: 783.99 Hz (G5) at t = 0.6s, duration 0.6s
          { freq: 1046.50, time: 0.9, dur: 0.8 }  // Note 4: 1046.50 Hz (C6) at t = 0.9s, duration 0.8s (final ringing note)
        ];
        notes.forEach(n => {
          const osc = customerSynthAudioCtx.createOscillator();
          const gain = customerSynthAudioCtx.createGain();
          osc.type = 'sine'; // Soft, warm sine wave tone
          const noteTime = now + n.time;
          const noteEnd = noteTime + n.dur;

          osc.frequency.setValueAtTime(n.freq, noteTime);

          // Connect to individual gain node: 0.15 at noteTime, smoothly ramps down exponentially
          gain.gain.setValueAtTime(0.15, noteTime);
          gain.gain.exponentialRampToValueAtTime(0.0001, noteEnd);

          osc.connect(gain);
          gain.connect(customerSynthAudioCtx.destination);
          osc.start(noteTime);
          osc.stop(noteEnd + 0.05);

          const nodeItem = { osc, gain, noteStart: noteTime };
          customerActiveOscillators.push(nodeItem);
          osc.onended = () => {
            const idx = customerActiveOscillators.indexOf(nodeItem);
            if (idx > -1) customerActiveOscillators.splice(idx, 1);
            try { osc.disconnect(); gain.disconnect(); } catch (e) {}
          };
        });
      } catch (err) {}
    }

    chimeTick();
    // Repeating interval set to exactly 5000ms (5 seconds):
    // Full 1.7-second melody plays out fully, rests in total silence for over 3 seconds, then repeats cleanly
    customerSynthLoopInterval = setInterval(chimeTick, 5000);
  } catch (e) {
    console.warn('Customer synth chime error:', e);
  }
}
window.playSynthArrivalAlarm = playSynthArrivalAlarm;

function playArrivalAlertSound() {
  if (window.FoodDashCall && typeof window.FoodDashCall.playArrivalAlarmAudio === 'function') {
    window.FoodDashCall.playArrivalAlarmAudio();
    return;
  }
  playSynthArrivalAlarm();
}
window.playArrivalAlertSound = playArrivalAlertSound;

function stopArrivalAlertSound() {
  if (customerSynthLoopInterval) {
    clearInterval(customerSynthLoopInterval);
    customerSynthLoopInterval = null;
  }
  if (customerActiveOscillators && customerActiveOscillators.length > 0) {
    const now = customerSynthAudioCtx ? customerSynthAudioCtx.currentTime : 0;
    customerActiveOscillators.forEach(item => {
      try {
        if (item.gain) {
          item.gain.gain.cancelScheduledValues(now);
          item.gain.gain.setValueAtTime(0.0001, now);
          item.gain.disconnect();
        }
      } catch (e) {}
      try {
        if (item.osc) {
          item.osc.onended = null;
          if (item.noteStart && item.noteStart > now) {
            try { item.osc.stop(item.noteStart); } catch (e1) { try { item.osc.stop(); } catch (e2) {} }
          } else {
            try { item.osc.stop(now + 0.01); } catch (e1) { try { item.osc.stop(); } catch (e2) {} }
          }
          item.osc.disconnect();
        }
      } catch (e) {}
    });
    customerActiveOscillators = [];
  }
  if (arrivalAlertAudio) {
    try {
      arrivalAlertAudio.pause();
      arrivalAlertAudio.currentTime = 0;
    } catch (e) {}
  }
  if (window.arrivalAlertAudio) {
    try {
      window.arrivalAlertAudio.pause();
      window.arrivalAlertAudio.currentTime = 0;
    } catch (e) {}
  }
  if (window.FoodDashCall) {
    if (typeof window.FoodDashCall.stopArrivalAlarm === 'function') {
      window.FoodDashCall.stopArrivalAlarm();
    } else if (typeof window.FoodDashCall.stopArrivalAlarmAudio === 'function') {
      window.FoodDashCall.stopArrivalAlarmAudio();
    } else if (typeof window.FoodDashCall.stopAllTones === 'function') {
      window.FoodDashCall.stopAllTones();
    }
  }
}
window.stopArrivalAlertSound = stopArrivalAlertSound;
window.stopArrivalAlarm = stopArrivalAlertSound;

let msgBannerTimeout = null;
const _customerRecentBanners = new Map();

function dismissLiveRiderBanner() {
  const banner = document.getElementById('liveRiderMessageBanner');
  if (banner) {
    banner.classList.remove('show');
    banner.classList.remove('slide-down');
    banner.style.display = 'none';
  }
  if (msgBannerTimeout) {
    clearTimeout(msgBannerTimeout);
    msgBannerTimeout = null;
  }
}

function showCustomerFloatingBanner(title, text, onClick = null) {
  if (!text) return;
  const banner = document.getElementById('liveRiderMessageBanner');
  const titleEl = document.getElementById('liveMsgBannerTitle');
  const textEl = document.getElementById('liveMsgBannerText');
  const iconEl = banner ? banner.querySelector('.rct-icon i') : null;
  if (!banner || !textEl) return;

  const bannerTitle = (title || 'FOOD DASH NOTIFICATION').toUpperCase();
  const bannerText = String(text).trim();
  const dedupeKey = `${bannerTitle}:::${bannerText}`;
  const now = Date.now();
  const isArrivalNotification = bannerTitle.includes('ARRIVED') || bannerTitle.includes('OUTSIDE');

  // Deduplication guard & duplicate prevention: arrival notifications render ONLY in center arrival modal
  if (isArrivalNotification) {
    dismissLiveRiderBanner();
    return;
  }

  const lastTime = _customerRecentBanners.get(dedupeKey) || 0;
  if (now - lastTime < 3500) {
    return;
  }
  _customerRecentBanners.set(dedupeKey, now);

  // Clean old entries in map
  if (_customerRecentBanners.size > 40) {
    for (const [k, t] of _customerRecentBanners.entries()) {
      if (now - t > 10000) _customerRecentBanners.delete(k);
    }
  }

  // Remove any obsolete floating toast DOM elements
  document.querySelectorAll('.live-toast-banner, .cust-toast').forEach(el => el.remove());

  if (titleEl) titleEl.textContent = bannerTitle;
  textEl.textContent = bannerText;

  if (iconEl) {
    if (bannerTitle.includes('PICKED UP') || bannerTitle.includes('DELIVERY') || bannerTitle.includes('WAY')) {
      iconEl.className = 'fa-solid fa-motorcycle';
    } else if (bannerTitle.includes('ARRIVED') || bannerTitle.includes('OUTSIDE') || bannerTitle.includes('LOCATION') || bannerTitle.includes('PINNED')) {
      iconEl.className = 'fa-solid fa-location-dot';
    } else if (bannerTitle.includes('MESSAGE') || bannerTitle.includes('CHAT') || bannerTitle.includes('RIDER')) {
      iconEl.className = 'fa-solid fa-comment-dots';
    } else if (bannerTitle.includes('CART') || bannerTitle.includes('ORDER')) {
      iconEl.className = 'fa-solid fa-bag-shopping';
    } else if (bannerTitle.includes('ERROR') || bannerTitle.includes('FAIL')) {
      iconEl.className = 'fa-solid fa-circle-exclamation';
    } else {
      iconEl.className = 'fa-solid fa-bell';
    }
  }

  banner.onclick = (e) => {
    if (e.target.closest('.rct-close')) {
      dismissLiveRiderBanner();
      return;
    }
    if (typeof onClick === 'function') onClick();
    else if (bannerTitle.includes('MESSAGE') || bannerTitle.includes('CHAT')) openChatFromBanner();
    else if (bannerTitle.includes('ORDER') || bannerTitle.includes('DELIVERY') || bannerTitle.includes('ARRIVED')) {
      if (activeCustomerTrackingOrderId) openCustomerTrackingModal(activeCustomerTrackingOrderId);
      else showScreen('orders');
    }
  };

  // Force the arrival banner to display and slide down reliably
  banner.style.display = 'block';
  banner.classList.add('slide-down');
  banner.classList.add('show');

  if (msgBannerTimeout) clearTimeout(msgBannerTimeout);
  msgBannerTimeout = setTimeout(() => {
    dismissLiveRiderBanner();
  }, 5500);

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

  const bannerTitle = title || 'NOTIFICATION';
  let onClick = null;
  if (bannerTitle === 'MESSAGE FROM RIDER' || bannerTitle.includes('MESSAGE')) {
    onClick = openChatFromBanner;
  } else if (bannerTitle.includes('DELIVERY') || bannerTitle.includes('PICKED UP') || bannerTitle.includes('ARRIVED')) {
    onClick = () => {
      if (activeCustomerTrackingOrderId) openCustomerTrackingModal(activeCustomerTrackingOrderId);
      else showScreen('orders');
    };
  }

  // Standardized alerting exclusively to top notification banner
  showCustomerFloatingBanner(bannerTitle, msg, onClick);
}

// Redirect all window.alert calls to the FoodDash top notification banner
window.alert = function(msg) {
  showCustomerFloatingBanner('NOTIFICATION', String(msg || ''));
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
  if (!call) return;

  const clientRole = 'customer';
  const callerRole = String(call.caller_role || call.caller || 'rider').toLowerCase().trim();

  // If this customer initiated the call (outgoing call), do NOT display incoming call modal!
  if (callerRole === clientRole) {
    dismissIncomingCallIfRinging();
    return;
  }

  // Explicitly render Rider's correct profile name and details instantly without name flashing
  const callerName = call.caller_name || currentTrackingRiderName || 'Alex Rider';
  const callerPhone = call.caller_phone || currentTrackingRiderPhone || '+63 917 555 0192';

  const modal = document.getElementById('incomingCallModal');
  if (!modal) return;

  const nameEl = document.getElementById('incomingCallerName');
  if (nameEl) nameEl.textContent = callerName;

  const phoneEl = document.getElementById('incomingCallerPhone');
  if (phoneEl) phoneEl.textContent = callerPhone;

  const statusEl = document.getElementById('incomingCallStatus');
  if (statusEl) statusEl.textContent = `${callerName} (Rider) is calling regarding your delivery...`;

  if (currentIncomingCall && currentIncomingCall.call_id === call.call_id && modal.classList.contains('show')) {
    return;
  }
  currentIncomingCall = call;

  const actionsEl = document.getElementById('incomingCallActions');
  if (actionsEl) actionsEl.style.display = 'flex';

  const connectedEl = document.getElementById('incomingConnectedActions');
  if (connectedEl) connectedEl.style.display = 'none';

  modal.style.display = 'flex';
  modal.style.visibility = 'visible';
  modal.style.pointerEvents = 'auto';
  modal.style.opacity = '1';
  modal.classList.add('show');
  startRingtone();
}

function dismissIncomingCallIfRinging() {
  if (currentIncomingCall && !currentIncomingCall.connected) {
    const modal = document.getElementById('incomingCallModal');
    if (modal) {
      modal.style.display = 'none';
      modal.style.visibility = 'hidden';
      modal.style.pointerEvents = 'none';
      modal.classList.remove('show');
    }
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
  if (modal) {
    modal.style.display = 'none';
    modal.style.visibility = 'hidden';
    modal.style.pointerEvents = 'none';
    modal.classList.remove('show');
  }
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
  if (modal) {
    modal.style.display = 'none';
    modal.style.visibility = 'hidden';
    modal.style.pointerEvents = 'none';
    modal.classList.remove('show');
  }
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
    <div class="msg-meta">${escapeCustHtml(msg.sender_name || 'Rider')} • ${escapeCustHtml(msg.time_formatted || 'Just now')}</div>
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

  const modal = document.getElementById('customerChatModal');
  const isChatOpen = modal && modal.classList.contains('show');

  if (!isChatOpen) {
    // Show top floating push banner pinned at top matching rider dashboard
    showCustomerFloatingBanner('MESSAGE FROM RIDER', msg.message_text, openChatFromBanner);

    // Update the unread badge counter in real time
    unreadChatCount++;
    updateChatBadges();
  } else {
    unreadChatCount = 0;
    updateChatBadges();
    if (activeOId) {
      sessionStorage.setItem('fd_chat_last_read_' + activeOId, msg.message_id);
    }
  }

  appendMessageToChatDrawer(msg);

  // Dynamically update notification badge on customer dashboard header
  if (typeof loadNotifications === 'function') loadNotifications();
}

function openChatFromBanner() {
  dismissLiveRiderBanner();
  openCustomerChat();
}

function closeRiderArrivedModal() {
  if (window.FoodDashCall && typeof window.FoodDashCall.stopArrivalAlarm === 'function') {
    window.FoodDashCall.stopArrivalAlarm();
  }
  stopArrivalAlertSound();
  const modal = document.getElementById('riderArrivedModal');
  if (modal) {
    modal.style.display = 'none';
    modal.style.visibility = 'hidden';
    modal.style.pointerEvents = 'none';
    modal.classList.remove('show', 'active');
  }
  if (window.FoodDashCall && typeof window.FoodDashCall.stopAllTones === 'function') {
    FoodDashCall.stopAllTones();
  }
}

function respondToRiderArrival(responseOption) {
  // Immediately invoke stopArrivalAlarm() for full cleanup
  if (window.FoodDashCall && typeof window.FoodDashCall.stopArrivalAlarm === 'function') {
    window.FoodDashCall.stopArrivalAlarm();
  }
  stopArrivalAlertSound();

  const oId = activeCustomerTrackingOrderId 
    || (window.FoodDashCall && typeof window.FoodDashCall.getActiveOrderId === 'function' ? window.FoodDashCall.getActiveOrderId() : 0);

  if (oId) {
    try { sessionStorage.setItem('fd_arrival_ack_' + oId, '1'); } catch (e) {}
  }
  try { sessionStorage.setItem('fd_arrival_acknowledged_all', '1'); } catch (e) {}

  closeRiderArrivedModal();

  if (window.FoodDashCall && typeof window.FoodDashCall.acknowledgeArrival === 'function') {
    window.FoodDashCall.acknowledgeArrival(oId, responseOption);
  }
}

function attachArrivalModalListeners() {
  const modal = document.getElementById('riderArrivedModal');
  if (!modal) return;
  const buttons = modal.querySelectorAll('.btn-arrival-action, #riderArrivedActionButtons button');
  buttons.forEach(btn => {
    if (btn.dataset.arrivalAudioBound === 'true') return;
    btn.dataset.arrivalAudioBound = 'true';
    btn.addEventListener('click', function() {
      if (window.FoodDashCall && typeof window.FoodDashCall.stopArrivalAlarm === 'function') {
        window.FoodDashCall.stopArrivalAlarm();
      }
      stopArrivalAlertSound();
    }, true);
  });
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', attachArrivalModalListeners);
  } else {
    attachArrivalModalListeners();
  }
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
  stopArrivalAlertSound();
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
    if (rowIcon) rowIcon.textContent = '📍';
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
    etaText.textContent = 'Your rider has arrived outside! 🛵';
  }
  const distText = document.getElementById('custTrackDistText');
  if (distText) {
    distText.textContent = '0m away (Arrived)';
  }
}

function handleRiderArrivedEvent(data) {
  const oId = data.order_id || activeCustomerTrackingOrderId || 0;
  const isAck = Boolean(data.arrival_acknowledged) || (sessionStorage.getItem('fd_arrival_ack_' + oId) === '1') || (sessionStorage.getItem('fd_arrival_acknowledged_all') === '1');
  if (isAck) {
    if (window.FoodDashCall) FoodDashCall.stopAllTones();
    stopArrivalAlertSound();
    return;
  }

  const modal = document.getElementById('riderArrivedModal');
  const isModalOpen = modal && (modal.style.display === 'flex' || modal.classList.contains('show'));

  // Instant Modal Pop-up on Arrival: immediately display modal if not currently open
  if (!isModalOpen) {
    if (modal) {
      modal.classList.add('show', 'active');
      modal.style.display = 'flex';
      modal.style.visibility = 'visible';
      modal.style.pointerEvents = 'auto';
    }
    const rName = (data.rider_name || currentTrackingRiderName || 'Waiting for Rider Assignment');
    // Dismiss top notification banner completely upon arrival: ONLY render center arrival modal
    dismissLiveRiderBanner();

    if (window.FoodDashCall && typeof FoodDashCall.triggerArrivalAlarm === 'function') {
      FoodDashCall.triggerArrivalAlarm(oId, rName);
    } else if (typeof playArrivalAlertSound === 'function') {
      playArrivalAlertSound();
    }
  }

  updateOrderStatusToArrived(oId);
}

// Global background poll running continuously on ALL customer screens for real-time telemetry
async function pollCustomerCommunicationUpdates() {
  if (!currentCustomerId) return;
  try {
    const targetOrderId = activeCustomerTrackingOrderId || 0;
    const url = `order_communication_api.php?action=get_updates&customer_id=${currentCustomerId}&order_id=${targetOrderId}&after_message_id=${lastSeenRiderMsgId}&_t=${Date.now()}`;
    const res = await fetch(url, {
      cache: 'no-store',
      headers: {
        'Cache-Control': 'no-cache, no-store, must-revalidate',
        'Pragma': 'no-cache'
      }
    });
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

    const sNorm = String(data.status || '').toUpperCase().trim();
    const dNorm = String(data.delivery_status || '').toLowerCase().trim();
    const sLower = String(data.status || '').toLowerCase().trim();
    const isPendingPrepReady = ['PENDING', 'PLACED', 'PREPARING', 'READY_FOR_PICKUP', 'READY', 'CANCELLED'].includes(sNorm)
                            || ['waiting', 'pending', 'preparing', 'cooking', 'ready', 'ready_for_pickup', 'cancelled'].includes(dNorm)
                            || ['pending', 'placed', 'preparing', 'ready', 'ready_for_pickup'].includes(sLower);

    if (!isPendingPrepReady) {
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
    }

    // Automated "Rider Arrived" Live Notification
    const isArrivedEvent = Boolean(data.is_arrived) 
                        || ['arrived', 'arrived_destination'].includes(dNorm) 
                        || ['ARRIVED', 'ARRIVED_DESTINATION'].includes(sNorm);
    if (isArrivedEvent) {
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

      // Real-time on-screen banner alert for customer when order is picked up
      const pickupKey = 'fd_pickup_alert_seen_' + oId;
      if (sessionStorage.getItem(pickupKey) !== '1') {
        sessionStorage.setItem(pickupKey, '1');
        const cleanRef = '#' + String(data.order_ref || ('FD-' + oId)).replace(/^#+/, '');
        const totalNum = parseFloat(data.total_amount || 0).toFixed(2);
        const formattedTotal = (totalNum > 0) ? ('₱' + Number(totalNum).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})) : '';
        const pickupMsg = formattedTotal 
          ? `Order ${cleanRef} has been picked up! Total due: ${formattedTotal}` 
          : `Order ${cleanRef} has been picked up! Ready for delivery.`;
        showCustomerFloatingBanner('ORDER PICKED UP - READY FOR DELIVERY', pickupMsg, () => {
          if (typeof openTrackingModal === 'function') openTrackingModal(oId);
          else if (typeof showScreen === 'function') showScreen('orders');
        });
        if (typeof loadNotifications === 'function') loadNotifications();
      }
      const tag = document.getElementById(`status-tag-${oId}`);
      if (tag) {
        if (data.is_arrived || ['arrived', 'arrived_destination'].includes(dNorm) || ['ARRIVED', 'ARRIVED_DESTINATION'].includes(sNorm)) {
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
        card.setAttribute('data-status', (['arrived', 'arrived_destination'].includes(dNorm) || ['ARRIVED', 'ARRIVED_DESTINATION'].includes(sNorm) || data.is_arrived) ? 'arrived' : ((dNorm === 'near_customer' || sNorm === 'NEAR_CUSTOMER') ? 'near_customer' : 'out_for_delivery'));
        card.classList.add('on-the-way-card');
        card.style.cursor = 'pointer';
        card.onclick = () => openTrackingModal(oId);
        card.setAttribute('title', 'Tap to view live delivery tracking map');

        const rowIcon = card.querySelector('.row-icon');
        if (rowIcon && !rowIcon.textContent.includes('📍') && !rowIcon.textContent.includes('🛵')) {
          rowIcon.className = 'row-icon jolli';
          rowIcon.textContent = '🛵';
        }

        // Hide cancel button if order has transitioned to on_delivery / near / arrived
        const cancelWrap = document.getElementById(`cancel-wrap-${oId}`);
        if (cancelWrap) cancelWrap.style.display = 'none';

        // Ensure the [ 📍 Live Track Rider ] button and [ 📦 Received ] button exist on the card
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
                <i class="fa-solid fa-location-dot"></i> 📍 Live Track Rider
              </button>
              <button class="confirm-btn" id="confirm-btn-${oId}" onclick="event.stopPropagation(); confirmOrderReceived(${oId})">
                <i class="fa-solid fa-box-open"></i> 📦 Received
              </button>
            `;
            infoDiv.appendChild(trackActions);
          }
        } else {
          trackActions.style.display = 'flex';
        }
      }

      // Persistent top banner removed per UX specifications
      const topBanner = document.getElementById('dynamicOnTheWayBanner');
      if (topBanner) {
        topBanner.style.display = 'none';
        topBanner.remove();
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
      if (topBanner) {
        topBanner.style.display = 'none';
        topBanner.remove();
      }
    }
  } catch (e) {
    console.warn('Customer global poll error:', e);
  }
}

// Continuously execute every 500ms on ALL screens for instant real-time telemetry sync
pollCustomerCommunicationUpdates();
setInterval(pollCustomerCommunicationUpdates, 500);

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
const orderTotal = '₱200.00';

function updatePaymentTheme(method) {
    selectedMethod = method;
    const btn = document.getElementById('payActionBtn') || document.getElementById('btnCheckoutSubmit');
    if (!btn) return;

    if (method === 'cod') {
        btn.style.setProperty('background-color', '#283d23', 'important');
        btn.style.setProperty('color', '#ffffff', 'important');
        btn.innerHTML = '<i class="fa-solid fa-money-bill-wave" style="margin-right:8px;"></i> Place Order • ' + orderTotal;
    } else if (method === 'gcash') {
        btn.style.setProperty('background-color', '#007DFE', 'important');
        btn.style.setProperty('color', '#ffffff', 'important');
        btn.innerHTML = '<span style="background:#fff; color:#007DFE; font-weight:bold; border-radius:4px; padding:1px 6px; margin-right:6px; font-size:12px;">G</span> Pay with GCash • ' + orderTotal;
    } else if (method === 'maya') {
        btn.style.setProperty('background-color', '#023a2b', 'important');
        btn.style.setProperty('color', '#05B851', 'important');
        btn.innerHTML = '<span style="background:#05B851; color:#023a2b; font-weight:bold; border-radius:4px; padding:1px 6px; margin-right:6px; font-size:12px;">M</span> Pay with Maya • ' + orderTotal;
    } else if (method === 'gotyme') {
        btn.style.setProperty('background-color', '#0a2540', 'important');
        btn.style.setProperty('color', '#00d2ff', 'important');
        btn.innerHTML = '<span style="background:#00d2ff; color:#0a2540; font-weight:bold; border-radius:4px; padding:1px 6px; margin-right:6px; font-size:12px;">GT</span> Pay with GoTyme • ' + orderTotal;
    } else if (method === 'apple_pay') {
        btn.style.setProperty('background-color', '#000000', 'important');
        btn.style.setProperty('color', '#ffffff', 'important');
        btn.innerHTML = '<span style="font-size:18px; margin-right:6px;">ï£¿</span> Pay • ' + orderTotal;
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
<!-- ============================================== -->
<!-- UNIFIED PAYMENT CONFIRMATION MODALS (5 METHODS) -->
<!-- ============================================== -->

<!-- 1. APPLE PAY NATIVE IOS BIOMETRIC MODAL -->
<div class="sim-modal" id="customerApplePayModal" style="display:none; pointer-events:none; visibility:hidden;" onclick="if(event.target===this)closeCustomerPaymentModal('customerApplePayModal')">
  <div class="sim-sheet" style="background:#1c1c1e; color:#ffffff;">
    <div class="sheet-grabber" style="background:#3a3a3c;"></div>
    <div style="display:flex; align-items:center; justify-content:space-between; padding:4px 22px 14px 22px; border-bottom:1px solid #2c2c2e;">
      <div style="display:flex; align-items:center; gap:6px; font-size:20px; font-weight:700;">
        <span style="font-size:22px;"></span><span>Pay</span>
      </div>
      <button type="button" onclick="closeCustomerPaymentModal('customerApplePayModal')" style="background:transparent; border:none; color:#0a84ff; font-size:15px; font-weight:600; cursor:pointer;">Cancel</button>
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
          <span>Deliver To</span><b style="color:#ffffff;" id="custAppleModalDest">Your Address</b>
        </div>
        <div style="height:1px; background:#3a3a3c; margin:8px 0;"></div>
        <div style="display:flex; justify-content:space-between; align-items:center;">
          <span style="font-size:15px; font-weight:700;">Total</span>
          <span style="font-size:22px; font-weight:800; color:#30d158;" id="custAppleModalTotal">₱0.00</span>
        </div>
      </div>

      <!-- Face ID Radar Viewfinder Animation Container -->
      <div class="face-id-wrapper">
        <div class="face-id-box" id="custAppleFaceIdBox">
          <div class="face-id-corner top-left"></div>
          <div class="face-id-corner top-right"></div>
          <div class="face-id-corner bottom-left"></div>
          <div class="face-id-corner bottom-right"></div>
          <svg id="custAppleFaceIdSvg" viewBox="0 0 48 48" width="46" height="46">
            <circle cx="16" cy="18" r="2.2" fill="#007aff"/>
            <circle cx="32" cy="18" r="2.2" fill="#007aff"/>
            <path d="M 24,18 L 24,26 L 20.5,26" fill="none" stroke="#007aff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M 15,31 C 18,36 30,36 33,31" fill="none" stroke="#007aff" stroke-width="2.4" stroke-linecap="round"/>
          </svg>
          <div id="custAppleFaceIdCheck" style="display:none;">
            <i class="fa-solid fa-circle-check check-pop" style="font-size: 48px; color: #30d158;"></i>
          </div>
          <div class="face-id-scan-line" id="custAppleFaceIdScanLine" style="display:none;"></div>
        </div>
        <div id="custFaceIdStatus" style="font-size:14px; font-weight:700; color:#8e8e93; margin-top:12px;">
          Scanning Face...
        </div>
      </div>

      <button type="button" class="btn-submit-order checkout-btn" id="btnCustApplePayTrigger" onclick="startCustomerApplePayScan()" style="background:#333336; color:#8e8e93; margin-top:16px;" disabled>
        <span style="font-size:18px;"></span> Scanning Face...
      </button>
    </div>
  </div>
</div>

<!-- 2. DIGITAL WALLET QUICKPAY MODAL (GCash / Maya / GoTyme) -->
<div class="sim-modal" id="customerWalletModal" style="display:none; pointer-events:none; visibility:hidden;" onclick="if(event.target===this)closeCustomerPaymentModal('customerWalletModal')">
  <div class="sim-sheet" style="background:#ffffff;">
    <div id="custWalletHeader" class="sim-sheet-header" style="background:#007DFE; color:#ffffff; padding:16px 20px 18px 20px;">
      <div class="sheet-grabber"></div>
      <div style="display:flex; align-items:center; justify-content:space-between; margin-top:8px;">
        <div style="display:flex; align-items:center; gap:10px;">
          <img id="custWalletLogo" src="assets/logos/gcash.svg" alt="Wallet" style="height:26px; border-radius:6px; background:#fff; padding:2px;">
          <div>
            <div id="custWalletTitle" style="font-size:16px; font-weight:800; line-height:1.2;">GCash QuickPay</div>
            <small id="custWalletSub" style="font-size:11px; opacity:0.85;">Official Digital Payment</small>
          </div>
        </div>
        <button type="button" onclick="closeCustomerPaymentModal('customerWalletModal')" style="background:rgba(255,255,255,0.18); border:none; color:#ffffff; font-size:12px; font-weight:700; padding:6px 12px; border-radius:20px; cursor:pointer;">Cancel</button>
      </div>
    </div>
    <div class="sim-sheet-body" style="background:#ffffff;">
      <div style="text-align:center; margin-bottom:16px;">
        <div style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#6b7280; margin-bottom:4px;">Payment Authorization</div>
        <h2 style="font-size:22px; font-weight:800; color:#111827;">Total: <span id="custWalletTotal" style="color:#007DFE;">₱0.00</span></h2>
      </div>
      <div style="background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:16px; padding:12px 16px; margin-bottom:14px;">
        <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
          <i class="fa-solid fa-location-dot" style="color:#ef4444;"></i> Delivery Destination:
        </div>
        <div style="font-size:14px; font-weight:700; color:#1e293b;" id="custWalletDest">Your Address</div>
      </div>
      <div style="background:#fdfbf7; border:1px solid #e8decb; border-radius:16px; padding:16px; margin-bottom:18px; text-align:center;">
        <label id="custWalletPinLabel" style="display:block; font-size:12px; font-weight:700; color:#374151; margin-bottom:8px;">
          <i class="fa-solid fa-lock" style="color:#007DFE;"></i> Enter 4-Digit MPIN / Security OTP:
        </label>
        <div style="display:flex; justify-content:center; margin-bottom:6px;">
          <input type="password" id="custWalletPinInput" maxlength="6" value="1234" autocomplete="off" style="width:170px; height:46px; text-align:center; font-size:24px; letter-spacing:10px; border-radius:12px; border:2px solid #007DFE; font-family:monospace; font-weight:800; outline:none; background:#ffffff; color:#1e293b;">
        </div>
        <small style="color:#6b7280; font-size:11px;"><i class="fa-solid fa-circle-check" style="color:#10b981;"></i> Security Verified • Instant Processing</small>
      </div>
      <button type="button" class="btn-submit-order checkout-btn" id="custWalletSubmitBtn" onclick="executeFinalOrderFromModal()" style="background:#007DFE; color:#ffffff; margin-top:0;">
        <i class="fa-solid fa-shield-halved"></i> Confirm & Pay • <span id="custWalletBtnTotal">₱0.00</span>
      </button>
    </div>
  </div>
</div>

<!-- 3. CASH ON DELIVERY (COD) ORDER SUMMARY MODAL -->
<div class="sim-modal" id="customerCodModal" style="display:none; pointer-events:none; visibility:hidden;" onclick="if(event.target===this)closeCustomerPaymentModal('customerCodModal')">
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
        <button type="button" onclick="closeCustomerPaymentModal('customerCodModal')" style="background:rgba(255,255,255,0.18); border:none; color:#ffffff; font-size:12px; font-weight:700; padding:6px 12px; border-radius:20px; cursor:pointer;">Cancel</button>
      </div>
    </div>
    <div class="sim-sheet-body" style="background:#ffffff;">
      <div style="text-align:center; margin-bottom:16px;">
        <div style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#6b7280; margin-bottom:4px;">Order Summary</div>
        <h2 style="font-size:22px; font-weight:800; color:#111827;">Total Amount: <span id="custCodTotal" style="color:#283d23;">₱0.00</span></h2>
      </div>
      <div style="background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:16px; padding:12px 16px; margin-bottom:14px;">
        <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
          <i class="fa-solid fa-location-dot" style="color:#ef4444;"></i> Delivery Destination:
        </div>
        <div style="font-size:14px; font-weight:700; color:#1e293b;" id="custCodDest">Your Address</div>
      </div>
      <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:14px; padding:14px; margin-bottom:18px; font-size:12px; color:#166534; display:flex; align-items:center; gap:10px;">
        <i class="fa-solid fa-money-bill-wave" style="font-size:20px; color:#15803d;"></i>
        <span>Please prepare exact cash of <b id="custCodCashAmt">₱0.00</b> upon arrival for swift handover.</span>
      </div>
      <button type="button" class="btn-submit-order checkout-btn" id="custCodSubmitBtn" onclick="executeFinalOrderFromModal()" style="background:#283d23; color:#ffffff; margin-top:0;">
        <i class="fa-solid fa-check"></i> Confirm Order • <span id="custCodBtnTotal">₱0.00</span>
      </button>
    </div>
  </div>
</div>
<?php $fd_gmail_btn_in_header = true; require_once 'gmail_simulator_modal.php'; ?>
<script>
function closeCustomerPaymentModal(modalId) {
    const el = document.getElementById(modalId);
    if (el) {
        el.style.display = 'none';
        el.style.pointerEvents = 'none';
        el.style.visibility = 'hidden';
        el.classList.remove('show', 'active');
    }
}

function openCustomerPaymentConfirmationModal() {
    if (!cart || !cart.length) {
        toast('Your cart is empty. Add items to checkout.');
        return;
    }
    const addrInput = document.getElementById('deliveryAddressInput');
    const address = (addrInput ? addrInput.value : '').trim();
    if (!address) {
        openAddressPromptModal();
        return;
    }

    const payInput = document.getElementById('selectedPaymentMethod');
    const chosenRaw = payInput ? payInput.value : 'Cash on Delivery (COD)';
    const m = chosenRaw.toLowerCase();

    // Calculate dynamic totals
    const subtotal = cart.reduce((sum, item) => sum + ((Number(item.price) || 0) * (Number(item.qty) || 1)), 0);
    const deliveryFee = 30;
    const grandTotal = subtotal + deliveryFee;
    const totalFormatted = '₱' + grandTotal.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});

    if (m.includes('apple')) {
        const dest = document.getElementById('custAppleModalDest');
        if (dest) dest.textContent = address;
        const tot = document.getElementById('custAppleModalTotal');
        if (tot) tot.textContent = totalFormatted;
        const modal = document.getElementById('customerApplePayModal');
        if (modal) {
            modal.style.display = 'flex';
            modal.style.pointerEvents = 'auto';
            modal.style.visibility = 'visible';
            modal.classList.add('show', 'active');
            startCustomerApplePayScan();
        }
    } else if (m.includes('gcash') || m.includes('maya') || m.includes('tyme')) {
        updateCustomerWalletTheme(m, totalFormatted, address);
        const modal = document.getElementById('customerWalletModal');
        if (modal) {
            modal.style.display = 'flex';
            modal.style.pointerEvents = 'auto';
            modal.style.visibility = 'visible';
            modal.classList.add('show', 'active');
        }
    } else {
        // Cash on Delivery (COD)
        const dest = document.getElementById('custCodDest');
        if (dest) dest.textContent = address;
        const tot = document.getElementById('custCodTotal');
        if (tot) tot.textContent = totalFormatted;
        const cashAmt = document.getElementById('custCodCashAmt');
        if (cashAmt) cashAmt.textContent = totalFormatted;
        const btnTot = document.getElementById('custCodBtnTotal');
        if (btnTot) btnTot.textContent = totalFormatted;
        const modal = document.getElementById('customerCodModal');
        if (modal) {
            modal.style.display = 'flex';
            modal.style.pointerEvents = 'auto';
            modal.style.visibility = 'visible';
            modal.classList.add('show', 'active');
        }
    }
}

function updateCustomerWalletTheme(method, totalFormatted, address) {
    const header = document.getElementById('custWalletHeader');
    const title = document.getElementById('custWalletTitle');
    const sub = document.getElementById('custWalletSub');
    const logo = document.getElementById('custWalletLogo');
    const tot = document.getElementById('custWalletTotal');
    const dest = document.getElementById('custWalletDest');
    const btnTot = document.getElementById('custWalletBtnTotal');
    const label = document.getElementById('custWalletPinLabel');
    const pinIn = document.getElementById('custWalletPinInput');
    const submitBtn = document.getElementById('custWalletSubmitBtn');

    if (dest) dest.textContent = address;
    if (tot) tot.textContent = totalFormatted;
    if (btnTot) btnTot.textContent = totalFormatted;

    if (method.includes('gcash')) {
        if (header) header.style.background = '#007DFE';
        if (title) title.textContent = 'GCash QuickPay';
        if (sub) sub.textContent = 'Official Digital Payment';
        if (logo) logo.src = 'assets/logos/gcash.svg';
        if (label) label.innerHTML = '<i class="fa-solid fa-lock" style="color:#007DFE;"></i> Enter 4-Digit MPIN / Security OTP:';
        if (pinIn) { pinIn.style.borderColor = '#007DFE'; pinIn.maxLength = 4; pinIn.value = '1234'; }
        if (submitBtn) {
            submitBtn.style.setProperty('background-color', '#007DFE', 'important');
            submitBtn.style.setProperty('color', '#ffffff', 'important');
        }
    } else if (method.includes('maya')) {
        if (header) header.style.background = '#023a2b';
        if (title) title.textContent = 'Maya Checkout';
        if (sub) sub.textContent = 'Maya Wallet & Digital Card';
        if (logo) logo.src = 'assets/logos/maya.svg';
        if (label) label.innerHTML = '<i class="fa-solid fa-lock" style="color:#05B851;"></i> Enter Maya OTP / Security Code:';
        if (pinIn) { pinIn.style.borderColor = '#05B851'; pinIn.maxLength = 6; pinIn.value = '654321'; }
        if (submitBtn) {
            submitBtn.style.setProperty('background-color', '#023a2b', 'important');
            submitBtn.style.setProperty('color', '#05B851', 'important');
        }
    } else if (method.includes('tyme')) {
        if (header) header.style.background = '#0a2540';
        if (title) title.textContent = 'GoTyme Bank Transfer';
        if (sub) sub.textContent = 'Everyday Account (•••• 4920)';
        if (logo) logo.src = 'assets/logos/gotyme.svg';
        if (label) label.innerHTML = '<i class="fa-solid fa-building-columns" style="color:#00d2ff;"></i> Enter GoTyme Authorization PIN:';
        if (pinIn) { pinIn.style.borderColor = '#00d2ff'; pinIn.maxLength = 6; pinIn.value = '882190'; }
        if (submitBtn) {
            submitBtn.style.setProperty('background-color', '#0a2540', 'important');
            submitBtn.style.setProperty('color', '#00d2ff', 'important');
        }
    }
}

function startCustomerApplePayScan() {
    const scanLine = document.getElementById('custAppleFaceIdScanLine');
    const svg = document.getElementById('custAppleFaceIdSvg');
    const check = document.getElementById('custAppleFaceIdCheck');
    const box = document.getElementById('custAppleFaceIdBox');
    const status = document.getElementById('custFaceIdStatus');
    const btn = document.getElementById('btnCustApplePayTrigger');

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
        status.innerHTML = '<span style="color:#007aff; font-size:14px; font-weight:700;"><i class="fa-solid fa-spinner fa-spin" style="margin-right:6px;"></i>Scanning Face...</span>';
    }

    setTimeout(function() {
        if (scanLine) scanLine.style.display = 'none';
        if (svg) svg.style.display = 'none';
        if (check) check.style.display = 'block';
        if (box) {
            box.classList.remove('scanning');
            box.classList.add('success');
        }
        if (status) {
            status.innerHTML = '<span style="color:#30d158; font-size:16px; font-weight:800; display:inline-flex; align-items:center; gap:6px;"><i class="fa-solid fa-check"></i> Verified</span>';
        }
        if (btn) {
            btn.style.background = '#30d158';
            btn.style.color = '#ffffff';
            btn.innerHTML = '<i class="fa-solid fa-check"></i> Authorized';
        }
        try {
            if (navigator.vibrate) navigator.vibrate([35, 45, 35]);
        } catch(e) {}

        setTimeout(function() {
            closeCustomerPaymentModal('customerApplePayModal');
            executeFinalOrderFromModal();
        }, 800);
    }, 1300);
}

function executeFinalOrderFromModal() {
    closeCustomerPaymentModal('customerWalletModal');
    closeCustomerPaymentModal('customerCodModal');
    closeCustomerPaymentModal('customerApplePayModal');
    executeOrderSubmission();
}

function handlePayAction(e) {
    if (e && e.preventDefault) e.preventDefault();
    openCustomerPaymentConfirmationModal();
    return false;
}
</script>

<!-- Global Touch & Click Event Delegation Engine: 100% Instantaneous Tap Execution Inside Mobile WebViews -->
<script>
(function initGlobalTouchDelegation() {
  if (window.__fdGlobalTouchDelegationInstalled) return;
  window.__fdGlobalTouchDelegationInstalled = true;

  var touchStartX = 0;
  var touchStartY = 0;
  var touchStartTime = 0;
  var touchMoved = false;
  var lastActionTimestamp = 0;
  var lastTriggeredElement = null;
  var touchJustHandled = false;
  var touchResetTimer = null;

  // Global touchstart: track coordinates & provide instant tactile active state
  document.addEventListener('touchstart', function(e) {
    if (e.touches && e.touches.length === 1) {
      touchStartX = e.touches[0].clientX;
      touchStartY = e.touches[0].clientY;
      touchStartTime = Date.now();
      touchMoved = false;

      var touch = e.touches[0];
      var target = document.elementFromPoint(touch.clientX, touch.clientY) || e.target;
      if (target) {
        var clickable = target.closest(
          'button, a, .card, .add-to-cart-btn, .store, .food-card, .row-card, .chip, .cancel-chip, .search, .icon-btn, .nav, .add, .btn-qty-minus, .btn-qty-plus, [onclick], [data-action], [data-screen], [data-category]'
        );
        if (clickable && !clickable.disabled) {
          clickable.classList.add('is-touch-active');
        }
      }
    }
  }, { passive: true, capture: true });

  // Global touchmove: distinguish genuine swipe/scroll from taps
  document.addEventListener('touchmove', function(e) {
    if (e.touches && e.touches.length === 1) {
      var dx = Math.abs(e.touches[0].clientX - touchStartX);
      var dy = Math.abs(e.touches[0].clientY - touchStartY);
      if (dx > 10 || dy > 10) {
        touchMoved = true;
        // Clean active touch styling during scrolling
        var activeEls = document.querySelectorAll('.is-touch-active');
        for (var i = 0; i < activeEls.length; i++) {
          activeEls[i].classList.remove('is-touch-active');
        }
      }
    }
  }, { passive: true, capture: true });

  // Global touchend: instant delegation handler for all dashboard buttons and interactive elements
  document.addEventListener('touchend', function(e) {
    setTimeout(function() {
      var activeEls = document.querySelectorAll('.is-touch-active');
      for (var i = 0; i < activeEls.length; i++) {
        activeEls[i].classList.remove('is-touch-active');
      }
    }, 120);

    if (touchMoved) return; // User was scrolling
    var duration = Date.now() - touchStartTime;
    if (duration > 400) return; // Ignore long presses

    if (!e.changedTouches || e.changedTouches.length === 0) return;
    var touch = e.changedTouches[0];
    var target = document.elementFromPoint(touch.clientX, touch.clientY) || e.target;
    if (!target) return;

    // Allow normal focus on native editable inputs and textareas
    var editableInput = target.closest('input:not([readonly]), textarea, select');
    if (editableInput) {
      editableInput.focus();
      return;
    }

    // Resolve closest interactive element
    var clickable = target.closest(
      'button, a, .card, .add-to-cart-btn, .store, .food-card, .row-card, .chip, .cancel-chip, .search, .icon-btn, .nav, .add, .btn-qty-minus, .btn-qty-plus, [onclick], [data-action], [data-screen], [data-category]'
    );
    if (!clickable || clickable.disabled) return;

    // Prevent delayed or cancelled synthetic click in React Native WebView
    if (e.cancelable) {
      e.preventDefault();
    }
    if (e.stopPropagation) e.stopPropagation();
    if (e.stopImmediatePropagation) e.stopImmediatePropagation();

    // Debounce rapid duplicate taps (300ms threshold)
    var now = Date.now();
    if (clickable === lastTriggeredElement && (now - lastActionTimestamp) < 300) {
      return;
    }
    lastTriggeredElement = clickable;
    lastActionTimestamp = now;
    touchJustHandled = true;
    if (touchResetTimer) clearTimeout(touchResetTimer);
    touchResetTimer = setTimeout(function() {
      touchJustHandled = false;
    }, 500);

    // Execute the interactive action instantaneously
    dispatchInteractiveEvent(clickable, e);
  }, { passive: false, capture: true });

  // Global click: handle desktop clicks and debounce delayed WebView ghost clicks
  document.addEventListener('click', function(e) {
    if (e.__isSyntheticTouchClick) {
      return; // Allow programmatic clicks through!
    }

    var target = e.target;
    if (!target) return;

    var clickable = target.closest(
      'button, a, .card, .add-to-cart-btn, .store, .food-card, .row-card, .chip, .cancel-chip, .search, .icon-btn, .nav, .add, .btn-qty-minus, .btn-qty-plus, [onclick], [data-action], [data-screen], [data-category]'
    );
    if (!clickable) return;

    var now = Date.now();
    // Only swallow delayed browser ghost click after a touch was handled in the last 450ms
    if (touchJustHandled && (now - lastActionTimestamp) < 450 && (clickable === lastTriggeredElement || clickable.contains(lastTriggeredElement))) {
      e.preventDefault();
      e.stopPropagation();
      if (e.stopImmediatePropagation) e.stopImmediatePropagation();
      return;
    }

    lastTriggeredElement = clickable;
    lastActionTimestamp = now;
  }, { capture: true });

  // Centralized action execution engine
  function dispatchInteractiveEvent(el, originalEvent) {
    // 1. Quick-add buttons (+) on food cards or lists
    var addBtn = (el.classList.contains('add') || el.classList.contains('add-to-cart-btn')) ? el : el.closest('.add, .add-to-cart-btn');
    if (addBtn) {
      if (originalEvent) {
        if (originalEvent.preventDefault && originalEvent.cancelable) originalEvent.preventDefault();
        if (originalEvent.stopPropagation) originalEvent.stopPropagation();
        if (originalEvent.stopImmediatePropagation) originalEvent.stopImmediatePropagation();
      }
      var dName = addBtn.getAttribute('data-name');
      var dPrice = parseFloat(addBtn.getAttribute('data-price') || '0');
      var dIcon = addBtn.getAttribute('data-icon') || '🍽️';
      var dStore = addBtn.getAttribute('data-store') || 'FoodDash Grill';
      if (dName && typeof window.addCart === 'function') {
        window.addCart(dName, dPrice, dIcon, dStore);
        return;
      }
      if (typeof addBtn.onclick === 'function') {
        try { addBtn.onclick.call(addBtn, originalEvent); return; } catch(err) {}
      }
    }

    // 2. Quantity decrement / increment buttons
    if (el.classList.contains('btn-qty-minus') || el.classList.contains('btn-qty-plus') || el.closest('.qty')) {
      var qBtn = el.tagName === 'BUTTON' ? el : el.closest('button');
      if (qBtn && typeof qBtn.onclick === 'function') {
        try { qBtn.onclick.call(qBtn, originalEvent); return; } catch(err) {}
      }
    }

    // 3. Bottom navigation tabs
    var navItem = el.getAttribute('data-screen') ? el : el.closest('[data-screen], .nav');
    if (navItem) {
      var screen = navItem.getAttribute('data-screen');
      if (screen && typeof window.showScreen === 'function') {
        window.showScreen(screen);
        return;
      }
    }

    // 4. Readonly search bar on home screen
    if (el.classList.contains('search') && el.querySelector('input[readonly]')) {
      if (typeof window.showScreen === 'function') {
        window.showScreen('search');
        return;
      }
    }

    // 5. Category filters and chip selection
    if (el.classList.contains('chip') || el.classList.contains('cancel-chip') || el.hasAttribute('data-category')) {
      var chipTarget = el.classList.contains('chip') || el.classList.contains('cancel-chip') ? el : el.closest('.chip, .cancel-chip');
      if (chipTarget && typeof chipTarget.onclick === 'function') {
        try { chipTarget.onclick.call(chipTarget, originalEvent); return; } catch(err) {}
      }
    }

    // 6. "Order Now" button in hero banner
    if (el.tagName === 'BUTTON' && el.closest('.hero')) {
      if (typeof window.showScreen === 'function') {
        window.showScreen('stores');
        return;
      }
    }

    // 7. Store cards or row cards
    var card = el.closest('.card, .store, .row-card, .food-card');
    if (card && typeof card.onclick === 'function') {
      try { card.onclick.call(card, originalEvent); return; } catch(err) {}
    }

    // 8. Explicit inline onclick handler
    if (typeof el.onclick === 'function') {
      try { el.onclick.call(el, originalEvent); return; } catch(err) {}
    }

    // 9. Standard programmatic click with synthetic flag
    try {
      var evt = new MouseEvent('click', { bubbles: true, cancelable: true, view: window });
      evt.__isSyntheticTouchClick = true;
      el.dispatchEvent(evt);
    } catch (err) {
      if (typeof el.click === 'function') el.click();
    }
  }

  // Ensure all interactive DOM elements have explicit pointer-events: auto
  function ensureTouchReadiness(root) {
    var scope = root || document;
    var elements = scope.querySelectorAll(
      'button, a, .card, .add-to-cart-btn, .store, .food-card, .row-card, .chip, .cancel-chip, .search, .icon-btn, .nav, .add, .btn-qty-minus, .btn-qty-plus, [onclick], [data-action], [data-screen], [data-category]'
    );
    for (var i = 0; i < elements.length; i++) {
      var item = elements[i];
      if (!item._touchConfigured) {
        item._touchConfigured = true;
        item.style.pointerEvents = 'auto';
        item.style.touchAction = 'manipulation';
        item.style.webkitTapHighlightColor = 'transparent';
      }
    }
  }

  // Initialize on load and observe future DOM mutations
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() { ensureTouchReadiness(document); });
  } else {
    ensureTouchReadiness(document);
  }

  if (window.MutationObserver) {
    var observer = new MutationObserver(function(mutations) {
      mutations.forEach(function(mutation) {
        if (mutation.addedNodes && mutation.addedNodes.length) {
          for (var j = 0; j < mutation.addedNodes.length; j++) {
            var node = mutation.addedNodes[j];
            if (node.nodeType === 1) {
              ensureTouchReadiness(node);
            }
          }
        }
      });
    });
    observer.observe(document.body, { childList: true, subtree: true });
  }
})();
</script>

<!-- Global Event Delegation for Mobile Webviews: All buttons, .card elements, and .add-to-cart-btn elements -->
<script id="global-event-delegation-script">
(function() {
  var DELEGATION_SELECTOR = 'button, .card, .add-to-cart-btn';

  function findDelegatedTarget(node) {
    if (!node || node === document || node === document.body) return null;
    return typeof node.closest === 'function' ? node.closest(DELEGATION_SELECTOR) : null;
  }

  function handleDelegatedAction(target, event) {
    if (!target || target.disabled || target.getAttribute('disabled')) return;

    // Avoid double-firing on quantity and add-to-cart buttons already handled by primary delegation
    if (target.matches('.btn-qty-minus, .btn-qty-plus, .add, .add-to-cart-btn') || target.closest('.qty, .add, .add-to-cart-btn')) {
      return;
    }

    // Execute inline or assigned onclick handler
    if (typeof target.onclick === 'function') {
      try {
        target.onclick.call(target, event);
        return;
      } catch (err) {
        console.error('Error in target onclick:', err);
      }
    }

    // Programmatic click dispatch if not synthetic
    if (!event.__isSyntheticTouchClick && typeof target.click === 'function') {
      try {
        var synClick = new MouseEvent('click', { bubbles: true, cancelable: true, view: window });
        synClick.__isSyntheticTouchClick = true;
        target.dispatchEvent(synClick);
      } catch (e) {
        target.click();
      }
    }
  }

  // Mobile Webview Touch Delegation
  var touchTarget = null;
  var touchMoved = false;
  var startX = 0;
  var startY = 0;
  var lastHandled = 0;

  document.addEventListener('touchstart', function(e) {
    if (!e.touches || e.touches.length !== 1) return;
    touchMoved = false;
    startX = e.touches[0].clientX;
    startY = e.touches[0].clientY;
    var target = document.elementFromPoint(startX, startY) || e.target;
    touchTarget = findDelegatedTarget(target);
  }, { passive: true, capture: true });

  document.addEventListener('touchmove', function(e) {
    if (!e.touches || e.touches.length !== 1) return;
    var dx = Math.abs(e.touches[0].clientX - startX);
    var dy = Math.abs(e.touches[0].clientY - startY);
    if (dx > 10 || dy > 10) {
      touchMoved = true;
      touchTarget = null;
    }
  }, { passive: true, capture: true });

  document.addEventListener('touchend', function(e) {
    if (touchMoved || !touchTarget) {
      touchTarget = null;
      return;
    }

    var el = touchTarget;
    touchTarget = null;

    var now = Date.now();
    if (now - lastHandled < 280) return;
    lastHandled = now;

    if (e.cancelable) {
      e.preventDefault();
    }

    handleDelegatedAction(el, e);
  }, { passive: false, capture: true });

  // Click Delegation Fallback for Webview and Desktop
  document.addEventListener('click', function(e) {
    if (e.__isSyntheticTouchClick) return;

    var now = Date.now();
    if (now - lastHandled < 400) return;

    var target = findDelegatedTarget(e.target);
    if (!target) return;

    // Avoid double-firing: add buttons and quantity buttons are handled by primary delegation and native/inline onclick
    if (target.matches('.btn-qty-minus, .btn-qty-plus, .add, .add-to-cart-btn') || target.closest('.qty, .add, .add-to-cart-btn')) {
      return;
    }
  }, { capture: true });
})();
</script>

</body>
</html>




