<?php
session_start();
require_once 'db_connect.php';
require_once 'notification_lib.php';

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'rider') {
    header('Location: index.php'); exit();
}

$user_id = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? $_SESSION['user']['username'] ?? $_SESSION['full_name'] ?? 'Rider';
$user_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? $_SESSION['user']['username'] ?? 'Rider';
$msg = ''; $error = '';

// Match the rider account to the existing riders table by name, preserving the original schema.
$full = trim($user_name);
$stmt = $conn->prepare("SELECT rider_id, rider_name, status, current_order FROM riders WHERE rider_name = :rname LIMIT 1");
$stmt->execute([':rname' => $full]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$r) {
    $q = $conn->query("SELECT rider_id, rider_name, status, current_order FROM riders ORDER BY rider_id ASC LIMIT 1");
    $r = $q ? $q->fetch(PDO::FETCH_ASSOC) : null;
}
$rid = $r ? (int)$r['rider_id'] : 0;

// Delivery actions: claim a ready order, start the trip, mark near, then complete.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $rid > 0) {
    // Check if this is an AJAX request
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;
    
    $is_ajax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($input['ajax']) && $input['ajax'] === 'true');
    
    $action = $input['delivery_action'] ?? '';
    $order_id = (int)($input['order_id'] ?? 0);
    
    if ($order_id > 0) {
        $stmt = $conn->prepare("SELECT order_id, customer_id, customer_name, order_ref, status, delivery_status, rider_id, total_amount, delivery_address, delivery_lat, delivery_lng, customer_lat, customer_lng FROM food_orders WHERE order_id = :oid LIMIT 1");
        $stmt->execute([':oid' => $order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($is_ajax) {
            try {
                if (!$order) throw new Exception('Order not found');
                $customer_id = (int)$order['customer_id'];
                $ref = $order['order_ref'] ? $order['order_ref'] : '#' . $order['order_id'];
                $old_st = strtoupper(trim($order['status'] ?? ''));

                if ($action === 'claim') {
                    if (!in_array($old_st, ['READY_FOR_PICKUP', 'READY']) || (!empty($order['rider_id']) && (int)$order['rider_id'] !== 0)) {
                        throw new Exception('This order is not ready for pickup or has already been assigned.');
                    }
                    $stmt = $conn->prepare("UPDATE food_orders SET rider_id = :rid, delivery_status = 'out_for_delivery', status = 'OUT_FOR_DELIVERY', updated_at = NOW() WHERE order_id = :oid AND UPPER(status) IN ('READY_FOR_PICKUP', 'READY') AND (rider_id IS NULL OR rider_id = 0)");
                    $stmt->execute([':rid' => $rid, ':oid' => $order_id]);
                    $changed = $stmt->rowCount();

                    if ($changed) {
                        $stmt = $conn->prepare("UPDATE riders SET status = 'on_delivery', current_order = :ref WHERE rider_id = :rid");
                        $stmt->execute([':ref' => $ref, ':rid' => $rid]);

                        record_order_audit_log($conn, $order_id, $old_st, 'OUT_FOR_DELIVERY', $user_id, 'rider', 'Order claimed by rider ' . $user_name);
                        create_notification($conn, $customer_id, 'rider_assigned', 'Order Out for Delivery', 'Rider ' . $user_name . ' has picked up your order ' . $ref . ' and is on the way!', $order_id);
                        notify_role($conn, 'admin', 'Rider Dispatched', 'Rider ' . $user_name . ' claimed and dispatched order ' . $ref, $order_id);

                        if (ob_get_level()) ob_clean();
                        header('Content-Type: application/json');
                        echo json_encode(['success' => true, 'message' => 'Delivery accepted. Order is now OUT FOR DELIVERY.', 'order_id' => $order_id]);
                        exit();
                    } else {
                        throw new Exception('Order could not be claimed. Please refresh.');
                    }
                } elseif ($action === 'on_way') {
                    if ((int)($order['rider_id'] ?? 0) !== $rid) throw new Exception('You are not assigned to this order.');
                    $stmt = $conn->prepare("UPDATE food_orders SET delivery_status = 'on_the_way', updated_at = NOW() WHERE order_id = :oid AND rider_id = :rid");
                    $stmt->execute([':oid' => $order_id, ':rid' => $rid]);

                    record_order_audit_log($conn, $order_id, 'OUT_FOR_DELIVERY', 'OUT_FOR_DELIVERY', $user_id, 'rider', 'Rider is en route to customer');
                    create_notification($conn, $customer_id, 'rider_on_way', 'Rider On the Way', 'Rider ' . $user_name . ' is actively heading towards your delivery address.', $order_id);

                    if (ob_get_level()) ob_clean();
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Customer notified that you are on the way.']);
                    exit();
                } elseif ($action === 'near') {
                    if ((int)($order['rider_id'] ?? 0) !== $rid) throw new Exception('You are not assigned to this order.');
                    $stmt = $conn->prepare("UPDATE food_orders SET delivery_status = 'near_customer', updated_at = NOW() WHERE order_id = :oid AND rider_id = :rid");
                    $stmt->execute([':oid' => $order_id, ':rid' => $rid]);

                    record_order_audit_log($conn, $order_id, 'OUT_FOR_DELIVERY', 'OUT_FOR_DELIVERY', $user_id, 'rider', 'Rider is near customer drop-off');
                    create_notification($conn, $customer_id, 'rider_near', 'Rider Arriving Soon', 'Your rider is near your delivery address. Please prepare to receive ' . $ref . '.', $order_id);

                    if (ob_get_level()) ob_clean();
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Customer notified that you are near.']);
                    exit();
                } elseif ($action === 'delivered') {
                    if ((int)($order['rider_id'] ?? 0) !== $rid) throw new Exception('You are not assigned to this order.');
                    $stmt = $conn->prepare("UPDATE food_orders SET delivery_status = 'delivered', status = 'DELIVERED', updated_at = NOW() WHERE order_id = :oid AND rider_id = :rid");
                    $stmt->execute([':oid' => $order_id, ':rid' => $rid]);

                    $stmt = $conn->prepare("UPDATE riders SET status = 'waiting', current_order = 'None' WHERE rider_id = :rid");
                    $stmt->execute([':rid' => $rid]);

                    record_order_audit_log($conn, $order_id, 'OUT_FOR_DELIVERY', 'DELIVERED', $user_id, 'rider', 'Order successfully delivered to customer by ' . $user_name);
                    create_notification($conn, $customer_id, 'delivered', 'Order Delivered!', 'Your order ' . $ref . ' has been marked delivered. Enjoy your meal!', $order_id);
                    notify_role($conn, 'admin', 'Order Delivered - Ready for Settlement', 'Order ' . $ref . ' delivered by ' . $user_name . '. Awaiting audit reconciliation.', $order_id);

                    if (ob_get_level()) ob_clean();
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Delivery completed! Customer and Admin notified.']);
                    exit();
                } else {
                    throw new Exception('Unknown delivery action.');
                }
            } catch (Exception $e) {
                if (ob_get_level()) ob_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit();
            }
        } else {
            // Non-AJAX fallback
            if (!$order) {
                $error = 'Order not found.';
            } else {
                $customer_id = (int)$order['customer_id'];
                $ref = $order['order_ref'] ? $order['order_ref'] : '#' . $order['order_id'];
                $old_st = strtoupper(trim($order['status'] ?? ''));

                if ($action === 'claim') {
                    if (in_array($old_st, ['READY_FOR_PICKUP', 'READY']) && (empty($order['rider_id']) || (int)$order['rider_id'] === 0)) {
                        $stmt = $conn->prepare("UPDATE food_orders SET rider_id = :rid, delivery_status = 'out_for_delivery', status = 'OUT_FOR_DELIVERY', updated_at = NOW() WHERE order_id = :oid");
                        $stmt->execute([':rid' => $rid, ':oid' => $order_id]);

                        $stmt = $conn->prepare("UPDATE riders SET status = 'on_delivery', current_order = :ref WHERE rider_id = :rid");
                        $stmt->execute([':ref' => $ref, ':rid' => $rid]);

                        record_order_audit_log($conn, $order_id, $old_st, 'OUT_FOR_DELIVERY', $user_id, 'rider', 'Order claimed by rider ' . $user_name);
                        create_notification($conn, $customer_id, 'rider_assigned', 'Order Out for Delivery', 'Rider ' . $user_name . ' has picked up your order ' . $ref . '.', $order_id);
                        $msg = 'Delivery accepted. Order is now OUT FOR DELIVERY.';
                    } else {
                        $error = 'Order is already assigned or not ready.';
                    }
                } elseif ($action === 'on_way' && (int)($order['rider_id'] ?? 0) === $rid) {
                    $stmt = $conn->prepare("UPDATE food_orders SET delivery_status = 'on_the_way', updated_at = NOW() WHERE order_id = :oid");
                    $stmt->execute([':oid' => $order_id]);

                    record_order_audit_log($conn, $order_id, 'OUT_FOR_DELIVERY', 'OUT_FOR_DELIVERY', $user_id, 'rider', 'Rider en route');
                    create_notification($conn, $customer_id, 'rider_on_way', 'Rider on the way', 'Your order ' . $ref . ' is on the way.', $order_id);
                    $msg = 'Customer notified that you are on the way.';
                } elseif ($action === 'near' && (int)($order['rider_id'] ?? 0) === $rid) {
                    $stmt = $conn->prepare("UPDATE food_orders SET delivery_status = 'near_customer', updated_at = NOW() WHERE order_id = :oid");
                    $stmt->execute([':oid' => $order_id]);

                    record_order_audit_log($conn, $order_id, 'OUT_FOR_DELIVERY', 'OUT_FOR_DELIVERY', $user_id, 'rider', 'Rider near customer');
                    create_notification($conn, $customer_id, 'rider_near', 'Rider near', 'Your rider is near.', $order_id);
                    $msg = 'Customer notified that you are near.';
                } elseif ($action === 'delivered' && (int)($order['rider_id'] ?? 0) === $rid) {
                    $stmt = $conn->prepare("UPDATE food_orders SET delivery_status = 'delivered', status = 'DELIVERED', updated_at = NOW() WHERE order_id = :oid");
                    $stmt->execute([':oid' => $order_id]);

                    $stmt = $conn->prepare("UPDATE riders SET status = 'waiting', current_order = 'None' WHERE rider_id = :rid");
                    $stmt->execute([':rid' => $rid]);

                    record_order_audit_log($conn, $order_id, 'OUT_FOR_DELIVERY', 'DELIVERED', $user_id, 'rider', 'Order delivered by ' . $user_name);
                    create_notification($conn, $customer_id, 'delivered', 'Order delivered', 'Your order ' . $ref . ' has been delivered.', $order_id);
                    notify_role($conn, 'admin', 'Order Delivered', 'Order ' . $ref . ' delivered by ' . $user_name, $order_id);
                    $msg = 'Delivery completed and customer notified.';
                }
            }
        }
    }

    // Preserve the original manual rider status control.
    if (isset($input['action_status'])) {
        $status = ($input['status'] ?? '') === 'on_delivery' ? 'on_delivery' : 'waiting';
        $current = trim($input['current_order'] ?? ''); if ($status==='waiting') $current='None';
        $stmt=$conn->prepare("UPDATE riders SET status = :st, current_order = :cur WHERE rider_id = :rid");
        $stmt->execute([':st' => $status, ':cur' => $current, ':rid' => $rid]);
        $msg='Delivery status updated.';
    }
}

if ($r) {
    $stmt=$conn->prepare("SELECT rider_id, rider_name, status, current_order FROM riders WHERE rider_id = :rid");
    $stmt->execute([':rid' => $rid]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
}

$ready_orders=[]; $my_orders=[];
if ($rid) {
    $stmt = $conn->prepare("SELECT order_id,order_ref,customer_name,store_name,items_summary,total_amount,status,delivery_status,rider_id,delivery_address,delivery_lat,delivery_lng,customer_lat,customer_lng,created_at FROM food_orders WHERE (UPPER(status) IN ('READY_FOR_PICKUP', 'READY') AND (rider_id IS NULL OR rider_id=0)) OR (rider_id = :rid AND UPPER(status) IN ('OUT_FOR_DELIVERY','ON_DELIVERY','ON_THE_WAY','NEAR_CUSTOMER')) ORDER BY order_id DESC LIMIT 30");
    $stmt->execute([':rid' => $rid]);
    while($o = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if((int)($o['rider_id']??0)===$rid) $my_orders[]=$o;
        else $ready_orders[]=$o;
    }
}
$total_riders = (int)($conn->query("SELECT COUNT(*) AS c FROM riders")->fetchColumn() ?: 0);
$waiting = (int)($conn->query("SELECT COUNT(*) AS c FROM riders WHERE status='waiting'")->fetchColumn() ?: 0);
$on_delivery = (int)($conn->query("SELECT COUNT(*) AS c FROM riders WHERE status='on_delivery'")->fetchColumn() ?: 0);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Food Dash - Rider</title><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"><style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
:root{
  --harvest-sun:#D88F2A;
  --sagebrush:#788A6E;
  --earth-clay:#923D32;
  --olive-shadow:#4B5320;
  --sandstone:#D4C5A5;
  --green:#4B5320;
  --green2:#788A6E;
  --cream:#f6f4e8;
  --gold:#D88F2A;
  --ink:#283510;
  --line:#e5e0cf;
  --white:#ffffff;
  --muted:#727568;
}
*{box-sizing:border-box}
html, body {
  min-height: 100vh;
  width: 100%;
  background:
    radial-gradient(circle at 15% 10%, rgba(216,143,42,.12), transparent 30%),
    radial-gradient(circle at 85% 90%, rgba(75,83,32,.13), transparent 35%),
    #f4f0df;
  font-family: 'Poppins', sans-serif;
  color: var(--ink);
  display: flex;
  justify-content: center;
  align-items: flex-start;
  margin: 0;
  padding: 20px 0;
}
.app {
  width: 100%;
  max-width: 440px;
  min-height: 100vh;
  margin: 0 auto;
  background: var(--white);
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
.hero{background:var(--olive-shadow);color:white;padding:20px 18px 23px;border-radius:0 0 25px 25px}
.brand{display:flex;align-items:center;gap:10px}
.brand img{width:42px;height:42px;border-radius:50%;object-fit:cover;border:2px solid white}
.brand b{letter-spacing:1px}
.topline{display:flex;justify-content:space-between;align-items:center}
.bell{position:relative;border:0;background:transparent;color:#fff;font-size:18px;cursor:pointer}
.bell-badge{position:absolute;top:-5px;right:-6px;background:var(--earth-clay);color:#fff;border-radius:9px;font-size:7px;padding:2px 5px;display:none}
.hello{margin-top:18px}
.hello small{opacity:.75}
.hello h1{margin:4px 0;font-size:1.28rem}
.status{display:inline-flex;align-items:center;gap:7px;background:#ffffff1b;padding:7px 10px;border-radius:99px;font-size:.7rem;margin-top:8px}
.dot{width:8px;height:8px;border-radius:50%;background:var(--sandstone)}
.dot.live{background:#a9d26a}
main{padding:16px;flex:1;overflow-x:hidden}
.alert{padding:11px 13px;background:#edf3d8;color:#43531b;border-radius:12px;margin-bottom:12px;font-size:.8rem}
.alert.err{background:#fff0ed;color:var(--earth-clay)}
.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:9px}
.stat{background:#fff;border:1px solid var(--line);border-radius:15px;padding:12px 8px;text-align:center}
.stat i{color:var(--harvest-sun)}
.stat strong{display:block;font-size:1.1rem;margin-top:4px}
.stat span{font-size:.6rem;color:var(--muted)}
.section{margin-top:18px}
.section-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.section-title h2{font-size:1rem;margin:0}
.card{background:#fff;border:1px solid var(--line);border-radius:17px;padding:14px;box-shadow:0 5px 18px rgba(75,83,32,0.06)}
.order{background:#f8f6f0;border:1px solid var(--sandstone);border-radius:14px;padding:13px;margin-bottom:10px}
.order:last-child{margin-bottom:0}
.order-ref{font-weight:700;font-size:.98rem;color:var(--olive-shadow)}
.meta{font-size:.7rem;color:#555;margin-top:3px;line-height:1.55}
.actions{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-top:10px}
.btn{border:0;border-radius:10px;padding:10px;background:var(--olive-shadow);color:#fff;font-family:inherit;font-size:.72rem;font-weight:700;cursor:pointer;transition:.2s}
.btn:hover{filter:brightness(1.1)}
.btn.secondary{background:#ece8d7;color:var(--olive-shadow)}
.btn.warn{background:var(--harvest-sun);color:#fff}
.btn.success{background:var(--sagebrush);color:#fff}
.btn.full{grid-column:1/-1}

/* Prominent Delivery Address Card & Google Maps Navigation */
.rider-address-card {
  background: #fdfbf7;
  border: 1.5px solid #d4c5a5;
  border-left: 4.5px solid var(--harvest-sun);
  border-radius: 12px;
  padding: 10px 12px;
  margin: 10px 0 8px;
  text-align: left;
  box-shadow: 0 2px 8px rgba(75, 83, 32, 0.05);
}
.rider-address-title {
  font-size: 0.65rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.6px;
  color: var(--olive-shadow);
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 4px;
}
.rider-address-title i {
  color: var(--harvest-sun);
  font-size: 0.8rem;
}
.rider-address-text {
  font-size: 0.84rem;
  font-weight: 600;
  color: var(--ink);
  line-height: 1.45;
  word-break: break-word;
}
.rider-gps-pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 0.65rem;
  color: var(--olive-shadow);
  background: rgba(120, 138, 110, 0.15);
  border: 1px solid rgba(120, 138, 110, 0.25);
  padding: 3px 8px;
  border-radius: 6px;
  margin-top: 6px;
  font-weight: 600;
}
.btn-maps {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  background: #1a73e8;
  color: #ffffff !important;
  text-decoration: none;
  border-radius: 10px;
  padding: 11px 14px;
  font-size: 0.76rem;
  font-weight: 700;
  letter-spacing: 0.3px;
  box-shadow: 0 4px 14px rgba(26, 115, 232, 0.28);
  transition: all 0.2s ease;
  width: 100%;
  box-sizing: border-box;
}
.btn-maps:hover {
  background: #1557b0;
  transform: translateY(-1px);
  box-shadow: 0 6px 18px rgba(26, 115, 232, 0.38);
}
.btn-maps i {
  font-size: 0.92rem;
}
.btn-maps-outline {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  background: #eef3fc;
  color: #1a73e8 !important;
  border: 1.5px solid #c2d7fa;
  text-decoration: none;
  border-radius: 10px;
  padding: 8px 14px;
  font-size: 0.72rem;
  font-weight: 700;
  transition: all 0.2s ease;
  white-space: nowrap;
}
.btn-maps-outline:hover {
  background: #1a73e8;
  color: #ffffff !important;
  border-color: #1a73e8;
}

.empty{font-size:.76rem;color:var(--muted);text-align:center;padding:18px}
.bottom {
  position: sticky;
  bottom: 0;
  width: 100%;
  height: 64px;
  background: rgba(255, 255, 255, 0.98);
  backdrop-filter: blur(8px);
  border-top: 1px solid var(--line);
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  z-index: 100;
  margin-top: auto;
}
.bottom a {
  color: var(--muted);
  text-decoration: none;
  font-size: 8px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 3px;
}
.bottom i { font-size: 15px; margin-bottom: 0; }
.bottom a.active { color: var(--olive-shadow); font-weight: 700; }
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);backdrop-filter:blur(3px);align-items:center;justify-content:center;padding:18px;z-index:1000}
.modal.show{display:flex}
.box{width:min(360px,100%);background:var(--white);border:1px solid var(--sandstone);border-radius:22px;padding:20px;text-align:center;box-shadow:0 20px 60px rgba(75,83,32,0.25)}
.box img{width:58px;height:58px;border-radius:50%;border:2px solid var(--sandstone)}
.box h3{font-size:16px;margin:8px 0 5px;color:var(--olive-shadow)}
.box p{font-size:9px;color:var(--muted);line-height:1.5}
.box-actions{display:flex;gap:8px;margin-top:14px}
.box-actions>*{flex:1}
.notification-list{max-height:55vh;overflow:auto;text-align:left;margin-top:10px}
.note{padding:11px;border-bottom:1px solid var(--line);font-size:9px}
.note.unread{background:#fdf8ef;border-left:3px solid var(--harvest-sun)}
.note b{display:block;font-size:10px;color:var(--olive-shadow)}
.note small{display:block;color:var(--muted);margin-top:3px}
.close{border:0;background:var(--sandstone);color:var(--olive-shadow);border-radius:10px;padding:10px;font-family:inherit;font-weight:700;width:100%;margin-top:10px;cursor:pointer}

.toast-rider{position:fixed;left:50%;bottom:88px;transform:translateX(-50%) translateY(20px);background:var(--olive-shadow);color:#fff;padding:10px 18px;border-radius:20px;font-size:11px;font-weight:600;opacity:0;pointer-events:none;transition:.25s;z-index:200;white-space:nowrap;box-shadow:0 4px 15px rgba(75,83,32,.30)}
.toast-rider.show{opacity:1;transform:translateX(-50%) translateY(0)}
.toast-rider.err{background:var(--earth-clay)}
</style></head><body><div class="app"><header class="hero"><div class="topline"><div class="brand"><img src="logo.jpg" alt="Food Dash"><b>FOOD DASH</b></div><button class="bell" onclick="openNotifications()"><i class="fa-regular fa-bell"></i><span id="noteBadge" class="bell-badge">0</span></button></div><div class="hello"><small>Rider Portal</small><h1>Ready to dash, <?=htmlspecialchars($user_name)?>?</h1><small>Pick up orders and keep customers updated.</small><div class="status"><span class="dot <?=$r && $r['status']==='on_delivery'?'live':''?>"></span><?=$r && $r['status']==='on_delivery'?'On Delivery':'Waiting for Delivery'?></div></div></header><main><?php if($msg):?><div class="alert">✓ <?=htmlspecialchars($msg)?></div><?php endif;?><?php if($error):?><div class="alert err">⚠ <?=htmlspecialchars($error)?></div><?php endif;?><div class="stats"><div class="stat"><i class="fa-solid fa-motorcycle"></i><strong><?=$total_riders?></strong><span>Total Riders</span></div><div class="stat"><i class="fa-solid fa-clock"></i><strong><?=$waiting?></strong><span>Waiting</span></div><div class="stat"><i class="fa-solid fa-route"></i><strong><?=$on_delivery?></strong><span>On Delivery</span></div></div>
<section class="section" id="delivery"><div class="section-title"><h2><i class="fa-solid fa-box"></i> My Delivery</h2></div><div class="card"><?php if($my_orders): foreach($my_orders as $o): 
  $ord_st = strtoupper($o['status'] ?? '');
  $del_st = strtolower($o['delivery_status'] ?? '');
  $addr = trim((string)($o['delivery_address'] ?? ''));
  $lat = $o['delivery_lat'] ?? $o['customer_lat'] ?? null;
  $lng = $o['delivery_lng'] ?? $o['customer_lng'] ?? null;
  $has_coords = ($lat !== null && $lng !== null && is_numeric($lat) && is_numeric($lng));
  if ($has_coords) {
      $maps_url = "https://www.google.com/maps/dir/?api=1&destination=" . urlencode($lat . ',' . $lng);
  } elseif (!empty($addr)) {
      $maps_url = "https://www.google.com/maps/dir/?api=1&destination=" . urlencode($addr);
  } else {
      $maps_url = "https://www.google.com/maps";
  }
?><div class="order"><div class="order-ref"><?=htmlspecialchars($o['order_ref'])?></div><div class="meta"><b><?=htmlspecialchars($o['store_name'])?></b> • <?=htmlspecialchars($o['customer_name'])?><br><?=htmlspecialchars($o['items_summary'])?><br>Status: <b><?=htmlspecialchars(ucwords(str_replace('_',' ',$ord_st)))?></b> (<?=htmlspecialchars(ucwords(str_replace('_',' ',$del_st)))?>)</div><div class="rider-address-card"><div class="rider-address-title"><i class="fa-solid fa-location-dot"></i> Customer Delivery Address</div><div class="rider-address-text"><?=htmlspecialchars(!empty($addr) ? $addr : 'No street address provided (using coordinates / default)')?></div><?php if($has_coords): ?><div class="rider-gps-pill"><i class="fa-solid fa-crosshairs"></i> GPS: <?=htmlspecialchars(number_format((float)$lat, 5))?>, <?=htmlspecialchars(number_format((float)$lng, 5))?></div><?php endif; ?></div><div style="margin: 10px 0 6px;"><a href="<?=htmlspecialchars($maps_url)?>" target="_blank" rel="noopener noreferrer" class="btn btn-maps full"><i class="fa-solid fa-diamond-turn-right"></i> Navigate / Open in Maps</a></div><div class="actions"><?php if($del_st === 'near_customer'): ?><button class="btn success full" onclick="doDeliveryAction(<?=$o['order_id']?>, 'delivered')">✓ Complete Delivery (Delivered)</button><?php elseif($del_st === 'on_the_way'): ?><button class="btn warn full" onclick="doDeliveryAction(<?=$o['order_id']?>, 'near')">📍 I'm Near Customer</button><button class="btn success full" onclick="doDeliveryAction(<?=$o['order_id']?>, 'delivered')">✓ Complete Delivery</button><button type="button" class="btn secondary full" onclick="startLiveLocation(<?=$o['order_id']?>)">📡 Share Live Location</button><?php else: ?><button class="btn full" onclick="doDeliveryAction(<?=$o['order_id']?>, 'on_way')">🚚 I'm On The Way</button><button class="btn success full" onclick="doDeliveryAction(<?=$o['order_id']?>, 'delivered')">✓ Complete Delivery</button><?php endif; ?></div></div><?php endforeach; else: ?><div class="empty">No active delivery yet. Accept a ready order below.</div><?php endif; ?></div></section>
<section class="section" id="available"><div class="section-title"><h2>Orders Ready for Pickup</h2></div><div class="card"><?php if($ready_orders): foreach($ready_orders as $o): 
  $addr = trim((string)($o['delivery_address'] ?? ''));
  $lat = $o['delivery_lat'] ?? $o['customer_lat'] ?? null;
  $lng = $o['delivery_lng'] ?? $o['customer_lng'] ?? null;
  $has_coords = ($lat !== null && $lng !== null && is_numeric($lat) && is_numeric($lng));
  if ($has_coords) {
      $maps_url = "https://www.google.com/maps/dir/?api=1&destination=" . urlencode($lat . ',' . $lng);
  } elseif (!empty($addr)) {
      $maps_url = "https://www.google.com/maps/dir/?api=1&destination=" . urlencode($addr);
  } else {
      $maps_url = "https://www.google.com/maps";
  }
?><div class="order"><div class="order-ref"><?=htmlspecialchars($o['order_ref'])?></div><div class="meta"><b><?=htmlspecialchars($o['store_name'])?></b> • <?=htmlspecialchars($o['customer_name'])?><br><?=htmlspecialchars($o['items_summary'])?><br>₱<?=number_format((float)$o['total_amount'],2)?></div><div class="rider-address-card" style="margin:8px 0 10px;"><div class="rider-address-title"><i class="fa-solid fa-location-dot"></i> Delivery Address</div><div class="rider-address-text"><?=htmlspecialchars(!empty($addr) ? $addr : 'No delivery address provided')?></div><?php if($has_coords): ?><div class="rider-gps-pill"><i class="fa-solid fa-crosshairs"></i> GPS: <?=htmlspecialchars(number_format((float)$lat, 5))?>, <?=htmlspecialchars(number_format((float)$lng, 5))?></div><?php endif; ?></div><div style="display:flex;gap:7px;"><button class="btn" style="flex:1" onclick="doDeliveryAction(<?=$o['order_id']?>, 'claim')">Accept Delivery</button><a href="<?=htmlspecialchars($maps_url)?>" target="_blank" rel="noopener noreferrer" class="btn btn-maps-outline" title="Open Destination in Google Maps"><i class="fa-solid fa-diamond-turn-right"></i> Maps</a></div></div><?php endforeach; else: ?><div class="empty">No unassigned ready orders.</div><?php endif; ?></div></section>
<section class="section" id="live"><div class="card"><h2 style="font-size:1rem;margin:0 0 8px">📡 Live Location</h2><div id="gpsStatus" class="meta">Live location is off. Tap Share Live Location after starting a delivery.</div><button type="button" class="btn full" style="margin-top:10px" onclick="stopLiveLocation()">Stop Sharing</button></div></section><section class="section" id="guide"><div class="card"><h2 style="font-size:1rem;margin:0 0 8px">Customer Notification Flow</h2><div class="meta">When you accept an order, the customer is notified. When you start the trip, they receive an <b>On the Way</b> notification. When you tap <b>I'm Near Customer</b>, they receive the near-arrival notification. When delivered, the customer receives a final delivery notice.</div></div></section></main><nav class="bottom"><a class="active" href="#delivery"><i class="fa-solid fa-box"></i>Delivery</a><a href="#available"><i class="fa-solid fa-motorcycle"></i>Pickup</a><a href="#live"><i class="fa-solid fa-location-crosshairs"></i>Live</a><a href="#guide"><i class="fa-solid fa-circle-info"></i>Guide</a><a href="#" onclick="openLogout();return false"><i class="fa-solid fa-right-from-bracket"></i>Logout</a></nav></div>
<div class="modal" id="noteModal"><div class="box"><img src="logo.jpg" alt="Food Dash"><h3>Notifications</h3><div id="notificationList" class="notification-list"><div class="empty">Loading...</div></div><div class="box-actions"><button class="close" onclick="markAllRead()">Mark all read</button><button class="close" onclick="closeNotifications()">Close</button></div></div></div>
<div class="modal" id="logoutModal"><div class="box"><img src="logo.jpg" alt="Food Dash"><h3>Log out of Food Dash?</h3><p>Your current session will be signed out.</p><div class="box-actions"><button class="close" onclick="closeLogout()">Cancel</button><a class="btn" style="display:flex;align-items:center;justify-content:center;text-decoration:none" href="logout.php?confirm=1">Yes, Log Out</a></div></div></div>
<div class="toast-rider" id="riderToast"></div>
<script>
function toastRider(msg, isError) {
  const el = document.getElementById('riderToast');
  el.textContent = msg;
  el.classList.toggle('err', !!isError);
  el.classList.add('show');
  clearTimeout(window._riderToast);
  window._riderToast = setTimeout(() => el.classList.remove('show'), 3500);
}

async function doDeliveryAction(orderId, action) {
  try {
    const fd = new FormData();
    fd.append('delivery_action', action);
    fd.append('order_id', orderId);
    fd.append('ajax', 'true');
    const r = await fetch(window.location.href, { method: 'POST', body: fd });
    
    if (!r.ok) {
      const text = await r.text();
      throw new Error('Server error ' + r.status + ': ' + text);
    }
    
    const data = await r.json();
    if (data.success) {
      toastRider(data.message || 'Action completed');
      setTimeout(() => location.reload(), 1500);
    } else {
      toastRider(data.message || 'Action failed', true);
      console.error('Delivery action failed:', data.message || 'No message from server');
    }
  } catch (e) {
    toastRider(e.message || ('Network error during ' + action), true);
    console.error('Delivery action error:', e);
  }
}

async function loadNotifications(){try{const r=await fetch('notification_api.php?limit=30',{cache:'no-store'}),d=await r.json();if(!d.success)return;const b=document.getElementById('noteBadge');b.textContent=d.unread;b.style.display=d.unread?'block':'none';const el=document.getElementById('notificationList');el.innerHTML=d.notifications.length?d.notifications.map(n=>`<div class="note ${n.is_read==0 || n.is_read===false || n.is_read=='f' || n.status==='unread'?'unread':''}" onclick="readNote(${n.notification_id})"><b>${esc(n.title)}</b><span>${esc(n.message)}</span><small>${esc(n.created_at)}</small></div>`).join(''):'<div class="empty">No notifications yet.</div>';}catch(e){}}
function esc(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]))}
async function readNote(id){await fetch('notification_api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'mark_read',notification_id:id})});loadNotifications()}
async function markAllRead(){await fetch('notification_api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'mark_all_read'})});loadNotifications()}
function openNotifications(){document.getElementById('noteModal').classList.add('show');loadNotifications()}function closeNotifications(){document.getElementById('noteModal').classList.remove('show')}
function openLogout(){document.getElementById('logoutModal').classList.add('show')}function closeLogout(){document.getElementById('logoutModal').classList.remove('show')}
loadNotifications();setInterval(loadNotifications,5000);document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeNotifications();closeLogout()}});

let gpsWatchId=null, gpsOrderId=null;
async function startLiveLocation(orderId){
  if(!navigator.geolocation){toastGps('GPS is not supported on this device.');return;}
  gpsOrderId=orderId;
  document.getElementById('gpsStatus').textContent='Requesting location permission…';
  gpsWatchId=navigator.geolocation.watchPosition(async pos=>{
    const lat=pos.coords.latitude,lng=pos.coords.longitude;
    try{
      const r=await fetch('tracking_api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'update_rider_location',order_id:gpsOrderId,lat,lng})});
      const d=await r.json();
      if(d.success){document.getElementById('gpsStatus').textContent=`Live location sharing • ${lat.toFixed(5)}, ${lng.toFixed(5)}${d.distance_km!=null?' • '+(d.distance_km<1?(d.distance_km*1000).toFixed(0)+' m':d.distance_km.toFixed(2)+' km')+' from customer':''}`;}
    }catch(e){document.getElementById('gpsStatus').textContent='Location update failed. Retrying…'}
  },err=>{document.getElementById('gpsStatus').textContent='GPS permission is required for automatic near-arrival alerts.';}, {enableHighAccuracy:true,maximumAge:5000,timeout:15000});
}
function stopLiveLocation(){if(gpsWatchId!==null){navigator.geolocation.clearWatch(gpsWatchId);gpsWatchId=null;}document.getElementById('gpsStatus').textContent='Live location is off.';}
function toastGps(msg){const el=document.getElementById('gpsStatus');if(el)el.textContent=msg;}
window.addEventListener('beforeunload',()=>stopLiveLocation());
</script></body></html>
