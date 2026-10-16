<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/repair_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo         = Database::getConnection();
$currentUser = getCurrentUser();
requireAuth(['staff', 'admin']);
$me = (int)$currentUser['id'];

$stats = ['unassigned' => 0, 'mine' => 0, 'in_progress' => 0, 'completed_month' => 0];
$queue = [];
$myJobs = [];

try {
    // 1. Fetch dashboard stats
    $st = $pdo->prepare("SELECT
        SUM(repair_status = 'pending' AND technician_id IS NULL)                     AS unassigned,
        SUM(repair_status IN ('assigned','in_progress') AND technician_id = ?)        AS mine,
        SUM(repair_status = 'in_progress')                                           AS in_progress,
        SUM(repair_status = 'completed' AND completed_at >= DATE_FORMAT(NOW(), '%Y-%m-01')) AS completed_month
        FROM repairs");
    $st->execute([$me]);
    $fetchedStats = $st->fetch(PDO::FETCH_ASSOC);
    if ($fetchedStats) {
        foreach ($fetchedStats as $k => $v) {
            $stats[$k] = (int)($v ?? 0);
        }
    }

    // 2. Fetch unassigned queue (up to 6)
    $queue = $pdo->query("SELECT r.id, r.repair_code, r.device_model, r.preferred_date, r.created_at, u.name AS customer_name
                          FROM repairs r 
                          JOIN users u ON u.id = r.user_id
                          WHERE r.repair_status = 'pending' AND r.technician_id IS NULL
                          ORDER BY r.preferred_date ASC, r.id ASC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);

    // 3. Fetch active assigned jobs (up to 8)
    $st = $pdo->prepare("SELECT r.id, r.repair_code, r.device_model, r.repair_status, r.preferred_date, u.name AS customer_name
                         FROM repairs r 
                         JOIN users u ON u.id = r.user_id
                         WHERE r.technician_id = ? AND r.repair_status IN ('assigned','in_progress')
                         ORDER BY r.preferred_date ASC, r.id ASC LIMIT 8");
    $st->execute([$me]);
    $myJobs = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Staff dashboard error: ' . $e->getMessage());
}

$today = date('Y-m-d');
$cards = [
    ['Unassigned',        $stats['unassigned'],       'inbox',          'bg-amber-50 text-amber-600',   '/staff/repairs.php?scope=unassigned&status=pending'],
    ['My Active Jobs',    $stats['mine'],             'wrench',         'bg-blue-50 text-blue-600',    '/staff/repairs.php?scope=mine'],
    ['In Progress',       $stats['in_progress'],     'loader',         'bg-purple-50 text-purple-600', '/staff/repairs.php?status=in_progress'],
    ['Completed (Month)', $stats['completed_month'], 'check-circle-2', 'bg-emerald-50 text-emerald-600', '/staff/repairs.php?status=completed'],
];

$pageTitle = 'Technician Dashboard';
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

        <!-- MAIN CONTENT CONTAINER -->
        <div class="flex-1 flex flex-col min-w-0">

            <!-- TOP BAR -->
            <header class="bg-white border-b border-gray-200 sticky top-0 z-20 flex items-center gap-4 px-6 h-16 flex-shrink-0">
                <button id="sidebar-open-btn" type="button" class="lg:hidden text-gray-500 hover:text-gray-900" aria-label="Open sidebar">
                    <i data-lucide="menu" class="w-5 h-5"></i>
                </button>

                <div class="flex-1 min-w-0 flex items-center gap-2 text-sm">
                    <span class="text-gray-400 font-medium hidden sm:inline">Staff Portal</span>
                    <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-gray-300 hidden sm:inline flex-shrink-0"></i>
                    <span class="font-bold text-gray-900">Dashboard</span>
                </div>

                <div class="flex items-center gap-3">
                    <a href="profile.php" class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center text-white text-sm font-bold flex-shrink-0">
                        <?php echo strtoupper(substr($currentUser['name'] ?? 'S', 0, 1)); ?>
                    </a>
                </div>
            </header>

            <!-- PAGE CONTENT -->
            <main class="flex-1 p-5 sm:p-7 overflow-auto">

                <!-- WELCOME TITLE -->
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                    <div>
                        <h1 class="text-2xl font-extrabold text-gray-900 tracking-tight">Technician Dashboard</h1>
                        <p class="text-sm text-gray-500 mt-0.5">Welcome back, <?php echo rp_e($currentUser['name']); ?>.</p>
                    </div>
                </div>

                <?php echo rp_flash_render(); ?>

                <!-- STATS METRIC CARDS -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                    <?php foreach ($cards as [$label, $val, $icon, $color, $href]): ?>
                        <a href="<?php echo APP_URL . $href; ?>" class="bg-white border border-gray-200 rounded-2xl shadow-sm p-5 hover:border-gray-300 transition group">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center <?php echo $color; ?>">
                                <i data-lucide="<?php echo $icon; ?>" class="w-5 h-5"></i>
                            </div>
                            <p class="mt-4 text-3xl font-black text-gray-900 tracking-tight"><?php echo number_format($val); ?></p>
                            <p class="text-xs font-bold uppercase tracking-wider text-gray-500 mt-1"><?php echo $label; ?></p>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- QUEUES GRID -->
                <div class="grid lg:grid-cols-2 gap-6">

                    <!-- UNASSIGNED QUEUE CARD -->
                    <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden flex flex-col">
                        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 bg-gray-50/50">
                            <div class="flex items-center gap-2">
                                <i data-lucide="inbox" class="w-4 h-4 text-amber-600"></i>
                                <h2 class="text-xs font-bold uppercase tracking-wider text-gray-700">Unassigned Queue</h2>
                            </div>
                            <a href="<?php echo APP_URL; ?>/staff/repairs.php?scope=unassigned" class="text-xs font-bold text-brand-red hover:underline">View All</a>
                        </div>

                        <div class="divide-y divide-gray-100 flex-1">
                            <?php if (!$queue): ?>
                                <div class="p-8 text-center text-sm text-gray-400">
                                    No pending unassigned repairs in queue. 🎉
                                </div>
                            <?php else: ?>
                                <?php foreach ($queue as $r):
                                    $late = $r['preferred_date'] < $today; ?>
                                    <a href="<?php echo APP_URL; ?>/staff/repair-detail.php?id=<?php echo (int)$r['id']; ?>"
                                        class="flex items-center justify-between gap-4 px-6 py-4 hover:bg-gray-50 transition">
                                        <div class="min-w-0">
                                            <p class="text-xs font-black text-brand-red font-mono tracking-wider"><?php echo rp_e($r['repair_code']); ?></p>
                                            <p class="text-xs sm:text-sm font-bold text-gray-900 truncate mt-0.5">
                                                <?php echo rp_e($r['device_model']); ?>
                                                <span class="font-normal text-gray-400">· <?php echo rp_e($r['customer_name']); ?></span>
                                            </p>
                                        </div>
                                        <div class="text-right flex-shrink-0">
                                            <p class="text-xs font-bold <?php echo $late ? 'text-red-600' : 'text-gray-500'; ?>">
                                                <?php echo $late ? 'Overdue · ' : ''; ?><?php echo rp_date($r['preferred_date']); ?>
                                            </p>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- MY ACTIVE JOBS CARD -->
                    <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden flex flex-col">
                        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 bg-gray-50/50">
                            <div class="flex items-center gap-2">
                                <i data-lucide="wrench" class="w-4 h-4 text-blue-600"></i>
                                <h2 class="text-xs font-bold uppercase tracking-wider text-gray-700">My Active Jobs</h2>
                            </div>
                            <a href="<?php echo APP_URL; ?>/staff/repairs.php?scope=mine" class="text-xs font-bold text-brand-red hover:underline">View All</a>
                        </div>

                        <div class="divide-y divide-gray-100 flex-1">
                            <?php if (!$myJobs): ?>
                                <div class="p-8 text-center text-sm text-gray-400">
                                    No active jobs assigned to you. Claim one from the queue.
                                </div>
                            <?php else: ?>
                                <?php foreach ($myJobs as $r):
                                    $late = $r['preferred_date'] < $today; ?>
                                    <a href="<?php echo APP_URL; ?>/staff/repair-detail.php?id=<?php echo (int)$r['id']; ?>"
                                        class="flex items-center justify-between gap-4 px-6 py-4 hover:bg-gray-50 transition">
                                        <div class="min-w-0">
                                            <p class="text-xs font-black text-brand-red font-mono tracking-wider"><?php echo rp_e($r['repair_code']); ?></p>
                                            <p class="text-xs sm:text-sm font-bold text-gray-900 truncate mt-0.5">
                                                <?php echo rp_e($r['device_model']); ?>
                                                <span class="font-normal text-gray-400">· <?php echo rp_e($r['customer_name']); ?></span>
                                            </p>
                                        </div>
                                        <div class="text-right flex-shrink-0 space-y-1">
                                            <?php echo rp_status_badge($r['repair_status']); ?>
                                            <p class="text-[11px] font-bold <?php echo $late ? 'text-red-600' : 'text-gray-400'; ?>">
                                                <?php echo rp_date($r['preferred_date']); ?>
                                            </p>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
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