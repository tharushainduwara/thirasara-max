<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/repair_helpers.php';

requireAuth(['admin']);

$currentUser = getCurrentUser();
$adminId = (int)$currentUser['id'];
$pdo = Database::getConnection();

// Handle Actions (Mark Single Read, Mark All Read)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rp_csrf_check($_POST['csrf'] ?? '')) {
        setFlash('error', 'Session expired. Please try again.');
    } else {
        $action = trim($_POST['action'] ?? '');
        if ($action === 'mark_read') {
            $notifId = (int)($_POST['notification_id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND (user_id IS NULL OR user_id = ?)");
            $stmt->execute([$notifId, $adminId]);
            setFlash('success', 'Notification marked as read.');
        } elseif ($action === 'mark_all_read') {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id IS NULL OR user_id = ?");
            $stmt->execute([$adminId]);
            setFlash('success', 'All administrator notifications marked as read.');
        }
    }
    header('Location: ' . APP_URL . '/admin/notifications.php');
    exit;
}

$typeFilter = trim($_GET['type'] ?? 'all');
$where = ["(user_id IS NULL OR user_id = ?)"];
$params = [$adminId];

if ($typeFilter !== 'all' && in_array($typeFilter, ['low_stock', 'general', 'repair_status', 'order_confirmation'], true)) {
    $where[] = "type = ?";
    $params[] = $typeFilter;
}

$whereSql = implode(' AND ', $where);

// Fetch notifications
$stmt = $pdo->prepare("
    SELECT * 
    FROM notifications 
    WHERE {$whereSql}
    ORDER BY created_at DESC 
    LIMIT 60
");
$stmt->execute($params);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count unread
$unreadCountStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE (user_id IS NULL OR user_id = ?) AND is_read = 0");
$unreadCountStmt->execute([$adminId]);
$unreadCount = (int)$unreadCountStmt->fetchColumn();

$pageTitle = 'System Notifications & Alerts';
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
        <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

        <div class="flex-1 flex flex-col min-w-0">
            <!-- TOP BAR -->
            <header class="bg-white border-b border-gray-200 sticky top-0 z-20 flex items-center gap-4 px-6 h-16 flex-shrink-0">
                <button id="sidebar-open-btn" type="button" class="lg:hidden text-gray-500 hover:text-gray-900" aria-label="Open sidebar">
                    <i data-lucide="menu" class="w-5 h-5"></i>
                </button>
                <div class="flex-1 min-w-0 flex items-center gap-2 text-sm">
                    <span class="text-gray-400 font-medium hidden sm:inline">Admin</span>
                    <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-gray-300 hidden sm:inline flex-shrink-0"></i>
                    <span class="font-bold text-gray-900 truncate">System Alerts &amp; Notifications</span>
                </div>
                <div class="flex items-center gap-3">
                    <?php if ($unreadCount > 0): ?>
                        <form method="POST">
                            <?php echo rp_csrf_field(); ?>
                            <input type="hidden" name="action" value="mark_all_read">
                            <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-gray-100 hover:bg-gray-200 text-xs font-semibold text-gray-700 transition">
                                <i data-lucide="check-check" class="w-3.5 h-3.5 text-brand-red"></i> Mark All as Read
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </header>

            <main class="flex-1 p-5 sm:p-7 max-w-5xl mx-auto w-full space-y-6">
                <?php if (function_exists('displayFlash')): ?><div class="mb-6"><?php displayFlash(); ?></div><?php endif; ?>

                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h1 class="text-2xl font-extrabold text-gray-900 tracking-tight">System Alerts Center</h1>
                        <p class="text-sm text-gray-500 mt-0.5">Critical low stock alerts, stock depletion notices, and automated system signals.</p>
                    </div>

                    <!-- Filter pills -->
                    <div class="flex flex-wrap gap-1.5">
                        <a href="?type=all" class="px-3 py-1.5 rounded-xl text-xs font-bold transition <?php echo $typeFilter === 'all' ? 'bg-brand-dark text-white' : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50'; ?>">
                            All Alerts
                        </a>
                        <a href="?type=low_stock" class="px-3 py-1.5 rounded-xl text-xs font-bold transition <?php echo $typeFilter === 'low_stock' ? 'bg-brand-red text-white' : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50'; ?>">
                            Low Stock
                        </a>
                        <a href="?type=general" class="px-3 py-1.5 rounded-xl text-xs font-bold transition <?php echo $typeFilter === 'general' ? 'bg-brand-dark text-white' : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50'; ?>">
                            General
                        </a>
                    </div>
                </div>

                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h2 class="text-xs font-bold uppercase tracking-wider text-gray-700 flex items-center gap-2">
                            <i data-lucide="bell" class="w-4 h-4 text-brand-red"></i> Notifications Log (<?php echo count($notifications); ?>)
                        </h2>
                        <span class="text-xs font-bold <?php echo $unreadCount > 0 ? 'text-brand-red' : 'text-gray-400'; ?>">
                            <?php echo $unreadCount > 0 ? "{$unreadCount} unread alert(s)" : 'All alerts reviewed'; ?>
                        </span>
                    </div>

                    <?php if (empty($notifications)): ?>
                        <div class="p-12 text-center">
                            <div class="w-12 h-12 rounded-full bg-gray-50 text-gray-400 flex items-center justify-center mx-auto mb-3">
                                <i data-lucide="bell-off" class="w-6 h-6"></i>
                            </div>
                            <h3 class="text-sm font-bold text-gray-800">No active alerts</h3>
                            <p class="text-xs text-gray-500 mt-1">There are currently no notifications matching this criteria.</p>
                        </div>
                    <?php else: ?>
                        <div class="divide-y divide-gray-100">
                            <?php foreach ($notifications as $n): 
                                $isUnread = (int)$n['is_read'] === 0;
                                $isStock = $n['type'] === 'low_stock';
                                $icon = $isStock ? 'alert-triangle' : 'info';
                                $iconCls = $isStock ? 'bg-amber-100 text-amber-700' : 'bg-blue-100 text-blue-700';
                            ?>
                                <div class="p-5 flex items-start gap-4 transition hover:bg-gray-50/80 <?php echo $isUnread ? 'bg-amber-50/20' : ''; ?>">
                                    <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 <?php echo $iconCls; ?>">
                                        <i data-lucide="<?php echo $icon; ?>" class="w-5 h-5"></i>
                                    </div>

                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center justify-between gap-2">
                                            <h3 class="text-sm font-bold text-gray-900 flex items-center gap-2">
                                                <?php echo htmlspecialchars($n['title']); ?>
                                                <?php if ($isUnread): ?>
                                                    <span class="w-2 h-2 rounded-full bg-brand-red"></span>
                                                <?php endif; ?>
                                            </h3>
                                            <span class="text-[11px] text-gray-400 flex-shrink-0">
                                                <?php echo date('d M Y, h:i A', strtotime($n['created_at'])); ?>
                                            </span>
                                        </div>

                                        <p class="text-xs text-gray-600 mt-1 leading-relaxed">
                                            <?php echo htmlspecialchars($n['message']); ?>
                                        </p>

                                        <div class="mt-3 flex items-center gap-3">
                                            <?php if ($isStock): ?>
                                                <a href="<?php echo APP_URL; ?>/admin/inventory/alerts.php" class="text-xs font-bold text-brand-red hover:underline inline-flex items-center gap-1">
                                                    Restock in Inventory Alerts &rarr;
                                                </a>
                                            <?php endif; ?>

                                            <?php if ($isUnread): ?>
                                                <form method="POST" class="inline">
                                                    <?php echo rp_csrf_field(); ?>
                                                    <input type="hidden" name="action" value="mark_read">
                                                    <input type="hidden" name="notification_id" value="<?php echo (int)$n['id']; ?>">
                                                    <button type="submit" class="text-[11px] font-semibold text-gray-400 hover:text-gray-700 transition">
                                                        Mark as read
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
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
