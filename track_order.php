<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Customer';

$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

// If no order_id provided, find latest active order for this customer
if ($order_id <= 0) {
    $stmt = $conn->prepare("SELECT order_id FROM food_orders 
        WHERE customer_id = :uid 
        ORDER BY CASE 
            WHEN status IN ('out_for_delivery', 'on_the_way', 'on_delivery', 'near_customer') THEN 1 
            WHEN status IN ('preparing', 'ready_for_pickup') THEN 2 
            ELSE 3 END ASC, order_id DESC LIMIT 1");
    $stmt->execute([':uid' => $user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $order_id = (int)$row['order_id'];
    }
}

// Fetch order information
$order = null;
if ($order_id > 0) {
    $stmt = $conn->prepare("SELECT 
        o.order_id, o.order_ref, o.store_name, o.delivery_address, 
        o.status, o.total_amount, o.created_at,
        COALESCE(o.store_lat, s.latitude, s.lat, 11.00842) AS store_lat,
        COALESCE(o.store_lng, s.longitude, s.lng, 124.60721) AS store_lng,
        COALESCE(o.delivery_lat, o.customer_lat, 11.00762) AS destination_lat,
        COALESCE(o.delivery_lng, o.customer_lng, 124.60749) AS destination_lng,
        COALESCE(o.rider_lat, r.current_lat) AS rider_lat,
        COALESCE(o.rider_lng, r.current_lng) AS rider_lng,
        COALESCE(r.rider_name, ru.full_name, 'Alex Rider') AS rider_name,
        COALESCE(o.rider_phone, r.phone, ru.phone, '+63 917 555 0192') AS rider_phone
    FROM food_orders o
    LEFT JOIN shops s ON (o.shop_id = s.shop_id OR (o.shop_id = 0 AND LOWER(TRIM(o.store_name)) = LOWER(TRIM(s.shop_name)) COLLATE utf8mb4_unicode_ci))
    LEFT JOIN riders r ON (o.rider_id = r.rider_id)
    LEFT JOIN users ru ON (r.user_id = ru.user_id)
    WHERE o.order_id = :oid AND (o.customer_id = :uid OR :is_admin = 1)
    LIMIT 1");
    $isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') ? 1 : 0;
    $stmt->execute([':oid' => $order_id, ':uid' => $user_id, ':is_admin' => $isAdmin]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
}

$raw_status = strtoupper(trim(str_replace([' ', '-'], '_', $order['status'] ?? 'ON_DELIVERY')));
$status_label = ucwords(strtolower(str_replace('_', ' ', $raw_status)));
$rider_phone = $order['rider_phone'] ?? '+63 917 555 0192';
$rider_dial = preg_replace('/[^\+0-9]/', '', $rider_phone) ?: '+639175550192';
$rider_name = $order['rider_name'] ?? 'Alex Rider';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Live Delivery Tracking - <?= htmlspecialchars($order['order_ref'] ?? 'Food Dash') ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
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
body { background: #f3f0e6; color: var(--ink); min-height: 100vh; display: flex; justify-content: center; }
.app-container { width: 100%; max-width: 480px; background: #ffffff; min-height: 100vh; display: flex; flex-direction: column; box-shadow: 0 0 24px rgba(75, 83, 32, 0.12); position: relative; }

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

  <!-- Header -->
  <header class="track-header">
    <a href="customer_dashboard.php?screen=orders" class="back-btn" title="Back to Orders">
      <i class="fa-solid fa-arrow-left"></i>
    </a>
    <div class="header-title">
      <h2><?= htmlspecialchars($order['order_ref'] ?? 'Live Delivery') ?></h2>
      <small><?= htmlspecialchars($order['store_name'] ?? 'Store') ?></small>
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
      <small style="font-size:10px; color:var(--muted); text-align:right;">Assigned Rider<br><span style="color:#2e7d32; font-weight:600;"><i class="fa-solid fa-shield-halved"></i> Verified</span></small>
    </div>
    <div class="contact-actions">
      <a id="btnCallRider" href="tel:<?= htmlspecialchars($rider_dial) ?>" class="comm-btn call" title="Direct Phone Call">
        <i class="fa-solid fa-phone"></i> Call Rider
      </a>
      <a id="btnMsgRider" href="customer_dashboard.php?track=<?= (int)$order_id ?>" class="comm-btn msg" title="Open In-App Chat">
        <i class="fa-solid fa-comment-dots"></i> Message Rider
      </a>
      <a id="btnSmsRider" href="sms:<?= htmlspecialchars($rider_dial) ?>?body=Hi%20<?= urlencode($rider_name) ?>,%20regarding%20order%20<?= urlencode($order['order_ref'] ?? '') ?>" class="comm-btn sms" title="Direct Native SMS">
        <i class="fa-solid fa-envelope"></i> SMS
      </a>
    </div>
  </div>

</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const orderId = <?= (int)$order_id ?>;
const initStoreLat = <?= (float)($order['store_lat'] ?? 11.00842) ?>;
const initStoreLng = <?= (float)($order['store_lng'] ?? 124.60721) ?>;
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
  const routeKey = `${startLat.toFixed(5)},${startLng.toFixed(5)}_${destLat.toFixed(5)},${destLng.toFixed(5)}`;
  if (routeKey === lastRouteKey && routePolyline) return;
  lastRouteKey = routeKey;

  const url = `https://router.project-osrm.org/route/v1/driving/${startLng},${startLat};${destLng},${destLat}?overview=full&geometries=geojson`;
  try {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 6000);
    const res = await fetch(url, { signal: controller.signal, headers: { 'Accept': 'application/json' } });
    clearTimeout(timeoutId);

    if (res.ok) {
      const data = await res.json();
      if (data && data.code === 'Ok' && data.routes && data.routes[0] && data.routes[0].geometry && Array.isArray(data.routes[0].geometry.coordinates)) {
        const leafletCoords = data.routes[0].geometry.coordinates.map(pt => [pt[1], pt[0]]);
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
  if (routePolyline) {
    routePolyline.setLatLngs(fallback);
  }
}

function initMap() {
  const defaultCenter = [initDestLat, initDestLng];
  trackingMap = L.map('map', {
    zoomControl: true,
    attributionControl: false
  }).setView(defaultCenter, 15);

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

  const startRiderLat = initRiderLat || initStoreLat;
  const startRiderLng = initRiderLng || initStoreLng;

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

  const bounds = L.latLngBounds([ [startRiderLat, startRiderLng], [initDestLat, initDestLng] ]);
  trackingMap.fitBounds(bounds, { padding: [40, 40], maxZoom: 16 });
}

// 2-second real-time polling loop
async function pollRiderLocation() {
  if (!orderId) return;
  try {
    // Append timestamp and no-cache to prevent browser caching on polling requests
    const res = await fetch(`tracking_api.php?action=get_location&order_id=${orderId}&_t=${Date.now()}`, {
      cache: 'no-store'
    });
    const data = await res.json();
    if (!data.success || !data.order) return;

    const ord = data.order;
    const rLat = parseFloat(data.latitude ?? data.lat ?? data.rider_lat ?? ord.rider_lat);
    const rLng = parseFloat(data.longitude ?? data.lng ?? data.rider_lng ?? ord.rider_lng);
    const dLat = parseFloat(data.destination_lat || ord.destination_lat || initDestLat);
    const dLng = parseFloat(data.destination_lng || ord.destination_lng || initDestLng);

    // Updates riderMarker smoothly via cubic ease-out animation
    if (riderMarker && !isNaN(rLat) && !isNaN(rLng) && rLat !== 0) {
      animateMarkerTo(riderMarker, rLat, rLng, 1400);
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
    if (data.active_call && data.active_call.status === 'ringing') {
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
  window.location.href = `customer_dashboard.php?track=${orderId}`;
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

document.addEventListener('DOMContentLoaded', () => {
  initMap();
  pollRiderLocation();
  // 2-second polling loop
  pollTimer = setInterval(pollRiderLocation, 2000);
});
</script>
</body>
</html>
