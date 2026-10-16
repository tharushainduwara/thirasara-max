<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/repair_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo         = Database::getConnection();
$currentUser = getCurrentUser();
requireAuth(['admin']); // Strict admin-only access
$uid = (int)$currentUser['id'];

/* Handle Status Toggle Action */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_status') {
    if (!rp_csrf_check($_POST['csrf'] ?? '')) {
        rp_flash_set('error', 'Session expired. Please try again.');
    } else {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $newStatus    = $_POST['status'] === 'active' ? 'inactive' : 'active';

        // Prevent admin from disabling their own account
        if ($targetUserId === $uid) {
            rp_flash_set('error', 'You cannot deactivate your own account.');
        } else {
            try {
                $st = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
                $st->execute([$newStatus, $targetUserId]);
                if ($st->rowCount()) {
                    rp_flash_set('success', "User account status updated to {$newStatus}.");
                } else {
                    rp_flash_set('error', 'Unable to update user status.');
                }
            } catch (PDOException $e) {
                error_log('Admin user status toggle: ' . $e->getMessage());
                rp_flash_set('error', 'Database error while updating status.');
            }
        }
    }
    header('Location: ' . APP_URL . '/admin/users/index.php?' . http_build_query($_GET));
    exit;
}

/* Filters, Search & Pagination */
$role    = $_GET['role'] ?? 'all';
$search  = trim($_GET['search'] ?? '');
$perPage = 10;
$page    = max(1, (int)($_GET['page'] ?? 1));

$roles = [
    'all'      => 'All Users',
    'customer' => 'Customers',
    'staff'    => 'Technicians / Staff',
    'admin'    => 'Admins'
];

$whereClauses = ["1=1"];
$params = [];

if (array_key_exists($role, $roles) && $role !== 'all') {
    $whereClauses[] = "role = ?";
    $params[] = $role;
}

if ($search !== '') {
    $whereClauses[] = "(name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereSql = implode(' AND ', $whereClauses);

$users = [];
$total = 0;

try {
    // Count total users
    $c = $pdo->prepare("SELECT COUNT(*) FROM users WHERE {$whereSql}");
    $c->execute($params);
    $total = (int)$c->fetchColumn();

    $pages = max(1, (int)ceil($total / $perPage));
    $page  = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    // Fetch users list
    $st = $pdo->prepare("SELECT id, name, email, phone, role, status, created_at FROM users WHERE {$whereSql} ORDER BY created_at DESC LIMIT ? OFFSET ?");

    // Bind dynamic criteria params
    $bindIndex = 1;
    foreach ($params as $param) {
        $st->bindValue($bindIndex++, $param);
    }
    $st->bindValue($bindIndex++, $perPage, PDO::PARAM_INT);
    $st->bindValue($bindIndex++, $offset, PDO::PARAM_INT);

    $st->execute();
    $users = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Admin list users: ' . $e->getMessage());
    $pages = 1;
}

$pageTitle = 'Manage Users';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> | <?php echo APP_NAME; ?> Admin</title>

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

        <!-- SIDEBAR -->
        <?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>

        <!-- MAIN CONTENT CONTAINER -->
        <div class="flex-1 flex flex-col min-w-0">

            <!-- TOP BAR -->
            <header class="bg-white border-b border-gray-200 sticky top-0 z-20 flex items-center gap-4 px-6 h-16 flex-shrink-0">
                <button id="sidebar-open-btn" type="button" class="lg:hidden text-gray-500 hover:text-gray-900" aria-label="Open sidebar">
                    <i data-lucide="menu" class="w-5 h-5"></i>
                </button>

                <!-- Breadcrumbs -->
                <div class="flex-1 min-w-0 flex items-center gap-2 text-sm">
                    <span class="text-gray-400 font-medium hidden sm:inline">Admin</span>
                    <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-gray-300 hidden sm:inline flex-shrink-0"></i>
                    <span class="font-bold text-gray-900">Users & Staff</span>
                </div>

                <!-- Admin Profile Avatar -->
                <div class="flex items-center gap-3">
                    <span class="text-xs text-gray-500 hidden sm:inline"><?php echo number_format($total); ?> total users</span>
                    <div class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center text-white text-sm font-bold flex-shrink-0">
                        <?php echo strtoupper(substr($currentUser['name'] ?? 'A', 0, 1)); ?>
                    </div>
                </div>
            </header>

            <!-- PAGE BODY -->
            <main class="flex-1 p-5 sm:p-7 overflow-auto">

                <!-- Page Header -->
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                    <div>
                        <h1 class="text-2xl font-extrabold text-gray-900 tracking-tight">Users &amp; Staff Management</h1>
                        <p class="text-sm text-gray-500 mt-0.5">Manage customer accounts, technician permissions, and staff credentials.</p>
                    </div>
                    <div>
                        <a href="<?php echo APP_URL; ?>/admin/users/add-staff.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-brand-red text-white text-xs font-bold uppercase tracking-wider shadow-sm transition hover:bg-brand-redHover">
                            <i data-lucide="user-plus" class="w-4 h-4"></i> Add Staff / Technician
                        </a>
                    </div>
                </div>

                <?php echo rp_flash_render(); ?>

                <!-- Search and Filter Bar -->
                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm p-4 mb-6">
                    <form method="get" class="flex flex-col md:flex-row gap-4 items-center justify-between">

                        <!-- Role Filter Tabs -->
                        <div class="flex flex-wrap gap-1.5 w-full md:w-auto">
                            <?php foreach ($roles as $key => $label): ?>
                                <a href="?role=<?php echo $key; ?>&search=<?php echo urlencode($search); ?>"
                                    class="px-3 py-1.5 rounded-xl text-xs font-semibold transition <?php echo $key === $role ? 'bg-brand-dark text-white shadow-sm' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                                    <?php echo $label; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <!-- Search Field -->
                        <div class="flex items-center gap-2 w-full md:w-80">
                            <input type="hidden" name="role" value="<?php echo rp_e($role); ?>">
                            <div class="relative w-full">
                                <input type="text" name="search" value="<?php echo rp_e($search); ?>" placeholder="Search name, email, phone..."
                                    class="w-full h-10 rounded-xl border border-gray-200 bg-gray-50 pl-9 pr-3 text-xs sm:text-sm font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                                <i data-lucide="search" class="w-4 h-4 text-gray-400 absolute left-3 top-3"></i>
                            </div>
                            <button type="submit" class="h-10 px-4 bg-gray-900 hover:bg-black text-white rounded-xl text-xs font-bold transition">Filter</button>
                        </div>
                    </form>
                </div>

                <!-- Users Data Table -->
                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden mb-6">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-gray-50 border-b border-gray-200 text-[11px] font-bold uppercase tracking-wider text-gray-500">
                                    <th class="py-3.5 px-5">User / Contact</th>
                                    <th class="py-3.5 px-4">Role</th>
                                    <th class="py-3.5 px-4">Status</th>
                                    <th class="py-3.5 px-4">Registered Date</th>
                                    <th class="py-3.5 px-5 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 text-xs sm:text-sm font-medium">
                                <?php if (empty($users)): ?>
                                    <tr>
                                        <td colspan="5" class="py-12 text-center text-gray-400 text-sm">
                                            <div class="w-12 h-12 mx-auto rounded-2xl bg-gray-100 flex items-center justify-center mb-3">
                                                <i data-lucide="users" class="w-6 h-6 text-gray-400"></i>
                                            </div>
                                            No user accounts match your search or filter settings.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($users as $u): ?>
                                        <tr class="hover:bg-gray-50/80 transition">
                                            <!-- User info -->
                                            <td class="py-3.5 px-5">
                                                <div class="font-bold text-gray-900"><?php echo rp_e($u['name']); ?></div>
                                                <div class="text-[11px] text-gray-400 flex items-center gap-2 mt-0.5">
                                                    <span><?php echo rp_e($u['email']); ?></span>
                                                    <?php if (!empty($u['phone'])): ?>
                                                        <span>•</span>
                                                        <span><?php echo rp_e($u['phone']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>

                                            <!-- Role Badge -->
                                            <td class="py-3.5 px-4">
                                                <?php if ($u['role'] === 'admin'): ?>
                                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold bg-purple-50 text-purple-700 border border-purple-200">Admin</span>
                                                <?php elseif ($u['role'] === 'staff'): ?>
                                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold bg-blue-50 text-blue-700 border border-blue-200">Technician / Staff</span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold bg-gray-100 text-gray-700 border border-gray-200">Customer</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Status Badge -->
                                            <td class="py-3.5 px-4">
                                                <?php if (($u['status'] ?? 'active') === 'active'): ?>
                                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 mr-1.5"></span> Active
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-rose-500 mr-1.5"></span> Inactive
                                                    </span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Registered Date -->
                                            <td class="py-3.5 px-4 text-xs text-gray-500">
                                                <?php echo rp_date($u['created_at']); ?>
                                            </td>

                                            <!-- Actions -->
                                            <td class="py-3.5 px-5 text-right">
                                                <?php if ($u['id'] !== $uid): ?>
                                                    <form method="post" class="inline-block" onsubmit="return confirm('Change status for this user?');">
                                                        <?php echo rp_csrf_field(); ?>
                                                        <input type="hidden" name="action" value="toggle_status">
                                                        <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                                        <input type="hidden" name="status" value="<?php echo rp_e($u['status'] ?? 'active'); ?>">
                                                        <button type="submit" class="px-3 py-1.5 rounded-xl bg-gray-100 hover:bg-brand-red hover:text-white text-gray-700 text-xs font-semibold transition">
                                                            <?php echo ($u['status'] ?? 'active') === 'active' ? 'Deactivate' : 'Activate'; ?>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="text-xs text-gray-400 italic">Current Admin</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php echo rp_pagination($page, $pages, ['role' => $role, 'search' => $search]); ?>

            </main>
        </div>
    </div>

    <script>
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    </script>
</body>

</html>