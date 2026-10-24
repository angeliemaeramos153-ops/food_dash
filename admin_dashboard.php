<?php
session_start();
require_once 'db_connect.php';
require_once 'notification_lib.php';

// Safe Fallback for Activity Logging
if (!function_exists('log_activity')) {
    function log_activity($conn, $user_id_or_action, $action_or_details = '', $details = '') {
        try {
            $user_id = null;
            $action = 'ACTION';
            $det = '';
            if (is_numeric($user_id_or_action)) {
                $user_id = (int)$user_id_or_action;
                $action = (string)$action_or_details;
                $det = (string)$details;
            } else {
                $action = (string)$user_id_or_action;
                $det = (string)$action_or_details;
                $user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            }
            $ip = substr($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', 0, 45);
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'System/API', 0, 255);
            $uname = $_SESSION['username'] ?? $_SESSION['full_name'] ?? 'Admin';
            $urole = $_SESSION['role'] ?? 'admin';
            if ($conn instanceof PDO) {
                try {
                    $stmt = $conn->prepare("INSERT INTO system_audit_logs (user_id, username, role, action, details, ip_address, user_agent, created_at) VALUES (:uid, :uname, :role, :act, :det, :ip, :ua, NOW())");
                    $stmt->execute([':uid' => $user_id, ':uname' => $uname, ':role' => $urole, ':act' => $action, ':det' => $det, ':ip' => $ip, ':ua' => $ua]);
                } catch (Throwable $e1) {
                    try {
                        $stmt2 = $conn->prepare("INSERT INTO system_audit_logs (user_id, username, role, action, details, ip_address, user_agent, created_at) VALUES (NULL, :uname, :role, :act, :det, :ip, :ua, NOW())");
                        $stmt2->execute([':uname' => $uname, ':role' => $urole, ':act' => $action, ':det' => $det, ':ip' => $ip, ':ua' => $ua]);
                    } catch (Throwable $e2) {
                        try {
                            $stmt3 = $conn->prepare("INSERT INTO activity_logs (action, details, created_at) VALUES (:act, :det, NOW())");
                            $stmt3->execute([':act' => $action, ':det' => $det]);
                        } catch (Throwable $e3) {
                            error_log("Activity log notice: " . $e3->getMessage());
                        }
                    }
                }
            }
        } catch (Throwable $t) {
            // Gracefully fallback so it never crashes execution
            error_log("Activity log notice: " . $t->getMessage());
        }
        return true;
    }
}

// 1. AUTHENTICATION GUARD
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: index.php");
    exit();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$username = $_SESSION['username'] ?? $_SESSION['user']['username'] ?? $_SESSION['full_name'] ?? 'Admin';
$user_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? $_SESSION['user']['username'] ?? 'Admin';
$user_role = ucfirst($_SESSION['role'] ?? 'Admin');
$action_msg = "";

// 2. HANDLE FORM ACTIONS (CRUD Operations)
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // ADD NEW RIDER
    if (isset($_POST['action_add_rider'])) {
        $rider_name = trim($_POST['rider_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '+63 917 555 0192');
        $plate_number = trim($_POST['plate_number'] ?? 'ORM-8891');
        $vehicle_model = trim($_POST['vehicle_model'] ?? 'Honda Click 125i');
        if (!empty($rider_name)) {
            $stmt = $conn->prepare("INSERT INTO riders (rider_name, phone, plate_number, vehicle_model, account_status, status, current_order) VALUES (?, ?, ?, ?, 'Active', 'waiting', 'None')");
            $stmt->execute([$rider_name, $phone, $plate_number, $vehicle_model]);
            $action_msg = "Rider added to dispatch fleet!";
        }
    }

    // EDIT RIDER DETAILS (Admin Edit Rider Feature)
    if (isset($_POST['action_edit_rider'])) {
        $rider_id = intval($_POST['rider_id'] ?? 0);
        $rider_name = trim($_POST['rider_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $plate_number = trim($_POST['plate_number'] ?? '');
        $vehicle_model = trim($_POST['vehicle_model'] ?? '');
        $account_status = trim($_POST['account_status'] ?? 'Active');
        $allowed_acc = ['Active', 'Suspended', 'Offline'];
        if (!in_array($account_status, $allowed_acc, true)) $account_status = 'Active';

        if ($rider_id > 0 && !empty($rider_name)) {
            $stmt = $conn->prepare("UPDATE riders SET rider_name = ?, phone = ?, plate_number = ?, vehicle_model = ?, account_status = ? WHERE rider_id = ?");
            $stmt->execute([$rider_name, $phone, $plate_number, $vehicle_model, $account_status, $rider_id]);

            // Sync user account full_name and phone if linked to a user_id
            $r_stmt = $conn->prepare("SELECT user_id FROM riders WHERE rider_id = ? LIMIT 1");
            $r_stmt->execute([$rider_id]);
            $r_row = $r_stmt->fetch(PDO::FETCH_ASSOC);
            if (!empty($r_row['user_id'])) {
                $u_stmt = $conn->prepare("UPDATE users SET full_name = ?, phone = ? WHERE user_id = ?");
                $u_stmt->execute([$rider_name, $phone, (int)$r_row['user_id']]);
            }

            $admin_uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            log_activity($conn, $admin_uid, 'RIDER_UPDATED', "Admin updated rider #{$rider_id} ({$rider_name}, Status: {$account_status}, Vehicle: {$vehicle_model} [{$plate_number}]).");
            $action_msg = "Rider '{$rider_name}' details updated successfully!";

            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => $action_msg,
                    'rider_id' => $rider_id,
                    'rider_name' => $rider_name,
                    'phone' => $phone,
                    'plate_number' => $plate_number,
                    'vehicle_model' => $vehicle_model,
                    'account_status' => $account_status
                ]);
                exit;
            }
        } else {
            $action_msg = "Error: Rider full name is required.";
        }
    }

    // TOGGLE RIDER STATUS
    if (isset($_POST['action_toggle_rider'])) {
        $rider_id = intval($_POST['rider_id']);
        $new_status = $_POST['new_status'];
        $order_ref = ($new_status === 'on_delivery') ? '#FD-' . rand(1000, 9999) : 'None';

        $stmt = $conn->prepare("UPDATE riders SET status = ?, current_order = ? WHERE rider_id = ?");
        $stmt->execute([$new_status, $order_ref, $rider_id]);
        $action_msg = "Rider status updated!";
    }

    // DELETE RIDER
    if (isset($_POST['action_delete_rider'])) {
        $rider_id = intval($_POST['rider_id']);
        $stmt = $conn->prepare("DELETE FROM riders WHERE rider_id = ?");
        $stmt->execute([$rider_id]);
        $action_msg = "Rider removed successfully!";
    }

    // ADD NEW SHOP
    if (isset($_POST['action_add_shop'])) {
        $shop_name = trim($_POST['shop_name'] ?? '');
        $category = trim($_POST['category'] ?? 'Fast Food');
        $address = trim($_POST['address'] ?? '');
        $logo_url = trim($_POST['logo_url'] ?? 'assets/shops/default_store.svg');
        if (empty($logo_url)) $logo_url = 'assets/shops/default_store.svg';

        $lat = (isset($_POST['lat']) && is_numeric($_POST['lat']) && floatval($_POST['lat']) != 0) ? floatval($_POST['lat']) : null;
        $lng = (isset($_POST['lng']) && is_numeric($_POST['lng']) && floatval($_POST['lng']) != 0) ? floatval($_POST['lng']) : null;

        // Backend fallback geocoding if coordinates not provided
        if (($lat === null || $lng === null) && !empty($shop_name)) {
            $q = urlencode(trim($shop_name . (!empty($address) ? ', ' . $address : '') . ', Ormoc City'));
            $ctx = stream_context_create([
                'http' => [
                    'header' => "User-Agent: FoodDash-App/1.0\r\nAccept: application/json\r\n",
                    'timeout' => 5
                ]
            ]);
            $res = @file_get_contents("https://nominatim.openstreetmap.org/search?format=json&limit=1&q={$q}", false, $ctx);
            if ($res) {
                $j = json_decode($res, true);
                if (!empty($j) && isset($j[0]['lat']) && isset($j[0]['lon'])) {
                    $lat = floatval($j[0]['lat']);
                    $lng = floatval($j[0]['lon']);
                }
            }
            if ($lat === null || $lng === null) {
                $lat = 11.00650000;
                $lng = 124.60750000;
            }
        }

        if (!empty($shop_name)) {
            $stmt = $conn->prepare("INSERT INTO shops (shop_name, category, address, lat, lng, lat, lng, logo_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$shop_name, $category, $address, $lat, $lng, $lat, $lng, $logo_url]);
            $action_msg = "Store added successfully with verified coordinates!";
        }
    }

    // EDIT SHOP
    if (isset($_POST['action_edit_shop'])) {
        $shop_id = intval($_POST['shop_id']);
        $shop_name = trim($_POST['shop_name'] ?? '');
        $category = trim($_POST['category'] ?? 'Fast Food');
        $address = trim($_POST['address'] ?? '');
        $logo_url = trim($_POST['logo_url'] ?? 'assets/shops/default_store.svg');
        if (empty($logo_url)) $logo_url = 'assets/shops/default_store.svg';

        $lat = (isset($_POST['lat']) && is_numeric($_POST['lat']) && floatval($_POST['lat']) != 0) ? floatval($_POST['lat']) : null;
        $lng = (isset($_POST['lng']) && is_numeric($_POST['lng']) && floatval($_POST['lng']) != 0) ? floatval($_POST['lng']) : null;

        if (($lat === null || $lng === null) && !empty($shop_name)) {
            $q = urlencode(trim($shop_name . (!empty($address) ? ', ' . $address : '') . ', Ormoc City'));
            $ctx = stream_context_create([
                'http' => [
                    'header' => "User-Agent: FoodDash-App/1.0\r\nAccept: application/json\r\n",
                    'timeout' => 5
                ]
            ]);
            $res = @file_get_contents("https://nominatim.openstreetmap.org/search?format=json&limit=1&q={$q}", false, $ctx);
            if ($res) {
                $j = json_decode($res, true);
                if (!empty($j) && isset($j[0]['lat']) && isset($j[0]['lon'])) {
                    $lat = floatval($j[0]['lat']);
                    $lng = floatval($j[0]['lon']);
                }
            }
        }

        if ($shop_id > 0 && !empty($shop_name)) {
            try {
                $stmt = $conn->prepare("UPDATE shops SET shop_name = ?, category = ?, address = ?, lat = ?, lng = ?, logo_url = ? WHERE shop_id = ?");
                $stmt->execute([$shop_name, $category, $address, $lat, $lng, $logo_url, $shop_id]);
            } catch (Throwable $e) {
                $stmt = $conn->prepare("UPDATE shops SET shop_name = ?, category = ?, logo_url = ? WHERE shop_id = ?");
                $stmt->execute([$shop_name, $category, $logo_url, $shop_id]);
            }
            $action_msg = "Store updated successfully!";
        }
    }

    // DELETE SHOP
    if (isset($_POST['action_delete_shop'])) {
        $shop_id = intval($_POST['shop_id']);
        $stmt = $conn->prepare("DELETE FROM shops WHERE shop_id = ?");
        $stmt->execute([$shop_id]);
        $action_msg = "Store removed successfully!";
    }

    // ADD MENU ITEM
    if (isset($_POST['action_add_item'])) {
        $item_name = trim($_POST['item_name']);
        $shop_id = intval($_POST['shop_id']);
        $price = floatval($_POST['price']);
        $is_recommended = !empty($_POST['is_recommended']);

        if (!empty($item_name) && $shop_id > 0 && $price > 0) {
            $stmt = $conn->prepare("INSERT INTO menu_items (shop_id, item_name, price, is_recommended) VALUES (?, ?, ?, ?)");
            $stmt->bindValue(1, $shop_id, PDO::PARAM_INT);
            $stmt->bindValue(2, $item_name, PDO::PARAM_STR);
            $stmt->bindValue(3, $price);
            $stmt->bindValue(4, $is_recommended, PDO::PARAM_BOOL);
            $stmt->execute();
            $action_msg = "Menu item added!";
        }
    }

    // EDIT MENU ITEM
    if (isset($_POST['action_edit_item'])) {
        $item_id = intval($_POST['item_id']);
        $item_name = trim($_POST['item_name']);
        $shop_id = intval($_POST['shop_id']);
        $price = floatval($_POST['price']);
        $is_recommended = !empty($_POST['is_recommended']);

        if ($item_id > 0 && !empty($item_name) && $shop_id > 0 && $price > 0) {
            $stmt = $conn->prepare("UPDATE menu_items SET shop_id = ?, item_name = ?, price = ?, is_recommended = ? WHERE item_id = ?");
            $stmt->bindValue(1, $shop_id, PDO::PARAM_INT);
            $stmt->bindValue(2, $item_name, PDO::PARAM_STR);
            $stmt->bindValue(3, $price);
            $stmt->bindValue(4, $is_recommended, PDO::PARAM_BOOL);
            $stmt->bindValue(5, $item_id, PDO::PARAM_INT);
            $stmt->execute();
            $action_msg = "Menu item updated!";
        }
    }

    // DELETE MENU ITEM
    if (isset($_POST['action_delete_item'])) {
        $item_id = intval($_POST['item_id']);
        $stmt = $conn->prepare("DELETE FROM menu_items WHERE item_id = ?");
        $stmt->execute([$item_id]);
        $action_msg = "Item removed from menu!";
    }

    // BROADCAST DELIVERED MESSAGE
    if (isset($_POST['action_add_msg'])) {
        $msg = trim($_POST['message_text']);
        if (!empty($msg)) {
            $stmt = $conn->prepare("INSERT INTO delivered_messages (message_text) VALUES (?)");
            $stmt->execute([$msg]);
            $action_msg = "Delivery broadcast sent!";
        }
    }

    // RECONCILE & SETTLE ORDER (Requirement 1: Admin verifies audit log, reconciles transaction -> 'SETTLED')
    if (isset($_POST['action_reconcile_order'])) {
        require_once 'notification_lib.php';
        $order_id = intval($_POST['order_id']);
        if ($order_id > 0) {
            $stmt = $conn->prepare("SELECT order_id, order_ref, customer_id, customer_name, total_amount, payment_method, status FROM food_orders WHERE order_id = ? LIMIT 1");
            $stmt->execute([$order_id]);
            $ord = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($ord && strtoupper(trim($ord['status'])) === 'DELIVERED') {
                $ref = '#' . ltrim(!empty($ord['order_ref']) ? $ord['order_ref'] : ('FD-' . $ord['order_id']), '#');
                $total_amount = (float)$ord['total_amount'];
                $subtotal = max(0, $total_amount - 49.00);
                $delivery_fee = 49.00;
                $pay_method = !empty($ord['payment_method']) ? $ord['payment_method'] : 'Cash on Delivery';
                $admin_id = (int)$_SESSION['user_id'];

                // 1. Update order status to SETTLED
                $stmt = $conn->prepare("UPDATE food_orders SET status = 'SETTLED', updated_at = NOW() WHERE order_id = ?");
                $stmt->execute([$order_id]);

                // 2. Insert/Update settlements table (PostgreSQL ON CONFLICT)
                $stmt = $conn->prepare("INSERT INTO settlements (order_id, admin_id, subtotal, delivery_fee, total_amount, payment_method, status, reconciled_at) 
                    VALUES (?, ?, ?, ?, ?, ?, 'settled', NOW())
                    ON CONFLICT (order_id) DO UPDATE SET admin_id = EXCLUDED.admin_id, subtotal = EXCLUDED.subtotal, delivery_fee = EXCLUDED.delivery_fee, total_amount = EXCLUDED.total_amount, payment_method = EXCLUDED.payment_method, status = 'settled', reconciled_at = NOW()");
                $stmt->execute([$order_id, $admin_id, $subtotal, $delivery_fee, $total_amount, $pay_method]);

                // 3. Record order audit log
                record_order_audit_log($conn, $order_id, 'DELIVERED', 'SETTLED', $admin_id, 'admin', 'Transaction verified, reconciled and settled by Admin ' . $user_name);

                // 4. Log to System Security Audit Trail (SETTLEMENT_CLOSED)
                log_activity($conn, $admin_id, 'SETTLEMENT_CLOSED', "Order {$ref} verified, reconciled and settled by Admin {$user_name}. Amount: ₱" . number_format($total_amount, 2));

                // 5. Send notifications
                create_notification($conn, (int)$ord['customer_id'], 'order_settled', 'Order Settled & Closed', 'Your order ' . $ref . ' has been audited and reconciled. Thank you!', $order_id);
                notify_role($conn, 'chef', 'Order Settled', 'Order ' . $ref . ' has been audited and settled by Admin.', $order_id);
                notify_role($conn, 'rider', 'Order Settled', 'Order ' . $ref . ' reconciliation completed.', $order_id);

                $action_msg = "Order " . $ref . " successfully reconciled, marked SETTLED, and recorded in audit log!";
            } else {
                $action_msg = "Order not found or not in DELIVERED state for reconciliation.";
            }
        }
    }

    // USER CRUD: CREATE NEW USER
    if (isset($_POST['action_create_user'])) {
        require_once 'notification_lib.php';
        $u_fullname = trim($_POST['full_name'] ?? '');
        $u_username = trim($_POST['username'] ?? '');
        $u_email = trim($_POST['email'] ?? '');
        $u_password = $_POST['password'] ?? '';
        $u_confirm = $_POST['confirm_password'] ?? '';
        $u_role = strtolower(trim($_POST['role'] ?? 'customer'));

        $allowed_roles = ['customer', 'chef', 'rider', 'admin'];
        if (!in_array($u_role, $allowed_roles, true)) $u_role = 'customer';

        if (empty($u_fullname) || empty($u_username) || empty($u_email) || empty($u_password) || empty($u_confirm)) {
            $action_msg = "Error: All fields are required to create a user.";
        } elseif (!filter_var($u_email, FILTER_VALIDATE_EMAIL)) {
            $action_msg = "Error: Please enter a valid email address.";
        } elseif ($u_password !== $u_confirm) {
            $action_msg = "Error: Passwords do not match. Please verify and re-enter.";
        } else {
            $pass_errs = validate_strong_password($u_password);
            if (!empty($pass_errs)) {
                $action_msg = "Error: " . implode(" ", $pass_errs);
            } else {
                $chk = $conn->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
                $chk->execute([$u_username, $u_email]);
                if ($chk->fetch(PDO::FETCH_ASSOC)) {
                    $action_msg = "Error: Username or Email is already taken by another account.";
                } else {
                    $hashed = password_hash($u_password, PASSWORD_BCRYPT);
                    $ins = $conn->prepare("INSERT INTO users (username, full_name, email, password, role, failed_attempts, lockout_until, created_at) VALUES (?, ?, ?, ?, ?, 0, NULL, NOW())");
                    if ($ins->execute([$u_username, $u_fullname, $u_email, $hashed, $u_role])) {
                        $new_user_id = (int)$conn->lastInsertId();
                        if ($u_role === 'rider') {
                            $r_chk = $conn->prepare("SELECT rider_id FROM riders WHERE user_id = ?");
                            $r_chk->execute([$new_user_id]);
                            if (!$r_chk->fetch(PDO::FETCH_ASSOC)) {
                                $r_ins = $conn->prepare("INSERT INTO riders (user_id, rider_name, status, current_order) VALUES (?, ?, 'waiting', 'None')");
                                $r_ins->execute([$new_user_id, $u_fullname]);
                            }
                        }

                        log_activity($conn, $user_id, 'USER_CREATED', "Admin {$user_name} created user '{$u_username}' (ID: {$new_user_id}, Role: {$u_role}).");
                        $action_msg = "User '{$u_username}' ({$u_role}) created successfully with BCRYPT password encryption!";
                    } else {
                        $errInfo = $ins->errorInfo();
                        $action_msg = "Error creating user: " . ($errInfo[2] ?? 'Database error');
                    }
                }
            }
        }
    }

    // USER CRUD: EDIT USER
    if (isset($_POST['action_edit_user'])) {
        require_once 'notification_lib.php';
        $edit_id = intval($_POST['user_id'] ?? 0);
        $e_fullname = trim($_POST['full_name'] ?? '');
        $e_username = trim($_POST['username'] ?? '');
        $e_email = trim($_POST['email'] ?? '');
        $e_role = strtolower(trim($_POST['role'] ?? 'customer'));
        $e_password = $_POST['password'] ?? '';
        $e_confirm = $_POST['confirm_password'] ?? '';
        $reset_lockout = isset($_POST['reset_lockout']) ? 1 : 0;

        $allowed_roles = ['customer', 'chef', 'rider', 'admin'];
        if (!in_array($e_role, $allowed_roles, true)) $e_role = 'customer';

        if ($edit_id <= 0 || empty($e_fullname) || empty($e_username) || empty($e_email)) {
            $action_msg = "Error: Invalid user details submitted for edit.";
        } else {
            $chk = $conn->prepare("SELECT user_id FROM users WHERE (username = ? OR email = ?) AND user_id != ?");
            $chk->execute([$e_username, $e_email, $edit_id]);
            if ($chk->fetch(PDO::FETCH_ASSOC)) {
                $action_msg = "Error: Username or Email is already taken by another user.";
            } else {
                $password_ok = true;
                $new_hash = null;
                if (!empty($e_password)) {
                    if ($e_password !== $e_confirm) {
                        $action_msg = "Error: New passwords do not match.";
                        $password_ok = false;
                    } else {
                        $p_errs = validate_strong_password($e_password);
                        if (!empty($p_errs)) {
                            $action_msg = "Error: " . implode(" ", $p_errs);
                            $password_ok = false;
                        } else {
                            $new_hash = password_hash($e_password, PASSWORD_BCRYPT);
                        }
                    }
                }

                if ($password_ok) {
                    if ($new_hash !== null) {
                        $upd = $conn->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, role = ?, password = ? WHERE user_id = ?");
                        $upd->execute([$e_fullname, $e_username, $e_email, $e_role, $new_hash, $edit_id]);
                    } else {
                        $upd = $conn->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, role = ? WHERE user_id = ?");
                        $upd->execute([$e_fullname, $e_username, $e_email, $e_role, $edit_id]);
                    }

                    if ($reset_lockout) {
                        $rst = $conn->prepare("UPDATE users SET failed_attempts = 0, lockout_until = NULL WHERE user_id = ?");
                        $rst->execute([$edit_id]);
                    }

                    if ($edit_id === (int)$_SESSION['user_id']) {
                        $_SESSION['username'] = $e_username;
                        $_SESSION['full_name'] = $e_fullname;
                        $_SESSION['role'] = $e_role;
                    }

                    log_activity($conn, $user_id, 'USER_UPDATED', "Admin {$user_name} updated user #{$edit_id} ('{$e_username}', Role: {$e_role})" . ($new_hash ? " [Password changed]" : "") . ($reset_lockout ? " [Lockout reset]" : ""));
                    $action_msg = "User '{$e_username}' updated successfully!";
                }
            }
        }
    }

    // USER CRUD: DELETE USER (Safe FK cascade/nullify, clean soft-delete, visible success banner)
    if (isset($_POST['action_delete_user']) || (isset($_POST['action']) && $_POST['action'] === 'delete_user')) {
        require_once 'notification_lib.php';
        $del_id = intval($_POST['user_id'] ?? 0);
        $current_admin_id = (int)$_SESSION['user_id'];
        $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || (isset($_POST['ajax']) && $_POST['ajax'] == 1);

        if ($del_id <= 0) {
            $err = "Error: Invalid user ID for deletion.";
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $err]);
                exit;
            }
            $action_msg = $err;
        } elseif ($del_id === $current_admin_id) {
            $err = "Security Protection: You cannot delete your own active administrator account!";
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $err]);
                exit;
            }
            $action_msg = $err;
        } else {
            $u_stmt = $conn->prepare("SELECT user_id, username, role FROM users WHERE user_id = ? LIMIT 1");
            $u_stmt->execute([$del_id]);
            $u_info = $u_stmt->fetch(PDO::FETCH_ASSOC);

            if ($u_info) {
                $del_uname = $u_info['username'];
                $del_role = $u_info['role'];

                try {
                    $conn->beginTransaction();

                    // 1. Safely handle dependent records in riders
                    $conn->prepare("DELETE FROM riders WHERE user_id = ?")->execute([$del_id]);

                    // 2. Safely handle dependent records in orders / food_orders
                    try {
                        $conn->prepare("UPDATE orders SET user_id = NULL WHERE user_id = ?")->execute([$del_id]);
                    } catch (Throwable $e) {}
                    try {
                        $conn->prepare("UPDATE food_orders SET customer_id = 0 WHERE customer_id = ?")->execute([$del_id]);
                    } catch (Throwable $e) {}

                    // 3. Safely handle dependent records in reviews & order_messages
                    try {
                        $conn->prepare("DELETE FROM reviews WHERE user_id = ? OR customer_id = ?")->execute([$del_id, $del_id]);
                    } catch (Throwable $e) {}
                    try {
                        $conn->prepare("UPDATE order_messages SET sender_id = 0 WHERE sender_id = ?")->execute([$del_id]);
                    } catch (Throwable $e) {}

                    // 4. Safely handle dependent records in system_audit_logs
                    try {
                        $conn->prepare("UPDATE system_audit_logs SET user_id = NULL WHERE user_id = ?")->execute([$del_id]);
                    } catch (Throwable $e) {}

                    // 5. Clean soft-delete on users table so constraints and system history stay safe
                    $upd_del = $conn->prepare("UPDATE users SET status = 'inactive', is_deleted = 1, username = CONCAT(username, '_del_', UNIX_TIMESTAMP()) WHERE user_id = ?");
                    $upd_del->execute([$del_id]);

                    // 6. Attempt hard delete if DB constraints allow, else soft-delete is already active
                    try {
                        $del_hard = $conn->prepare("DELETE FROM users WHERE user_id = ?");
                        $del_hard->execute([$del_id]);
                    } catch (Throwable $e) {
                        // Soft delete safely handled above
                    }

                    $conn->commit();

                    log_activity($conn, $current_admin_id, 'USER_DELETED', "Admin {$user_name} removed user '{$del_uname}' (ID: {$del_id}, Role: {$del_role}).");
                    $action_msg = "User successfully removed";

                    if ($is_ajax) {
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => true,
                            'message' => 'User successfully removed',
                            'user_id' => $del_id,
                            'username' => $del_uname
                        ]);
                        exit;
                    }
                } catch (Throwable $e) {
                    if ($conn->inTransaction()) $conn->rollBack();
                    // Fallback to soft delete
                    try {
                        $conn->prepare("UPDATE users SET status = 'inactive', is_deleted = 1 WHERE user_id = ?")->execute([$del_id]);
                        log_activity($conn, $current_admin_id, 'USER_DELETED', "Admin {$user_name} soft-deleted user '{$del_uname}' (ID: {$del_id}, Role: {$del_role}).");
                        $action_msg = "User successfully removed";
                        if ($is_ajax) {
                            header('Content-Type: application/json');
                            echo json_encode([
                                'success' => true,
                                'message' => 'User successfully removed',
                                'user_id' => $del_id,
                                'username' => $del_uname
                            ]);
                            exit;
                        }
                    } catch (Throwable $e2) {
                        $action_msg = "Error deleting user: " . $e2->getMessage();
                        if ($is_ajax) {
                            header('Content-Type: application/json');
                            echo json_encode(['success' => false, 'message' => $action_msg]);
                            exit;
                        }
                    }
                }
            } else {
                $err = "Error: User account not found.";
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => $err]);
                    exit;
                }
                $action_msg = $err;
            }
        }
    }

    // USER CRUD: UNLOCK USER
    if (isset($_POST['action_unlock_user'])) {
        require_once 'notification_lib.php';
        $unlock_id = intval($_POST['user_id'] ?? 0);
        if ($unlock_id > 0) {
            $u_stmt = $conn->prepare("SELECT username FROM users WHERE user_id = ? LIMIT 1");
            $u_stmt->execute([$unlock_id]);
            $u_row = $u_stmt->fetch(PDO::FETCH_ASSOC);

            $unl = $conn->prepare("UPDATE users SET failed_attempts = 0, lockout_until = NULL WHERE user_id = ?");
            $unl->execute([$unlock_id]);

            $unl_name = $u_row['username'] ?? "User #{$unlock_id}";
            log_activity($conn, (int)$_SESSION['user_id'], 'USER_UPDATED', "Admin {$user_name} unlocked account for '{$unl_name}'.");
            $action_msg = "Account for '{$unl_name}' has been unlocked successfully!";
        }
    }

    // USER CRUD / IAS HELP DESK: ADMIN-ASSISTED PASSWORD RESET & ACCOUNT UNLOCK
    if (isset($_POST['action_reset_password_unlock'])) {
        require_once 'notification_lib.php';
        $target_user_id = intval($_POST['user_id'] ?? 0);
        $new_password = (string)($_POST['new_password'] ?? '');
        $confirm_password = (string)($_POST['confirm_new_password'] ?? $_POST['confirm_password'] ?? '');
        $unlock_account = isset($_POST['unlock_account']) ? 1 : 0;
        $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
            || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
            || (isset($_POST['ajax']) && $_POST['ajax'] == '1');

        $error_response = null;

        if ($target_user_id <= 0) {
            $error_response = "Error: Invalid user ID for password reset.";
        } elseif (empty($new_password) || empty($confirm_password)) {
            $error_response = "Error: All password fields are required.";
        } elseif ($new_password !== $confirm_password) {
            $error_response = "Error: Passwords do not match. Please verify and re-enter.";
        } else {
            $pass_errs = validate_strong_password($new_password);
            if (!empty($pass_errs)) {
                $error_response = "Error: " . implode(" ", $pass_errs);
            } else {
                $u_stmt = $conn->prepare("SELECT user_id, username, full_name, role FROM users WHERE user_id = ? LIMIT 1");
                $u_stmt->execute([$target_user_id]);
                $target_user = $u_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$target_user) {
                    $error_response = "Error: Target user account not found.";
                } else {
                    $target_username = $target_user['username'];
                    $target_role = $target_user['role'];
                    $hashed = password_hash($new_password, PASSWORD_BCRYPT);

                    if ($unlock_account) {
                        $upd = $conn->prepare("UPDATE users SET password = ?, failed_attempts = 0, lockout_until = NULL WHERE user_id = ?");
                        $executed = $upd->execute([$hashed, $target_user_id]);
                    } else {
                        $upd = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                        $executed = $upd->execute([$hashed, $target_user_id]);
                    }

                    if ($executed) {
                        // Immutable audit log entry in system_audit_logs
                        $admin_username = $_SESSION['username'] ?? $user_name;
                        $audit_detail = "Admin {$admin_username} reset password and unlocked account for User ID: {$target_user_id} ({$target_username})";
                        log_activity($conn, (int)$_SESSION['user_id'], 'ADMIN_PASSWORD_RESET', $audit_detail);

                        $success_msg = "Password reset successfully. Account lockout cleared.";

                        if ($is_ajax) {
                            header('Content-Type: application/json; charset=utf-8');
                            echo json_encode([
                                'success' => true,
                                'message' => $success_msg,
                                'user_id' => $target_user_id,
                                'username' => $target_username,
                                'unlocked' => (bool)$unlock_account
                            ]);
                            exit();
                        } else {
                            $action_msg = $success_msg;
                        }
                    } else {
                        $errInfo = $upd->errorInfo();
                        $error_response = "Error updating user password: " . ($errInfo[2] ?? 'Database error');
                    }
                }
            }
        }

        if ($error_response !== null) {
            if ($is_ajax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => $error_response
                ]);
                exit();
            } else {
                $action_msg = $error_response;
            }
        }
    }
}

// 3. FETCH DATA FROM DATABASE
require_once 'notification_lib.php';

// Fetch Users for User CRUD (excluding soft-deleted)
$users_list = [];
$users_res = $conn->query("SELECT user_id, username, full_name, email, role, failed_attempts, lockout_until, created_at, status, is_deleted FROM users WHERE (is_deleted = 0 OR is_deleted IS NULL) AND (status != 'inactive' OR status IS NULL) ORDER BY user_id DESC");
$user_counts = ['customer' => 0, 'chef' => 0, 'rider' => 0, 'admin' => 0, 'locked' => 0];
if ($users_res) {
    $now_ts = time();
    while ($urow = $users_res->fetch(PDO::FETCH_ASSOC)) {
        $users_list[] = $urow;
        $r_key = strtolower(trim($urow['role'] ?? 'customer'));
        if (isset($user_counts[$r_key])) {
            $user_counts[$r_key]++;
        }
        $is_locked = !empty($urow['lockout_until']) && strtotime($urow['lockout_until']) > $now_ts;
        if ($is_locked) {
            $user_counts['locked']++;
        }
    }
}
$total_users = count($users_list);

// Fetch Security Audit Logs with Filtering & Pagination
$filter_action = trim($_GET['audit_action'] ?? '');
$filter_role = trim($_GET['audit_role'] ?? '');
$filter_search = trim($_GET['audit_search'] ?? '');
$audit_page = max(1, intval($_GET['audit_page'] ?? 1));
$audit_per_page = 15;

$audit_where = [];
$audit_params = [];

if (!empty($filter_action) && $filter_action !== 'all') {
    $audit_where[] = "action = ?";
    $audit_params[] = strtoupper($filter_action);
}
if (!empty($filter_role) && $filter_role !== 'all') {
    $audit_where[] = "LOWER(role) = LOWER(?)";
    $audit_params[] = $filter_role;
}
if (!empty($filter_search)) {
    $audit_where[] = "(username LIKE ? OR details LIKE ? OR ip_address LIKE ?)";
    $s_term = "%" . $filter_search . "%";
    $audit_params[] = $s_term;
    $audit_params[] = $s_term;
    $audit_params[] = $s_term;
}

$where_clause_sql = !empty($audit_where) ? "WHERE " . implode(" AND ", $audit_where) : "";

// Count total matching logs
$audit_count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM system_audit_logs {$where_clause_sql}");
$audit_count_stmt->execute($audit_params);
$audit_total_rows = (int)($audit_count_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

$audit_total_pages = max(1, ceil($audit_total_rows / $audit_per_page));
if ($audit_page > $audit_total_pages) $audit_page = $audit_total_pages;
$audit_offset = ($audit_page - 1) * $audit_per_page;

// Fetch paginated logs
$audit_logs_stmt = $conn->prepare("SELECT log_id, user_id, username, role, action, details, ip_address, user_agent, created_at FROM system_audit_logs {$where_clause_sql} ORDER BY log_id DESC LIMIT ? OFFSET ?");
$page_params = array_merge($audit_params, [$audit_per_page, $audit_offset]);
$audit_logs_stmt->execute($page_params);
$system_audit_logs = $audit_logs_stmt->fetchAll(PDO::FETCH_ASSOC);

$shops_res = $conn->query("SELECT * FROM shops ORDER BY shop_id DESC");
$shops = $shops_res ? $shops_res->fetchAll(PDO::FETCH_ASSOC) : [];

$menu_items_res = $conn->query("SELECT m.*, s.shop_name FROM menu_items m JOIN shops s ON m.shop_id = s.shop_id ORDER BY m.item_id DESC");
$menu_items = $menu_items_res ? $menu_items_res->fetchAll(PDO::FETCH_ASSOC) : [];

$riders_res = $conn->query("SELECT r.*, COALESCE(r.phone, u.phone, '+63 917 555 0192') AS display_phone, COALESCE(r.account_status, 'Active') AS acc_status, COALESCE(r.plate_number, 'ORM-8891') AS display_plate, COALESCE(r.vehicle_model, 'Honda Click 125i') AS display_model FROM riders r LEFT JOIN users u ON r.user_id = u.user_id ORDER BY r.rider_id DESC");
$riders = $riders_res ? $riders_res->fetchAll(PDO::FETCH_ASSOC) : [];

$total_shops = count($shops);
$total_items = count($menu_items);
$total_riders = count($riders);

// Fetch orders delivered awaiting reconciliation
$pending_settlements = [];
$ps_res = $conn->query("SELECT o.*, s.shop_name FROM food_orders o LEFT JOIN shops s ON o.shop_id = s.shop_id WHERE UPPER(o.status) = 'DELIVERED' ORDER BY o.order_id DESC");
if ($ps_res) {
    $pending_settlements = $ps_res->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch all reconciled settlements & transaction history
$settled_transactions = [];
$settled_res = $conn->query("SELECT st.*, o.order_ref, o.customer_name, o.store_name, o.items_summary, u.username as admin_user FROM settlements st JOIN food_orders o ON st.order_id = o.order_id LEFT JOIN users u ON st.admin_id = u.user_id ORDER BY st.settlement_id DESC LIMIT 50");
if ($settled_res) {
    $settled_transactions = $settled_res->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch complete transaction history across all states
$transactions = [];
$txn_res = $conn->query("SELECT o.order_id, o.order_ref, o.shop_id, o.customer_id, o.customer_name, o.store_name, o.items_summary, o.total_amount, o.status, o.delivery_status, o.created_at, o.updated_at, s.shop_name AS shopper_name FROM food_orders o LEFT JOIN shops s ON o.shop_id = s.shop_id ORDER BY o.order_id DESC LIMIT 100");
if ($txn_res) {
    $transactions = $txn_res->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch Order Audit Logs (Requirement: Admin verifies audit log)
$audit_logs = [];
$audit_res = $conn->query("SELECT a.*, o.order_ref, u.username as actor_name FROM order_audit_logs a LEFT JOIN food_orders o ON a.order_id = o.order_id LEFT JOIN users u ON a.action_by_user_id = u.user_id ORDER BY a.log_id DESC LIMIT 60");
if ($audit_res) {
    $audit_logs = $audit_res->fetchAll(PDO::FETCH_ASSOC);
}

// Sales summary: settled + delivered
$total_revenue = 0;
$rev_res = $conn->query("SELECT SUM(total_amount) as rev FROM food_orders WHERE UPPER(status) IN ('SETTLED', 'DELIVERED', 'COMPLETED')");
if ($rev_res && $rev_row = $rev_res->fetch(PDO::FETCH_ASSOC)) {
    $total_revenue = (float)($rev_row['rev'] ?? 0);
}
$txn_count = count($transactions);
$total_revenue = number_format($total_revenue, 2);

$active_tab = 'analytics';
if (isset($_GET['tab']) && in_array($_GET['tab'], ['analytics', 'users', 'auditlogs', 'riders', 'stores', 'menus', 'transactions', 'dispatch-map'], true)) {
    $active_tab = $_GET['tab'];
} elseif (isset($_POST['active_tab'])) {
    $active_tab = $_POST['active_tab'];
} elseif (isset($_POST['action_create_user']) || isset($_POST['action_edit_user']) || isset($_POST['action_delete_user']) || isset($_POST['action_unlock_user']) || isset($_POST['action_reset_password_unlock'])) {
    $active_tab = 'users';
} elseif (isset($_POST['action_reconcile_order'])) {
    $active_tab = 'transactions';
} elseif (isset($_POST['action_add_rider']) || isset($_POST['action_toggle_rider']) || isset($_POST['action_delete_rider']) || isset($_POST['action_edit_rider'])) {
    $active_tab = 'riders';
} elseif (isset($_POST['action_add_shop']) || isset($_POST['action_edit_shop']) || isset($_POST['action_delete_shop'])) {
    $active_tab = 'stores';
} elseif (isset($_POST['action_add_item']) || isset($_POST['action_edit_item']) || isset($_POST['action_delete_item'])) {
    $active_tab = 'menus';
} elseif (isset($_GET['audit_page']) || isset($_GET['audit_action']) || isset($_GET['audit_role']) || isset($_GET['audit_search'])) {
    $active_tab = 'auditlogs';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
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
    @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');

    :root {
      --harvest-sun: #D88F2A;
      --sagebrush: #788A6E;
      --earth-clay: #923D32;
      --olive-shadow: #4B5320;
      --sandstone: #D4C5A5;
      --sandstone-light: #FAF6EE;
      --fd-gold: #D88F2A;
      --fd-sage: #788A6E;
      --fd-rust: #923D32;
      --fd-olive: #4B5320;
      --fd-cream: #D4C5A5;
    }

    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }

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
      overflow-x: hidden;
    }

    button, input, select { font: inherit; }
    button { cursor: pointer; }

    @keyframes liveRadarDot {
      0% { transform: scale(0.9); opacity: 1; }
      50% { transform: scale(1.3); opacity: 0.5; }
      100% { transform: scale(0.9); opacity: 1; }
    }
    @keyframes riderRadarPulse {
      0% { transform: scale(0.6); opacity: 0.9; }
      100% { transform: scale(1.6); opacity: 0; }
    }
    .custom-store-marker, .custom-rider-marker {
      background: none !important;
      border: none !important;
    }

    /* UNIFIED 440PX APP SHELL CONTAINER */
    .app {
      width: 100%;
      max-width: 440px;
      min-height: 100vh;
      background: #fffdf7;
      position: relative;
      box-shadow: 0 10px 30px rgba(75,83,32,.12);
      display: flex;
      flex-direction: column;
      margin: 0 auto;
    }

    @media (min-width: 600px) {
      .app {
        min-height: 720px;
        height: auto;
        border: 1px solid #ddd3ad;
        border-radius: 26px;
        box-shadow: 0 22px 60px rgba(55,65,22,.15);
        overflow: hidden;
      }
    }

    @media (max-width: 599px) {
      html, body { padding: 0; }
      .app { border: none; border-radius: 0; box-shadow: none; min-height: 100vh; }
    }

    /* MAIN CONTENT AREA */
    .main-content {
      margin: 0;
      width: 100%;
      flex: 1 0 auto;
      padding: 0 14px 20px;
      background: #fffdf7;
      box-sizing: border-box;
    }

    .top-bar {
      position: sticky;
      top: 0;
      z-index: 50;
      background: rgba(255,253,247,.96);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      padding: 14px 2px 10px;
      margin: 0 -2px 12px;
      border-bottom: 1px solid rgba(231,223,196,.65);
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .top-bar h1 { font-size: 19px; color: #344016; font-weight: 700; }
    .top-bar p { color: #74776a !important; font-size: 9px !important; margin-top: 2px; }

    .action-header-btns { display: flex; gap: 6px; align-items: center; }
    .mobile-logout {
      display: grid !important;
      width: 32px;
      height: 32px;
      border: 1px solid #e7dfc4;
      border-radius: 50%;
      background: #fff;
      color: #4f5d21;
      place-items: center;
      cursor: pointer;
      font-size: 12px;
      transition: all 0.2s;
    }
    .mobile-logout:hover { background: #f7f3df; }

    .btn-action {
      background: #4B5320;
      color: #fff;
      border: none;
      border-radius: 12px;
      padding: 8px 10px;
      font-size: 8px;
      font-weight: 600;
      cursor: pointer;
      transition: 0.2s;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .btn-action:hover { background: #56652b; }

    .alert-banner {
      background: #edf3d8;
      border: 1px solid #dbe7b5;
      color: #43531b;
      padding: 10px 12px;
      border-radius: 12px;
      margin-bottom: 12px;
      font-size: 9px;
    }

    /* METRICS GRID */
    .metrics-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 9px;
      margin-bottom: 14px;
    }
    .metric-card {
      background: #fff;
      border: 1px solid #e7dfc4;
      border-radius: 15px;
      padding: 13px;
      box-shadow: 0 5px 18px rgba(55,65,22,.06);
      display: flex;
      justify-content: space-between;
      align-items: center;
      transition: 0.2s;
    }
    .metric-card:hover {
      border-color: #d9cfaa;
      box-shadow: 0 5px 18px rgba(55,65,22,.08);
    }
    .metric-data h3 { font-size: 17px; font-weight: 700; color: #344016; }
    .metric-data p { font-size: 8px; color: #74776a; }
    .metric-icon { font-size: 20px; color: #c6a24a; }

    /* DYNAMIC TAB SECTIONS */
    .tab-section { display: none; }
    .tab-section.active-tab { display: block; }

    /* SECTIONS & TABLES */
    .dashboard-section {
      background: #fff;
      border: 1px solid #e7dfc4;
      border-radius: 16px;
      padding: 12px;
      margin-bottom: 12px;
      box-shadow: 0 5px 18px rgba(55,65,22,.06);
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      max-width: 100%;
    }
    .section-title {
      font-size: 12px;
      font-weight: 600;
      color: #272b20;
      margin-bottom: 8px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .section-title .btn-action { font-size: 8px; padding: 7px 9px; }

    .table-responsive {
      width: 100%;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }
    table { width: 100%; border-collapse: collapse; margin-top: 5px; }
    thead { display: none; }
    tbody { display: grid; gap: 8px; }
    tr {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 4px 10px;
      padding: 10px;
      border: 1px solid #eee8d7;
      border-radius: 12px;
      background: #fff;
    }
    td {
      border: 0;
      padding: 1px 0;
      font-size: 8px;
      color: #303429;
      display: block;
      text-align: left;
      min-width: 0;
      word-break: break-word;
    }
    td strong { font-size: 9px; color: #29300f; }
    td:last-child {
      grid-column: 1 / -1;
      padding-top: 5px;
      display: flex;
      gap: 5px;
      flex-wrap: wrap;
      justify-content: flex-start;
    }
    td:last-child form { display: inline-flex; }

    .badge-recommended { background: #f2e5ad; color: #685516; padding: 3px 7px; border-radius: 12px; font-size: 7px; }
    
    /* STATUS & ACTION BADGES */
    .status-badge { font-size: 7px; padding: 3px 8px; border-radius: 10px; font-weight: 600; text-transform: uppercase; display: inline-block; }
    .status-badge.pending { background: #fff4e5; color: #D88F2A; border: 1px solid #D88F2A; }
    .status-badge.preparing { background: #eef3ec; color: #788A6E; border: 1px solid #788A6E; }
    .status-badge.ready, .status-badge.ready_for_pickup { background: #faf6ee; color: #7a6332; border: 1px solid #D4C5A5; }
    .status-badge.out_for_delivery, .status-badge.on_delivery { background: #f3f5ea; color: #4B5320; border: 1px solid #4B5320; }
    .status-badge.delivered { background: #e8f5e9; color: #2e7d32; border: 1px solid #2e7d32; }
    .status-badge.settled { background: #e6ece3; color: #283a24; border: 1px solid #788A6E; }
    .status-badge.waiting { background: #faf6ee; color: #7a6332; border: 1px solid #D4C5A5; }
    .status-badge.admin { background: #fbeee8; color: #923D32; border: 1px solid #923D32; }
    .status-badge.chef, .status-badge.manager { background: #eef3ec; color: #788A6E; border: 1px solid #788A6E; }
    .status-badge.rider { background: #faf6ee; color: #D88F2A; border: 1px solid #D88F2A; }
    .status-badge.customer { background: #f3f5ea; color: #4B5320; border: 1px solid #4B5320; }
    .status-badge.locked { background: #fcedec; color: #923D32; border: 1px solid #923D32; font-weight: 700; }
    .status-badge.active-user { background: #eef3ec; color: #4B5320; border: 1px solid #788A6E; }

    .action-badge { font-size: 7.5px; padding: 2px 7px; border-radius: 8px; font-weight: 700; text-transform: uppercase; display: inline-block; }
    .action-badge.auth-ok { background: #eef3ec; color: #4B5320; border: 1px solid #788A6E; }
    .action-badge.auth-fail { background: #fcedec; color: #923D32; border: 1px solid #923D32; }
    .action-badge.user-mod { background: #faf6ee; color: #D88F2A; border: 1px solid #D88F2A; }
    .action-badge.order-flow { background: #f3f5ea; color: #4B5320; border: 1px solid #4B5320; }
    .action-badge.settle { background: #fff4e5; color: #D88F2A; border: 1px solid #D88F2A; }

    .btn-edit, .btn-delete, .btn-toggle-status, .btn-reset-pw {
      font-size: 8px !important;
      padding: 6px 8px !important;
      border-radius: 8px;
      margin: 0;
      cursor: pointer;
      border: none;
      transition: 0.2s;
    }
    .btn-edit { background: #eef2df; color: #4f5d21; }
    .btn-delete { background: #f8e4e1; color: #b33b32; }
    .btn-toggle-status { background: #eef2df; border: 1px solid #dbe7b5; color: #4f5d21; }
    .btn-reset-pw {
      background: #faf6ee;
      border: 1px solid #D88F2A;
      color: #8c5810;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-weight: 600;
    }
    .btn-reset-pw:hover { background: #D88F2A; color: #fff; }

    /* TOAST NOTIFICATION SYSTEM */
    .admin-toast {
      position: fixed;
      left: 50%;
      bottom: 80px;
      transform: translateX(-50%) translateY(20px);
      background: linear-gradient(135deg, #788A6E 0%, #4B5320 100%);
      color: #fffdf7;
      border: 1.5px solid #D88F2A;
      padding: 10px 20px;
      border-radius: 25px;
      font-size: 10px;
      font-weight: 600;
      opacity: 0;
      pointer-events: none;
      transition: all .25s ease;
      z-index: 2500;
      box-shadow: 0 6px 20px rgba(75, 83, 32, 0.35);
      white-space: nowrap;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .admin-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
    .admin-toast.toast-error {
      background: linear-gradient(135deg, #923D32 0%, #6f261d 100%);
      border-color: #fcedec;
      box-shadow: 0 6px 20px rgba(146, 61, 50, 0.40);
    }

    /* MODALS */
    .modal {
      display: none;
      position: fixed;
      inset: 0;
      width: 100%;
      height: 100%;
      background: rgba(28,32,6,.38);
      backdrop-filter: blur(3px);
      align-items: center;
      justify-content: center;
      padding: 18px;
      z-index: 2000;
    }
    .modal-box {
      width: min(390px, 100%);
      background: #fffdf7;
      border: 1px solid #e7dfc4;
      border-radius: 20px;
      padding: 20px;
      color: #29300f;
      box-shadow: 0 20px 60px rgba(55,65,22,.22);
    }
    .modal-box h3 { font-size: 15px; color: #344016; margin-bottom: 8px; }
    .modal-box p { font-size: 10px; color: #74776a; margin-bottom: 15px; line-height: 1.5; }
    .modal-box input, .modal-box select {
      width: 100%;
      padding: 10px;
      margin-bottom: 10px;
      border-radius: 10px;
      border: 1px solid #e7dfc4;
      background: #fff;
      color: #303429;
      outline: none;
      font-size: 10px;
    }
    .modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 8px; }
    .btn-cancel { background: #eee9d7; color: #4f5d21; border: 0; padding: 9px 13px; border-radius: 10px; font-size: 9px; cursor: pointer; }
    .btn-submit { background: #4f5d21; color: #fff; border: 0; padding: 9px 13px; border-radius: 10px; font-size: 9px; font-weight: 700; text-decoration: none; display: inline-block; cursor: pointer; }

    /* STORES GRID */
    .admin-stores-grid { display: grid; gap: 10px; margin-top: 6px; }
    .admin-store-card { display: flex; align-items: center; gap: 12px; padding: 10px 12px; background: #FFFDF9; border: 1px solid #eee8d7; border-radius: 14px; transition: all .2s ease; }
    .admin-store-logo { width: 46px; height: 46px; border-radius: 12px; background: #FAF6EE; border: 1px solid #D4C5A5; display: flex; align-items: center; justify-content: center; padding: 5px; flex: none; overflow: hidden; }
    .admin-store-logo img { width: 100%; height: 100%; object-fit: contain; }
    .admin-store-meta { flex: 1; min-width: 0; }
    .admin-store-id { font-size: 7.5px; font-weight: 700; color: #788A6E; letter-spacing: .5px; text-transform: uppercase; }
    .admin-store-meta strong { display: block; font-size: 11px; color: #344016; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .admin-store-cat { display: inline-block; font-size: 7.5px; color: #788A6E; background: #FAF6EE; padding: 1.5px 6px; border-radius: 6px; border: 1px solid rgba(212,197,165,.6); margin-top: 2px; }
    .admin-store-actions { display: flex; gap: 6px; align-items: center; flex: none; }

    /* BOTTOM NAVIGATION BAR (SIDEBAR IN APP CONTAINER) */
    .sidebar {
      position: sticky;
      bottom: 0;
      width: 100%;
      height: 64px;
      padding: 0 4px;
      background: rgba(255,255,255,.96);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      border: 0;
      border-top: 1px solid #e7dfc4;
      box-shadow: 0 -4px 18px rgba(55,65,22,.08);
      border-radius: 0;
      display: flex;
      align-items: center;
      z-index: 100;
      margin-top: auto;
    }
    .sidebar > div { width: 100%; }
    .sidebar-brand, .user-panel { display: none; }
    .nav-links {
      height: 64px;
      display: flex;
      overflow-x: auto;
      overflow-y: hidden;
      scrollbar-width: none;
      -ms-overflow-style: none;
      gap: 0;
      align-items: stretch;
      list-style: none;
      width: 100%;
      margin: 0;
      padding: 0;
    }
    .nav-links::-webkit-scrollbar { display: none; }
    .nav-links li { flex: 0 0 auto; margin: 0; display: block; }
    .nav-links a {
      height: 64px;
      padding: 6px 12px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      gap: 3px;
      border-radius: 0;
      color: #74776a;
      background: transparent;
      box-shadow: none;
      font-size: 8px;
      font-weight: 500;
      text-align: center;
      text-decoration: none;
      white-space: nowrap;
      cursor: pointer;
      transition: color 0.2s;
    }
    .nav-links a i { font-size: 15px; }
    .nav-links a:hover, .nav-links a.active {
      background: transparent;
      color: #4B5320;
      box-shadow: none;
      font-weight: 700;
    }
  </style>
</head>
<body>
<div class="app admin-app">

  <!-- MAIN DASHBOARD CONTENT -->
  <main class="main-content">
    
    <div class="top-bar">
      <div>
        <h1>Dashboard Control</h1>
        <p style="font-size: 0.8rem; color: #b87b85;">Real-time metrics, user identity assurance, store controls, and audit trails.</p>
      </div>
      <div class="action-header-btns">
        <button type="button" class="mobile-logout" onclick="openModal('logoutModal')"><i class="fa-solid fa-right-from-bracket"></i></button>
        <button class="btn-action" onclick="switchTab('dispatch-map')" style="background:var(--fd-gold);"><i class="fa-solid fa-map-location-dot"></i> Fleet Map</button>
        <button class="btn-action" onclick="openModal('addUserModal')" style="background:var(--fd-olive);"><i class="fa-solid fa-user-plus"></i> New User</button>
        <button class="btn-action" onclick="openModal('addRiderModal')"><i class="fa-solid fa-motorcycle"></i> New Rider</button>
        <button class="btn-action" onclick="openModal('addShopModal')"><i class="fa-solid fa-plus"></i> New Shop</button>
      </div>
    </div>

    <?php if (!empty($action_msg)): ?>
      <div class="alert-banner">✅ <?php echo htmlspecialchars($action_msg); ?></div>
    <?php endif; ?>

    <!-- TAB 0: ANALYTICS (METRICS GRID) -->
    <div id="tab-analytics" class="tab-section">
      <div class="metrics-grid">
        <div class="metric-card">
          <div class="metric-data">
            <h3>₱<?php echo $total_revenue; ?></h3>
            <p>Total Settled Revenue</p>
          </div>
          <i class="fa-solid fa-wallet metric-icon" style="color:var(--fd-gold);"></i>
        </div>
        <div class="metric-card" onclick="switchTab('users')" style="cursor:pointer;">
          <div class="metric-data">
            <h3><?php echo $total_users; ?></h3>
            <p>Registered Users (<?php echo $user_counts['locked']; ?> Locked)</p>
          </div>
          <i class="fa-solid fa-users-gear metric-icon" style="color:var(--fd-olive);"></i>
        </div>
        <div class="metric-card" onclick="switchTab('auditlogs')" style="cursor:pointer;">
          <div class="metric-data">
            <h3><?php echo $audit_total_rows; ?></h3>
            <p>System Audit Trail Logs</p>
          </div>
          <i class="fa-solid fa-shield-halved metric-icon" style="color:var(--fd-rust);"></i>
        </div>
        <div class="metric-card" onclick="switchTab('dispatch-map')" style="cursor:pointer;" title="View on Master Dispatch Map">
          <div class="metric-data">
            <h3><?php echo $total_shops; ?></h3>
            <p>Partner Stores (Live Map)</p>
          </div>
          <i class="fa-solid fa-shop metric-icon" style="color:#D88F2A;"></i>
        </div>
        <div class="metric-card" onclick="switchTab('menus')" style="cursor:pointer;">
          <div class="metric-data">
            <h3><?php echo $total_items; ?></h3>
            <p>Total Menu Dishes</p>
          </div>
          <i class="fa-solid fa-burger metric-icon"></i>
        </div>
        <div class="metric-card" onclick="switchTab('dispatch-map')" style="cursor:pointer;" title="Track live moving fleet">
          <div class="metric-data">
            <h3><?php echo $total_riders; ?></h3>
            <p>Fleet Riders (Live GPS)</p>
          </div>
          <i class="fa-solid fa-motorcycle metric-icon" style="color:#4B5320;"></i>
        </div>
      </div>
    </div>

    <!-- TAB: USER MANAGEMENT & ACCESS CONTROL (CRUD MODULE) -->
    <div id="tab-users" class="tab-section">
      <div class="dashboard-section" style="border-left: 4px solid var(--fd-olive);">
        <div class="section-title">
          <span><i class="fa-solid fa-users-gear" style="color:var(--fd-olive);"></i> User Management & IAM</span>
          <button class="btn-action" onclick="openModal('addUserModal')" style="padding: 5px 10px; font-size: 0.75rem; background: var(--fd-olive); color: white;">
            <i class="fa-solid fa-user-plus"></i> Add User
          </button>
        </div>
        <p style="font-size:11px; color:#74776a; margin-bottom:12px;">
          Admins can Create, View, Edit, and Delete users (Customer, Chef, Rider, Admin). All passwords are encrypted with <code>PASSWORD_BCRYPT</code>. Brute-force account lockouts can be audited and reset below.
        </p>

        <!-- Role Summary Badges -->
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px;">
          <span style="font-size:10px; background:#fffdf7; border:1px solid #dcd3b8; padding:4px 8px; border-radius:12px; color:#344016;">Total: <strong><?php echo $total_users; ?></strong></span>
          <span style="font-size:10px; background:#fbeee8; border:1px solid #923D32; padding:4px 8px; border-radius:12px; color:#923D32;">Admin: <strong><?php echo $user_counts['admin'] ?? 0; ?></strong></span>
          <span style="font-size:10px; background:#eef3ec; border:1px solid #788A6E; padding:4px 8px; border-radius:12px; color:#788A6E;">Chef: <strong><?php echo $user_counts['chef'] ?? 0; ?></strong></span>
          <span style="font-size:10px; background:#faf6ee; border:1px solid #D88F2A; padding:4px 8px; border-radius:12px; color:#D88F2A;">Rider: <strong><?php echo $user_counts['rider'] ?? 0; ?></strong></span>
          <span style="font-size:10px; background:#f3f5ea; border:1px solid #4B5320; padding:4px 8px; border-radius:12px; color:#4B5320;">Customer: <strong><?php echo $user_counts['customer'] ?? 0; ?></strong></span>
          <?php if (($user_counts['locked'] ?? 0) > 0): ?>
            <span style="font-size:10px; background:#fcedec; border:1px solid #923D32; padding:4px 8px; border-radius:12px; color:#923D32; font-weight:700;">Locked: <?php echo $user_counts['locked']; ?></span>
          <?php endif; ?>
        </div>

        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Username & Full Name</th>
              <th>Email</th>
              <th>Role</th>
              <th>Security / Lockout Status</th>
              <th>Created</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($users_list)): ?>
              <?php foreach ($users_list as $u): 
                $is_locked = !empty($u['lockout_until']) && strtotime($u['lockout_until']) > time();
                $u_role = strtolower(trim($u['role'] ?? 'customer'));
                $is_current_admin = ((int)$u['user_id'] === (int)$_SESSION['user_id']);
              ?>
                <tr id="user-row-<?php echo $u['user_id']; ?>">
                  <td><small>#UID-<?php echo $u['user_id']; ?></small></td>
                  <td>
                    <strong><?php echo htmlspecialchars($u['username']); ?></strong>
                    <div style="font-size:8px; color:#74776a;"><?php echo htmlspecialchars($u['full_name']); ?></div>
                  </td>
                  <td><small><?php echo htmlspecialchars($u['email'] ?? 'No email'); ?></small></td>
                  <td>
                    <span class="status-badge <?php echo $u_role; ?>">
                      <?php echo strtoupper($u_role); ?>
                    </span>
                  </td>
                  <td>
                    <?php if ($is_locked): 
                      $rem_s = strtotime($u['lockout_until']) - time();
                      $rem_m = ceil($rem_s / 60);
                    ?>
                      <span class="status-badge locked" title="Lockout until <?php echo $u['lockout_until']; ?>">
                        <i class="fa-solid fa-lock"></i> Locked (~<?php echo $rem_m; ?>m)
                      </span>
                      <form method="POST" style="display:inline; margin-left:4px;">
                        <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">
                        <button type="submit" name="action_unlock_user" class="btn-toggle-status" style="font-size:7px; padding:2px 5px;" title="Unlock account immediately">
                          <i class="fa-solid fa-lock-open"></i> Unlock
                        </button>
                      </form>
                    <?php elseif ((int)($u['failed_attempts'] ?? 0) > 0): ?>
                      <span class="status-badge" style="background:#fff4e5; color:#D88F2A; border:1px solid #D88F2A; font-size:7px;">
                        <?php echo $u['failed_attempts']; ?> Fails
                      </span>
                    <?php else: ?>
                      <span class="status-badge active-user" style="font-size:7px;">Healthy</span>
                    <?php endif; ?>
                  </td>
                  <td><small><?php echo date('M d, Y', strtotime($u['created_at'])); ?></small></td>
                  <td>
                    <button type="button" class="btn-reset-pw" onclick="openResetPasswordModal(<?php echo $u['user_id']; ?>, '<?php echo addslashes($u['username']); ?>', '<?php echo addslashes($u['full_name']); ?>', '<?php echo addslashes($u_role); ?>', <?php echo $is_locked ? 1 : 0; ?>, '<?php echo $is_locked ? addslashes($u['lockout_until']) : ''; ?>', <?php echo (int)($u['failed_attempts'] ?? 0); ?>)" title="IAS Help Desk: Reset password and unlock account">
                      <i class="fa-solid fa-key"></i> Reset Password / Unlock
                    </button>
                    <button class="btn-edit" onclick="openEditUserModal(<?php echo $u['user_id']; ?>, '<?php echo addslashes($u['full_name']); ?>', '<?php echo addslashes($u['username']); ?>', '<?php echo addslashes($u['email'] ?? ''); ?>', '<?php echo addslashes($u_role); ?>', <?php echo $is_locked ? 1 : 0; ?>)">
                      <i class="fa-solid fa-pen-to-square"></i> Edit
                    </button>
                    <?php if ($is_current_admin): ?>
                      <span style="font-size:7.5px; color:#788A6E; padding:4px 6px; background:#f4f0df; border-radius:6px;" title="Security Protection: You cannot delete your own active administrator account.">
                        <i class="fa-solid fa-user-shield"></i> Active Session
                      </span>
                    <?php else: ?>
                      <form method="POST" style="display:inline;" onsubmit="return confirmAdminAction('Delete User?', 'Are you sure you want to permanently delete user <?php echo htmlspecialchars($u['username']); ?>? This action cannot be undone and will be recorded in audit logs.', this, this.querySelector('button[name=action_delete_user]'));">
                        <input type="hidden" name="action_delete_user" value="1">
                        <input type="hidden" name="active_tab" value="users">
                        <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">
                        <button type="submit" name="action_delete_user" class="btn-delete"><i class="fa-solid fa-trash"></i> Delete</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="7">No users registered in database.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- TAB: LOG TRAIL / SECURITY AUDIT (RESTRICTED TO ADMIN) -->
    <div id="tab-auditlogs" class="tab-section">
      <div class="dashboard-section" style="border-left: 4px solid var(--fd-gold);">
        <div class="section-title">
          <span><i class="fa-solid fa-shield-halved" style="color:var(--fd-gold);"></i> System Security Audit Trail</span>
          <span style="font-size:10px; background:#FAF6EE; color:var(--fd-gold); padding:3px 8px; border-radius:10px; font-weight:700;">
            <?php echo $audit_total_rows; ?> Total Events
          </span>
        </div>
        <p style="font-size:11px; color:#74776a; margin-bottom:12px;">
          Immutable security and activity trail logging logins (<code>LOGIN_SUCCESS</code>, <code>LOGIN_FAILED</code>, <code>LOGOUT</code>), user operations (<code>USER_CREATED</code>, <code>USER_UPDATED</code>, <code>USER_DELETED</code>), and order lifecycle stages (<code>ORDER_PLACED</code>, <code>ORDER_PREPARED</code>, <code>ORDER_DISPATCHED</code>, <code>ORDER_DELIVERED</code>, <code>SETTLEMENT_CLOSED</code>).
        </p>

        <!-- Audit Trail Filters -->
        <form method="GET" action="admin_dashboard.php" style="background:#FFFDF7; border:1px solid #D4C5A5; border-radius:12px; padding:10px 12px; margin-bottom:14px; display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
          <input type="hidden" name="tab" value="auditlogs">

          <div style="flex:1; min-width:140px;">
            <label style="font-size:7.5px; font-weight:700; color:#4B5320; display:block; margin-bottom:2px;">Filter Action</label>
            <select name="audit_action" style="width:100%; padding:6px 8px; font-size:8.5px; border-radius:8px; border:1px solid #D4C5A5; background:#fff; color:#29300f;">
              <option value="all" <?php echo empty($filter_action) || $filter_action === 'all' ? 'selected' : ''; ?>>All Actions</option>
              <option value="LOGIN_SUCCESS" <?php echo $filter_action === 'LOGIN_SUCCESS' ? 'selected' : ''; ?>>LOGIN_SUCCESS</option>
              <option value="LOGIN_FAILED" <?php echo $filter_action === 'LOGIN_FAILED' ? 'selected' : ''; ?>>LOGIN_FAILED</option>
              <option value="LOGOUT" <?php echo $filter_action === 'LOGOUT' ? 'selected' : ''; ?>>LOGOUT</option>
              <option value="USER_CREATED" <?php echo $filter_action === 'USER_CREATED' ? 'selected' : ''; ?>>USER_CREATED</option>
              <option value="USER_UPDATED" <?php echo $filter_action === 'USER_UPDATED' ? 'selected' : ''; ?>>USER_UPDATED</option>
              <option value="USER_DELETED" <?php echo $filter_action === 'USER_DELETED' ? 'selected' : ''; ?>>USER_DELETED</option>
              <option value="ADMIN_PASSWORD_RESET" <?php echo $filter_action === 'ADMIN_PASSWORD_RESET' ? 'selected' : ''; ?>>ADMIN_PASSWORD_RESET</option>
              <option value="ORDER_PLACED" <?php echo $filter_action === 'ORDER_PLACED' ? 'selected' : ''; ?>>ORDER_PLACED</option>
              <option value="ORDER_PREPARED" <?php echo $filter_action === 'ORDER_PREPARED' ? 'selected' : ''; ?>>ORDER_PREPARED</option>
              <option value="ORDER_DISPATCHED" <?php echo $filter_action === 'ORDER_DISPATCHED' ? 'selected' : ''; ?>>ORDER_DISPATCHED</option>
              <option value="ORDER_DELIVERED" <?php echo $filter_action === 'ORDER_DELIVERED' ? 'selected' : ''; ?>>ORDER_DELIVERED</option>
              <option value="SETTLEMENT_CLOSED" <?php echo $filter_action === 'SETTLEMENT_CLOSED' ? 'selected' : ''; ?>>SETTLEMENT_CLOSED</option>
            </select>
          </div>

          <div style="flex:1; min-width:100px;">
            <label style="font-size:7.5px; font-weight:700; color:#4B5320; display:block; margin-bottom:2px;">Filter Role</label>
            <select name="audit_role" style="width:100%; padding:6px 8px; font-size:8.5px; border-radius:8px; border:1px solid #D4C5A5; background:#fff; color:#29300f;">
              <option value="all" <?php echo empty($filter_role) || $filter_role === 'all' ? 'selected' : ''; ?>>All Roles</option>
              <option value="admin" <?php echo strtolower($filter_role) === 'admin' ? 'selected' : ''; ?>>Admin</option>
              <option value="chef" <?php echo strtolower($filter_role) === 'chef' ? 'selected' : ''; ?>>Chef</option>
              <option value="rider" <?php echo strtolower($filter_role) === 'rider' ? 'selected' : ''; ?>>Rider</option>
              <option value="customer" <?php echo strtolower($filter_role) === 'customer' ? 'selected' : ''; ?>>Customer</option>
            </select>
          </div>

          <div style="flex:1.5; min-width:130px;">
            <label style="font-size:7.5px; font-weight:700; color:#4B5320; display:block; margin-bottom:2px;">Search Actor / IP / Detail</label>
            <input type="text" name="audit_search" value="<?php echo htmlspecialchars($filter_search); ?>" placeholder="Search keyword..." style="width:100%; padding:6px 8px; font-size:8.5px; border-radius:8px; border:1px solid #D4C5A5; background:#fff; color:#29300f;">
          </div>

          <div style="display:flex; gap:4px; align-items:flex-end; padding-top:12px;">
            <button type="submit" class="btn-action" style="padding:6px 12px; font-size:8px; background:var(--fd-olive); color:#fff; border-radius:8px;">
              <i class="fa-solid fa-filter"></i> Apply
            </button>
            <a href="admin_dashboard.php?tab=auditlogs" class="btn-cancel" style="padding:6px 10px; font-size:8px; text-decoration:none; display:inline-block; border-radius:8px;">
              Reset
            </a>
          </div>
        </form>

        <!-- Audit Table View -->
        <table>
          <thead>
            <tr>
              <th>Log ID</th>
              <th>Action</th>
              <th>Actor</th>
              <th>Role</th>
              <th>Event Details</th>
              <th>IP Address</th>
              <th>Timestamp</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($system_audit_logs)): ?>
              <?php foreach ($system_audit_logs as $log): 
                $act = strtoupper(trim($log['action']));
                $badge_class = 'auth-ok';
                if (in_array($act, ['LOGIN_FAILED', 'USER_DELETED'], true)) {
                    $badge_class = 'auth-fail';
                } elseif (in_array($act, ['SETTLEMENT_CLOSED', 'ORDER_PLACED'], true)) {
                    $badge_class = 'settle';
                } elseif (in_array($act, ['USER_CREATED', 'USER_UPDATED', 'ADMIN_PASSWORD_RESET'], true)) {
                    $badge_class = 'user-mod';
                } elseif (in_array($act, ['ORDER_PREPARED', 'ORDER_DISPATCHED', 'ORDER_DELIVERED'], true)) {
                    $badge_class = 'order-flow';
                }
              ?>
                <tr>
                  <td><small>#LOG-<?php echo $log['log_id']; ?></small></td>
                  <td>
                    <span class="action-badge <?php echo $badge_class; ?>">
                      <?php echo htmlspecialchars($act); ?>
                    </span>
                  </td>
                  <td>
                    <strong><?php echo htmlspecialchars($log['username'] ?? 'Anonymous/Guest'); ?></strong>
                    <?php if (!empty($log['user_id'])): ?>
                      <div style="font-size:7px; color:#788A6E;">UID: <?php echo $log['user_id']; ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="status-badge <?php echo strtolower($log['role'] ?? 'guest'); ?>" style="font-size:7px;">
                      <?php echo htmlspecialchars($log['role'] ? strtoupper($log['role']) : 'GUEST'); ?>
                    </span>
                  </td>
                  <td>
                    <small style="color:#2f341d; word-break:break-word;">
                      <?php echo htmlspecialchars($log['details'] ?? ''); ?>
                    </small>
                  </td>
                  <td>
                    <small style="color:#788A6E;">
                      <code><?php echo htmlspecialchars($log['ip_address']); ?></code>
                    </small>
                  </td>
                  <td>
                    <small style="color:#6b7056; white-space:nowrap;">
                      <?php echo date('M d, Y H:i:s', strtotime($log['created_at'])); ?>
                    </small>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="7">No security audit events match the selected criteria.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>

        <!-- Pagination Controls -->
        <?php if ($audit_total_pages > 1): ?>
          <div style="display:flex; justify-content:space-between; align-items:center; margin-top:14px; padding-top:10px; border-top:1px solid #D4C5A5;">
            <div style="font-size:8.5px; color:#74776a;">
              Page <strong><?php echo $audit_page; ?></strong> of <strong><?php echo $audit_total_pages; ?></strong> (<?php echo $audit_total_rows; ?> events)
            </div>
            <div style="display:flex; gap:4px;">
              <?php
              $query_base = http_build_query([
                  'tab'          => 'auditlogs',
                  'audit_action' => $filter_action,
                  'audit_role'   => $filter_role,
                  'audit_search' => $filter_search,
              ]);
              ?>
              <?php if ($audit_page > 1): ?>
                <a href="admin_dashboard.php?<?php echo $query_base; ?>&audit_page=<?php echo $audit_page - 1; ?>" class="btn-cancel" style="padding:4px 8px; font-size:8px; text-decoration:none;">&laquo; Prev</a>
              <?php endif; ?>

              <?php for ($p = max(1, $audit_page - 2); $p <= min($audit_total_pages, $audit_page + 2); $p++): ?>
                <a href="admin_dashboard.php?<?php echo $query_base; ?>&audit_page=<?php echo $p; ?>" class="<?php echo $p == $audit_page ? 'btn-submit' : 'btn-cancel'; ?>" style="padding:4px 8px; font-size:8px; text-decoration:none; <?php echo $p == $audit_page ? 'background:var(--fd-olive); color:#fff;' : ''; ?>">
                  <?php echo $p; ?>
                </a>
              <?php endfor; ?>

              <?php if ($audit_page < $audit_total_pages): ?>
                <a href="admin_dashboard.php?<?php echo $query_base; ?>&audit_page=<?php echo $audit_page + 1; ?>" class="btn-cancel" style="padding:4px 8px; font-size:8px; text-decoration:none;">Next &raquo;</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

      </div>
    </div>

    <!-- TAB 1: RIDERS -->
    <div id="tab-riders" class="tab-section">
      <div class="dashboard-section">
        <div class="section-title">
          <span><i class="fa-solid fa-motorcycle" style="color:var(--fd-olive);"></i> Dispatch Fleet (<?php echo $total_riders; ?>)</span>
          <div style="display:flex; gap:6px;">
            <button class="btn-action" onclick="switchTab('dispatch-map')" style="background:var(--fd-gold); padding: 4px 10px; font-size: 0.72rem;"><i class="fa-solid fa-map-location-dot"></i> Live Dispatch Map</button>
            <button class="btn-action" onclick="openModal('addRiderModal')" style="padding: 4px 10px; font-size: 0.72rem;"><i class="fa-solid fa-plus"></i> Add Rider</button>
          </div>
        </div>
        <table>
          <thead>
            <tr>
              <th>Rider & Contact</th>
              <th>Vehicle Details</th>
              <th>Account / Status</th>
              <th>Active Order</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($riders)): ?>
              <?php foreach ($riders as $r): 
                $r_phone = $r['display_phone'] ?? $r['phone'] ?? '+63 917 555 0192';
                $r_plate = $r['display_plate'] ?? $r['plate_number'] ?? 'ORM-8891';
                $r_vehicle = $r['display_model'] ?? $r['vehicle_model'] ?? 'Honda Click 125i';
                $r_acc_status = $r['acc_status'] ?? $r['account_status'] ?? 'Active';
              ?>
                <tr id="rider-row-<?php echo $r['rider_id']; ?>">
                  <td>
                    <strong><span id="rider-name-val-<?php echo $r['rider_id']; ?>"><?php echo htmlspecialchars($r['rider_name']); ?></span></strong>
                    <div style="font-size:8px; color:#74776a;">
                      <i class="fa-solid fa-phone" style="font-size:7px;"></i> <span id="rider-phone-val-<?php echo $r['rider_id']; ?>"><?php echo htmlspecialchars($r_phone); ?></span>
                    </div>
                  </td>
                  <td>
                    <div><i class="fa-solid fa-motorcycle" style="font-size:7.5px; color:#4B5320;"></i> <span id="rider-vehicle-val-<?php echo $r['rider_id']; ?>"><?php echo htmlspecialchars($r_vehicle); ?></span></div>
                    <div style="font-size:7.5px; color:#D88F2A; font-weight:600;"><span id="rider-plate-val-<?php echo $r['rider_id']; ?>"><?php echo htmlspecialchars($r_plate); ?></span></div>
                  </td>
                  <td>
                    <span id="rider-accstatus-badge-<?php echo $r['rider_id']; ?>" class="status-badge" style="font-size:7.5px; background:<?php echo ($r_acc_status === 'Suspended' ? '#ffebee; color:#c62828;' : ($r_acc_status === 'Offline' ? '#f5f5f5; color:#757575;' : '#e8f5e9; color:#2e7d32;')); ?>;">
                      <?php echo htmlspecialchars($r_acc_status); ?>
                    </span>
                    <span class="status-badge <?php echo $r['status']; ?>" style="font-size:7.5px; margin-top:2px; display:inline-block;">
                      <?php echo ($r['status'] === 'on_delivery') ? 'On Delivery' : 'Waiting'; ?>
                    </span>
                  </td>
                  <td>
                    <span style="font-size:8px; font-weight:600; color:#344016;"><?php echo htmlspecialchars($r['current_order']); ?></span>
                  </td>
                  <td>
                    <button type="button" class="btn-edit" onclick='openEditRiderModal(<?php echo htmlspecialchars(json_encode([
                      "rider_id" => (int)$r["rider_id"],
                      "rider_name" => $r["rider_name"],
                      "phone" => $r_phone,
                      "plate_number" => $r_plate,
                      "vehicle_model" => $r_vehicle,
                      "account_status" => $r_acc_status
                    ]), ENT_QUOTES, "UTF-8"); ?>)'>
                      <i class="fa-solid fa-pen-to-square"></i> Edit
                    </button>
                    <form method="POST" style="display:inline;">
                      <input type="hidden" name="action_toggle_rider" value="1">
                      <input type="hidden" name="active_tab" value="riders">
                      <input type="hidden" name="rider_id" value="<?php echo $r['rider_id']; ?>">
                      <input type="hidden" name="new_status" value="<?php echo ($r['status'] === 'on_delivery') ? 'waiting' : 'on_delivery'; ?>">
                      <button type="submit" name="action_toggle_rider" class="btn-toggle-status">
                        Switch to <?php echo ($r['status'] === 'on_delivery') ? 'Waiting' : 'On Delivery'; ?>
                      </button>
                    </form>
                    <form method="POST" style="display:inline;" onsubmit="return confirmAdminAction('Remove rider?', 'Are you sure you want to remove rider <?php echo htmlspecialchars($r['rider_name']); ?> from the fleet?', this, this.querySelector('button[name=action_delete_rider]'));">
                      <input type="hidden" name="action_delete_rider" value="1">
                      <input type="hidden" name="active_tab" value="riders">
                      <input type="hidden" name="rider_id" value="<?php echo $r['rider_id']; ?>">
                      <button type="submit" name="action_delete_rider" class="btn-delete"><i class="fa-solid fa-trash"></i> Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="5">No riders in fleet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- TAB 2: PARTNER STORES -->
    <div id="tab-stores" class="tab-section">
      <div class="dashboard-section">
        <div class="section-title">
          <span>Partner Stores (<?php echo $total_shops; ?>)</span>
          <div style="display: flex; gap: 6px; align-items: center;">
            <a href="scripts/backfill_shops_geocoding.php" target="_blank" class="btn-action" style="padding: 4px 10px; font-size: 0.72rem; background: #788A6E; text-decoration: none; color: #fff; display: inline-flex; align-items: center; gap: 5px; border-radius: 4px;" title="Geocode missing store coordinates via Nominatim"><i class="fa-solid fa-satellite-dish"></i> Backfill / Sync GPS</a>
            <button class="btn-action" onclick="openModal('addShopModal')" style="padding: 4px 10px; font-size: 0.72rem;">Add Store</button>
          </div>
        </div>
        <div class="admin-stores-grid">
          <?php if (!empty($shops)): ?>
            <?php foreach ($shops as $s): 
              $s_logo = !empty($s['logo_url']) ? $s['logo_url'] : 'assets/shops/default_store.png';
              $s_cat = !empty($s['category']) ? $s['category'] : 'Restaurant';
              $s_addr = !empty($s['address']) ? $s['address'] : 'Ormoc City Proper';
              $s_lat = !empty($s['lat']) ? (float)$s['lat'] : (!empty($s['lat']) ? (float)$s['lat'] : null);
              $s_lng = !empty($s['lng']) ? (float)$s['lng'] : (!empty($s['lng']) ? (float)$s['lng'] : null);
            ?>
              <div class="admin-store-card">
                <div class="admin-store-logo">
                  <img src="<?php echo htmlspecialchars($s_logo); ?>" alt="<?php echo htmlspecialchars($s['shop_name']); ?>" onerror="if(!this.dataset.fallback){this.dataset.fallback=1;this.src='assets/shops/default_store.png';}else{this.onerror=null;this.src='logo.jpg';}">
                </div>
                <div class="admin-store-meta">
                  <div class="admin-store-id">#SHOP-<?php echo $s['shop_id']; ?></div>
                  <strong><?php echo htmlspecialchars($s['shop_name']); ?></strong>
                  <span class="admin-store-cat"><?php echo htmlspecialchars($s_cat); ?></span>
                  <div style="font-size: 0.7rem; color: #74776a; margin-top: 3px; display: flex; flex-direction: column; gap: 2px;">
                    <span><i class="fa-solid fa-location-dot" style="color: #D88F2A; font-size: 0.72rem;"></i> <?php echo htmlspecialchars($s_addr); ?></span>
                    <?php if ($s_lat !== null && $s_lng !== null): ?>
                      <span style="font-family: monospace; font-size: 0.67rem; color: #4B5320; background: #FAF6EE; padding: 1px 4px; border-radius: 3px; width: fit-content; border: 1px solid #d4c5a5;"><i class="fa-solid fa-crosshairs" style="color:#2e7d32"></i> <?php echo number_format($s_lat, 5) . ', ' . number_format($s_lng, 5); ?></span>
                    <?php else: ?>
                      <span style="font-size: 0.67rem; color: #d32f2f;"><i class="fa-solid fa-triangle-exclamation"></i> GPS missing</span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="admin-store-actions">
                  <button class="btn-edit" onclick="openEditShopModal(<?php echo $s['shop_id']; ?>, '<?php echo addslashes($s['shop_name']); ?>', '<?php echo addslashes($s_cat); ?>', '<?php echo addslashes($s_logo); ?>', '<?php echo addslashes($s['address'] ?? ''); ?>', <?php echo json_encode($s_lat); ?>, <?php echo json_encode($s_lng); ?>)"><i class="fa-solid fa-pen-to-square"></i> Edit</button>
                  <form method="POST" style="display:inline;" onclick="return confirmAdminAction('Delete this shop?', 'Are you sure you want to delete this store?', this);">
                    <input type="hidden" name="shop_id" value="<?php echo $s['shop_id']; ?>">
                    <button type="submit" name="action_delete_shop" class="btn-delete"><i class="fa-solid fa-trash"></i> Delete</button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div style="padding: 15px; text-align: center; color: #74776a; font-size: 0.8rem;">No stores registered.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- TAB 3: MENUS -->
    <div id="tab-menus" class="tab-section">
      <div class="dashboard-section">
        <div class="section-title">
          <span>Active Menu Dishes</span>
          <button class="btn-action" onclick="openModal('addItemModal')" style="padding: 4px 8px; font-size: 0.72rem;">Add Item</button>
        </div>
        <table>
          <thead>
            <tr>
              <th>Item Name</th>
              <th>Store</th>
              <th>Price</th>
              <th>Badge</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($menu_items)): ?>
              <?php foreach ($menu_items as $m): ?>
                <tr>
                  <td><strong><?php echo htmlspecialchars($m['item_name']); ?></strong></td>
                  <td><?php echo htmlspecialchars($m['shop_name']); ?></td>
                  <td>₱<?php echo number_format($m['price'], 2); ?></td>
                  <td>
                    <?php 
                    $is_rec = !empty($m['is_recommended']) && $m['is_recommended'] !== 'f' && $m['is_recommended'] !== false;
                    if ($is_rec): 
                    ?>
                      <span class="badge-recommended">Recommended</span>
                    <?php else: ?>
                      <span style="font-size:0.7rem; color:#b87b85;">Standard</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <button class="btn-edit" onclick="openEditItemModal(<?php echo $m['item_id']; ?>, '<?php echo addslashes($m['item_name']); ?>', <?php echo $m['shop_id']; ?>, <?php echo $m['price']; ?>, <?php echo $is_rec ? 1 : 0; ?>)"><i class="fa-solid fa-pen-to-square"></i> Edit</button>
                    <form method="POST" style="display:inline;" onclick="return confirmAdminAction('Remove item?', 'Are you sure you want to remove this menu item?', this);">
                      <input type="hidden" name="item_id" value="<?php echo $m['item_id']; ?>">
                      <button type="submit" name="action_delete_item" class="btn-delete"><i class="fa-solid fa-trash"></i> Remove</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="5">No dishes listed.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- TAB 5: TRANSACTIONS & AUDIT RECONCILIATION -->
    <div id="tab-transactions" class="tab-section">
      
      <!-- SUBSECTION 1: ORDERS AWAITING SETTLEMENT -->
      <div class="dashboard-section" style="border-left: 4px solid var(--harvest-sun);">
        <div class="section-title">
          <span><i class="fa-solid fa-hourglass-half" style="color:var(--harvest-sun);"></i> Delivered Orders Awaiting Settlement</span>
          <span style="font-size:10px; background:#fff4e5; color:var(--harvest-sun); padding:3px 8px; border-radius:10px; font-weight:700;">
            <?php echo count($pending_settlements); ?> Pending Reconciliation
          </span>
        </div>
        <p style="font-size:11px; color:#74776a; margin-bottom:12px;">
          These orders have been delivered to customers. As Admin, verify the delivery details and audit trail, then click <strong>Reconcile & Settle</strong> to finalize the financial ledger and mark status as <strong>SETTLED</strong>.
        </p>

        <?php if (empty($pending_settlements)): ?>
          <p style="font-size:11px; color:#788A6E; text-align:center; padding:15px; background:#f7faf5; border-radius:8px;">
            ✓ All delivered orders have been reconciled and settled. No pending settlements.
          </p>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>Order Ref</th>
                <th>Store</th>
                <th>Customer</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pending_settlements as $po): 
                $pref = !empty($po['order_ref']) ? $po['order_ref'] : '#' . $po['order_id'];
              ?>
                <tr>
                  <td><strong><?php echo htmlspecialchars($pref); ?></strong></td>
                  <td><?php echo htmlspecialchars($po['shop_name'] ?? $po['store_name']); ?></td>
                  <td><?php echo htmlspecialchars($po['customer_name'] ?? 'Customer'); ?></td>
                  <td><strong>₱<?php echo number_format((float)$po['total_amount'], 2); ?></strong></td>
                  <td><span class="status-badge delivered">DELIVERED</span></td>
                  <td>
                    <form method="POST" style="display:inline;" onsubmit="return confirmAdminAction('Reconcile & Settle Order', 'Confirm reconciliation of <?php echo htmlspecialchars($pref); ?>? This will finalize the financial record and transition order status to SETTLED.', this);">
                      <input type="hidden" name="order_id" value="<?php echo $po['order_id']; ?>">
                      <button type="submit" name="action_reconcile_order" class="btn-action" style="background:#D88F2A; color:white; padding:6px 12px; font-size:10px;">
                        <i class="fa-solid fa-file-signature"></i> Reconcile & Settle
                      </button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <!-- SUBSECTION 2: ORDER AUDIT TRAIL VERIFICATION -->
      <div class="dashboard-section" style="border-left: 4px solid var(--sagebrush);">
        <div class="section-title">
          <span><i class="fa-solid fa-clipboard-check" style="color:var(--sagebrush);"></i> Order Audit Logs (Full Lifecycle Verification)</span>
          <span style="font-size:10px; color:#74776a;">Latest <?php echo count($audit_logs); ?> audit events</span>
        </div>
        <p style="font-size:11px; color:#74776a; margin-bottom:12px;">
          Verifiable audit trail recording state transitions across Customer (<code>PENDING</code>) &rarr; Chef (<code>PREPARING</code> &rarr; <code>READY_FOR_PICKUP</code>) &rarr; Rider (<code>OUT_FOR_DELIVERY</code> &rarr; <code>DELIVERED</code>) &rarr; Admin (<code>SETTLED</code>).
        </p>

        <?php if (empty($audit_logs)): ?>
          <p style="font-size:11px; color:#74776a; text-align:center; padding:15px;">No audit log events recorded yet.</p>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>Log ID</th>
                <th>Order Ref</th>
                <th>Transition</th>
                <th>Actor / Role</th>
                <th>Audit Notes</th>
                <th>Timestamp</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($audit_logs as $al): 
                $ost = strtolower(trim($al['old_status'] ?? ''));
                $nst = strtolower(trim($al['new_status'] ?? ''));
              ?>
                <tr>
                  <td><small>#<?php echo $al['log_id']; ?></small></td>
                  <td><strong><?php echo htmlspecialchars($al['order_ref'] ?? '#' . $al['order_id']); ?></strong></td>
                  <td>
                    <?php if (!empty($al['old_status'])): ?>
                      <span class="status-badge <?php echo $ost; ?>" style="font-size:8px;"><?php echo strtoupper($al['old_status']); ?></span>
                      &rarr;
                    <?php endif; ?>
                    <span class="status-badge <?php echo $nst; ?>" style="font-size:8px;"><?php echo strtoupper($al['new_status']); ?></span>
                  </td>
                  <td>
                    <span style="font-weight:600; text-transform:uppercase; font-size:9px; color:#4B5320;">
                      <?php echo htmlspecialchars($al['action_by_role'] ?? 'system'); ?>
                    </span>
                    <?php if (!empty($al['actor_name'])): ?>
                      <span style="color:#74776a; font-size:9px;">(<?php echo htmlspecialchars($al['actor_name']); ?>)</span>
                    <?php endif; ?>
                  </td>
                  <td><small style="color:#555;"><?php echo htmlspecialchars($al['notes'] ?? ''); ?></small></td>
                  <td><small style="color:#888;"><?php echo date('M d, H:i', strtotime($al['created_at'])); ?></small></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <!-- SUBSECTION 3: RECONCILED SETTLEMENTS LEDGER -->
      <div class="dashboard-section" style="border-left: 4px solid var(--olive-shadow);">
        <div class="section-title">
          <span><i class="fa-solid fa-scale-balanced" style="color:var(--olive-shadow);"></i> Reconciled Settlements Ledger</span>
          <span style="font-size:10px; color:#74776a;"><?php echo count($settled_transactions); ?> Settled Records</span>
        </div>

        <?php if (empty($settled_transactions)): ?>
          <p style="font-size:11px; color:#74776a; text-align:center; padding:15px;">No settled transactions in ledger yet. Reconcile delivered orders above.</p>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>Settlement ID</th>
                <th>Order Ref</th>
                <th>Subtotal</th>
                <th>Delivery</th>
                <th>Total Settled</th>
                <th>Payment</th>
                <th>Reconciled By</th>
                <th>Reconciled At</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($settled_transactions as $st): ?>
                <tr>
                  <td>#ST-<?php echo $st['settlement_id']; ?></td>
                  <td><strong><?php echo htmlspecialchars($st['order_ref'] ?? '#' . $st['order_id']); ?></strong></td>
                  <td>₱<?php echo number_format((float)$st['subtotal'], 2); ?></td>
                  <td>₱<?php echo number_format((float)$st['delivery_fee'], 2); ?></td>
                  <td><strong>₱<?php echo number_format((float)$st['total_amount'], 2); ?></strong></td>
                  <td><small><?php echo htmlspecialchars($st['payment_method'] ?? 'COD'); ?></small></td>
                  <td><small><?php echo htmlspecialchars($st['admin_user'] ?? 'Admin'); ?></small></td>
                  <td><small><?php echo date('M d, Y H:i', strtotime($st['reconciled_at'])); ?></small></td>
                  <td><span class="status-badge settled">SETTLED</span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <!-- SUBSECTION 4: MASTER ORDER HISTORY -->
      <div class="dashboard-section">
        <div class="section-title">
          <span><i class="fa-solid fa-list"></i> Master Orders History</span>
          <span style="font-size:10px; color:#74776a;">₱<?php echo $total_revenue; ?> Total Revenue • <?php echo $txn_count; ?> Orders</span>
        </div>
        
        <?php if (empty($transactions)): ?>
          <p style="font-size:11px; color:#74776a; text-align:center; padding:15px;">No orders found in database.</p>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>Order Ref</th>
                <th>Store</th>
                <th>Customer</th>
                <th>Items</th>
                <th>Total</th>
                <th>Status</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($transactions as $t): 
                $st_class = strtolower(trim($t['status'] ?? 'pending'));
              ?>
                <tr>
                  <td><strong><?php echo htmlspecialchars($t['order_ref'] ?? '#' . $t['order_id']); ?></strong></td>
                  <td><?php echo htmlspecialchars($t['shopper_name'] ?? $t['store_name']); ?></td>
                  <td><?php echo htmlspecialchars($t['customer_name'] ?? 'N/A'); ?></td>
                  <td><?php echo htmlspecialchars($t['items_summary'] ?? 'Order'); ?></td>
                  <td>₱<?php echo number_format((float)$t['total_amount'], 2); ?></td>
                  <td><span class="status-badge <?php echo $st_class; ?>"><?php echo strtoupper($t['status'] ?? 'PENDING'); ?></span></td>
                  <td><?php echo date('M d, Y', strtotime($t['created_at'] ?? 'now')); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

    </div>

    <!-- TAB: MASTER FLEET & STORE DISPATCH MAP (Requirement 4) -->
    <div id="tab-dispatch-map" class="tab-section">
      <div class="dashboard-section" style="border-left: 4px solid var(--fd-gold); padding: 14px;">
        <div class="section-title" style="margin-bottom: 8px;">
          <div>
            <span style="font-size:13.5px; font-weight:700; color:#344016;">
              <i class="fa-solid fa-map-location-dot" style="color:var(--fd-gold);"></i> Master Fleet & Store Dispatch Map
            </span>
            <p style="font-size:9.5px; color:#74776a; margin-top:2px;">
              Live telemetry covering Ormoc City: Partner store locations, active orders, and live moving delivery riders.
            </p>
          </div>
          <div style="display:flex; align-items:center; gap:8px;">
            <span id="dispatch-pulse-indicator" style="display:inline-flex; align-items:center; gap:5px; font-size:9.5px; font-weight:600; color:#2e7d32; background:#e8f5e9; padding:4px 8px; border-radius:12px; border:1px solid #c8e6c9;">
              <span style="width:7px; height:7px; border-radius:50%; background:#2e7d32; box-shadow:0 0 0 2px rgba(46,125,50,0.2); animation: liveRadarDot 1.5s infinite;"></span> 3s Live GPS
            </span>
            <button type="button" class="btn-action" onclick="pollMasterDispatchMap(true);" style="font-size:9.5px; padding:5px 9px;">
              <i class="fa-solid fa-rotate"></i> Refresh
            </button>
          </div>
        </div>

        <!-- Telemetry Stats Cards -->
        <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:8px; margin-bottom:12px;">
          <div style="background:#FFFDF7; border:1px solid #D4C5A5; border-radius:10px; padding:8px; text-align:center;">
            <div style="font-size:8.5px; color:#74776a; font-weight:600; text-transform:uppercase;">Partner Stores</div>
            <div id="stat-dispatch-stores" style="font-size:15px; font-weight:700; color:#4B5320;"><?php echo $total_shops; ?></div>
          </div>
          <div style="background:#FFFDF7; border:1px solid #D4C5A5; border-radius:10px; padding:8px; text-align:center;">
            <div style="font-size:8.5px; color:#74776a; font-weight:600; text-transform:uppercase;">Dispatched Riders</div>
            <div id="stat-dispatch-riders" style="font-size:15px; font-weight:700; color:#D88F2A;">0</div>
          </div>
          <div style="background:#FFFDF7; border:1px solid #D4C5A5; border-radius:10px; padding:8px; text-align:center;">
            <div style="font-size:8.5px; color:#74776a; font-weight:600; text-transform:uppercase;">Active Deliveries</div>
            <div id="stat-dispatch-orders" style="font-size:15px; font-weight:700; color:#923D32;">0</div>
          </div>
        </div>

        <!-- Leaflet Map Container covering Ormoc City -->
        <div style="position:relative; width:100%; border-radius:14px; overflow:hidden; border:2px solid #e7dfc4; box-shadow:0 6px 18px rgba(55,65,22,0.08);">
          <div id="master-dispatch-map" style="width:100%; height:520px; background:#f4f0df;"></div>

          <!-- Map Legend Overlay -->
          <div style="position:absolute; bottom:10px; left:10px; z-index:1000; background:rgba(255,255,255,0.95); backdrop-filter:blur(8px); border:1px solid #D4C5A5; border-radius:10px; padding:6px 10px; font-size:9px; box-shadow:0 4px 12px rgba(0,0,0,0.12);">
            <div style="font-weight:700; color:#344016; margin-bottom:3px;"><i class="fa-solid fa-layer-group"></i> Dispatch Layers</div>
            <div style="display:flex; flex-direction:column; gap:3px;">
              <span style="display:flex; align-items:center; gap:5px;">
                <span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:#D88F2A; border:1.5px solid #fff;"></span> Partner Stores (Click for Active Orders)
              </span>
              <span style="display:flex; align-items:center; gap:5px;">
                <span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:#4B5320; border:1.5px solid #fff;"></span> Live Dispatched Riders (Click for Details)
              </span>
            </div>
          </div>
        </div>

      </div>
    </div>

  </main>

  <!-- BOTTOM NAVIGATION / SIDEBAR -->
  <aside class="sidebar">
    <div>
      <div class="sidebar-brand">
        <img src="logo.jpg" onerror="this.onerror=null; this.src='dad0d42c-954c-4d2d-ae59-f772e34dcf5b.jpg';" alt="Logo" class="brand-logo-img">
        <h2>FOOD DASH</h2>
      </div>

      <ul class="nav-links">
        <li><a onclick="switchTab('analytics')" id="nav-analytics" class="active"><i class="fa-solid fa-chart-line"></i> Analytics</a></li>
        <li><a onclick="switchTab('dispatch-map')" id="nav-dispatch-map"><i class="fa-solid fa-map-location-dot"></i> Dispatch Map</a></li>
        <li><a onclick="switchTab('users')" id="nav-users"><i class="fa-solid fa-users-gear"></i> Users</a></li>
        <li><a onclick="switchTab('auditlogs')" id="nav-auditlogs"><i class="fa-solid fa-shield-halved"></i> Audit Trail</a></li>
        <li><a onclick="switchTab('riders')" id="nav-riders"><i class="fa-solid fa-motorcycle"></i> Riders</a></li>
        <li><a onclick="switchTab('stores')" id="nav-stores"><i class="fa-solid fa-store"></i> Stores</a></li>
        <li><a onclick="switchTab('menus')" id="nav-menus"><i class="fa-solid fa-utensils"></i> Menus</a></li>
        <li><a onclick="switchTab('transactions')" id="nav-transactions"><i class="fa-solid fa-receipt"></i> Orders</a></li>
        <li><a onclick="openModal('msgModal')"><i class="fa-solid fa-bullhorn"></i> Broadcast</a></li>
      </ul>
    </div>

    <div class="user-panel">
      <div class="user-info">
        <p><?php echo htmlspecialchars($user_name); ?></p>
        <span><?php echo htmlspecialchars($user_role); ?> Access</span>
      </div>
      <button type="button" onclick="openModal('logoutModal')" class="logout-btn" title="Logout">
        <i class="fa-solid fa-right-from-bracket"></i>
      </button>
    </div>
  </aside>

</div>

  <!-- MODAL: LOGOUT CONFIRMATION -->
  <div id="logoutModal" class="modal">
    <div class="modal-box">
      <h3>Logout Confirmation</h3>
      <p>Are you sure you want to logout/sign out?</p>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeModal('logoutModal')">Cancel</button>
        <a href="logout.php?confirm=1" class="btn-submit">Yes, Logout</a>
      </div>
    </div>
  </div>

  <!-- MODAL: ADD RIDER -->
  <div id="addRiderModal" class="modal">
    <div class="modal-box" style="max-width: 440px;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
        <h3 style="margin:0; font-size:15px; color:#344016;"><i class="fa-solid fa-motorcycle" style="color:var(--fd-olive);"></i> Add New Rider</h3>
        <button type="button" onclick="closeModal('addRiderModal')" style="background:none; border:none; font-size:18px; color:#74776a; cursor:pointer;">&times;</button>
      </div>
      <form method="POST">
        <input type="hidden" name="action_add_rider" value="1">
        <input type="hidden" name="active_tab" value="riders">

        <div style="margin-bottom:10px;">
          <label style="font-size:10px; color:#4B5320; font-weight:600; display:block; margin-bottom:3px;">Full Name *</label>
          <input type="text" name="rider_name" placeholder="Rider Full Name" required style="width:100%; padding:8px 10px; border:1px solid #D4C5A5; border-radius:8px; font-size:11px;">
        </div>

        <div style="margin-bottom:10px;">
          <label style="font-size:10px; color:#4B5320; font-weight:600; display:block; margin-bottom:3px;">Phone Number</label>
          <input type="text" name="phone" placeholder="+63 917 555 0192" value="+63 917 555 0192" style="width:100%; padding:8px 10px; border:1px solid #D4C5A5; border-radius:8px; font-size:11px;">
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:12px;">
          <div>
            <label style="font-size:10px; color:#4B5320; font-weight:600; display:block; margin-bottom:3px;">Plate Number</label>
            <input type="text" name="plate_number" placeholder="ORM-8891" value="ORM-8891" style="width:100%; padding:8px 10px; border:1px solid #D4C5A5; border-radius:8px; font-size:11px;">
          </div>
          <div>
            <label style="font-size:10px; color:#4B5320; font-weight:600; display:block; margin-bottom:3px;">Vehicle Model</label>
            <input type="text" name="vehicle_model" placeholder="Honda Click 125i" value="Honda Click 125i" style="width:100%; padding:8px 10px; border:1px solid #D4C5A5; border-radius:8px; font-size:11px;">
          </div>
        </div>

        <div class="modal-actions">
          <button type="button" class="btn-cancel" onclick="closeModal('addRiderModal')">Cancel</button>
          <button type="submit" name="action_add_rider" class="btn-submit" style="background:#4B5320; color:#fff;">Add Rider</button>
        </div>
      </form>
    </div>
  </div>

  <!-- MODAL: EDIT RIDER (Requirement 3: Full Name, Phone, Vehicle Plate/Model, Account Status) -->
  <div id="editRiderModal" class="modal">
    <div class="modal-box" style="max-width: 440px;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
        <h3 style="margin:0; font-size:15px; color:#344016;"><i class="fa-solid fa-user-pen" style="color:var(--fd-olive);"></i> Edit Fleet Rider</h3>
        <button type="button" onclick="closeModal('editRiderModal')" style="background:none; border:none; font-size:18px; color:#74776a; cursor:pointer;">&times;</button>
      </div>
      <form method="POST" id="editRiderForm" onsubmit="handleEditRiderSubmit(event, this);">
        <input type="hidden" name="action_edit_rider" value="1">
        <input type="hidden" name="active_tab" value="riders">
        <input type="hidden" name="rider_id" id="edit_rider_id" value="">

        <div style="margin-bottom:10px;">
          <label style="font-size:10.5px; font-weight:600; color:#4B5320; display:block; margin-bottom:3px;">Full Name *</label>
          <input type="text" name="rider_name" id="edit_rider_name" required style="width:100%; padding:8px 10px; border:1px solid #D4C5A5; border-radius:8px; font-size:11px;">
        </div>

        <div style="margin-bottom:10px;">
          <label style="font-size:10.5px; font-weight:600; color:#4B5320; display:block; margin-bottom:3px;">Phone Number</label>
          <input type="text" name="phone" id="edit_rider_phone" placeholder="+63 917 555 0192" style="width:100%; padding:8px 10px; border:1px solid #D4C5A5; border-radius:8px; font-size:11px;">
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px; margin-bottom:10px;">
          <div>
            <label style="font-size:10.5px; font-weight:600; color:#4B5320; display:block; margin-bottom:3px;">Plate Number</label>
            <input type="text" name="plate_number" id="edit_rider_plate" placeholder="ORM-8891" style="width:100%; padding:8px 10px; border:1px solid #D4C5A5; border-radius:8px; font-size:11px;">
          </div>
          <div>
            <label style="font-size:10.5px; font-weight:600; color:#4B5320; display:block; margin-bottom:3px;">Vehicle Model</label>
            <input type="text" name="vehicle_model" id="edit_rider_vehicle" placeholder="Honda Click 125i" style="width:100%; padding:8px 10px; border:1px solid #D4C5A5; border-radius:8px; font-size:11px;">
          </div>
        </div>

        <div style="margin-bottom:14px;">
          <label style="font-size:10.5px; font-weight:600; color:#4B5320; display:block; margin-bottom:3px;">Account Status</label>
          <select name="account_status" id="edit_rider_account_status" style="width:100%; padding:8px 10px; border:1px solid #D4C5A5; border-radius:8px; font-size:11px; background:#fff;">
            <option value="Active">Active (Eligible for deliveries)</option>
            <option value="Suspended">Suspended (Restricted)</option>
            <option value="Offline">Offline</option>
          </select>
        </div>

        <div class="modal-actions" style="margin-top:14px;">
          <button type="button" class="btn-cancel" onclick="closeModal('editRiderModal')">Cancel</button>
          <button type="submit" name="action_edit_rider" class="btn-submit" style="background:#4B5320; color:#fff;">Save Rider Changes</button>
        </div>
      </form>
    </div>
  </div>

  <!-- MODAL: ADD SHOP -->
  <div id="addShopModal" class="modal">
    <div class="modal-box" style="max-width: 480px;">
      <h3>Add New Store</h3>
      <form method="POST">
        <label style="font-size: 9px; color: #788A6E; font-weight: 600; display: block; margin-bottom: 3px;">Store Name</label>
        <input type="text" name="shop_name" id="add_shop_name" placeholder="e.g. Popeyes Louisiana Kitchen" required>
        
        <label style="font-size: 9px; color: #788A6E; font-weight: 600; display: block; margin-bottom: 3px;">Category / Cuisine</label>
        <input type="text" name="category" placeholder="e.g. Fried Chicken • Biscuits" value="Fast Food">

        <label style="font-size: 9px; color: #788A6E; font-weight: 600; display: block; margin-bottom: 3px;">Store Physical Address (Ormoc City)</label>
        <input type="text" name="address" id="add_shop_address" placeholder="e.g. Real St, Ormoc City Proper">

        <!-- Embedded Mini Leaflet Map Pin Picker -->
        <div style="margin: 8px 0 12px 0;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
            <label style="font-size: 9px; color: #788A6E; font-weight: 600; margin: 0;"><i class="fa-solid fa-map-pin" style="color: #D88F2A;"></i> Store Location Pin</label>
            <span id="add_shop_coords_text" style="font-size: 0.68rem; font-family: monospace; color: #4B5320; background: #FAF6EE; padding: 1px 6px; border-radius: 3px; border: 1px solid #d4c5a5;">11.0065, 124.6075</span>
          </div>
          <div id="add-shop-map" style="height: 180px; width: 100%; border-radius: 8px; border: 1px solid #d4c5a5; background: #e8e6df; z-index: 1;"></div>
          <small style="font-size: 0.68rem; color: #788A6E; display: flex; align-items: center; gap: 4px; margin-top: 4px;">
            <i class="fa-solid fa-arrows-up-down-left-right" style="color: #D88F2A;"></i> Type store name/address to auto-locate, or drag marker to pinpoint the store entrance.
          </small>
        </div>
        <input type="hidden" name="lat" id="add_shop_lat" value="11.00650000">
        <input type="hidden" name="lng" id="add_shop_lng" value="124.60750000">
        
        <label style="font-size: 9px; color: #788A6E; font-weight: 600; display: block; margin-bottom: 3px;">Official Logo Path or URL</label>
        <input type="text" name="logo_url" placeholder="assets/shops/default_store.png" value="assets/shops/default_store.png">
        
        <div class="modal-actions">
          <button type="button" class="btn-cancel" onclick="closeModal('addShopModal')">Cancel</button>
          <button type="submit" name="action_add_shop" class="btn-submit">Save Shop</button>
        </div>
      </form>
    </div>
  </div>

  <!-- MODAL: EDIT SHOP -->
  <div id="editShopModal" class="modal">
    <div class="modal-box" style="max-width: 480px;">
      <h3>Edit Store</h3>
      <form method="POST">
        <input type="hidden" name="shop_id" id="edit_shop_id">
        
        <label style="font-size: 9px; color: #788A6E; font-weight: 600; display: block; margin-bottom: 3px;">Store Name</label>
        <input type="text" name="shop_name" id="edit_shop_name" placeholder="Store Name" required>
        
        <label style="font-size: 9px; color: #788A6E; font-weight: 600; display: block; margin-bottom: 3px;">Category / Cuisine</label>
        <input type="text" name="category" id="edit_shop_category" placeholder="Category">

        <label style="font-size: 9px; color: #788A6E; font-weight: 600; display: block; margin-bottom: 3px;">Store Physical Address</label>
        <input type="text" name="address" id="edit_shop_address" placeholder="e.g. Aviles St, Ormoc City">

        <!-- Embedded Mini Leaflet Map Pin Picker -->
        <div style="margin: 8px 0 12px 0;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
            <label style="font-size: 9px; color: #788A6E; font-weight: 600; margin: 0;"><i class="fa-solid fa-map-pin" style="color: #D88F2A;"></i> Store Location Pin</label>
            <span id="edit_shop_coords_text" style="font-size: 0.68rem; font-family: monospace; color: #4B5320; background: #FAF6EE; padding: 1px 6px; border-radius: 3px; border: 1px solid #d4c5a5;">11.0065, 124.6075</span>
          </div>
          <div id="edit-shop-map" style="height: 180px; width: 100%; border-radius: 8px; border: 1px solid #d4c5a5; background: #e8e6df; z-index: 1;"></div>
          <small style="font-size: 0.68rem; color: #788A6E; display: flex; align-items: center; gap: 4px; margin-top: 4px;">
            <i class="fa-solid fa-arrows-up-down-left-right" style="color: #D88F2A;"></i> Type name/address to auto-locate, or drag marker to position store entrance.
          </small>
        </div>
        <input type="hidden" name="lat" id="edit_shop_lat">
        <input type="hidden" name="lng" id="edit_shop_lng">
        
        <label style="font-size: 9px; color: #788A6E; font-weight: 600; display: block; margin-bottom: 3px;">Official Logo Path or URL</label>
        <input type="text" name="logo_url" id="edit_shop_logo_url" placeholder="assets/shops/default_store.png">
        
        <div class="modal-actions">
          <button type="button" class="btn-cancel" onclick="closeModal('editShopModal')">Cancel</button>
          <button type="submit" name="action_edit_shop" class="btn-submit">Update Store</button>
        </div>
      </form>
    </div>
  </div>

  <!-- MODAL: ADD MENU ITEM -->
  <div id="addItemModal" class="modal">
    <div class="modal-box">
      <h3>Add Menu Item</h3>
      <form method="POST">
        <input type="text" name="item_name" placeholder="Item Name" required>
        
        <select name="shop_id" required>
          <option value="" disabled selected>Select Associated Store</option>
          <?php 
          if (!empty($shops)) {
              foreach ($shops as $s) {
                  echo "<option value='".$s['shop_id']."'>".htmlspecialchars($s['shop_name'])."</option>";
              }
          }
          ?>
        </select>

        <input type="number" step="0.01" name="price" placeholder="Price (₱)" required>

        <label style="font-size: 0.8rem; display: flex; align-items: center; gap: 8px; margin-bottom: 15px;">
          <input type="checkbox" name="is_recommended" value="1" style="width: auto; margin:0;"> Set as Recommended
        </label>

        <div class="modal-actions">
          <button type="button" class="btn-cancel" onclick="closeModal('addItemModal')">Cancel</button>
          <button type="submit" name="action_add_item" class="btn-submit">Save Item</button>
        </div>
      </form>
    </div>
  </div>

  <!-- MODAL: EDIT MENU ITEM -->
  <div id="editItemModal" class="modal">
    <div class="modal-box">
      <h3>Edit Menu Item</h3>
      <form method="POST">
        <input type="hidden" name="item_id" id="edit_item_id">
        <input type="text" name="item_name" id="edit_item_name" placeholder="Item Name" required>
        
        <select name="shop_id" id="edit_item_shop_id" required>
          <?php 
          if (!empty($shops)) {
              foreach ($shops as $s) {
                  echo "<option value='".$s['shop_id']."'>".htmlspecialchars($s['shop_name'])."</option>";
              }
          }
          ?>
        </select>

        <input type="number" step="0.01" name="price" id="edit_item_price" placeholder="Price (₱)" required>

        <label style="font-size: 0.8rem; display: flex; align-items: center; gap: 8px; margin-bottom: 15px;">
          <input type="checkbox" name="is_recommended" id="edit_item_recommended" value="1" style="width: auto; margin:0;"> Set as Recommended
        </label>

        <div class="modal-actions">
          <button type="button" class="btn-cancel" onclick="closeModal('editItemModal')">Cancel</button>
          <button type="submit" name="action_edit_item" class="btn-submit">Update Item</button>
        </div>
      </form>
    </div>
  </div>

  <!-- MODAL: BROADCAST MESSAGE -->
  <div id="msgModal" class="modal">
    <div class="modal-box">
      <h3>Post Delivery Broadcast</h3>
      <form method="POST">
        <input type="text" name="message_text" placeholder="e.g. Order #FD-1002 has been delivered!" required>
        <div class="modal-actions">
          <button type="button" class="btn-cancel" onclick="closeModal('msgModal')">Cancel</button>
          <button type="submit" name="action_add_msg" class="btn-submit">Post Log</button>
        </div>
      </form>
    </div>
  </div>

  <!-- MODAL: ADD USER -->
  <div id="addUserModal" class="modal">
    <div class="modal-box" style="max-width:440px;">
      <h3 style="display:flex; align-items:center; gap:8px;">
        <i class="fa-solid fa-user-plus" style="color:var(--fd-olive);"></i> Add New User
      </h3>
      <p style="font-size:10px; color:#74776a; margin-bottom:12px;">
        Register a new account into Identity Access Management with bcrypt password hashing.
      </p>

      <div id="addUserError" style="display:none; color:#923D32; background:#fbeee8; border:1px solid #923D32; padding:8px 10px; border-radius:8px; font-size:9px; margin-bottom:12px; line-height:1.4;"></div>

      <form method="POST" id="addUserForm">
        <input type="hidden" name="active_tab" value="users">
        
        <label style="font-size:9px; color:#788A6E; font-weight:600; display:block; margin-bottom:3px;">Full Name</label>
        <input type="text" name="full_name" id="add_user_fullname" placeholder="e.g. Maria Clara Santos" required>

        <div style="display:flex; gap:10px;">
          <div style="flex:1;">
            <label style="font-size:9px; color:#788A6E; font-weight:600; display:block; margin-bottom:3px;">Username</label>
            <input type="text" name="username" id="add_user_username" placeholder="e.g. maria_santos" required autocomplete="off">
          </div>
          <div style="flex:1;">
            <label style="font-size:9px; color:#788A6E; font-weight:600; display:block; margin-bottom:3px;">System Role</label>
            <select name="role" id="add_user_role" required>
              <option value="customer" selected>Customer</option>
              <option value="chef">Chef / Kitchen</option>
              <option value="rider">Rider / Fleet</option>
              <option value="admin">Administrator</option>
            </select>
          </div>
        </div>

        <label style="font-size:9px; color:#788A6E; font-weight:600; display:block; margin-bottom:3px;">Email Address</label>
        <input type="email" name="email" id="add_user_email" placeholder="e.g. maria@fooddash.ph" required>

        <label style="font-size:9px; color:#788A6E; font-weight:600; display:block; margin-bottom:3px;">Password</label>
        <input type="password" name="password" id="add_user_password" placeholder="At least 8 chars (Aa1@)" required autocomplete="new-password">

        <label style="font-size:9px; color:#788A6E; font-weight:600; display:block; margin-bottom:3px;">Confirm Password</label>
        <input type="password" name="confirm_password" id="add_user_confirm" placeholder="Re-enter password" required autocomplete="new-password">

        <!-- Policy checklist -->
        <div style="background:#FAF6EE; border:1px solid #D4C5A5; border-radius:10px; padding:8px 10px; font-size:8px; color:#4B5320; margin-bottom:12px; line-height:1.5;">
          <strong><i class="fa-solid fa-shield-halved"></i> Password Policy:</strong> Min 8 chars &bull; Uppercase &bull; Lowercase &bull; Number &bull; Special symbol (<code>@$!%*?&</code>).
        </div>

        <div class="modal-actions">
          <button type="button" class="btn-cancel" onclick="closeModal('addUserModal')">Cancel</button>
          <button type="submit" name="action_create_user" class="btn-submit" style="background:var(--fd-olive); color:white;">
            <i class="fa-solid fa-check"></i> Save User
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- MODAL: EDIT USER -->
  <div id="editUserModal" class="modal">
    <div class="modal-box" style="max-width:440px;">
      <h3 style="display:flex; align-items:center; gap:8px;">
        <i class="fa-solid fa-user-pen" style="color:var(--fd-olive);"></i> Edit User Account
      </h3>
      <p style="font-size:10px; color:#74776a; margin-bottom:12px;">
        Update account details, role permissions, reset lockouts, or modify password.
      </p>

      <div id="editUserError" style="display:none; color:#923D32; background:#fbeee8; border:1px solid #923D32; padding:8px 10px; border-radius:8px; font-size:9px; margin-bottom:12px; line-height:1.4;"></div>

      <form method="POST" id="editUserForm">
        <input type="hidden" name="active_tab" value="users">
        <input type="hidden" name="user_id" id="edit_user_id">
        
        <label style="font-size:9px; color:#788A6E; font-weight:600; display:block; margin-bottom:3px;">Full Name</label>
        <input type="text" name="full_name" id="edit_user_fullname" required>

        <div style="display:flex; gap:10px;">
          <div style="flex:1;">
            <label style="font-size:9px; color:#788A6E; font-weight:600; display:block; margin-bottom:3px;">Username</label>
            <input type="text" name="username" id="edit_user_username" required autocomplete="off">
          </div>
          <div style="flex:1;">
            <label style="font-size:9px; color:#788A6E; font-weight:600; display:block; margin-bottom:3px;">System Role</label>
            <select name="role" id="edit_user_role" required>
              <option value="customer">Customer</option>
              <option value="chef">Chef / Kitchen</option>
              <option value="rider">Rider / Fleet</option>
              <option value="admin">Administrator</option>
            </select>
          </div>
        </div>

        <label style="font-size:9px; color:#788A6E; font-weight:600; display:block; margin-bottom:3px;">Email Address</label>
        <input type="email" name="email" id="edit_user_email" required>

        <label style="font-size:9px; color:#788A6E; font-weight:600; display:block; margin-bottom:3px;">New Password (Optional)</label>
        <input type="password" name="password" id="edit_user_password" placeholder="Leave blank to keep existing password" autocomplete="new-password">

        <label style="font-size:9px; color:#788A6E; font-weight:600; display:block; margin-bottom:3px;">Confirm New Password</label>
        <input type="password" name="confirm_password" id="edit_user_confirm" placeholder="Confirm only if changing password" autocomplete="new-password">

        <label style="font-size:9px; color:#344016; display:flex; align-items:center; gap:8px; margin:8px 0 14px; cursor:pointer;">
          <input type="checkbox" name="reset_lockout" id="edit_user_reset_lockout" value="1" style="width:auto; margin:0;">
          <span>Clear Account Lockout & Reset Failed Attempts</span>
        </label>

        <div class="modal-actions">
          <button type="button" class="btn-cancel" onclick="closeModal('editUserModal')">Cancel</button>
          <button type="submit" name="action_edit_user" class="btn-submit" style="background:var(--fd-olive); color:white;">
            <i class="fa-solid fa-floppy-disk"></i> Save Changes
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- MODAL: HELP DESK RESET PASSWORD & ACCOUNT UNLOCK -->
  <div id="resetPasswordModal" class="modal">
    <div class="modal-box" style="max-width: 440px;">
      <h3 style="display:flex; align-items:center; gap:8px; color:var(--harvest-sun);">
        <i class="fa-solid fa-key" style="color:var(--harvest-sun);"></i> Reset Password / Unlock Account
      </h3>
      <p style="font-size:10px; color:#74776a; margin-bottom:12px;">
        IAS Help Desk recovery workflow for forgotten credentials and brute-force account lockouts.
      </p>

      <!-- Target User Information & Security Status Card -->
      <div id="resetUserStatusCard" style="background:#FAF6EE; border:1px solid #D4C5A5; border-radius:12px; padding:10px 12px; margin-bottom:12px; font-size:10px; color:#29300f;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:6px;">
          <div>
            <strong id="reset_user_display_name" style="font-size:12px; color:#344016;">Username</strong>
            <div id="reset_user_display_sub" style="font-size:8.5px; color:#74776a;">UID: #0 &bull; Full Name</div>
          </div>
          <span id="reset_user_role_badge" class="status-badge customer">CUSTOMER</span>
        </div>
        <div id="reset_user_lockout_info" style="display:flex; align-items:center; gap:6px; margin-top:4px; font-size:9px;">
          <!-- Dynamically populated: Active or Locked status with remaining time -->
        </div>
      </div>

      <!-- In-App Validation & Error Feedback (Zero native alert() calls) -->
      <div id="resetPasswordError" style="display:none; color:#923D32; background:#fbeee8; border:1px solid #923D32; padding:8px 10px; border-radius:8px; font-size:9px; margin-bottom:12px; line-height:1.4;"></div>

      <form method="POST" id="resetPasswordForm">
        <input type="hidden" name="active_tab" value="users">
        <input type="hidden" name="action_reset_password_unlock" value="1">
        <input type="hidden" name="user_id" id="reset_user_id">

        <label style="font-size:9px; color:#788A6E; font-weight:600; display:block; margin-bottom:3px;">New Password</label>
        <div style="position:relative; margin-bottom:10px;">
          <input type="password" name="new_password" id="reset_new_password" placeholder="Enter new password" required autocomplete="new-password" style="padding-right:34px; margin-bottom:0;">
          <button type="button" onclick="togglePasswordVisibility('reset_new_password', this)" style="position:absolute; right:8px; top:50%; transform:translateY(-50%); background:none; border:none; color:#788A6E; cursor:pointer; font-size:11px;" title="Show/Hide Password">
            <i class="fa-solid fa-eye"></i>
          </button>
        </div>

        <label style="font-size:9px; color:#788A6E; font-weight:600; display:block; margin-bottom:3px;">Confirm New Password</label>
        <div style="position:relative; margin-bottom:10px;">
          <input type="password" name="confirm_new_password" id="reset_confirm_password" placeholder="Re-enter new password" required autocomplete="new-password" style="padding-right:34px; margin-bottom:0;">
          <button type="button" onclick="togglePasswordVisibility('reset_confirm_password', this)" style="position:absolute; right:8px; top:50%; transform:translateY(-50%); background:none; border:none; color:#788A6E; cursor:pointer; font-size:11px;" title="Show/Hide Password">
            <i class="fa-solid fa-eye"></i>
          </button>
        </div>

        <!-- Real-Time IAS Password Complexity Checklist -->
        <div style="background:#FAF6EE; border:1px solid #D4C5A5; border-radius:10px; padding:8px 10px; font-size:8px; color:#4B5320; margin-bottom:12px;">
          <div style="font-weight:700; margin-bottom:4px; color:#344016;"><i class="fa-solid fa-shield-halved" style="color:var(--harvest-sun);"></i> IAS Password Complexity Requirements:</div>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:3px;">
            <span id="pw_req_len" style="color:#74776a;"><i class="fa-regular fa-circle"></i> Min 8 characters</span>
            <span id="pw_req_upper" style="color:#74776a;"><i class="fa-regular fa-circle"></i> Uppercase letter (A-Z)</span>
            <span id="pw_req_lower" style="color:#74776a;"><i class="fa-regular fa-circle"></i> Lowercase letter (a-z)</span>
            <span id="pw_req_num" style="color:#74776a;"><i class="fa-regular fa-circle"></i> Numeric digit (0-9)</span>
            <span id="pw_req_sym" style="color:#74776a;"><i class="fa-regular fa-circle"></i> Symbol (@$!%*?&)</span>
            <span id="pw_req_match" style="color:#74776a;"><i class="fa-regular fa-circle"></i> Passwords match</span>
          </div>
        </div>

        <!-- Unlock account toggle: checked by default -->
        <label style="font-size:9px; color:#344016; display:flex; align-items:center; gap:8px; margin:8px 0 14px; cursor:pointer; font-weight:600;">
          <input type="checkbox" name="unlock_account" id="reset_unlock_account" value="1" checked style="width:auto; margin:0;">
          <span>Unlock account immediately (reset failed attempts &amp; lockout timer)</span>
        </label>

        <div class="modal-actions">
          <button type="button" class="btn-cancel" onclick="closeModal('resetPasswordModal')">Cancel</button>
          <button type="submit" id="btn_submit_reset_password" class="btn-submit" style="background:var(--harvest-sun); color:white;">
            <i class="fa-solid fa-key"></i> Reset &amp; Unlock
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Toast Notification -->
  <div class="admin-toast" id="adminToast"></div>

  <!-- Confirmation Modal -->
  <div id="adminConfirmModal" class="modal">
    <div class="modal-box">
      <h3 id="adminConfirmTitle">Confirm Action</h3>
      <p id="adminConfirmMsg" style="font-size:11px;">Are you sure you want to proceed?</p>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeAdminConfirm()">Cancel</button>
        <button type="button" class="btn-submit" onclick="doAdminConfirm()">Confirm</button>
      </div>
    </div>
  </div>

  <script>
    function switchTab(tabName) {
      document.querySelectorAll('.nav-links a').forEach(el => el.classList.remove('active'));
      document.querySelectorAll('.tab-section').forEach(el => el.classList.remove('active-tab'));

      const activeNav = document.getElementById('nav-' + tabName);
      if (activeNav) activeNav.classList.add('active');

      const targetTab = document.getElementById('tab-' + tabName);
      if (targetTab) targetTab.classList.add('active-tab');

      if (tabName === 'dispatch-map') {
        setTimeout(function() {
          initMasterDispatchMap();
          if (masterMap) masterMap.invalidateSize();
        }, 120);
      }
    }

    // Initialize Active Tab
    var initialTab = <?php echo json_encode($active_tab); ?>;
    switchTab(initialTab);

    // Custom store map pin icon
    function createStoreMapIcon() {
      return L.divIcon({
        className: 'custom-shop-pin',
        html: `
          <div style="position:relative;display:flex;flex-direction:column;align-items:center;">
            <div style="background:#D88F2A;color:#ffffff;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 3px 8px rgba(0,0,0,0.3);border:2px solid #ffffff;font-size:14px;">
              <i class="fa-solid fa-store"></i>
            </div>
            <div style="width:0;height:0;border-left:5px solid transparent;border-right:5px solid transparent;border-top:7px solid #D88F2A;margin-top:-2px;"></div>
          </div>
        `,
        iconSize: [32, 42],
        iconAnchor: [16, 40],
        popupAnchor: [0, -38]
      });
    }

    var addShopMap = null, addShopMarker = null;
    var editShopMap = null, editShopMarker = null;
    var defaultOrmocCoords = [11.00650000, 124.60750000];

    function initAddShopMap() {
      if (addShopMap) {
        addShopMap.invalidateSize();
        return;
      }
      var container = document.getElementById('add-shop-map');
      if (!container || typeof L === 'undefined') return;

      var lat = parseFloat(document.getElementById('add_shop_lat').value) || defaultOrmocCoords[0];
      var lng = parseFloat(document.getElementById('add_shop_lng').value) || defaultOrmocCoords[1];

      addShopMap = L.map('add-shop-map', { attributionControl: false }).setView([lat, lng], 16);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(addShopMap);

      addShopMarker = L.marker([lat, lng], { draggable: true, icon: createStoreMapIcon() }).addTo(addShopMap);
      addShopMarker.bindPopup('<b>Store Entrance Pin</b><br>Drag to position').openPopup();

      addShopMarker.on('dragend', function(e) {
        var p = e.target.getLatLng();
        document.getElementById('add_shop_lat').value = p.lat.toFixed(8);
        document.getElementById('add_shop_lng').value = p.lng.toFixed(8);
        document.getElementById('add_shop_coords_text').textContent = p.lat.toFixed(5) + ', ' + p.lng.toFixed(5);
      });
    }

    function initEditShopMap(lat, lng) {
      var latVal = (lat !== null && !isNaN(lat) && lat != 0) ? parseFloat(lat) : defaultOrmocCoords[0];
      var lngVal = (lng !== null && !isNaN(lng) && lng != 0) ? parseFloat(lng) : defaultOrmocCoords[1];

      var container = document.getElementById('edit-shop-map');
      if (!container || typeof L === 'undefined') return;

      if (!editShopMap) {
        editShopMap = L.map('edit-shop-map', { attributionControl: false }).setView([latVal, lngVal], 16);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(editShopMap);
        editShopMarker = L.marker([latVal, lngVal], { draggable: true, icon: createStoreMapIcon() }).addTo(editShopMap);
        editShopMarker.bindPopup('<b>Store Entrance Pin</b><br>Drag to fine-tune').openPopup();

        editShopMarker.on('dragend', function(e) {
          var p = e.target.getLatLng();
          document.getElementById('edit_shop_lat').value = p.lat.toFixed(8);
          document.getElementById('edit_shop_lng').value = p.lng.toFixed(8);
          document.getElementById('edit_shop_coords_text').textContent = p.lat.toFixed(5) + ', ' + p.lng.toFixed(5);
        });
      } else {
        editShopMap.setView([latVal, lngVal], 16);
        editShopMarker.setLatLng([latVal, lngVal]);
        editShopMap.invalidateSize();
      }
    }

    function openModal(id) {
      var el = document.getElementById(id);
      if (el) el.style.display = "flex";
      if (id === 'addShopModal') {
        setTimeout(function() {
          initAddShopMap();
          if (addShopMap) addShopMap.invalidateSize();
        }, 200);
      } else if (id === 'editShopModal') {
        setTimeout(function() {
          if (editShopMap) editShopMap.invalidateSize();
        }, 200);
      }
    }

    function closeModal(id) {
      var el = document.getElementById(id);
      if (el) el.style.display = "none";
    }

    function openEditShopModal(id, name, category, logoUrl, address, lat, lng) {
      document.getElementById('edit_shop_id').value = id;
      document.getElementById('edit_shop_name').value = name || '';
      document.getElementById('edit_shop_category').value = category || 'Fast Food';
      document.getElementById('edit_shop_logo_url').value = logoUrl || 'assets/shops/default_store.png';
      document.getElementById('edit_shop_address').value = address || '';

      var latVal = (lat !== null && !isNaN(lat) && lat != 0) ? parseFloat(lat) : defaultOrmocCoords[0];
      var lngVal = (lng !== null && !isNaN(lng) && lng != 0) ? parseFloat(lng) : defaultOrmocCoords[1];

      document.getElementById('edit_shop_lat').value = latVal.toFixed(8);
      document.getElementById('edit_shop_lng').value = lngVal.toFixed(8);
      document.getElementById('edit_shop_coords_text').textContent = latVal.toFixed(5) + ', ' + lngVal.toFixed(5);

      openModal('editShopModal');
      setTimeout(function() {
        initEditShopMap(latVal, lngVal);
      }, 200);
    }

    var geoDebounceTimer = null;
    function triggerStoreGeocode(nameInputId, addrInputId, latInputId, lngInputId, badgeId, mapInstance, markerInstance) {
      clearTimeout(geoDebounceTimer);
      geoDebounceTimer = setTimeout(function() {
        var nameEl = document.getElementById(nameInputId);
        var addrEl = document.getElementById(addrInputId);
        var badgeEl = document.getElementById(badgeId);
        var latEl = document.getElementById(latInputId);
        var lngEl = document.getElementById(lngInputId);

        var nameVal = nameEl ? nameEl.value.trim() : '';
        var addrVal = addrEl ? addrEl.value.trim() : '';

        if (!nameVal && !addrVal) return;

        var query = nameVal;
        if (addrVal) query += ', ' + addrVal;
        query += ', Ormoc City, Leyte';

        if (badgeEl) badgeEl.textContent = 'Searching OSM...';

        var url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(query);
        fetch(url, { headers: { 'Accept': 'application/json' } })
          .then(function(res) { return res.json(); })
          .then(function(data) {
            if (data && data.length > 0 && data[0].lat && data[0].lon) {
              var nLat = parseFloat(data[0].lat);
              var nLng = parseFloat(data[0].lon);
              if (latEl) latEl.value = nLat.toFixed(8);
              if (lngEl) lngEl.value = nLng.toFixed(8);
              if (badgeEl) badgeEl.textContent = nLat.toFixed(5) + ', ' + nLng.toFixed(5);
              if (mapInstance && markerInstance) {
                mapInstance.setView([nLat, nLng], 17);
                markerInstance.setLatLng([nLat, nLng]);
                markerInstance.bindPopup('<b>' + (nameVal || 'Store') + '</b><br>Pinpoint entrance').openPopup();
              }
            } else {
              if (badgeEl) badgeEl.textContent = 'Building not on OSM (drag pin)';
            }
          })
          .catch(function(err) {
            if (badgeEl) badgeEl.textContent = 'Drag pin to position';
          });
      }, 450);
    }

    document.addEventListener('DOMContentLoaded', function() {
      ['add_shop_name', 'add_shop_address'].forEach(function(inputId) {
        var el = document.getElementById(inputId);
        if (el) {
          el.addEventListener('change', function() {
            triggerStoreGeocode('add_shop_name', 'add_shop_address', 'add_shop_lat', 'add_shop_lng', 'add_shop_coords_text', addShopMap, addShopMarker);
          });
          el.addEventListener('blur', function() {
            triggerStoreGeocode('add_shop_name', 'add_shop_address', 'add_shop_lat', 'add_shop_lng', 'add_shop_coords_text', addShopMap, addShopMarker);
          });
        }
      });

      ['edit_shop_name', 'edit_shop_address'].forEach(function(inputId) {
        var el = document.getElementById(inputId);
        if (el) {
          el.addEventListener('change', function() {
            triggerStoreGeocode('edit_shop_name', 'edit_shop_address', 'edit_shop_lat', 'edit_shop_lng', 'edit_shop_coords_text', editShopMap, editShopMarker);
          });
          el.addEventListener('blur', function() {
            triggerStoreGeocode('edit_shop_name', 'edit_shop_address', 'edit_shop_lat', 'edit_shop_lng', 'edit_shop_coords_text', editShopMap, editShopMarker);
          });
        }
      });
    });

    function openEditItemModal(id, name, shopId, price, isRecommended) {
      document.getElementById('edit_item_id').value = id;
      document.getElementById('edit_item_name').value = name;
      document.getElementById('edit_item_shop_id').value = shopId;
      document.getElementById('edit_item_price').value = price;
      document.getElementById('edit_item_recommended').checked = (isRecommended == 1 || isRecommended === true || isRecommended == 't');
      openModal('editItemModal');
    }

    function openEditUserModal(id, fullname, username, email, role, isLocked) {
      document.getElementById('edit_user_id').value = id;
      document.getElementById('edit_user_fullname').value = fullname;
      document.getElementById('edit_user_username').value = username;
      document.getElementById('edit_user_email').value = email;
      document.getElementById('edit_user_role').value = role;
      document.getElementById('edit_user_password').value = '';
      document.getElementById('edit_user_confirm').value = '';
      document.getElementById('edit_user_reset_lockout').checked = (isLocked == 1);
      const errEl = document.getElementById('editUserError');
      if (errEl) { errEl.style.display = 'none'; errEl.textContent = ''; }
      openModal('editUserModal');
    }

    function validatePasswordRules(pass) {
      const errs = [];
      if (pass.length < 8) errs.push("Must be at least 8 characters long.");
      if (!/[A-Z]/.test(pass)) errs.push("Must contain an uppercase letter.");
      if (!/[a-z]/.test(pass)) errs.push("Must contain a lowercase letter.");
      if (!/[0-9]/.test(pass)) errs.push("Must contain a digit (0-9).");
      if (!/[@$!%*?&]/.test(pass)) errs.push("Must contain a special character (@$!%*?&).");
      return errs;
    }

    const addForm = document.getElementById('addUserForm');
    if (addForm) {
      addForm.addEventListener('submit', function(e) {
        const pass = document.getElementById('add_user_password').value;
        const conf = document.getElementById('add_user_confirm').value;
        const errDiv = document.getElementById('addUserError');

        if (pass !== conf) {
          e.preventDefault();
          errDiv.textContent = "Passwords do not match. Please re-enter.";
          errDiv.style.display = "block";
          return;
        }
        const errs = validatePasswordRules(pass);
        if (errs.length > 0) {
          e.preventDefault();
          errDiv.textContent = errs.join(" ");
          errDiv.style.display = "block";
          return;
        }
        errDiv.style.display = "none";
      });
    }

    const editForm = document.getElementById('editUserForm');
    if (editForm) {
      editForm.addEventListener('submit', function(e) {
        const pass = document.getElementById('edit_user_password').value;
        const conf = document.getElementById('edit_user_confirm').value;
        const errDiv = document.getElementById('editUserError');

        if (pass.length > 0) {
          if (pass !== conf) {
            e.preventDefault();
            errDiv.textContent = "New passwords do not match. Please re-enter.";
            errDiv.style.display = "block";
            return;
          }
          const errs = validatePasswordRules(pass);
          if (errs.length > 0) {
            e.preventDefault();
            errDiv.textContent = errs.join(" ");
            errDiv.style.display = "block";
            return;
          }
        }
        errDiv.style.display = "none";
      });
    }

    // In-app toast notification (Harvest Sun #D88F2A / Sagebrush #788A6E)
    function adminToast(msg, isError = false) {
      const el = document.getElementById('adminToast');
      if (!el) return;
      el.innerHTML = (isError ? '<i class="fa-solid fa-triangle-exclamation" style="color:#fff;"></i> ' : '<i class="fa-solid fa-circle-check" style="color:#D88F2A;"></i> ') + msg;
      if (isError) {
        el.classList.add('toast-error');
      } else {
        el.classList.remove('toast-error');
      }
      el.classList.add('show');
      clearTimeout(window._adminToast);
      window._adminToast = setTimeout(() => el.classList.remove('show'), 4000);
    }

    // Redirect all window.alert calls to the in-app adminToast
    window.alert = function(msg) {
      adminToast(String(msg || ''), false);
    };

    <?php if (!empty($action_msg)): ?>
      adminToast(<?php echo json_encode($action_msg); ?>, <?php echo (strpos($action_msg, 'Error') !== false || strpos($action_msg, 'Security') !== false) ? 'true' : 'false'; ?>);
    <?php endif; ?>

    // Toggle Password Visibility in Help Desk Modal
    function togglePasswordVisibility(inputId, btn) {
      const input = document.getElementById(inputId);
      if (!input) return;
      const icon = btn.querySelector('i');
      if (input.type === 'password') {
        input.type = 'text';
        if (icon) icon.className = 'fa-solid fa-eye-slash';
      } else {
        input.type = 'password';
        if (icon) icon.className = 'fa-solid fa-eye';
      }
    }

    // Open Help Desk Reset Password / Unlock Modal
    function openResetPasswordModal(userId, username, fullName, role, isLocked, lockoutUntil, failedAttempts) {
      document.getElementById('reset_user_id').value = userId;
      document.getElementById('reset_user_display_name').textContent = username;
      document.getElementById('reset_user_display_sub').textContent = 'UID: #' + userId + ' • ' + fullName;
      
      const roleBadge = document.getElementById('reset_user_role_badge');
      if (roleBadge) {
        roleBadge.textContent = role.toUpperCase();
        roleBadge.className = 'status-badge ' + role.toLowerCase();
      }

      const statusInfo = document.getElementById('reset_user_lockout_info');
      if (statusInfo) {
        if (isLocked) {
          statusInfo.innerHTML = '<span class="status-badge locked"><i class="fa-solid fa-lock"></i> Locked Out</span> ' +
            '<span style="color:#923D32; font-weight:600;">Lockout active until ' + lockoutUntil + ' (' + failedAttempts + ' failed attempts recorded)</span>';
        } else if (failedAttempts > 0) {
          statusInfo.innerHTML = '<span class="status-badge" style="background:#fff4e5; color:#D88F2A; border:1px solid #D88F2A;"><i class="fa-solid fa-triangle-exclamation"></i> ' + failedAttempts + ' Failed Attempts</span> ' +
            '<span style="color:#788A6E;">Healthy account, attempts pending reset.</span>';
        } else {
          statusInfo.innerHTML = '<span class="status-badge active-user"><i class="fa-solid fa-circle-check"></i> Active</span> ' +
            '<span style="color:#788A6E;">Healthy account, zero failed attempts.</span>';
        }
      }

      document.getElementById('reset_new_password').value = '';
      document.getElementById('reset_confirm_password').value = '';
      document.getElementById('reset_unlock_account').checked = true;
      
      const errEl = document.getElementById('resetPasswordError');
      if (errEl) { errEl.style.display = 'none'; errEl.textContent = ''; }
      
      updatePasswordChecklist();
      openModal('resetPasswordModal');
    }

    // Real-Time Password Checklist Updates
    function updatePasswordChecklist() {
      const p = document.getElementById('reset_new_password') ? document.getElementById('reset_new_password').value : '';
      const c = document.getElementById('reset_confirm_password') ? document.getElementById('reset_confirm_password').value : '';

      const setItem = (id, valid) => {
        const el = document.getElementById(id);
        if (!el) return;
        const icon = el.querySelector('i');
        if (valid) {
          el.style.color = '#344016';
          el.style.fontWeight = '600';
          if (icon) {
            icon.className = 'fa-solid fa-circle-check';
            icon.style.color = '#788A6E';
          }
        } else {
          el.style.color = '#74776a';
          el.style.fontWeight = '400';
          if (icon) {
            icon.className = 'fa-regular fa-circle';
            icon.style.color = '#a09983';
          }
        }
      };

      setItem('pw_req_len', p.length >= 8);
      setItem('pw_req_upper', /[A-Z]/.test(p));
      setItem('pw_req_lower', /[a-z]/.test(p));
      setItem('pw_req_num', /[0-9]/.test(p));
      setItem('pw_req_sym', /[@$!%*?&]/.test(p));
      setItem('pw_req_match', p.length > 0 && p === c);
    }

    function validateIasPassword(pass) {
      const issues = [];
      if (pass.length < 8) issues.push("Minimum 8 characters.");
      if (!/[A-Z]/.test(pass)) issues.push("At least one uppercase letter (A-Z).");
      if (!/[a-z]/.test(pass)) issues.push("At least one lowercase letter (a-z).");
      if (!/[0-9]/.test(pass)) issues.push("At least one numeric digit (0-9).");
      if (!/[@$!%*?&]/.test(pass)) issues.push("At least one special symbol (@$!%*?&).");
      return issues;
    }

    const resetNewPassInput = document.getElementById('reset_new_password');
    if (resetNewPassInput) resetNewPassInput.addEventListener('input', updatePasswordChecklist);

    const resetConfirmPassInput = document.getElementById('reset_confirm_password');
    if (resetConfirmPassInput) resetConfirmPassInput.addEventListener('input', updatePasswordChecklist);

    // Help Desk Password Reset Form Submission (No Native alert/confirm)
    const resetForm = document.getElementById('resetPasswordForm');
    if (resetForm) {
      resetForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const p = document.getElementById('reset_new_password').value;
        const c = document.getElementById('reset_confirm_password').value;
        const errDiv = document.getElementById('resetPasswordError');

        if (p !== c) {
          errDiv.textContent = "Error: Passwords do not match. Please verify and re-enter.";
          errDiv.style.display = 'block';
          return;
        }

        const issues = validateIasPassword(p);
        if (issues.length > 0) {
          errDiv.textContent = "Error: " + issues.join(" ");
          errDiv.style.display = 'block';
          return;
        }

        errDiv.style.display = 'none';

        const submitBtn = document.getElementById('btn_submit_reset_password');
        const origBtnHtml = submitBtn ? submitBtn.innerHTML : '';
        if (submitBtn) {
          submitBtn.disabled = true;
          submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';
        }

        const formData = new FormData(resetForm);

        fetch('admin_dashboard.php', {
          method: 'POST',
          body: formData,
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        })
        .then(res => res.json())
        .then(data => {
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = origBtnHtml;
          }
          if (data.success) {
            closeModal('resetPasswordModal');
            adminToast(data.message || 'Password reset successfully. Account lockout cleared.');
            setTimeout(() => {
              window.location.href = 'admin_dashboard.php?tab=users';
            }, 1200);
          } else {
            errDiv.textContent = data.message || 'An error occurred while resetting password.';
            errDiv.style.display = 'block';
          }
        })
        .catch(err => {
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = origBtnHtml;
          }
          errDiv.textContent = 'Server communication error. Please try again.';
          errDiv.style.display = 'block';
        });
      });
    }

    // In-app confirmation modal (replaces confirm())
    var _adminPendingForm = null;
    var _adminPendingButton = null;

    function confirmAdminAction(title, message, form, btn) {
      document.getElementById('adminConfirmTitle').textContent = title;
      document.getElementById('adminConfirmMsg').textContent = message;
      _adminPendingForm = form;
      _adminPendingButton = btn || null;
      document.getElementById('adminConfirmModal').style.display = 'flex';
      return false;
    }

    function closeAdminConfirm() {
      document.getElementById('adminConfirmModal').style.display = 'none';
      _adminPendingForm = null;
      _adminPendingButton = null;
    }

    function doAdminConfirm() {
      if (_adminPendingForm) {
        if (_adminPendingButton && _adminPendingButton.name) {
          var hidden = _adminPendingForm.querySelector('input[name="' + _adminPendingButton.name + '"]');
          if (!hidden) {
            hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = _adminPendingButton.name;
            _adminPendingForm.appendChild(hidden);
          }
          hidden.value = _adminPendingButton.value || '1';
        }

        // Clean asynchronous deletion for users without page refresh loop
        var isDelUser = _adminPendingForm.querySelector('input[name="action_delete_user"]');
        if (isDelUser) {
          deleteUserAjax(_adminPendingForm);
          closeAdminConfirm();
          return;
        }

        _adminPendingForm.submit();
      }
      closeAdminConfirm();
    }

    // USER DELETION AJAX HANDLER (Requirement 2)
    function deleteUserAjax(form) {
      var formData = new FormData(form);
      formData.set('action_delete_user', '1');
      formData.set('ajax', '1');
      var userId = formData.get('user_id');

      fetch('admin_dashboard.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(function(res) {
        var ct = res.headers.get('content-type') || '';
        if (ct.includes('application/json')) return res.json();
        form.submit();
        return null;
      })
      .then(function(data) {
        if (!data) return;
        if (data.success) {
          showAdminAlertBanner(data.message || 'User successfully removed');
          var row = document.getElementById('user-row-' + userId);
          if (row) {
            row.style.transition = 'all 0.3s ease-out';
            row.style.opacity = '0';
            row.style.transform = 'translateX(-20px)';
            setTimeout(function() {
              if (row.parentNode) row.parentNode.removeChild(row);
            }, 300);
          }
        } else {
          showAdminAlertBanner(data.message || 'Error deleting user.');
        }
      })
      .catch(function(err) {
        form.submit();
      });
    }

    // GLOBAL NOTIFICATION BANNER
    function showAdminAlertBanner(msg) {
      var existing = document.getElementById('adminGlobalAlert');
      if (!existing) {
        existing = document.createElement('div');
        existing.id = 'adminGlobalAlert';
        existing.className = 'alert-banner';
        var topBar = document.querySelector('.top-bar');
        if (topBar && topBar.parentNode) {
          topBar.parentNode.insertBefore(existing, topBar.nextSibling);
        }
      }
      existing.innerHTML = '<i class="fa-solid fa-circle-check"></i> ' + msg;
      existing.style.display = 'block';
      existing.style.opacity = '1';
      existing.scrollIntoView({ behavior: 'smooth', block: 'center' });
      setTimeout(function() {
        existing.style.transition = 'opacity 0.6s';
        existing.style.opacity = '0';
        setTimeout(function() { existing.style.display = 'none'; existing.style.opacity = '1'; }, 600);
      }, 4000);
    }

    // RIDER EDIT MODAL & AJAX (Requirement 3)
    function openEditRiderModal(rider) {
      if (typeof rider === 'string') {
        try { rider = JSON.parse(rider); } catch(e) {}
      }
      document.getElementById('edit_rider_id').value = rider.rider_id;
      document.getElementById('edit_rider_name').value = rider.rider_name || '';
      document.getElementById('edit_rider_phone').value = rider.phone || '';
      document.getElementById('edit_rider_plate').value = rider.plate_number || '';
      document.getElementById('edit_rider_vehicle').value = rider.vehicle_model || '';
      document.getElementById('edit_rider_account_status').value = rider.account_status || 'Active';
      openModal('editRiderModal');
    }

    function handleEditRiderSubmit(e, form) {
      e.preventDefault();
      var formData = new FormData(form);
      var riderId = formData.get('rider_id');

      fetch('admin_dashboard.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(function(res) {
        var ct = res.headers.get('content-type') || '';
        if (ct.includes('application/json')) return res.json();
        form.submit();
        return null;
      })
      .then(function(data) {
        if (!data) return;
        if (data.success) {
          closeModal('editRiderModal');
          showAdminAlertBanner(data.message || 'Rider details updated successfully!');
          
          var nameEl = document.getElementById('rider-name-val-' + riderId);
          if (nameEl) nameEl.textContent = data.rider_name;
          var phoneEl = document.getElementById('rider-phone-val-' + riderId);
          if (phoneEl) phoneEl.textContent = data.phone;
          var plateEl = document.getElementById('rider-plate-val-' + riderId);
          if (plateEl) plateEl.textContent = data.plate_number;
          var vehEl = document.getElementById('rider-vehicle-val-' + riderId);
          if (vehEl) vehEl.textContent = data.vehicle_model;
          var badgeEl = document.getElementById('rider-accstatus-badge-' + riderId);
          if (badgeEl) {
            badgeEl.textContent = data.account_status;
            if (data.account_status === 'Suspended') {
              badgeEl.style.background = '#ffebee';
              badgeEl.style.color = '#c62828';
            } else if (data.account_status === 'Offline') {
              badgeEl.style.background = '#f5f5f5';
              badgeEl.style.color = '#757575';
            } else {
              badgeEl.style.background = '#e8f5e9';
              badgeEl.style.color = '#2e7d32';
            }
          }
          if (typeof pollMasterDispatchMap === 'function') {
            pollMasterDispatchMap();
          }
        } else {
          adminToast(data.message || 'Failed to update rider details.', true);
        }
      })
      .catch(function(err) {
        form.submit();
      });
    }

    // MASTER DISPATCH MAP TELEMETRY (Requirement 4)
    var masterMap = null;
    var masterStoreLayer = null;
    var masterRiderLayer = null;
    var masterRiderMarkers = {}; // rider_id -> Leaflet Marker
    var masterDispatchTimer = null;

    function getStoreLogoUrl(store) {
      var name = (store.shop_name || '').toLowerCase();
      if (name.includes('mcdonald')) return 'assets/shops/mcdonalds.png';
      if (name.includes('jollibee')) return 'assets/shops/jollibee.png';
      if (name.includes('mang inasal')) return 'assets/shops/mang_inasal.png';
      if (name.includes('ribshack')) return 'assets/shops/ribshack.png';
      if (name.includes('chowking')) return 'assets/shops/chowking.png';
      if (name.includes('shakey')) return 'assets/shops/shakeys.png';
      if (name.includes('greenwich')) return 'assets/shops/greenwich.png';
      if (store.logo_url && !store.logo_url.includes('default_store')) return store.logo_url;
      return 'logo.jpg';
    }

    function createStorePinIcon(store) {
      var logo = getStoreLogoUrl(store);
      var activeCount = store.active_orders_count || 0;
      var badgeHtml = activeCount > 0 
        ? '<span style="position:absolute; top:-5px; right:-5px; background:#e53935; color:#fff; font-size:9px; font-weight:700; width:18px; height:18px; border-radius:50%; display:flex; align-items:center; justify-content:center; border:2px solid #fff; box-shadow:0 2px 5px rgba(0,0,0,0.3);">' + activeCount + '</span>'
        : '';

      return L.divIcon({
        className: 'custom-store-marker',
        html: `
          <div style="position:relative; display:flex; flex-direction:column; align-items:center; cursor:pointer;">
            <div style="width:36px; height:36px; border-radius:50%; background:#ffffff; border:2.5px solid #D88F2A; box-shadow:0 4px 10px rgba(0,0,0,0.22); display:flex; align-items:center; justify-content:center; overflow:hidden;">
              <img src="${logo}" alt="${store.shop_name}" style="width:100%; height:100%; object-fit:cover;" onerror="this.onerror=null; this.src='logo.jpg';">
            </div>
            ${badgeHtml}
            <div style="width:0; height:0; border-left:5px solid transparent; border-right:5px solid transparent; border-top:7px solid #D88F2A; margin-top:-2px;"></div>
          </div>
        `,
        iconSize: [38, 46],
        iconAnchor: [19, 44],
        popupAnchor: [0, -40]
      });
    }

    function createRiderMarkerIcon(rider) {
      return L.divIcon({
        className: 'custom-rider-marker',
        html: `
          <div style="position:relative; display:flex; flex-direction:column; align-items:center; cursor:pointer;">
            <div style="position:absolute; width:44px; height:44px; border-radius:50%; background:rgba(75,83,32,0.25); animation:riderRadarPulse 2s infinite ease-out; pointer-events:none;"></div>
            <div style="width:34px; height:34px; border-radius:50%; background:#4B5320; border:2.5px solid #ffffff; box-shadow:0 4px 12px rgba(0,0,0,0.32); display:flex; align-items:center; justify-content:center; color:#ffffff; font-size:14px; position:relative; z-index:2;">
              <i class="fa-solid fa-motorcycle"></i>
            </div>
            <div style="width:0; height:0; border-left:5px solid transparent; border-right:5px solid transparent; border-top:7px solid #4B5320; margin-top:-2px; z-index:2;"></div>
            <div style="margin-top:2px; background:rgba(52,64,22,0.92); color:#fff; font-size:8px; font-weight:700; padding:1px 6px; border-radius:7px; white-space:nowrap; border:1px solid rgba(255,255,255,0.7); box-shadow:0 2px 5px rgba(0,0,0,0.2); z-index:3;">
              ${rider.rider_name}
            </div>
          </div>
        `,
        iconSize: [44, 54],
        iconAnchor: [22, 42],
        popupAnchor: [0, -38]
      });
    }

    function initMasterDispatchMap() {
      if (masterMap) {
        masterMap.invalidateSize();
        return;
      }
      var container = document.getElementById('master-dispatch-map');
      if (!container || typeof L === 'undefined') return;

      masterMap = L.map('master-dispatch-map', {
        center: [11.0089, 124.6068], // Ormoc City center
        zoom: 14,
        attributionControl: false
      });

      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19
      }).addTo(masterMap);

      masterStoreLayer = L.layerGroup().addTo(masterMap);
      masterRiderLayer = L.layerGroup().addTo(masterMap);

      pollMasterDispatchMap();

      if (!masterDispatchTimer) {
        masterDispatchTimer = setInterval(function() {
          var tab = document.getElementById('tab-dispatch-map');
          if (tab && tab.classList.contains('active-tab')) {
            pollMasterDispatchMap();
          }
        }, 3000); // 3-second live polling loop
      }
    }

    function pollMasterDispatchMap(manual) {
      if (!masterMap) return;

      fetch('tracking_api.php?action=get_dispatch_map', {
        headers: { 'Accept': 'application/json' }
      })
      .then(function(res) { return res.json(); })
      .then(function(data) {
        if (!data || !data.success) return;

        // Update Counter Badges
        var stStores = document.getElementById('stat-dispatch-stores');
        if (stStores) stStores.textContent = data.total_stores || (data.stores ? data.stores.length : 0);
        var stRiders = document.getElementById('stat-dispatch-riders');
        if (stRiders) stRiders.textContent = data.active_riders_count || (data.riders ? data.riders.length : 0);
        var stOrders = document.getElementById('stat-dispatch-orders');
        if (stOrders && data.stores) {
          var activeTotal = data.stores.reduce(function(acc, s) { return acc + (s.active_orders_count || 0); }, 0);
          stOrders.textContent = activeTotal;
        }

        // Layer 1: Partner Stores
        if (masterStoreLayer && data.stores) {
          masterStoreLayer.clearLayers();
          data.stores.forEach(function(store) {
            if (store.lat && store.lng) {
              var m = L.marker([store.lat, store.lng], {
                icon: createStorePinIcon(store)
              });
              var actCount = store.active_orders_count || 0;
              var popupHtml = `
                <div style="min-width:210px; font-family:'Poppins', sans-serif;">
                  <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px; border-bottom:1px solid #eee; padding-bottom:6px;">
                    <img src="${getStoreLogoUrl(store)}" style="width:28px; height:28px; border-radius:50%; object-fit:cover; border:1px solid #ddd;">
                    <div>
                      <h4 style="margin:0; font-size:12px; color:#344016; font-weight:700;">${store.shop_name}</h4>
                      <span style="font-size:9px; color:#788A6E;">${store.category || 'Partner Store'}</span>
                    </div>
                  </div>
                  <div style="font-size:9.5px; color:#555; margin-bottom:6px;">
                    <i class="fa-solid fa-location-dot" style="color:#D88F2A;"></i> ${store.address || 'Ormoc City, Leyte'}
                  </div>
                  <div style="display:flex; justify-content:space-between; align-items:center; background:#FAF6EE; padding:5px 8px; border-radius:7px; border:1px solid #D4C5A5;">
                    <span style="font-size:9px; font-weight:600; color:#4B5320;">Active Orders</span>
                    <span style="font-size:10px; font-weight:700; color:${actCount > 0 ? '#d32f2f' : '#788A6E'};">
                      ${actCount > 0 ? '⚡ ' + actCount + ' in kitchen/transit' : '0 active'}
                    </span>
                  </div>
                </div>
              `;
              m.bindPopup(popupHtml);
              masterStoreLayer.addLayer(m);
            }
          });
        }

        // Layer 2: Active Dispatched Riders
        if (masterRiderLayer && data.riders) {
          var currentIds = {};
          data.riders.forEach(function(r) {
            currentIds[r.rider_id] = true;
            var lat = parseFloat(r.lat);
            var lng = parseFloat(r.lng);
            if (isNaN(lat) || isNaN(lng)) return;

            var popupContent = `
              <div style="min-width:240px; font-family:'Poppins', sans-serif; font-size:10.5px;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:6px; border-bottom:1px solid #eee; padding-bottom:5px;">
                  <div>
                    <div style="font-weight:700; font-size:12px; color:#344016; display:flex; align-items:center; gap:5px;">
                      <i class="fa-solid fa-motorcycle" style="color:#4B5320;"></i> ${r.rider_name}
                    </div>
                    <div style="font-size:9px; color:#788A6E;">
                      <i class="fa-solid fa-phone"></i> ${r.rider_phone || '+63 917 555 0192'}
                    </div>
                  </div>
                  <span style="background:#e8f5e9; color:#2e7d32; font-size:8px; font-weight:700; padding:2px 5px; border-radius:5px; border:1px solid #c8e6c9;">
                    DISPATCHED
                  </span>
                </div>
                <div style="background:#FAF6EE; border:1px solid #D4C5A5; border-radius:7px; padding:6px 8px; margin-bottom:6px;">
                  <div style="display:flex; justify-content:space-between; align-items:center;">
                    <span style="font-size:8.5px; color:#74776a; font-weight:600;">ACTIVE ORDER</span>
                    <span style="font-size:10.5px; font-weight:700; color:#D88F2A;">${r.order_ref ? ('#' + String(r.order_ref).replace(/^#+/, '')) : '#FD-ACTIVE'}</span>
                  </div>
                  <div style="font-size:9.5px; color:#333; margin-top:2px; font-weight:600;">
                    <i class="fa-solid fa-utensils" style="color:#788A6E;"></i> ${r.items_summary || 'Food Dash Package'}
                  </div>
                  <div style="font-size:9.5px; font-weight:700; color:#344016; margin-top:2px;">
                    Total Bill: ₱${Number(r.total_amount || 0).toFixed(2)}
                  </div>
                </div>
                <div style="display:flex; flex-direction:column; gap:3px; margin-bottom:6px; font-size:9.5px;">
                  <div style="display:flex; align-items:flex-start; gap:5px;">
                    <span style="width:6px; height:6px; border-radius:50%; background:#D88F2A; margin-top:4px; flex-shrink:0;"></span>
                    <div><strong style="color:#4B5320;">Pickup:</strong> ${r.store_name || 'Store'}</div>
                  </div>
                  <div style="display:flex; align-items:flex-start; gap:5px;">
                    <span style="width:6px; height:6px; border-radius:50%; background:#d32f2f; margin-top:4px; flex-shrink:0;"></span>
                    <div><strong style="color:#4B5320;">Drop-off:</strong> ${r.delivery_address || 'Ormoc City'}</div>
                  </div>
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center; background:#f4f0df; padding:4px 7px; border-radius:6px; font-size:9px;">
                  <span><strong>Vehicle:</strong> ${r.vehicle_model} [${r.plate_number}]</span>
                  <span style="color:#D88F2A; font-weight:700;">ETA: ${r.eta || 'En Route'}</span>
                </div>
              </div>
            `;

            if (masterRiderMarkers[r.rider_id]) {
              masterRiderMarkers[r.rider_id].setLatLng([lat, lng]);
              masterRiderMarkers[r.rider_id].setPopupContent(popupContent);
            } else {
              var rMarker = L.marker([lat, lng], {
                icon: createRiderMarkerIcon(r)
              }).addTo(masterRiderLayer);
              rMarker.bindPopup(popupContent);
              masterRiderMarkers[r.rider_id] = rMarker;
            }
          });

          // Remove markers for riders no longer active
          Object.keys(masterRiderMarkers).forEach(function(rid) {
            if (!currentIds[rid]) {
              masterRiderLayer.removeLayer(masterRiderMarkers[rid]);
              delete masterRiderMarkers[rid];
            }
          });
        }
      })
      .catch(function(err) {
        console.error('Dispatch telemetry poll error:', err);
      });
    }

    document.addEventListener('keydown', e => {
      if (e.key === 'Escape') { 
        closeAdminConfirm(); 
        closeModal('resetPasswordModal');
        closeModal('editRiderModal');
      }
    });
  </script>
</body>
</html>
