<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/warranty_helpers.php';

requireAuth(['admin']);

$currentUser = getCurrentUser();
$pdo = Database::getConnection();

// Synchronize all warranty statuses
syncWarrantyStatuses($pdo);

$feedback = ['type' => '', 'message' => ''];

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    $warrantyId = (int) ($_POST['warranty_id'] ?? 0);
    $newStatus  = trim($_POST['status'] ?? '');
    $validStatuses = ['active', 'claimed', 'expired', 'void'];

    if ($warrantyId > 0 && in_array($newStatus, $validStatuses, true)) {
        try {
            $up = $pdo->prepare("UPDATE warranties SET status = ? WHERE id = ?");
            $up->execute([$newStatus, $warrantyId]);
            $feedback = ['type' => 'success', 'message' => 'Warranty status updated successfully.'];
        } catch (Throwable $e) {
            $feedback = ['type' => 'error', 'message' => 'Failed to update warranty: ' . $e->getMessage()];
        }
    } else {
        $feedback = ['type' => 'error', 'message' => 'Invalid parameters provided.'];
    }
}

// Filters & Pagination
$statusFilter = trim($_GET['status'] ?? '');
$typeFilter   = trim($_GET['type'] ?? '');
$searchQuery  = trim($_GET['search'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$limit        = 15;
$offset       = ($page - 1) * $limit;

$whereClause = ['1=1'];
$params      = [];

if ($statusFilter !== '' && in_array($statusFilter, ['active', 'claimed', 'expired', 'void'], true)) {
    $whereClause[] = 'w.status = ?';
    $params[]      = $statusFilter;
}

if ($typeFilter !== '' && in_array($typeFilter, ['product', 'repair'], true)) {
    $whereClause[] = 'w.warranty_type = ?';
    $params[]      = $typeFilter;
}

if ($searchQuery !== '') {
    $whereClause[] = '(w.warranty_code LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR p.name LIKE ? OR o.order_number LIKE ? OR r.repair_code LIKE ?)';
    $searchTerm    = "%{$searchQuery}%";
    array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
}

$whereSql = implode(' AND ', $whereClause);

// Stats counts
$counts = ['total' => 0, 'active' => 0, 'claimed' => 0, 'expired' => 0, 'void' => 0];
try {
    $cRow = $pdo->query("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_cnt,
            SUM(CASE WHEN status = 'claimed' THEN 1 ELSE 0 END) AS claimed_cnt,
            SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) AS expired_cnt,
            SUM(CASE WHEN status = 'void' THEN 1 ELSE 0 END) AS void_cnt
        FROM warranties
    ")->fetch(PDO::FETCH_ASSOC);
    if ($cRow) {
        $counts['total']   = (int) $cRow['total'];
        $counts['active']  = (int) $cRow['active_cnt'];
        $counts['claimed'] = (int) $cRow['claimed_cnt'];
        $counts['expired'] = (int) $cRow['expired_cnt'];
        $counts['void']    = (int) $cRow['void_cnt'];
    }
} catch (Throwable $e) {}

// Total matching
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM warranties w 
    JOIN users u ON u.id = w.user_id 
    LEFT JOIN products p ON p.id = w.product_id
    LEFT JOIN orders o ON o.id = w.order_id
    LEFT JOIN repairs r ON r.id = w.repair_id
    WHERE {$whereSql}
");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages   = max(1, (int) ceil($totalRecords / $limit));

// Fetch records
$stmt = $pdo->prepare("
    SELECT 
        w.*,
        u.name AS customer_name,
        u.email AS customer_email,
        u.phone AS customer_phone,
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
    WHERE {$whereSql}
    ORDER BY w.created_at DESC
    LIMIT {$limit} OFFSET {$offset}
");
$stmt->execute($params);
$warranties = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Manage Warranties';
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
                    <span class="font-bold text-gray-900">Warranty Administration</span>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-xs text-gray-500 hidden sm:inline"><?php echo number_format($totalRecords); ?> records</span>
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
                        <h1 class="text-2xl font-black text-gray-900 tracking-tight">Warranty Certificates & Claims</h1>
                        <p class="text-xs text-gray-500 mt-1">Manage digital warranties, monitor individual product coverage periods, and handle replacement claims.</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <a href="add.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-xs font-bold transition shadow-sm">
                            <i data-lucide="plus-circle" class="w-4 h-4"></i> Register Manual Warranty
                        </a>
                        <a href="<?php echo APP_URL; ?>/warranty-check.php" target="_blank" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-xs font-bold transition shadow-sm">
                            <i data-lucide="external-link" class="w-4 h-4 text-brand-red"></i> Verification Tool
                        </a>
                    </div>
                </div>

                <!-- ALERTS -->
                <?php if ($feedback['message'] !== ''): ?>
                    <div class="mb-6 p-4 rounded-xl text-xs font-semibold flex items-center gap-3 shadow-sm <?php echo $feedback['type'] === 'success' ? 'bg-green-50 text-green-800 border border-green-200' : 'bg-red-50 text-red-800 border border-red-200'; ?>">
                        <i data-lucide="<?php echo $feedback['type'] === 'success' ? 'check-circle' : 'alert-circle'; ?>" class="w-4 h-4 flex-shrink-0"></i>
                        <p><?php echo htmlspecialchars($feedback['message']); ?></p>
                    </div>
                <?php endif; ?>

                <!-- KPI METRICS -->
                <div class="grid grid-cols-2 sm:grid-cols-5 gap-4 mb-6">
                    <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Total Registered</span>
                        <p class="text-xl font-black text-gray-900 mt-1"><?php echo $counts['total']; ?></p>
                    </div>
                    <div class="bg-white border border-green-200 rounded-xl p-4 shadow-sm">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-green-600">Active Valid</span>
                        <p class="text-xl font-black text-green-700 mt-1"><?php echo $counts['active']; ?></p>
                    </div>
                    <div class="bg-white border border-blue-200 rounded-xl p-4 shadow-sm">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-blue-600">Pending Claims</span>
                        <p class="text-xl font-black text-blue-700 mt-1"><?php echo $counts['claimed']; ?></p>
                    </div>
                    <div class="bg-white border border-amber-200 rounded-xl p-4 shadow-sm">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-amber-600">Expired</span>
                        <p class="text-xl font-black text-amber-700 mt-1"><?php echo $counts['expired']; ?></p>
                    </div>
                    <div class="bg-white border border-red-200 rounded-xl p-4 shadow-sm">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-red-600">Void / Cancelled</span>
                        <p class="text-xl font-black text-red-700 mt-1"><?php echo $counts['void']; ?></p>
                    </div>
                </div>

                <!-- FILTER BAR -->
                <div class="bg-white border border-gray-200 rounded-2xl p-4 mb-6 shadow-sm">
                    <form method="GET" class="flex flex-col sm:flex-row gap-3">
                        <div class="relative flex-1">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($searchQuery); ?>"
                                   placeholder="Search by code, customer name, email, phone, product, or order #..."
                                   class="w-full pl-10 pr-4 py-2 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-800 placeholder-gray-400 focus:bg-white focus:outline-none focus:border-brand-red transition">
                            <i data-lucide="search" class="w-4 h-4 text-gray-400 absolute left-3.5 top-1/2 -translate-y-1/2"></i>
                        </div>

                        <select name="status" class="px-3 py-2 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-700 focus:outline-none focus:border-brand-red">
                            <option value="">All Statuses</option>
                            <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="claimed" <?php echo $statusFilter === 'claimed' ? 'selected' : ''; ?>>Claimed</option>
                            <option value="expired" <?php echo $statusFilter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                            <option value="void" <?php echo $statusFilter === 'void' ? 'selected' : ''; ?>>Void</option>
                        </select>

                        <select name="type" class="px-3 py-2 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-700 focus:outline-none focus:border-brand-red">
                            <option value="">All Types</option>
                            <option value="product" <?php echo $typeFilter === 'product' ? 'selected' : ''; ?>>Product Sales</option>
                            <option value="repair" <?php echo $typeFilter === 'repair' ? 'selected' : ''; ?>>Repairs</option>
                        </select>

                        <button type="submit" class="px-4 py-2 rounded-xl bg-brand-dark hover:bg-black text-white text-xs font-bold transition flex items-center justify-center gap-1.5">
                            <i data-lucide="filter" class="w-3.5 h-3.5"></i> Apply Filter
                        </button>

                        <?php if ($searchQuery !== '' || $statusFilter !== '' || $typeFilter !== ''): ?>
                            <a href="index.php" class="px-3 py-2 rounded-xl border border-gray-200 text-gray-600 text-xs font-bold hover:bg-gray-50 transition flex items-center justify-center">
                                Clear
                            </a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- TABLE CARD -->
                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-gray-50 border-b border-gray-200 text-gray-500 uppercase tracking-wider font-extrabold text-[10px]">
                                <tr>
                                    <th class="py-3 px-4">Warranty Code</th>
                                    <th class="py-3 px-4">Customer</th>
                                    <th class="py-3 px-4">Product / Item</th>
                                    <th class="py-3 px-4">Product Term</th>
                                    <th class="py-3 px-4">Start - End Date</th>
                                    <th class="py-3 px-4">Status</th>
                                    <th class="py-3 px-4 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 text-gray-700">
                                <?php if (empty($warranties)): ?>
                                    <tr>
                                        <td colspan="7" class="py-10 text-center text-gray-400">
                                            No warranty records found matching your criteria.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($warranties as $item): ?>
                                        <?php
                                            $st = $item['status'];
                                            $isRepair = ($item['warranty_type'] === 'repair');
                                            $title = $isRepair ? ($item['device_model'] ? 'Repair: ' . $item['device_model'] : 'Service Repair') : ($item['product_name'] ?? 'Product');
                                            $months = $isRepair ? 3 : (int) $item['product_warranty_months'];

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
                                                    <?php echo htmlspecialchars($item['warranty_code']); ?>
                                                </span>
                                                <span class="block text-[10px] text-gray-400 uppercase tracking-wider mt-0.5">
                                                    <?php echo ucfirst($item['warranty_type']); ?>
                                                </span>
                                            </td>

                                            <td class="py-3 px-4">
                                                <p class="font-bold text-gray-900"><?php echo htmlspecialchars($item['customer_name']); ?></p>
                                                <p class="text-[11px] text-gray-400"><?php echo htmlspecialchars($item['customer_email']); ?></p>
                                                <?php if (!empty($item['customer_phone'])): ?>
                                                    <p class="text-[10px] text-gray-400"><?php echo htmlspecialchars($item['customer_phone']); ?></p>
                                                <?php endif; ?>
                                            </td>

                                            <td class="py-3 px-4">
                                                <p class="font-semibold text-gray-900 max-w-xs truncate"><?php echo htmlspecialchars($title); ?></p>
                                                <?php if (!empty($item['order_number'])): ?>
                                                    <a href="<?php echo APP_URL; ?>/admin/orders/view.php?order=<?php echo urlencode($item['order_number']); ?>" 
                                                       class="text-[10px] text-brand-red font-semibold hover:underline">
                                                        Order: <?php echo htmlspecialchars($item['order_number']); ?>
                                                    </a>
                                                <?php elseif (!empty($item['repair_code'])): ?>
                                                    <a href="<?php echo APP_URL; ?>/admin/repairs/view.php?code=<?php echo urlencode($item['repair_code']); ?>" 
                                                       class="text-[10px] text-brand-red font-semibold hover:underline">
                                                        Repair: <?php echo htmlspecialchars($item['repair_code']); ?>
                                                    </a>
                                                <?php endif; ?>
                                                <div class="mt-1">
                                                    <?php if ($isRepair || (int)($item['is_repairable'] ?? 0) === 1): ?>
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
                                                <span class="font-extrabold text-brand-red"><?php echo $months; ?> Months</span>
                                                <span class="block text-[10px] text-gray-400">Product Term</span>
                                            </td>

                                            <td class="py-3 px-4">
                                                <p class="font-semibold text-gray-800"><?php echo date('M d, Y', strtotime($item['end_date'])); ?></p>
                                                <span class="text-[10px] text-gray-400">From <?php echo date('M d, Y', strtotime($item['start_date'])); ?></span>
                                            </td>

                                            <td class="py-3 px-4">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] uppercase font-bold <?php echo $badge; ?>">
                                                    <?php echo ucfirst($st); ?>
                                                </span>
                                            </td>

                                            <td class="py-3 px-4 text-right whitespace-nowrap">
                                                <div class="flex items-center justify-end gap-1.5">
                                                    <button type="button" 
                                                            onclick="openStatusModal(<?php echo (int) $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['warranty_code'])); ?>', '<?php echo $st; ?>', '<?php echo htmlspecialchars(addslashes($item['claim_description'] ?? '')); ?>')"
                                                            class="px-2.5 py-1 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold text-[11px] transition">
                                                        Edit Status
                                                    </button>
                                                    <a href="<?php echo APP_URL; ?>/warranty-check.php?code=<?php echo urlencode($item['warranty_code']); ?>" 
                                                       target="_blank" 
                                                       title="View Public Certificate"
                                                       class="p-1 text-gray-400 hover:text-gray-700 transition">
                                                        <i data-lucide="external-link" class="w-3.5 h-3.5"></i>
                                                    </a>
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
                            <span class="text-gray-500">Showing page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                            <div class="flex gap-1">
                                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                    <a href="?page=<?php echo $p; ?>&search=<?php echo urlencode($searchQuery); ?>&status=<?php echo urlencode($statusFilter); ?>&type=<?php echo urlencode($typeFilter); ?>"
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

    <!-- UPDATE STATUS MODAL -->
    <div id="statusModal" class="fixed inset-0 z-50 bg-black/60 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl relative animate-in fade-in zoom-in-95">
            <button type="button" onclick="closeStatusModal()" class="absolute top-5 right-5 text-gray-400 hover:text-gray-700">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>

            <h3 class="text-lg font-bold text-gray-900 mb-1">Update Warranty Status</h3>
            <p class="text-xs text-gray-500 mb-4" id="modalCodeDisplay"></p>

            <!-- Claim notes display if present -->
            <div id="modalClaimBox" class="hidden mb-4 p-3 bg-blue-50 border border-blue-100 rounded-xl text-xs text-blue-900">
                <p class="font-bold mb-1 flex items-center gap-1.5"><i data-lucide="info" class="w-3.5 h-3.5"></i> Customer Claim Note:</p>
                <p id="modalClaimText" class="text-[11px] leading-relaxed text-blue-800"></p>
            </div>

            <form method="POST" action="" class="space-y-4">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="warranty_id" id="modalWarrantyId" value="">

                <div>
                    <label for="statusSelect" class="block text-xs font-bold text-gray-700 mb-1.5">Change Status To</label>
                    <select id="statusSelect" name="status" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 text-xs font-semibold focus:outline-none focus:border-brand-red">
                        <option value="active">Active (Valid Coverage)</option>
                        <option value="claimed">Claimed (Claim Under Investigation)</option>
                        <option value="expired">Expired (Lapsed Coverage)</option>
                        <option value="void">Void (Cancelled / Tampered)</option>
                    </select>
                </div>

                <div class="pt-2 flex justify-end gap-3">
                    <button type="button" onclick="closeStatusModal()" class="px-4 py-2 rounded-xl border border-gray-200 text-gray-600 font-bold text-xs hover:bg-gray-50 transition">
                        Cancel
                    </button>
                    <button type="submit" class="px-5 py-2 rounded-xl bg-brand-red hover:bg-brand-redHover text-white font-bold text-xs transition shadow-sm">
                        Save Status
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openStatusModal(id, code, status, claimDesc) {
        document.getElementById('modalWarrantyId').value = id;
        document.getElementById('modalCodeDisplay').textContent = 'Warranty Code: ' + code;
        document.getElementById('statusSelect').value = status;

        const claimBox = document.getElementById('modalClaimBox');
        const claimText = document.getElementById('modalClaimText');
        if (claimDesc && claimDesc.trim() !== '') {
            claimText.textContent = claimDesc;
            claimBox.classList.remove('hidden');
        } else {
            claimBox.classList.add('hidden');
        }

        document.getElementById('statusModal').classList.remove('hidden');
        document.getElementById('statusModal').classList.add('flex');
    }

    function closeStatusModal() {
        document.getElementById('statusModal').classList.add('hidden');
        document.getElementById('statusModal').classList.remove('flex');
    }
    </script>
</body>
</html>
