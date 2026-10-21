<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db_connect.php';

header('Content-Type: application/json; charset=utf-8');

function addr_out(bool $success, string $message = '', array $extra = [], int $status = 200): never {
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    addr_out(false, 'Unauthorized. Please log in.', [], 401);
}

$user_id = (int)$_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

$raw_input = file_get_contents('php://input');
$json_input = json_decode($raw_input, true);
$payload = is_array($json_input) ? $json_input : $_POST;

if ($method === 'POST' && !empty($payload['action'])) {
    $action = $payload['action'];
}

// 1. GET ADDRESSES
if ($action === 'get_addresses' || ($method === 'GET' && empty($action))) {
    $stmt = $conn->prepare("SELECT address_id, user_id, label, street, barangay, city, postal_code, full_address, lat, lng, is_default, created_at 
        FROM user_addresses 
        WHERE user_id = :uid 
        ORDER BY is_default DESC, address_id DESC");
    $stmt->execute([':uid' => $user_id]);
    $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // If customer has no saved addresses yet, create default Home address
    if (empty($addresses)) {
        $default_full = '124 Rizal Street, Barangay Cogon, Ormoc City, 6541';
        $ins = $conn->prepare("INSERT INTO user_addresses (user_id, label, street, barangay, city, postal_code, full_address, lat, lng, is_default) 
            VALUES (:uid, 'Home', '124 Rizal Street, District 4', 'Barangay Cogon', 'Ormoc City', '6541', :full, 11.00762, 124.60749, 1)");
        $ins->execute([
            ':uid'  => $user_id,
            ':full' => $default_full
        ]);
        $new_id = (int)$conn->lastInsertId();
        $addresses = [[
            'address_id'   => $new_id,
            'user_id'      => $user_id,
            'label'        => 'Home',
            'street'       => '124 Rizal Street, District 4',
            'barangay'     => 'Barangay Cogon',
            'city'         => 'Ormoc City',
            'postal_code'  => '6541',
            'full_address' => $default_full,
            'lat'     => 11.00762,
            'lng'    => 124.60749,
            'is_default'   => 1,
            'created_at'   => date('Y-m-d H:i:s')
        ]];
    }

    addr_out(true, 'Addresses retrieved.', ['addresses' => $addresses]);
}

// 2. ADD NEW ADDRESS
if ($action === 'add_address' || $action === 'create_address') {
    $label = trim((string)($payload['label'] ?? 'Home'));
    $street = trim((string)($payload['street'] ?? ''));
    $barangay = trim((string)($payload['barangay'] ?? ''));
    $city = trim((string)($payload['city'] ?? 'Ormoc City'));
    $postal_code = trim((string)($payload['postal_code'] ?? '6541'));
    $lat = isset($payload['lat']) && is_numeric($payload['lat']) ? (float)$payload['lat'] : 11.00762;
    $lng = isset($payload['lng']) && is_numeric($payload['lng']) ? (float)$payload['lng'] : 124.60749;
    $is_default = !empty($payload['is_default']) ? 1 : 0;

    if ($street === '') {
        addr_out(false, 'Street address is required.', [], 422);
    }

    // Construct clean full address
    $parts = array_filter([$street, $barangay, $city, $postal_code], fn($p) => trim((string)$p) !== '');
    $full_address = implode(', ', $parts);

    // If setting as default or if this is the first address, clear existing defaults
    $chk = $conn->prepare("SELECT COUNT(*) FROM user_addresses WHERE user_id = :uid");
    $chk->execute([':uid' => $user_id]);
    $existing_count = (int)$chk->fetchColumn();

    if ($is_default || $existing_count === 0) {
        $is_default = 1;
        $conn->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = :uid")->execute([':uid' => $user_id]);
    }

    $stmt = $conn->prepare("INSERT INTO user_addresses (user_id, label, street, barangay, city, postal_code, full_address, lat, lng, is_default, created_at) 
        VALUES (:uid, :label, :street, :brgy, :city, :zip, :full, :lat, :lng, :def, NOW())");
    $stmt->execute([
        ':uid'    => $user_id,
        ':label'  => $label ?: 'Home',
        ':street' => $street,
        ':brgy'   => $barangay,
        ':city'   => $city ?: 'Ormoc City',
        ':zip'    => $postal_code ?: '6541',
        ':full'   => $full_address,
        ':lat'    => $lat,
        ':lng'    => $lng,
        ':def'    => $is_default
    ]);
    $new_id = (int)$conn->lastInsertId();

    addr_out(true, 'Address added successfully.', [
        'address' => [
            'address_id'   => $new_id,
            'user_id'      => $user_id,
            'label'        => $label ?: 'Home',
            'street'       => $street,
            'barangay'     => $barangay,
            'city'         => $city ?: 'Ormoc City',
            'postal_code'  => $postal_code ?: '6541',
            'full_address' => $full_address,
            'lat'     => $lat,
            'lng'    => $lng,
            'is_default'   => $is_default
        ]
    ]);
}

// 3. SET DEFAULT ADDRESS
if ($action === 'set_default') {
    $address_id = (int)($payload['address_id'] ?? 0);
    if ($address_id <= 0) {
        addr_out(false, 'Valid address_id is required.', [], 422);
    }

    $chk = $conn->prepare("SELECT full_address, lat, lng FROM user_addresses WHERE address_id = :aid AND user_id = :uid LIMIT 1");
    $chk->execute([':aid' => $address_id, ':uid' => $user_id]);
    $addr = $chk->fetch(PDO::FETCH_ASSOC);

    if (!$addr) {
        addr_out(false, 'Address not found or does not belong to you.', [], 404);
    }

    $conn->beginTransaction();
    try {
        $conn->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = :uid")->execute([':uid' => $user_id]);
        $conn->prepare("UPDATE user_addresses SET is_default = 1 WHERE address_id = :aid AND user_id = :uid")->execute([':aid' => $address_id, ':uid' => $user_id]);
        $conn->commit();

        addr_out(true, 'Default delivery address updated.', [
            'address_id'   => $address_id,
            'full_address' => $addr['full_address'],
            'lat'     => $addr['lat'],
            'lng'    => $addr['lng']
        ]);
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        addr_out(false, 'Failed to update default address: ' . $e->getMessage(), [], 500);
    }
}

// 4. DELETE ADDRESS
if ($action === 'delete_address') {
    $address_id = (int)($payload['address_id'] ?? 0);
    if ($address_id <= 0) {
        addr_out(false, 'Valid address_id is required.', [], 422);
    }

    $chk = $conn->prepare("SELECT is_default FROM user_addresses WHERE address_id = :aid AND user_id = :uid LIMIT 1");
    $chk->execute([':aid' => $address_id, ':uid' => $user_id]);
    $addr = $chk->fetch(PDO::FETCH_ASSOC);

    if (!$addr) {
        addr_out(false, 'Address not found.', [], 404);
    }

    $was_default = (int)$addr['is_default'] === 1;

    $del = $conn->prepare("DELETE FROM user_addresses WHERE address_id = :aid AND user_id = :uid");
    $del->execute([':aid' => $address_id, ':uid' => $user_id]);

    // If the deleted address was default, promote the first remaining address to default
    if ($was_default) {
        $rem = $conn->prepare("SELECT address_id FROM user_addresses WHERE user_id = :uid ORDER BY address_id DESC LIMIT 1");
        $rem->execute([':uid' => $user_id]);
        $next_id = $rem->fetchColumn();
        if ($next_id) {
            $conn->prepare("UPDATE user_addresses SET is_default = 1 WHERE address_id = :aid")->execute([':aid' => $next_id]);
        }
    }

    addr_out(true, 'Address deleted successfully.', ['deleted_address_id' => $address_id]);
}

addr_out(false, 'Invalid action.', [], 400);

