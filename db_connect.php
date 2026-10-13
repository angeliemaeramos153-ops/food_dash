<?php
// Food Dash Dual-Environment Database Connection (Local MySQL & Cloud Supabase PostgreSQL)

$databaseUrl = getenv('DATABASE_URL') ?: ($_ENV['DATABASE_URL'] ?? ($_SERVER['DATABASE_URL'] ?? null));
$isRender    = getenv('RENDER') ?: ($_ENV['RENDER'] ?? ($_SERVER['RENDER'] ?? null));
$isCloud     = !empty($databaseUrl) || !empty($isRender);

try {
    if ($isCloud) {
        // Cloud Environment: Supabase PostgreSQL via DATABASE_URL
        if (!empty($databaseUrl)) {
            if (str_starts_with($databaseUrl, 'pgsql:')) {
                $dsn = $databaseUrl;
                if (!str_contains($dsn, 'sslmode=')) {
                    $dsn .= (str_ends_with($dsn, ';') ? '' : ';') . 'sslmode=require';
                }
                $user = getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? ($_SERVER['DB_USER'] ?? null));
                $password = getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? ($_SERVER['DB_PASS'] ?? null));
            } else {
                $dbParts  = parse_url($databaseUrl);
                $host     = $dbParts['host'] ?? (getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? ($_SERVER['DB_HOST'] ?? 'aws-0-ap-southeast-1.pooler.supabase.com')));
                $port     = $dbParts['port'] ?? (getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? ($_SERVER['DB_PORT'] ?? '6543')));
                $dbname   = isset($dbParts['path']) ? ltrim($dbParts['path'], '/') : (getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? ($_SERVER['DB_NAME'] ?? 'postgres')));
                $user     = isset($dbParts['user']) ? urldecode($dbParts['user']) : (getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? ($_SERVER['DB_USER'] ?? 'postgres.ijxbecblutgwfmuguxkg')));
                $password = isset($dbParts['pass']) ? urldecode($dbParts['pass']) : (getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? ($_SERVER['DB_PASS'] ?? 'TDTEFcitYwQmQcFa')));
                $dsn      = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require";
            }
        } else {
            // Cloud environment without explicit DATABASE_URL (e.g. Render detection fallback)
            $host     = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? ($_SERVER['DB_HOST'] ?? 'aws-0-ap-southeast-1.pooler.supabase.com'));
            $port     = getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? ($_SERVER['DB_PORT'] ?? '6543'));
            $dbname   = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? ($_SERVER['DB_NAME'] ?? 'postgres'));
            $user     = getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? ($_SERVER['DB_USER'] ?? 'postgres.ijxbecblutgwfmuguxkg'));
            $password = getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? ($_SERVER['DB_PASS'] ?? 'TDTEFcitYwQmQcFa'));
            $dsn      = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require";
        }

        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } else {
        // Local Environment: Apache / XAMPP MySQL
        $host     = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? ($_SERVER['DB_HOST'] ?? '127.0.0.1'));
        $dbname   = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? ($_SERVER['DB_NAME'] ?? 'food_dash'));
        $user     = getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? ($_SERVER['DB_USER'] ?? 'root'));
        $password = (getenv('DB_PASS') !== false) ? getenv('DB_PASS') : ($_ENV['DB_PASS'] ?? ($_SERVER['DB_PASS'] ?? ''));
        $charset  = 'utf8mb4';

        $dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    // Keep $conn available for existing queries and backwards compatibility
    $conn = $pdo;

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