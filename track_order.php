<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db_connect.php';
require_once 'payment_helper.php';
require_once __DIR__ . '/geocode_lib.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Customer';

$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

// If no order_id provided, find latest active order for this customer
if ($order_id <= 0) {
    $is_demo = ($user_id === 15 || $user_id === 26 || strtolower($_SESSION['username'] ?? '') === 'customer');
    $scope_sql = $is_demo 
        ? "(customer_id = :cid OR user_id = :uid OR customer_id IN (15, 26, 0) OR user_id IN (15, 26, 0))" 
        : "(customer_id = :cid OR user_id = :uid)";
    $stmt = $conn->prepare("SELECT order_id FROM food_orders 
        WHERE {$scope_sql} 
        ORDER BY CASE 
            WHEN LOWER(TRIM(status)) IN ('out_for_delivery', 'on_the_way', 'on_delivery', 'near_customer', 'arrived') THEN 1 
            WHEN LOWER(TRIM(status)) IN ('preparing', 'cooking', 'in_kitchen', 'ready_for_pickup', 'ready', 'accepted', 'claimed', 'pending') THEN 2 
            ELSE 3 END ASC, order_id DESC LIMIT 1");
    $stmt->execute([':cid' => $user_id, ':uid' => $user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $order_id = (int)$row['order_id'];
    }
}

// Fetch order information
$order = null;
if ($order_id > 0) {
    $is_demo = ($user_id === 15 || $user_id === 26 || strtolower($_SESSION['username'] ?? '') === 'customer');
    $scope_single = $is_demo 
        ? "(o.customer_id = :cid OR o.user_id = :uid OR o.customer_id IN (15, 26, 0) OR o.user_id IN (15, 26, 0) OR :is_admin = 1)" 
        : "(o.customer_id = :cid OR o.user_id = :uid OR :is_admin = 1)";
    $stmt = $conn->prepare("SELECT 
        o.order_id, o.order_ref, o.store_name, o.delivery_address, 
        o.status, o.total_amount, o.created_at,
        COALESCE(o.payment_method, 'Cash on Delivery (COD)') AS payment_method,
        COALESCE(o.store_lat, s.lat, s.lat, 11.0062) AS store_lat,
        COALESCE(o.store_lng, s.lng, s.lng, 124.6068) AS store_lng,
        COALESCE(o.delivery_lat, o.customer_lat, 11.0076) AS destination_lat,
        COALESCE(o.delivery_lng, o.customer_lng, 124.6089) AS destination_lng,
        COALESCE(o.rider_lat, r.current_lat) AS rider_lat,
        COALESCE(o.rider_lng, r.current_lng) AS rider_lng,
        COALESCE(r.rider_name, ru.full_name, o.rider_name, o.rider_fullname) AS rider_name,
        COALESCE(o.rider_phone, r.phone, ru.phone) AS rider_phone
    FROM food_orders o
    LEFT JOIN shops s ON (o.shop_id = s.shop_id OR (o.shop_id = 0 AND LOWER(TRIM(o.store_name)) = LOWER(TRIM(s.shop_name)) COLLATE utf8mb4_unicode_ci))
    LEFT JOIN riders r ON (o.rider_id = r.rider_id OR o.rider_id = r.user_id)
    LEFT JOIN users ru ON (r.user_id = ru.user_id OR o.rider_id = ru.user_id)
    WHERE o.order_id = :oid AND {$scope_single}
    LIMIT 1");
    $isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') ? 1 : 0;
    $stmt->execute([':oid' => $order_id, ':cid' => $user_id, ':uid' => $user_id, ':is_admin' => $isAdmin]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($order) {
        apply_dynamic_geocoding_to_order($order, $conn);
        resolve_order_rider_info($conn, $order);
        if (!empty($order['order_ref'])) {
            $order['order_ref'] = '#' . ltrim($order['order_ref'], '#');
        }
    }
}

$raw_status = strtoupper(trim(str_replace([' ', '-'], '_', $order['status'] ?? 'ON_DELIVERY')));
$status_label = ucwords(strtolower(str_replace('_', ' ', $raw_status)));
$raw_r_name = trim((string)($order['rider_name'] ?? ''));
$is_r_assigned = (!empty($raw_r_name) && strtolower($raw_r_name) !== 'delivery partner' && strtolower($raw_r_name) !== 'alex rider' && strtolower($raw_r_name) !== 'assigned rider' && strtolower($raw_r_name) !== 'none');
$rider_name = $is_r_assigned ? $raw_r_name : 'Waiting for Rider Assignment';
$rider_phone = !empty($order['rider_phone']) ? $order['rider_phone'] : ($is_r_assigned ? 'No phone provided' : 'Rider not yet assigned');
$rider_dial = preg_replace('/[^\+0-9]/', '', $rider_phone) ?: '+639175550192';
// $rider_name resolved dynamically above
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<title>Live Delivery Tracking - <?= htmlspecialchars($order['order_ref'] ?? 'Food Dash') ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
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
:root {
  --olive-shadow: #4B5320;
  --sagebrush: #708238;
  --sandstone: #D8CBB5;
  --harvest-sun: #D88F2A;
  --dark-clay: #923D32;
  --cream: #FAF9F6;
  --ink: #1C1D17;
  --muted: #6B7280;
  --line: #E5E7EB;
}
* { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
html, body { width: 100%; min-height: 100%; margin: 0; padding: 0; -webkit-text-size-adjust: 100%; }
body { background: #f3f0e6; color: var(--ink); min-height: 100vh; display: flex; justify-content: center; }
.app-container { width: 100%; max-width: 480px; background: #ffffff; min-height: 100vh; display: flex; flex-direction: column; box-shadow: 0 0 24px rgba(75, 83, 32, 0.12); position: relative; }
@media (max-width: 599px) {
  html, body { width: 100%; min-height: 100%; margin: 0; padding: 0; }
  .app-container { max-width: 100%; box-shadow: none; }
}

/* Header */
.track-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 18px;
  background: #ffffff;
  border-bottom: 1px solid var(--line);
  position: sticky;
  top: 0;
  z-index: 1000;
}
.back-btn {
  background: #f4efe4;
  border: none;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--olive-shadow);
  cursor: pointer;
  text-decoration: none;
  font-size: 14px;
}
.header-title { text-align: center; }
.header-title h2 { font-size: 15px; font-weight: 700; color: var(--olive-shadow); }
.header-title small { font-size: 11px; color: var(--muted); }
.status-pill {
  background: rgba(216, 143, 42, 0.15);
  color: #b45309;
  font-size: 11px;
  font-weight: 700;
  padding: 4px 10px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  gap: 5px;
}

/* ETA & Distance Hero Badge */
.eta-hero-badge {
  background: linear-gradient(135deg, #fdf8eb 0%, #fff7e6 100%);
  border: 1.5px solid #f3d18e;
  border-radius: 14px;
  margin: 12px 16px 6px;
  padding: 12px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  box-shadow: 0 4px 12px rgba(216, 143, 42, 0.12);
}
.eta-hero-left { display: flex; align-items: center; gap: 10px; }
.eta-hero-icon {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  background: var(--harvest-sun);
  color: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 17px;
  box-shadow: 0 3px 8px rgba(216, 143, 42, 0.35);
}
.eta-hero-title { font-size: 11px; font-weight: 600; color: #b45309; text-transform: uppercase; letter-spacing: 0.5px; }
.eta-hero-val { font-size: 15px; font-weight: 800; color: var(--olive-shadow); }
.eta-hero-pill {
  background: #2e7d32;
  color: #ffffff;
  font-size: 11px;
  font-weight: 700;
  padding: 5px 10px;
  border-radius: 16px;
  display: flex;
  align-items: center;
  gap: 4px;
}

/* Map Section */
.map-wrap {
  flex: 1;
  min-height: 340px;
  position: relative;
  margin: 8px 16px;
  border-radius: 16px;
  overflow: hidden;
  border: 1.5px solid var(--sandstone);
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
}
#map { width: 100%; height: 100%; min-height: 340px; }

/* Rider Contact Card */
.contact-card {
  background: #ffffff;
  border: 1.5px solid var(--sandstone);
  border-radius: 16px;
  margin: 10px 16px;
  padding: 14px 16px;
  box-shadow: 0 4px 16px rgba(75, 83, 32, 0.08);
}
.contact-card-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}
.rider-profile { display: flex; align-items: center; gap: 10px; }
.rider-avatar {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: rgba(75, 83, 32, 0.12);
  color: var(--olive-shadow);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 19px;
  border: 2px solid var(--sandstone);
}
.rider-name { font-size: 14px; font-weight: 700; color: var(--olive-shadow); }
.rider-sub { font-size: 11px; color: var(--muted); }

/* Communication Buttons */
.contact-actions {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 8px;
}
.comm-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 10px 8px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 700;
  text-decoration: none;
  cursor: pointer;
  border: none;
  transition: transform 0.15s ease, filter 0.15s ease;
}
.comm-btn:active { transform: scale(0.97); }
.comm-btn.call {
  background: #2e7d32;
  color: #ffffff;
  box-shadow: 0 3px 10px rgba(46, 125, 50, 0.25);
}
.comm-btn.msg {
  background: var(--harvest-sun);
  color: #ffffff;
  box-shadow: 0 3px 10px rgba(216, 143, 42, 0.25);
}
.comm-btn.sms {
  background: #0284c7;
  color: #ffffff;
  box-shadow: 0 3px 10px rgba(2, 132, 199, 0.25);
}

/* Map Legend */
.map-legend {
  display: flex;
  justify-content: space-around;
  padding: 6px 16px 12px;
  font-size: 11px;
  color: var(--muted);
}
.legend-item { display: flex; align-items: center; gap: 5px; }
.legend-dot { width: 9px; height: 9px; border-radius: 50%; }
.legend-dot.store { background: #d97706; }
.legend-dot.rider { background: #16a34a; }
.legend-dot.dest { background: #dc2626; }

@keyframes pulseGlow {
  0% { transform: scale(0.85); opacity: 0.8; }
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
  top: 64px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 9999;
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
.live-rider-msg-banner.slide-down,
.slide-down {
  display: flex !important;
  visibility: visible !important;
  opacity: 1 !important;
  pointer-events: auto !important;
  animation: bannerSlideDown 0.35s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards !important;
}
@keyframes bannerSlideDown {
  0% { transform: translate(-50%, -40px); opacity: 0; }
  100% { transform: translate(-50%, 0); opacity: 1; }
}
@keyframes scaleUp {
  0% { transform: scale(0.85); opacity: 0; }
  100% { transform: scale(1); opacity: 1; }
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

/* Official Payment Brand Badges */
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

/* In-App Customer Chat Drawer */
.track-chat-modal {
  position: fixed;
  inset: 0;
  z-index: 999999;
  display: flex;
  justify-content: flex-end;
}
.track-chat-backdrop {
  position: absolute;
  inset: 0;
  background: rgba(15, 23, 42, 0.55);
  backdrop-filter: blur(4px);
}
.track-chat-drawer {
  position: relative;
  width: 100%;
  max-width: 420px;
  background: #ffffff;
  height: 100%;
  display: flex;
  flex-direction: column;
  box-shadow: -8px 0 30px rgba(0, 0, 0, 0.25);
  z-index: 1;
  animation: slideInRight 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes slideInRight {
  from { transform: translateX(100%); }
  to { transform: translateX(0); }
}
.track-chat-header {
  padding: 14px 16px;
  background: #1e293b;
  color: #ffffff;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.track-chat-user-info {
  display: flex;
  align-items: center;
  gap: 10px;
}
.track-chat-user-info .chat-avatar {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  background: #16a34a;
  color: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
}
.track-chat-user-info h4 {
  font-size: 14px;
  font-weight: 700;
  margin: 0;
}
.chat-close-btn {
  background: rgba(255, 255, 255, 0.15);
  border: none;
  color: #ffffff;
  font-size: 22px;
  line-height: 1;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
}
.track-chat-body {
  flex: 1;
  overflow-y: auto;
  padding: 16px;
  background: #f8fafc;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.msg-bubble {
  max-width: 82%;
  padding: 10px 14px;
  border-radius: 16px;
  font-size: 13px;
  line-height: 1.4;
  word-wrap: break-word;
}
.msg-bubble.me {
  align-self: flex-end;
  background: #4B5320;
  color: #ffffff;
  border-bottom-right-radius: 4px;
}
.msg-bubble.them {
  align-self: flex-start;
  background: #ffffff;
  color: #1e293b;
  border: 1px solid #e2e8f0;
  border-bottom-left-radius: 4px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.msg-bubble .msg-meta {
  font-size: 10px;
  opacity: 0.75;
  margin-top: 4px;
  text-align: right;
}
.track-chat-footer {
  padding: 12px 16px;
  background: #ffffff;
  border-top: 1px solid #e2e8f0;
  display: flex;
  gap: 8px;
}
.track-chat-footer input {
  flex: 1;
  padding: 10px 14px;
  border-radius: 20px;
  border: 1px solid #cbd5e1;
  font-size: 13px;
  outline: none;
}
.track-chat-footer input:focus {
  border-color: #4B5320;
}
.chat-send-btn {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: #4B5320;
  color: #ffffff;
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
}
</style>
</head>
<body>
<div class="app-container">

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
  <div id="liveRiderMessageBanner" class="live-rider-msg-banner" style="display:none;" onclick="openTrackChat()">
    <div class="msg-banner-avatar"><i class="fa-solid fa-motorcycle"></i></div>
    <div class="msg-banner-content">
      <div class="msg-banner-title">
        <span id="liveMsgBannerTitle">New message from rider (<?= htmlspecialchars($rider_name) ?>)</span>
        <span class="msg-banner-time">Just now</span>
      </div>
      <div class="msg-banner-body" id="liveMsgBannerText">I'm outside with your order! 🛵</div>
    </div>
    <div class="msg-banner-action">
      <span class="tap-reply-pill">Reply <i class="fa-solid fa-chevron-right"></i></span>
    </div>
  </div>

  <!-- Prominent Rider Arrived Live Notification Modal -->
  <div id="riderArrivedModal" style="display:none; position:fixed; inset:0; z-index:9999999; align-items:center; justify-content:center;">
    <div onclick="/* Persistent alarm: must select an action button below */" style="position:absolute; inset:0; background:rgba(15,23,42,0.65); backdrop-filter:blur(4px);"></div>
    <div style="text-align:center; padding:28px 22px 24px; width:calc(100% - 32px); max-width:350px; border-radius:24px; border:2px solid #86efac; box-shadow:0 24px 60px rgba(0,0,0,0.35); background:#ffffff; position:relative; z-index:1; animation:scaleUp 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);">
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
      </div>
    </div>
  </div>

  <!-- Header -->
  <header class="track-header">
    <a href="customer_dashboard.php?screen=orders" class="back-btn" title="Back to Orders">
      <i class="fa-solid fa-arrow-left"></i>
    </a>
    <div class="header-title">
      <h2><?= htmlspecialchars($order['order_ref'] ?? 'Live Delivery') ?></h2>
      <small><?= htmlspecialchars($order['store_name'] ?? 'Store') ?> • <?= render_payment_badge($order['payment_method'] ?? 'Cash on Delivery (COD)') ?></small>
    </div>
    <div class="status-pill">
      <i class="fa-solid fa-circle-dot fa-beat-fade"></i>
      <span><?= htmlspecialchars($status_label) ?></span>
    </div>
  </header>

  <!-- Remaining distance & ETA badge (e.g. "500m away • ~3 mins") -->
  <div class="eta-hero-badge" id="etaHeroBadge">
    <div class="eta-hero-left">
      <div class="eta-hero-icon"><i class="fa-solid fa-motorcycle"></i></div>
      <div>
        <div class="eta-hero-title">Live Delivery Status</div>
        <div class="eta-hero-val" id="etaBadgeText">Locating rider & calculating ETA...</div>
      </div>
    </div>
    <div class="eta-hero-pill" id="distPill">
      <i class="fa-solid fa-route"></i>
      <span id="distPillText">Calculating...</span>
    </div>
  </div>

  <!-- Real-Time Leaflet Delivery Tracking Map -->
  <div class="map-wrap">
    <div id="map"></div>
  </div>

  <!-- Legend -->
  <div class="map-legend">
    <div class="legend-item"><span class="legend-dot store"></span> Store Pickup</div>
    <div class="legend-item"><span class="legend-dot rider"></span> Live Rider GPS</div>
    <div class="legend-item"><span class="legend-dot dest"></span> Delivery Location</div>
  </div>

  <!-- Rider Contact Card -->
  <div class="contact-card">
    <div class="contact-card-top">
      <div class="rider-profile">
        <div class="rider-avatar"><i class="fa-solid fa-motorcycle"></i></div>
        <div>
          <div class="rider-name" id="riderNameDisplay"><?= htmlspecialchars($rider_name) ?></div>
          <div class="rider-sub" id="riderPhoneDisplay"><i class="fa-solid fa-phone-volume"></i> <?= htmlspecialchars($rider_phone) ?></div>
        </div>
      </div>
      <small style="font-size:10px; color:var(--muted); text-align:right;">
        Total Bill: <strong style="color:var(--olive-shadow);">₱<?= number_format((float)($order['total_amount'] ?? 0), 2) ?></strong><br>
        <span style="display:inline-block; margin-top:3px;"><?= render_payment_badge($order['payment_method'] ?? 'Cash on Delivery (COD)') ?></span>
      </small>
    </div>
    <div class="contact-actions">
      <button type="button" id="btnCallRider" onclick="initiateCustomerVoiceCall()" class="comm-btn call" title="Call Rider via Synchronized In-App Voice">
        <i class="fa-solid fa-phone"></i> Call Rider
      </button>
      <button type="button" id="btnMsgRider" onclick="openTrackChat()" class="comm-btn msg" title="Open In-App Chat" style="position:relative;">
        <i class="fa-solid fa-comment-dots"></i> Message Rider
        <span class="cust-chat-unread-badge" id="trackChatBadge" style="display:none; position:absolute; top:-6px; right:-6px; background:#ef4444; color:#fff; font-size:10px; font-weight:800; border-radius:50%; width:18px; height:18px; align-items:center; justify-content:center; border:2px solid #fff;">0</span>
      </button>
      <a id="btnSmsRider" href="sms:<?= htmlspecialchars($rider_dial) ?>?body=Hi%20<?= urlencode($rider_name) ?>,%20regarding%20order%20<?= urlencode($order['order_ref'] ?? '') ?>" class="comm-btn sms" title="Direct Native SMS">
        <i class="fa-solid fa-envelope"></i> SMS
      </a>
    </div>
  </div>

  <!-- In-App Customer Chat Drawer Modal -->
  <div id="trackChatModal" class="track-chat-modal" style="display:none;">
    <div class="track-chat-backdrop" onclick="closeTrackChat()"></div>
    <div class="track-chat-drawer">
      <div class="track-chat-header">
        <div class="track-chat-user-info">
          <div class="chat-avatar"><i class="fa-solid fa-motorcycle"></i></div>
          <div>
            <h4 id="trackChatRiderName">Chat with <?= htmlspecialchars($rider_name) ?></h4>
            <span style="font-size:11px; color:#16a34a; font-weight:600;"><i class="fa-solid fa-circle" style="font-size:7px;"></i> Active Delivery</span>
          </div>
        </div>
        <button type="button" class="chat-close-btn" onclick="closeTrackChat()">&times;</button>
      </div>
      <div class="track-chat-body" id="trackChatMessagesList">
        <div class="chat-loading-hint" style="text-align:center; color:#94a3b8; padding:20px; font-size:12px;">Connecting to secure chat...</div>
      </div>
      <form class="track-chat-footer" onsubmit="sendTrackChatMessage(event)">
        <input type="text" id="trackChatInput" placeholder="Message rider..." autocomplete="off">
        <button type="submit" class="chat-send-btn" id="trackChatSendBtn"><i class="fa-solid fa-paper-plane"></i></button>
      </form>
    </div>
  </div>

</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="assets/js/order_call_engine.js"></script>
<script>
if (window.FoodDashCall) {
  FoodDashCall.init('customer');
}
const orderId = <?= (int)$order_id ?>;
const initStoreLat = <?= (float)($order['store_lat'] ?? 11.0062) ?>;
const initStoreLng = <?= (float)($order['store_lng'] ?? 124.6068) ?>;
const initDestLat = <?= (float)($order['destination_lat'] ?? 11.00762) ?>;
const initDestLng = <?= (float)($order['destination_lng'] ?? 124.60749) ?>;
const initRiderLat = <?= !empty($order['rider_lat']) ? (float)$order['rider_lat'] : 'null' ?>;
const initRiderLng = <?= !empty($order['rider_lng']) ? (float)$order['rider_lng'] : 'null' ?>;

let trackingMap = null;
let storeMarker = null;
let destMarker = null;
let riderMarker = null;
let routePolyline = null;
let lastRouteKey = '';
let pollTimer = null;

function calculateHaversineKm(lat1, lon1, lat2, lon2) {
  const R = 6371;
  const dLat = (lat2 - lat1) * Math.PI / 180;
  const dLon = (lon2 - lon1) * Math.PI / 180;
  const a = Math.sin(dLat / 2) ** 2 + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * Math.sin(dLon / 2) ** 2;
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

// Smoothly interpolate Leaflet marker coordinates so the motorcycle pin glides without snapping
function animateMarkerTo(marker, newLat, newLng, durationMs = 1400) {
  if (!marker || typeof marker.getLatLng !== 'function') return;
  const cur = marker.getLatLng();
  const startLat = cur.lat;
  const startLng = cur.lng;
  const deltaLat = newLat - startLat;
  const deltaLng = newLng - startLng;

  if (Math.abs(deltaLat) < 0.000005 && Math.abs(deltaLng) < 0.000005) {
    return;
  }
  if (Math.abs(deltaLat) > 0.05 || Math.abs(deltaLng) > 0.05) {
    marker.setLatLng([newLat, newLng]);
    return;
  }

  const totalDist = calculateHaversineKm(startLat, startLng, newLat, newLng);
  if (totalDist < 0.00002) return;

  if (marker._animFrameId) {
    cancelAnimationFrame(marker._animFrameId);
    marker._animFrameId = null;
  }

  // Extract path waypoints from OSRM road geometry if available
  let waypoints = [];
  if (Array.isArray(currentTrackOsrmPath) && currentTrackOsrmPath.length > 2) {
    let closestStartIdx = 0;
    let minStartDist = Infinity;
    let closestEndIdx = 0;
    let minEndDist = Infinity;

    for (let i = 0; i < currentTrackOsrmPath.length; i++) {
      const pt = currentTrackOsrmPath[i];
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
        waypoints.push(currentTrackOsrmPath[i]);
      }
      waypoints.push(currentTrackOsrmPath[closestEndIdx]);
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

let currentTrackOsrmPath = [];

function animateMarkerAlongRoad(marker, newLat, newLng, durationMs = 1500) {
  animateMarkerTo(marker, newLat, newLng, durationMs);
}

// Fallback street corridor generator if OSRM is unreachable
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

// Display exact OSRM driving distance and road ETA matching the rider dashboard
function updateEtaBadge(dKm, distMeters = null) {
  const badgeEl = document.getElementById('etaBadgeText');
  const pillEl = document.getElementById('distPillText');
  if (dKm === null || isNaN(dKm)) return;

  const d = parseFloat(dKm);
  const meters = (distMeters !== null && !isNaN(distMeters)) ? Math.round(distMeters) : Math.round(d * 1000);

  let badgeStr = '';
  let pillStr = '';

  if (meters <= 50) {
    badgeStr = 'Arrived at your door! • 0 mins';
    pillStr = 'Road Distance: Arrived!';
  } else if (meters < 1000) {
    const mins = Math.max(1, Math.round((meters / 1000) * 4));
    badgeStr = `${meters}m away • ~${mins} mins`;
    pillStr = `Road Distance: ${meters} meters`;
  } else {
    const kmStr = (meters / 1000).toFixed(2);
    const mins = Math.max(3, Math.round((meters / 1000) * 3.5));
    badgeStr = `${kmStr} km away • ~${mins} mins`;
    pillStr = `Road Distance: ${kmStr} km`;
  }

  if (badgeEl) badgeEl.textContent = badgeStr;
  if (pillEl) pillEl.textContent = pillStr;
}

async function fetchOsrmRoadPolyline(startLat, startLng, destLat, destLng) {
  // If we already have road waypoints from OSRM, slice along geometry & compute remaining road distance in 0ms
  if (Array.isArray(currentTrackOsrmPath) && currentTrackOsrmPath.length > 1) {
    let closestIdx = 0;
    let minD = Infinity;
    for (let i = 0; i < currentTrackOsrmPath.length; i++) {
      const pt = currentTrackOsrmPath[i];
      const d = calculateHaversineKm(startLat, startLng, pt[0], pt[1]);
      if (d < minD) {
        minD = d;
        closestIdx = i;
      }
    }
    // If rider is close to existing corridor (within ~150 meters), slice and compute remaining distance synchronously
    if (minD < 0.15) {
      const remainingCoords = currentTrackOsrmPath.slice(closestIdx);
      if (remainingCoords.length > 0) {
        if (routePolyline) {
          routePolyline.setLatLngs(remainingCoords);
        }
        let totalRemKm = 0;
        for (let i = 0; i < remainingCoords.length - 1; i++) {
          totalRemKm += calculateHaversineKm(remainingCoords[i][0], remainingCoords[i][1], remainingCoords[i+1][0], remainingCoords[i+1][1]);
        }
        updateEtaBadge(totalRemKm, totalRemKm * 1000);
        return;
      }
    }
  }

  const routeKey = `${startLat.toFixed(4)},${startLng.toFixed(4)}_${destLat.toFixed(4)},${destLng.toFixed(4)}`;
  if (routeKey === lastRouteKey && routePolyline) return;
  lastRouteKey = routeKey;

  const url = `https://router.project-osrm.org/route/v1/driving/${startLng},${startLat};${destLng},${destLat}?overview=full&geometries=geojson`;
  try {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 4000);
    const res = await fetch(url, { signal: controller.signal, headers: { 'Accept': 'application/json' } });
    clearTimeout(timeoutId);

    if (res.ok) {
      const data = await res.json();
      if (data && data.code === 'Ok' && data.routes && data.routes[0] && data.routes[0].geometry && Array.isArray(data.routes[0].geometry.coordinates)) {
        const leafletCoords = data.routes[0].geometry.coordinates.map(pt => [pt[1], pt[0]]);
        currentTrackOsrmPath = leafletCoords;
        if (routePolyline && leafletCoords.length > 0) {
          routePolyline.setLatLngs(leafletCoords);
        }
        if (typeof data.routes[0].distance === 'number') {
          updateEtaBadge(data.routes[0].distance / 1000, data.routes[0].distance);
        }
        return;
      }
    }
  } catch (err) {
    console.warn('OSRM road fetch fallback:', err);
  }

  // Fallback: Street corridor along street axes rather than direct diagonal
  const fallback = generateFallbackStreetCorridor(startLat, startLng, destLat, destLng);
  currentTrackOsrmPath = fallback;
  if (routePolyline) {
    routePolyline.setLatLngs(fallback);
  }
}

let isTrackMapInitialized = false;

function initMap() {
  const startRiderLat = initRiderLat || initStoreLat;
  const startRiderLng = initRiderLng || initStoreLng;

  // Requirement 4: Center map on active rider coordinates if available
  const defaultCenter = initRiderLat ? [initRiderLat, initRiderLng] : (initStoreLat ? [initStoreLat, initStoreLng] : [initDestLat, initDestLng]);
  trackingMap = L.map('map', {
    zoomControl: true,
    attributionControl: false
  }).setView(defaultCenter, 16);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(trackingMap);

  // Store Icon
  const storeIcon = L.divIcon({
    className: 'custom-pin',
    html: `<div style="background:#d97706; color:#ffffff; width:34px; height:34px; border-radius:50%; display:flex; align-items:center; justify-content:center; box-shadow:0 3px 10px rgba(217,119,6,0.4); border:2.5px solid #ffffff; font-size:15px;"><i class="fa-solid fa-store"></i></div>`,
    iconSize: [34, 34],
    iconAnchor: [17, 17]
  });
  storeMarker = L.marker([initStoreLat, initStoreLng], { icon: storeIcon }).addTo(trackingMap);
  storeMarker.bindPopup(`<b><?= htmlspecialchars(addslashes($order['store_name'] ?? 'Store')) ?></b><br><small>Store Pickup Origin</small>`);

  // Customer Destination Icon
  const destIcon = L.divIcon({
    className: 'custom-pin',
    html: `<div style="background:#dc2626; color:#ffffff; width:34px; height:34px; border-radius:50%; display:flex; align-items:center; justify-content:center; box-shadow:0 3px 10px rgba(220,38,38,0.4); border:2.5px solid #ffffff; font-size:15px;"><i class="fa-solid fa-house"></i></div>`,
    iconSize: [34, 34],
    iconAnchor: [17, 17]
  });
  destMarker = L.marker([initDestLat, initDestLng], { icon: destIcon }).addTo(trackingMap);
  destMarker.bindPopup(`<b>Delivery Destination</b><br><small><?= htmlspecialchars(addslashes($order['delivery_address'] ?? 'Customer Location')) ?></small>`);

  // Rider Motorcycle Icon
  const riderIcon = L.divIcon({
    className: 'custom-pin',
    html: `<div style="position:relative; width:42px; height:42px;">
             <div style="position:absolute; inset:0; border-radius:50%; background:rgba(22,163,74,0.35); animation:pulseGlow 1.8s infinite;"></div>
             <div style="position:absolute; inset:2px; border-radius:50%; background:#16a34a; color:#ffffff; display:flex; align-items:center; justify-content:center; box-shadow:0 3px 12px rgba(22,163,74,0.5); border:2.5px solid #ffffff; font-size:18px;"><i class="fa-solid fa-motorcycle"></i></div>
           </div>`,
    iconSize: [42, 42],
    iconAnchor: [21, 21]
  });

  riderMarker = L.marker([startRiderLat, startRiderLng], { icon: riderIcon, zIndexOffset: 1000 }).addTo(trackingMap);
  riderMarker.bindPopup(`<b><?= htmlspecialchars(addslashes($rider_name)) ?></b><br><small>Live Motorcycle GPS</small>`);

  // Connecting road polyline initialized along street corridors
  const initialCorridor = generateFallbackStreetCorridor(startRiderLat, startRiderLng, initDestLat, initDestLng);
  routePolyline = L.polyline(initialCorridor, {
    color: '#4B5320',
    weight: 5,
    opacity: 0.9,
    lineCap: 'round',
    lineJoin: 'round'
  }).addTo(trackingMap);

  fetchOsrmRoadPolyline(startRiderLat, startRiderLng, initDestLat, initDestLng);

  if (initRiderLat) {
    trackingMap.setView([initRiderLat, initRiderLng], 16);
  } else {
    const bounds = L.latLngBounds([ [startRiderLat, startRiderLng], [initDestLat, initDestLng] ]);
    trackingMap.fitBounds(bounds, { padding: [40, 40], maxZoom: 16 });
  }
  isTrackMapInitialized = true;
}

// Exactly 800ms real-time high-frequency GPS telemetry polling loop
let _isPollingLocation = false;
async function pollRiderLocation() {
  if (!orderId || _isPollingLocation) return;
  _isPollingLocation = true;
  try {
    // Append timestamp and no-cache to prevent browser caching on polling requests
    let data = null;
    try {
      const resStatus = await fetch(`get_order_status.php?order_id=${orderId}&_t=${Date.now()}`, {
        cache: 'no-store'
      });
      if (resStatus.ok) data = await resStatus.json();
    } catch (e) {}

    if (!data || !data.success) {
      const res = await fetch(`tracking_api.php?action=get_location&order_id=${orderId}&_t=${Date.now()}`, {
        cache: 'no-store'
      });
      data = await res.json();
    }
    if (!data.success || !data.order) return;

    const ord = data.order;
    const rLat = parseFloat(data.lat ?? data.lat ?? data.rider_lat ?? ord.rider_lat);
    const rLng = parseFloat(data.lng ?? data.lng ?? data.rider_lng ?? ord.rider_lng);
    const dLat = parseFloat(data.destination_lat || ord.destination_lat || initDestLat);
    const dLng = parseFloat(data.destination_lng || ord.destination_lng || initDestLng);

    // Updates riderMarker smoothly via OSRM road animation (700ms duration synchronized with 800ms polling)
    if (riderMarker && !isNaN(rLat) && !isNaN(rLng) && rLat !== 0) {
      animateMarkerTo(riderMarker, rLat, rLng, 700);
      // Snap route polyline via OSRM roads
      fetchOsrmRoadPolyline(rLat, rLng, dLat, dLng);
    }

    // Distance & ETA calculation matching rider dashboard
    let distKm = (typeof data.distance_km === 'number') ? data.distance_km : null;
    if (distKm === null && !isNaN(rLat) && !isNaN(rLng) && rLat !== 0) {
      distKm = calculateHaversineKm(rLat, rLng, dLat, dLng);
    }
    updateEtaBadge(distKm);

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

    // Update Rider contact details if refreshed
    if (data.rider_name) {
      const nameEl = document.getElementById('riderNameDisplay');
      if (nameEl) nameEl.textContent = data.rider_name;
    }
    if (data.rider_phone) {
      const dial = String(data.rider_phone).replace(/[^\+0-9]/g, '');
      const phoneEl = document.getElementById('riderPhoneDisplay');
      if (phoneEl) phoneEl.innerHTML = `<i class="fa-solid fa-phone-volume"></i> ${data.rider_phone}`;
      const callLink = document.getElementById('btnCallRider');
      if (callLink) callLink.href = `tel:${dial}`;
      const smsLink = document.getElementById('btnSmsRider');
      if (smsLink) smsLink.href = `sms:${dial}`;
    }

  } catch (err) {
    console.warn('Poll tracking error:', err);
  } finally {
    _isPollingLocation = false;
  }
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
  if (!call) return;

  const clientRole = 'customer';
  const callerRole = String(call.caller_role || call.caller || 'rider').toLowerCase().trim();

  // If this customer initiated the call (outgoing call), do NOT display incoming call modal!
  if (callerRole === clientRole) {
    dismissIncomingCallIfRinging();
    return;
  }

  // Explicitly render Rider's correct profile name and details instantly without name flashing
  const callerName = call.caller_name || '<?= htmlspecialchars(addslashes($rider_name)) ?>' || 'Alex Rider';
  const callerPhone = call.caller_phone || '<?= htmlspecialchars(addslashes($order['rider_phone'] ?? '')) ?>' || '+63 917 555 0192';

  const modal = document.getElementById('incomingCallModal');
  if (!modal) return;

  const nameEl = document.getElementById('incomingCallerName');
  if (nameEl) nameEl.textContent = callerName;

  const phoneEl = document.getElementById('incomingCallerPhone');
  if (phoneEl) phoneEl.textContent = callerPhone;

  const statusEl = document.getElementById('incomingCallStatus');
  if (statusEl) statusEl.textContent = `${callerName} (Rider) is calling regarding your delivery...`;

  if (currentIncomingCall && currentIncomingCall.call_id === call.call_id && modal.style.display === 'flex') {
    return;
  }
  currentIncomingCall = call;

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

let seenRiderMessageIds = new Set();
let unreadChatCount = 0;

function updateTrackChatBadges() {
  const badge = document.getElementById('trackChatBadge');
  if (!badge) return;
  if (unreadChatCount > 0) {
    badge.textContent = unreadChatCount;
    badge.style.display = 'inline-flex';
  } else {
    badge.style.display = 'none';
  }
}

function openTrackChat() {
  const modal = document.getElementById('trackChatModal');
  if (modal) modal.style.display = 'flex';
  unreadChatCount = 0;
  updateTrackChatBadges();
  loadTrackChatMessages();
}

function closeTrackChat() {
  const modal = document.getElementById('trackChatModal');
  if (modal) modal.style.display = 'none';
}

function escapeTrackHtml(str) {
  if (!str) return '';
  return String(str).replace(/[&<>"']/g, m => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  })[m]);
}

function appendMessageToTrackChatDrawer(msg) {
  const list = document.getElementById('trackChatMessagesList');
  if (!list) return;

  const hint = list.querySelector('.chat-loading-hint');
  if (hint) hint.remove();

  const existing = list.querySelector(`[data-msg-id="${msg.message_id}"]`);
  if (existing) return;

  const isMe = msg.sender_role === 'customer';
  const bubble = document.createElement('div');
  bubble.className = `msg-bubble ${isMe ? 'me' : 'them'}`;
  bubble.setAttribute('data-msg-id', msg.message_id);
  bubble.innerHTML = `
    <div>${escapeTrackHtml(msg.message_text)}</div>
    <div class="msg-meta">${escapeTrackHtml(msg.sender_name || (isMe ? 'You' : 'Rider'))} • ${escapeTrackHtml(msg.time_formatted || 'Just now')}</div>
  `;
  list.appendChild(bubble);
  list.scrollTop = list.scrollHeight;
}

async function loadTrackChatMessages() {
  if (!orderId) return;
  try {
    const res = await fetch(`order_communication_api.php?action=get_updates&order_id=${orderId}&after_message_id=0&_t=${Date.now()}`);
    const data = await res.json();
    if (data.success && Array.isArray(data.messages)) {
      const list = document.getElementById('trackChatMessagesList');
      if (list) list.innerHTML = '';
      data.messages.forEach(m => appendMessageToTrackChatDrawer(m));
    }
  } catch (e) {}
}

async function sendTrackChatMessage(e) {
  e.preventDefault();
  const input = document.getElementById('trackChatInput');
  const btn = document.getElementById('trackChatSendBtn');
  if (!input) return;
  const text = input.value.trim();
  if (!text) return;

  input.value = '';
  if (btn) btn.disabled = true;

  try {
    const res = await fetch('order_communication_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'send_message',
        order_id: orderId,
        message_text: text
      })
    });
    const data = await res.json();
    if (data.success) {
      appendMessageToTrackChatDrawer({
        message_id: data.message_id || ('local_' + Date.now()),
        sender_role: 'customer',
        sender_name: 'You',
        message_text: text,
        time_formatted: 'Just now'
      });
    }
  } catch (err) {
    console.error('Send message error:', err);
  } finally {
    if (btn) btn.disabled = false;
    input.focus();
  }
}

// -------------------------------------------------------------
// WEB AUDIO & BASE64 AUDIO CHIME
// -------------------------------------------------------------
const CUSTOMER_CHIME_B64_URI = 'data:audio/wav;base64,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';

function playCustomerChime() {
  try {
    const audio = new Audio(CUSTOMER_CHIME_B64_URI);
    audio.volume = 0.85;
    const playPromise = audio.play();
    if (playPromise !== undefined) {
      playPromise.catch(() => {});
    }
  } catch (e) {}

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

function handleLiveRiderMessage(msg) {
  if (!msg || !msg.message_id) return;
  const msgKey = 'msg_' + msg.message_id;
  if (window.displayedNotificationIds.has(msgKey)) return;
  window.displayedNotificationIds.add(msgKey);

  if (msg.message_id > lastSeenRiderMsgId) {
    lastSeenRiderMsgId = msg.message_id;
  }

  const senderName = msg.sender_name || 'Alex';

  // Dismiss any existing toast / banner (strict single-toast queue)
  document.querySelectorAll('.live-toast-banner, .cust-toast').forEach(el => el.remove());

  // Show top floating push banner below header
  const banner = document.getElementById('liveRiderMessageBanner');
  const titleEl = document.getElementById('liveMsgBannerTitle');
  const textEl = document.getElementById('liveMsgBannerText');
  if (banner && textEl) {
    if (titleEl) titleEl.textContent = `MESSAGE FROM RIDER (${senderName})`;
    textEl.textContent = msg.message_text;
    banner.style.display = 'flex';

    if (msgBannerTimeout) clearTimeout(msgBannerTimeout);
    msgBannerTimeout = setTimeout(() => {
      banner.style.display = 'none';
    }, 4000);
  }

  // Play an alert notification sound
  playCustomerChime();

  // Update the unread badge counter in real time and append to chat drawer
  unreadChatCount++;
  updateTrackChatBadges();
  appendMessageToTrackChatDrawer(msg);
}

let hasShownArrivedAlert = false;
let arrivalAlertAudio = null;
let trackSynthLoopInterval = null;
let trackSynthAudioCtx = null;

function playSynthArrivalAlarm() {
  if (window.FoodDashCall && typeof window.FoodDashCall.playSynthArrivalAlarm === 'function') {
    window.FoodDashCall.playSynthArrivalAlarm();
    return;
  }
  try {
    const AudioCtxClass = window.AudioContext || window.webkitAudioContext;
    if (!AudioCtxClass) return;
    if (!trackSynthAudioCtx) {
      trackSynthAudioCtx = new AudioCtxClass();
    }
    if (trackSynthAudioCtx.state === 'suspended') {
      trackSynthAudioCtx.resume().catch(() => {});
    }

    if (trackSynthLoopInterval) {
      clearInterval(trackSynthLoopInterval);
      trackSynthLoopInterval = null;
    }

    function chimeTick() {
      try {
        if (!trackSynthAudioCtx) return;
        if (trackSynthAudioCtx.state === 'suspended') {
          trackSynthAudioCtx.resume().catch(() => {});
        }
        const now = trackSynthAudioCtx.currentTime;
        const notes = [
          { freq: 659.25, time: 0.00, dur: 0.45 },
          { freq: 830.61, time: 0.16, dur: 0.45 },
          { freq: 987.77, time: 0.32, dur: 0.45 },
          { freq: 1318.51, time: 0.48, dur: 0.65 }
        ];
        notes.forEach(n => {
          const osc = trackSynthAudioCtx.createOscillator();
          const gain = trackSynthAudioCtx.createGain();
          osc.type = 'triangle';
          osc.frequency.setValueAtTime(n.freq, now + n.time);

          gain.gain.setValueAtTime(0.001, now + n.time);
          gain.gain.linearRampToValueAtTime(0.28, now + n.time + 0.03);
          gain.gain.exponentialRampToValueAtTime(0.001, now + n.time + n.dur);

          osc.connect(gain);
          gain.connect(trackSynthAudioCtx.destination);
          osc.start(now + n.time);
          osc.stop(now + n.time + n.dur + 0.05);
        });
      } catch (err) {}
    }

    chimeTick();
    trackSynthLoopInterval = setInterval(chimeTick, 1800);
  } catch (e) {
    console.warn('Track synth chime error:', e);
  }
}
window.playSynthArrivalAlarm = playSynthArrivalAlarm;

function stopArrivalAlertSound() {
  if (trackSynthLoopInterval) {
    clearInterval(trackSynthLoopInterval);
    trackSynthLoopInterval = null;
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
    if (typeof FoodDashCall.stopArrivalAlarmAudio === 'function') {
      FoodDashCall.stopArrivalAlarmAudio();
    } else if (typeof FoodDashCall.stopAllTones === 'function') {
      FoodDashCall.stopAllTones();
    }
  }
}
window.stopArrivalAlertSound = stopArrivalAlertSound;

function closeRiderArrivedModal() {
  stopArrivalAlertSound();
  const modal = document.getElementById('riderArrivedModal');
  if (modal) {
    modal.style.display = 'none';
    modal.classList.remove('show', 'active');
  }
  if (window.FoodDashCall) {
    FoodDashCall.stopAllTones();
  }
}

function respondToRiderArrival(responseOption) {
  stopArrivalAlertSound();
  if (window.FoodDashCall) {
    FoodDashCall.acknowledgeArrival(orderId, responseOption);
  }
  closeRiderArrivedModal();
}

function attachTrackArrivalModalListeners() {
  const modal = document.getElementById('riderArrivedModal');
  if (!modal) return;
  const buttons = modal.querySelectorAll('.btn-arrival-action');
  buttons.forEach(btn => {
    if (btn.dataset.arrivalAudioBound === 'true') return;
    btn.dataset.arrivalAudioBound = 'true';
    btn.addEventListener('click', function() {
      stopArrivalAlertSound();
    });
  });
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', attachTrackArrivalModalListeners);
  } else {
    attachTrackArrivalModalListeners();
  }
}

function initiateCustomerVoiceCall() {
  const rName = document.getElementById('riderNameDisplay')?.textContent?.trim() || 'Rider';
  const rPhone = document.getElementById('riderPhoneDisplay')?.textContent?.trim() || '+63 917 555 0192';
  if (window.FoodDashCall) {
    FoodDashCall.initiateCall(orderId, rName, rPhone);
  }
}

function playArrivalAlertSound() {
  if (window.FoodDashCall && typeof FoodDashCall.playArrivalAlarmAudio === 'function') {
    FoodDashCall.playArrivalAlarmAudio();
    return;
  }

  let synthFallbackTriggered = false;
  function triggerFallback(reason) {
    if (synthFallbackTriggered) return;
    synthFallbackTriggered = true;
    console.warn('Arrival alert audio file fallback triggered:', reason);
    if (arrivalAlertAudio) {
      try { arrivalAlertAudio.pause(); } catch (e) {}
    }
    playSynthArrivalAlarm();
  }

  try {
    const soundPath = (function() {
      try {
        if (window.location.pathname.includes('/food_dash/')) {
          return window.location.origin + '/food_dash/assets/arrival_alarm.wav';
        }
      } catch (e) {}
      return 'assets/arrival_alarm.wav';
    })();

    if (!arrivalAlertAudio) {
      if (window.arrivalAlertAudio) {
        arrivalAlertAudio = window.arrivalAlertAudio;
      } else {
        arrivalAlertAudio = new Audio(soundPath);
        window.arrivalAlertAudio = arrivalAlertAudio;
      }
    }

    if (arrivalAlertAudio.error || arrivalAlertAudio.networkState === 3) {
      triggerFallback('Audio element in error state');
    }

    arrivalAlertAudio.onerror = function() {
      triggerFallback('arrivalAlertAudio.onerror fired');
    };
    arrivalAlertAudio.onstalled = function() {
      triggerFallback('arrivalAlertAudio.onstalled fired');
    };

    arrivalAlertAudio.loop = true;
    arrivalAlertAudio.volume = 0.95;
    arrivalAlertAudio.currentTime = 0;

    const playPromise = arrivalAlertAudio.play();
    if (playPromise !== undefined) {
      playPromise.then(() => {
        if (trackSynthLoopInterval && !synthFallbackTriggered) {
          clearInterval(trackSynthLoopInterval);
          trackSynthLoopInterval = null;
        }
      }).catch((e) => {
        triggerFallback('arrivalAlertAudio.play() rejected: ' + e);
      });
    }

    // Safety watchdog for mobile browsers
    setTimeout(() => {
      if (!arrivalAlertAudio || arrivalAlertAudio.paused || arrivalAlertAudio.currentTime === 0 || arrivalAlertAudio.error) {
        triggerFallback('Mobile audio stall watchdog timeout');
      }
    }, 1000);

  } catch (e) {
    triggerFallback('Synchronous playArrivalAlertSound exception: ' + e);
  }
}
window.playArrivalAlertSound = playArrivalAlertSound;

function handleRiderArrivedEvent(data) {
  if (hasShownArrivedAlert && window._trackOrderPrevStatus && ['ARRIVED', 'ARRIVED_DESTINATION', 'arrived', 'arrived_destination'].includes(window._trackOrderPrevStatus)) return;
  hasShownArrivedAlert = true;

  const rName = data.rider_name || 'Alex';

  // Dismiss top notification banner completely upon arrival: ONLY render center arrival modal
  const banner = document.getElementById('liveRiderMessageBanner');
  if (banner) {
    banner.style.display = 'none';
    banner.classList.remove('slide-down', 'show');
  }
  if (msgBannerTimeout) {
    clearTimeout(msgBannerTimeout);
    msgBannerTimeout = null;
  }

  // Trigger persistent looping arrival alarm / arrival modal
  const modal = document.getElementById('riderArrivedModal');
  if (modal) {
    modal.classList.add('show', 'active');
    modal.style.display = 'flex';
    modal.style.visibility = 'visible';
    modal.style.pointerEvents = 'auto';
  }
  if (window.FoodDashCall) {
    FoodDashCall.triggerArrivalAlarm(orderId, rName);
  }

  // Programmatically play persistent looping arrival audio alert sound immediately
  playArrivalAlertSound();

  // Update status badge on page to Arrived
  const statusPills = document.querySelectorAll('.status-pill');
  statusPills.forEach(pill => {
    pill.style.background = '#dcfce7';
    pill.style.color = '#15803d';
    pill.style.borderColor = '#86efac';
    pill.innerHTML = '<i class="fa-solid fa-location-pin fa-bounce"></i> <span>Arrived</span>';
  });

  const etaBadgeText = document.getElementById('etaBadgeText');
  if (etaBadgeText) {
    etaBadgeText.textContent = 'Your rider has arrived outside! 🛵';
  }
  const distPillText = document.getElementById('distPillText');
  if (distPillText) {
    distPillText.textContent = '0m (Arrived)';
  }
}

// 800ms background polling loop checking order_communication_api.php
async function pollOrderCommunication() {
  if (!orderId) return;
  try {
    const res = await fetch(`order_communication_api.php?action=get_updates&customer_id=<?= (int)$user_id ?>&order_id=${orderId}&after_message_id=${lastSeenRiderMsgId}&_t=${Date.now()}`, {
      cache: 'no-store'
    });
    const data = await res.json();
    if (!data.success) return;

    // Synchronize Real-Time Bidirectional In-App Calling
    if (window.FoodDashCall) {
      FoodDashCall.syncCallStatus(data.active_call);
    }

    // Detect active incoming call where status is ringing from rider
    if (data.active_call && (data.active_call.status === 'ringing' || data.active_call.call_status === 'ringing') && (data.active_call.caller === 'rider' || data.active_call.caller_role === 'rider')) {
      handleIncomingCallAlert(data.active_call);
    } else if (!data.active_call || ['idle', 'ended', 'declined', 'missed'].includes(data.active_call.status || data.active_call.call_status)) {
      dismissIncomingCallIfRinging();
    }

    if (Array.isArray(data.messages) && data.messages.length > 0) {
      data.messages.forEach(msg => {
        if (msg.sender_role === 'rider') {
          handleLiveRiderMessage(msg);
        } else if (msg.sender_role === 'customer') {
          appendMessageToTrackChatDrawer(msg);
        }
      });
    } else if (data.latest_banner && data.latest_banner.sender_role === 'rider') {
      handleLiveRiderMessage(data.latest_banner);
    }

    // Automated "Rider Arrived" Live Notification with exact state-transition detection
    const sNorm = String(data.status || '').toUpperCase();
    const dNorm = String(data.delivery_status || '').toLowerCase();
    const isArrivedNow = Boolean(data.is_arrived) || ['arrived', 'arrived_destination'].includes(dNorm) || ['ARRIVED', 'ARRIVED_DESTINATION'].includes(sNorm);
    const wasArrivedBefore = window._trackOrderPrevStatus && ['ARRIVED', 'ARRIVED_DESTINATION', 'arrived', 'arrived_destination'].includes(window._trackOrderPrevStatus);

    if (isArrivedNow) {
      if (data.arrival_acknowledged) {
        if (window.FoodDashCall) FoodDashCall.stopAllTones();
      } else if (!wasArrivedBefore || !hasShownArrivedAlert) {
        handleRiderArrivedEvent(data);
      }
    }
    window._trackOrderPrevStatus = data.delivery_status || data.status;
  } catch (e) {}
}

document.addEventListener('DOMContentLoaded', () => {
  initMap();
  pollRiderLocation();
  pollOrderCommunication();
  // Continuous 800ms ultra-responsive polling loop
  pollTimer = setInterval(() => {
    pollRiderLocation();
    pollOrderCommunication();
  }, 800);
});
</script>
</body>
</html>


