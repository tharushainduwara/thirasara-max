<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/repair_helpers.php';

requireAuth(['customer', 'admin', 'staff']);

$currentUser = getCurrentUser();
$uid = (int)$currentUser['id'];
$pdo = Database::getConnection();

// Handle Actions (Mark Single Read, Mark All Read)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rp_csrf_check($_POST['csrf'] ?? '')) {
        setFlash('error', 'Session expired. Please try again.');
    } else {
        $action = trim($_POST['action'] ?? '');
        if ($action === 'mark_read') {
            $notifId = (int)($_POST['notification_id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $stmt->execute([$notifId, $uid]);
            setFlash('success', 'Notification marked as read.');
        } elseif ($action === 'mark_all_read') {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
            $stmt->execute([$uid]);
            setFlash('success', 'All notifications marked as read.');
        }
    }
    header('Location: ' . APP_URL . '/customer/notifications.php');
    exit;
}

// Fetch all notifications
$stmt = $pdo->prepare("
    SELECT * 
    FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 50
");
$stmt->execute([$uid]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count unread
$unreadCount = 0;
foreach ($notifications as $n) {
    if ((int)$n['is_read'] === 0) {
        $unreadCount++;
    }
}

$pageTitle = 'My Notifications';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- PAGE HERO -->
<section class="bg-gradient-to-r from-gray-900 via-zinc-900 to-gray-900 text-white border-b-4 border-brand-red py-10 mb-8 overflow-hidden rounded-3xl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded bg-brand-red text-white text-[10px] font-bold uppercase tracking-wider mb-2 shadow-sm">
                    Activity &amp; Updates
                </span>
                <h1 class="text-2xl sm:text-3xl font-black uppercase tracking-tight text-white">Notifications</h1>
                <p class="text-gray-300 text-xs sm:text-sm mt-1">Review your recent order progress, repair status updates, and service replies.</p>
            </div>
            <?php if ($unreadCount > 0): ?>
                <form method="POST">
                    <?php echo rp_csrf_field(); ?>
                    <input type="hidden" name="action" value="mark_all_read">
                    <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white text-gray-900 font-bold text-xs hover:bg-gray-100 transition shadow-sm">
                        <i data-lucide="check-check" class="w-4 h-4 text-brand-red"></i> Mark All as Read
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>

<main class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 pb-16">
    <?php if (function_exists('displayFlash')): ?><div class="mb-6"><?php displayFlash(); ?></div><?php endif; ?>

    <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h2 class="text-xs font-bold uppercase tracking-wider text-gray-700 flex items-center gap-2">
                <i data-lucide="bell" class="w-4 h-4 text-brand-red"></i> Notification Inbox
            </h2>
            <span class="text-xs font-semibold text-gray-400">
                <?php echo $unreadCount > 0 ? "{$unreadCount} unread" : 'All caught up'; ?>
            </span>
        </div>

        <?php if (empty($notifications)): ?>
            <div class="p-12 text-center">
                <div class="w-12 h-12 rounded-full bg-gray-50 text-gray-400 flex items-center justify-center mx-auto mb-3">
                    <i data-lucide="bell-off" class="w-6 h-6"></i>
                </div>
                <h3 class="text-sm font-bold text-gray-800">No notifications yet</h3>
                <p class="text-xs text-gray-500 mt-1">Updates about your orders, repairs, and support inquiries will appear here.</p>
            </div>
        <?php else: ?>
            <div class="divide-y divide-gray-100">
                <?php foreach ($notifications as $n): 
                    $isUnread = (int)$n['is_read'] === 0;
                    $icon = match ($n['type']) {
                        'order_confirmation' => 'shopping-bag',
                        'repair_status'     => 'wrench',
                        'warranty_alert'    => 'shield-check',
                        default             => 'message-circle'
                    };
                    $iconColor = match ($n['type']) {
                        'order_confirmation' => 'bg-emerald-100 text-emerald-700',
                        'repair_status'     => 'bg-blue-100 text-blue-700',
                        'warranty_alert'    => 'bg-amber-100 text-amber-700',
                        default             => 'bg-gray-100 text-gray-700'
                    };
                ?>
                    <div class="p-5 flex items-start gap-4 transition hover:bg-gray-50/80 <?php echo $isUnread ? 'bg-red-50/30' : ''; ?>">
                        <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 <?php echo $iconColor; ?>">
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
                                <?php if ($n['type'] === 'order_confirmation'): ?>
                                    <a href="<?php echo APP_URL; ?>/customer/orders.php" class="text-xs font-bold text-brand-red hover:underline inline-flex items-center gap-1">
                                        View Orders &rarr;
                                    </a>
                                <?php elseif ($n['type'] === 'repair_status'): ?>
                                    <a href="<?php echo APP_URL; ?>/customer/repairs.php" class="text-xs font-bold text-brand-red hover:underline inline-flex items-center gap-1">
                                        View Repairs &rarr;
                                    </a>
                                <?php elseif ($n['type'] === 'warranty_alert'): ?>
                                    <a href="<?php echo APP_URL; ?>/customer/warranties.php" class="text-xs font-bold text-brand-red hover:underline inline-flex items-center gap-1">
                                        View Warranties &rarr;
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
