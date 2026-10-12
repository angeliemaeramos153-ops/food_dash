<?php
session_start();
require_once 'db_connect.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired']);
    exit;
}
$user_id = (int)$_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    
    // Quick unread count check (PostgreSQL boolean: is_read = FALSE)
    $stmt2 = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id = :uid AND is_read = FALSE");
    $stmt2->execute([':uid' => $user_id]);
    $unread = (int)($stmt2->fetchColumn() ?: 0);
    
    if ($action === 'get_unread_count') {
        echo json_encode(['success' => true, 'unread' => $unread, 'unread_count' => $unread]);
        exit;
    }

    $limit = min(50, max(1, (int)($_GET['limit'] ?? 30)));
    $stmt = $conn->prepare("SELECT notification_id, order_id, notification_type, title, message, is_read, TO_CHAR(created_at, 'Mon DD, YYYY • HH12:MI AM') AS created_at FROM notifications WHERE user_id = :uid ORDER BY notification_id DESC LIMIT :lim");
    $stmt->bindValue(':uid', $user_id, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $is_read_bool = (!empty($r['is_read']) && $r['is_read'] !== 'f' && $r['is_read'] !== false);
        $r['status'] = $is_read_bool ? 'read' : 'unread';
        $r['is_read'] = $is_read_bool ? 1 : 0;
    }
    unset($r);

    echo json_encode([
        'success' => true,
        'notifications' => $rows,
        'unread' => $unread,
        'unread_count' => $unread
    ]);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = $payload['action'] ?? '';
if ($action === 'mark_read') {
    $id = (int)($payload['notification_id'] ?? 0);
    $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE notification_id = :nid AND user_id = :uid");
    $ok = $stmt->execute([':nid' => $id, ':uid' => $user_id]);
    echo json_encode(['success' => (bool)$ok]);
    exit;
}
if ($action === 'mark_all_read') {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = :uid AND is_read = FALSE");
    $ok = $stmt->execute([':uid' => $user_id]);
    echo json_encode(['success' => (bool)$ok]);
    exit;
}
echo json_encode(['success' => false, 'message' => 'Unknown notification action']);
?>
