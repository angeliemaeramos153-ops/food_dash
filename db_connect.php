<?php
// Food Dash Dual-Environment Database Connection (Local MySQL & Cloud Supabase PostgreSQL)

$database_url = getenv('DATABASE_URL') ?: ($_ENV['DATABASE_URL'] ?? ($_SERVER['DATABASE_URL'] ?? null));
$is_render    = getenv('RENDER') !== false || !empty($_ENV['RENDER']) || !empty($_SERVER['RENDER']) || !empty($database_url);

try {
    if ($is_render) {
        // Supabase IPv4 Session Pooler (Required for Render IPv4-only production environment)
        $default_host = 'aws-0-ap-southeast-1.pooler.supabase.com';
        $default_port = '5432';
        $default_db   = 'postgres';
        $default_user = 'postgres.ijxbecblutgwfmuguxkg';
        $default_pass = 'TDTEFcitYwQmQcFa';

        $host     = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? ($_SERVER['DB_HOST'] ?? $default_host));
        $port     = getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? ($_SERVER['DB_PORT'] ?? $default_port));
        $dbname   = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? ($_SERVER['DB_NAME'] ?? $default_db));
        $user     = getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? ($_SERVER['DB_USER'] ?? $default_user));
        $password = getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? ($_SERVER['DB_PASS'] ?? $default_pass));

        // If direct IPv6-only host is passed, safely redirect to IPv4 pooler
        if ($host === 'db.ijxbecblutgwfmuguxkg.supabase.co') {
            $host = $default_host;
            if ($user === 'postgres') {
                $user = $default_user;
            }
        }

        if (!empty($database_url) && !str_starts_with($database_url, 'mysql:')) {
            $dbParts  = parse_url($database_url);
            $parsed_host = $dbParts['host'] ?? $host;
            if ($parsed_host === 'db.ijxbecblutgwfmuguxkg.supabase.co') {
                $parsed_host = $default_host;
            }
            $host     = $parsed_host;
            $port     = $dbParts['port'] ?? $port;
            $dbname   = isset($dbParts['path']) ? ltrim($dbParts['path'], '/') : $dbname;
            $parsed_user = isset($dbParts['user']) ? urldecode($dbParts['user']) : $user;
            if ($parsed_user === 'postgres' && str_contains($host, 'pooler.supabase.com')) {
                $parsed_user = $default_user;
            }
            $user     = $parsed_user;
            $password = isset($dbParts['pass']) ? urldecode($dbParts['pass']) : $password;
        }

        $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require";

        $conn = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 10,
        ]);
        $pdo = $conn;
    } else {
        $conn = new PDO("mysql:host=localhost;dbname=food_dash;charset=utf8mb4", "root", "", [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo = $conn;
    }
} catch (PDOException $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'status'  => 'error',
        'message' => 'Database connection failed: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}