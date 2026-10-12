<?php
session_start();
require_once 'db_connect.php';
require_once 'notification_lib.php';

header('Content-Type: application/json; charset=utf-8');

function json_response(bool $success, string $message = '', array $extra = [], int $status = 200): never {
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra), JSON_UNESCAPED_SLASHES);
    exit;
}

$pdo = $conn;

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    json_response(false, 'Authentication required.', [], 401);
}

$role = strtolower(trim((string)$_SESSION['role']));
$user_id = (int)$_SESSION['user_id'];
$user_name = $_SESSION['full_name'] ?? ucfirst($role);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$action = trim((string)($input['action'] ?? ''));

// IAS HELP DESK: Admin-Assisted Password Reset & Account Unlock API
if ($action === 'admin_reset_password' || $action === 'action_reset_password_unlock') {
    if ($role !== 'admin') {
        json_response(false, 'Unauthorized. Administrative access required.', [], 403);
    }

    $target_user_id = (int)($input['user_id'] ?? 0);
    $new_password = (string)($input['new_password'] ?? '');
    $confirm_password = (string)($input['confirm_new_password'] ?? $input['confirm_password'] ?? '');
    $unlock_account = !empty($input['unlock_account']);

    if ($target_user_id <= 0) {
        json_response(false, 'Invalid target user ID.', [], 400);
    }
    if (empty($new_password) || empty($confirm_password)) {
        json_response(false, 'New password and confirm password fields are required.', [], 400);
    }
    if ($new_password !== $confirm_password) {
        json_response(false, 'Passwords do not match. Please verify and re-enter.', [], 400);
    }

    $pass_errs = validate_strong_password($new_password);
    if (!empty($pass_errs)) {
        json_response(false, implode(" ", $pass_errs), [], 400);
    }

    $u_stmt = $pdo->prepare("SELECT user_id, username, full_name, role FROM users WHERE user_id = :uid LIMIT 1");
    $u_stmt->execute([':uid' => $target_user_id]);
    $target_user = $u_stmt->fetch();

    if (!$target_user) {
        json_response(false, 'Target user account not found.', [], 404);
    }

    $target_username = $target_user['username'];
    $hashed = password_hash($new_password, PASSWORD_BCRYPT);

    if ($unlock_account) {
        $upd = $pdo->prepare("UPDATE users SET password = :pwd, failed_attempts = 0, lockout_until = NULL WHERE user_id = :uid");
        $upd->execute([':pwd' => $hashed, ':uid' => $target_user_id]);
    } else {
        $upd = $pdo->prepare("UPDATE users SET password = :pwd WHERE user_id = :uid");
        $upd->execute([':pwd' => $hashed, ':uid' => $target_user_id]);
    }

    $admin_uname = $_SESSION['username'] ?? $user_name;
    log_activity(
        $pdo,
        (int)$_SESSION['user_id'],
        'ADMIN_PASSWORD_RESET',
        "Admin {$admin_uname} reset password and unlocked account for User ID: {$target_user_id} ({$target_username})"
    );

    json_response(true, 'Password reset successfully. Account lockout cleared.', [
        'user_id'  => $target_user_id,
        'username' => $target_username,
        'unlocked' => $unlock_account
    ]);
}

$order_id = (int)($input['order_id'] ?? 0);
$raw_status = trim((string)($input['status'] ?? ''));

if ($order_id <= 0) {
    json_response(false, 'Invalid order ID.', [], 400);
}

// Canonical status normalizer
function normalize_canonical_status(string $status): string {
    $s = strtoupper(trim(str_replace([' ', '-'], '_', $status)));
    $map = [
        'SUBMITTED'       => 'PENDING',
        'PENDING'         => 'PENDING',
        'ACCEPTED'        => 'PREPARING',
        'PREPARING'       => 'PREPARING',
        'READY'           => 'READY_FOR_PICKUP',
        'READY_FOR_PICKUP'=> 'READY_FOR_PICKUP',
        'PICKUP_READY'    => 'READY_FOR_PICKUP',
        'ASSIGNED'        => 'OUT_FOR_DELIVERY',
        'ON_DELIVERY'     => 'OUT_FOR_DELIVERY',
        'ON_THE_WAY'      => 'OUT_FOR_DELIVERY',
        'OUT_FOR_DELIVERY'=> 'OUT_FOR_DELIVERY',
        'NEAR_CUSTOMER'   => 'OUT_FOR_DELIVERY',
        'IN_TRANSIT'      => 'OUT_FOR_DELIVERY',
        'DELIVERED'       => 'DELIVERED',
        'COMPLETED'       => 'DELIVERED',
        'SETTLED'         => 'SETTLED',
        'RECONCILED'      => 'SETTLED',
        'CANCELLED'       => 'CANCELLED',
    ];
    return $map[$s] ?? $s;
}

$target_status = normalize_canonical_status($raw_status);
if ($target_status === '') {
    // Check if action itself dictates status
    if ($action === 'chef_accept') $target_status = 'PREPARING';
    elseif ($action === 'chef_ready') $target_status = 'READY_FOR_PICKUP';
    elseif ($action === 'claim') $target_status = 'OUT_FOR_DELIVERY';
    elseif ($action === 'delivered') $target_status = 'DELIVERED';
    elseif ($action === 'settle' || $action === 'reconcile') $target_status = 'SETTLED';
}

if ($target_status === '') {
    json_response(false, 'Target status is required.', [], 400);
}

$valid_customer_transitions = [
    'PENDING'          => ['CANCELLED'],
    'OUT_FOR_DELIVERY' => ['DELIVERED'],
];
$valid_chef_transitions = [
    'PENDING'   => ['PREPARING'],
    'PREPARING' => ['READY_FOR_PICKUP'],
];
$valid_rider_transitions = [
    'READY_FOR_PICKUP' => ['OUT_FOR_DELIVERY'],
    'OUT_FOR_DELIVERY' => ['OUT_FOR_DELIVERY', 'DELIVERED'],
];
$valid_admin_transitions = [
    'DELIVERED' => ['SETTLED'],
];

$admin_override = ($role === 'admin');

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT order_id, shop_id, customer_id, customer_name, order_ref, store_name, subtotal, delivery_fee, total_amount, status, delivery_status, chef_id, rider_id FROM food_orders WHERE order_id = :id FOR UPDATE");
    $stmt->execute([':id' => $order_id]);
    $order = $stmt->fetch();

    if (!$order) {
        $pdo->rollBack();
        json_response(false, 'Order not found.', [], 404);
    }

    $current_status = normalize_canonical_status($order['status']);
    $delivery_status = strtolower($order['delivery_status'] ?? 'waiting');

    $allowed = false;
    $new_status = $target_status;
    $new_delivery_status = $delivery_status;
    $notes = '';

    if ($admin_override) {
        $allowed = true;
        if ($target_status === 'SETTLED') {
            $new_delivery_status = 'settled';
            $notes = 'Transaction reconciled and settled by Admin';
        } else {
            $new_delivery_status = strtolower($target_status);
            $notes = "Admin forced state transition to {$target_status}";
        }
    } elseif ($role === 'customer') {
        if (isset($valid_customer_transitions[$current_status]) && in_array($target_status, $valid_customer_transitions[$current_status], true)) {
            $allowed = true;
            if ($target_status === 'CANCELLED') {
                $new_delivery_status = 'cancelled';
                $notes = 'Order cancelled by customer';
            } elseif ($target_status === 'DELIVERED') {
                $new_delivery_status = 'delivered';
                $notes = 'Order received confirmed by customer';
            }
        }
    } elseif ($role === 'chef' || $role === 'manager') {
        if (isset($valid_chef_transitions[$current_status]) && in_array($target_status, $valid_chef_transitions[$current_status], true)) {
            $allowed = true;
            if ($target_status === 'PREPARING') {
                $new_delivery_status = 'preparing';
                $notes = 'Chef accepted order and started preparation';
            } elseif ($target_status === 'READY_FOR_PICKUP') {
                $new_delivery_status = 'ready_for_pickup';
                $notes = 'Food prepared and packed, ready for rider pickup';
            }
        }
    } elseif ($role === 'rider') {
        if (isset($valid_rider_transitions[$current_status]) && in_array($target_status, $valid_rider_transitions[$current_status], true)) {
            $allowed = true;
            if ($target_status === 'OUT_FOR_DELIVERY') {
                $sub = strtolower(trim((string)($input['sub_status'] ?? $action)));
                if ($sub === 'near') {
                    $new_delivery_status = 'near_customer';
                    $notes = 'Rider is near customer location';
                } elseif ($sub === 'on_way') {
                    $new_delivery_status = 'on_the_way';
                    $notes = 'Rider is on the way to customer';
                } else {
                    $new_delivery_status = 'out_for_delivery';
                    $notes = 'Rider claimed order and started delivery';
                }
            } elseif ($target_status === 'DELIVERED') {
                $new_delivery_status = 'delivered';
                $notes = 'Order successfully delivered to customer';
            }
        }
    }

    if (!$allowed) {
        $pdo->rollBack();
        json_response(false, "Invalid state transition from {$current_status} to {$target_status} for role {$role}.", [
            'current_status'   => $current_status,
            'attempted_status' => $target_status,
            'role'             => $role
        ], 403);
    }

    // Role assignment updates
    $chef_id = $order['chef_id'];
    $rider_id = $order['rider_id'];

    if (($role === 'chef' || $role === 'manager') && empty($chef_id)) {
        $chef_id = $user_id;
    }
    if ($role === 'rider' && $target_status === 'OUT_FOR_DELIVERY') {
        if (!empty($rider_id) && (int)$rider_id !== $user_id) {
            $pdo->rollBack();
            json_response(false, 'Order already assigned to another rider.', [], 409);
        }
        $rider_id = $user_id;

        $u_rider = $pdo->prepare("UPDATE riders SET status = 'on_delivery', current_order = :ref WHERE user_id = :uid OR rider_id = :rid");
        $u_rider->execute([':ref' => $order['order_ref'], ':uid' => $user_id, ':rid' => $user_id]);
    }
    if ($target_status === 'DELIVERED' && !empty($rider_id)) {
        $u_rider = $pdo->prepare("UPDATE riders SET status = 'waiting', current_order = 'None' WHERE user_id = :uid OR rider_id = :rid");
        $u_rider->execute([':uid' => $rider_id, ':rid' => $rider_id]);
    }

    // Update food_orders
    $stmt = $pdo->prepare("UPDATE food_orders SET status = :status, delivery_status = :delivery_status, chef_id = :chef_id, rider_id = :rider_id, updated_at = NOW() WHERE order_id = :id");
    $stmt->execute([
        ':status'          => $target_status,
        ':delivery_status' => $new_delivery_status,
        ':chef_id'         => $chef_id,
        ':rider_id'        => $rider_id,
        ':id'              => $order_id
    ]);

    // Insert Settlement record if SETTLED
    if ($target_status === 'SETTLED') {
        $settle_stmt = $pdo->prepare("INSERT INTO settlements (order_id, admin_id, subtotal, delivery_fee, total_amount, payment_method, status, notes, reconciled_at) VALUES (:oid, :aid, :sub, :del, :tot, 'Cash on Delivery', 'SETTLED', :notes, NOW()) ON CONFLICT (order_id) DO UPDATE SET status = 'SETTLED', notes = EXCLUDED.notes, reconciled_at = NOW()");
        $settle_stmt->execute([
            ':oid'   => $order_id,
            ':aid'   => ($role === 'admin' ? $user_id : 14),
            ':sub'   => $order['subtotal'],
            ':del'   => $order['delivery_fee'],
            ':tot'   => $order['total_amount'],
            ':notes' => 'Reconciled & settled by ' . $user_name
        ]);
    }

    // Insert into order_audit_logs
    record_order_audit_log($pdo, $order_id, $current_status, $target_status, $user_id, $role, $notes);

    // Send contextual notifications
    $cust_id = (int)$order['customer_id'];
    $ref = $order['order_ref'];

    if ($target_status === 'PREPARING') {
        create_notification($pdo, $cust_id, 'chef_preparing', 'Kitchen Preparing Order', "Your order {$ref} is now being prepared by the Chef.", $order_id);
        log_activity($pdo, $user_id, 'ORDER_PREPARED', "Order {$ref} accepted and preparation started by {$user_name} ({$role}).");
    } elseif ($target_status === 'READY_FOR_PICKUP') {
        create_notification($pdo, $cust_id, 'order_ready', 'Order Ready for Pickup', "Your order {$ref} is packed and ready for rider pickup.", $order_id);
        notify_role($pdo, 'rider', 'pickup_ready', 'Order Ready for Pickup', "{$ref} at {$order['store_name']} is ready for dispatch.", $order_id);
        log_activity($pdo, $user_id, 'ORDER_PREPARED', "Order {$ref} packed and ready for rider pickup by {$user_name}.");
    } elseif ($target_status === 'OUT_FOR_DELIVERY') {
        if ($new_delivery_status === 'near_customer') {
            create_notification($pdo, $cust_id, 'rider_near', 'Rider is Near You', "Your rider is arriving shortly with {$ref}.", $order_id);
        } elseif ($new_delivery_status === 'on_the_way') {
            create_notification($pdo, $cust_id, 'rider_on_way', 'Rider on the Way', "Your rider has picked up {$ref} and is on the way.", $order_id);
        } else {
            create_notification($pdo, $cust_id, 'rider_assigned', 'Rider Out for Delivery', "A rider has claimed {$ref} and is out for delivery.", $order_id);
        }
        log_activity($pdo, $user_id, 'ORDER_DISPATCHED', "Order {$ref} dispatched ({$new_delivery_status}) with rider {$user_name}.");
    } elseif ($target_status === 'DELIVERED') {
        create_notification($pdo, $cust_id, 'delivered', 'Order Delivered', "Your order {$ref} has been delivered. Enjoy your meal!", $order_id);
        notify_role($pdo, 'admin', 'order_delivered', 'Order Delivered - Ready for Settlement', "Order {$ref} delivered. Ready for admin reconciliation.", $order_id);
        log_activity($pdo, $user_id, 'ORDER_DELIVERED', "Order {$ref} delivered to customer by {$user_name} ({$role}).");
    } elseif ($target_status === 'SETTLED') {
        create_notification($pdo, $cust_id, 'settled', 'Order Settled', "Transaction for {$ref} has been verified and settled.", $order_id);
        if ($rider_id) {
            create_notification($pdo, (int)$rider_id, 'settled', 'Delivery Settled', "Order {$ref} delivery payment settled.", $order_id);
        }
        log_activity($pdo, $user_id, 'SETTLEMENT_CLOSED', "Order {$ref} reconciled and settled by Admin {$user_name}.");
    }

    $pdo->commit();

    json_response(true, 'Order status updated successfully.', [
        'order_id'        => $order_id,
        'order_ref'       => $order['order_ref'],
        'status'          => $target_status,
        'delivery_status' => $new_delivery_status,
        'previous_status' => $current_status
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Order state update failed: " . $e->getMessage());
    json_response(false, 'Database error: ' . $e->getMessage(), [], 500);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Order state update failed: " . $e->getMessage());
    json_response(false, 'Server error: ' . $e->getMessage(), [], 500);
}
?>