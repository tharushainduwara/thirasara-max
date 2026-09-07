CREATE DATABASE IF NOT EXISTS `thirasara_max_db` 
DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `thirasara_max_db`;

-- ------------------------------------------------------------------------------
-- 1. USERS TABLE (Customers, Technicians/Staff, Administrator/Owner)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `phone` VARCHAR(20) NULL,
    `address` TEXT NULL,
    `role` ENUM('customer', 'staff', 'admin') NOT NULL DEFAULT 'customer',
    `status` ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 2. PRODUCTS TABLE (Accessories, Spare Parts & Repairable Devices)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `products` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(200) NOT NULL,
    `category` VARCHAR(100) NOT NULL,
    `brand` VARCHAR(100) NULL,
    `description` TEXT NULL,
    `price` DECIMAL(10, 2) NOT NULL,
    `is_repairable` TINYINT(1) NOT NULL DEFAULT 0,
    `warranty_period_months` INT NOT NULL DEFAULT 6,
    `image_url` VARCHAR(255) NULL,
    `status` ENUM('available', 'out_of_stock', 'discontinued') NOT NULL DEFAULT 'available',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 3. INVENTORY TABLE (Real-time Stock Control & Low Stock Thresholds)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `inventory` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL UNIQUE,
    `stock_quantity` INT NOT NULL DEFAULT 0,
    `low_stock_threshold` INT NOT NULL DEFAULT 5,
    `last_restocked_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_inventory_product` FOREIGN KEY (`product_id`) 
        REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 4. ORDERS TABLE (Sales Transactions & Order Status Lifecycle)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_number` VARCHAR(50) NOT NULL UNIQUE,
    `user_id` INT NOT NULL,
    `total_amount` DECIMAL(10, 2) NOT NULL,
    `payment_status` ENUM('pending', 'paid', 'failed', 'refunded') NOT NULL DEFAULT 'pending',
    `order_status` ENUM('placed', 'processing', 'shipped', 'delivered', 'cancelled') NOT NULL DEFAULT 'placed',
    `shipping_address` TEXT NOT NULL,
    `contact_phone` VARCHAR(20) NOT NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 5. ORDER ITEMS TABLE (Individual Items Purchased within an Order)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `unit_price` DECIMAL(10, 2) NOT NULL,
    `subtotal` DECIMAL(10, 2) NOT NULL,
    CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) 
        REFERENCES `orders`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_order_items_product` FOREIGN KEY (`product_id`) 
        REFERENCES `products`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 6. PAYMENTS TABLE (Stripe / PayPal Gateway Transaction Logs)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NULL,
    `repair_id` INT NULL,
    `transaction_id` VARCHAR(100) NOT NULL,
    `payment_method` ENUM('stripe', 'paypal', 'cash', 'bank_transfer') NOT NULL,
    `amount` DECIMAL(10, 2) NOT NULL,
    `payment_status` ENUM('success', 'pending', 'failed') NOT NULL DEFAULT 'pending',
    `payment_payload` TEXT NULL,
    `payment_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_payments_order` FOREIGN KEY (`order_id`) 
        REFERENCES `orders`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 7. REPAIRS TABLE (Device Intake, Technician Workflow & Status Lifecycle)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `repairs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `repair_code` VARCHAR(50) NOT NULL UNIQUE,
    `user_id` INT NOT NULL,
    `technician_id` INT NULL,
    `device_model` VARCHAR(150) NOT NULL,
    `serial_or_imei` VARCHAR(100) NULL,
    `issue_description` TEXT NOT NULL,
    `repair_status` ENUM('pending', 'assigned', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    `diagnosis_notes` TEXT NULL,
    `repair_notes` TEXT NULL,
    `is_warranty_covered` TINYINT(1) NOT NULL DEFAULT 0,
    `estimated_cost` DECIMAL(10, 2) DEFAULT 0.00,
    `final_cost` DECIMAL(10, 2) DEFAULT 0.00,
    `preferred_date` DATE NULL,
    `completed_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_repairs_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_repairs_technician` FOREIGN KEY (`technician_id`) 
        REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 8. WARRANTIES TABLE (Digital Certificates, Expiry & Replacement Claims)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `warranties` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `warranty_code` VARCHAR(50) NOT NULL UNIQUE,
    `user_id` INT NOT NULL,
    `product_id` INT NULL,
    `order_id` INT NULL,
    `repair_id` INT NULL,
    `warranty_type` ENUM('product', 'repair') NOT NULL DEFAULT 'product',
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `status` ENUM('active', 'expired', 'claimed', 'void') NOT NULL DEFAULT 'active',
    `claim_description` TEXT NULL,
    `claim_date` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_warranties_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_warranties_product` FOREIGN KEY (`product_id`) 
        REFERENCES `products`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_warranties_order` FOREIGN KEY (`order_id`) 
        REFERENCES `orders`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_warranties_repair` FOREIGN KEY (`repair_id`) 
        REFERENCES `repairs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 9. INVOICES TABLE (Dompdf Invoice Records for Orders & Completed Repairs)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `invoices` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `invoice_number` VARCHAR(50) NOT NULL UNIQUE,
    `order_id` INT NULL,
    `repair_id` INT NULL,
    `invoice_type` ENUM('order', 'repair') NOT NULL,
    `total_amount` DECIMAL(10, 2) NOT NULL,
    `pdf_path` VARCHAR(255) NULL,
    `generated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_invoices_order` FOREIGN KEY (`order_id`) 
        REFERENCES `orders`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_invoices_repair` FOREIGN KEY (`repair_id`) 
        REFERENCES `repairs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 10. NOTIFICATIONS TABLE (PHPMailer Logs, Order/Repair/Stock Alerts)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `title` VARCHAR(150) NOT NULL,
    `message` TEXT NOT NULL,
    `type` ENUM('order_confirmation', 'repair_status', 'warranty_alert', 'low_stock', 'general') NOT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `email_sent` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 11. INQUIRIES TABLE (Customer Pre-Sales & Service Questions)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `inquiries` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL,
    `subject` VARCHAR(200) NOT NULL,
    `message` TEXT NOT NULL,
    `reply` TEXT NULL,
    `status` ENUM('pending', 'resolved') NOT NULL DEFAULT 'pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_inquiries_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;
