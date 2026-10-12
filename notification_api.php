<?php
session_start();
require_once 'db_connect.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['success'=>false,'message'=>'Session expired']); exit;
}
$user_id = (int)$_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    
    // Quick unread count check
    $stmt2 = $conn->prepare("SELECT COUNT(*) c FROM notifications WHERE user_id=? AND is_read=0");
    $stmt2->bind_param('i', $user_id);
    $stmt2->execute();
    $unread = (int)($stmt2->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt2->close();
    
    if ($action === 'get_unread_count') {
        echo json_encode(['success' => true, 'unread' => $unread, 'unread_count' => $unread]);
        exit;
    }

    $limit = min(50, max(1, (int)($_GET['limit'] ?? 30)));
    $stmt = $conn->prepare("SELECT notification_id, order_id, notification_type, title, message, is_read, DATE_FORMAT(created_at, '%b %d, %Y • %h:%i %p') created_at FROM notifications WHERE user_id=? ORDER BY notification_id DESC LIMIT ?");
    $stmt->bind_param('ii', $user_id, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $r['status'] = ((int)$r['is_read'] === 0) ? 'unread' : 'read';
        $rows[] = $r;
    }
    $stmt->close();

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
    $id=(int)($payload['notification_id']??0);
    $stmt=$conn->prepare("UPDATE notifications SET is_read=1 WHERE notification_id=? AND user_id=?");
    $stmt->bind_param('ii',$id,$user_id); $ok=$stmt->execute(); $stmt->close();
    echo json_encode(['success'=>$ok]); exit;
}
if ($action === 'mark_all_read') {
    $stmt=$conn->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0");
    $stmt->bind_param('i',$user_id); $ok=$stmt->execute(); $stmt->close();
    echo json_encode(['success'=>$ok]); exit;
}
echo json_encode(['success'=>false,'message'=>'Unknown notification action']);
?>
