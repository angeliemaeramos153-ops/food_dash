<?php
require_once __DIR__ . '/../db_connect.php';
$cols = $conn->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo $c['Field'] . " (" . $c['Type'] . ")\n";
}
