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

// Overall Stats Query
$statsStmt = $pdo->query("
    SELECT 
        COUNT(*) AS total_repairs,
        SUM(CASE WHEN repair_status = 'pending' THEN 1 ELSE 0 END) AS count_pending,
        SUM(CASE WHEN repair_status = 'assigned' THEN 1 ELSE 0 END) AS count_assigned,
        SUM(CASE WHEN repair_status = 'in_progress' THEN 1 ELSE 0 END) AS count_in_progress,
        SUM(CASE WHEN repair_status = 'completed' THEN 1 ELSE 0 END) AS count_completed,
        SUM(CASE WHEN repair_status = 'cancelled' THEN 1 ELSE 0 END) AS count_cancelled,
        SUM(CASE WHEN repair_status = 'completed' THEN final_cost ELSE 0 END) AS total_revenue
    FROM repairs
");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Recent Completed Repairs
$recentStmt = $pdo->query("
    SELECT r.repair_code, r.device_model, r.final_cost, r.completed_at, u.name AS customer_name
    FROM repairs r
    JOIN users u ON u.id = r.user_id
    WHERE r.repair_status = 'completed'
    ORDER BY r.completed_at DESC
    LIMIT 10
");
$recentCompleted = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Repairs Report';
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
                    <a href="<?php echo APP_URL; ?>/admin/repairs/index.php" class="text-gray-500 hover:text-gray-900 font-medium">Repairs</a>
                    <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-gray-300 hidden sm:inline flex-shrink-0"></i>
                    <span class="font-bold text-gray-900">Reports</span>
                </div>
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center text-white text-sm font-bold flex-shrink-0">
                        <?php echo strtoupper(substr($currentUser['name'] ?? 'A', 0, 1)); ?>
                    </div>
                </div>
            </header>

            <main class="flex-1 p-5 sm:p-7 overflow-auto">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                    <div>
                        <h1 class="text-2xl font-extrabold text-gray-900 tracking-tight">Repairs Analytics &amp; Report</h1>
                        <p class="text-sm text-gray-500 mt-0.5">Overview of service status, technician performance, and total revenue.</p>
                    </div>
                    <div>
                        <a href="<?php echo APP_URL; ?>/admin/repairs/index.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-gray-200 bg-white text-gray-700 font-bold text-xs hover:bg-gray-50 transition shadow-sm">
                            <i data-lucide="arrow-left" class="w-4 h-4"></i> Back to Repairs
                        </a>
                    </div>
                </div>

                <!-- STATS CARDS GRID -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
                    <div class="bg-white border border-gray-200 rounded-2xl p-4 shadow-sm text-center">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Total Bookings</p>
                        <p class="text-2xl font-black text-gray-900 mt-1"><?php echo (int)($stats['total_repairs'] ?? 0); ?></p>
                    </div>
                    <div class="bg-amber-50/50 border border-amber-200 rounded-2xl p-4 shadow-sm text-center">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-amber-700">Received</p>
                        <p class="text-2xl font-black text-amber-700 mt-1"><?php echo (int)($stats['count_pending'] ?? 0); ?></p>
                    </div>
                    <div class="bg-blue-50/50 border border-blue-200 rounded-2xl p-4 shadow-sm text-center">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-blue-700">Assigned</p>
                        <p class="text-2xl font-black text-blue-700 mt-1"><?php echo (int)($stats['count_assigned'] ?? 0); ?></p>
                    </div>
                    <div class="bg-purple-50/50 border border-purple-200 rounded-2xl p-4 shadow-sm text-center">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-purple-700">In Progress</p>
                        <p class="text-2xl font-black text-purple-700 mt-1"><?php echo (int)($stats['count_in_progress'] ?? 0); ?></p>
                    </div>
                    <div class="bg-emerald-50/50 border border-emerald-200 rounded-2xl p-4 shadow-sm text-center">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-emerald-700">Completed</p>
                        <p class="text-2xl font-black text-emerald-700 mt-1"><?php echo (int)($stats['count_completed'] ?? 0); ?></p>
                    </div>
                    <div class="bg-rose-50/50 border border-rose-200 rounded-2xl p-4 shadow-sm text-center">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-rose-700">Cancelled</p>
                        <p class="text-2xl font-black text-rose-700 mt-1"><?php echo (int)($stats['count_cancelled'] ?? 0); ?></p>
                    </div>
                </div>

                <!-- REVENUE SUMMARY CARD -->
                <div class="bg-gray-900 text-white rounded-2xl p-6 shadow-sm border-b-4 border-brand-red mb-6 flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div>
                        <span class="text-[10px] font-bold uppercase tracking-wider text-brand-red">Financial Summary</span>
                        <h2 class="text-xl sm:text-2xl font-black uppercase tracking-tight mt-1">Total Completed Revenue</h2>
                    </div>
                    <div class="text-right">
                        <span class="text-2xl sm:text-3xl font-black text-emerald-400">
                            Rs. <?php echo number_format((float)($stats['total_revenue'] ?? 0), 2); ?>
                        </span>
                    </div>
                </div>

                <!-- RECENT COMPLETED REPAIRS TABLE -->
                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-200 bg-gray-50">
                        <h2 class="text-xs font-bold uppercase tracking-wider text-gray-700">Recent Completed Repairs</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-gray-50 border-b border-gray-200 text-[11px] font-bold text-gray-500 uppercase tracking-wider">
                                    <th class="px-5 py-3.5">Repair Code</th>
                                    <th class="px-4 py-3.5">Customer</th>
                                    <th class="px-4 py-3.5">Device</th>
                                    <th class="px-4 py-3.5">Completed Date</th>
                                    <th class="px-5 py-3.5 text-right">Revenue</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 text-xs sm:text-sm font-medium">
                                <?php if (empty($recentCompleted)): ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-12 text-center text-gray-400 text-sm">No completed repair revenue recorded yet.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recentCompleted as $rc): ?>
                                        <tr class="hover:bg-gray-50 transition">
                                            <td class="px-5 py-3.5 font-bold text-brand-red font-mono tracking-wider"><?php echo rp_e($rc['repair_code']); ?></td>
                                            <td class="px-4 py-3.5 font-bold text-gray-900"><?php echo rp_e($rc['customer_name']); ?></td>
                                            <td class="px-4 py-3.5 font-semibold text-gray-800"><?php echo rp_e($rc['device_model']); ?></td>
                                            <td class="px-4 py-3.5 text-xs text-gray-500"><?php echo date('d M Y, h:i A', strtotime($rc['completed_at'])); ?></td>
                                            <td class="px-5 py-3.5 text-right font-black text-emerald-600">Rs. <?php echo number_format((float)$rc['final_cost'], 2); ?></td>
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
    <script>
        if (typeof lucide !== 'undefined') lucide.createIcons();
    </script>
</body>

</html>