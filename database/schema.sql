-- ------------------------------------------------------------------------------
-- 1. USERS TABLE
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(100) NOT NULL,
    `password` VARCHAR(255) NULL,
    `phone` VARCHAR(10) NULL,
    `address` TEXT NULL,
    `role` ENUM('customer', 'staff', 'admin') NOT NULL DEFAULT 'customer',
    `status` ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `google_id` VARCHAR(64) NULL,
    UNIQUE KEY `email` (`email`),
    UNIQUE KEY `google_id` (`google_id`)
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 2. PRODUCTS TABLE
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `products` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(200) NOT NULL,
    `category` VARCHAR(100) NOT NULL,
    `brand` VARCHAR(100) NULL,
    `description` TEXT NULL,
    `price` DECIMAL(10,2) NOT NULL,
    `is_repairable` TINYINT(1) NOT NULL DEFAULT 0,
    `warranty_period_months` INT NOT NULL DEFAULT 6,
    `image_url` VARCHAR(255) NULL,
    `status` ENUM('available', 'out_of_stock', 'discontinued') NOT NULL DEFAULT 'available',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 3. PRODUCT_IMAGES TABLE (Image Gallery for Products)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_images` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `image_url` VARCHAR(500) NOT NULL,
    `alt_text` VARCHAR(255) NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_product_images_product` (`product_id`),
    KEY `idx_product_images_primary` (`product_id`, `is_primary`),
    CONSTRAINT `fk_product_images_product` FOREIGN KEY (`product_id`)
        REFERENCES `products`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 4. PRODUCT_SPECIFICATIONS TABLE (Product Attributes and Details)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_specifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `spec_group` VARCHAR(100) NOT NULL,
    `spec_name` VARCHAR(150) NOT NULL,
    `spec_value` TEXT NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_product_specifications_product` (`product_id`),
    KEY `idx_product_specifications_group` (`product_id`, `spec_group`),
    CONSTRAINT `fk_product_specifications_product` FOREIGN KEY (`product_id`)
        REFERENCES `products`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 5. INVENTORY TABLE
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `inventory` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `stock_quantity` INT NOT NULL DEFAULT 0,
    `low_stock_threshold` INT NOT NULL DEFAULT 5,
    `last_restocked_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `product_id` (`product_id`),
    CONSTRAINT `inventory_ibfk_1` FOREIGN KEY (`product_id`)
        REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 6. ORDERS TABLE
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_number` VARCHAR(50) NOT NULL,
    `user_id` INT NOT NULL,
    `total_amount` DECIMAL(10,2) NOT NULL,
    `payment_status` ENUM('pending', 'paid', 'failed', 'refunded') NOT NULL DEFAULT 'pending',
    `order_status` ENUM('placed', 'processing', 'shipped', 'delivered', 'cancelled') NOT NULL DEFAULT 'placed',
    `shipping_address` TEXT NOT NULL,
    `contact_phone` VARCHAR(20) NOT NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `order_number` (`order_number`),
    KEY `user_id` (`user_id`),
    CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`user_id`)
        REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 7. ORDER_ITEMS TABLE
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `unit_price` DECIMAL(10,2) NOT NULL,
    `subtotal` DECIMAL(10,2) NOT NULL,
    KEY `order_id` (`order_id`),
    KEY `product_id` (`product_id`),
    CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`)
        REFERENCES `orders`(`id`) ON DELETE CASCADE,
    CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`product_id`)
        REFERENCES `products`(`id`)
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 8. REPAIRS TABLE
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `repairs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `repair_code` VARCHAR(50) NOT NULL,
    `user_id` INT NOT NULL,
    `technician_id` INT NULL,
    `device_model` VARCHAR(150) NOT NULL,
    `serial_or_imei` VARCHAR(100) NULL,
    `issue_description` TEXT NOT NULL,
    `repair_status` ENUM('pending', 'assigned', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    `diagnosis_notes` TEXT NULL,
    `repair_notes` TEXT NULL,
    `is_warranty_covered` TINYINT(1) NOT NULL DEFAULT 0,
    `estimated_cost` DECIMAL(10,2) DEFAULT 0.00,
    `final_cost` DECIMAL(10,2) DEFAULT 0.00,
    `preferred_date` DATE NULL,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `repair_code` (`repair_code`),
    KEY `user_id` (`user_id`),
    KEY `technician_id` (`technician_id`),
    CONSTRAINT `repairs_ibfk_1` FOREIGN KEY (`user_id`)
        REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `repairs_ibfk_2` FOREIGN KEY (`technician_id`)
        REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 9. INVOICES TABLE
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `invoices` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `invoice_number` VARCHAR(50) NOT NULL,
    `order_id` INT NULL,
    `repair_id` INT NULL,
    `invoice_type` ENUM('order', 'repair') NOT NULL,
    `total_amount` DECIMAL(10,2) NOT NULL,
    `pdf_path` VARCHAR(255) NULL,
    `generated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `invoice_number` (`invoice_number`),
    KEY `order_id` (`order_id`),
    KEY `repair_id` (`repair_id`),
    CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`order_id`)
        REFERENCES `orders`(`id`) ON DELETE SET NULL,
    CONSTRAINT `invoices_ibfk_2` FOREIGN KEY (`repair_id`)
        REFERENCES `repairs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 10. PAYMENTS TABLE
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NULL,
    `repair_id` INT NULL,
    `transaction_id` VARCHAR(100) NOT NULL,
    `payment_method` ENUM('payhere', 'cash') NOT NULL,
    `amount` DECIMAL(10,2) NOT NULL,
    `payment_status` ENUM('success', 'pending', 'failed') NOT NULL DEFAULT 'pending',
    `payment_payload` TEXT NULL,
    `payment_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `order_id` (`order_id`),
    CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`order_id`)
        REFERENCES `orders`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 11. WARRANTIES TABLE
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `warranties` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `warranty_code` VARCHAR(50) NOT NULL,
    `user_id` INT NOT NULL,
    `product_id` INT NULL,
    `order_id` INT NULL,
    `repair_id` INT NULL,
    `warranty_type` ENUM('product', 'repair') NOT NULL DEFAULT 'product',
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `status` ENUM('active', 'expired', 'claimed', 'void') NOT NULL DEFAULT 'active',
    `claim_description` TEXT NULL,
    `claim_date` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `warranty_code` (`warranty_code`),
    KEY `user_id` (`user_id`),
    KEY `product_id` (`product_id`),
    KEY `order_id` (`order_id`),
    KEY `repair_id` (`repair_id`),
    CONSTRAINT `warranties_ibfk_1` FOREIGN KEY (`user_id`)
        REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `warranties_ibfk_2` FOREIGN KEY (`product_id`)
        REFERENCES `products`(`id`) ON DELETE SET NULL,
    CONSTRAINT `warranties_ibfk_3` FOREIGN KEY (`order_id`)
        REFERENCES `orders`(`id`) ON DELETE SET NULL,
    CONSTRAINT `warranties_ibfk_4` FOREIGN KEY (`repair_id`)
        REFERENCES `repairs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 12. NOTIFICATIONS TABLE
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
    KEY `user_id` (`user_id`),
    CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`)
        REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 13. INQUIRIES TABLE (Customer Pre-Sales & Service Questions)
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
    KEY `user_id` (`user_id`),
    CONSTRAINT `fk_inquiries_user` FOREIGN KEY (`user_id`)
        REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 14. PASSWORD_RESETS TABLE (Password Reset Tokens)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_resets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `token` VARCHAR(255) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `token` (`token`),
    KEY `user_id` (`user_id`),
    CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`)
        REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;