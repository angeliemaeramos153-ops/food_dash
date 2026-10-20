<?php
// Food Dash Dual-Environment Database Connection (Local MySQL & Cloud Supabase PostgreSQL)

$database_url = getenv('DATABASE_URL') ?: ($_ENV['DATABASE_URL'] ?? ($_SERVER['DATABASE_URL'] ?? null));
$is_render    = getenv('RENDER') !== false || !empty($_ENV['RENDER']) || !empty($_SERVER['RENDER']) || !empty($database_url);

try {
    if ($is_render) {
        $host     = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? ($_SERVER['DB_HOST'] ?? 'db.ijxbecblutgwfmuguxkg.supabase.co'));
        $port     = getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? ($_SERVER['DB_PORT'] ?? '5432'));
        $dbname   = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? ($_SERVER['DB_NAME'] ?? 'postgres'));
        $user     = getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? ($_SERVER['DB_USER'] ?? 'postgres'));
        $password = getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? ($_SERVER['DB_PASS'] ?? 'Ventrue@2026!'));

        if (!empty($database_url) && !str_starts_with($database_url, 'mysql:')) {
            if (str_starts_with($database_url, 'pgsql:')) {
                $dsn = $database_url;
            } else {
                $dbParts  = parse_url($database_url);
                $host     = $dbParts['host'] ?? $host;
                $port     = $dbParts['port'] ?? $port;
                $dbname   = isset($dbParts['path']) ? ltrim($dbParts['path'], '/') : $dbname;
                $user     = isset($dbParts['user']) ? urldecode($dbParts['user']) : $user;
                $password = isset($dbParts['pass']) ? urldecode($dbParts['pass']) : $password;
                $dsn      = "pgsql:host=$host;port=$port;dbname=$dbname";
            }
        } else {
            $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
        }

        $conn = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
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