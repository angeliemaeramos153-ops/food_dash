<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$role = strtolower($_SESSION['role'] ?? '');

switch ($role) {
    case 'admin':
        header('Location: admin_dashboard.php');
        break;
    case 'chef':
    case 'manager':
        header('Location: chef_dashboard.php');
        break;
    case 'rider':
        header('Location: rider_dashboard.php');
        break;
    case 'customer':
    default:
        header('Location: customer_dashboard.php');
        break;
}
exit();
?>