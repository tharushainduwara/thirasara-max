<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/repair_helpers.php';
require_once __DIR__ . '/../../includes/pdf.php';

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

// CSV Export Handler
if (($_GET['export'] ?? '') === 'csv') {
    $allRepairsStmt = $pdo->query("
        SELECT r.repair_code, r.device_model, r.serial_or_imei, r.repair_status,
               r.estimated_cost, r.final_cost, r.is_warranty_covered,
               r.created_at, r.completed_at,
               u.name AS customer_name, u.phone AS customer_phone,
               tech.name AS technician_name
        FROM repairs r
        JOIN users u ON u.id = r.user_id
        LEFT JOIN users tech ON tech.id = r.technician_id
        ORDER BY r.created_at DESC
    ");
    $repairsList = $allRepairsStmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="repairs_report_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Repair Code',
        'Customer Name',
        'Phone',
        'Device Model',
        'Serial / IMEI',
        'Status',
        'Technician',
        'Warranty Covered',
        'Estimated Cost (LKR)',
        'Final Cost (LKR)',
        'Booked Date',
        'Completed Date'
    ]);

    foreach ($repairsList as $row) {
        fputcsv($out, [
            $row['repair_code'],
            $row['customer_name'],
            $row['customer_phone'] ?? '',
            $row['device_model'],
            $row['serial_or_imei'] ?? '',
            ucfirst(str_replace('_', ' ', $row['repair_status'])),
            $row['technician_name'] ?? 'Unassigned',
            $row['is_warranty_covered'] ? 'Yes' : 'No',
            number_format((float)$row['estimated_cost'], 2, '.', ''),
            number_format((float)$row['final_cost'], 2, '.', ''),
            $row['created_at'],
            $row['completed_at'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}
if (($_GET['export'] ?? '') === 'pdf') {
    $pdfHtml = "<!DOCTYPE html><html><head><meta charset='utf-8'><style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1f2937; margin: 20px; }
    .header { border-bottom: 2px solid #e52b34; padding-bottom: 10px; margin-bottom: 20px; }
    .header h1 { font-size: 20px; color: #e52b34; margin: 0; }
    table.kpi { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
    table.kpi td { padding: 10px; background: #f9fafb; border: 1px solid #e5e7eb; text-align: center; font-size: 10px; }
    .val { font-size: 16px; font-weight: bold; color: #111827; }
    table.data { width: 100%; border-collapse: collapse; margin-top: 15px; }
    table.data th { background: #111827; color: #fff; padding: 7px 8px; font-size: 9px; text-align: left; }
    table.data td { padding: 7px 8px; border-bottom: 1px solid #e5e7eb; font-size: 10px; }
    .text-right { text-align: right; }
    </style></head><body>
    <div class='header'>
        <h1>THIRASARA MAX MOBILE - REPAIRS & SERVICE REPORT</h1>
        <div style='font-size: 10px; color: #6b7280; margin-top: 4px;'>Generated: " . date('d M Y, h:i A') . "</div>
    </div>
    <table class='kpi'><tr>
        <td><div>Total Bookings</div><div class='val'>" . (int)($stats['total_repairs'] ?? 0) . "</div></td>
        <td><div>Completed Jobs</div><div class='val'>" . (int)($stats['count_completed'] ?? 0) . "</div></td>
        <td><div>Active Repairs</div><div class='val'>" . ((int)($stats['count_pending'] ?? 0) + (int)($stats['count_assigned'] ?? 0) + (int)($stats['count_in_progress'] ?? 0)) . "</div></td>
        <td><div>Service Revenue</div><div class='val'>Rs. " . number_format((float)($stats['total_revenue'] ?? 0), 2) . "</div></td>
    </tr></table>
    <h3 style='font-size: 12px; margin: 20px 0 5px;'>Recent Completed Repairs</h3>
    <table class='data'><thead><tr>
        <th>Repair Code</th><th>Customer</th><th>Device</th><th>Completed Date</th><th class='text-right'>Cost</th>
    </tr></thead><tbody>";
    foreach ($recentCompleted as $rc) {
        $pdfHtml .= "<tr>
            <td><strong>" . htmlspecialchars($rc['repair_code']) . "</strong></td>
            <td>" . htmlspecialchars($rc['customer_name']) . "</td>
            <td>" . htmlspecialchars($rc['device_model']) . "</td>
            <td>" . date('d M Y, h:i A', strtotime($rc['completed_at'])) . "</td>
            <td class='text-right font-bold'>Rs. " . number_format((float)$rc['final_cost'], 2) . "</td>
        </tr>";
    }
    $pdfHtml .= "</tbody></table></body></html>";
    streamPdf($pdfHtml, 'Repairs_Report_' . date('Ymd') . '.pdf', false, 'A4', 'portrait');
    exit;
}

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
                        <a href="<?php echo APP_URL; ?>/admin/reports/repairs-report.php?export=pdf" target="_blank" class="inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-xl bg-gray-900 text-white font-bold text-xs hover:bg-black transition shadow-sm mr-2">
                            <i data-lucide="file-text" class="w-4 h-4"></i> Export PDF
                        </a>
                        <a href="<?php echo APP_URL; ?>/admin/reports/repairs-report.php?export=csv" class="inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-xl border border-gray-200 bg-white text-gray-700 font-bold text-xs hover:bg-gray-50 transition shadow-sm mr-2">
                            <i data-lucide="download" class="w-4 h-4"></i> Export CSV
                        </a>
                        <a href="<?php echo APP_URL; ?>/admin/reports/index.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-gray-200 bg-white text-gray-700 font-bold text-xs hover:bg-gray-50 transition shadow-sm">
                            <i data-lucide="arrow-left" class="w-4 h-4"></i> Reports Hub
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