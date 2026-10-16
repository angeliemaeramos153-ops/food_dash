<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once 'db_connect.php';
require_once 'email_template_helper.php';

$action = $_REQUEST['action'] ?? 'list';

function relative_time($datetime) {
    if (!$datetime) return 'Recently';
    $time = strtotime($datetime);
    $diff = time() - $time;
    if ($diff < 10) return 'Just now';
    if ($diff < 60) return $diff . 's ago';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return date('M j', $time);
}

try {
    // 1. Send Password Reset
    if ($action === 'send_reset') {
        $email = trim($_POST['email'] ?? $_GET['email'] ?? '');
        if (empty($email)) {
            echo json_encode(['success' => false, 'message' => 'Please provide an email address.']);
            exit;
        }

        $res = create_password_reset_request($conn, $email);
        echo json_encode($res);
        exit;
    }

    // 2. Get List of Emails
    if ($action === 'list') {
        $filter_email = trim($_GET['email'] ?? '');
        
        $sql = "SELECT id, recipient, subject, body_html, token, otp_code, sent_at, is_read FROM system_emails";
        $params = [];
        
        if (!empty($filter_email) && $filter_email !== 'all') {
            $sql .= " WHERE LOWER(recipient) = LOWER(:em)";
            $params[':em'] = $filter_email;
        }
        $sql .= " ORDER BY sent_at DESC, id DESC LIMIT 50";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $raw_emails = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate unread count
        $unread_sql = "SELECT COUNT(*) FROM system_emails WHERE is_read = 0";
        if (!empty($filter_email) && $filter_email !== 'all') {
            $unread_sql .= " AND LOWER(recipient) = LOWER(:em)";
            $u_stmt = $conn->prepare($unread_sql);
            $u_stmt->execute([':em' => $filter_email]);
        } else {
            $u_stmt = $conn->query($unread_sql);
        }
        $unread_count = (int)$u_stmt->fetchColumn();

        $emails = [];
        foreach ($raw_emails as $row) {
            // Extract snippet from HTML
            $clean_body = strip_tags($row['body_html']);
            $clean_body = preg_replace('/\s+/', ' ', $clean_body);
            $snippet = mb_substr(trim($clean_body), 0, 110) . '...';

            $emails[] = [
                'id' => (int)$row['id'],
                'recipient' => $row['recipient'],
                'subject' => $row['subject'],
                'snippet' => $snippet,
                'token' => $row['token'],
                'otp_code' => $row['otp_code'],
                'sent_at' => $row['sent_at'],
                'time_ago' => relative_time($row['sent_at']),
                'is_read' => (int)$row['is_read']
            ];
        }

        echo json_encode([
            'success' => true,
            'emails' => $emails,
            'unread_count' => $unread_count,
            'total_count' => count($emails)
        ]);
        exit;
    }

    // 3. Get Single Email
    if ($action === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid email ID.']);
            exit;
        }

        $stmt = $conn->prepare("SELECT * FROM system_emails WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $email = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$email) {
            echo json_encode(['success' => false, 'message' => 'Email not found.']);
            exit;
        }

        // Mark as read
        if ((int)$email['is_read'] === 0) {
            $conn->prepare("UPDATE system_emails SET is_read = 1 WHERE id = :id")->execute([':id' => $id]);
            $email['is_read'] = 1;
        }

        $email['time_ago'] = relative_time($email['sent_at']);
        $email['formatted_date'] = date('l, F j, Y \a\t g:i A', strtotime($email['sent_at']));

        echo json_encode([
            'success' => true,
            'email' => $email
        ]);
        exit;
    }

    // 4. Mark Read / Unread
    if ($action === 'mark_read') {
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if ($id > 0) {
            $conn->prepare("UPDATE system_emails SET is_read = 1 WHERE id = :id")->execute([':id' => $id]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // 5. Unread Count Quick Poll
    if ($action === 'unread_count') {
        $filter_email = trim($_GET['email'] ?? '');
        $sql = "SELECT COUNT(*) FROM system_emails WHERE is_read = 0";
        $params = [];
        if (!empty($filter_email) && $filter_email !== 'all') {
            $sql .= " AND LOWER(recipient) = LOWER(:em)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':em' => $filter_email]);
        } else {
            $stmt = $conn->query($sql);
        }
        $count = (int)$stmt->fetchColumn();
        echo json_encode(['success' => true, 'unread_count' => $count]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
