<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/repair_helpers.php';

requireAuth(['admin']);

$currentUser = getCurrentUser();
$pdo = Database::getConnection();

$userId = (int)($_GET['id'] ?? 0);
if ($userId <= 0) {
    setFlash('error', 'Invalid user specified.');
    header('Location: index.php');
    exit;
}

// Fetch user profile
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    setFlash('error', 'User not found.');
    header('Location: index.php');
    exit;
}

// 1. Fetch Orders History
$ordersStmt = $pdo->prepare("
    SELECT o.*, COUNT(oi.id) as item_count 
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id = o.id
    WHERE o.user_id = ?
    GROUP BY o.id
    ORDER BY o.created_at DESC
");
$ordersStmt->execute([$userId]);
$orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

$totalSpent = 0.0;
foreach ($orders as $ord) {
    if ($ord['order_status'] !== 'cancelled' && $ord['payment_status'] === 'paid') {
        $totalSpent += (float)$ord['total_amount'];
    }
}

// 2. Fetch Repairs History
$repairsStmt = $pdo->prepare("
    SELECT r.*, tech.name AS tech_name 
    FROM repairs r
    LEFT JOIN users tech ON tech.id = r.technician_id
    WHERE r.user_id = ?
    ORDER BY r.created_at DESC
");
$repairsStmt->execute([$userId]);
$repairs = $repairsStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch Warranties
$warrantiesStmt = $pdo->prepare("
    SELECT w.*, p.name AS product_name 
    FROM warranties w
    LEFT JOIN products p ON p.id = w.product_id
    WHERE w.user_id = ?
    ORDER BY w.created_at DESC
");
$warrantiesStmt->execute([$userId]);
$warranties = $warrantiesStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Customer Profile: ' . $user['name'];
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
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
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
                    <a href="index.php" class="text-gray-400 font-medium hover:text-brand-red transition hidden sm:inline">Users &amp; Staff</a>
                    <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-gray-300 hidden sm:inline flex-shrink-0"></i>
                    <span class="font-bold text-gray-900 truncate"><?php echo htmlspecialchars($user['name']); ?></span>
                </div>
                <div class="flex items-center gap-3">
                    <a href="edit.php?id=<?php echo $userId; ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-gray-900 text-white text-xs font-semibold hover:bg-black transition">
                        <i data-lucide="edit-3" class="w-3.5 h-3.5"></i> Edit Account
                    </a>
                    <a href="index.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-gray-200 text-xs font-semibold text-gray-500 hover:bg-gray-50 transition">
                        <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Back
                    </a>
                </div>
            </header>

            <main class="flex-1 p-5 sm:p-7 max-w-7xl mx-auto w-full space-y-6">

                <!-- 1. Header Profile Banner -->
                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm p-6 sm:p-7 flex flex-col md:flex-row md:items-center justify-between gap-6">
                    <div class="flex items-center gap-4">
                        <div class="w-16 h-16 rounded-2xl bg-brand-dark text-white font-black text-2xl flex items-center justify-center shadow-md">
                            <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                        </div>
                        <div>
                            <div class="flex items-center gap-2.5">
                                <h1 class="text-2xl font-black text-gray-900"><?php echo htmlspecialchars($user['name']); ?></h1>
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold uppercase tracking-wider <?php echo $user['role'] === 'admin' ? 'bg-purple-100 text-purple-700' : ($user['role'] === 'staff' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-700'); ?>">
                                    <?php echo ucfirst($user['role']); ?>
                                </span>
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold uppercase tracking-wider <?php echo $user['status'] === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'; ?>">
                                    <?php echo ucfirst($user['status']); ?>
                                </span>
                            </div>
                            <div class="flex flex-wrap items-center gap-4 text-xs text-gray-500 mt-2">
                                <span class="flex items-center gap-1"><i data-lucide="mail" class="w-3.5 h-3.5 text-gray-400"></i> <?php echo htmlspecialchars($user['email']); ?></span>
                                <?php if ($user['phone']): ?>
                                    <span class="flex items-center gap-1"><i data-lucide="phone" class="w-3.5 h-3.5 text-gray-400"></i> <?php echo htmlspecialchars($user['phone']); ?></span>
                                <?php endif; ?>
                                <span>Member since <?php echo date('d M Y', strtotime($user['created_at'])); ?></span>
                            </div>
                            <?php if ($user['address']): ?>
                                <p class="text-xs text-gray-600 mt-2 flex items-center gap-1"><i data-lucide="map-pin" class="w-3.5 h-3.5 text-gray-400"></i> <?php echo htmlspecialchars($user['address']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Metrics Badges -->
                    <div class="grid grid-cols-3 gap-4 border-t md:border-t-0 md:border-l border-gray-100 pt-4 md:pt-0 md:pl-6 text-center">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Orders</p>
                            <p class="text-xl font-black text-gray-900 mt-0.5"><?php echo count($orders); ?></p>
                        </div>
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Repairs</p>
                            <p class="text-xl font-black text-gray-900 mt-0.5"><?php echo count($repairs); ?></p>
                        </div>
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Total Spent</p>
                            <p class="text-xl font-black text-brand-red mt-0.5">Rs. <?php echo number_format($totalSpent, 0); ?></p>
                        </div>
                    </div>
                </div>

                <!-- 2. Orders History Card -->
                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h2 class="text-xs font-bold uppercase tracking-wider text-gray-900 flex items-center gap-2">
                            <i data-lucide="shopping-bag" class="w-4 h-4 text-brand-red"></i> Order History (<?php echo count($orders); ?>)
                        </h2>
                    </div>
                    <?php if (empty($orders)): ?>
                        <p class="text-xs text-gray-400 p-6 text-center">No orders placed by this customer yet.</p>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse text-xs">
                                <thead>
                                    <tr class="bg-gray-50 border-b border-gray-200 text-[10px] font-bold uppercase tracking-wider text-gray-400">
                                        <th class="py-3 px-6">Order Number</th>
                                        <th class="py-3 px-4">Date</th>
                                        <th class="py-3 px-4">Items</th>
                                        <th class="py-3 px-4">Order Status</th>
                                        <th class="py-3 px-4">Payment</th>
                                        <th class="py-3 px-4 text-right">Amount</th>
                                        <th class="py-3 px-6 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($orders as $o): ?>
                                        <tr class="hover:bg-gray-50/80 transition">
                                            <td class="py-3 px-6 font-bold text-gray-900 font-mono">#<?php echo htmlspecialchars($o['order_number']); ?></td>
                                            <td class="py-3 px-4 text-gray-500"><?php echo date('d M Y', strtotime($o['created_at'])); ?></td>
                                            <td class="py-3 px-4"><?php echo (int)$o['item_count']; ?> item(s)</td>
                                            <td class="py-3 px-4"><span class="px-2 py-0.5 rounded text-[10px] font-bold bg-gray-100 text-gray-700"><?php echo ucfirst($o['order_status']); ?></span></td>
                                            <td class="py-3 px-4"><span class="px-2 py-0.5 rounded text-[10px] font-bold <?php echo $o['payment_status'] === 'paid' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'; ?>"><?php echo ucfirst($o['payment_status']); ?></span></td>
                                            <td class="py-3 px-4 text-right font-bold font-mono">Rs. <?php echo number_format((float)$o['total_amount'], 2); ?></td>
                                            <td class="py-3 px-6 text-right">
                                                <a href="<?php echo APP_URL; ?>/admin/orders/view.php?id=<?php echo (int)$o['id']; ?>" class="text-brand-red font-bold hover:underline">View</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 3. Repairs History Card -->
                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h2 class="text-xs font-bold uppercase tracking-wider text-gray-900 flex items-center gap-2">
                            <i data-lucide="wrench" class="w-4 h-4 text-brand-red"></i> Repair Records (<?php echo count($repairs); ?>)
                        </h2>
                    </div>
                    <?php if (empty($repairs)): ?>
                        <p class="text-xs text-gray-400 p-6 text-center">No repair jobs recorded for this customer.</p>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse text-xs">
                                <thead>
                                    <tr class="bg-gray-50 border-b border-gray-200 text-[10px] font-bold uppercase tracking-wider text-gray-400">
                                        <th class="py-3 px-6">Repair Code</th>
                                        <th class="py-3 px-4">Device Model</th>
                                        <th class="py-3 px-4">Date Booked</th>
                                        <th class="py-3 px-4">Status</th>
                                        <th class="py-3 px-4">Technician</th>
                                        <th class="py-3 px-4 text-right">Cost</th>
                                        <th class="py-3 px-6 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($repairs as $r): ?>
                                        <tr class="hover:bg-gray-50/80 transition">
                                            <td class="py-3 px-6 font-bold text-gray-900 font-mono"><?php echo htmlspecialchars($r['repair_code']); ?></td>
                                            <td class="py-3 px-4 font-semibold text-gray-800"><?php echo htmlspecialchars($r['device_model']); ?></td>
                                            <td class="py-3 px-4 text-gray-500"><?php echo date('d M Y', strtotime($r['created_at'])); ?></td>
                                            <td class="py-3 px-4"><span class="px-2 py-0.5 rounded text-[10px] font-bold bg-blue-100 text-blue-700"><?php echo ucfirst(str_replace('_', ' ', $r['repair_status'])); ?></span></td>
                                            <td class="py-3 px-4 text-gray-600"><?php echo htmlspecialchars($r['tech_name'] ?? 'Unassigned'); ?></td>
                                            <td class="py-3 px-4 text-right font-bold font-mono">Rs. <?php echo number_format((float)($r['final_cost'] ?: $r['estimated_cost']), 2); ?></td>
                                            <td class="py-3 px-6 text-right">
                                                <a href="<?php echo APP_URL; ?>/admin/repairs/view.php?id=<?php echo (int)$r['id']; ?>" class="text-brand-red font-bold hover:underline">Manage</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 4. Warranties Card -->
                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h2 class="text-xs font-bold uppercase tracking-wider text-gray-900 flex items-center gap-2">
                            <i data-lucide="shield-check" class="w-4 h-4 text-brand-red"></i> Warranty Certificates (<?php echo count($warranties); ?>)
                        </h2>
                    </div>
                    <?php if (empty($warranties)): ?>
                        <p class="text-xs text-gray-400 p-6 text-center">No active or expired warranty certificates registered.</p>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse text-xs">
                                <thead>
                                    <tr class="bg-gray-50 border-b border-gray-200 text-[10px] font-bold uppercase tracking-wider text-gray-400">
                                        <th class="py-3 px-6">Warranty Code</th>
                                        <th class="py-3 px-4">Item Covered</th>
                                        <th class="py-3 px-4">Valid From</th>
                                        <th class="py-3 px-4">Expires On</th>
                                        <th class="py-3 px-4">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($warranties as $w): ?>
                                        <tr class="hover:bg-gray-50/80 transition">
                                            <td class="py-3 px-6 font-bold text-gray-900 font-mono"><?php echo htmlspecialchars($w['warranty_code']); ?></td>
                                            <td class="py-3 px-4 font-semibold text-gray-800"><?php echo htmlspecialchars($w['product_name'] ?? ucfirst($w['warranty_type']) . ' Warranty'); ?></td>
                                            <td class="py-3 px-4 text-gray-500"><?php echo date('d M Y', strtotime($w['start_date'])); ?></td>
                                            <td class="py-3 px-4 text-gray-500"><?php echo date('d M Y', strtotime($w['end_date'])); ?></td>
                                            <td class="py-3 px-4">
                                                <span class="px-2 py-0.5 rounded text-[10px] font-bold <?php echo $w['status'] === 'active' ? 'bg-emerald-100 text-emerald-700' : ($w['status'] === 'claimed' ? 'bg-blue-100 text-blue-700' : 'bg-amber-100 text-amber-700'); ?>">
                                                    <?php echo ucfirst($w['status']); ?>
                                                </span>
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
