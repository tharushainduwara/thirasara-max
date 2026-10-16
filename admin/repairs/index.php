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

// Filters & Pagination
$statusFilter = trim($_GET['status'] ?? '');
$searchQuery  = trim($_GET['search'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$limit        = 15;
$offset       = ($page - 1) * $limit;

$whereClause = ['1=1'];
$params      = [];

if ($statusFilter !== '') {
    $whereClause[] = 'r.repair_status = ?';
    $params[]      = $statusFilter;
}

if ($searchQuery !== '') {
    $whereClause[] = '(r.repair_code LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR r.device_model LIKE ? OR r.serial_or_imei LIKE ?)';
    $searchTerm    = "%{$searchQuery}%";
    array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
}

$whereSql = implode(' AND ', $whereClause);

// Total count
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM repairs r 
    JOIN users u ON u.id = r.user_id 
    WHERE {$whereSql}
");
$countStmt->execute($params);
$totalRepairs = (int)$countStmt->fetchColumn();
$totalPages   = max(1, (int)ceil($totalRepairs / $limit));

// Fetch repairs list
$stmt = $pdo->prepare("
    SELECT r.*, u.name AS customer_name, u.email AS customer_email, u.phone AS customer_phone,
           tech.name AS technician_name
    FROM repairs r
    JOIN users u ON u.id = r.user_id
    LEFT JOIN users tech ON tech.id = r.technician_id
    WHERE {$whereSql}
    ORDER BY r.created_at DESC
    LIMIT {$limit} OFFSET {$offset}
");
$stmt->execute($params);
$repairs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Manage Repairs';
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
                    <span class="font-bold text-gray-900">Repair Management</span>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-xs text-gray-500 hidden sm:inline"><?php echo number_format($totalRepairs); ?> repairs</span>
                    <div class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center text-white text-sm font-bold flex-shrink-0">
                        <?php echo strtoupper(substr($currentUser['name'] ?? 'A', 0, 1)); ?>
                    </div>
                </div>
            </header>

            <main class="flex-1 p-5 sm:p-7 overflow-auto">
                <?php if (function_exists('displayFlash')): ?><div class="mb-6"><?php displayFlash(); ?></div><?php endif; ?>

                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                    <div>
                        <h1 class="text-2xl font-extrabold text-gray-900 tracking-tight">Repairs</h1>
                        <p class="text-sm text-gray-500 mt-0.5">View, assign, and update all customer repair bookings.</p>
                    </div>
                    <div>
                        <a href="<?php echo APP_URL; ?>/admin/reports/repairs-report.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white border border-gray-200 text-gray-700 font-bold text-xs hover:bg-gray-50 transition shadow-sm">
                            <i data-lucide="bar-chart-2" class="w-4 h-4 text-brand-red"></i> Repairs Report
                        </a>
                    </div>
                </div>

                <!-- FILTERS BAR -->
                <div class="bg-white border border-gray-200 rounded-2xl p-4 sm:p-5 shadow-sm mb-6">
                    <form method="GET" class="grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-4 gap-4 items-end">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1.5">Search</label>
                            <input type="text" name="search" value="<?php echo rp_e($searchQuery); ?>" placeholder="Code, Customer, Device..."
                                class="w-full h-10 px-3 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1.5">Status</label>
                            <select name="status" class="w-full h-10 px-3 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                                <option value="">All Statuses</option>
                                <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Received (Pending)</option>
                                <option value="assigned" <?php echo $statusFilter === 'assigned' ? 'selected' : ''; ?>>Technician Assigned</option>
                                <option value="in_progress" <?php echo $statusFilter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>

                        <div class="flex items-center gap-2">
                            <button type="submit" class="h-10 px-5 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-xs font-bold transition shadow-sm inline-flex items-center justify-center gap-1.5">
                                <i data-lucide="filter" class="w-3.5 h-3.5"></i> Filter
                            </button>
                            <?php if ($statusFilter !== '' || $searchQuery !== ''): ?>
                                <a href="index.php" class="h-10 px-4 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 text-xs font-bold transition inline-flex items-center justify-center">Clear</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- REPAIRS TABLE -->
                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-gray-50 border-b border-gray-200 text-[11px] font-bold text-gray-500 uppercase tracking-wider">
                                    <th class="px-5 py-3.5">Repair Code</th>
                                    <th class="px-4 py-3.5">Customer</th>
                                    <th class="px-4 py-3.5">Device</th>
                                    <th class="px-4 py-3.5">Status</th>
                                    <th class="px-4 py-3.5">Technician</th>
                                    <th class="px-4 py-3.5">Date Booked</th>
                                    <th class="px-5 py-3.5 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 text-xs sm:text-sm font-medium">
                                <?php if (empty($repairs)): ?>
                                    <tr>
                                        <td colspan="7" class="p-8 text-center text-gray-500 font-semibold">No repair bookings found matching your criteria.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($repairs as $r): ?>
                                        <?php
                                        $badgeClass = match ($r['repair_status']) {
                                            'pending'     => 'bg-amber-100 text-amber-700 border-amber-200',
                                            'assigned'    => 'bg-blue-100 text-blue-700 border-blue-200',
                                            'in_progress' => 'bg-purple-100 text-purple-700 border-purple-200',
                                            'completed'   => 'bg-emerald-100 text-emerald-700 border-emerald-200',
                                            'cancelled'   => 'bg-rose-100 text-rose-700 border-rose-200',
                                            default       => 'bg-gray-100 text-gray-700 border-gray-200'
                                        };
                                        ?>
                                        <tr class="hover:bg-gray-50 transition">
                                            <td class="px-5 py-3.5 font-bold text-brand-red font-mono tracking-wider">
                                                <?php echo rp_e($r['repair_code']); ?>
                                            </td>
                                            <td class="px-4 py-3.5">
                                                <p class="font-bold text-gray-900"><?php echo rp_e($r['customer_name']); ?></p>
                                                <p class="text-[11px] text-gray-400"><?php echo rp_e($r['customer_phone'] ?: $r['customer_email']); ?></p>
                                            </td>
                                            <td class="px-4 py-3.5 font-semibold text-gray-800">
                                                <?php echo rp_e($r['device_model']); ?>
                                            </td>
                                            <td class="px-4 py-3.5">
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold border <?php echo $badgeClass; ?>">
                                                    <?php echo rp_e(ucfirst(str_replace('_', ' ', $r['repair_status']))); ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3.5 text-xs font-semibold text-gray-700">
                                                <?php echo rp_e($r['technician_name'] ?: 'Unassigned'); ?>
                                            </td>
                                            <td class="px-4 py-3.5 text-xs text-gray-500">
                                                <?php echo date('d M Y, h:i A', strtotime($r['created_at'])); ?>
                                            </td>
                                            <td class="px-5 py-3.5 text-right">
                                                <a href="view.php?id=<?php echo (int)$r['id']; ?>" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-gray-100 hover:bg-brand-red hover:text-white text-gray-700 text-xs font-semibold transition">
                                                    <i data-lucide="eye" class="w-3.5 h-3.5"></i> View
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- PAGINATION -->
                    <?php if ($totalPages > 1): ?>
                        <div class="p-4 border-t border-gray-200 flex items-center justify-between bg-gray-50">
                            <span class="text-xs text-gray-600 font-semibold">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                            <div class="flex items-center gap-2">
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?php echo $page - 1; ?>&status=<?php echo urlencode($statusFilter); ?>&search=<?php echo urlencode($searchQuery); ?>" class="px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-xs font-bold text-gray-700 hover:bg-gray-100">Previous</a>
                                <?php endif; ?>
                                <?php if ($page < $totalPages): ?>
                                    <a href="?page=<?php echo $page + 1; ?>&status=<?php echo urlencode($statusFilter); ?>&search=<?php echo urlencode($searchQuery); ?>" class="px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-xs font-bold text-gray-700 hover:bg-gray-100">Next</a>
                                <?php endif; ?>
                            </div>
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