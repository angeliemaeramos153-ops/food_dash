-- Food Dash 3rd Checking Database Schema Expansion
-- Purpose: Complete transactional flow across Customer, Chef, Rider, and Admin.
-- Tables: users, menu_items, shops, food_orders, food_order_items, order_items, order_audit_logs, settlements, riders, notifications.

SET FOREIGN_KEY_CHECKS = 0;

-- 1. USERS TABLE
CREATE TABLE IF NOT EXISTS users (
    user_id INT NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) DEFAULT NULL,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) DEFAULT 'customer',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_users_username (username),
    KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. SHOPS TABLE
CREATE TABLE IF NOT EXISTS shops (
    shop_id INT NOT NULL AUTO_INCREMENT,
    shop_name VARCHAR(100) NOT NULL,
    category VARCHAR(100) DEFAULT 'Fast Food',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (shop_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. MENU ITEMS TABLE
CREATE TABLE IF NOT EXISTS menu_items (
    item_id INT NOT NULL AUTO_INCREMENT,
    shop_id INT DEFAULT NULL,
    item_name VARCHAR(100) NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_recommended TINYINT(1) DEFAULT 0,
    is_available TINYINT(1) DEFAULT 1,
    PRIMARY KEY (item_id),
    KEY idx_menu_shop (shop_id),
    CONSTRAINT fk_menu_shop FOREIGN KEY (shop_id) REFERENCES shops (shop_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4. FOOD ORDERS TABLE (Lifecycle: PENDING -> PREPARING -> READY_FOR_PICKUP -> OUT_FOR_DELIVERY -> DELIVERED -> SETTLED)
CREATE TABLE IF NOT EXISTS food_orders (
    order_id INT NOT NULL AUTO_INCREMENT,
    shop_id INT DEFAULT 0,
    customer_id INT NOT NULL,
    customer_name VARCHAR(150) NOT NULL,
    order_ref VARCHAR(30) NOT NULL,
    store_name VARCHAR(150) NOT NULL,
    items_summary TEXT NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 30.00,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
    delivery_status VARCHAR(30) NOT NULL DEFAULT 'waiting',
    order_type VARCHAR(30) NOT NULL DEFAULT 'Delivery',
    customer_lat DECIMAL(10,7) DEFAULT NULL,
    customer_lng DECIMAL(10,7) DEFAULT NULL,
    chef_id INT DEFAULT NULL,
    rider_id INT DEFAULT NULL,
    rider_lat DECIMAL(10,8) DEFAULT NULL,
    rider_lng DECIMAL(11,8) DEFAULT NULL,
    location_updated_at DATETIME DEFAULT NULL,
    near_notified_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (order_id),
    UNIQUE KEY uq_food_orders_ref (order_ref),
    KEY idx_food_orders_customer (customer_id),
    KEY idx_food_orders_status (status),
    KEY idx_food_orders_delivery_status (delivery_status),
    KEY idx_food_orders_chef (chef_id),
    KEY idx_food_orders_rider (rider_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. FOOD ORDER ITEMS
CREATE TABLE IF NOT EXISTS food_order_items (
    item_id INT NOT NULL AUTO_INCREMENT,
    order_id INT NOT NULL,
    item_name VARCHAR(150) NOT NULL,
    store_name VARCHAR(150) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    line_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    icon VARCHAR(20) DEFAULT '🍴',
    PRIMARY KEY (item_id),
    KEY idx_food_order_items_order (order_id),
    CONSTRAINT fk_food_order_items_order FOREIGN KEY (order_id) REFERENCES food_orders (order_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. ORDER ITEMS COMPATIBILITY TABLE
CREATE TABLE IF NOT EXISTS order_items (
    item_id INT NOT NULL AUTO_INCREMENT,
    order_id INT NOT NULL,
    item_name VARCHAR(150) NOT NULL,
    store_name VARCHAR(150) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    line_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    icon VARCHAR(20) DEFAULT '🍴',
    PRIMARY KEY (item_id),
    KEY idx_order_items_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. ORDER AUDIT LOGS TABLE
CREATE TABLE IF NOT EXISTS order_audit_logs (
    log_id INT NOT NULL AUTO_INCREMENT,
    order_id INT NOT NULL,
    old_status VARCHAR(50) DEFAULT NULL,
    new_status VARCHAR(50) NOT NULL,
    action_by_user_id INT DEFAULT NULL,
    action_by_role VARCHAR(50) NOT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (log_id),
    KEY idx_audit_order (order_id),
    KEY idx_audit_user (action_by_user_id),
    KEY idx_audit_role (action_by_role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. SETTLEMENTS TABLE (ADMIN RECONCILIATION)
CREATE TABLE IF NOT EXISTS settlements (
    settlement_id INT NOT NULL AUTO_INCREMENT,
    order_id INT NOT NULL UNIQUE,
    admin_id INT NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_method VARCHAR(50) NOT NULL DEFAULT 'Cash on Delivery',
    status VARCHAR(30) NOT NULL DEFAULT 'SETTLED',
    notes TEXT DEFAULT NULL,
    reconciled_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (settlement_id),
    KEY idx_settlements_order (order_id),
    KEY idx_settlements_admin (admin_id),
    CONSTRAINT fk_settlements_order FOREIGN KEY (order_id) REFERENCES food_orders (order_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. RIDERS TABLE
CREATE TABLE IF NOT EXISTS riders (
    rider_id INT NOT NULL AUTO_INCREMENT,
    user_id INT DEFAULT NULL,
    rider_name VARCHAR(100) NOT NULL,
    status ENUM('waiting','on_delivery') DEFAULT 'waiting',
    current_order VARCHAR(50) DEFAULT 'None',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (rider_id),
    KEY idx_riders_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 10. NOTIFICATIONS TABLE
CREATE TABLE IF NOT EXISTS notifications (
    notification_id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    order_id INT DEFAULT NULL,
    notification_type VARCHAR(50) NOT NULL DEFAULT 'general',
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notification_id),
    KEY idx_notifications_user (user_id),
    KEY idx_notifications_order (order_id),
    KEY idx_notifications_read (user_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
