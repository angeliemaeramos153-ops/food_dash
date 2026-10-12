<?php
// Food Dash PostgreSQL Cloud Connection (Supabase)

$host     = getenv('DB_HOST')     ?: 'aws-0-ap-southeast-1.pooler.supabase.com';
$port     = getenv('DB_PORT')     ?: '6543';
$dbname   = getenv('DB_NAME')     ?: 'postgres';
$user     = getenv('DB_USER')     ?: 'postgres.ijxbecblutgwfmuguxkg';
$password = getenv('DB_PASS')     ?: 'TDTEFcitYwQmQcFa';

try {
    $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    // Keep $conn available for existing queries
    $conn = $pdo;
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}