<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/inventory_helpers.php';
require_once __DIR__ . '/../../includes/pdf.php';

requireAuth(['admin']);

$pdo = Database::getConnection();
$currentUser = getCurrentUser();

$categoryFilter = sanitize($_GET['category'] ?? '');
$statusFilter = sanitize($_GET['stock_status'] ?? 'all');
$export = sanitize($_GET['export'] ?? '');

// Fetch Categories for dropdown
$categories = $pdo->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);

// Overall Inventory Stats
$stats = getInventoryStats($pdo);

// Category Valuation Breakdown
$catBreakdown = $pdo->query("
    SELECT 
        COALESCE(p.category, 'General') AS category_name,
        COUNT(p.id) AS sku_count,
        SUM(COALESCE(i.stock_quantity, 0)) AS total_qty,
        SUM(COALESCE(i.stock_quantity, 0) * p.price) AS category_value
    FROM products p
    LEFT JOIN inventory i ON p.id = i.product_id
    WHERE p.status != 'discontinued'
    GROUP BY p.category
    ORDER BY category_value DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Build Products Query
$where = ["p.status != 'discontinued'"];
$params = [];

if (!empty($categoryFilter)) {
    $where[] = "p.category = ?";
    $params[] = $categoryFilter;
}

if ($statusFilter === 'in_stock') {
    $where[] = "i.stock_quantity > i.low_stock_threshold";
} elseif ($statusFilter === 'low_stock') {
    $where[] = "i.stock_quantity <= i.low_stock_threshold AND i.stock_quantity > 0";
} elseif ($statusFilter === 'out_of_stock') {
    $where[] = "i.stock_quantity = 0";
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$itemsSql = "
    SELECT 
        p.id,
        p.name,
        p.price,
        p.status,
        p.category AS category_name,
        COALESCE(i.stock_quantity, 0) AS stock_quantity,
        COALESCE(i.low_stock_threshold, 5) AS low_stock_threshold,
        (COALESCE(i.stock_quantity, 0) * p.price) AS total_valuation,
        i.last_restocked_at
    FROM products p
    LEFT JOIN inventory i ON p.id = i.product_id
    {$whereSql}
    ORDER BY total_valuation DESC
";
$stmt = $pdo->prepare($itemsSql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Export CSV
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventory_report_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Product ID', 'Product Name', 'Category', 'Unit Price (LKR)', 'Stock Qty', 'Threshold', 'Valuation (LKR)', 'Status']);
    foreach ($items as $item) {
        fputcsv($out, [
            $item['id'],
            $item['name'],
            $item['category_name'] ?? 'General',
            number_format((float)$item['price'], 2, '.', ''),
            $item['stock_quantity'],
            $item['low_stock_threshold'],
            number_format((float)$item['total_valuation'], 2, '.', ''),
            $item['status']
        ]);
    }
    fclose($out);
    exit;
}

// Export PDF
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
            .text-center { text-align: center; }
            .footer { margin-top: 30px; font-size: 9px; color: #9ca3af; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 10px; }
        </style>
    </head>
    <body>
        <div class='header'>
            <h1>THIRASARA MAX MOBILE - INVENTORY VALUATION REPORT</h1>
            <div class='meta'>
                <strong>Generated:</strong> " . date('d M Y, h:i A') . " | 
                <strong>Total Catalog SKUs:</strong> " . $stats['total_products'] . " | 
                <strong>Active Stock Units:</strong> " . number_format($stats['total_units']) . "
            </div>
        </div>

        <table class='kpi-table'>
            <tr>
                <td>
                    <div class='kpi-title'>Total Valuation</div>
                    <div class='kpi-value'>Rs. " . number_format((float)$stats['total_valuation'], 2) . "</div>
                </td>
                <td>
                    <div class='kpi-title'>Total Stock Units</div>
                    <div class='kpi-value'>" . number_format($stats['total_units']) . "</div>
                </td>
                <td>
                    <div class='kpi-title'>Low Stock SKUs</div>
                    <div class='kpi-value'>" . $stats['low_stock_count'] . "</div>
                </td>
                <td>
                    <div class='kpi-title'>Out of Stock</div>
                    <div class='kpi-value'>" . $stats['out_of_stock_count'] . "</div>
                </td>
            </tr>
        </table>

        <h3 style='font-size: 12px; margin: 20px 0 5px; text-transform: uppercase;'>Inventory Stock Breakdown</h3>
        <table class='data'>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Category</th>
                    <th class='text-right'>Unit Price</th>
                    <th class='text-center'>Stock Qty</th>
                    <th class='text-right'>Asset Value</th>
                </tr>
            </thead>
            <tbody>";
    foreach ($items as $it) {
        $pdfHtml .= "
                <tr>
                    <td><strong>" . htmlspecialchars($it['name']) . "</strong></td>
                    <td>" . htmlspecialchars($it['category_name'] ?? 'General') . "</td>
                    <td class='text-right'>Rs. " . number_format((float)$it['price'], 2) . "</td>
                    <td class='text-center font-bold'>" . (int)$it['stock_quantity'] . "</td>
                    <td class='text-right font-bold'>Rs. " . number_format((float)$it['total_valuation'], 2) . "</td>
                </tr>";
    }
    $pdfHtml .= "
            </tbody>
        </table>

        <div class='footer'>
            Thirasara Max Mobile &bull; Inventory Valuation Audit &bull; Confidential Business Record
        </div>
    </body>
    </html>";

    streamPdf($pdfHtml, 'Inventory_Report_' . date('Ymd') . '.pdf', false, 'A4', 'portrait');
    exit;
}

$pageTitle = 'Inventory Valuation & Stock Report';
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
                    <span class="font-bold text-gray-900">Inventory Report</span>
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
                    <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-emerald-100 text-emerald-600 font-bold">
                        <i data-lucide="package-check" class="w-5 h-5"></i>
                    </span>
                    <h1 class="text-2xl font-black text-gray-900 uppercase tracking-tight">Inventory Valuation Report</h1>
                </div>
                <p class="text-xs text-gray-500 mt-1">Audit current inventory asset values, category allocations, and depletion risks.</p>
            </div>

            <!-- Export Buttons -->
            <div class="flex items-center gap-2 self-start sm:self-auto">
                <a href="<?php echo APP_URL; ?>/admin/reports/inventory-report.php?<?php echo http_build_query(array_merge($_GET, ['export' => 'pdf'])); ?>"
                   target="_blank"
                   class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-gray-900 text-white rounded-xl text-xs font-bold hover:bg-black transition shadow-sm">
                    <i data-lucide="file-text" class="w-4 h-4"></i>
                    Export PDF
                </a>
                <a href="<?php echo APP_URL; ?>/admin/reports/inventory-report.php?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>"
                   class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-emerald-600 text-white rounded-xl text-xs font-bold hover:bg-emerald-700 transition shadow-sm">
                    <i data-lucide="download" class="w-4 h-4"></i>
                    Export CSV
                </a>
            </div>
        </div>

        <!-- KPI Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
            <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Total Asset Valuation</span>
                <p class="text-2xl font-black text-emerald-600 mt-1">Rs. <?php echo number_format((float)$stats['total_valuation'], 2); ?></p>
                <p class="text-[11px] text-gray-400 mt-0.5">Calculated at retail unit price</p>
            </div>

            <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Total Units in Stock</span>
                <p class="text-2xl font-black text-gray-900 mt-1"><?php echo number_format($stats['total_units']); ?></p>
                <p class="text-[11px] text-gray-400 mt-0.5">Across <?php echo $stats['total_products']; ?> total SKUs</p>
            </div>

            <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Low Stock SKUs</span>
                <p class="text-2xl font-black text-amber-600 mt-1"><?php echo $stats['low_stock_count']; ?></p>
                <p class="text-[11px] text-gray-400 mt-0.5">At or below reorder threshold</p>
            </div>

            <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Depleted / Zero Stock</span>
                <p class="text-2xl font-black text-rose-600 mt-1"><?php echo $stats['out_of_stock_count']; ?></p>
                <p class="text-[11px] text-gray-400 mt-0.5">Requires immediate replenishment</p>
            </div>
        </div>

        <!-- Category Valuation Breakdown Table -->
        <div class="bg-white rounded-3xl border border-gray-200 p-6 mb-8 shadow-sm">
            <h3 class="text-sm font-extrabold text-gray-900 uppercase tracking-tight mb-4">Stock Valuation by Category</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <?php foreach ($catBreakdown as $cat): ?>
                    <div class="p-4 bg-gray-50 rounded-2xl border border-gray-100 flex flex-col justify-between">
                        <div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400">
                                <?php echo htmlspecialchars($cat['category_name']); ?>
                            </span>
                            <p class="text-lg font-black text-gray-900 mt-1">
                                Rs. <?php echo number_format((float)$cat['category_value'], 2); ?>
                            </p>
                        </div>
                        <div class="mt-3 pt-3 border-t border-gray-200/60 flex items-center justify-between text-[11px] text-gray-500">
                            <span><?php echo (int)$cat['sku_count']; ?> SKUs</span>
                            <span class="font-bold text-gray-700"><?php echo (int)$cat['total_qty']; ?> Units</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="bg-white rounded-2xl border border-gray-200 p-4 mb-6 shadow-sm flex flex-col md:flex-row items-center justify-between gap-4">
            <form method="GET" action="" class="flex flex-wrap items-center gap-3 w-full">
                <div>
                    <select name="category" class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl text-xs font-bold text-gray-700 outline-none">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $catName): ?>
                            <option value="<?php echo htmlspecialchars($catName); ?>" <?php echo $categoryFilter === $catName ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($catName); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <select name="stock_status" class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-xl text-xs font-bold text-gray-700 outline-none">
                        <option value="all">All Stock Statuses</option>
                        <option value="in_stock" <?php echo $statusFilter === 'in_stock' ? 'selected' : ''; ?>>In Stock (Healthy)</option>
                        <option value="low_stock" <?php echo $statusFilter === 'low_stock' ? 'selected' : ''; ?>>Low Stock (Alert)</option>
                        <option value="out_of_stock" <?php echo $statusFilter === 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock (Zero)</option>
                    </select>
                </div>

                <button type="submit" class="px-4 py-2 bg-brand-red text-white text-xs font-bold uppercase tracking-wider rounded-xl hover:bg-red-700 transition shadow-sm">
                    Filter
                </button>

                <?php if (!empty($categoryFilter) || $statusFilter !== 'all'): ?>
                    <a href="<?php echo APP_URL; ?>/admin/reports/inventory-report.php" class="text-xs text-gray-400 hover:text-gray-600 transition">
                        Reset
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Product Inventory Valuation Table -->
        <div class="bg-white rounded-3xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="p-5 border-b border-gray-200 bg-gray-50 flex items-center justify-between">
                <h3 class="text-xs font-bold uppercase tracking-wider text-gray-700">Detailed Product Valuation (<?php echo count($items); ?> Items)</h3>
            </div>

            <?php if (empty($items)): ?>
                <div class="p-12 text-center text-gray-400 text-xs">
                    No products match your filter criteria.
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="bg-gray-50 border-b border-gray-200 text-gray-400 font-bold uppercase tracking-wider text-[10px]">
                                <th class="py-3.5 px-4">Product Name</th>
                                <th class="py-3.5 px-4">Category</th>
                                <th class="py-3.5 px-4 text-right">Unit Price</th>
                                <th class="py-3.5 px-4 text-center">In Stock</th>
                                <th class="py-3.5 px-4 text-center">Alert Limit</th>
                                <th class="py-3.5 px-4 text-right">Total Valuation</th>
                                <th class="py-3.5 px-4 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($items as $it): ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="py-3.5 px-4">
                                        <p class="font-bold text-gray-900"><?php echo htmlspecialchars($it['name']); ?></p>
                                        <span class="text-[10px] text-gray-400 font-mono">SKU #<?php echo $it['id']; ?></span>
                                    </td>
                                    <td class="py-3.5 px-4 text-gray-600">
                                        <?php echo htmlspecialchars($it['category_name'] ?? 'General'); ?>
                                    </td>
                                    <td class="py-3.5 px-4 text-right font-medium text-gray-900">
                                        Rs. <?php echo number_format((float)$it['price'], 2); ?>
                                    </td>
                                    <td class="py-3.5 px-4 text-center font-black <?php echo (int)$it['stock_quantity'] === 0 ? 'text-rose-600' : ((int)$it['stock_quantity'] <= (int)$it['low_stock_threshold'] ? 'text-amber-600' : 'text-gray-900'); ?>">
                                        <?php echo (int)$it['stock_quantity']; ?>
                                    </td>
                                    <td class="py-3.5 px-4 text-center text-gray-400 font-mono text-[11px]">
                                        &le; <?php echo (int)$it['low_stock_threshold']; ?>
                                    </td>
                                    <td class="py-3.5 px-4 text-right font-black text-emerald-600">
                                        Rs. <?php echo number_format((float)$it['total_valuation'], 2); ?>
                                    </td>
                                    <td class="py-3.5 px-4 text-center">
                                        <?php if ((int)$it['stock_quantity'] === 0): ?>
                                            <span class="px-2 py-0.5 rounded-full text-[9px] font-black bg-rose-100 text-rose-700">Out of Stock</span>
                                        <?php elseif ((int)$it['stock_quantity'] <= (int)$it['low_stock_threshold']): ?>
                                            <span class="px-2 py-0.5 rounded-full text-[9px] font-black bg-amber-100 text-amber-700">Low Stock</span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 rounded-full text-[9px] font-black bg-emerald-100 text-emerald-700">Healthy</span>
                                        <?php endif; ?>
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
        if (typeof lucide !== 'undefined') lucide.createIcons();
    </script>
</body>
</html>
