-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 19, 2026 at 04:47 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `thirasara_max_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `inquiries`
--

CREATE TABLE `inquiries` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `subject` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `reply` text DEFAULT NULL,
  `status` enum('pending','resolved') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `inquiries`
--

INSERT INTO `inquiries` (`id`, `user_id`, `name`, `email`, `subject`, `message`, `reply`, `status`, `created_at`, `updated_at`) VALUES
(1, 4, 'Tharusha Iduwara', 'tiduwara26@gmail.com', 'Inquiry Regarding about Samsung OLED replacement panels', 'Do you currently have original Samsung OLED replacement panels in stock at Wadduwa shop?', 'Yes, we have only one', 'resolved', '2026-11-13 07:42:07', '2026-11-13 07:43:26');

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

CREATE TABLE `inventory` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `stock_quantity` int(11) NOT NULL DEFAULT 0,
  `low_stock_threshold` int(11) NOT NULL DEFAULT 5,
  `last_restocked_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `inventory`
--

INSERT INTO `inventory` (`id`, `product_id`, `stock_quantity`, `low_stock_threshold`, `last_restocked_at`, `updated_at`) VALUES
(3, 3, 12, 5, NULL, '2026-10-13 08:01:15'),
(6, 6, 13, 5, NULL, '2026-10-17 16:02:57'),
(10, 10, 18, 5, NULL, '2026-09-26 06:44:15'),
(11, 11, 10, 5, NULL, '2026-10-13 08:00:46'),
(16, 16, 15, 8, NULL, '2026-10-13 08:00:29'),
(17, 17, 20, 8, NULL, '2026-10-13 08:00:12'),
(23, 23, 13, 8, NULL, '2026-10-13 08:01:39'),
(24, 24, 12, 3, NULL, '2026-10-13 08:05:08'),
(27, 27, 2, 5, NULL, '2026-09-12 10:54:56'),
(31, 31, 8, 5, NULL, '2026-09-12 19:07:47'),
(32, 32, 8, 5, NULL, '2026-10-13 08:04:24'),
(33, 33, 13, 5, NULL, '2026-10-13 08:02:00'),
(36, 36, 17, 5, NULL, '2026-10-19 02:00:23'),
(38, 38, 8, 5, NULL, '2026-10-13 08:02:28'),
(39, 39, 12, 5, NULL, '2026-09-23 14:59:59'),
(43, 43, 16, 5, NULL, '2026-09-12 10:54:17'),
(44, 44, 9, 5, NULL, '2026-10-13 08:02:43'),
(49, 49, 13, 10, NULL, '2026-10-17 16:02:57'),
(50, 50, 12, 5, NULL, '2026-10-13 08:03:03'),
(51, 51, 16, 5, NULL, '2026-10-13 08:03:24'),
(52, 52, 10, 5, NULL, '2026-10-13 08:03:40'),
(54, 54, 8, 5, NULL, '2026-10-13 08:03:56'),
(56, 56, 19, 10, NULL, '2026-09-12 10:54:17'),
(65, 65, 11, 4, NULL, '2026-09-12 10:54:17'),
(66, 66, 8, 4, NULL, '2026-09-23 13:21:25'),
(69, 69, 5, 3, NULL, '2026-09-12 10:54:17'),
(70, 70, 11, 3, NULL, '2026-09-12 10:54:17'),
(74, 74, 5, 3, NULL, '2026-09-12 10:54:17'),
(75, 75, 14, 4, NULL, '2026-09-12 10:54:17'),
(77, 77, 6, 4, NULL, '2026-09-12 10:54:17'),
(130, 83, 12, 3, NULL, '2026-09-12 17:45:19'),
(138, 91, 0, 3, NULL, '2026-09-12 15:42:45'),
(145, 98, 8, 3, NULL, '2026-10-13 08:04:44'),
(147, 100, 14, 3, NULL, '2026-09-12 11:05:49'),
(148, 101, 11, 3, NULL, '2026-09-12 11:05:49'),
(149, 102, 7, 3, NULL, '2026-10-13 08:05:21'),
(151, 104, 13, 3, NULL, '2026-09-12 11:05:49'),
(155, 108, 6, 3, NULL, '2026-09-12 11:05:49'),
(157, 110, 12, 3, NULL, '2026-09-12 18:39:56'),
(159, 112, 6, 3, NULL, '2026-09-12 11:05:49'),
(192, 113, 11, 5, NULL, '2026-10-13 08:02:10'),
(193, 114, 12, 4, NULL, '2026-09-23 03:30:00'),
(194, 115, 15, 5, NULL, '2026-09-23 03:30:00'),
(195, 116, 10, 4, NULL, '2026-09-23 03:30:00'),
(196, 117, 8, 5, NULL, '2026-10-13 08:00:58'),
(197, 118, 6, 3, NULL, '2026-09-23 03:30:00'),
(198, 119, 10, 5, NULL, '2026-10-13 08:01:24'),
(199, 120, 8, 3, NULL, '2026-09-23 03:30:00'),
(200, 121, 7, 3, NULL, '2026-10-16 13:50:19');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_logs`
--

CREATE TABLE `inventory_logs` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `change_type` enum('restock','adjustment','order_sale','repair_part','return') NOT NULL DEFAULT 'restock',
  `quantity_change` int(11) NOT NULL,
  `resulting_stock` int(11) NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` int(11) NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `repair_id` int(11) DEFAULT NULL,
  `invoice_type` enum('order','repair') NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `pdf_path` varchar(255) DEFAULT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `invoices`
--

INSERT INTO `invoices` (`id`, `invoice_number`, `order_id`, `repair_id`, `invoice_type`, `total_amount`, `pdf_path`, `generated_at`) VALUES
(1, 'INV-20261013-0017', 17, NULL, 'order', 5340.00, NULL, '2026-10-13 07:44:55'),
(2, 'INV-20261013-0010', 10, NULL, 'order', 1540.00, NULL, '2026-10-13 07:53:59'),
(3, 'INV-20261016-0018', 18, NULL, 'order', 90250.00, NULL, '2026-10-16 13:51:37'),
(4, 'INV-20261017-0019', 19, NULL, 'order', 10520.00, NULL, '2026-10-17 16:03:48');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `type` enum('order_confirmation','repair_status','warranty_alert','low_stock','general') NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `email_sent` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `title`, `message`, `type`, `is_read`, `email_sent`, `created_at`) VALUES
(1, 4, 'Order Cancelled', 'Your order TM-20260912-3D8B50 has been cancelled and any reserved stock has been released.', 'general', 1, 0, '2026-09-12 19:07:47'),
(2, 4, 'Order Cancelled', 'Your order TM-20260923-DFDECC has been cancelled and any reserved stock has been released.', 'general', 1, 0, '2026-09-23 15:20:38'),
(7, 4, 'Repair booking received', 'Your repair RP-VSVE6W for Redmi Note 14 Pro 5G has been received.', 'repair_status', 1, 0, '2026-10-17 16:19:39'),
(8, 4, 'Repair booking received', 'Your repair RP-8K3YSV for Redmi Note 14 Pro 5G has been received.', 'repair_status', 1, 0, '2026-10-17 16:25:26'),
(9, 4, 'Technician assigned', 'Repair RP-8K3YSV has been assigned to a technician and will be looked at soon.', 'repair_status', 1, 0, '2026-10-17 16:52:08'),
(10, 4, 'Repair started', 'Work has started on your repair RP-8K3YSV.', 'repair_status', 1, 0, '2026-10-17 16:52:38'),
(11, 4, 'Digital Warranty Issued — WAR-20261017-3A01', 'An official digital warranty certificate (WAR-20261017-3A01) has been issued for your item. Valid until 17 Oct 2027.', 'warranty_alert', 1, 0, '2026-10-17 18:23:39'),
(12, 4, 'Reply to inquiry: Inquiry Regarding about Samsung OLED ...', 'We have replied to your inquiry: \"Yes, we have only one\"', 'general', 1, 1, '2026-11-13 07:43:31');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `order_number` varchar(50) NOT NULL,
  `user_id` int(11) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `payment_status` enum('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
  `order_status` enum('placed','processing','shipped','delivered','cancelled') NOT NULL DEFAULT 'placed',
  `shipping_address` text NOT NULL,
  `contact_phone` varchar(20) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `order_number`, `user_id`, `total_amount`, `payment_status`, `order_status`, `shipping_address`, `contact_phone`, `notes`, `created_at`, `updated_at`) VALUES
(10, 'TM-20260915-31216D', 4, 1540.00, 'paid', 'delivered', '55, Colombo 05, Nawala Road', '0705321114', NULL, '2026-09-15 14:11:00', '2026-10-13 07:53:59'),
(11, 'TM-20260923-58BAD6', 4, 7340.00, 'failed', 'cancelled', '55, Colombo 05, Nawala Road', '0705321114', NULL, '2026-09-23 13:21:25', '2026-10-13 08:25:48'),
(12, 'TM-20260923-E46E74', 4, 4490.00, 'failed', 'cancelled', 'No. 25, Main Street, Colombo 03, Sri Lanka', '0705321114', NULL, '2026-09-23 14:56:07', '2026-09-23 14:57:34'),
(14, 'TM-20260923-A666F5', 4, 2490.00, 'paid', 'delivered', 'No. 25, Main Street, Colombo 03, Sri Lanka', '0705321114', NULL, '2026-09-23 14:59:59', '2026-10-13 07:46:07'),
(15, 'TM-20260923-B7F073', 4, 3990.00, 'paid', 'delivered', 'No. 25, Main Street, Colombo 03, Sri Lanka', '0705321114', NULL, '2026-09-23 15:28:18', '2026-10-13 07:46:01'),
(16, 'TM-20260926-35A57D', 4, 5990.00, 'failed', 'cancelled', 'No. 25, Main Street, Colombo 03, Sri Lanka', '0705321114', NULL, '2026-09-26 06:41:00', '2026-09-26 06:44:15'),
(17, 'TM-20261013-AC0A81', 4, 5340.00, 'paid', 'delivered', 'No. 25, Main Street, Colombo 03, Sri Lanka', '0705321114', NULL, '2026-10-13 07:40:45', '2026-10-13 07:44:55'),
(18, 'TM-20261016-3041F2', 4, 90250.00, 'paid', 'delivered', 'No. 25, Main Street, Colombo 03, Sri Lanka', '0705321114', NULL, '2026-10-16 13:50:19', '2026-10-16 13:51:37'),
(19, 'TM-20261017-5AF693', 4, 10520.00, 'pending', 'processing', 'No. 25, Main Street, Colombo 03, Sri Lanka', '0705321114', NULL, '2026-10-17 16:02:57', '2026-10-17 16:45:10'),
(20, 'TM-20261019-EC7B9F', 4, 2990.00, 'failed', 'cancelled', 'No. 25, Main Street, Colombo 03, Sri Lanka', '0705321114', NULL, '2026-10-19 01:59:25', '2026-10-19 02:00:23');

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES
(10, 10, 49, 1, 1190.00, 1190.00),
(11, 11, 66, 1, 6990.00, 6990.00),
(12, 12, 6, 1, 4490.00, 4490.00),
(14, 14, 39, 1, 2490.00, 2490.00),
(15, 15, 38, 1, 3990.00, 3990.00),
(16, 16, 10, 1, 5990.00, 5990.00),
(17, 17, 17, 1, 4990.00, 4990.00),
(18, 18, 121, 1, 89900.00, 89900.00),
(19, 19, 6, 2, 4490.00, 8980.00),
(20, 19, 49, 1, 1190.00, 1190.00),
(21, 20, 36, 1, 2990.00, 2990.00);

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `repair_id` int(11) DEFAULT NULL,
  `transaction_id` varchar(100) NOT NULL,
  `payment_method` enum('payhere','cash') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_status` enum('success','pending','failed') NOT NULL DEFAULT 'pending',
  `payment_payload` text DEFAULT NULL,
  `payment_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `order_id`, `repair_id`, `transaction_id`, `payment_method`, `amount`, `payment_status`, `payment_payload`, `payment_date`) VALUES
(10, 10, NULL, 'PENDING-TM-20260915-31216D', 'cash', 1540.00, 'pending', '{\"source\":\"checkout.php\",\"order_number\":\"TM-20260915-31216D\",\"user_id\":4,\"payment_method\":\"cash\",\"subtotal\":1190,\"delivery_fee\":0,\"payment_fee\":350,\"total_amount\":1540,\"created_at\":\"2026-09-15T16:11:00+02:00\"}', '2026-09-15 14:11:00'),
(11, 11, NULL, 'PENDING-TM-20260923-58BAD6', 'cash', 7340.00, 'pending', '{\"source\":\"checkout.php\",\"order_number\":\"TM-20260923-58BAD6\",\"user_id\":4,\"payment_method\":\"cash\",\"subtotal\":6990,\"delivery_fee\":0,\"payment_fee\":350,\"total_amount\":7340,\"created_at\":\"2026-09-23T15:21:25+02:00\"}', '2026-09-23 13:21:25'),
(12, 12, NULL, 'PENDING-TM-20260923-E46E74', 'cash', 4490.00, 'failed', '{\"reason\":\"User cancelled or aborted gateway authorization\",\"cancelled_at\":\"2026-09-23T16:57:34+02:00\"}', '2026-09-23 14:56:07'),
(14, 14, NULL, 'PAYPAL_SIM_2972b40216ac48', 'cash', 2490.00, 'success', '{\"gateway\":\"paypal\",\"verified_at\":\"2026-09-23T17:00:03+02:00\",\"amount\":2490,\"simulation\":true,\"simulated_token\":\"SIMULATED_PAYPAL_71a68f0e6cc245753f0a\"}', '2026-09-23 15:00:03'),
(15, 15, NULL, 'PH_SIM_78C380189DA5', 'payhere', 3990.00, 'success', '{\"gateway\":\"payhere\",\"verified_at\":\"2026-09-23T17:28:36+02:00\",\"amount\":3990,\"payhere_payment_id\":\"PH_SIM_78C380189DA5\",\"simulation\":true}', '2026-09-23 15:28:36'),
(16, 16, NULL, 'PENDING-PH-TM-20260926-35A57D', 'payhere', 5990.00, 'failed', '{\"reason\":\"User cancelled or aborted gateway authorization\",\"cancelled_at\":\"2026-09-26T08:44:15+02:00\"}', '2026-09-26 06:41:00'),
(17, 17, NULL, 'PENDING-TM-20261013-AC0A81', 'cash', 5340.00, 'pending', '{\"source\":\"checkout.php\",\"order_number\":\"TM-20261013-AC0A81\",\"user_id\":4,\"payment_method\":\"cash\",\"subtotal\":4990,\"delivery_fee\":0,\"payment_fee\":350,\"total_amount\":5340,\"created_at\":\"2026-10-13T09:40:45+02:00\"}', '2026-10-13 07:40:45'),
(18, 18, NULL, 'PENDING-TM-20261016-3041F2', 'cash', 90250.00, 'pending', '{\"source\":\"checkout.php\",\"order_number\":\"TM-20261016-3041F2\",\"user_id\":4,\"payment_method\":\"cash\",\"subtotal\":89900,\"delivery_fee\":0,\"payment_fee\":350,\"total_amount\":90250,\"created_at\":\"2026-10-16T15:50:19+02:00\"}', '2026-10-16 13:50:19'),
(19, 19, NULL, 'PENDING-TM-20261017-5AF693', 'cash', 10520.00, 'pending', '{\"source\":\"checkout.php\",\"order_number\":\"TM-20261017-5AF693\",\"user_id\":4,\"payment_method\":\"cash\",\"subtotal\":10170,\"delivery_fee\":0,\"payment_fee\":350,\"total_amount\":10520,\"created_at\":\"2026-10-17T18:02:57+02:00\"}', '2026-10-17 16:02:57'),
(20, 20, NULL, 'PENDING-PH-TM-20261019-EC7B9F', 'payhere', 2990.00, 'failed', '{\"reason\":\"User cancelled or aborted gateway authorization\",\"cancelled_at\":\"2026-10-19T04:00:23+02:00\"}', '2026-10-19 01:59:25');

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL,
  `category` varchar(100) NOT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `is_repairable` tinyint(1) NOT NULL DEFAULT 0,
  `warranty_period_months` int(11) NOT NULL DEFAULT 6,
  `image_url` varchar(255) DEFAULT NULL,
  `status` enum('available','out_of_stock','discontinued') NOT NULL DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `name`, `category`, `brand`, `description`, `price`, `is_repairable`, `warranty_period_months`, `image_url`, `status`, `created_at`, `updated_at`) VALUES
(3, 'Samsung 15W Fast Charger', 'Chargers', 'Samsung', '15W fast charging adapter for compatible Samsung Galaxy smartphones.', 2490.00, 0, 6, 'assets/images/products/samsung-15w-charger.jpg', 'available', '2026-09-12 10:52:04', '2026-09-12 10:52:04'),
(6, 'Anker 20W USB-C Charger', 'Chargers', 'Anker', 'Compact 20W Power Delivery charger for fast smartphone charging.', 4490.00, 0, 12, 'assets/images/products/anker-20w-charger.png\r\n', 'available', '2026-09-12 10:52:04', '2026-09-23 08:03:09'),
(10, 'MCDODO 33W GaN Charger', 'Chargers', 'MCDODO', 'Compact GaN charger supporting high-speed charging for compatible devices.', 5990.00, 0, 12, 'assets/images/products/mcdodo-33w-gan.jpg', 'available', '2026-09-12 10:52:04', '2026-09-12 10:52:04'),
(11, 'MCDODO 67W GaN Charger', 'Chargers', 'MCDODO', 'High-power 67W GaN charger suitable for smartphones, tablets and compatible laptops.', 10350.00, 0, 12, 'assets/images/products/mcdodo-67w-gan.jpg', 'available', '2026-09-12 10:52:04', '2026-09-12 10:52:04'),
(16, 'Anker USB-C to USB-C Cable', 'Cables', 'Anker', 'Durable USB-C to USB-C cable for charging and data transfer.', 3490.00, 0, 12, 'assets/images/products/anker-usbc-cable.jpg', 'available', '2026-09-12 10:52:04', '2026-09-12 10:52:04'),
(17, 'Anker USB-C to Lightning Cable', 'Cables', 'Anker', 'USB-C to Lightning cable for charging compatible Apple devices.', 4990.00, 0, 12, 'assets/images/products/anker-usbc-lightning.jpg', 'available', '2026-09-12 10:52:04', '2026-09-12 10:52:04'),
(23, 'Micro USB Fast Charging Cable', 'Cables', 'Generic', 'Standard Micro USB charging and data cable for compatible devices.', 490.00, 0, 3, 'assets/images/products/micro-usb-cable.jpg', 'available', '2026-09-12 10:52:04', '2026-09-12 10:52:04'),
(24, 'Braided USB-C Cable 2M', 'Cables', 'Generic', 'Durable braided USB-C cable with extended 2 metre length.', 990.00, 0, 3, 'assets/images/products/braided-usbc-cable.jpg', 'available', '2026-09-12 10:52:04', '2026-09-12 10:52:04'),
(27, 'Anker 10000mAh Power Bank', 'Power Banks', 'Anker', 'Portable 10000mAh power bank designed for everyday smartphone charging.', 7990.00, 0, 12, 'assets/images/products/anker-10000mah.jpg', 'available', '2026-09-12 10:52:04', '2026-09-12 10:52:04'),
(31, 'MCDODO 20000mAh Power Bank', 'Power Banks', 'MCDODO', '20000mAh high-capacity power bank designed for multiple device charging.', 9990.00, 0, 12, 'assets/images/products/mcdodo-20000mah.jpg', 'available', '2026-09-12 10:52:04', '2026-09-12 10:52:04'),
(32, 'Kakusiga 10000mAh Power Bank', 'Power Banks', 'Kakusiga', 'Affordable 10000mAh portable power bank with multiple charging ports.', 2490.00, 0, 6, 'assets/images/products/kakusiga-10000mah.jpg', 'available', '2026-09-12 10:52:04', '2026-09-12 10:52:04'),
(33, 'Xundd iPhone 15 Pro Max Beatle Case', 'Cases & Covers', 'Xundd', 'Shock-resistant transparent protective case designed for iPhone 15 Pro Max.', 3010.00, 0, 3, 'assets/images/products/xundd-15-pro-max-beatle.jpg', 'available', '2026-09-12 10:52:04', '2026-09-12 10:52:04'),
(36, 'Xundd iPhone 16 Case', 'Cases & Covers', 'Xundd', 'Slim protective transparent case designed for iPhone 16.', 2990.00, 0, 3, 'assets/images/products/xundd-iphone-16.jpg', 'available', '2026-09-12 10:52:04', '2026-09-12 10:52:04'),
(38, 'Samsung Galaxy S24 Silicone Case', 'Cases & Covers', 'Samsung', 'Protective silicone case designed for Samsung Galaxy S24.', 3990.00, 0, 3, 'assets/images/products/samsung-s24-silicone.jpg', 'available', '2026-09-12 10:52:04', '2026-09-12 10:52:04'),
(39, 'Samsung Galaxy A55 Protective Case', 'Cases & Covers', 'Samsung', 'Protective back cover designed for Samsung Galaxy A55.', 2490.00, 0, 3, 'assets/images/products/samsung-a55-case.jpg', 'available', '2026-09-12 10:52:04', '2026-09-12 10:52:04'),
(43, 'Redmi Note 14 Protective Case', 'Cases & Covers', 'Xiaomi', 'Protective back cover for compatible Redmi Note 14 smartphones.', 1690.00, 0, 3, 'assets/images/products/redmi-note-14-case.jpg', 'available', '2026-09-12 10:52:04', '2026-09-12 10:52:04'),
(44, 'Nothing Phone 2a Transparent Case', 'Cases & Covers', 'Generic', 'Clear protective case designed for Nothing Phone 2a.', 1990.00, 0, 3, 'assets/images/products/nothing-phone-2a-case.jpg', 'available', '2026-09-12 10:52:04', '2026-09-12 10:52:04'),
(49, 'iPhone 16 Tempered Glass', 'Tempered Glass', 'Generic', 'Scratch-resistant tempered glass screen protector for iPhone 16.', 1190.00, 0, 0, 'assets/images/products/iphone-16-glass.jpg', 'available', '2026-09-12 10:52:04', '2026-09-12 10:52:04'),
(50, 'Samsung Galaxy S24 Tempered Glass', 'Tempered Glass', 'Generic', 'Premium tempered glass screen protector for Samsung Galaxy S24.', 1290.00, 0, 0, 'assets/images/products/s24-glass.jpg', 'available', '2026-09-12 10:52:04', '2026-09-12 10:52:04'),
(51, 'Samsung Galaxy A55 Tempered Glass', 'Tempered Glass', 'Generic', 'Tempered glass screen protector for Samsung Galaxy A55.', 990.00, 0, 0, 'assets/images/products/a55-glass.jpg', 'available', '2026-09-12 10:52:04', '2026-09-12 10:52:04'),
(52, 'Samsung Galaxy A35 Tempered Glass', 'Tempered Glass', 'Generic', 'Tempered glass screen protector for Samsung Galaxy A35.', 990.00, 0, 0, 'assets/images/products/a35-glass.jpg', 'available', '2026-09-12 10:52:04', '2026-09-12 10:52:04'),
(54, 'Redmi Note 13 Pro Tempered Glass', 'Tempered Glass', 'Generic', 'Protective tempered glass designed for Redmi Note 13 Pro.', 890.00, 0, 0, 'assets/images/products/redmi-note-13-pro-glass.png', 'available', '2026-09-12 10:52:04', '2026-10-13 08:03:56'),
(56, 'Universal 9H Full Glue Tempered Glass', 'Tempered Glass', 'Generic', 'Full-glue 9H tempered glass protection for compatible smartphones.', 690.00, 0, 0, 'assets/images/products/universal-tempered-glass.jpg', 'available', '2026-09-12 10:52:04', '2026-09-12 10:52:04'),
(65, 'MCDODO Wireless Earbuds', 'Earbuds', 'MCDODO', 'Compact Bluetooth wireless earbuds with charging case.', 4990.00, 0, 6, 'assets/images/products/mcdodo-earbuds.jpeg', 'available', '2026-09-12 10:52:04', '2026-09-23 08:02:12'),
(66, 'Baseus Bowie Wireless Earbuds', 'Earbuds', 'Baseus', 'Wireless Bluetooth earbuds designed for everyday listening and phone calls.', 6990.00, 0, 12, 'assets/images/products/baseus-bowie.jpg', 'available', '2026-09-12 10:52:04', '2026-09-12 10:52:04'),
(69, 'Anker Soundcore Bluetooth Headphones', 'Headphones', 'Anker', 'Comfortable Bluetooth headphones for music, entertainment and calls.', 11990.00, 0, 12, 'assets/images/products/anker-headphones.jpeg', 'available', '2026-09-12 10:52:04', '2026-09-23 08:10:26'),
(70, 'MCDODO Wireless Headphones', 'Headphones', 'MCDODO', 'Foldable wireless Bluetooth headphones suitable for everyday entertainment.', 6990.00, 0, 6, 'assets/images/products/mcdodo-headphones.jpg', 'available', '2026-09-12 10:52:04', '2026-09-12 10:52:04'),
(74, 'Soundcore Portable Bluetooth Speaker', 'Speakers', 'Soundcore', 'Portable Bluetooth speaker designed for powerful everyday audio.', 14990.00, 0, 12, 'assets/images/products/soundcore-speaker.jpg', 'available', '2026-09-12 10:52:04', '2026-09-12 10:52:04'),
(75, 'Anker Wireless Charging Pad', 'Wireless Chargers', 'Anker', 'Compact wireless charging pad for compatible smartphones.', 5990.00, 0, 12, 'assets/images/products/anker-wireless-pad.jpg', 'available', '2026-09-12 10:52:04', '2026-09-12 10:52:04'),
(77, 'Xiaomi 30W Wireless Car Charger', 'Wireless Chargers', 'Xiaomi', 'Wireless car charging mount designed for compatible smartphones.', 9490.00, 0, 12, 'assets/images/products/xiaomi-wireless-car-charger.jpg', 'available', '2026-09-12 10:52:04', '2026-09-12 10:52:04'),
(83, 'Samsung Galaxy A16 4G 8GB/256GB', 'Smartphones', 'Samsung', 'Samsung mid-range smartphone with 8GB RAM, 256GB storage and Super AMOLED display.', 56990.00, 1, 12, 'assets/images/products/samsung-a16-4g.jpg', 'available', '2026-09-12 10:59:18', '2026-09-23 07:38:30'),
(91, 'Xiaomi Redmi A5', 'Smartphones', 'Xiaomi', 'Affordable Redmi smartphone designed for everyday communication, entertainment and social media.', 32999.00, 1, 12, 'assets/images/products/xiaomi-redmi-a5.png', 'available', '2026-09-12 10:59:18', '2026-09-23 08:11:52'),
(98, 'HONOR Play 10 4G', 'Smartphones', 'HONOR', 'Affordable HONOR smartphone designed for everyday use with a large display and long battery life.', 39999.00, 1, 12, 'assets/images/products/honor-play-10-4g.jpg', 'available', '2026-09-12 10:59:18', '2026-09-23 07:40:00'),
(100, 'HONOR X5C Plus 4G', 'Smartphones', 'HONOR', 'Affordable HONOR smartphone with improved storage, performance and long-lasting battery.', 54999.00, 1, 12, 'assets/images/products/honor-x5c-plus-4g.jpg', 'available', '2026-09-12 10:59:18', '2026-09-23 07:40:23'),
(101, 'HONOR X6C 4G', 'Smartphones', 'HONOR', 'Budget-friendly HONOR smartphone with large display and high-capacity battery.', 56999.00, 1, 12, 'assets/images/products/honor-x6c-4g.jpg', 'available', '2026-09-12 10:59:18', '2026-09-23 07:40:46'),
(102, 'HONOR X7D 4G', 'Smartphones', 'HONOR', 'Mid-range HONOR smartphone with premium styling, large display and long battery life.', 79999.00, 1, 12, 'assets/images/products/honor-x7d-4g.png', 'available', '2026-09-12 10:59:18', '2026-09-23 08:12:38'),
(104, 'HONOR Magic 7 Pro', 'Smartphones', 'HONOR', 'Premium flagship smartphone featuring advanced cameras, powerful performance and a high-quality display.', 300000.00, 1, 12, 'assets/images/products/honor-magic-7-pro.jpg', 'available', '2026-09-12 10:59:18', '2026-09-23 07:41:41'),
(108, 'Infinix Note 60', 'Smartphones', 'Infinix', 'Performance-focused Infinix smartphone with large display, powerful hardware and fast charging.', 139999.00, 1, 12, 'assets/images/products/infinix-note-60.jpg', 'available', '2026-09-12 10:59:18', '2026-09-23 07:42:00'),
(110, 'OnePlus Nord CE 3 Lite 5G', 'Smartphones', 'OnePlus', 'Affordable OnePlus 5G smartphone with fast charging, large display and smooth everyday performance.', 74999.00, 1, 12, 'assets/images/products/oneplus-nord-ce3-lite-5g.jpg', 'available', '2026-09-12 10:59:18', '2026-09-23 07:42:23'),
(112, 'Realme C85', 'Smartphones', 'Realme', 'Affordable Realme smartphone with large display, strong battery life and modern design.', 79999.00, 1, 12, 'assets/images/products/realme-c85.jpg', 'available', '2026-09-12 10:59:18', '2026-09-23 07:42:43'),
(113, 'JBL Tune 510BT Wireless On-Ear Headphones', 'Headphones', 'JBL', 'Lightweight wireless on-ear headphones with up to 40 hours of battery life and JBL Pure Bass sound.', 8990.00, 0, 12, 'assets/images/products/jbl-tune-510bt.jpg', 'available', '2026-09-23 03:30:00', '2026-09-23 03:30:00'),
(114, 'JBL Live 660NC Wireless Noise Cancelling Headphones', 'Headphones', 'JBL', 'Over-ear wireless headphones with active noise cancelling, JBL Signature Sound and hands-free voice assistant.', 24990.00, 0, 12, 'assets/images/products/jbl-live-660nc.jpg', 'available', '2026-09-23 03:30:00', '2026-09-23 03:30:00'),
(115, 'JBL Flip 6 Portable Bluetooth Speaker', 'Speakers', 'JBL', 'Waterproof portable Bluetooth speaker with powerful JBL Original Pro Sound and up to 12 hours of playtime.', 22990.00, 0, 12, 'assets/images/products/jbl-flip-6.jpg', 'available', '2026-09-23 03:30:00', '2026-09-23 03:30:00'),
(116, 'JBL Charge 5 Portable Bluetooth Speaker', 'Speakers', 'JBL', 'Powerful portable speaker with built-in power bank, IP67 waterproof and dustproof design, up to 20 hours playtime.', 34990.00, 0, 12, 'assets/images/products/jbl-charge-5.jpg', 'available', '2026-09-23 03:30:00', '2026-09-23 03:30:00'),
(117, 'Sony WH-CH520 Wireless Headphones', 'Headphones', 'Sony', 'Lightweight wireless on-ear headphones with up to 50 hours battery life and quick charging support.', 7990.00, 0, 12, 'assets/images/products/sony-wh-ch520.jpg', 'available', '2026-09-23 03:30:00', '2026-09-23 03:30:00'),
(118, 'Sony WH-1000XM5 Wireless Noise Cancelling Headphones', 'Headphones', 'Sony', 'Industry-leading noise cancelling over-ear headphones with exceptional sound quality and up to 30 hours battery life.', 89990.00, 0, 12, 'assets/images/products/sony-wh-1000xm5.png', 'available', '2026-09-23 03:30:00', '2026-09-23 09:06:37'),
(119, 'Sony SRS-XB13 Portable Bluetooth Speaker', 'Speakers', 'Sony', 'Compact, rugged wireless speaker with punchy Extra Bass sound and up to 16 hours of battery life.', 9990.00, 0, 12, 'assets/images/products/sony-srs-xb13.jpg', 'available', '2026-09-23 03:30:00', '2026-09-23 03:30:00'),
(120, 'Sony SRS-XG300 Portable Bluetooth Speaker', 'Speakers', 'Sony', 'High-power portable party speaker with X-Balanced Speaker Unit, built-in lighting and up to 25 hours battery life.', 59990.00, 0, 12, 'assets/images/products/sony-srs-xg300.jpg', 'available', '2026-09-23 03:30:00', '2026-09-23 03:30:00'),
(121, 'Redmi Note 14 Pro 5G', 'Smartphones', 'Xiaomi', 'The Xiaomi Redmi Note 14 Pro 5G is a powerful smartphone featuring a vibrant AMOLED display, 5G connectivity, a high-resolution camera system, and a large battery. It delivers smooth performance for everyday use, photography, gaming, entertainment, and multitasking.', 89900.00, 1, 12, 'assets/images/products/product_1790165266_1e76a68c.jpg', 'available', '2026-09-23 11:21:18', '2026-09-23 12:07:46');

-- --------------------------------------------------------

--
-- Table structure for table `product_images`
--

CREATE TABLE `product_images` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `image_url` varchar(500) NOT NULL,
  `alt_text` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `product_images`
--

INSERT INTO `product_images` (`id`, `product_id`, `image_url`, `alt_text`, `sort_order`, `is_primary`, `created_at`) VALUES
(1, 3, 'assets/images/products/samsung-15w-charger.jpg', 'Samsung 15W Fast Charger', 0, 1, '2026-09-23 08:23:19'),
(2, 6, 'assets/images/products/anker-20w-charger.png', 'Anker 20W USB-C Charger', 0, 1, '2026-09-23 08:23:19'),
(3, 10, 'assets/images/products/mcdodo-33w-gan.jpg', 'MCDODO 33W GaN Charger', 0, 1, '2026-09-23 08:23:19'),
(4, 11, 'assets/images/products/mcdodo-67w-gan.jpg', 'MCDODO 67W GaN Charger', 0, 1, '2026-09-23 08:23:19'),
(5, 16, 'assets/images/products/anker-usbc-cable.jpg', 'Anker USB-C to USB-C Cable', 0, 1, '2026-09-23 08:23:19'),
(6, 17, 'assets/images/products/anker-usbc-lightning.jpg', 'Anker USB-C to Lightning Cable', 0, 1, '2026-09-23 08:23:19'),
(7, 23, 'assets/images/products/micro-usb-cable.jpg', 'Micro USB Fast Charging Cable', 0, 1, '2026-09-23 08:23:19'),
(8, 24, 'assets/images/products/braided-usbc-cable.jpg', 'Braided USB-C Cable 2M', 0, 1, '2026-09-23 08:23:19'),
(9, 27, 'assets/images/products/anker-10000mah.jpg', 'Anker 10000mAh Power Bank', 0, 1, '2026-09-23 08:23:19'),
(10, 31, 'assets/images/products/mcdodo-20000mah.jpg', 'MCDODO 20000mAh Power Bank', 0, 1, '2026-09-23 08:23:19'),
(11, 32, 'assets/images/products/kakusiga-10000mah.jpg', 'Kakusiga 10000mAh Power Bank', 0, 1, '2026-09-23 08:23:19'),
(12, 33, 'assets/images/products/xundd-15-pro-max-beatle.jpg', 'Xundd iPhone 15 Pro Max Beatle Case', 0, 1, '2026-09-23 08:23:19'),
(13, 36, 'assets/images/products/xundd-iphone-16.jpg', 'Xundd iPhone 16 Case', 0, 1, '2026-09-23 08:23:19'),
(14, 38, 'assets/images/products/samsung-s24-silicone.jpg', 'Samsung Galaxy S24 Silicone Case', 0, 1, '2026-09-23 08:23:19'),
(15, 39, 'assets/images/products/samsung-a55-case.jpg', 'Samsung Galaxy A55 Protective Case', 0, 1, '2026-09-23 08:23:19'),
(16, 43, 'assets/images/products/redmi-note-14-case.jpg', 'Redmi Note 14 Protective Case', 0, 1, '2026-09-23 08:23:19'),
(17, 44, 'assets/images/products/nothing-phone-2a-case.jpg', 'Nothing Phone 2a Transparent Case', 0, 1, '2026-09-23 08:23:19'),
(18, 49, 'assets/images/products/iphone-16-glass.jpg', 'iPhone 16 Tempered Glass', 0, 1, '2026-09-23 08:23:19'),
(19, 50, 'assets/images/products/s24-glass.jpg', 'Samsung Galaxy S24 Tempered Glass', 0, 1, '2026-09-23 08:23:19'),
(20, 51, 'assets/images/products/a55-glass.jpg', 'Samsung Galaxy A55 Tempered Glass', 0, 1, '2026-09-23 08:23:19'),
(21, 52, 'assets/images/products/a35-glass.jpg', 'Samsung Galaxy A35 Tempered Glass', 0, 1, '2026-09-23 08:23:19'),
(22, 54, 'assets/images/products/redmi-note-13-pro-glass.png', 'Redmi Note 13 Pro Tempered Glass', 0, 1, '2026-09-23 08:23:19'),
(23, 56, 'assets/images/products/universal-tempered-glass.jpg', 'Universal 9H Full Glue Tempered Glass', 0, 1, '2026-09-23 08:23:19'),
(24, 65, 'assets/images/products/mcdodo-earbuds.jpeg', 'MCDODO Wireless Earbuds', 0, 1, '2026-09-23 08:23:19'),
(25, 66, 'assets/images/products/baseus-bowie.jpg', 'Baseus Bowie Wireless Earbuds', 0, 1, '2026-09-23 08:23:19'),
(26, 69, 'assets/images/products/anker-headphones.jpeg', 'Anker Soundcore Bluetooth Headphones', 0, 1, '2026-09-23 08:23:19'),
(27, 70, 'assets/images/products/mcdodo-headphones.jpg', 'MCDODO Wireless Headphones', 0, 1, '2026-09-23 08:23:19'),
(28, 74, 'assets/images/products/soundcore-speaker.jpg', 'Soundcore Portable Bluetooth Speaker', 0, 1, '2026-09-23 08:23:19'),
(29, 75, 'assets/images/products/anker-wireless-pad.jpg', 'Anker Wireless Charging Pad', 0, 1, '2026-09-23 08:23:19'),
(30, 77, 'assets/images/products/xiaomi-wireless-car-charger.jpg', 'Xiaomi 30W Wireless Car Charger', 0, 1, '2026-09-23 08:23:19'),
(31, 83, 'assets/images/products/samsung-a16-4g.jpg', 'Samsung Galaxy A16 4G 8GB/256GB', 0, 1, '2026-09-23 08:23:19'),
(32, 91, 'assets/images/products/xiaomi-redmi-a5.png', 'Xiaomi Redmi A5', 0, 1, '2026-09-23 08:23:19'),
(33, 98, 'assets/images/products/honor-play-10-4g.jpg', 'HONOR Play 10 4G', 0, 1, '2026-09-23 08:23:19'),
(34, 100, 'assets/images/products/honor-x5c-plus-4g.jpg', 'HONOR X5C Plus 4G', 0, 1, '2026-09-23 08:23:19'),
(35, 101, 'assets/images/products/honor-x6c-4g.jpg', 'HONOR X6C 4G', 0, 1, '2026-09-23 08:23:19'),
(36, 102, 'assets/images/products/honor-x7d-4g.png', 'HONOR X7D 4G', 0, 1, '2026-09-23 08:23:19'),
(37, 104, 'assets/images/products/honor-magic-7-pro.jpg', 'HONOR Magic 7 Pro', 0, 1, '2026-09-23 08:23:19'),
(38, 108, 'assets/images/products/infinix-note-60.jpg', 'Infinix Note 60', 0, 1, '2026-09-23 08:23:19'),
(39, 110, 'assets/images/products/oneplus-nord-ce3-lite-5g.jpg', 'OnePlus Nord CE 3 Lite 5G', 0, 1, '2026-09-23 08:23:19'),
(40, 112, 'assets/images/products/realme-c85.jpg', 'Realme C85', 0, 1, '2026-09-23 08:23:19'),
(41, 113, 'assets/images/products/jbl-tune-510bt.jpg', 'JBL Tune 510BT Wireless On-Ear Headphones', 0, 1, '2026-09-23 03:30:00'),
(42, 114, 'assets/images/products/jbl-live-660nc.jpg', 'JBL Live 660NC Wireless Noise Cancelling Headphones', 0, 1, '2026-09-23 03:30:00'),
(43, 115, 'assets/images/products/jbl-flip-6.jpg', 'JBL Flip 6 Portable Bluetooth Speaker', 0, 1, '2026-09-23 03:30:00'),
(44, 116, 'assets/images/products/jbl-charge-5.jpg', 'JBL Charge 5 Portable Bluetooth Speaker', 0, 1, '2026-09-23 03:30:00'),
(45, 117, 'assets/images/products/sony-wh-ch520.jpg', 'Sony WH-CH520 Wireless Headphones', 0, 1, '2026-09-23 03:30:00'),
(46, 118, 'assets/images/products/sony-wh-1000xm5.jpg', 'Sony WH-1000XM5 Wireless Noise Cancelling Headphones', 0, 1, '2026-09-23 03:30:00'),
(47, 119, 'assets/images/products/sony-srs-xb13.jpg', 'Sony SRS-XB13 Portable Bluetooth Speaker', 0, 1, '2026-09-23 03:30:00'),
(48, 120, 'assets/images/products/sony-srs-xg300.jpg', 'Sony SRS-XG300 Portable Bluetooth Speaker', 0, 1, '2026-09-23 03:30:00');

-- --------------------------------------------------------

--
-- Table structure for table `product_specifications`
--

CREATE TABLE `product_specifications` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `spec_group` varchar(100) NOT NULL,
  `spec_name` varchar(150) NOT NULL,
  `spec_value` text NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `product_specifications`
--

INSERT INTO `product_specifications` (`id`, `product_id`, `spec_group`, `spec_name`, `spec_value`, `sort_order`, `created_at`) VALUES
(1, 83, 'Display', 'Display', '6.7-inch Super AMOLED, 90Hz', 10, '2026-09-12 07:08:57'),
(2, 83, 'Performance', 'Processor', 'MediaTek Helio G99', 20, '2026-09-12 07:08:57'),
(3, 83, 'Memory', 'RAM', '8GB', 30, '2026-09-12 07:08:57'),
(4, 83, 'Memory', 'Storage', '256GB', 40, '2026-09-12 07:08:57'),
(5, 83, 'Camera', 'Rear Camera', '50MP main + 5MP ultra-wide + 2MP macro', 50, '2026-09-12 07:08:57'),
(6, 83, 'Camera', 'Front Camera', '13MP', 60, '2026-09-12 07:08:57'),
(7, 83, 'Battery', 'Battery', '5000mAh', 70, '2026-09-12 07:08:57'),
(8, 83, 'Charging', 'Charging', '25W wired charging', 80, '2026-09-12 07:08:57'),
(9, 83, 'Network', 'Network', '4G LTE, Dual SIM', 90, '2026-09-12 07:08:57'),
(10, 83, 'Software', 'Operating System', 'Android 14 with One UI 6.1', 100, '2026-09-12 07:08:57'),
(11, 83, 'Durability', 'Water/Dust Resistance', 'IP54', 110, '2026-09-12 07:08:57'),
(12, 91, 'Display', 'Display', '6.88-inch HD+ display, up to 120Hz', 10, '2026-09-12 07:08:57'),
(13, 91, 'Performance', 'Processor', 'UNISOC T7250, octa-core up to 1.8GHz', 20, '2026-09-12 07:08:57'),
(14, 91, 'Memory', 'Configurations', '3GB/64GB or 4GB/128GB', 30, '2026-09-12 07:08:57'),
(15, 91, 'Memory', 'Expandable Storage', 'microSD support', 40, '2026-09-12 07:08:57'),
(16, 91, 'Camera', 'Rear Camera', '32MP AI dual camera', 50, '2026-09-12 07:08:57'),
(17, 91, 'Camera', 'Front Camera', '8MP', 60, '2026-09-12 07:08:57'),
(18, 91, 'Battery', 'Battery', '5200mAh typical', 70, '2026-09-12 07:08:57'),
(19, 91, 'Charging', 'Charging', '15W via USB Type-C', 80, '2026-09-12 07:08:57'),
(20, 91, 'Network', 'Network', '4G / 3G / 2G, Dual SIM + microSD', 90, '2026-09-12 07:08:57'),
(21, 91, 'Connectivity', 'Bluetooth', 'Bluetooth 5.2', 100, '2026-09-12 07:08:57'),
(22, 91, 'Software', 'Operating System', 'Android 15 (Go Edition)', 110, '2026-09-12 07:08:57'),
(23, 91, 'Security', 'Security', 'Side fingerprint sensor and AI face unlock', 120, '2026-09-12 07:08:57'),
(32, 100, 'Display', 'Display', '6.74-inch display, 90Hz', 10, '2026-09-12 07:08:57'),
(33, 100, 'Performance', 'Processor', 'MediaTek Helio G81', 20, '2026-09-12 07:08:57'),
(34, 100, 'Memory', 'RAM / Storage', '4GB + 128GB, with HONOR RAM Turbo', 30, '2026-09-12 07:08:57'),
(35, 100, 'Memory', 'Expandable Storage', 'microSD expansion up to 1TB', 40, '2026-09-12 07:08:57'),
(36, 100, 'Camera', 'Rear Camera', '50MP ultra-clear main camera', 50, '2026-09-12 07:08:57'),
(37, 100, 'Camera', 'Front Camera', 'Front camera; model specification varies by region', 60, '2026-09-12 07:08:57'),
(38, 100, 'Battery', 'Battery', '5260mAh typical', 70, '2026-09-12 07:08:57'),
(39, 100, 'Network', 'Network', '4G LTE', 80, '2026-09-12 07:08:57'),
(40, 100, 'Security', 'Security', 'Side fingerprint sensor', 90, '2026-09-12 07:08:57'),
(41, 101, 'Display', 'Display', 'Large high-refresh-rate display', 10, '2026-09-12 07:08:57'),
(42, 101, 'Performance', 'Processor', 'Octa-core platform', 20, '2026-09-12 07:08:57'),
(43, 101, 'Memory', 'Storage', 'Configuration varies by market', 30, '2026-09-12 07:08:57'),
(44, 101, 'Camera', 'Rear Camera', '50MP main camera', 40, '2026-09-12 07:08:57'),
(45, 101, 'Battery', 'Battery', 'High-capacity battery', 50, '2026-09-12 07:08:57'),
(46, 101, 'Charging', 'Charging', 'Fast wired charging; region dependent', 60, '2026-09-12 07:08:57'),
(47, 101, 'Network', 'Network', '4G LTE', 70, '2026-09-12 07:08:57'),
(48, 101, 'Security', 'Security', 'Fingerprint security', 80, '2026-09-12 07:08:57'),
(57, 104, 'Display', 'Display', 'Premium LTPO OLED display with high refresh rate', 10, '2026-09-12 07:08:57'),
(58, 104, 'Performance', 'Processor', 'Qualcomm Snapdragon 8 Elite Mobile Platform', 20, '2026-09-12 07:08:57'),
(59, 104, 'Camera', 'Telephoto', '200MP ultra-clear telephoto camera', 30, '2026-09-12 07:08:57'),
(60, 104, 'Camera', 'Rear Camera', 'Multi-camera flagship system with advanced stabilization', 40, '2026-09-12 07:08:57'),
(61, 104, 'Battery', 'Battery', 'Third-generation HONOR silicon-carbon battery', 50, '2026-09-12 07:08:57'),
(62, 104, 'Charging', 'Wired Charging', '100W HONOR Wired SuperCharge', 60, '2026-09-12 07:08:57'),
(63, 104, 'Charging', 'Wireless Charging', '80W HONOR Wireless SuperCharge', 70, '2026-09-12 07:08:57'),
(64, 104, 'Durability', 'Protection', 'Water and dust resistance; market/region dependent', 80, '2026-09-12 07:08:57'),
(65, 104, 'Software', 'Operating System', 'MagicOS', 90, '2026-09-12 07:08:57'),
(66, 108, 'Display', 'Display', 'Large high-refresh-rate smartphone display', 10, '2026-09-12 07:08:57'),
(67, 108, 'Performance', 'Processor', 'Model/market configuration dependent', 20, '2026-09-12 07:08:57'),
(68, 108, 'Memory', 'RAM / Storage', 'Configuration dependent', 30, '2026-09-12 07:08:57'),
(69, 108, 'Camera', 'Rear Camera', 'High-resolution main camera system', 40, '2026-09-12 07:08:57'),
(70, 108, 'Battery', 'Battery', 'Large-capacity battery with fast charging', 50, '2026-09-12 07:08:57'),
(71, 108, 'Network', 'Network', '4G/5G availability depends on exact variant', 60, '2026-09-12 07:08:57'),
(72, 108, 'Software', 'Operating System', 'Android with XOS', 70, '2026-09-12 07:08:57'),
(73, 110, 'Display', 'Display', '6.72-inch LCD, 120Hz', 10, '2026-09-12 07:08:57'),
(74, 110, 'Performance', 'Processor', 'Qualcomm Snapdragon 695 5G', 20, '2026-09-12 07:08:57'),
(75, 110, 'Memory', 'RAM / Storage', 'Up to 8GB RAM and 256GB storage', 30, '2026-09-12 07:08:57'),
(76, 110, 'Camera', 'Rear Camera', '108MP main + 2MP depth + 2MP macro', 40, '2026-09-12 07:08:57'),
(77, 110, 'Camera', 'Front Camera', '16MP', 50, '2026-09-12 07:08:57'),
(78, 110, 'Battery', 'Battery', '5000mAh', 60, '2026-09-12 07:08:57'),
(79, 110, 'Charging', 'Charging', '67W SUPERVOOC', 70, '2026-09-12 07:08:57'),
(80, 110, 'Network', 'Network', '5G / 4G LTE, Dual SIM', 80, '2026-09-12 07:08:57'),
(81, 110, 'Software', 'Operating System', 'OxygenOS based on Android', 90, '2026-09-12 07:08:57'),
(82, 112, 'Display', 'Display', '6.8-inch LCD, 1570 × 720, up to 144Hz', 10, '2026-09-12 07:08:57'),
(83, 112, 'Performance', 'Processor', 'Qualcomm Snapdragon 685, octa-core', 20, '2026-09-12 07:08:57'),
(84, 112, 'Memory', 'RAM / Storage', '6GB + 128GB or 8GB + 256GB; market dependent', 30, '2026-09-12 07:08:57'),
(85, 112, 'Camera', 'Rear Camera', '50MP AI camera, f/1.8', 40, '2026-09-12 07:08:57'),
(86, 112, 'Camera', 'Front Camera', '8MP, f/2.0', 50, '2026-09-12 07:08:57'),
(87, 112, 'Battery', 'Battery', '7000mAh typical', 60, '2026-09-12 07:08:57'),
(88, 112, 'Charging', 'Charging', '45W fast charging', 70, '2026-09-12 07:08:57'),
(89, 112, 'Network', 'Network', '4G LTE, Dual SIM', 80, '2026-09-12 07:08:57'),
(90, 112, 'Connectivity', 'Bluetooth', 'Bluetooth 5.0', 90, '2026-09-12 07:08:57'),
(91, 112, 'Software', 'Operating System', 'realme UI 6.0 based on Android 15', 100, '2026-09-12 07:08:57'),
(95, 6, 'Charging', 'Output', '20W Power Delivery', 10, '2026-09-12 07:08:57'),
(96, 6, 'Charging', 'Technology', 'USB Power Delivery', 20, '2026-09-12 07:08:57'),
(97, 6, 'Port', 'Connector', 'USB-C', 30, '2026-09-12 07:08:57'),
(98, 6, 'Design', 'Type', 'Compact wall charger', 40, '2026-09-12 07:08:57'),
(99, 10, 'Charging', 'Maximum Output', '33W', 10, '2026-09-12 07:08:57'),
(100, 10, 'Charging', 'Technology', 'GaN fast-charging technology', 20, '2026-09-12 07:08:57'),
(101, 10, 'Compatibility', 'Use', 'Smartphones and other compatible USB devices', 30, '2026-09-12 07:08:57'),
(116, 27, 'Battery', 'Capacity', '10000mAh', 10, '2026-09-12 07:08:57'),
(117, 27, 'Design', 'Type', 'Portable power bank', 20, '2026-09-12 07:08:57'),
(118, 27, 'Use', 'Compatibility', 'USB charging devices', 30, '2026-09-12 07:08:57'),
(119, 31, 'Battery', 'Capacity', '20000mAh', 10, '2026-09-12 07:08:57'),
(120, 31, 'Design', 'Type', 'High-capacity portable power bank', 20, '2026-09-12 07:08:57'),
(121, 31, 'Use', 'Compatibility', 'Multiple USB charging devices', 30, '2026-09-12 07:08:57'),
(128, 36, 'Compatibility', 'Device', 'iPhone 16', 10, '2026-09-12 07:08:57'),
(129, 36, 'Protection', 'Type', 'Transparent protective case', 20, '2026-09-12 07:08:57'),
(130, 36, 'Design', 'Style', 'Slim protective design', 30, '2026-09-12 07:08:57'),
(133, 39, 'Compatibility', 'Device', 'Samsung Galaxy A55', 10, '2026-09-12 07:08:57'),
(134, 39, 'Protection', 'Type', 'Protective back cover', 20, '2026-09-12 07:08:57'),
(135, 43, 'Compatibility', 'Device', 'Redmi Note 14', 10, '2026-09-12 07:08:57'),
(136, 43, 'Protection', 'Type', 'Protective back cover', 20, '2026-09-12 07:08:57'),
(139, 49, 'Compatibility', 'Device', 'iPhone 16', 10, '2026-09-12 07:08:57'),
(140, 49, 'Protection', 'Type', 'Tempered glass screen protector', 20, '2026-09-12 07:08:57'),
(149, 56, 'Protection', 'Type', '9H full-glue tempered glass', 10, '2026-09-12 07:08:57'),
(150, 56, 'Compatibility', 'Device', 'Compatible smartphones', 20, '2026-09-12 07:08:57'),
(151, 65, 'Connectivity', 'Type', 'Bluetooth wireless earbuds', 10, '2026-09-12 07:08:57'),
(152, 65, 'Design', 'Type', 'True wireless earbuds with charging case', 20, '2026-09-12 07:08:57'),
(153, 65, 'Use', 'Purpose', 'Music, calls and everyday listening', 30, '2026-09-12 07:08:57'),
(154, 66, 'Connectivity', 'Type', 'Bluetooth wireless earbuds', 10, '2026-09-12 07:08:57'),
(155, 66, 'Design', 'Series', 'Baseus Bowie', 20, '2026-09-12 07:08:57'),
(156, 66, 'Use', 'Purpose', 'Music, calls and everyday listening', 30, '2026-09-12 07:08:57'),
(157, 69, 'Connectivity', 'Type', 'Bluetooth 5.0 wireless over-ear headphones', 10, '2026-09-23 03:45:00'),
(158, 69, 'Audio', 'Driver', '40mm dynamic driver with enhanced bass', 20, '2026-09-23 03:45:00'),
(159, 69, 'Battery', 'Battery Life', 'Up to 30 hours playback on a single charge', 30, '2026-09-23 03:45:00'),
(160, 69, 'Use', 'Purpose', 'Music, entertainment and hands-free calls', 40, '2026-09-23 03:45:00'),
(161, 70, 'Connectivity', 'Type', 'Bluetooth 5.0 wireless over-ear headphones', 10, '2026-09-23 03:45:00'),
(162, 70, 'Design', 'Type', 'Foldable, adjustable headband design', 20, '2026-09-23 03:45:00'),
(163, 70, 'Battery', 'Battery Life', 'Up to 20 hours playback on a single charge', 30, '2026-09-23 03:45:00'),
(164, 70, 'Use', 'Purpose', 'Everyday entertainment and calls', 40, '2026-09-23 03:45:00'),
(165, 74, 'Connectivity', 'Type', 'Portable Bluetooth speaker', 10, '2026-09-12 07:08:57'),
(166, 74, 'Use', 'Purpose', 'Portable everyday audio', 20, '2026-09-12 07:08:57'),
(167, 75, 'Charging', 'Type', 'Wireless charging pad', 10, '2026-09-12 07:08:57'),
(168, 75, 'Compatibility', 'Use', 'Compatible Qi wireless charging devices', 20, '2026-09-12 07:08:57'),
(169, 77, 'Charging', 'Type', 'Wireless car charger', 10, '2026-09-12 07:08:57'),
(170, 77, 'Charging', 'Output', 'Up to 30W', 20, '2026-09-12 07:08:57'),
(171, 77, 'Use', 'Purpose', 'Wireless charging while mounted in a vehicle', 30, '2026-09-12 07:08:57'),
(176, 114, 'Connectivity', 'Type', 'Bluetooth 5.0 wireless over-ear headphones', 10, '2026-09-23 03:30:00'),
(177, 114, 'Audio', 'Noise Cancellation', 'Adaptive Active Noise Cancelling with Smart Ambient', 20, '2026-09-23 03:30:00'),
(178, 114, 'Battery', 'Battery Life', 'Up to 40 hours (ANC on), 65 hours (ANC off)', 30, '2026-09-23 03:30:00'),
(179, 114, 'Use', 'Purpose', 'Travel, commuting and immersive listening', 40, '2026-09-23 03:30:00'),
(180, 115, 'Connectivity', 'Type', 'Bluetooth 5.1 portable speaker', 10, '2026-09-23 03:30:00'),
(181, 115, 'Durability', 'Water/Dust Resistance', 'IP67 waterproof and dustproof', 20, '2026-09-23 03:30:00'),
(182, 115, 'Battery', 'Battery Life', 'Up to 12 hours playback', 30, '2026-09-23 03:30:00'),
(183, 115, 'Use', 'Purpose', 'Outdoor and poolside portable audio', 40, '2026-09-23 03:30:00'),
(184, 116, 'Connectivity', 'Type', 'Bluetooth 5.1 portable speaker', 10, '2026-09-23 03:30:00'),
(185, 116, 'Durability', 'Water/Dust Resistance', 'IP67 waterproof and dustproof', 20, '2026-09-23 03:30:00'),
(186, 116, 'Battery', 'Battery Life', 'Up to 20 hours playback, built-in power bank', 30, '2026-09-23 03:30:00'),
(187, 116, 'Use', 'Purpose', 'Outdoor parties and extended use', 40, '2026-09-23 03:30:00'),
(191, 118, 'Connectivity', 'Type', 'Bluetooth 5.2 wireless over-ear headphones', 10, '2026-09-23 03:30:00'),
(192, 118, 'Audio', 'Noise Cancellation', 'Dual Noise Sensor technology with industry-leading ANC', 20, '2026-09-23 03:30:00'),
(193, 118, 'Battery', 'Battery Life', 'Up to 30 hours playback with ANC on', 30, '2026-09-23 03:30:00'),
(194, 118, 'Use', 'Purpose', 'Travel, focus work and premium listening', 40, '2026-09-23 03:30:00'),
(199, 120, 'Connectivity', 'Type', 'Bluetooth 5.2 portable speaker', 10, '2026-09-23 03:30:00'),
(200, 120, 'Durability', 'Water/Dust Resistance', 'IP67 waterproof and dustproof', 20, '2026-09-23 03:30:00'),
(201, 120, 'Battery', 'Battery Life', 'Up to 25 hours playback', 30, '2026-09-23 03:30:00'),
(202, 120, 'Use', 'Purpose', 'Outdoor parties with built-in ambient lighting', 40, '2026-09-23 03:30:00'),
(216, 121, 'Display', 'Screen', '6.67-inch AMOLED', 0, '2026-09-23 12:07:46'),
(217, 121, 'Display', 'Refresh Rate', '120Hz', 1, '2026-09-23 12:07:46'),
(218, 121, 'Performance', 'Processor', 'MediaTek Dimensity 7300-Ultra', 2, '2026-09-23 12:07:46'),
(219, 121, 'Performance', 'RAM', '8GB', 3, '2026-09-23 12:07:46'),
(220, 121, 'Storage', 'Internal Storage', '256GB', 4, '2026-09-23 12:07:46'),
(221, 121, 'Camera', 'Rear Camera', '200MP + 8MP + 2MP', 5, '2026-09-23 12:07:46'),
(222, 121, 'Camera', 'Front Camera', '20MP', 6, '2026-09-23 12:07:46'),
(223, 121, 'Battery', 'Capacity', '5500mAh', 7, '2026-09-23 12:07:46'),
(224, 121, 'Battery', 'Charging', '45W Fast Charging', 8, '2026-09-23 12:07:46'),
(225, 121, 'Network', 'Dual SIM', '5G', 9, '2026-09-23 12:07:46'),
(226, 121, 'Network', 'Wi-Fi', 'Wi-Fi', 10, '2026-09-23 12:07:46'),
(227, 121, 'Connectivity', 'Bluetooth', 'Bluetooth', 11, '2026-09-23 12:07:46'),
(228, 121, 'Port', 'USB', 'USB Type-C', 12, '2026-09-23 12:07:46'),
(229, 17, 'Cable', 'Type', 'USB-C to Lightning', 0, '2026-10-13 08:00:12'),
(230, 17, 'Cable', 'Use', 'Charging and data transfer for compatible Apple devices', 1, '2026-10-13 08:00:12'),
(231, 17, 'Cable', 'Brand', 'Anker', 2, '2026-10-13 08:00:12'),
(232, 16, 'Cable', 'Type', 'USB-C to USB-C', 0, '2026-10-13 08:00:29'),
(233, 16, 'Cable', 'Use', 'Charging and data transfer', 1, '2026-10-13 08:00:29'),
(234, 16, 'Cable', 'Brand', 'Anker', 2, '2026-10-13 08:00:29'),
(235, 11, 'Charging', 'Maximum Output', '67W', 0, '2026-10-13 08:00:46'),
(236, 11, 'Charging', 'Technology', 'GaN fast-charging technology', 1, '2026-10-13 08:00:46'),
(237, 11, 'Compatibility', 'Use', 'Smartphones, tablets and compatible laptops', 2, '2026-10-13 08:00:46'),
(238, 117, 'Connectivity', 'Type', 'Bluetooth 5.2 wireless on-ear headphones', 0, '2026-10-13 08:00:58'),
(239, 117, 'Battery', 'Battery Life', 'Up to 50 hours playback, quick charge support', 1, '2026-10-13 08:00:58'),
(240, 117, 'Use', 'Purpose', 'Everyday music, calls and commuting', 2, '2026-10-13 08:00:58'),
(241, 3, 'Charging', 'Output', '15W', 0, '2026-10-13 08:01:15'),
(242, 3, 'Charging', 'Type', 'Samsung-compatible fast charging adapter', 1, '2026-10-13 08:01:15'),
(243, 3, 'Port', 'Connector', 'USB-A output', 2, '2026-10-13 08:01:15'),
(244, 119, 'Connectivity', 'Type', 'Bluetooth 4.2 portable speaker', 0, '2026-10-13 08:01:24'),
(245, 119, 'Durability', 'Water/Dust Resistance', 'IP67 waterproof and dustproof', 1, '2026-10-13 08:01:24'),
(246, 119, 'Battery', 'Battery Life', 'Up to 16 hours playback', 2, '2026-10-13 08:01:24'),
(247, 119, 'Use', 'Purpose', 'Compact everyday portable audio', 3, '2026-10-13 08:01:24'),
(248, 23, 'Cable', 'Type', 'Micro USB', 0, '2026-10-13 08:01:39'),
(249, 23, 'Cable', 'Use', 'Charging and data transfer', 1, '2026-10-13 08:01:39'),
(250, 33, 'Compatibility', 'Device', 'iPhone 15 Pro Max', 0, '2026-10-13 08:02:00'),
(251, 33, 'Protection', 'Type', 'Shock-resistant transparent case', 1, '2026-10-13 08:02:00'),
(252, 33, 'Design', 'Model', 'Xundd Beatle series', 2, '2026-10-13 08:02:00'),
(253, 113, 'Connectivity', 'Type', 'Bluetooth 5.0 wireless on-ear headphones', 0, '2026-10-13 08:02:10'),
(254, 113, 'Audio', 'Driver', '32mm dynamic driver with JBL Pure Bass Sound', 1, '2026-10-13 08:02:10'),
(255, 113, 'Battery', 'Battery Life', 'Up to 40 hours playback', 2, '2026-10-13 08:02:10'),
(256, 113, 'Use', 'Purpose', 'Everyday music, calls and commuting', 3, '2026-10-13 08:02:10'),
(257, 38, 'Compatibility', 'Device', 'Samsung Galaxy S24', 0, '2026-10-13 08:02:28'),
(258, 38, 'Material', 'Type', 'Silicone protective case', 1, '2026-10-13 08:02:28'),
(259, 44, 'Compatibility', 'Device', 'Nothing Phone 2a', 0, '2026-10-13 08:02:43'),
(260, 44, 'Design', 'Type', 'Transparent protective case', 1, '2026-10-13 08:02:43'),
(261, 50, 'Compatibility', 'Device', 'Samsung Galaxy S24', 0, '2026-10-13 08:03:03'),
(262, 50, 'Protection', 'Type', 'Tempered glass screen protector', 1, '2026-10-13 08:03:03'),
(263, 51, 'Compatibility', 'Device', 'Samsung Galaxy A55', 0, '2026-10-13 08:03:24'),
(264, 51, 'Protection', 'Type', 'Tempered glass screen protector', 1, '2026-10-13 08:03:24'),
(265, 52, 'Compatibility', 'Device', 'Samsung Galaxy A35', 0, '2026-10-13 08:03:40'),
(266, 52, 'Protection', 'Type', 'Tempered glass screen protector', 1, '2026-10-13 08:03:40'),
(267, 54, 'Compatibility', 'Device', 'Redmi Note 13 Pro', 0, '2026-10-13 08:03:56'),
(268, 54, 'Protection', 'Type', 'Tempered glass screen protector', 1, '2026-10-13 08:03:56'),
(269, 32, 'Battery', 'Capacity', '10000mAh', 0, '2026-10-13 08:04:24'),
(270, 32, 'Design', 'Type', 'Portable power bank', 1, '2026-10-13 08:04:24'),
(271, 32, 'Use', 'Compatibility', 'USB charging devices', 2, '2026-10-13 08:04:24'),
(272, 98, 'Display', 'Display', '6.74-inch HD+ display', 0, '2026-10-13 08:04:44'),
(273, 98, 'Performance', 'Processor', 'MediaTek entry-level octa-core platform', 1, '2026-10-13 08:04:44'),
(274, 98, 'Memory', 'RAM / Storage', 'Configuration varies by market', 2, '2026-10-13 08:04:44'),
(275, 98, 'Camera', 'Rear Camera', 'Market configuration dependent', 3, '2026-10-13 08:04:44'),
(276, 98, 'Camera', 'Front Camera', 'Market configuration dependent', 4, '2026-10-13 08:04:44'),
(277, 98, 'Battery', 'Battery', 'Large-capacity battery designed for long everyday use', 5, '2026-10-13 08:04:44'),
(278, 98, 'Network', 'Network', '4G LTE', 6, '2026-10-13 08:04:44'),
(279, 98, 'Software', 'Operating System', 'Android / MagicOS; region dependent', 7, '2026-10-13 08:04:44'),
(280, 24, 'Cable', 'Type', 'USB-C to USB-C', 0, '2026-10-13 08:05:08'),
(281, 24, 'Cable', 'Length', '2 metres', 1, '2026-10-13 08:05:08'),
(282, 24, 'Cable', 'Design', 'Braided cable', 2, '2026-10-13 08:05:08'),
(283, 102, 'Display', 'Display', 'Large high-brightness display', 0, '2026-10-13 08:05:21'),
(284, 102, 'Camera', 'Rear Camera', '108MP ultra-clear main camera', 1, '2026-10-13 08:05:21'),
(285, 102, 'Memory', 'Storage', 'Up to 256GB', 2, '2026-10-13 08:05:21'),
(286, 102, 'Battery', 'Battery', '6500mAh typical dual-cell battery', 3, '2026-10-13 08:05:21'),
(287, 102, 'Charging', 'Charging', '35W HONOR SuperCharge', 4, '2026-10-13 08:05:21'),
(288, 102, 'Durability', 'Protection', '5-star SGS Premium Drop Protection; IP65 water resistance', 5, '2026-10-13 08:05:21'),
(289, 102, 'Audio', 'Speakers', 'Dual speakers with high-volume mode', 6, '2026-10-13 08:05:21'),
(290, 102, 'Network', 'Network', '4G LTE', 7, '2026-10-13 08:05:21');

-- --------------------------------------------------------

--
-- Table structure for table `repairs`
--

CREATE TABLE `repairs` (
  `id` int(11) NOT NULL,
  `repair_code` varchar(50) NOT NULL,
  `user_id` int(11) NOT NULL,
  `technician_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `order_item_id` int(11) DEFAULT NULL,
  `device_model` varchar(150) NOT NULL,
  `serial_or_imei` varchar(100) DEFAULT NULL,
  `issue_description` text NOT NULL,
  `repair_status` enum('pending','assigned','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
  `diagnosis_notes` text DEFAULT NULL,
  `repair_notes` text DEFAULT NULL,
  `is_warranty_covered` tinyint(1) NOT NULL DEFAULT 0,
  `estimated_cost` decimal(10,2) DEFAULT 0.00,
  `final_cost` decimal(10,2) DEFAULT 0.00,
  `preferred_date` date DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `repairs`
--

INSERT INTO `repairs` (`id`, `repair_code`, `user_id`, `technician_id`, `product_id`, `order_item_id`, `device_model`, `serial_or_imei`, `issue_description`, `repair_status`, `diagnosis_notes`, `repair_notes`, `is_warranty_covered`, `estimated_cost`, `final_cost`, `preferred_date`, `completed_at`, `created_at`, `updated_at`) VALUES
(2, 'RP-VSVE6W', 4, NULL, 121, 18, 'Redmi Note 14 Pro 5G', '356984112345678', 'Screen glass shattered, touch responsiveness impaired', 'cancelled', NULL, NULL, 0, 0.00, 0.00, '2026-10-25', NULL, '2026-10-17 16:19:39', '2026-10-17 16:24:11'),
(3, 'RP-8K3YSV', 4, 8, 121, 18, 'Redmi Note 14 Pro 5G', '356984112345678', 'Screen glass shattered, touch responsiveness impaired', 'in_progress', NULL, 'Genuine AMOLED panel was installed in the display assembly. Tested OK.', 1, 0.00, 0.00, '2026-10-25', NULL, '2026-10-17 16:25:26', '2026-10-17 16:55:09');

-- --------------------------------------------------------

--
-- Table structure for table `repair_parts`
--

CREATE TABLE `repair_parts` (
  `id` int(11) NOT NULL,
  `repair_id` int(11) NOT NULL,
  `line_type` enum('part','labour') NOT NULL DEFAULT 'part',
  `description` varchar(200) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `repair_parts`
--

INSERT INTO `repair_parts` (`id`, `repair_id`, `line_type`, `description`, `quantity`, `unit_cost`, `created_by`, `created_at`) VALUES
(2, 3, 'part', 'AMOLED display panel for Redmi Note 14 Pro', 1, 14500.00, 8, '2026-10-17 16:54:36');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `phone` varchar(10) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `role` enum('customer','staff','admin') NOT NULL DEFAULT 'customer',
  `status` enum('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `google_id` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password`, `phone`, `address`, `role`, `status`, `created_at`, `updated_at`, `google_id`) VALUES
(4, 'Tharusha Iduwara', 'tiduwara26@gmail.com', '$2y$10$v3.Zq.mc5XygfwKif1DIlegC6DgXFA5H2RWEvd6JwSQkYnAQW8fhe', '0705321114', 'No. 25, Main Street, Colombo 03, Sri Lanka', 'customer', 'active', '2026-09-03 11:46:30', '2026-10-16 20:11:23', NULL),
(5, 'Tharusha Iduwara', 'tharushaiduwara1234@gmail.com', '$2y$10$GQ/1m9QW13uOwkYXShLoj.6/rYj6dbUTm55cW3s1fH1VBo9Qel8u.', NULL, NULL, 'admin', 'active', '2026-09-07 17:44:56', '2026-10-17 15:13:40', '100983182804069472953'),
(8, 'Nimal Perera', 'staff@thirasaramax.lk', '$2y$10$1BTgwM43ietBcSaC8.3bRux3GDWKXD534TB4Uz5CPIPZ8uzqu378G', '', NULL, 'staff', 'active', '2026-10-17 16:51:28', '2026-10-17 16:51:28', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `warranties`
--

CREATE TABLE `warranties` (
  `id` int(11) NOT NULL,
  `warranty_code` varchar(50) NOT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `order_id` int(11) DEFAULT NULL,
  `repair_id` int(11) DEFAULT NULL,
  `warranty_type` enum('product','repair') NOT NULL DEFAULT 'product',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` enum('active','expired','claimed','void') NOT NULL DEFAULT 'active',
  `claim_description` text DEFAULT NULL,
  `claim_date` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `warranties`
--

INSERT INTO `warranties` (`id`, `warranty_code`, `serial_number`, `user_id`, `product_id`, `order_id`, `repair_id`, `warranty_type`, `start_date`, `end_date`, `status`, `claim_description`, `claim_date`, `created_at`) VALUES
(2, 'TM-WAR-20261025-5C6E4C', NULL, 4, 66, 11, NULL, 'product', '2026-09-23', '2027-09-23', 'active', NULL, NULL, '2026-10-25 11:06:49'),
(3, 'TM-WAR-20261025-72A7A2', NULL, 4, 6, 12, NULL, 'product', '2026-09-23', '2027-09-23', 'active', NULL, NULL, '2026-10-25 11:06:49'),
(4, 'TM-WAR-20261025-5A641E', NULL, 4, 39, 14, NULL, 'product', '2026-09-23', '2026-12-23', 'active', NULL, NULL, '2026-10-25 11:06:49'),
(5, 'TM-WAR-20261025-40ECCF', NULL, 4, 38, 15, NULL, 'product', '2026-09-23', '2026-12-23', 'active', NULL, NULL, '2026-10-25 11:06:49'),
(6, 'TM-WAR-20261025-A00E4D', NULL, 4, 10, 16, NULL, 'product', '2026-09-26', '2027-09-26', 'active', NULL, NULL, '2026-10-25 11:06:49'),
(7, 'TM-WAR-20261025-D98709', NULL, 4, 17, 17, NULL, 'product', '2026-10-13', '2027-10-13', 'active', NULL, NULL, '2026-10-25 11:06:49'),
(8, 'TM-WAR-20261025-8910B4', NULL, 4, 121, 18, NULL, 'product', '2026-10-16', '2027-10-16', 'active', NULL, NULL, '2026-10-25 11:06:49'),
(9, 'TM-WAR-20261025-588100', NULL, 4, 6, 19, NULL, 'product', '2026-10-17', '2027-10-17', 'active', NULL, NULL, '2026-10-25 11:06:49'),
(10, 'TM-WAR-20261025-4EED9A', NULL, 4, 6, 19, NULL, 'product', '2026-10-17', '2027-10-17', 'active', NULL, NULL, '2026-10-25 11:06:49'),
(11, 'TM-WAR-20261025-EC7775', NULL, 4, 36, 20, NULL, 'product', '2026-10-19', '2027-01-19', 'active', NULL, NULL, '2026-10-25 11:06:49');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `inquiries`
--
ALTER TABLE `inquiries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `product_id` (`product_id`);

--
-- Indexes for table `inventory_logs`
--
ALTER TABLE `inventory_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_inv_product` (`product_id`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `repair_id` (`repair_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_number` (`order_number`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `product_images`
--
ALTER TABLE `product_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_product_images_product` (`product_id`),
  ADD KEY `idx_product_images_primary` (`product_id`,`is_primary`);

--
-- Indexes for table `product_specifications`
--
ALTER TABLE `product_specifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_product_specifications_product` (`product_id`),
  ADD KEY `idx_product_specifications_group` (`product_id`,`spec_group`);

--
-- Indexes for table `repairs`
--
ALTER TABLE `repairs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `repair_code` (`repair_code`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `technician_id` (`technician_id`),
  ADD KEY `idx_repairs_status` (`repair_status`),
  ADD KEY `idx_repairs_product` (`product_id`),
  ADD KEY `idx_repairs_order_item` (`order_item_id`);

--
-- Indexes for table `repair_parts`
--
ALTER TABLE `repair_parts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_repair_parts_repair` (`repair_id`),
  ADD KEY `fk_repair_parts_user` (`created_by`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `google_id` (`google_id`);

--
-- Indexes for table `warranties`
--
ALTER TABLE `warranties`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `warranty_code` (`warranty_code`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `repair_id` (`repair_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `inquiries`
--
ALTER TABLE `inquiries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `inventory`
--
ALTER TABLE `inventory`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=202;

--
-- AUTO_INCREMENT for table `inventory_logs`
--
ALTER TABLE `inventory_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=122;

--
-- AUTO_INCREMENT for table `product_images`
--
ALTER TABLE `product_images`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- AUTO_INCREMENT for table `product_specifications`
--
ALTER TABLE `product_specifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=291;

--
-- AUTO_INCREMENT for table `repairs`
--
ALTER TABLE `repairs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `repair_parts`
--
ALTER TABLE `repair_parts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `warranties`
--
ALTER TABLE `warranties`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `inquiries`
--
ALTER TABLE `inquiries`
  ADD CONSTRAINT `inquiries_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `inventory`
--
ALTER TABLE `inventory`
  ADD CONSTRAINT `inventory_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `inventory_logs`
--
ALTER TABLE `inventory_logs`
  ADD CONSTRAINT `fk_inv_logs_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `invoices_ibfk_2` FOREIGN KEY (`repair_id`) REFERENCES `repairs` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `product_images`
--
ALTER TABLE `product_images`
  ADD CONSTRAINT `fk_product_images_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `product_specifications`
--
ALTER TABLE `product_specifications`
  ADD CONSTRAINT `fk_product_specifications_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `repairs`
--
ALTER TABLE `repairs`
  ADD CONSTRAINT `repairs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `repairs_ibfk_2` FOREIGN KEY (`technician_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `repairs_ibfk_3` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `repairs_ibfk_4` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `repair_parts`
--
ALTER TABLE `repair_parts`
  ADD CONSTRAINT `fk_repair_parts_repair` FOREIGN KEY (`repair_id`) REFERENCES `repairs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_repair_parts_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `warranties`
--
ALTER TABLE `warranties`
  ADD CONSTRAINT `warranties_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `warranties_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `warranties_ibfk_3` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `warranties_ibfk_4` FOREIGN KEY (`repair_id`) REFERENCES `repairs` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
