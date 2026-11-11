<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth(['admin']);

$pdo = Database::getConnection();
$currentUser = getCurrentUser();

// 1. Sales & Order Metrics (Last 30 Days & All-Time)
$salesMetrics = $pdo->query("
    SELECT 
        COUNT(*) AS total_orders,
        SUM(CASE WHEN order_status = 'delivered' THEN total_amount ELSE 0 END) AS delivered_revenue,
        SUM(CASE WHEN order_status NOT IN ('cancelled', 'refunded') THEN total_amount ELSE 0 END) AS gross_revenue,
        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND order_status NOT IN ('cancelled', 'refunded') THEN total_amount ELSE 0 END) AS revenue_30d,
        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS orders_30d
    FROM orders
")->fetch(PDO::FETCH_ASSOC);

// 2. Inventory Metrics
$invMetrics = $pdo->query("
    SELECT 
        COUNT(p.id) AS total_products,
        SUM(COALESCE(i.stock_quantity, 0)) AS total_units,
        SUM(COALESCE(i.stock_quantity, 0) * p.price) AS total_asset_value,
        SUM(CASE WHEN COALESCE(i.stock_quantity, 0) <= COALESCE(i.low_stock_threshold, 5) THEN 1 ELSE 0 END) AS low_stock_count
    FROM products p
    LEFT JOIN inventory i ON p.id = i.product_id
    WHERE p.status != 'discontinued'
")->fetch(PDO::FETCH_ASSOC);

// 3. Repair Metrics
$repairMetrics = $pdo->query("
    SELECT 
        COUNT(*) AS total_repairs,
        SUM(CASE WHEN repair_status = 'completed' THEN 1 ELSE 0 END) AS completed_repairs,
        SUM(CASE WHEN repair_status = 'completed' THEN final_cost ELSE 0 END) AS repair_revenue,
        SUM(CASE WHEN repair_status IN ('pending', 'assigned', 'in_progress') THEN 1 ELSE 0 END) AS active_repairs
    FROM repairs
")->fetch(PDO::FETCH_ASSOC);

// 4. User Metrics
$userMetrics = $pdo->query("
    SELECT 
        COUNT(CASE WHEN role = 'customer' THEN 1 END) AS total_customers,
        COUNT(CASE WHEN role = 'staff' THEN 1 END) AS total_staff
    FROM users
")->fetch(PDO::FETCH_ASSOC);

$pageTitle = 'Reports & Analytics Hub';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> | <?php echo APP_NAME; ?> Admin</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif']
                    },
                    colors: {
                        brand: {
                            red: '#e52b34',
                            redHover: '#c4232c',
                            dark: '#0a0a0c',
                            card: '#121216',
                            border: '#1f1f28'
                        }
                    }
                }
            }
        }
    </script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="<?php echo APP_URL; ?>/assets/js/main.js" defer></script>
</head>

<body class="bg-gray-100 text-gray-800 font-sans antialiased">
    <div class="flex min-h-screen">
        <!-- SIDEBAR -->
        <?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>

        <div class="flex-1 flex flex-col min-w-0">
            <!-- TOP BAR -->
            <header class="bg-white border-b border-gray-200 sticky top-0 z-20 flex items-center gap-4 px-6 h-16 flex-shrink-0">
                <button id="sidebar-open-btn" type="button" class="lg:hidden text-gray-500 hover:text-gray-900" aria-label="Open sidebar">
                    <i data-lucide="menu" class="w-5 h-5"></i>
                </button>
                <div class="flex-1 min-w-0 flex items-center gap-2 text-sm">
                    <span class="text-gray-400 font-medium hidden sm:inline">Admin</span>
                    <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-gray-300 hidden sm:inline flex-shrink-0"></i>
                    <span class="font-bold text-gray-900">Reports Hub</span>
                </div>
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center text-white text-sm font-bold flex-shrink-0">
                        <?php echo strtoupper(substr($currentUser['name'] ?? 'A', 0, 1)); ?>
                    </div>
                </div>
            </header>

            <!-- MAIN CONTENT -->
            <main class="flex-1 p-5 sm:p-7 overflow-auto">
                <!-- Page Title Section -->
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                    <div>
                        <h1 class="text-2xl font-extrabold text-gray-900 tracking-tight">Business Reports & Analytics</h1>
                        <p class="text-sm text-gray-500 mt-0.5">Real-time business performance summaries, revenue exports, and inventory audits.</p>
                    </div>
                    <div class="text-xs text-gray-500 bg-white px-3.5 py-2 rounded-xl border border-gray-200 shadow-sm flex items-center gap-2 self-start sm:self-auto font-medium">
                        <i data-lucide="calendar" class="w-4 h-4 text-brand-red"></i>
                        <span>Generated: <?php echo date('d M Y, h:i A'); ?></span>
                    </div>
                </div>

                <!-- High-Level Executive KPI Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                    <!-- 30-Day Sales -->
                    <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400">30-Day Orders Revenue</span>
                            <div class="w-9 h-9 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                                <i data-lucide="shopping-bag" class="w-4 h-4"></i>
                            </div>
                        </div>
                        <p class="text-2xl font-black text-gray-900">
                            Rs. <?php echo number_format((float)($salesMetrics['revenue_30d'] ?? 0), 2); ?>
                        </p>
                        <p class="text-[11px] text-gray-500 mt-1">
                            <strong><?php echo (int)($salesMetrics['orders_30d'] ?? 0); ?></strong> orders placed this month
                        </p>
                    </div>

                    <!-- Total Repair Revenue -->
                    <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Repair Services Revenue</span>
                            <div class="w-9 h-9 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center">
                                <i data-lucide="wrench" class="w-4 h-4"></i>
                            </div>
                        </div>
                        <p class="text-2xl font-black text-gray-900">
                            Rs. <?php echo number_format((float)($repairMetrics['repair_revenue'] ?? 0), 2); ?>
                        </p>
                        <p class="text-[11px] text-gray-500 mt-1">
                            <strong><?php echo (int)($repairMetrics['completed_repairs'] ?? 0); ?></strong> completed jobs (<?php echo (int)($repairMetrics['active_repairs'] ?? 0); ?> in progress)
                        </p>
                    </div>

                    <!-- Inventory Asset Valuation -->
                    <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Stock Valuation</span>
                            <div class="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                                <i data-lucide="warehouse" class="w-4 h-4"></i>
                            </div>
                        </div>
                        <p class="text-2xl font-black text-gray-900">
                            Rs. <?php echo number_format((float)($invMetrics['total_asset_value'] ?? 0), 2); ?>
                        </p>
                        <p class="text-[11px] text-gray-500 mt-1">
                            <strong><?php echo (int)($invMetrics['total_units'] ?? 0); ?></strong> total units across <?php echo (int)($invMetrics['total_products'] ?? 0); ?> SKUs
                        </p>
                    </div>

                    <!-- Customer Base -->
                    <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Active Clients</span>
                            <div class="w-9 h-9 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                                <i data-lucide="users" class="w-4 h-4"></i>
                            </div>
                        </div>
                        <p class="text-2xl font-black text-gray-900">
                            <?php echo number_format((int)($userMetrics['total_customers'] ?? 0)); ?>
                        </p>
                        <p class="text-[11px] text-gray-500 mt-1">
                            Registered clients (<?php echo (int)($userMetrics['total_staff'] ?? 0); ?> staff members)
                        </p>
                    </div>
                </div>

                <!-- Section: Report Navigation Modules -->
                <h2 class="text-xs font-bold uppercase tracking-wider text-gray-400 mb-4">Detailed Reports & Analytics Modules</h2>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-8">
                    <!-- 1. Sales & Revenue Report -->
                    <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm hover:shadow-md transition flex flex-col justify-between">
                        <div>
                            <div class="w-12 h-12 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center mb-4">
                                <i data-lucide="trending-up" class="w-6 h-6"></i>
                            </div>
                            <h3 class="text-base font-extrabold text-gray-900">Sales & Orders Report</h3>
                            <p class="text-xs text-gray-500 mt-1 leading-relaxed">
                                Filter store sales by date range, examine payment gateway breakdowns (PayHere vs COD), inspect item volumes, and download PDF financial statements.
                            </p>
                            <div class="mt-4 pt-4 border-t border-gray-100 space-y-1.5 text-xs text-gray-600">
                                <div class="flex justify-between">
                                    <span class="text-gray-400">Total Orders:</span>
                                    <span class="font-bold text-gray-900"><?php echo (int)($salesMetrics['total_orders'] ?? 0); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-400">Delivered Revenue:</span>
                                    <span class="font-bold text-emerald-600">Rs. <?php echo number_format((float)($salesMetrics['delivered_revenue'] ?? 0), 2); ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="mt-6 pt-4 border-t border-gray-100">
                            <a href="<?php echo APP_URL; ?>/admin/reports/sales-report.php" 
                               class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-gray-900 text-white rounded-xl text-xs font-bold hover:bg-black transition shadow-sm">
                                <span>Open Sales Report</span>
                                <i data-lucide="arrow-right" class="w-4 h-4"></i>
                            </a>
                        </div>
                    </div>

                    <!-- 2. Inventory & Stock Valuation Report -->
                    <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm hover:shadow-md transition flex flex-col justify-between">
                        <div>
                            <div class="w-12 h-12 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center mb-4">
                                <i data-lucide="package-check" class="w-6 h-6"></i>
                            </div>
                            <h3 class="text-base font-extrabold text-gray-900">Inventory Valuation Report</h3>
                            <p class="text-xs text-gray-500 mt-1 leading-relaxed">
                                Comprehensive stock balance audit, category-wise retail asset evaluation, low stock replenishment requirements, and fast/slow-moving inventory logs.
                            </p>
                            <div class="mt-4 pt-4 border-t border-gray-100 space-y-1.5 text-xs text-gray-600">
                                <div class="flex justify-between">
                                    <span class="text-gray-400">Stock Units:</span>
                                    <span class="font-bold text-gray-900"><?php echo (int)($invMetrics['total_units'] ?? 0); ?> items</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-400">Alerts Needed:</span>
                                    <span class="font-bold text-amber-600"><?php echo (int)($invMetrics['low_stock_count'] ?? 0); ?> SKUs</span>
                                </div>
                            </div>
                        </div>
                        <div class="mt-6 pt-4 border-t border-gray-100">
                            <a href="<?php echo APP_URL; ?>/admin/reports/inventory-report.php" 
                               class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-gray-900 text-white rounded-xl text-xs font-bold hover:bg-black transition shadow-sm">
                                <span>Open Inventory Report</span>
                                <i data-lucide="arrow-right" class="w-4 h-4"></i>
                            </a>
                        </div>
                    </div>

                    <!-- 3. Repairs & Service Analytics -->
                    <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm hover:shadow-md transition flex flex-col justify-between">
                        <div>
                            <div class="w-12 h-12 rounded-xl bg-purple-100 text-purple-600 flex items-center justify-center mb-4">
                                <i data-lucide="cpu" class="w-6 h-6"></i>
                            </div>
                            <h3 class="text-base font-extrabold text-gray-900">Repairs &amp; Services Report</h3>
                            <p class="text-xs text-gray-500 mt-1 leading-relaxed">
                                Overview of mobile phone repair bookings, technician completion rate, hardware diagnostic labor revenues, and job turnaround stats.
                            </p>
                            <div class="mt-4 pt-4 border-t border-gray-100 space-y-1.5 text-xs text-gray-600">
                                <div class="flex justify-between">
                                    <span class="text-gray-400">Total Bookings:</span>
                                    <span class="font-bold text-gray-900"><?php echo (int)($repairMetrics['total_repairs'] ?? 0); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-400">Completed Rate:</span>
                                    <span class="font-bold text-purple-600">
                                        <?php 
                                        $totR = (int)($repairMetrics['total_repairs'] ?? 0);
                                        echo $totR > 0 ? round(((int)$repairMetrics['completed_repairs'] / $totR) * 100) . '%' : '0%';
                                        ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="mt-6 pt-4 border-t border-gray-100">
                            <a href="<?php echo APP_URL; ?>/admin/reports/repairs-report.php" 
                               class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-gray-900 text-white rounded-xl text-xs font-bold hover:bg-black transition shadow-sm">
                                <span>Open Repairs Report</span>
                                <i data-lucide="arrow-right" class="w-4 h-4"></i>
                            </a>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <script>
        if (typeof lucide !== 'undefined') lucide.createIcons();
    </script>
</body>

</html>
