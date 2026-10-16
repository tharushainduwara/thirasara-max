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

$statuses = rp_status_meta();
$status   = $_GET['status'] ?? '';
$scope    = $_GET['scope'] ?? 'all';
$q        = trim($_GET['q'] ?? '');

if ($status !== '' && !isset($statuses[$status])) {
    $status = '';
}
if (!in_array($scope, ['all', 'mine', 'unassigned'], true)) {
    $scope = 'all';
}

$where  = [];
$params = [];

if ($status !== '') {
    $where[]  = 'r.repair_status = ?';
    $params[] = $status;
}
if ($scope === 'mine') {
    $where[]  = 'r.technician_id = ?';
    $params[] = $me;
}
if ($scope === 'unassigned') {
    $where[] = 'r.technician_id IS NULL';
}
if ($q !== '') {
    $where[] = '(r.repair_code LIKE ? OR r.device_model LIKE ? OR u.name LIKE ? OR u.phone LIKE ?)';
    $like    = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$perPage = 15;
$page    = max(1, (int)($_GET['page'] ?? 1));
$rows    = [];
$total   = 0;
$pages   = 1;

try {
    $c = $pdo->prepare("SELECT COUNT(*) FROM repairs r JOIN users u ON u.id = r.user_id $whereSql");
    $c->execute($params);
    $total = (int)$c->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    $page  = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    $st = $pdo->prepare("SELECT r.id, r.repair_code, r.device_model, r.repair_status, r.preferred_date, r.created_at,
                                u.name AS customer_name, u.phone AS customer_phone, t.name AS tech_name
                         FROM repairs r
                         JOIN users u ON u.id = r.user_id
                         LEFT JOIN users t ON t.id = r.technician_id
                         $whereSql
                         ORDER BY FIELD(r.repair_status,'pending','assigned','in_progress','completed','cancelled'),
                                  r.preferred_date ASC, r.id DESC
                         LIMIT ? OFFSET ?");
    $i = 1;
    foreach ($params as $p) {
        $st->bindValue($i++, $p, is_int($p) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $st->bindValue($i++, $perPage, PDO::PARAM_INT);
    $st->bindValue($i, $offset, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Staff repairs list: ' . $e->getMessage());
}

$today     = date('Y-m-d');
$pageTitle = 'All Repairs';
$inputCls  = 'px-3.5 py-2 rounded-xl border border-gray-200 bg-gray-50 text-xs sm:text-sm font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition';
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
                    <span class="font-bold text-gray-900">Repairs Queue</span>
                </div>

                <div class="flex items-center gap-3">
                    <span class="text-xs text-gray-500 hidden sm:inline"><?php echo number_format($total); ?> total repairs</span>
                    <a href="profile.php" class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center text-white text-sm font-bold flex-shrink-0">
                        <?php echo strtoupper(substr($currentUser['name'] ?? 'S', 0, 1)); ?>
                    </a>
                </div>
            </header>

            <!-- PAGE BODY -->
            <main class="flex-1 p-5 sm:p-7 overflow-auto">

                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                    <div>
                        <h1 class="text-2xl font-extrabold text-gray-900 tracking-tight">Repairs Queue</h1>
                        <p class="text-sm text-gray-500 mt-0.5">View, diagnose, and update customer repair requests.</p>
                    </div>
                </div>

                <?php echo rp_flash_render(); ?>

                <!-- FILTERS BAR -->
                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm p-4 mb-6">
                    <form method="get" class="flex flex-col md:flex-row gap-3 items-center justify-between">
                        <div class="relative flex-1 w-full">
                            <input name="q" value="<?php echo rp_e($q); ?>" placeholder="Search code, device, customer, phone…" class="<?php echo $inputCls; ?> w-full pl-9">
                            <i data-lucide="search" class="w-4 h-4 text-gray-400 absolute left-3 top-2.5"></i>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 w-full md:w-auto">
                            <select name="status" class="<?php echo $inputCls; ?>">
                                <option value="">All statuses</option>
                                <?php foreach ($statuses as $k => [$label]): ?>
                                    <option value="<?php echo $k; ?>" <?php echo $status === $k ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>

                            <select name="scope" class="<?php echo $inputCls; ?>">
                                <option value="all" <?php echo $scope === 'all' ? 'selected' : ''; ?>>Everyone's</option>
                                <option value="mine" <?php echo $scope === 'mine' ? 'selected' : ''; ?>>Assigned to me</option>
                                <option value="unassigned" <?php echo $scope === 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                            </select>

                            <button type="submit" class="h-10 px-5 rounded-xl bg-gray-900 hover:bg-black text-white text-xs font-bold uppercase tracking-wider transition inline-flex items-center gap-1.5">
                                <i data-lucide="filter" class="w-3.5 h-3.5"></i> Filter
                            </button>
                        </div>
                    </form>
                </div>

                <!-- REPAIRS TABLE -->
                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden mb-6">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-gray-50 border-b border-gray-200 text-[11px] font-bold text-gray-500 uppercase tracking-wider">
                                    <th class="px-5 py-3.5">Code</th>
                                    <th class="px-4 py-3.5">Customer</th>
                                    <th class="px-4 py-3.5">Device</th>
                                    <th class="px-4 py-3.5">Status</th>
                                    <th class="px-4 py-3.5">Technician</th>
                                    <th class="px-4 py-3.5">Preferred Date</th>
                                    <th class="px-5 py-3.5 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 text-xs sm:text-sm font-medium">
                                <?php if (!$rows): ?>
                                    <tr>
                                        <td colspan="7" class="px-5 py-12 text-center text-gray-400 text-sm">
                                            No repair records match these filter settings.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($rows as $r):
                                        $late = rp_is_open($r['repair_status']) && $r['preferred_date'] && $r['preferred_date'] < $today; ?>
                                        <tr class="hover:bg-gray-50/80 transition">
                                            <td class="px-5 py-3.5 font-bold tracking-wider text-brand-red font-mono whitespace-nowrap">
                                                <?php echo rp_e($r['repair_code']); ?>
                                            </td>
                                            <td class="px-4 py-3.5">
                                                <p class="font-bold text-gray-900"><?php echo rp_e($r['customer_name']); ?></p>
                                                <p class="text-[11px] text-gray-400"><?php echo rp_e($r['customer_phone'] ?: 'N/A'); ?></p>
                                            </td>
                                            <td class="px-4 py-3.5 font-semibold text-gray-800">
                                                <?php echo rp_e($r['device_model']); ?>
                                            </td>
                                            <td class="px-4 py-3.5">
                                                <?php echo rp_status_badge($r['repair_status']); ?>
                                            </td>
                                            <td class="px-4 py-3.5 text-xs text-gray-700">
                                                <?php echo $r['tech_name'] ? rp_e($r['tech_name']) : '<span class="text-gray-400 font-normal">Unassigned</span>'; ?>
                                            </td>
                                            <td class="px-4 py-3.5 whitespace-nowrap text-xs <?php echo $late ? 'text-red-600 font-bold' : 'text-gray-500'; ?>">
                                                <?php echo rp_date($r['preferred_date']); ?><?php echo $late ? ' · overdue' : ''; ?>
                                            </td>
                                            <td class="px-5 py-3.5 text-right">
                                                <a href="<?php echo APP_URL; ?>/staff/repair-detail.php?id=<?php echo (int)$r['id']; ?>"
                                                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-gray-100 hover:bg-brand-red hover:text-white text-gray-700 text-xs font-semibold transition">
                                                    Open <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php echo rp_pagination($page, $pages, ['q' => $q, 'status' => $status, 'scope' => $scope]); ?>

            </main>
        </div>
    </div>

    <script>
        if (typeof lucide !== 'undefined') lucide.createIcons();
    </script>
</body>

</html>