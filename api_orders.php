<?php
/**
 * Food Dash - Orders API Endpoint
 * Accepts JSON or form POST payloads to create customer orders.
 * Supports: 'cod', 'gcash', 'maya', 'gotyme', 'apple_pay'.
 * Generates unique order number (e.g. FD-261020-XXXX).
 * Inserts with initial status 'pending' (or payment confirmed).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

// Ensure user session exists (fallback to default customer if in guest/demo mode)
if (empty($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 15;
    $_SESSION['username'] = 'customer';
    $_SESSION['full_name'] = 'Customer';
    $_SESSION['role'] = 'customer';
}

$user_id = (int)$_SESSION['user_id'];
$customer_name = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Customer');

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' || !empty($payload)) {
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

    // Financials
    $total = !empty($payload['total']) ? (float)$payload['total'] : (!empty($payload['total_amount']) ? (float)$payload['total_amount'] : 489.00);
    $delivery_fee = isset($payload['delivery_fee']) ? (float)$payload['delivery_fee'] : 30.00;
    $subtotal = round(max(0, $total - $delivery_fee), 2);

    // Delivery location
    $delivery_address = trim((string)($payload['delivery_address'] ?? 'Western Leyte College'));
    if ($delivery_address === '') {
        $delivery_address = 'Western Leyte College';
    }
    $delivery_lat = isset($payload['delivery_lat']) && is_numeric($payload['delivery_lat']) ? (float)$payload['delivery_lat'] : 11.01020;
    $delivery_lng = isset($payload['delivery_lng']) && is_numeric($payload['delivery_lng']) ? (float)$payload['delivery_lng'] : 124.61779;
    $customer_lat = $delivery_lat;
    $customer_lng = $delivery_lng;

    // Cart / items summary
    $cart = $payload['cart'] ?? null;
    if (is_string($cart)) {
        $decoded = json_decode($cart, true);
        if (is_array($decoded)) $cart = $decoded;
    }
    if (is_array($cart) && !empty($cart)) {
        $items_summary = implode(', ', array_map(function($i) {
            $qty = $i['qty'] ?? 1;
            $name = $i['name'] ?? 'Meal';
            return "{$qty}x {$name}";
        }, $cart));
    } else {
        $items_summary = '1x Signature Meal Combo';
    }

    $store_name = 'Food Dash Grill';
    $shop_id = 1;
    $initial_status = ($payment_status === 'paid') ? 'PREPARING' : 'PENDING';

    try {
        $stmt = $conn->prepare("INSERT INTO food_orders 
            (shop_id, customer_id, user_id, customer_name, order_ref, store_name, items_summary, subtotal, delivery_fee, total_amount, payment_method, payment_status, delivery_address, delivery_lat, delivery_lng, customer_lat, customer_lng, status, delivery_status, order_type, created_at) 
            VALUES 
            (:shop_id, :cust_id, :uid, :cust_name, :ref, :store, :summary, :subtotal, :fee, :total, :pm, :pstatus, :addr, :dlat, :dlng, :clat, :clng, :status, 'waiting', 'Delivery', NOW())");

        $stmt->execute([
            ':shop_id'   => $shop_id,
            ':cust_id'   => $user_id,
            ':uid'       => $user_id,
            ':cust_name' => $customer_name,
            ':ref'       => $order_ref,
            ':store'     => $store_name,
            ':summary'   => $items_summary,
            ':subtotal'  => $subtotal,
            ':fee'       => $delivery_fee,
            ':total'     => $total,
            ':pm'        => $payment_method,
            ':pstatus'   => $payment_status,
            ':addr'      => $delivery_address,
            ':dlat'      => $delivery_lat,
            ':dlng'      => $delivery_lng,
            ':clat'      => $customer_lat,
            ':clng'      => $customer_lng,
            ':status'    => $initial_status
        ]);

        $order_id = (int)$conn->lastInsertId();

        // Mirror into orders table
        try {
            $is_pgsql = ($conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql');
            if ($is_pgsql) {
                $orders_stmt = $conn->prepare("INSERT INTO orders (order_id, user_id, status, total_price, payment_method, shop_id, created_at) VALUES (:oid, :uid, 'pending', :total, :pm, :shop_id, NOW()) ON CONFLICT (order_id) DO NOTHING");
            } else {
                $orders_stmt = $conn->prepare("INSERT INTO orders (order_id, user_id, status, total_price, payment_method, shop_id, created_at) VALUES (:oid, :uid, 'pending', :total, :pm, :shop_id, NOW()) ON DUPLICATE KEY UPDATE payment_method = VALUES(payment_method), total_price = VALUES(total_price)");
            }
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
