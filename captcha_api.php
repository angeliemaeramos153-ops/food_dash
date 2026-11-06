<?php
/**
 * Food Dash - CAPTCHA Interactive AJAX API
 * Provides endpoints for dynamic CAPTCHA challenge refresh and status check
 */
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/captcha_lib.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? 'refresh');

switch ($action) {
    case 'refresh':
        $challenge = generate_captcha_challenge(true);
        echo json_encode([
            'status'     => 'success',
            'type'       => $challenge['type'],
            'display'    => $challenge['display'],
            'question'   => $challenge['question'],
            'audio_text' => $challenge['audio_text'] ?? $challenge['question']
        ]);
        exit;

    case 'check':
        $username = trim((string)($_GET['username'] ?? ($_POST['username'] ?? '')));
        $required = is_captcha_required($conn, $username);
        $payload = [
            'status'           => 'success',
            'captcha_required' => $required,
            'failed_attempts'  => (int)($_SESSION['login_attempts'] ?? 0)
        ];
        if ($required) {
            $challenge = generate_captcha_challenge(false);
            $payload['challenge'] = [
                'type'       => $challenge['type'],
                'display'    => $challenge['display'],
                'question'   => $challenge['question'],
                'audio_text' => $challenge['audio_text'] ?? $challenge['question']
            ];
        }
        echo json_encode($payload);
        exit;

    default:
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        exit;
}
