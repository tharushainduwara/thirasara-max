<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/pdf.php';

requireAuth(['admin']);

$pdo = Database::getConnection();
$currentUser = getCurrentUser();

// Date Range & Filters
$range = sanitize($_GET['range'] ?? '30d');
$startDate = sanitize($_GET['start_date'] ?? '');
$endDate = sanitize($_GET['end_date'] ?? '');
$statusFilter = sanitize($_GET['status'] ?? 'all');
$paymentFilter = sanitize($_GET['payment'] ?? 'all');
$export = sanitize($_GET['export'] ?? '');

$today = date('Y-m-d');

switch ($range) {
    case 'today':
        $from = $today . ' 00:00:00';
        $to = $today . ' 23:59:59';
        $rangeLabel = 'Today (' . date('d M Y') . ')';
        break;
    case '7d':
        $from = date('Y-m-d 00:00:00', strtotime('-6 days'));
        $to = $today . ' 23:59:59';
        $rangeLabel = 'Last 7 Days';
        break;
    case 'this_month':
        $from = date('Y-m-01 00:00:00');
        $to = date('Y-m-t 23:59:59');
        $rangeLabel = 'This Month (' . date('F Y') . ')';
        break;
    case 'all':
        $from = '2020-01-01 00:00:00';
        $to = $today . ' 23:59:59';
        $rangeLabel = 'All Time';
        break;
    case 'custom':
        $from = !empty($startDate) ? $startDate . ' 00:00:00' : '2020-01-01 00:00:00';
        $to = !empty($endDate) ? $endDate . ' 23:59:59' : $today . ' 23:59:59';
        $rangeLabel = 'Custom: ' . date('d M Y', strtotime($from)) . ' to ' . date('d M Y', strtotime($to));
        break;
    case '30d':
    default:
        $range = '30d';
        $from = date('Y-m-d 00:00:00', strtotime('-29 days'));
        $to = $today . ' 23:59:59';
        $rangeLabel = 'Last 30 Days';
        break;
}

// Payment join to derive payment method from payments table
$payJoin = "LEFT JOIN (
    SELECT order_id, MAX(payment_method) AS payment_method 
    FROM payments 
    WHERE order_id IS NOT NULL 
    GROUP BY order_id
) pm ON pm.order_id = o.id";

// Build Query Conditions
$where = ["o.created_at >= ? AND o.created_at <= ?"];
$params = [$from, $to];

if ($statusFilter !== 'all' && !empty($statusFilter)) {
    $where[] = "o.order_status = ?";
    $params[] = $statusFilter;
}

if ($paymentFilter !== 'all' && !empty($paymentFilter)) {
    $mappedPay = ($paymentFilter === 'cod') ? 'cash' : $paymentFilter;
    $where[] = "COALESCE(pm.payment_method, 'cash') = ?";
    $params[] = $mappedPay;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

// 1. Summary Metrics
$summaryStmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS total_orders,
        SUM(CASE WHEN o.order_status NOT IN ('cancelled', 'refunded') THEN o.total_amount ELSE 0 END) AS gross_revenue,
        SUM(CASE WHEN o.order_status = 'delivered' THEN o.total_amount ELSE 0 END) AS delivered_revenue,
        AVG(CASE WHEN o.order_status NOT IN ('cancelled', 'refunded') THEN o.total_amount ELSE NULL END) AS aov,
        SUM(CASE WHEN o.order_status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_orders
    FROM orders o
    {$payJoin}
    {$whereSql}
");
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

// 2. Payment Method Breakdown
$payStmt = $pdo->prepare("
    SELECT 
        COALESCE(pm.payment_method, 'cash') AS payment_method,
        COUNT(*) AS order_count,
        SUM(CASE WHEN o.order_status NOT IN ('cancelled', 'refunded') THEN o.total_amount ELSE 0 END) AS method_revenue
    FROM orders o
    {$payJoin}
    {$whereSql}
    GROUP BY COALESCE(pm.payment_method, 'cash')
");
$payStmt->execute($params);
$paymentBreakdown = $payStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Top-Selling Products in this range
$topItemsStmt = $pdo->prepare("
    SELECT 
        p.name AS product_name,
        p.category AS category_name,
        SUM(oi.quantity) AS total_qty_sold,
        SUM(oi.subtotal) AS total_subtotal
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    {$payJoin}
    JOIN products p ON oi.product_id = p.id
    {$whereSql}
      AND o.order_status NOT IN ('cancelled', 'refunded')
    GROUP BY oi.product_id, p.name, p.category
    ORDER BY total_qty_sold DESC
    LIMIT 10
");
$topItemsStmt->execute($params);
$topProducts = $topItemsStmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Detailed Orders List
$ordersStmt = $pdo->prepare("
    SELECT 
        o.id,
        o.order_number,
        o.total_amount,
        o.order_status,
        COALESCE(pm.payment_method, 'cash') AS payment_method,
        o.payment_status,
        o.created_at,
        u.name AS customer_name,
        u.email AS customer_email,
        (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) AS item_count
    FROM orders o
    {$payJoin}
    JOIN users u ON o.user_id = u.id
    {$whereSql}
    ORDER BY o.created_at DESC
");
$ordersStmt->execute($params);
$ordersList = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

// Export Handler: CSV
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sales_report_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Order Number', 'Date', 'Customer Name', 'Customer Email', 'Items', 'Payment Method', 'Payment Status', 'Order Status', 'Total Amount (LKR)']);
    foreach ($ordersList as $row) {
        fputcsv($out, [
            $row['order_number'],
            $row['created_at'],
            $row['customer_name'],
            $row['customer_email'],
            $row['item_count'],
            strtoupper($row['payment_method']),
            ucfirst($row['payment_status']),
            ucfirst($row['order_status']),
            number_format((float)$row['total_amount'], 2, '.', '')
        ]);
    }
    fclose($out);
    exit;
}

// Export Handler: PDF
if ($export === 'pdf') {
    $pdfHtml = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='utf-8'>
        <style>
            body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1f2937; margin: 20px; }
            .header { border-bottom: 2px solid #e52b34; padding-bottom: 10px; margin-bottom: 20px; }
            .header h1 { font-size: 20px; color: #e52b34; margin: 0; }
            .meta { font-size: 10px; color: #6b7280; margin-top: 4px; }
            .kpi-table { width: 100%; margin-bottom: 25px; border-collapse: collapse; }
            .kpi-table td { padding: 12px; background: #f9fafb; border: 1px solid #e5e7eb; text-align: center; }
            .kpi-title { font-size: 9px; text-transform: uppercase; color: #6b7280; font-weight: bold; }
            .kpi-value { font-size: 16px; font-weight: bold; color: #111827; margin-top: 4px; }
            table.data { width: 100%; border-collapse: collapse; margin-top: 15px; }
            table.data th { background: #111827; color: #ffffff; padding: 7px 8px; font-size: 9px; text-align: left; text-transform: uppercase; }
            table.data td { padding: 7px 8px; border-bottom: 1px solid #e5e7eb; font-size: 10px; }
            .text-right { text-align: right; }
            .footer { margin-top: 30px; font-size: 9px; color: #9ca3af; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 10px; }
        </style>
    </head>
    <body>
        <div class='header'>
            <h1>THIRASARA MAX MOBILE - SALES REPORT</h1>
            <div class='meta'>
                <strong>Report Period:</strong> " . htmlspecialchars($rangeLabel) . " | 
                <strong>Generated:</strong> " . date('d M Y, h:i A') . " | 
                <strong>Status Filter:</strong> " . htmlspecialchars(ucfirst($statusFilter)) . "
            </div>
        </div>

        <table class='kpi-table'>
            <tr>
                <td>
                    <div class='kpi-title'>Total Orders</div>
                    <div class='kpi-value'>" . number_format((int)($summary['total_orders'] ?? 0)) . "</div>
                </td>
                <td>
                    <div class='kpi-title'>Gross Revenue</div>
                    <div class='kpi-value'>Rs. " . number_format((float)($summary['gross_revenue'] ?? 0), 2) . "</div>
                </td>
                <td>
                    <div class='kpi-title'>Delivered Revenue</div>
                    <div class='kpi-value'>Rs. " . number_format((float)($summary['delivered_revenue'] ?? 0), 2) . "</div>
                </td>
                <td>
                    <div class='kpi-title'>Average Order Value</div>
                    <div class='kpi-value'>Rs. " . number_format((float)($summary['aov'] ?? 0), 2) . "</div>
                </td>
            </tr>
        </table>

        <h3 style='font-size: 12px; margin: 20px 0 5px; text-transform: uppercase;'>Detailed Orders</h3>
        <table class='data'>
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Method</th>
                    <th>Status</th>
                    <th class='text-right'>Total (LKR)</th>
                </tr>
            </thead>
            <tbody>";
    foreach ($ordersList as $o) {
        $pdfHtml .= "
                <tr>
                    <td><strong>" . htmlspecialchars($o['order_number']) . "</strong></td>
                    <td>" . date('d M Y', strtotime($o['created_at'])) . "</td>
                    <td>" . htmlspecialchars($o['customer_name']) . "</td>
                    <td>" . strtoupper($o['payment_method']) . "</td>
                    <td>" . ucfirst($o['order_status']) . "</td>
                    <td class='text-right font-bold'>Rs. " . number_format((float)$o['total_amount'], 2) . "</td>
                </tr>";
    }
    $pdfHtml .= "
            </tbody>
        </table>

        <div class='footer'>
            Thirasara Max Mobile &bull; Galle - Colombo Rd, Wadduwa &bull; Confidential Business Sales Document
        </div>
    </body>
    </html>";

    streamPdf($pdfHtml, 'Sales_Report_' . date('Ymd') . '.pdf', false, 'A4', 'portrait');
    exit;
}

$pageTitle = 'Sales & Revenue Report';
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
                    <a href="<?php echo APP_URL; ?>/admin/reports/index.php" class="text-gray-500 hover:text-gray-900 font-medium">Reports</a>
                    <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-gray-300 hidden sm:inline flex-shrink-0"></i>
                    <span class="font-bold text-gray-900">Sales Report</span>
                </div>
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center text-white text-sm font-bold flex-shrink-0">
                        <?php echo strtoupper(substr($currentUser['name'] ?? 'A', 0, 1)); ?>
                    </div>
                </div>
            </header>

            <!-- MAIN CONTENT -->
            <main class="flex-1 p-5 sm:p-7 overflow-auto">
                <!-- Header -->
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-blue-100 text-blue-600 font-bold">
                                <i data-lucide="trending-up" class="w-5 h-5"></i>
                            </span>
                            <h1 class="text-2xl font-black text-gray-900 uppercase tracking-tight">Sales & Revenue Report</h1>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Review revenue analytics, payment gateways, and download statements.</p>
                    </div>

                    <!-- Export Buttons -->
                    <div class="flex items-center gap-2 self-start sm:self-auto">
                        <a href="<?php echo APP_URL; ?>/admin/reports/sales-report.php?<?php echo http_build_query(array_merge($_GET, ['export' => 'pdf'])); ?>"
                            target="_blank"
                            class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-gray-900 text-white rounded-xl text-xs font-bold hover:bg-black transition shadow-sm">
                            <i data-lucide="file-text" class="w-4 h-4"></i>
                            Export PDF
                        </a>
                        <a href="<?php echo APP_URL; ?>/admin/reports/sales-report.php?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>"
                            class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-emerald-600 text-white rounded-xl text-xs font-bold hover:bg-emerald-700 transition shadow-sm">
                            <i data-lucide="download" class="w-4 h-4"></i>
                            Export CSV
                        </a>
                    </div>
                </div>

                <!-- Filter Bar -->
                <div class="bg-white rounded-2xl border border-gray-200 p-5 mb-8 shadow-sm">
                    <form method="GET" action="" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 items-end">
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-1">Time Range</label>
                            <select name="range" onchange="toggleCustomDates(this.value)" class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl text-xs focus:ring-2 focus:ring-brand-red outline-none">
                                <option value="today" <?php echo $range === 'today' ? 'selected' : ''; ?>>Today</option>
                                <option value="7d" <?php echo $range === '7d' ? 'selected' : ''; ?>>Last 7 Days</option>
                                <option value="30d" <?php echo $range === '30d' ? 'selected' : ''; ?>>Last 30 Days</option>
                                <option value="this_month" <?php echo $range === 'this_month' ? 'selected' : ''; ?>>This Month</option>
                                <option value="all" <?php echo $range === 'all' ? 'selected' : ''; ?>>All Time</option>
                                <option value="custom" <?php echo $range === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                            </select>
                        </div>

                        <div id="customDateStart" class="<?php echo $range === 'custom' ? '' : 'hidden'; ?>">
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-1">From Date</label>
                            <input type="date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>" class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl text-xs focus:ring-2 focus:ring-brand-red outline-none">
                        </div>

                        <div id="customDateEnd" class="<?php echo $range === 'custom' ? '' : 'hidden'; ?>">
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-1">To Date</label>
                            <input type="date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>" class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl text-xs focus:ring-2 focus:ring-brand-red outline-none">
                        </div>

                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-1">Order Status</label>
                            <select name="status" class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl text-xs focus:ring-2 focus:ring-brand-red outline-none">
                                <option value="all">All Statuses</option>
                                <option value="placed" <?php echo $statusFilter === 'placed' ? 'selected' : ''; ?>>Placed</option>
                                <option value="processing" <?php echo $statusFilter === 'processing' ? 'selected' : ''; ?>>Processing</option>
                                <option value="shipped" <?php echo $statusFilter === 'shipped' ? 'selected' : ''; ?>>Shipped</option>
                                <option value="delivered" <?php echo $statusFilter === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                                <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-1">Payment Method</label>
                            <select name="payment" class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl text-xs focus:ring-2 focus:ring-brand-red outline-none">
                                <option value="all">All Methods</option>
                                <option value="payhere" <?php echo $paymentFilter === 'payhere' ? 'selected' : ''; ?>>PayHere (Online)</option>
                                <option value="cash" <?php echo ($paymentFilter === 'cash' || $paymentFilter === 'cod') ? 'selected' : ''; ?>>Cash On Delivery</option>
                            </select>
                        </div>

                        <div>
                            <button type="submit" class="w-full px-4 py-2 bg-brand-red text-white text-xs font-bold uppercase tracking-wider rounded-xl hover:bg-red-700 transition shadow-sm">
                                Apply Filters
                            </button>
                        </div>
                    </form>
                </div>

                <!-- KPI Tiles Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
                    <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Total Filtered Orders</span>
                        <p class="text-2xl font-black text-gray-900 mt-1"><?php echo number_format((int)($summary['total_orders'] ?? 0)); ?></p>
                        <p class="text-[11px] text-gray-400 mt-0.5"><?php echo (int)($summary['cancelled_orders'] ?? 0); ?> cancelled orders</p>
                    </div>

                    <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Gross Sales Revenue</span>
                        <p class="text-2xl font-black text-blue-600 mt-1">Rs. <?php echo number_format((float)($summary['gross_revenue'] ?? 0), 2); ?></p>
                        <p class="text-[11px] text-gray-400 mt-0.5">Excludes cancellations</p>
                    </div>

                    <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Delivered & Settled</span>
                        <p class="text-2xl font-black text-emerald-600 mt-1">Rs. <?php echo number_format((float)($summary['delivered_revenue'] ?? 0), 2); ?></p>
                        <p class="text-[11px] text-gray-400 mt-0.5">Fully fulfilled revenue</p>
                    </div>

                    <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Average Order Value (AOV)</span>
                        <p class="text-2xl font-black text-gray-900 mt-1">Rs. <?php echo number_format((float)($summary['aov'] ?? 0), 2); ?></p>
                        <p class="text-[11px] text-gray-400 mt-0.5">Per valid order</p>
                    </div>
                </div>

                <!-- 2-Column Section: Payment Channels & Top Selling Products -->
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 mb-8">
                    <!-- Payment Channel Breakdown -->
                    <div class="lg:col-span-5 bg-white rounded-3xl border border-gray-200 p-6 shadow-sm">
                        <h3 class="text-sm font-extrabold text-gray-900 uppercase tracking-tight mb-4">Payment Method Breakdown</h3>
                        <div class="space-y-4">
                            <?php if (empty($paymentBreakdown)): ?>
                                <p class="text-xs text-gray-400">No payment data in this range.</p>
                            <?php else: ?>
                                <?php foreach ($paymentBreakdown as $pay): ?>
                                    <div class="p-4 bg-gray-50 rounded-2xl border border-gray-100 flex items-center justify-between">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-xl bg-white border border-gray-200 flex items-center justify-center font-bold text-xs text-gray-700">
                                                <?php echo strtoupper($pay['payment_method']); ?>
                                            </div>
                                            <div>
                                                <p class="font-extrabold text-gray-900 text-xs">
                                                    <?php echo $pay['payment_method'] === 'payhere' ? 'PayHere Gateway' : 'Cash On Delivery (COD)'; ?>
                                                </p>
                                                <p class="text-[11px] text-gray-400"><?php echo (int)$pay['order_count']; ?> Orders</p>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <p class="font-black text-gray-900 text-sm">Rs. <?php echo number_format((float)$pay['method_revenue'], 2); ?></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Top Selling Products -->
                    <div class="lg:col-span-7 bg-white rounded-3xl border border-gray-200 p-6 shadow-sm">
                        <h3 class="text-sm font-extrabold text-gray-900 uppercase tracking-tight mb-4">Top-Selling Products (This Period)</h3>
                        <?php if (empty($topProducts)): ?>
                            <p class="text-xs text-gray-400">No sales recorded in selected timeframe.</p>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-xs">
                                    <thead>
                                        <tr class="text-gray-400 border-b border-gray-100 font-bold uppercase text-[10px]">
                                            <th class="pb-3">Product Name</th>
                                            <th class="pb-3 text-center">Qty Sold</th>
                                            <th class="pb-3 text-right">Subtotal Revenue</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <?php foreach ($topProducts as $tp): ?>
                                            <tr class="hover:bg-gray-50 transition">
                                                <td class="py-3 pr-2">
                                                    <p class="font-bold text-gray-900"><?php echo htmlspecialchars($tp['product_name']); ?></p>
                                                    <span class="text-[10px] text-gray-400"><?php echo htmlspecialchars($tp['category_name'] ?? 'General'); ?></span>
                                                </td>
                                                <td class="py-3 text-center font-black text-gray-900">
                                                    <?php echo (int)$tp['total_qty_sold']; ?>
                                                </td>
                                                <td class="py-3 text-right font-black text-emerald-600">
                                                    Rs. <?php echo number_format((float)$tp['total_subtotal'], 2); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Orders Table -->
                <div class="bg-white rounded-3xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="p-5 border-b border-gray-200 bg-gray-50 flex items-center justify-between">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-gray-700">Orders List (<?php echo count($ordersList); ?>)</h3>
                    </div>

                    <?php if (empty($ordersList)): ?>
                        <div class="p-12 text-center text-gray-400 text-xs">
                            No orders match your filter criteria.
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-xs">
                                <thead>
                                    <tr class="bg-gray-50 border-b border-gray-200 text-gray-400 font-bold uppercase tracking-wider text-[10px]">
                                        <th class="py-3.5 px-4">Order Number</th>
                                        <th class="py-3.5 px-4">Customer</th>
                                        <th class="py-3.5 px-4">Payment</th>
                                        <th class="py-3.5 px-4">Status</th>
                                        <th class="py-3.5 px-4">Date</th>
                                        <th class="py-3.5 px-4 text-right">Total Amount</th>
                                        <th class="py-3.5 px-4 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($ordersList as $o): ?>
                                        <tr class="hover:bg-gray-50 transition">
                                            <td class="py-3.5 px-4 font-mono font-bold text-brand-red">
                                                #<?php echo htmlspecialchars($o['order_number']); ?>
                                                <div class="text-[10px] text-gray-400 font-sans font-normal"><?php echo (int)$o['item_count']; ?> items</div>
                                            </td>
                                            <td class="py-3.5 px-4">
                                                <p class="font-bold text-gray-900"><?php echo htmlspecialchars($o['customer_name']); ?></p>
                                                <p class="text-[11px] text-gray-400"><?php echo htmlspecialchars($o['customer_email']); ?></p>
                                            </td>
                                            <td class="py-3.5 px-4">
                                                <span class="inline-block px-2 py-0.5 bg-gray-100 text-gray-700 font-bold rounded text-[10px] uppercase">
                                                    <?php echo strtoupper($o['payment_method']); ?>
                                                </span>
                                                <span class="text-[10px] ml-1 <?php echo $o['payment_status'] === 'paid' ? 'text-emerald-600 font-bold' : 'text-amber-600'; ?>">
                                                    (<?php echo ucfirst($o['payment_status']); ?>)
                                                </span>
                                            </td>
                                            <td class="py-3.5 px-4">
                                                <?php
                                                $badgeStyles = [
                                                    'delivered'  => 'bg-emerald-100 text-emerald-700',
                                                    'shipped'    => 'bg-blue-100 text-blue-700',
                                                    'processing' => 'bg-purple-100 text-purple-700',
                                                    'placed'     => 'bg-amber-100 text-amber-700',
                                                    'cancelled'  => 'bg-rose-100 text-rose-700',
                                                ];
                                                $style = $badgeStyles[$o['order_status']] ?? 'bg-gray-100 text-gray-700';
                                                ?>
                                                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold <?php echo $style; ?>">
                                                    <?php echo ucfirst($o['order_status']); ?>
                                                </span>
                                            </td>
                                            <td class="py-3.5 px-4 text-gray-500 text-[11px]">
                                                <?php echo date('d M Y, h:i A', strtotime($o['created_at'])); ?>
                                            </td>
                                            <td class="py-3.5 px-4 text-right font-black text-gray-900">
                                                Rs. <?php echo number_format((float)$o['total_amount'], 2); ?>
                                            </td>
                                            <td class="py-3.5 px-4 text-right">
                                                <a href="<?php echo APP_URL; ?>/admin/orders/view.php?id=<?php echo $o['id']; ?>" class="text-brand-red font-bold hover:underline">
                                                    View
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <script>
        function toggleCustomDates(val) {
            const s = document.getElementById('customDateStart');
            const e = document.getElementById('customDateEnd');
            if (val === 'custom') {
                s.classList.remove('hidden');
                e.classList.remove('hidden');
            } else {
                s.classList.add('hidden');
                e.classList.add('hidden');
            }
        }
        if (typeof lucide !== 'undefined') lucide.createIcons();
    </script>
</body>

</html>