<?php
/**
 * Food Dash - Sign-Up View (Alias for Registration)
 */
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_GET['tab'])) {
    $_GET['tab'] = 'register';
}
require_once __DIR__ . '/index.php';
