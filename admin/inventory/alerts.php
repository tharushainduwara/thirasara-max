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

// Handle Restock action from Alerts
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = trim($_POST['action'] ?? '');
    $productId = (int) ($_POST['product_id'] ?? 0);

    if ($productId <= 0) {
        $feedback = ['type' => 'error', 'message' => 'Invalid product specified.'];
    } elseif ($action === 'restock') {
        $qtyToAdd = (int) ($_POST['quantity_to_add'] ?? 0);
        $notes    = trim($_POST['notes'] ?? 'Priority low-stock alert restock');

        if ($qtyToAdd <= 0) {
            $feedback = ['type' => 'error', 'message' => 'Quantity must be greater than 0.'];
        } else {
            $res = adjustStock($pdo, $productId, $qtyToAdd, 'restock', $notes, $uid);
            $feedback = [
                'type' => 'success',
                'message' => "Successfully replenished +{$qtyToAdd} units. Current stock is now {$res['new_stock']} units."
            ];
        }
    }
}

// Fetch Global Inventory Stats
$stats = getInventoryStats($pdo);

// Filters
$filterLevel = trim($_GET['level'] ?? 'all'); // 'all', 'critical' (0 units), 'warning' (<= threshold)
$search      = trim($_GET['search'] ?? '');

$where = [
    "p.status != 'discontinued'",
    "COALESCE(i.stock_quantity, 0) <= COALESCE(i.low_stock_threshold, 5)"
];
$params = [];

if ($filterLevel === 'critical') {
    $where[] = "COALESCE(i.stock_quantity, 0) = 0";
} elseif ($filterLevel === 'warning') {
    $where[] = "COALESCE(i.stock_quantity, 0) > 0";
}

if ($search !== '') {
    $where[] = "(p.name LIKE ? OR p.brand LIKE ? OR p.category LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

// Fetch Alert Products
$query = "
    SELECT 
        p.id AS product_id,
        p.name AS product_name,
        p.category,
        p.brand,
        p.price,
        p.image_url,
        COALESCE(i.stock_quantity, 0) AS stock_quantity,
        COALESCE(i.low_stock_threshold, 5) AS low_stock_threshold,
        (COALESCE(i.low_stock_threshold, 5) - COALESCE(i.stock_quantity, 0)) AS units_deficit,
        i.last_restocked_at
    FROM products p
    LEFT JOIN inventory i ON i.product_id = p.id
    {$whereSql}
    ORDER BY COALESCE(i.stock_quantity, 0) ASC, (COALESCE(i.low_stock_threshold, 5) - COALESCE(i.stock_quantity, 0)) DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$alertItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate total deficit units across all depleted items
$totalDeficit = 0;
foreach ($alertItems as $it) {
    $totalDeficit += max(0, (int) $it['units_deficit']);
}

$pageTitle = 'Low Stock & Depletion Alerts';
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
                    <a href="index.php" class="text-gray-500 hover:text-gray-800 font-medium">Inventory</a>
                    <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-gray-300 hidden sm:inline flex-shrink-0"></i>
                    <span class="font-bold text-gray-900">Low Stock Trigger &amp; Alerts</span>
                </div>
                <div class="flex items-center gap-3">
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
                        <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-amber-600 mb-1">
                            <i data-lucide="bell-ring" class="w-3.5 h-3.5"></i> Depletion Warning Dashboard
                        </div>
                        <h1 class="text-2xl font-black text-gray-900 tracking-tight">Low Stock &amp; Depletion Alerts</h1>
                        <p class="text-xs text-gray-500 mt-1">Products currently at or below safety threshold that require immediate vendor restocking.</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <a href="index.php" class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-xs font-bold transition shadow-sm">
                            <i data-lucide="warehouse" class="w-4 h-4 text-brand-red"></i> Full Inventory
                        </a>
                    </div>
                </div>

                <!-- FLASH MESSAGE -->
                <?php if ($feedback['message'] !== ''): ?>
                    <div class="mb-6 p-4 rounded-xl text-xs font-semibold flex items-center gap-3 shadow-sm <?php echo $feedback['type'] === 'success' ? 'bg-green-50 text-green-800 border border-green-200' : 'bg-red-50 text-red-800 border border-red-200'; ?>">
                        <i data-lucide="<?php echo $feedback['type'] === 'success' ? 'check-circle' : 'alert-circle'; ?>" class="w-4 h-4 flex-shrink-0"></i>
                        <p><?php echo htmlspecialchars($feedback['message']); ?></p>
                    </div>
                <?php endif; ?>

                <!-- SUMMARY KPI STRIP -->
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
                    <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Total Alert SKUs</span>
                        <p class="text-2xl font-black text-gray-900 mt-1"><?php echo $stats['alerts_total']; ?></p>
                        <p class="text-[11px] text-gray-400 mt-0.5">Below safety thresholds</p>
                    </div>

                    <div class="bg-white border border-red-200 rounded-xl p-4 shadow-sm">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-red-600">Critical: Out of Stock</span>
                        <p class="text-2xl font-black text-red-700 mt-1"><?php echo $stats['out_of_stock_count']; ?></p>
                        <p class="text-[11px] text-red-500 mt-0.5">Zero units in store</p>
                    </div>

                    <div class="bg-white border border-amber-200 rounded-xl p-4 shadow-sm">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-amber-600">Low Stock Warning</span>
                        <p class="text-2xl font-black text-amber-700 mt-1"><?php echo $stats['low_stock_count']; ?></p>
                        <p class="text-[11px] text-amber-500 mt-0.5">Approaching depletion</p>
                    </div>

                    <div class="bg-white border border-indigo-200 rounded-xl p-4 shadow-sm">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-indigo-600">Total Deficit Units</span>
                        <p class="text-2xl font-black text-indigo-700 mt-1"><?php echo number_format($totalDeficit); ?> <span class="text-xs font-normal text-gray-500">units</span></p>
                        <p class="text-[11px] text-indigo-500 mt-0.5">To reach safety threshold</p>
                    </div>
                </div>

                <!-- FILTER TABS & SEARCH -->
                <div class="bg-white border border-gray-200 rounded-2xl p-4 mb-6 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-4">
                    <!-- Filter Tabs -->
                    <div class="flex flex-wrap gap-2 w-full sm:w-auto">
                        <a href="?level=all&search=<?php echo urlencode($search); ?>" 
                           class="px-3.5 py-2 rounded-xl text-xs font-bold transition <?php echo $filterLevel === 'all' ? 'bg-brand-dark text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                            All Depleted Items (<?php echo $stats['alerts_total']; ?>)
                        </a>
                        <a href="?level=critical&search=<?php echo urlencode($search); ?>" 
                           class="px-3.5 py-2 rounded-xl text-xs font-bold transition <?php echo $filterLevel === 'critical' ? 'bg-red-600 text-white' : 'bg-red-50 text-red-700 hover:bg-red-100 border border-red-200'; ?>">
                            Critical Out of Stock (<?php echo $stats['out_of_stock_count']; ?>)
                        </a>
                        <a href="?level=warning&search=<?php echo urlencode($search); ?>" 
                           class="px-3.5 py-2 rounded-xl text-xs font-bold transition <?php echo $filterLevel === 'warning' ? 'bg-amber-500 text-white' : 'bg-amber-50 text-amber-700 hover:bg-amber-100 border border-amber-200'; ?>">
                            Low Stock Warning (<?php echo $stats['low_stock_count']; ?>)
                        </a>
                    </div>

                    <!-- Search Input -->
                    <form method="GET" class="w-full sm:w-72">
                        <input type="hidden" name="level" value="<?php echo htmlspecialchars($filterLevel); ?>">
                        <div class="relative">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                                   placeholder="Search alert items..."
                                   class="w-full pl-9 pr-4 py-2 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-800 placeholder-gray-400 focus:bg-white focus:outline-none focus:border-brand-red transition">
                            <i data-lucide="search" class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2"></i>
                        </div>
                    </form>
                </div>

                <!-- ALERTS TABLE -->
                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-gray-50 border-b border-gray-200 text-gray-500 uppercase tracking-wider font-extrabold text-[10px]">
                                <tr>
                                    <th class="py-3 px-4">Urgency Level</th>
                                    <th class="py-3 px-4">Product Details</th>
                                    <th class="py-3 px-4">Current Stock</th>
                                    <th class="py-3 px-4">Safety Trigger</th>
                                    <th class="py-3 px-4">Deficit Below Safety</th>
                                    <th class="py-3 px-4">Last Restocked</th>
                                    <th class="py-3 px-4 text-right">Immediate Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 text-gray-700">
                                <?php if (empty($alertItems)): ?>
                                    <tr>
                                        <td colspan="7" class="py-14 text-center">
                                            <div class="w-12 h-12 rounded-2xl bg-green-50 text-green-600 flex items-center justify-center mx-auto mb-3">
                                                <i data-lucide="check-circle-2" class="w-6 h-6"></i>
                                            </div>
                                            <p class="font-bold text-gray-900 text-sm">All Inventory Levels Are Healthy!</p>
                                            <p class="text-xs text-gray-400 mt-1">No products are currently depleted or below safety thresholds.</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($alertItems as $item): ?>
                                        <?php
                                            $qty = (int) $item['stock_quantity'];
                                            $threshold = (int) $item['low_stock_threshold'];
                                            $deficit = max(0, $threshold - $qty);
                                            $suggestedRestock = max(20, $deficit + 15);
                                            $isCritical = ($qty === 0);
                                        ?>
                                        <tr class="hover:bg-gray-50/80 transition">
                                            <!-- Urgency Badge -->
                                            <td class="py-3 px-4 whitespace-nowrap">
                                                <?php if ($isCritical): ?>
                                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-black uppercase tracking-wider bg-red-100 text-red-800 border border-red-300">
                                                        <span class="w-2 h-2 rounded-full bg-red-600 animate-ping"></span>
                                                        CRITICAL OUT OF STOCK
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-black uppercase tracking-wider bg-amber-100 text-amber-800 border border-amber-300">
                                                        <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                                                        LOW STOCK WARNING
                                                    </span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Product Details -->
                                            <td class="py-3 px-4">
                                                <div class="flex items-center gap-3">
                                                    <div class="w-10 h-10 rounded-xl bg-gray-100 border border-gray-200 flex-shrink-0 overflow-hidden flex items-center justify-center">
                                                        <?php if (!empty($item['image_url'])): ?>
                                                            <img src="<?php echo APP_URL . '/' . ltrim($item['image_url'], '/'); ?>" alt="" class="w-full h-full object-cover">
                                                        <?php else: ?>
                                                            <i data-lucide="package" class="w-5 h-5 text-gray-400"></i>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <span class="font-bold text-gray-900 block truncate max-w-xs">
                                                            <?php echo htmlspecialchars($item['product_name']); ?>
                                                        </span>
                                                        <span class="text-[10px] text-gray-400">
                                                            <?php echo htmlspecialchars($item['brand'] ?? 'Store Item'); ?> &bull; <?php echo htmlspecialchars($item['category']); ?> &bull; Rs. <?php echo number_format((float) $item['price'], 2); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </td>

                                            <!-- Current Stock -->
                                            <td class="py-3 px-4">
                                                <span class="text-base font-black <?php echo $isCritical ? 'text-red-600' : 'text-amber-600'; ?>">
                                                    <?php echo $qty; ?>
                                                </span>
                                                <span class="text-xs text-gray-400">units</span>
                                            </td>

                                            <!-- Safety Trigger -->
                                            <td class="py-3 px-4 font-semibold text-gray-700">
                                                &le; <?php echo $threshold; ?> units
                                            </td>

                                            <!-- Deficit -->
                                            <td class="py-3 px-4">
                                                <span class="inline-flex items-center gap-1 font-bold text-red-600 bg-red-50 px-2 py-0.5 rounded border border-red-100">
                                                    <i data-lucide="arrow-down" class="w-3 h-3"></i> -<?php echo $deficit; ?> units
                                                </span>
                                            </td>

                                            <!-- Last Restocked -->
                                            <td class="py-3 px-4 text-gray-500 text-[11px]">
                                                <?php if (!empty($item['last_restocked_at'])): ?>
                                                    <?php echo date('M d, Y', strtotime($item['last_restocked_at'])); ?>
                                                <?php else: ?>
                                                    <span class="text-gray-400 italic">Never</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Actions -->
                                            <td class="py-3 px-4 text-right whitespace-nowrap">
                                                <button type="button" 
                                                        onclick="openPriorityRestockModal(<?php echo (int) $item['product_id']; ?>, '<?php echo htmlspecialchars(addslashes($item['product_name'])); ?>', <?php echo $qty; ?>, <?php echo $suggestedRestock; ?>)"
                                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-brand-red hover:bg-brand-redHover text-white font-bold text-xs transition shadow-sm">
                                                    <i data-lucide="plus-circle" class="w-3.5 h-3.5"></i> Restock Now
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <!-- PRIORITY RESTOCK MODAL -->
    <div id="priorityRestockModal" class="fixed inset-0 z-50 bg-black/60 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl relative animate-in fade-in zoom-in-95">
            <button type="button" onclick="closePriorityRestockModal()" class="absolute top-5 right-5 text-gray-400 hover:text-gray-700">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>

            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-xl bg-red-50 text-brand-red flex items-center justify-center flex-shrink-0">
                    <i data-lucide="truck" class="w-5 h-5"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-gray-900">Priority Inventory Replenishment</h3>
                    <p class="text-xs text-gray-500" id="modalAlertProdName"></p>
                </div>
            </div>

            <form method="POST" action="" class="space-y-4">
                <input type="hidden" name="action" value="restock">
                <input type="hidden" name="product_id" id="modalAlertProdId" value="">

                <div class="p-3 bg-amber-50 rounded-xl border border-amber-200 flex items-center justify-between text-xs text-amber-900">
                    <span class="font-semibold">Current Stock Level:</span>
                    <span id="modalAlertCurrentQty" class="font-extrabold text-red-600">0 units</span>
                </div>

                <div>
                    <label for="modal_quantity_to_add" class="block text-xs font-bold text-gray-700 mb-1">
                        Quantity Received / Adding <span class="text-brand-red">*</span>
                    </label>
                    <input type="number" id="modal_quantity_to_add" name="quantity_to_add" min="1" max="10000" required
                           class="w-full px-3.5 py-2.5 rounded-xl border border-gray-300 text-xs font-bold text-gray-900 focus:outline-none focus:border-brand-red">
                    <p class="text-[11px] text-gray-400 mt-1" id="modalSuggestedText"></p>
                </div>

                <div>
                    <label for="modal_notes" class="block text-xs font-bold text-gray-700 mb-1">
                        Shipment / Invoice Reference <span class="text-gray-400 font-normal">(Optional)</span>
                    </label>
                    <input type="text" id="modal_notes" name="notes" placeholder="e.g. Urgent shipment delivery #GRN-981"
                           class="w-full px-3.5 py-2 rounded-xl border border-gray-300 text-xs text-gray-800 focus:outline-none focus:border-brand-red">
                </div>

                <div class="pt-3 flex justify-end gap-3 border-t border-gray-100">
                    <button type="button" onclick="closePriorityRestockModal()" class="px-4 py-2 rounded-xl border border-gray-200 text-gray-600 font-bold text-xs hover:bg-gray-50 transition">
                        Cancel
                    </button>
                    <button type="submit" class="px-5 py-2 rounded-xl bg-brand-red hover:bg-brand-redHover text-white font-bold text-xs transition shadow-sm">
                        Confirm &amp; Update Stock
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openPriorityRestockModal(id, name, currentQty, suggested) {
        document.getElementById('modalAlertProdId').value = id;
        document.getElementById('modalAlertProdName').textContent = name;
        document.getElementById('modalAlertCurrentQty').textContent = currentQty + ' units';
        document.getElementById('modal_quantity_to_add').value = suggested;
        document.getElementById('modalSuggestedText').textContent = 'Suggested replenishment: ' + suggested + ' units to exceed safety threshold.';
        document.getElementById('priorityRestockModal').classList.remove('hidden');
        document.getElementById('priorityRestockModal').classList.add('flex');
    }

    function closePriorityRestockModal() {
        document.getElementById('priorityRestockModal').classList.add('hidden');
        document.getElementById('priorityRestockModal').classList.remove('flex');
    }
    </script>
</body>
</html>
