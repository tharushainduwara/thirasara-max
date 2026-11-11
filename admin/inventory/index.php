<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/inventory_helpers.php';

requireAuth(['admin']);

$currentUser = getCurrentUser();
$pdo = Database::getConnection();
$uid = (int) ($currentUser['id'] ?? 0);

$feedback = ['type' => '', 'message' => ''];

// Handle POST actions: Restock, Stock Adjustment, Threshold update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = trim($_POST['action'] ?? '');
    $productId = (int) ($_POST['product_id'] ?? 0);

    if ($productId <= 0) {
        $feedback = ['type' => 'error', 'message' => 'Invalid product specified.'];
    } elseif ($action === 'restock') {
        $qtyToAdd = (int) ($_POST['quantity_to_add'] ?? 0);
        $notes    = trim($_POST['notes'] ?? 'Supplier shipment restock');

        if ($qtyToAdd <= 0) {
            $feedback = ['type' => 'error', 'message' => 'Quantity to restock must be greater than 0.'];
        } else {
            $res = adjustStock($pdo, $productId, $qtyToAdd, 'restock', $notes, $uid);
            $feedback = [
                'type' => 'success',
                'message' => "Successfully restocked +{$qtyToAdd} units. Current stock is now {$res['new_stock']} units."
            ];
        }
    } elseif ($action === 'adjust') {
        $exactQty = (int) ($_POST['exact_quantity'] ?? 0);
        $reason   = trim($_POST['reason'] ?? 'Manual stock count adjustment');

        if ($exactQty < 0) {
            $feedback = ['type' => 'error', 'message' => 'Stock quantity cannot be negative.'];
        } else {
            $res = setExactStock($pdo, $productId, $exactQty, $reason, $uid);
            $feedback = [
                'type' => 'success',
                'message' => "Stock count updated to {$exactQty} units."
            ];
        }
    } elseif ($action === 'update_threshold') {
        $newThreshold = (int) ($_POST['threshold'] ?? 5);
        if ($newThreshold < 1) {
            $feedback = ['type' => 'error', 'message' => 'Low stock threshold must be at least 1 unit.'];
        } else {
            updateStockThreshold($pdo, $productId, $newThreshold);
            $feedback = ['type' => 'success', 'message' => "Low stock threshold updated to {$newThreshold} units."];
        }
    }
}

// Fetch Global KPIs
$stats = getInventoryStats($pdo);

// Filters & Pagination
$q          = trim($_GET['q'] ?? '');
$category   = trim($_GET['category'] ?? '');
$stockState = trim($_GET['stock_state'] ?? '');
$sortBy     = trim($_GET['sort'] ?? 'stock_asc');
$page       = max(1, (int) ($_GET['page'] ?? 1));
$limit      = 15;
$offset     = ($page - 1) * $limit;

$where = ["p.status != 'discontinued'"];
$params = [];

if ($q !== '') {
    $where[] = "(p.name LIKE ? OR p.brand LIKE ? OR p.category LIKE ? OR p.id = ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, ctype_digit($q) ? (int)$q : 0);
}

if ($category !== '') {
    $where[] = "p.category = ?";
    $params[] = $category;
}

if ($stockState === 'healthy') {
    $where[] = "COALESCE(i.stock_quantity, 0) > COALESCE(i.low_stock_threshold, 5)";
} elseif ($stockState === 'low') {
    $where[] = "COALESCE(i.stock_quantity, 0) <= COALESCE(i.low_stock_threshold, 5) AND COALESCE(i.stock_quantity, 0) > 0";
} elseif ($stockState === 'out') {
    $where[] = "COALESCE(i.stock_quantity, 0) = 0";
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

// Sorting logic
$sortMap = [
    'stock_asc'   => 'COALESCE(i.stock_quantity, 0) ASC, p.name ASC',
    'stock_desc'  => 'COALESCE(i.stock_quantity, 0) DESC, p.name ASC',
    'value_desc'  => '(COALESCE(i.stock_quantity, 0) * p.price) DESC',
    'name_asc'    => 'p.name ASC',
    'restock_desc'=> 'i.last_restocked_at DESC, p.id DESC'
];
$orderBy = $sortMap[$sortBy] ?? $sortMap['stock_asc'];

// Total records
$countStmt = $pdo->prepare("
    SELECT COUNT(p.id) 
    FROM products p 
    LEFT JOIN inventory i ON i.product_id = p.id 
    {$whereSql}
");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRecords / $limit));

// Fetch Inventory Rows
$query = "
    SELECT 
        p.id AS product_id,
        p.name AS product_name,
        p.category,
        p.brand,
        p.price,
        p.image_url,
        p.is_repairable,
        p.status AS product_status,
        COALESCE(i.stock_quantity, 0) AS stock_quantity,
        COALESCE(i.low_stock_threshold, 5) AS low_stock_threshold,
        i.last_restocked_at,
        i.updated_at AS stock_updated_at,
        (COALESCE(i.stock_quantity, 0) * p.price) AS total_value
    FROM products p
    LEFT JOIN inventory i ON i.product_id = p.id
    {$whereSql}
    ORDER BY {$orderBy}
    LIMIT {$limit} OFFSET {$offset}
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$inventoryItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Distinct Categories for filter
$categories = $pdo->query("SELECT DISTINCT category FROM products WHERE status != 'discontinued' ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);

// Fetch recent 5 inventory logs for quick audit drawer/widget
$recentLogs = $pdo->query("
    SELECT il.*, p.name AS product_name, u.name AS user_name
    FROM inventory_logs il
    JOIN products p ON p.id = il.product_id
    LEFT JOIN users u ON u.id = il.user_id
    ORDER BY il.created_at DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Inventory & Stock Controls';
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
                    <span class="font-bold text-gray-900">Inventory &amp; Stock Controls</span>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-xs text-gray-500 hidden sm:inline"><?php echo number_format($stats['total_units']); ?> units in store</span>
                    <div class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center text-white text-sm font-bold flex-shrink-0">
                        <?php echo strtoupper(substr($currentUser['name'] ?? 'A', 0, 1)); ?>
                    </div>
                </div>
            </header>

            <!-- MAIN CONTENT -->
            <main class="flex-1 p-6 sm:p-8 overflow-auto">

                <!-- Header Actions Strip -->
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                    <div>
                        <h1 class="text-2xl font-black text-gray-900 tracking-tight">Real-Time Inventory Management</h1>
                        <p class="text-xs text-gray-500 mt-1">Monitor real-time warehouse stock, log supplier restocks, and manage depletion thresholds.</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <a href="alerts.php" class="relative inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-white text-xs font-bold transition shadow-sm">
                            <i data-lucide="alert-triangle" class="w-4 h-4"></i>
                            Low Stock Alerts
                            <?php if ($stats['alerts_total'] > 0): ?>
                                <span class="ml-1 px-1.5 py-0.5 rounded-full bg-white text-amber-600 text-[10px] font-black">
                                    <?php echo $stats['alerts_total']; ?>
                                </span>
                            <?php endif; ?>
                        </a>
                        <a href="<?php echo APP_URL; ?>/admin/products/index.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-xs font-bold transition shadow-sm">
                            <i data-lucide="package" class="w-4 h-4 text-brand-red"></i> Product Catalog
                        </a>
                    </div>
                </div>

                <!-- ALERTS / FLASH FEEDBACK -->
                <?php if ($feedback['message'] !== ''): ?>
                    <div class="mb-6 p-4 rounded-xl text-xs font-semibold flex items-center gap-3 shadow-sm <?php echo $feedback['type'] === 'success' ? 'bg-green-50 text-green-800 border border-green-200' : 'bg-red-50 text-red-800 border border-red-200'; ?>">
                        <i data-lucide="<?php echo $feedback['type'] === 'success' ? 'check-circle' : 'alert-circle'; ?>" class="w-4 h-4 flex-shrink-0"></i>
                        <p><?php echo htmlspecialchars($feedback['message']); ?></p>
                    </div>
                <?php endif; ?>

                <!-- KPI METRICS GRID -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
                    <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Total SKUs</span>
                        <p class="text-xl font-black text-gray-900 mt-1"><?php echo number_format($stats['total_products']); ?></p>
                    </div>

                    <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Total Stock</span>
                        <p class="text-xl font-black text-gray-900 mt-1"><?php echo number_format($stats['total_units']); ?> <span class="text-xs font-normal text-gray-500">units</span></p>
                    </div>

                    <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm col-span-2 sm:col-span-1">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Stock Valuation</span>
                        <p class="text-xl font-black text-brand-red mt-1">Rs. <?php echo number_format($stats['total_valuation'], 2); ?></p>
                    </div>

                    <div class="bg-white border border-green-200 rounded-xl p-4 shadow-sm">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-green-600">Healthy Stock</span>
                        <p class="text-xl font-black text-green-700 mt-1"><?php echo number_format($stats['healthy_count']); ?></p>
                    </div>

                    <div class="bg-white border border-amber-200 rounded-xl p-4 shadow-sm">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-amber-600">Low Stock Warning</span>
                        <p class="text-xl font-black text-amber-700 mt-1"><?php echo number_format($stats['low_stock_count']); ?></p>
                    </div>

                    <div class="bg-white border border-red-200 rounded-xl p-4 shadow-sm">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-red-600">Out of Stock</span>
                        <p class="text-xl font-black text-red-700 mt-1"><?php echo number_format($stats['out_of_stock_count']); ?></p>
                    </div>
                </div>

                <!-- FILTER & SEARCH BAR -->
                <div class="bg-white border border-gray-200 rounded-2xl p-4 mb-6 shadow-sm">
                    <form method="GET" class="flex flex-col lg:flex-row gap-3">
                        <div class="relative flex-1">
                            <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>"
                                   placeholder="Search product name, brand, SKU or ID..."
                                   class="w-full pl-10 pr-4 py-2 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-800 placeholder-gray-400 focus:bg-white focus:outline-none focus:border-brand-red transition">
                            <i data-lucide="search" class="w-4 h-4 text-gray-400 absolute left-3.5 top-1/2 -translate-y-1/2"></i>
                        </div>

                        <select name="stock_state" class="px-3 py-2 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-700 focus:outline-none focus:border-brand-red">
                            <option value="">All Stock Levels</option>
                            <option value="healthy" <?php echo $stockState === 'healthy' ? 'selected' : ''; ?>>Healthy Stock (> Threshold)</option>
                            <option value="low" <?php echo $stockState === 'low' ? 'selected' : ''; ?>>Low Stock Warning (&le; Threshold)</option>
                            <option value="out" <?php echo $stockState === 'out' ? 'selected' : ''; ?>>Out of Stock (0 units)</option>
                        </select>

                        <select name="category" class="px-3 py-2 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-700 focus:outline-none focus:border-brand-red">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category === $cat ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select name="sort" class="px-3 py-2 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-700 focus:outline-none focus:border-brand-red">
                            <option value="stock_asc" <?php echo $sortBy === 'stock_asc' ? 'selected' : ''; ?>>Stock: Lowest First</option>
                            <option value="stock_desc" <?php echo $sortBy === 'stock_desc' ? 'selected' : ''; ?>>Stock: Highest First</option>
                            <option value="value_desc" <?php echo $sortBy === 'value_desc' ? 'selected' : ''; ?>>Stock Value: Highest</option>
                            <option value="name_asc" <?php echo $sortBy === 'name_asc' ? 'selected' : ''; ?>>Product Name: A to Z</option>
                            <option value="restock_desc" <?php echo $sortBy === 'restock_desc' ? 'selected' : ''; ?>>Recently Restocked</option>
                        </select>

                        <button type="submit" class="px-4 py-2 rounded-xl bg-brand-dark hover:bg-black text-white text-xs font-bold transition flex items-center justify-center gap-1.5">
                            <i data-lucide="filter" class="w-3.5 h-3.5"></i> Apply
                        </button>

                        <?php if ($q !== '' || $category !== '' || $stockState !== '' || $sortBy !== 'stock_asc'): ?>
                            <a href="index.php" class="px-3 py-2 rounded-xl border border-gray-200 text-gray-600 text-xs font-bold hover:bg-gray-50 transition flex items-center justify-center">
                                Reset
                            </a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- MAIN INVENTORY TABLE -->
                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden mb-8">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-gray-50 border-b border-gray-200 text-gray-500 uppercase tracking-wider font-extrabold text-[10px]">
                                <tr>
                                    <th class="py-3 px-4">Product Info</th>
                                    <th class="py-3 px-4">Price</th>
                                    <th class="py-3 px-4">Stock Level</th>
                                    <th class="py-3 px-4">Alert Threshold</th>
                                    <th class="py-3 px-4">Inventory Valuation</th>
                                    <th class="py-3 px-4">Last Restocked</th>
                                    <th class="py-3 px-4 text-right">Quick Restock / Adjust</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 text-gray-700">
                                <?php if (empty($inventoryItems)): ?>
                                    <tr>
                                        <td colspan="7" class="py-10 text-center text-gray-400">
                                            No inventory items found matching your filters.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($inventoryItems as $item): ?>
                                        <?php
                                            $qty = (int) $item['stock_quantity'];
                                            $threshold = (int) $item['low_stock_threshold'];
                                            
                                            // Determine Stock Status Badge
                                            if ($qty === 0) {
                                                $badge = 'bg-red-100 text-red-800 border-red-200';
                                                $label = 'Out of Stock';
                                                $dot = 'bg-red-500';
                                            } elseif ($qty <= $threshold) {
                                                $badge = 'bg-amber-100 text-amber-800 border-amber-200';
                                                $label = 'Low Stock';
                                                $dot = 'bg-amber-500 animate-pulse';
                                            } else {
                                                $badge = 'bg-green-100 text-green-800 border-green-200';
                                                $label = 'Healthy';
                                                $dot = 'bg-green-500';
                                            }
                                        ?>
                                        <tr class="hover:bg-gray-50/80 transition">
                                            <!-- Product Info -->
                                            <td class="py-3 px-4">
                                                <div class="flex items-center gap-3">
                                                    <div class="w-10 h-10 rounded-xl bg-gray-100 border border-gray-200 flex-shrink-0 overflow-hidden flex items-center justify-center">
                                                        <?php if (!empty($item['image_url'])): ?>
                                                            <img src="<?php echo APP_URL . '/' . ltrim($item['image_url'], '/'); ?>" alt="" class="w-full h-full object-cover">
                                                        <?php else: ?>
                                                            <i data-lucide="package" class="w-5 h-5 text-gray-400"></i>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="min-w-0">
                                                        <a href="<?php echo APP_URL; ?>/admin/products/edit.php?id=<?php echo (int) $item['product_id']; ?>" 
                                                           class="font-bold text-gray-900 hover:text-brand-red transition truncate block max-w-xs">
                                                            <?php echo htmlspecialchars($item['product_name']); ?>
                                                        </a>
                                                        <span class="text-[10px] text-gray-400">
                                                            <?php echo htmlspecialchars($item['brand'] ?? 'Store Item'); ?> &bull; <?php echo htmlspecialchars($item['category']); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </td>

                                            <!-- Price -->
                                            <td class="py-3 px-4 font-semibold text-gray-900">
                                                Rs. <?php echo number_format((float) $item['price'], 2); ?>
                                            </td>

                                            <!-- Stock Level -->
                                            <td class="py-3 px-4">
                                                <div class="flex items-center gap-2">
                                                    <span class="text-sm font-extrabold <?php echo $qty === 0 ? 'text-red-600' : ($qty <= $threshold ? 'text-amber-600' : 'text-gray-900'); ?>">
                                                        <?php echo $qty; ?>
                                                    </span>
                                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold border uppercase tracking-wider <?php echo $badge; ?>">
                                                        <span class="w-1.5 h-1.5 rounded-full <?php echo $dot; ?>"></span>
                                                        <?php echo $label; ?>
                                                    </span>
                                                </div>
                                            </td>

                                            <!-- Threshold -->
                                            <td class="py-3 px-4">
                                                <button type="button" 
                                                        onclick="openThresholdModal(<?php echo (int) $item['product_id']; ?>, '<?php echo htmlspecialchars(addslashes($item['product_name'])); ?>', <?php echo $threshold; ?>)"
                                                        class="inline-flex items-center gap-1 font-semibold text-gray-700 hover:text-brand-red text-xs transition"
                                                        title="Click to edit threshold">
                                                    <span>&le; <?php echo $threshold; ?> units</span>
                                                    <i data-lucide="edit-3" class="w-3 h-3 text-gray-400"></i>
                                                </button>
                                            </td>

                                            <!-- Inventory Valuation -->
                                            <td class="py-3 px-4 font-bold text-gray-900">
                                                Rs. <?php echo number_format((float) $item['total_value'], 2); ?>
                                            </td>

                                            <!-- Last Restocked -->
                                            <td class="py-3 px-4 text-gray-500 text-[11px]">
                                                <?php if (!empty($item['last_restocked_at'])): ?>
                                                    <span class="font-semibold text-gray-800"><?php echo date('M d, Y', strtotime($item['last_restocked_at'])); ?></span>
                                                    <span class="block text-[10px] text-gray-400"><?php echo date('h:i A', strtotime($item['last_restocked_at'])); ?></span>
                                                <?php else: ?>
                                                    <span class="text-gray-400 italic">Not recorded</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Actions -->
                                            <td class="py-3 px-4 text-right whitespace-nowrap">
                                                <div class="flex items-center justify-end gap-1.5">
                                                    <button type="button" 
                                                            onclick="openRestockModal(<?php echo (int) $item['product_id']; ?>, '<?php echo htmlspecialchars(addslashes($item['product_name'])); ?>', <?php echo $qty; ?>)"
                                                            class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-brand-red hover:bg-brand-redHover text-white font-bold text-[11px] transition shadow-sm">
                                                        <i data-lucide="plus" class="w-3 h-3"></i> Restock
                                                    </button>

                                                    <button type="button" 
                                                            onclick="openAdjustModal(<?php echo (int) $item['product_id']; ?>, '<?php echo htmlspecialchars(addslashes($item['product_name'])); ?>', <?php echo $qty; ?>)"
                                                            class="px-2.5 py-1.5 rounded-lg border border-gray-200 bg-gray-50 hover:bg-gray-100 text-gray-700 font-bold text-[11px] transition">
                                                        Adjust
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- PAGINATION -->
                    <?php if ($totalPages > 1): ?>
                        <div class="px-4 py-3 border-t border-gray-200 bg-gray-50 flex items-center justify-between text-xs">
                            <span class="text-gray-500">Showing page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo number_format($totalRecords); ?> items)</span>
                            <div class="flex gap-1">
                                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                    <a href="?page=<?php echo $p; ?>&q=<?php echo urlencode($q); ?>&category=<?php echo urlencode($category); ?>&stock_state=<?php echo urlencode($stockState); ?>&sort=<?php echo urlencode($sortBy); ?>"
                                       class="px-2.5 py-1 rounded-lg font-bold <?php echo $p === $page ? 'bg-brand-red text-white' : 'bg-white border border-gray-200 text-gray-700 hover:bg-gray-50'; ?>">
                                        <?php echo $p; ?>
                                    </a>
                                <?php endfor; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- RECENT RESTOCK & STOCK MOVEMENT AUDIT LOGS -->
                <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-2">
                            <i data-lucide="history" class="w-4 h-4 text-brand-red"></i>
                            <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wider">Recent Stock Movement &amp; Restock Audit Logs</h2>
                        </div>
                        <span class="text-[11px] text-gray-400">Automated Audit Trail</span>
                    </div>

                    <?php if (empty($recentLogs)): ?>
                        <p class="text-xs text-gray-400 py-4 text-center">No inventory log entries recorded yet.</p>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-xs">
                                <thead class="bg-gray-50 text-gray-400 text-[10px] uppercase font-bold border-b border-gray-100">
                                    <tr>
                                        <th class="py-2.5 px-3">Date &amp; Time</th>
                                        <th class="py-2.5 px-3">Product</th>
                                        <th class="py-2.5 px-3">Action Type</th>
                                        <th class="py-2.5 px-3">Quantity Change</th>
                                        <th class="py-2.5 px-3">Resulting Stock</th>
                                        <th class="py-2.5 px-3">Notes / Performed By</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 text-gray-700">
                                    <?php foreach ($recentLogs as $log): ?>
                                        <tr class="hover:bg-gray-50/50">
                                            <td class="py-2 px-3 text-gray-500 whitespace-nowrap">
                                                <?php echo date('M d, Y h:i A', strtotime($log['created_at'])); ?>
                                            </td>
                                            <td class="py-2 px-3 font-semibold text-gray-900">
                                                <?php echo htmlspecialchars($log['product_name']); ?>
                                            </td>
                                            <td class="py-2 px-3">
                                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider <?php echo $log['change_type'] === 'restock' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700'; ?>">
                                                    <?php echo htmlspecialchars($log['change_type']); ?>
                                                </span>
                                            </td>
                                            <td class="py-2 px-3 font-bold <?php echo $log['quantity_change'] > 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                                <?php echo $log['quantity_change'] > 0 ? ('+' . $log['quantity_change']) : $log['quantity_change']; ?>
                                            </td>
                                            <td class="py-2 px-3 font-extrabold text-gray-900">
                                                <?php echo $log['resulting_stock']; ?> units
                                            </td>
                                            <td class="py-2 px-3 text-gray-500">
                                                <?php echo htmlspecialchars($log['notes'] ?? 'Standard update'); ?>
                                                <?php if (!empty($log['user_name'])): ?>
                                                    <span class="text-gray-400">by <?php echo htmlspecialchars($log['user_name']); ?></span>
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

    <!-- RESTOCK MODAL -->
    <div id="restockModal" class="fixed inset-0 z-50 bg-black/60 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl relative animate-in fade-in zoom-in-95">
            <button type="button" onclick="closeRestockModal()" class="absolute top-5 right-5 text-gray-400 hover:text-gray-700">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-xl bg-red-50 text-brand-red flex items-center justify-center flex-shrink-0">
                    <i data-lucide="plus-circle" class="w-5 h-5"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-gray-900">Restock Product Inventory</h3>
                    <p class="text-xs text-gray-500" id="restockProdName"></p>
                </div>
            </div>

            <form method="POST" action="" class="space-y-4">
                <input type="hidden" name="action" value="restock">
                <input type="hidden" name="product_id" id="restockProdId" value="">

                <div class="p-3 bg-gray-50 rounded-xl border border-gray-100 flex items-center justify-between text-xs">
                    <span class="text-gray-500 font-semibold">Current Available Stock:</span>
                    <span id="restockCurrentQty" class="font-extrabold text-gray-900">0 units</span>
                </div>

                <div>
                    <label for="quantity_to_add" class="block text-xs font-bold text-gray-700 mb-1">
                        Quantity to Add (Units) <span class="text-brand-red">*</span>
                    </label>
                    <input type="number" id="quantity_to_add" name="quantity_to_add" min="1" max="10000" required placeholder="e.g. 25"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-gray-300 text-xs font-bold text-gray-900 focus:outline-none focus:border-brand-red">
                </div>

                <div>
                    <label for="restock_notes" class="block text-xs font-bold text-gray-700 mb-1">
                        Supplier / Shipment Notes <span class="text-gray-400 font-normal">(Optional)</span>
                    </label>
                    <input type="text" id="restock_notes" name="notes" placeholder="e.g. Supplier invoice #INV-4928"
                           class="w-full px-3.5 py-2 rounded-xl border border-gray-300 text-xs text-gray-800 focus:outline-none focus:border-brand-red">
                </div>

                <div class="pt-3 flex justify-end gap-3 border-t border-gray-100">
                    <button type="button" onclick="closeRestockModal()" class="px-4 py-2 rounded-xl border border-gray-200 text-gray-600 font-bold text-xs hover:bg-gray-50 transition">
                        Cancel
                    </button>
                    <button type="submit" class="px-5 py-2 rounded-xl bg-brand-red hover:bg-brand-redHover text-white font-bold text-xs transition shadow-sm">
                        Confirm Restock
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- EXACT ADJUSTMENT MODAL -->
    <div id="adjustModal" class="fixed inset-0 z-50 bg-black/60 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl relative animate-in fade-in zoom-in-95">
            <button type="button" onclick="closeAdjustModal()" class="absolute top-5 right-5 text-gray-400 hover:text-gray-700">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-xl bg-gray-100 text-gray-700 flex items-center justify-center flex-shrink-0">
                    <i data-lucide="sliders" class="w-5 h-5"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-gray-900">Adjust Exact Stock Count</h3>
                    <p class="text-xs text-gray-500" id="adjustProdName"></p>
                </div>
            </div>

            <form method="POST" action="" class="space-y-4">
                <input type="hidden" name="action" value="adjust">
                <input type="hidden" name="product_id" id="adjustProdId" value="">

                <div>
                    <label for="exact_quantity" class="block text-xs font-bold text-gray-700 mb-1">
                        New Exact Stock Quantity <span class="text-brand-red">*</span>
                    </label>
                    <input type="number" id="exact_quantity" name="exact_quantity" min="0" max="100000" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-gray-300 text-xs font-bold text-gray-900 focus:outline-none focus:border-brand-red">
                </div>

                <div>
                    <label for="adjust_reason" class="block text-xs font-bold text-gray-700 mb-1">
                        Adjustment Reason <span class="text-brand-red">*</span>
                    </label>
                    <input type="text" id="adjust_reason" name="reason" required placeholder="e.g. Physical inventory audit, damaged item write-off"
                           class="w-full px-3.5 py-2 rounded-xl border border-gray-300 text-xs text-gray-800 focus:outline-none focus:border-brand-red">
                </div>

                <div class="pt-3 flex justify-end gap-3 border-t border-gray-100">
                    <button type="button" onclick="closeAdjustModal()" class="px-4 py-2 rounded-xl border border-gray-200 text-gray-600 font-bold text-xs hover:bg-gray-50 transition">
                        Cancel
                    </button>
                    <button type="submit" class="px-5 py-2 rounded-xl bg-gray-900 hover:bg-black text-white font-bold text-xs transition shadow-sm">
                        Apply Count
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- THRESHOLD MODAL -->
    <div id="thresholdModal" class="fixed inset-0 z-50 bg-black/60 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-sm w-full p-6 shadow-2xl relative animate-in fade-in zoom-in-95">
            <button type="button" onclick="closeThresholdModal()" class="absolute top-5 right-5 text-gray-400 hover:text-gray-700">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center flex-shrink-0">
                    <i data-lucide="bell" class="w-5 h-5"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-gray-900">Low Stock Alert Trigger</h3>
                    <p class="text-xs text-gray-500" id="thresholdProdName"></p>
                </div>
            </div>

            <form method="POST" action="" class="space-y-4">
                <input type="hidden" name="action" value="update_threshold">
                <input type="hidden" name="product_id" id="thresholdProdId" value="">

                <div>
                    <label for="threshold_input" class="block text-xs font-bold text-gray-700 mb-1">
                        Alert Threshold (Units) <span class="text-brand-red">*</span>
                    </label>
                    <input type="number" id="threshold_input" name="threshold" min="1" max="1000" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-gray-300 text-xs font-bold text-gray-900 focus:outline-none focus:border-brand-red">
                    <p class="text-[11px] text-gray-400 mt-1">When stock drops to or below this number, the item triggers a Low Stock Warning.</p>
                </div>

                <div class="pt-3 flex justify-end gap-3 border-t border-gray-100">
                    <button type="button" onclick="closeThresholdModal()" class="px-4 py-2 rounded-xl border border-gray-200 text-gray-600 font-bold text-xs hover:bg-gray-50 transition">
                        Cancel
                    </button>
                    <button type="submit" class="px-5 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs transition shadow-sm">
                        Update Trigger
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openRestockModal(id, name, currentQty) {
        document.getElementById('restockProdId').value = id;
        document.getElementById('restockProdName').textContent = name;
        document.getElementById('restockCurrentQty').textContent = currentQty + ' units';
        document.getElementById('quantity_to_add').value = '';
        document.getElementById('restockModal').classList.remove('hidden');
        document.getElementById('restockModal').classList.add('flex');
    }
    function closeRestockModal() {
        document.getElementById('restockModal').classList.add('hidden');
        document.getElementById('restockModal').classList.remove('flex');
    }

    function openAdjustModal(id, name, currentQty) {
        document.getElementById('adjustProdId').value = id;
        document.getElementById('adjustProdName').textContent = name;
        document.getElementById('exact_quantity').value = currentQty;
        document.getElementById('adjustModal').classList.remove('hidden');
        document.getElementById('adjustModal').classList.add('flex');
    }
    function closeAdjustModal() {
        document.getElementById('adjustModal').classList.add('hidden');
        document.getElementById('adjustModal').classList.remove('flex');
    }

    function openThresholdModal(id, name, currentThreshold) {
        document.getElementById('thresholdProdId').value = id;
        document.getElementById('thresholdProdName').textContent = name;
        document.getElementById('threshold_input').value = currentThreshold;
        document.getElementById('thresholdModal').classList.remove('hidden');
        document.getElementById('thresholdModal').classList.add('flex');
    }
    function closeThresholdModal() {
        document.getElementById('thresholdModal').classList.add('hidden');
        document.getElementById('thresholdModal').classList.remove('flex');
    }
    </script>
</body>
</html>
