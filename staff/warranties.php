<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/warranty_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUser = getCurrentUser();
requireAuth(['staff', 'admin']);
$pdo = Database::getConnection();

// Synchronize all warranty statuses
syncWarrantyStatuses($pdo);

$q      = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$type   = trim($_GET['type'] ?? '');

$where = [];
$params = [];

if ($q !== '') {
    $where[] = "(w.warranty_code LIKE ? OR u.name LIKE ? OR u.phone LIKE ? OR u.email LIKE ? OR p.name LIKE ? OR o.order_number LIKE ? OR r.repair_code LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like, $like);
}

if ($status !== '' && in_array($status, ['active', 'claimed', 'expired', 'void'], true)) {
    $where[] = "w.status = ?";
    $params[] = $status;
}

if ($type !== '' && in_array($type, ['product', 'repair'], true)) {
    $where[] = "w.warranty_type = ?";
    $params[] = $type;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$perPage = 15;
$page = max(1, (int)($_GET['page'] ?? 1));
$total = 0;
$warranties = [];

try {
    $cntStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM warranties w
        JOIN users u ON u.id = w.user_id
        LEFT JOIN products p ON p.id = w.product_id
        LEFT JOIN orders o ON o.id = w.order_id
        LEFT JOIN repairs r ON r.id = w.repair_id
        $whereSql
    ");
    $cntStmt->execute($params);
    $total = (int) $cntStmt->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    $query = "
        SELECT 
            w.*,
            u.name AS customer_name,
            u.phone AS customer_phone,
            u.email AS customer_email,
            p.name AS product_name,
            p.brand AS product_brand,
            p.category AS product_category,
            COALESCE(p.warranty_period_months, 6) AS product_warranty_months,
            COALESCE(p.is_repairable, 0) AS is_repairable,
            o.order_number,
            r.repair_code,
            r.device_model
        FROM warranties w
        JOIN users u ON u.id = w.user_id
        LEFT JOIN products p ON p.id = w.product_id
        LEFT JOIN orders o ON o.id = w.order_id
        LEFT JOIN repairs r ON r.id = w.repair_id
        $whereSql
        ORDER BY w.created_at DESC
        LIMIT ? OFFSET ?
    ";
    $st = $pdo->prepare($query);
    $idx = 1;
    foreach ($params as $p) {
        $st->bindValue($idx++, $p, is_int($p) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $st->bindValue($idx++, $perPage, PDO::PARAM_INT);
    $st->bindValue($idx, $offset, PDO::PARAM_INT);
    $st->execute();
    $warranties = $st->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log('Staff warranties error: ' . $e->getMessage());
    $pages = 1;
}

// Global counts
$counts = ['total' => 0, 'active' => 0, 'claimed' => 0, 'expired' => 0];
try {
    $cRow = $pdo->query("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_cnt,
            SUM(CASE WHEN status = 'claimed' THEN 1 ELSE 0 END) AS claimed_cnt,
            SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) AS expired_cnt
        FROM warranties
    ")->fetch(PDO::FETCH_ASSOC);
    if ($cRow) {
        $counts['total']   = (int) $cRow['total'];
        $counts['active']  = (int) $cRow['active_cnt'];
        $counts['claimed'] = (int) $cRow['claimed_cnt'];
        $counts['expired'] = (int) $cRow['expired_cnt'];
    }
} catch (Throwable $e) {}

$pageTitle = 'Warranties & Claims';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> | <?php echo APP_NAME; ?> Staff</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Stylesheet -->
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css">

    <!-- Tailwind -->
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

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="<?php echo APP_URL; ?>/assets/js/main.js" defer></script>
</head>

<body class="bg-gray-100 text-gray-800 font-sans antialiased">

    <div class="flex min-h-screen">

        <!-- STAFF SIDEBAR -->
        <?php require_once __DIR__ . '/../includes/staff_sidebar.php'; ?>

        <!-- MAIN CONTAINER -->
        <div class="flex-1 flex flex-col min-w-0">

            <!-- TOP BAR -->
            <header class="bg-white border-b border-gray-200 sticky top-0 z-20 flex items-center gap-4 px-6 h-16 flex-shrink-0">
                <button id="sidebar-open-btn" type="button" class="lg:hidden text-gray-500 hover:text-gray-900" aria-label="Open sidebar">
                    <i data-lucide="menu" class="w-5 h-5"></i>
                </button>

                <div class="flex-1 min-w-0 flex items-center gap-2 text-sm">
                    <span class="text-gray-400 font-medium hidden sm:inline">Staff Portal</span>
                    <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-gray-300 hidden sm:inline flex-shrink-0"></i>
                    <span class="font-bold text-gray-900">Warranty Lookup & Claims</span>
                </div>

                <div class="flex items-center gap-3">
                    <span class="text-xs text-gray-500 hidden sm:inline"><?php echo number_format($total); ?> records</span>
                    <a href="profile.php" class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center text-white text-sm font-bold flex-shrink-0">
                        <?php echo strtoupper(substr($currentUser['name'] ?? 'S', 0, 1)); ?>
                    </a>
                </div>
            </header>

            <!-- PAGE BODY -->
            <main class="flex-1 p-5 sm:p-7 overflow-auto">

                <!-- Header & Quick Lookup Banner -->
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                    <div>
                        <h1 class="text-2xl font-black text-gray-900 tracking-tight">Warranty Records & Service Verification</h1>
                        <p class="text-xs text-gray-500 mt-1">Verify individual product warranty periods and process customer claims.</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2.5">
                        <a href="<?php echo APP_URL; ?>/admin/warranties/add.php"
                           class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-xs font-bold transition shadow-sm">
                            <i data-lucide="plus-circle" class="w-4 h-4"></i> Issue Walk-In Warranty
                        </a>
                        <a href="<?php echo APP_URL; ?>/warranty-check.php" target="_blank"
                           class="inline-flex items-center gap-2 px-4 py-2 rounded-xl border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-xs font-bold transition shadow-sm">
                            <i data-lucide="external-link" class="w-4 h-4 text-brand-red"></i> Verification Tool
                        </a>
                    </div>
                </div>

                <!-- KPI Mini Cards -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
                    <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
                        <p class="text-[10px] font-bold uppercase text-gray-400 tracking-wider">Total Warranties</p>
                        <p class="text-xl font-black text-gray-900 mt-1"><?php echo $counts['total']; ?></p>
                    </div>
                    <div class="bg-white border border-green-200 rounded-xl p-4 shadow-sm">
                        <p class="text-[10px] font-bold uppercase text-green-600 tracking-wider">Active Coverage</p>
                        <p class="text-xl font-black text-green-700 mt-1"><?php echo $counts['active']; ?></p>
                    </div>
                    <div class="bg-white border border-blue-200 rounded-xl p-4 shadow-sm">
                        <p class="text-[10px] font-bold uppercase text-blue-600 tracking-wider">Customer Claims</p>
                        <p class="text-xl font-black text-blue-700 mt-1"><?php echo $counts['claimed']; ?></p>
                    </div>
                    <div class="bg-white border border-amber-200 rounded-xl p-4 shadow-sm">
                        <p class="text-[10px] font-bold uppercase text-amber-600 tracking-wider">Expired</p>
                        <p class="text-xl font-black text-amber-700 mt-1"><?php echo $counts['expired']; ?></p>
                    </div>
                </div>

                <!-- Filter & Search Toolbar -->
                <div class="bg-white border border-gray-200 rounded-2xl p-4 mb-6 shadow-sm">
                    <form method="GET" class="flex flex-col sm:flex-row gap-3">
                        <div class="relative flex-1">
                            <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>"
                                   placeholder="Search by code, customer name, phone, order #, or item..."
                                   class="w-full pl-10 pr-4 py-2 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-800 placeholder-gray-400 focus:bg-white focus:outline-none focus:border-brand-red transition">
                            <i data-lucide="search" class="w-4 h-4 text-gray-400 absolute left-3.5 top-1/2 -translate-y-1/2"></i>
                        </div>

                        <select name="status" class="px-3 py-2 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-700 focus:outline-none focus:border-brand-red">
                            <option value="">All Statuses</option>
                            <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="claimed" <?php echo $status === 'claimed' ? 'selected' : ''; ?>>Claimed</option>
                            <option value="expired" <?php echo $status === 'expired' ? 'selected' : ''; ?>>Expired</option>
                            <option value="void" <?php echo $status === 'void' ? 'selected' : ''; ?>>Void</option>
                        </select>

                        <select name="type" class="px-3 py-2 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-700 focus:outline-none focus:border-brand-red">
                            <option value="">All Types</option>
                            <option value="product" <?php echo $type === 'product' ? 'selected' : ''; ?>>Product Sales</option>
                            <option value="repair" <?php echo $type === 'repair' ? 'selected' : ''; ?>>Repairs</option>
                        </select>

                        <button type="submit" class="px-4 py-2 rounded-xl bg-brand-dark hover:bg-black text-white text-xs font-bold transition flex items-center justify-center gap-1.5">
                            <i data-lucide="filter" class="w-3.5 h-3.5"></i> Filter
                        </button>

                        <?php if ($q !== '' || $status !== '' || $type !== ''): ?>
                            <a href="warranties.php" class="px-3 py-2 rounded-xl border border-gray-200 text-gray-600 text-xs font-bold hover:bg-gray-50 transition flex items-center justify-center">
                                Clear
                            </a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- TABLE SECTION -->
                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-gray-50 border-b border-gray-200 text-gray-500 uppercase tracking-wider font-extrabold text-[10px]">
                                <tr>
                                    <th class="py-3 px-4">Warranty Code</th>
                                    <th class="py-3 px-4">Customer</th>
                                    <th class="py-3 px-4">Product / Item</th>
                                    <th class="py-3 px-4">Product Period</th>
                                    <th class="py-3 px-4">Valid Until</th>
                                    <th class="py-3 px-4">Status</th>
                                    <th class="py-3 px-4 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 text-gray-700">
                                <?php if (empty($warranties)): ?>
                                    <tr>
                                        <td colspan="7" class="py-8 text-center text-gray-400">
                                            No warranty records found matching your filters.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($warranties as $w): ?>
                                        <?php
                                            $st = $w['status'];
                                            $isRepair = ($w['warranty_type'] === 'repair');
                                            $title = $isRepair ? ($w['device_model'] ? 'Repair: ' . $w['device_model'] : 'Service Repair') : ($w['product_name'] ?? 'Product');
                                            $periodMonths = $isRepair ? 3 : (int) $w['product_warranty_months'];
                                            
                                            $badge = match($st) {
                                                'active'  => 'bg-green-100 text-green-800',
                                                'claimed' => 'bg-blue-100 text-blue-800 font-bold',
                                                'expired' => 'bg-amber-100 text-amber-800',
                                                'void'    => 'bg-red-100 text-red-800',
                                                default   => 'bg-gray-100 text-gray-800'
                                            };
                                        ?>
                                        <tr class="hover:bg-gray-50/80 transition">
                                            <td class="py-3 px-4">
                                                <span class="font-mono font-bold text-gray-900 bg-gray-100 px-2 py-0.5 rounded">
                                                    <?php echo htmlspecialchars($w['warranty_code']); ?>
                                                </span>
                                                <span class="block text-[10px] text-gray-400 uppercase tracking-wider mt-0.5">
                                                    <?php echo ucfirst($w['warranty_type']); ?>
                                                </span>
                                            </td>

                                            <td class="py-3 px-4">
                                                <p class="font-bold text-gray-900"><?php echo htmlspecialchars($w['customer_name']); ?></p>
                                                <p class="text-[11px] text-gray-400"><?php echo htmlspecialchars($w['customer_phone'] ?? $w['customer_email']); ?></p>
                                            </td>

                                            <td class="py-3 px-4">
                                                <p class="font-semibold text-gray-900 max-w-xs truncate"><?php echo htmlspecialchars($title); ?></p>
                                                <?php if (!empty($w['order_number'])): ?>
                                                    <span class="text-[10px] text-gray-500">Order: <?php echo htmlspecialchars($w['order_number']); ?></span>
                                                <?php elseif (!empty($w['repair_code'])): ?>
                                                    <span class="text-[10px] text-gray-500">Repair: <?php echo htmlspecialchars($w['repair_code']); ?></span>
                                                <?php endif; ?>
                                                <div class="mt-1">
                                                    <?php if ($isRepair || (int)($w['is_repairable'] ?? 0) === 1): ?>
                                                        <span class="inline-flex items-center gap-1 text-[10px] font-bold text-green-700 bg-green-50 px-1.5 py-0.5 rounded border border-green-200">
                                                            <i data-lucide="wrench" class="w-3 h-3"></i> Serviceable Device
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="inline-flex items-center gap-1 text-[10px] font-bold text-indigo-700 bg-indigo-50 px-1.5 py-0.5 rounded border border-indigo-200">
                                                            <i data-lucide="refresh-cw" class="w-3 h-3"></i> 1-to-1 Exchange Only
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>

                                            <td class="py-3 px-4">
                                                <span class="font-extrabold text-brand-red"><?php echo $periodMonths; ?> Months</span>
                                                <span class="block text-[10px] text-gray-400">Individual term</span>
                                            </td>

                                            <td class="py-3 px-4">
                                                <p class="font-semibold text-gray-800"><?php echo date('M d, Y', strtotime($w['end_date'])); ?></p>
                                                <span class="text-[10px] text-gray-400">Start: <?php echo date('M d, Y', strtotime($w['start_date'])); ?></span>
                                            </td>

                                            <td class="py-3 px-4">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] uppercase font-bold <?php echo $badge; ?>">
                                                    <?php echo ucfirst($st); ?>
                                                </span>
                                            </td>

                                            <td class="py-3 px-4 text-right">
                                                <a href="<?php echo APP_URL; ?>/warranty-check.php?code=<?php echo urlencode($w['warranty_code']); ?>" 
                                                   target="_blank"
                                                   class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 text-[11px] font-bold transition">
                                                    <i data-lucide="eye" class="w-3.5 h-3.5"></i> Inspect
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($pages > 1): ?>
                        <div class="px-4 py-3 border-t border-gray-200 bg-gray-50 flex items-center justify-between text-xs">
                            <span class="text-gray-500">Showing page <?php echo $page; ?> of <?php echo $pages; ?></span>
                            <div class="flex gap-1">
                                <?php for ($p = 1; $p <= $pages; $p++): ?>
                                    <a href="?page=<?php echo $p; ?>&q=<?php echo urlencode($q); ?>&status=<?php echo urlencode($status); ?>&type=<?php echo urlencode($type); ?>"
                                       class="px-2.5 py-1 rounded-lg font-bold <?php echo $p === $page ? 'bg-brand-red text-white' : 'bg-white border border-gray-200 text-gray-700 hover:bg-gray-50'; ?>">
                                        <?php echo $p; ?>
                                    </a>
                                <?php endfor; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            </main>
        </div>
    </div>

</body>
</html>
