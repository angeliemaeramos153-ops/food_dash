<?php
session_start();

// Direct access shows a confirmation screen. Dashboard links use ?confirm=1.
if (!isset($_GET['confirm']) || $_GET['confirm'] !== '1') {
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover"><title>Food Dash - Confirm Logout</title><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"><style>@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');*{box-sizing:border-box;font-family:Poppins,Arial,sans-serif}html,body{width:100%;min-height:100%;margin:0;padding:0;-webkit-text-size-adjust:100%}body{margin:0;min-height:100vh;display:flex;justify-content:center;align-items:center;background:#ece7d3;color:#29300f;padding:20px}.app{width:min(440px,100%);min-height:100vh;background:#fffdf7;display:flex;align-items:center;justify-content:center;padding:22px}.card{width:100%;max-width:360px;background:#fffdf7;border:1px solid #e7dfc4;border-radius:24px;padding:26px 22px;text-align:center;box-shadow:0 20px 60px rgba(55,65,22,.13)}.logo{width:70px;height:70px;border-radius:50%;object-fit:cover;border:2px solid #d7c98d}.card h1{font-size:20px;margin:14px 0 6px;color:#344016}.card p{font-size:10px;color:#74776a;line-height:1.5;margin:0 0 20px}.actions{display:flex;gap:9px}.actions a{flex:1;text-decoration:none;padding:11px;border-radius:12px;font-size:10px;font-weight:700}.cancel{background:#eee9d7;color:#4f5d21}.yes{background:#4f5d21;color:#fff}.brand{font-size:9px;color:#74776a;margin-top:15px;letter-spacing:1px}</style></head><body><div class="app"><div class="card"><img class="logo" src="logo.jpg" alt="Food Dash"><h1>Log out of Food Dash?</h1><p>Your current session will be signed out. You can log in again anytime.</p><div class="actions"><a class="cancel" href="javascript:history.back()">Cancel</a><a class="yes" href="logout.php?confirm=1">Yes, Log Out</a></div><div class="brand">FOOD DASH</div></div></div></body></html>
<?php
exit();
}

require_once 'db_connect.php';
require_once 'notification_lib.php';

$uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$uname = $_SESSION['username'] ?? $_SESSION['full_name'] ?? null;
$urole = $_SESSION['role'] ?? null;

if ($uid !== null && $uid > 0) {
    log_activity($conn, $uid, 'LOGOUT', "User {$uname} securely logged out.", $uname, $urole);
}

$_SESSION = array();
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();
echo '<!doctype html><html><head><meta charset="utf-8"></head><body><script>' .
    'try { localStorage.removeItem("foodDashCart"); localStorage.removeItem("foodDashDeliveryAddress"); localStorage.removeItem("foodDashDeliveryCoords"); } catch (error) {} ' .
    'window.location.replace("index.php?msg=logged_out");' .
    '</script></body></html>';
exit();
?>
