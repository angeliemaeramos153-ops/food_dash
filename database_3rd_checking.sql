-- Food Dash 3rd Checking Complete Database Schema
-- Project: Food Dash: Mobile Food Delivery and Order Tracking Management System
-- Increment: 3rd Increment Defense
-- Stack: PHP 8 / MySQL (MariaDB) / Vanilla JS & CSS

SET FOREIGN_KEY_CHECKS = 0;

-- 1. USERS TABLE
CREATE TABLE IF NOT EXISTS users (
    user_id INT NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) DEFAULT NULL,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) DEFAULT 'customer',
    failed_attempts INT DEFAULT 0,
    lockout_until DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_users_username (username),
    KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. SHOPS / STORES TABLE
CREATE TABLE IF NOT EXISTS shops (
    shop_id INT NOT NULL AUTO_INCREMENT,
    shop_name VARCHAR(100) NOT NULL,
    category VARCHAR(100) DEFAULT 'Fast Food',
    logo_url VARCHAR(255) DEFAULT 'assets/shops/default_store.svg',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (shop_id),
    UNIQUE KEY uq_shops_name (shop_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. MENU ITEMS TABLE
CREATE TABLE IF NOT EXISTS menu_items (
    item_id INT NOT NULL AUTO_INCREMENT,
    shop_id INT DEFAULT NULL,
    item_name VARCHAR(100) NOT NULL,
    category VARCHAR(50) NOT NULL DEFAULT 'Main',
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    description VARCHAR(255) DEFAULT '',
    icon VARCHAR(50) DEFAULT '🍴',
    is_recommended TINYINT(1) DEFAULT 0,
    is_available TINYINT(1) DEFAULT 1,
    PRIMARY KEY (item_id),
    KEY idx_menu_shop (shop_id),
    CONSTRAINT fk_menu_shop FOREIGN KEY (shop_id) REFERENCES shops (shop_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4. FOOD ORDERS (CORE TRANSACTION LIFECYCLE)
-- Lifecycle statuses: PENDING -> PREPARING -> READY_FOR_PICKUP -> OUT_FOR_DELIVERY -> DELIVERED -> SETTLED
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
    payment_method VARCHAR(50) DEFAULT 'Cash on Delivery',
    delivery_address TEXT DEFAULT NULL,
    delivery_lat DECIMAL(10,8) DEFAULT NULL,
    delivery_lng DECIMAL(11,8) DEFAULT NULL,
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

-- 7. ORDER AUDIT LOGS (COMPLETE VERIFIABLE TRAIL)
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

-- 8. SETTLEMENTS / RECONCILED TRANSACTIONS TABLE
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

-- 10. NOTIFICATIONS TABLE (IN-APP NOTIFICATION ENGINE)
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

-- 11. DELIVERED BROADCAST MESSAGES TABLE
CREATE TABLE IF NOT EXISTS delivered_messages (
    message_id INT NOT NULL AUTO_INCREMENT,
    message_text TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 12. SYSTEM AUDIT LOGS (SECURITY & AUDIT TRAIL)
CREATE TABLE IF NOT EXISTS system_audit_logs (
    log_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    username VARCHAR(100) NULL,
    role VARCHAR(50) NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    KEY idx_sys_audit_user (user_id),
    KEY idx_sys_audit_action (action),
    KEY idx_sys_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- SEED CORE DEMO ACCOUNTS IF MISSING
INSERT INTO users (username, full_name, email, password, role)
SELECT 'admin', 'Administrator', 'admin@fooddash.com', 'admin123', 'admin'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'admin');

INSERT INTO users (username, full_name, email, password, role)
SELECT 'customer', 'Juan dela Cruz', 'customer@fooddash.com', 'cust123', 'customer'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'customer');

INSERT INTO users (username, full_name, email, password, role)
SELECT 'manager', 'Chef Chefsky', 'chef@fooddash.com', 'man123', 'chef'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'manager');

INSERT INTO users (username, full_name, email, password, role)
SELECT 'rider', 'Alex Gonzaga (Rider)', 'rider@fooddash.com', 'rider123', 'rider'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'rider');

-- SEED DEFAULT STORES IF MISSING
INSERT INTO shops (shop_name, category, logo_url)
SELECT 'FoodDash Grill', 'Steakhouse & BBQ Grill', 'logo.jpg' WHERE NOT EXISTS (SELECT 1 FROM shops WHERE shop_name = 'FoodDash Grill');
INSERT INTO shops (shop_name, category, logo_url)
SELECT 'McDonalds', 'Burgers & Fries', 'assets/shops/mcdonalds.png' WHERE NOT EXISTS (SELECT 1 FROM shops WHERE shop_name = 'McDonalds');
INSERT INTO shops (shop_name, category, logo_url)
SELECT 'Jollibee', 'Chickenjoy & Fast Food', 'assets/shops/jollibee.png' WHERE NOT EXISTS (SELECT 1 FROM shops WHERE shop_name = 'Jollibee');
INSERT INTO shops (shop_name, category, logo_url)
SELECT 'Mang Inasal', 'Grilled Chicken & Inasal', 'assets/shops/mang_inasal.png' WHERE NOT EXISTS (SELECT 1 FROM shops WHERE shop_name = 'Mang Inasal');
INSERT INTO shops (shop_name, category, logo_url)
SELECT 'Ribshack', 'Ribs & Barbecue', 'assets/shops/ribshack.png' WHERE NOT EXISTS (SELECT 1 FROM shops WHERE shop_name = 'Ribshack');
INSERT INTO shops (shop_name, category, logo_url)
SELECT 'Shakeys', 'Pizza & Mojos', 'assets/shops/shakeys.png' WHERE NOT EXISTS (SELECT 1 FROM shops WHERE shop_name = 'Shakeys');
INSERT INTO shops (shop_name, category, logo_url)
SELECT 'Chowking', 'Chinese & Dim Sum', 'assets/shops/chowking.png' WHERE NOT EXISTS (SELECT 1 FROM shops WHERE shop_name = 'Chowking');
INSERT INTO shops (shop_name, category, logo_url)
SELECT 'Greenwich', 'Pizza & Pasta', 'assets/shops/greenwich.png' WHERE NOT EXISTS (SELECT 1 FROM shops WHERE shop_name = 'Greenwich');

-- ENSURE EXISTING SHOPS USE UPDATED PNG BRAND LOGOS
UPDATE shops SET logo_url = 'logo.jpg' WHERE shop_name = 'FoodDash Grill';
UPDATE shops SET logo_url = 'assets/shops/mcdonalds.png' WHERE shop_name = 'McDonalds';
UPDATE shops SET logo_url = 'assets/shops/jollibee.png' WHERE shop_name = 'Jollibee';
UPDATE shops SET logo_url = 'assets/shops/mang_inasal.png' WHERE shop_name = 'Mang Inasal';
UPDATE shops SET logo_url = 'assets/shops/ribshack.png' WHERE shop_name = 'Ribshack';
UPDATE shops SET logo_url = 'assets/shops/shakeys.png' WHERE shop_name = 'Shakeys';
UPDATE shops SET logo_url = 'assets/shops/chowking.png' WHERE shop_name = 'Chowking';
UPDATE shops SET logo_url = 'assets/shops/greenwich.png' WHERE shop_name = 'Greenwich';

-- CLEANUP & DEDUPLICATE LEGACY DUPLICATE SHOPS (e.g. merge 'Green Wich' into 'Greenwich')
UPDATE menu_items m
  JOIN shops old_s ON m.shop_id = old_s.shop_id AND old_s.shop_name = 'Green Wich'
  JOIN shops new_s ON new_s.shop_name = 'Greenwich'
  SET m.shop_id = new_s.shop_id;

UPDATE food_orders fo
  JOIN shops old_s ON fo.shop_id = old_s.shop_id AND old_s.shop_name = 'Green Wich'
  JOIN shops new_s ON new_s.shop_name = 'Greenwich'
  SET fo.shop_id = new_s.shop_id, fo.store_name = 'Greenwich';

UPDATE orders o
  JOIN shops old_s ON o.shop_id = old_s.shop_id AND old_s.shop_name = 'Green Wich'
  JOIN shops new_s ON new_s.shop_name = 'Greenwich'
  SET o.shop_id = new_s.shop_id;

DELETE FROM shops WHERE shop_name = 'Green Wich';

-- SEED COMPLETE PARTNER STORE MENU ITEMS WITH DYNAMIC CATEGORIES
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Ribeye Steak', 'Ribs & Steaks', 680.00, 'Tender USDA ribeye steak flame-grilled with herb butter.', '🥩', 1, 1 FROM shops s WHERE s.shop_name = 'FoodDash Grill' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Ribeye Steak');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Smoked Pork BBQ Ribs', 'Ribs & Steaks', 289.00, 'Signature flame-grilled pork ribs with house barbecue glaze.', '🍖', 1, 1 FROM shops s WHERE s.shop_name = 'FoodDash Grill' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Smoked Pork BBQ Ribs');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Flame-Grilled Inasal Platter', 'Grilled Chicken', 219.00, 'Charcoal-grilled quarter chicken with spiced garlic rice.', '🍗', 1, 1 FROM shops s WHERE s.shop_name = 'FoodDash Grill' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Flame-Grilled Inasal Platter');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Angus Beef Grill Burger', 'Burgers', 199.00, '100% Angus beef patty flame-grilled to perfection with cheddar.', '🍔', 1, 1 FROM shops s WHERE s.shop_name = 'FoodDash Grill' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Angus Beef Grill Burger');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Gourmet Burger', 'Burgers', 250.00, 'Artisan brioche burger with caramelized onions and truffle aioli.', '🍔', 0, 1 FROM shops s WHERE s.shop_name = 'FoodDash Grill' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Gourmet Burger');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Grilled Pork Belly Liempo', 'BBQ & Pork', 249.00, 'Marinated succulent pork belly grilled over live charcoal.', '🥩', 0, 1 FROM shops s WHERE s.shop_name = 'FoodDash Grill' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Grilled Pork Belly Liempo');

INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Big Mac', 'Burgers', 179.00, 'Two 100% pure beef patties, special sauce, lettuce and cheese.', '🍔', 1, 1 FROM shops s WHERE s.shop_name = 'McDonalds' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Big Mac');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Quarter Pounder', 'Burgers', 195.00, 'Quarter pound of 100% fresh beef with melted cheese.', '🍔', 0, 1 FROM shops s WHERE s.shop_name = 'McDonalds' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Quarter Pounder');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Chicken McDo', 'Chicken', 119.00, 'Golden crispy fried chicken served with rich gravy and rice.', '🍗', 1, 1 FROM shops s WHERE s.shop_name = 'McDonalds' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Chicken McDo');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'McSpaghetti', 'Pasta', 89.00, 'Filipino-style sweet tomato sauce spaghetti with ground meat and cheese.', '🍝', 1, 1 FROM shops s WHERE s.shop_name = 'McDonalds' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'McSpaghetti');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Chicken Ala King', 'Rice Meals', 129.00, 'Crispy chicken fillet with creamy ala king sauce over rice.', '🍚', 0, 1 FROM shops s WHERE s.shop_name = 'McDonalds' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Chicken Ala King');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'French Fries', 'Sides', 75.00, 'Golden crispy french fries seasoned with salt.', '🍟', 1, 1 FROM shops s WHERE s.shop_name = 'McDonalds' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'French Fries');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Hot Fudge Sundae', 'Desserts', 49.00, 'Creamy vanilla soft serve with warm chocolate fudge.', '🍨', 0, 1 FROM shops s WHERE s.shop_name = 'McDonalds' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Hot Fudge Sundae');

INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Chickenjoy', 'Chicken', 129.00, 'Crispylicious, juicylicious fried chicken with savory gravy.', '🍗', 1, 1 FROM shops s WHERE s.shop_name = 'Jollibee' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Chickenjoy');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Spicy Chickenjoy', 'Chicken', 139.00, 'Fiery crispy fried chicken with savory gravy.', '🍗', 0, 1 FROM shops s WHERE s.shop_name = 'Jollibee' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Spicy Chickenjoy');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Jolly Spaghetti', 'Pasta', 99.00, 'Sweet-style Filipino spaghetti with meaty sauce, hotdog and cheddar.', '🍝', 1, 1 FROM shops s WHERE s.shop_name = 'Jollibee' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Jolly Spaghetti');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Yumburger', 'Burgers', 69.00, 'Beef burger patty with signature Jollibee dressing.', '🍔', 1, 1 FROM shops s WHERE s.shop_name = 'Jollibee' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Yumburger');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Burger Steak', 'Rice Meals', 85.00, '100% beef patty with flavorful mushroom gravy and steamed rice.', '🍛', 0, 1 FROM shops s WHERE s.shop_name = 'Jollibee' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Burger Steak');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Jolly Hotdog', 'Snacks', 59.00, 'Classic hotdog with sweet-style relish and cheese.', '🌭', 0, 1 FROM shops s WHERE s.shop_name = 'Jollibee' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Jolly Hotdog');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Peach Mango Pie', 'Desserts', 45.00, 'Sweet tropical peach mango filling in crispy golden crust.', '🥧', 0, 1 FROM shops s WHERE s.shop_name = 'Jollibee' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Peach Mango Pie');

INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Chicken Inasal', 'Inasal Meals', 189.00, 'Grilled chicken leg quarter with rice and savory marinade.', '🍗', 1, 1 FROM shops s WHERE s.shop_name = 'Mang Inasal' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Chicken Inasal');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Pecho', 'Inasal Meals', 199.00, 'Flame-grilled chicken breast and wing meal with garlic rice.', '🍗', 1, 1 FROM shops s WHERE s.shop_name = 'Mang Inasal' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Pecho');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Pork BBQ', 'Grilled BBQ', 129.00, 'Two skewers of Filipino-style sweet-savory grilled pork barbecue.', '🍢', 1, 1 FROM shops s WHERE s.shop_name = 'Mang Inasal' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Pork BBQ');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Grilled Liempo', 'Grilled BBQ', 179.00, 'Juicy flame-grilled pork belly with spiced dipping sauce.', '🥩', 0, 1 FROM shops s WHERE s.shop_name = 'Mang Inasal' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Grilled Liempo');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Palabok', 'Noodles', 89.00, 'Savory rice noodles with golden sauce, pork cracklings, and egg.', '🍜', 0, 1 FROM shops s WHERE s.shop_name = 'Mang Inasal' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Palabok');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Halo-Halo', 'Desserts', 99.00, 'Classic Filipino shaved-ice dessert with sweetened beans and ube.', '🍧', 1, 1 FROM shops s WHERE s.shop_name = 'Mang Inasal' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Halo-Halo');

INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Baby Back Ribs', 'Ribs & Platters', 349.00, 'Fall-off-the-bone tender ribs with signature barbecue sauce.', '🍖', 1, 1 FROM shops s WHERE s.shop_name = 'Ribshack' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Baby Back Ribs');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Ribs Meal', 'Ribs & Platters', 399.00, 'Signature ribs served with garlic rice, buttered corn and side slaw.', '🍖', 1, 1 FROM shops s WHERE s.shop_name = 'Ribshack' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Ribs Meal');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Chicken BBQ', 'Grilled Chicken', 219.00, 'Chargrilled quarter chicken with sweet barbecue glaze.', '🍗', 1, 1 FROM shops s WHERE s.shop_name = 'Ribshack' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Chicken BBQ');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Pasta', 'Pasta', 229.00, 'Creamy bacon and mushroom pasta with toast.', '🍝', 0, 1 FROM shops s WHERE s.shop_name = 'Ribshack' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Pasta');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Grilled Pork Belly', 'Ribs & Platters', 239.00, 'Thick-cut succulent grilled pork belly with house vinegar dip.', '🥩', 0, 1 FROM shops s WHERE s.shop_name = 'Ribshack' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Grilled Pork Belly');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Garlic Rice & Atchara', 'Sides', 49.00, 'Fragrant fried garlic rice paired with pickled papaya slaw.', '🍚', 0, 1 FROM shops s WHERE s.shop_name = 'Ribshack' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Garlic Rice & Atchara');

INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Chao Fan', 'Rice Bowls', 99.00, 'Wok-tossed fried rice with savory pork and scrambled egg.', '🍚', 1, 1 FROM shops s WHERE s.shop_name = 'Chowking' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Chao Fan');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Chao fan w/ Fried Chicken', 'Rice Bowls', 169.00, 'Savory pork Chao Fan paired with Chinese-style fried chicken.', '🍗', 1, 1 FROM shops s WHERE s.shop_name = 'Chowking' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Chao fan w/ Fried Chicken');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Sweet & Sour Pork', 'Rice Bowls', 179.00, 'Crispy pork pieces in sweet and tangy sauce with steamed rice.', '🍛', 0, 1 FROM shops s WHERE s.shop_name = 'Chowking' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Sweet & Sour Pork');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Siomai', 'Dim Sum', 79.00, 'Four pieces of steamed pork dumplings with chili garlic sauce.', '🥟', 1, 1 FROM shops s WHERE s.shop_name = 'Chowking' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Siomai');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Siopao', 'Dim Sum', 69.00, 'Fluffy steamed bun filled with sweet savory roasted pork asado.', '🥮', 1, 1 FROM shops s WHERE s.shop_name = 'Chowking' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Siopao');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Wonton Mami', 'Noodles', 119.00, 'Egg noodles in steaming clear broth topped with pork wontons.', '🍜', 0, 1 FROM shops s WHERE s.shop_name = 'Chowking' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Wonton Mami');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Chopsuey', 'Sides', 129.00, 'Crisp stir-fried vegetables with chicken liver in savory sauce.', '🥬', 0, 1 FROM shops s WHERE s.shop_name = 'Chowking' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Chopsuey');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Halo-Halo Supreme', 'Desserts', 99.00, 'Shaved ice delight with sweet beans, leche flan, and ube ice cream.', '🍨', 0, 1 FROM shops s WHERE s.shop_name = 'Chowking' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Halo-Halo Supreme');

INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Managers Choice Pizza', 'Pizza', 499.00, 'Loaded with ham, beef, Italian sausage, bacon, and peppers.', '🍕', 1, 1 FROM shops s WHERE s.shop_name = 'Shakeys' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Managers Choice Pizza');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Pepperoni Crunch', 'Pizza', 459.00, 'Classic crispy pepperoni slices with melted mozzarella.', '🍕', 1, 1 FROM shops s WHERE s.shop_name = 'Shakeys' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Pepperoni Crunch');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Garlic & Cheese Pizza', 'Pizza', 449.00, 'Thin crust with roasted garlic, parmesan, and mozzarella.', '🍕', 0, 1 FROM shops s WHERE s.shop_name = 'Shakeys' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Garlic & Cheese Pizza');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Chicken N Mojos', 'Chicken', 349.00, 'Crispy fried chicken paired with seasoned deep-fried mojos.', '🍗', 1, 1 FROM shops s WHERE s.shop_name = 'Shakeys' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Chicken N Mojos');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Bunch of Lunch', 'Combos', 399.00, 'Thin-crust pizza slice, fried chicken, mojos, and garlic bread.', '🍕', 1, 1 FROM shops s WHERE s.shop_name = 'Shakeys' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Bunch of Lunch');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Carbonara Supreme', 'Pasta', 239.00, 'Rich cream sauce with smoky bacon, mushrooms, and parmesan.', '🍝', 0, 1 FROM shops s WHERE s.shop_name = 'Shakeys' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Carbonara Supreme');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Basket of Mojos', 'Sides', 189.00, 'Generous basket of seasoned deep-fried potato slices.', '🥔', 0, 1 FROM shops s WHERE s.shop_name = 'Shakeys' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Basket of Mojos');

INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Hawaiian Overload', 'Pizza', 499.00, 'Loaded with sweet pineapple tidbits, ham, bacon, and mozzarella.', '🍕', 1, 1 FROM shops s WHERE s.shop_name = 'Greenwich' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Hawaiian Overload');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'All Meat Overload', 'Pizza', 529.00, 'Pepperoni, beef, burger chunks, ham, and bacon blend.', '🍕', 0, 1 FROM shops s WHERE s.shop_name = 'Greenwich' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'All Meat Overload');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Garlic & Cheese Pizza', 'Pizza', 459.00, 'Crispy thin crust topped with creamy cheese and toasted garlic.', '🍕', 1, 1 FROM shops s WHERE s.shop_name = 'Greenwich' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Garlic & Cheese Pizza');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Lasagna Supreme', 'Pasta', 229.00, 'Rich layers of pasta, beef filling, creamy béchamel, and mozzarella.', '🍝', 1, 1 FROM shops s WHERE s.shop_name = 'Greenwich' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Lasagna Supreme');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Meaty Spaghetti', 'Pasta', 99.00, 'Classic sweet-style tomato sauce pasta with ground meat and cheese.', '🍝', 0, 1 FROM shops s WHERE s.shop_name = 'Greenwich' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Meaty Spaghetti');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Fried Chicken w/ Lasagna', 'Combos', 170.00, 'Crispy golden fried chicken paired with signature lasagna supreme.', '🍗', 1, 1 FROM shops s WHERE s.shop_name = 'Greenwich' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Fried Chicken w/ Lasagna');
INSERT INTO menu_items (shop_id, item_name, category, price, description, icon, is_recommended, is_available)
SELECT s.shop_id, 'Potato Waves', 'Sides', 89.00, 'Crispy crinkle-cut seasoned potato waves with dip.', '🥔', 0, 1 FROM shops s WHERE s.shop_name = 'Greenwich' AND NOT EXISTS (SELECT 1 FROM menu_items WHERE shop_id = s.shop_id AND item_name = 'Potato Waves');

