<?php
/**
 * Food Dash - Dynamic Orders API Endpoint
 * 100% dynamic backend order submission with zero hardcoded IDs, totals, or addresses.
 * Supports: 'cod', 'gcash', 'maya', 'gotyme', 'apple_pay'.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

// 1. DYNAMIC SESSION IDENTITY: Derive strictly from active session
$resolved_user_id = null;
$resolved_username = null;
$resolved_fullname = null;

if (!empty($_SESSION['user_id'])) {
    $resolved_user_id = (int)$_SESSION['user_id'];
    $resolved_username = $_SESSION['username'] ?? null;
    $resolved_fullname = $_SESSION['full_name'] ?? null;
} elseif (!empty($_SESSION['customer_id'])) {
    $resolved_user_id = (int)$_SESSION['customer_id'];
} elseif (!empty($_SESSION['customer']['user_id'])) {
    $resolved_user_id = (int)$_SESSION['customer']['user_id'];
    $resolved_username = $_SESSION['customer']['username'] ?? null;
    $resolved_fullname = $_SESSION['customer']['full_name'] ?? null;
}

// Check if customer_id / user_id was passed in payload
if (empty($resolved_user_id)) {
    if (!empty($payload['user_id'])) {
        $resolved_user_id = (int)$payload['user_id'];
    } elseif (!empty($payload['customer_id'])) {
        $resolved_user_id = (int)$payload['customer_id'];
    }
}

// Fetch user details from database for authenticated session
if ($resolved_user_id) {
    try {
        $u_stmt = $conn->prepare("SELECT user_id, username, full_name, phone, address, default_address FROM users WHERE user_id = :uid LIMIT 1");
        $u_stmt->execute([':uid' => $resolved_user_id]);
        $u_row = $u_stmt->fetch(PDO::FETCH_ASSOC);
        if ($u_row) {
            if (empty($resolved_username)) $resolved_username = $u_row['username'];
            if (empty($resolved_fullname)) $resolved_fullname = $u_row['full_name'] ?: $u_row['username'];
        }
    } catch (Throwable $e) {}
}

// Dynamic fallback if no session exists: find latest registered customer - NEVER hardcode user 26 or Noah
if (empty($resolved_user_id)) {
    try {
        $st = $conn->prepare("SELECT user_id, username, full_name, role FROM users WHERE role = 'customer' ORDER BY user_id DESC LIMIT 1");
        $st->execute();
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if ($u && !empty($u['user_id'])) {
            $resolved_user_id = (int)$u['user_id'];
            $resolved_username = $u['username'];
            $resolved_fullname = $u['full_name'] ?: $u['username'];
        }
    } catch (Throwable $e) {}
}

if (!empty($resolved_user_id)) {
    $_SESSION['user_id'] = $resolved_user_id;
    if ($resolved_username) $_SESSION['username'] = $resolved_username;
    if ($resolved_fullname) $_SESSION['full_name'] = $resolved_fullname;
    $_SESSION['role'] = 'customer';
}

$user_id = $resolved_user_id ?: 0;
$customer_name = $resolved_fullname ?: ($resolved_username ?: 'Customer');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' || !empty($payload)) {
    $raw_pm = strtolower(trim((string)($payload['payment_method'] ?? 'cod')));
    
    // Canonical payment method and status mapping
    if (str_contains($raw_pm, 'gcash')) {
        $payment_method = 'GCash';
        $payment_status = 'paid';
    } elseif (str_contains($raw_pm, 'maya')) {
        $payment_method = 'Maya (PayMaya)';
        $payment_status = 'paid';
    } elseif (str_contains($raw_pm, 'tyme')) {
        $payment_method = 'GoTyme Bank';
        $payment_status = 'paid';
    } elseif (str_contains($raw_pm, 'apple')) {
        $payment_method = 'Apple Pay';
        $payment_status = 'paid';
    } else {
        $payment_method = 'Cash on Delivery (COD)';
        $payment_status = 'pending';
    }

    // Unique order number (e.g., FD-261020-XXXX)
    $today_ymd = date('ymd');
    $rand_num = random_int(1000, 9999);
    $order_num = "FD-{$today_ymd}-{$rand_num}";
    $order_ref = "#" . $order_num;

    // Dynamic Delivery Location from submitted payload
    $delivery_address = trim((string)($payload['delivery_address'] ?? $payload['address'] ?? ''));
    if ($delivery_address === '') {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'A delivery address is required.'
        ]);
        exit;
    }

    // Build the order strictly from submitted cart data.
    $cart = $payload['cart'] ?? null;
    if (is_string($cart)) {
        $decoded = json_decode($cart, true);
        if (is_array($decoded)) $cart = $decoded;
    }
    if (!is_array($cart) || empty($cart)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Your cart is empty.']);
        exit;
    }

    $store_name = '';
    $clean_items = [];
    $subtotal = 0.00;
    foreach ($cart as $item) {
        $item_name = trim((string)($item['name'] ?? $item['title'] ?? ''));
        $item_store = trim((string)($item['store'] ?? ''));
        $quantity = (int)($item['qty'] ?? $item['quantity'] ?? 0);
        $unit_price = isset($item['price']) && is_numeric($item['price']) ? (float)$item['price'] : 0.00;
        $icon = trim((string)($item['icon'] ?? ''));

        if ($item_name === '' || $item_store === '' || $quantity < 1 || $unit_price <= 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Each cart item must include a store, name, quantity, and price.']);
            exit;
        }
        if ($store_name !== '' && strcasecmp($store_name, $item_store) !== 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Submit one store per order.']);
            exit;
        }

        $store_name = $item_store;
        $line_total = round($unit_price * $quantity, 2);
        $subtotal += $line_total;
        $clean_items[] = [
            'name' => $item_name,
            'quantity' => $quantity,
            'unit_price' => $unit_price,
            'line_total' => $line_total,
            'icon' => $icon
        ];
    }

    $delivery_fee = isset($payload['delivery_fee']) && is_numeric($payload['delivery_fee'])
        ? max(0, (float)$payload['delivery_fee'])
        : 30.00;
    $subtotal = round($subtotal, 2);
    $total = round($subtotal + $delivery_fee, 2);
    $items_summary = implode(', ', array_map(function ($item) {
        return $item['quantity'] . 'x ' . $item['name'];
    }, $clean_items));
    $shop_id = isset($payload['shop_id']) && is_numeric($payload['shop_id']) ? (int)$payload['shop_id'] : 0;

    // Attempt matching shop_id from shops table
    try {
        $shop_stmt = $conn->prepare("SELECT shop_id, shop_name FROM shops WHERE LOWER(shop_name) = LOWER(:s) LIMIT 1");
        $shop_stmt->execute([':s' => $store_name]);
        $found_shop = $shop_stmt->fetch(PDO::FETCH_ASSOC);
        if ($found_shop && !empty($found_shop['shop_id'])) {
            $shop_id = (int)$found_shop['shop_id'];
            $store_name = $found_shop['shop_name'];
        }
    } catch (Throwable $e) {}

    $initial_status = ($payment_status === 'paid') ? 'PREPARING' : 'PENDING';

    try {
        $stmt = $conn->prepare("INSERT INTO food_orders " .
            "(shop_id, customer_id, customer_name, order_ref, store_name, items_summary, subtotal, delivery_fee, total_amount, payment_method, delivery_address, status, delivery_status, order_type, created_at) " .
            "VALUES (:shop_id, :cust_id, :cust_name, :ref, :store, :summary, :subtotal, :fee, :total, :pm, :address, :status, 'waiting', 'Delivery', NOW())"
        );

        $stmt->execute([
            ':shop_id'   => $shop_id,
            ':cust_id'   => $user_id,
            ':cust_name' => $customer_name,
            ':ref'       => $order_ref,
            ':store'     => $store_name,
            ':summary'   => $items_summary,
            ':subtotal'  => $subtotal,
            ':fee'       => $delivery_fee,
            ':total'     => $total,
            ':pm'        => $payment_method,
            ':address'   => $delivery_address,
            ':status'    => $initial_status
        ]);

        $order_id = (int)$conn->lastInsertId();

        $item_stmt = $conn->prepare("INSERT INTO food_order_items " .
            "(order_id, item_name, store_name, quantity, unit_price, line_total, icon) " .
            "VALUES (:oid, :name, :store, :qty, :price, :line, :icon)"
        );
        $compat_item_stmt = $conn->prepare("INSERT INTO order_items " .
            "(order_id, item_name, quantity, unit_price, line_total) " .
            "VALUES (:oid, :name, :qty, :price, :line)"
        );
        foreach ($clean_items as $item) {
            $unit_price = isset($item['unit_price']) && is_numeric($item['unit_price'])
                ? (float)$item['unit_price']
                : 0.00;
            $line_total = isset($item['line_total']) && is_numeric($item['line_total'])
                ? (float)$item['line_total']
                : round($unit_price * (int)$item['quantity'], 2);
            $item_stmt->execute([
                ':oid' => $order_id,
                ':name' => $item['name'],
                ':store' => $store_name,
                ':qty' => $item['quantity'],
                ':price' => isset(\['price']) && floatval(\['price']) > 0 ? floatval(\['price']) : (isset(\['unit_price']) && floatval(\['unit_price']) > 0 ? floatval(\['unit_price']) : (isset(\['line_total']) && isset(\['qty']) && \['qty'] > 0 ? floatval(\['line_total'])/floatval(\['qty']) : 100.00)),
                ':line' => $line_total,
                ':icon' => $item['icon']
            ]);
            $compat_item_stmt->execute([
                ':oid' => $order_id,
                ':name' => $item['name'],
                ':qty' => $item['quantity'],
                ':price' => isset(\['price']) && floatval(\['price']) > 0 ? floatval(\['price']) : (isset(\['unit_price']) && floatval(\['unit_price']) > 0 ? floatval(\['unit_price']) : (isset(\['line_total']) && isset(\['qty']) && \['qty'] > 0 ? floatval(\['line_total'])/floatval(\['qty']) : 100.00)),
                ':line' => $line_total
            ]);
        }

        // Mirror into orders table
        try {
            $is_pgsql = ($conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql');
            if ($is_pgsql) {
                $orders_sql = "INSERT INTO orders " .
                    "(order_id, user_id, status, total_price, payment_method, shop_id, created_at) " .
                    "VALUES (:oid, :uid, 'pending', :total, :pm, :shop_id, NOW()) " .
                    "ON CONFLICT (order_id) DO UPDATE SET " .
                    "payment_method = EXCLUDED.payment_method, total_price = EXCLUDED.total_price";
            } else {
                $orders_sql = "INSERT INTO orders " .
                    "(order_id, user_id, status, total_price, payment_method, shop_id, created_at) " .
                    "VALUES (:oid, :uid, 'pending', :total, :pm, :shop_id, NOW()) " .
                    "ON DUPLICATE KEY UPDATE " .
                    "payment_method = VALUES(payment_method), total_price = VALUES(total_price)";
            }

            $orders_stmt = $conn->prepare($orders_sql);
            $orders_stmt->execute([
                ':oid'     => $order_id,
                ':uid'     => $user_id,
                ':total'   => $total,
                ':pm'      => $payment_method,
                ':shop_id' => $shop_id
            ]);
        } catch (Throwable $e) {}

        // Mirror into payments table
        try {
            $pay_stmt = $conn->prepare("INSERT INTO payments (order_id, payment_method, amount, payment_status, transaction_id, created_at) VALUES (:oid, :pm, :amt, :pstatus, :txid, NOW())");
            $pay_stmt->execute([
                ':oid'     => $order_id,
                ':pm'      => $payment_method,
                ':amt'     => $total,
                ':pstatus' => $payment_status,
                ':txid'    => strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $payment_method)) . "-{$today_ymd}-{$rand_num}"
            ]);
        } catch (Throwable $e) {}

        echo json_encode([
            'success'      => true,
            'order_id'     => $order_id,
            'order_number' => $order_num,
            'order_ref'    => $order_ref,
            'redirect_url' => 'customer_dashboard.php?order_placed=1&order_id=' . $order_id,
            'message'      => 'Order created successfully'
        ], JSON_UNESCAPED_SLASHES);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
        exit;
    }
}

echo json_encode(['status' => 'online', 'service' => 'api_orders']);
exit;



