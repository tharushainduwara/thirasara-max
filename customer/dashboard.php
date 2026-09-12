<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Thirasara Max Mobile
| Customer Service Portal
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

requireAuth(['customer', 'admin']);

$currentUser = getCurrentUser();

if (!$currentUser) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

$userId = (int) $currentUser['id'];

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

$db = Database::getConnection();

/*
|--------------------------------------------------------------------------
| Default Data
|--------------------------------------------------------------------------
*/

$stats = [
    'orders' => 0,
    'active_orders' => 0,
    'total_spent' => 0,

    'repairs' => 0,
    'active_repairs' => 0,

    'warranties' => 0,
    'active_warranties' => 0,

    'inquiries' => 0,
    'open_inquiries' => 0,

    'unread_notifications' => 0
];

$recentOrders = [];
$recentRepairs = [];
$recentWarranties = [];
$recentInquiries = [];
$recentNotifications = [];

$dashboardError = null;

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/

function dashboardStatusClass(string $status): string
{
    return match (strtolower(trim($status))) {

        'placed',
        'pending',
        'assigned',
        'open',
        'new' =>
            'bg-amber-50 text-amber-700 border-amber-200',

        'processing',
        'in_progress',
        'under_repair' =>
            'bg-blue-50 text-blue-700 border-blue-200',

        'shipped' =>
            'bg-indigo-50 text-indigo-700 border-indigo-200',

        'delivered',
        'completed',
        'resolved',
        'paid',
        'active',
        'closed' =>
            'bg-emerald-50 text-emerald-700 border-emerald-200',

        'cancelled',
        'failed',
        'expired',
        'void',
        'rejected' =>
            'bg-red-50 text-red-700 border-red-200',

        'claimed' =>
            'bg-purple-50 text-purple-700 border-purple-200',

        default =>
            'bg-gray-50 text-gray-600 border-gray-200'
    };
}

function dashboardStatusLabel(string $status): string
{
    return ucwords(str_replace('_', ' ', $status));
}

function dashboardFormatMoney($amount): string
{
    return 'Rs. ' . number_format((float) $amount, 2);
}

function dashboardFormatDate(?string $date): string
{
    if (!$date) {
        return '-';
    }

    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return '-';
    }

    return date('M d, Y', $timestamp);
}

function dashboardNotificationIcon(string $type): string
{
    return match (strtolower(trim($type))) {

        'repair_status',
        'repair' =>
            'wrench',

        'warranty_alert',
        'warranty' =>
            'shield-check',

        'order_status',
        'order' =>
            'package',

        'payment' =>
            'credit-card',

        'support',
        'inquiry' =>
            'message-circle',

        default =>
            'bell'
    };
}

/*
|--------------------------------------------------------------------------
| Load Dashboard Data
|--------------------------------------------------------------------------
*/

try {

    /*
    |--------------------------------------------------------------------------
    | Order Statistics
    |--------------------------------------------------------------------------
    */

    $stmt = $db->prepare("
        SELECT
            COUNT(*) AS total_orders,

            COALESCE(
                SUM(
                    CASE
                        WHEN order_status NOT IN ('delivered', 'cancelled')
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS active_orders,

            COALESCE(
                SUM(
                    CASE
                        WHEN payment_status = 'paid'
                        THEN total_amount
                        ELSE 0
                    END
                ),
                0
            ) AS total_spent

        FROM orders
        WHERE user_id = ?
    ");

    $stmt->execute([$userId]);

    $orderStats = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($orderStats) {
        $stats['orders'] = (int) ($orderStats['total_orders'] ?? 0);
        $stats['active_orders'] = (int) ($orderStats['active_orders'] ?? 0);
        $stats['total_spent'] = (float) ($orderStats['total_spent'] ?? 0);
    }


    /*
    |--------------------------------------------------------------------------
    | Repair Statistics
    |--------------------------------------------------------------------------
    */

    $stmt = $db->prepare("
        SELECT
            COUNT(*) AS total_repairs,

            COALESCE(
                SUM(
                    CASE
                        WHEN repair_status NOT IN ('completed', 'cancelled')
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS active_repairs

        FROM repairs
        WHERE user_id = ?
    ");

    $stmt->execute([$userId]);

    $repairStats = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($repairStats) {
        $stats['repairs'] = (int) ($repairStats['total_repairs'] ?? 0);
        $stats['active_repairs'] = (int) ($repairStats['active_repairs'] ?? 0);
    }


    /*
    |--------------------------------------------------------------------------
    | Warranty Statistics
    |--------------------------------------------------------------------------
    */

    $stmt = $db->prepare("
        SELECT
            COUNT(*) AS total_warranties,

            COALESCE(
                SUM(
                    CASE
                        WHEN status = 'active'
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS active_warranties

        FROM warranties
        WHERE user_id = ?
    ");

    $stmt->execute([$userId]);

    $warrantyStats = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($warrantyStats) {
        $stats['warranties'] = (int) ($warrantyStats['total_warranties'] ?? 0);
        $stats['active_warranties'] = (int) ($warrantyStats['active_warranties'] ?? 0);
    }


    /*
    |--------------------------------------------------------------------------
    | Inquiry Statistics
    |--------------------------------------------------------------------------
    */

    $stmt = $db->prepare("
        SELECT
            COUNT(*) AS total_inquiries,

            COALESCE(
                SUM(
                    CASE
                        WHEN status NOT IN ('resolved', 'closed')
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS open_inquiries

        FROM inquiries
        WHERE user_id = ?
    ");

    $stmt->execute([$userId]);

    $inquiryStats = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($inquiryStats) {
        $stats['inquiries'] = (int) ($inquiryStats['total_inquiries'] ?? 0);
        $stats['open_inquiries'] = (int) ($inquiryStats['open_inquiries'] ?? 0);
    }


    /*
    |--------------------------------------------------------------------------
    | Notification Statistics
    |--------------------------------------------------------------------------
    */

    $stmt = $db->prepare("
        SELECT COUNT(*) AS unread_notifications

        FROM notifications

        WHERE user_id = ?
        AND is_read = 0
    ");

    $stmt->execute([$userId]);

    $notificationStats = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($notificationStats) {
        $stats['unread_notifications'] =
            (int) ($notificationStats['unread_notifications'] ?? 0);
    }


    /*
    |--------------------------------------------------------------------------
    | Recent Orders
    |--------------------------------------------------------------------------
    */

    $stmt = $db->prepare("
        SELECT
            id,
            order_number,
            total_amount,
            payment_status,
            order_status,
            created_at

        FROM orders

        WHERE user_id = ?

        ORDER BY created_at DESC

        LIMIT 5
    ");

    $stmt->execute([$userId]);

    $recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);


    /*
    |--------------------------------------------------------------------------
    | Recent Repairs
    |--------------------------------------------------------------------------
    */

    $stmt = $db->prepare("
        SELECT
            id,
            repair_code,
            device_model,
            repair_status,
            estimated_cost,
            final_cost,
            preferred_date,
            created_at

        FROM repairs

        WHERE user_id = ?

        ORDER BY created_at DESC

        LIMIT 4
    ");

    $stmt->execute([$userId]);

    $recentRepairs = $stmt->fetchAll(PDO::FETCH_ASSOC);


    /*
    |--------------------------------------------------------------------------
    | Recent Warranties
    |--------------------------------------------------------------------------
    */

    $stmt = $db->prepare("
        SELECT
            w.id,
            w.warranty_code,
            w.warranty_type,
            w.start_date,
            w.end_date,
            w.status,

            p.name AS product_name,
            p.brand AS product_brand

        FROM warranties w

        LEFT JOIN products p
            ON p.id = w.product_id

        WHERE w.user_id = ?

        ORDER BY w.created_at DESC

        LIMIT 4
    ");

    $stmt->execute([$userId]);

    $recentWarranties = $stmt->fetchAll(PDO::FETCH_ASSOC);


    /*
    |--------------------------------------------------------------------------
    | Recent Inquiries
    |--------------------------------------------------------------------------
    */

    $stmt = $db->prepare("
        SELECT
            id,
            subject,
            status,
            created_at

        FROM inquiries

        WHERE user_id = ?

        ORDER BY created_at DESC

        LIMIT 4
    ");

    $stmt->execute([$userId]);

    $recentInquiries = $stmt->fetchAll(PDO::FETCH_ASSOC);


    /*
    |--------------------------------------------------------------------------
    | Recent Notifications
    |--------------------------------------------------------------------------
    */

    $stmt = $db->prepare("
        SELECT
            id,
            title,
            message,
            type,
            is_read,
            created_at

        FROM notifications

        WHERE user_id = ?

        ORDER BY created_at DESC

        LIMIT 5
    ");

    $stmt->execute([$userId]);

    $recentNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC);


} catch (Throwable $e) {

    error_log(
        'Customer Service Portal Error: ' .
        $e->getMessage()
    );

    $dashboardError =
        'Some portal information could not be loaded right now.';
}


/*
|--------------------------------------------------------------------------
| Page
|--------------------------------------------------------------------------
*/

$pageTitle = 'Customer Portal';

require_once __DIR__ . '/../includes/header.php';

// Presentational-only helper: total active items across services.
// Does not alter $stats, queries, or structure above.
$dashboardActivePulse =
    $stats['active_orders'] +
    $stats['active_repairs'] +
    $stats['open_inquiries'];

?>

<style>
    @keyframes dash-rise {
        from { opacity: 0; transform: translateY(10px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .dash-hero-in {
        animation: dash-rise 0.5s cubic-bezier(0.16, 1, 0.3, 1) both;
    }
    @media (prefers-reduced-motion: reduce) {
        .dash-hero-in { animation: none; }
    }
</style>

<div class="max-w-7xl mx-auto pb-10">


    <!-- =========================================================
         1. WELCOME HEADER
    ========================================================== -->

    <section class="mb-8">

        <div class="dash-hero-in relative overflow-hidden bg-white border border-gray-200 rounded-[28px] shadow-sm">

            <div class="h-[3px] w-full bg-gradient-to-r from-brand-red via-brand-red to-gray-900"></div>

            <div class="px-6 py-8 sm:px-10 sm:py-10">

                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-8">

                    <div class="max-w-xl">

                        <p class="text-sm font-semibold text-gray-400">
                            Thirasara Max &middot; Customer portal
                        </p>

                        <h1 class="mt-2 text-3xl sm:text-4xl font-black tracking-tight text-gray-900 leading-tight">
                            Welcome back, <span class="text-brand-red"><?php echo htmlspecialchars($currentUser['name'] ?? 'Customer'); ?></span>
                        </h1>

                        <p class="mt-3 text-[15px] text-gray-500 leading-relaxed">
                            Manage your orders, device repairs, warranties and support
                            requests from one place.
                        </p>

                        <?php if ($dashboardActivePulse > 0): ?>
                            <div class="mt-5 inline-flex items-center gap-2 text-sm text-gray-600">
                                <span class="relative flex h-2 w-2">
                                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-brand-red opacity-60"></span>
                                    <span class="relative inline-flex rounded-full h-2 w-2 bg-brand-red"></span>
                                </span>
                                <span>
                                    <strong class="font-bold text-gray-900"><?php echo $dashboardActivePulse; ?></strong>
                                    thing<?php echo $dashboardActivePulse === 1 ? '' : 's'; ?> currently in progress
                                </span>
                            </div>
                        <?php endif; ?>

                    </div>

                    <a
                        href="<?php echo APP_URL; ?>/products.php"
                        class="inline-flex items-center justify-center gap-2 bg-gray-900 hover:bg-brand-red text-white px-6 py-3.5 rounded-2xl text-sm font-bold shadow-sm transition-colors shrink-0"
                    >
                        <i data-lucide="shopping-bag" class="w-4 h-4"></i>
                        Shop products
                    </a>

                </div>

            </div>

        </div>

    </section>


    <!-- =========================================================
         2. ERROR MESSAGE
    ========================================================== -->

    <?php if ($dashboardError): ?>

        <div class="mb-8 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-800">
            <i data-lucide="alert-circle" class="w-5 h-5 mt-0.5 shrink-0"></i>
            <span><?php echo htmlspecialchars($dashboardError); ?></span>
        </div>

    <?php endif; ?>


    <!-- =========================================================
         3. HOW CAN WE HELP?
    ========================================================== -->

    <section class="mb-10">

        <div class="mb-5 flex items-baseline justify-between">
            <h2 class="text-xl font-extrabold text-gray-900">How can we help?</h2>
            <p class="text-sm text-gray-400">Pick a service to get started</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

            <!-- Shop -->
            <a
                href="<?php echo APP_URL; ?>/products.php"
                class="group bg-white border border-gray-200 rounded-2xl p-5 hover:border-brand-red/30 hover:shadow-md transition-all duration-200"
            >
                <div class="w-11 h-11 rounded-full bg-red-50 text-brand-red flex items-center justify-center mb-4 group-hover:scale-105 transition-transform">
                    <i data-lucide="shopping-bag" class="w-5 h-5"></i>
                </div>

                <div class="flex items-center justify-between">
                    <h3 class="text-[15px] font-bold text-gray-900">Shop products</h3>
                    <i data-lucide="arrow-up-right" class="w-4 h-4 text-gray-300 group-hover:text-brand-red transition-colors"></i>
                </div>

                <p class="text-[13px] text-gray-500 mt-1.5 leading-5">
                    Browse phones, accessories and more.
                </p>
            </a>

            <!-- Repair -->
            <a
                href="<?php echo APP_URL; ?>/repair-booking.php"
                class="group bg-white border border-gray-200 rounded-2xl p-5 hover:border-blue-200 hover:shadow-md transition-all duration-200"
            >
                <div class="w-11 h-11 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center mb-4 group-hover:scale-105 transition-transform">
                    <i data-lucide="wrench" class="w-5 h-5"></i>
                </div>

                <div class="flex items-center justify-between">
                    <h3 class="text-[15px] font-bold text-gray-900">Book a repair</h3>
                    <i data-lucide="arrow-up-right" class="w-4 h-4 text-gray-300 group-hover:text-blue-600 transition-colors"></i>
                </div>

                <p class="text-[13px] text-gray-500 mt-1.5 leading-5">
                    Request a repair for your device.
                </p>
            </a>

            <!-- Warranty -->
            <a
                href="<?php echo APP_URL; ?>/warranty-check.php"
                class="group bg-white border border-gray-200 rounded-2xl p-5 hover:border-emerald-200 hover:shadow-md transition-all duration-200"
            >
                <div class="w-11 h-11 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center mb-4 group-hover:scale-105 transition-transform">
                    <i data-lucide="shield-check" class="w-5 h-5"></i>
                </div>

                <div class="flex items-center justify-between">
                    <h3 class="text-[15px] font-bold text-gray-900">Check warranty</h3>
                    <i data-lucide="arrow-up-right" class="w-4 h-4 text-gray-300 group-hover:text-emerald-600 transition-colors"></i>
                </div>

                <p class="text-[13px] text-gray-500 mt-1.5 leading-5">
                    Check coverage for your products.
                </p>
            </a>

            <!-- Inquiry -->
            <a
                href="<?php echo APP_URL; ?>/inquiry.php"
                class="group bg-white border border-gray-200 rounded-2xl p-5 hover:border-purple-200 hover:shadow-md transition-all duration-200"
            >
                <div class="w-11 h-11 rounded-full bg-purple-50 text-purple-600 flex items-center justify-center mb-4 group-hover:scale-105 transition-transform">
                    <i data-lucide="message-circle" class="w-5 h-5"></i>
                </div>

                <div class="flex items-center justify-between">
                    <h3 class="text-[15px] font-bold text-gray-900">Inquiry</h3>
                    <i data-lucide="arrow-up-right" class="w-4 h-4 text-gray-300 group-hover:text-purple-600 transition-colors"></i>
                </div>

                <p class="text-[13px] text-gray-500 mt-1.5 leading-5">
                    Send us an inquiry.
                </p>
            </a>

        </div>

    </section>


    <!-- =========================================================
         4. SERVICE STATUS
    ========================================================== -->

    <section class="mb-10">

        <div class="mb-5">
            <h2 class="text-xl font-extrabold text-gray-900">Your services</h2>
            <p class="text-sm text-gray-500 mt-1">A quick overview of your activity with Thirasara Max.</p>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">

            <!-- Orders -->
            <a
                href="<?php echo APP_URL; ?>/customer/orders.php"
                class="group relative bg-white border border-gray-200 rounded-xl pl-5 pr-4 py-4 overflow-hidden hover:shadow-sm hover:border-gray-300 transition-all"
            >
                <span class="absolute left-0 top-0 bottom-0 w-1 bg-brand-red"></span>

                <div class="flex items-center justify-between">
                    <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400">Orders</p>
                    <i data-lucide="package" class="w-4 h-4 text-brand-red"></i>
                </div>

                <p class="text-2xl font-black text-gray-900 mt-1"><?php echo $stats['orders']; ?></p>

                <p class="text-[11px] text-gray-500 mt-1">
                    <?php echo $stats['active_orders']; ?> active
                </p>
            </a>

            <!-- Repairs -->
            <a
                href="<?php echo APP_URL; ?>/customer/repairs.php"
                class="group relative bg-white border border-gray-200 rounded-xl pl-5 pr-4 py-4 overflow-hidden hover:shadow-sm hover:border-blue-200 transition-all"
            >
                <span class="absolute left-0 top-0 bottom-0 w-1 bg-blue-500"></span>

                <div class="flex items-center justify-between">
                    <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400">Repairs</p>
                    <i data-lucide="wrench" class="w-4 h-4 text-blue-600"></i>
                </div>

                <p class="text-2xl font-black text-gray-900 mt-1"><?php echo $stats['repairs']; ?></p>

                <p class="text-[11px] text-gray-500 mt-1">
                    <?php echo $stats['active_repairs']; ?> active
                </p>
            </a>

            <!-- Warranties -->
            <a
                href="<?php echo APP_URL; ?>/customer/warranties.php"
                class="group relative bg-white border border-gray-200 rounded-xl pl-5 pr-4 py-4 overflow-hidden hover:shadow-sm hover:border-emerald-200 transition-all"
            >
                <span class="absolute left-0 top-0 bottom-0 w-1 bg-emerald-500"></span>

                <div class="flex items-center justify-between">
                    <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400">Warranties</p>
                    <i data-lucide="shield-check" class="w-4 h-4 text-emerald-600"></i>
                </div>

                <p class="text-2xl font-black text-gray-900 mt-1"><?php echo $stats['warranties']; ?></p>

                <p class="text-[11px] text-gray-500 mt-1">
                    <?php echo $stats['active_warranties']; ?> active
                </p>
            </a>

            <!-- Inquiries -->
            <a
                href="<?php echo APP_URL; ?>/customer/inquiries.php"
                class="group relative bg-white border border-gray-200 rounded-xl pl-5 pr-4 py-4 overflow-hidden hover:shadow-sm hover:border-purple-200 transition-all"
            >
                <span class="absolute left-0 top-0 bottom-0 w-1 bg-purple-500"></span>

                <div class="flex items-center justify-between">
                    <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400">Inquiries</p>
                    <i data-lucide="message-circle" class="w-4 h-4 text-purple-600"></i>
                </div>

                <p class="text-2xl font-black text-gray-900 mt-1"><?php echo $stats['inquiries']; ?></p>

                <p class="text-[11px] text-gray-500 mt-1">
                    <?php echo $stats['open_inquiries']; ?> open
                </p>
            </a>

        </div>

    </section>


    <!-- =========================================================
         5. MAIN ACTIVITY + NOTIFICATIONS
    ========================================================== -->

    <section class="mb-10">

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">


            <!-- =================================================
                 RECENT ACTIVITY
            ================================================== -->

            <div class="lg:col-span-2 bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">

                <div class="px-6 py-5 border-b border-gray-100">
                    <h2 class="text-base font-extrabold text-gray-900">Recent activity</h2>
                    <p class="text-[13px] text-gray-500 mt-1">
                        Your latest orders, repairs and support activity.
                    </p>
                </div>


                <?php
                $hasActivity =
                    !empty($recentOrders) ||
                    !empty($recentRepairs) ||
                    !empty($recentWarranties) ||
                    !empty($recentInquiries);
                ?>


                <?php if (!$hasActivity): ?>

                    <div class="px-6 py-16 text-center">
                        <div class="w-12 h-12 rounded-full bg-gray-50 text-gray-400 flex items-center justify-center mx-auto mb-3">
                            <i data-lucide="activity" class="w-6 h-6"></i>
                        </div>
                        <p class="text-sm font-bold text-gray-800">No recent activity</p>
                        <p class="text-[13px] text-gray-500 mt-1">Your service activity will appear here.</p>
                    </div>

                <?php else: ?>

                    <div class="divide-y divide-gray-100">


                        <!-- Recent Orders -->

                        <?php foreach ($recentOrders as $order): ?>

                            <a
                                href="<?php echo APP_URL; ?>/customer/orders.php?order=<?php echo urlencode($order['order_number']); ?>"
                                class="flex items-center gap-3.5 px-6 py-4 hover:bg-gray-50/80 transition-colors"
                            >
                                <div class="w-9 h-9 rounded-full bg-red-50 text-brand-red flex items-center justify-center shrink-0">
                                    <i data-lucide="package" class="w-4 h-4"></i>
                                </div>

                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <p class="text-sm font-bold text-gray-900">
                                            Order #<?php echo htmlspecialchars($order['order_number']); ?>
                                        </p>

                                        <span class="inline-flex px-2 py-0.5 rounded-full border text-[10px] font-bold <?php echo dashboardStatusClass($order['order_status']); ?>">
                                            <?php echo dashboardStatusLabel($order['order_status']); ?>
                                        </span>
                                    </div>

                                    <p class="text-xs text-gray-500 mt-1">
                                        <?php echo dashboardFormatDate($order['created_at']); ?>
                                    </p>
                                </div>

                                <div class="text-right shrink-0">
                                    <p class="text-sm font-extrabold text-gray-900">
                                        <?php echo dashboardFormatMoney($order['total_amount']); ?>
                                    </p>
                                    <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 ml-auto mt-1"></i>
                                </div>
                            </a>

                        <?php endforeach; ?>


                        <!-- Recent Repairs -->

                        <?php foreach ($recentRepairs as $repair): ?>

                            <a
                                href="<?php echo APP_URL; ?>/customer/repairs.php"
                                class="flex items-center gap-3.5 px-6 py-4 hover:bg-gray-50/80 transition-colors"
                            >
                                <div class="w-9 h-9 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center shrink-0">
                                    <i data-lucide="wrench" class="w-4 h-4"></i>
                                </div>

                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <p class="text-sm font-bold text-gray-900 truncate">
                                            <?php echo htmlspecialchars($repair['device_model']); ?>
                                        </p>

                                        <span class="inline-flex px-2 py-0.5 rounded-full border text-[10px] font-bold <?php echo dashboardStatusClass($repair['repair_status']); ?>">
                                            <?php echo dashboardStatusLabel($repair['repair_status']); ?>
                                        </span>
                                    </div>

                                    <p class="text-xs text-gray-500 mt-1">
                                        <?php echo htmlspecialchars($repair['repair_code']); ?>

                                        <?php if (!empty($repair['preferred_date'])): ?>
                                            <span class="mx-1">&middot;</span>
                                            <?php echo dashboardFormatDate($repair['preferred_date']); ?>
                                        <?php endif; ?>
                                    </p>
                                </div>

                                <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 shrink-0"></i>
                            </a>

                        <?php endforeach; ?>


                        <!-- Recent Warranties -->

                        <?php foreach ($recentWarranties as $warranty): ?>

                            <a
                                href="<?php echo APP_URL; ?>/customer/warranties.php"
                                class="flex items-center gap-3.5 px-6 py-4 hover:bg-gray-50/80 transition-colors"
                            >
                                <div class="w-9 h-9 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
                                    <i data-lucide="shield-check" class="w-4 h-4"></i>
                                </div>

                                <div class="flex-1 min-w-0">

                                    <?php
                                    $warrantyProduct =
                                        $warranty['product_name']
                                        ?: (
                                            ucfirst($warranty['warranty_type'])
                                            . ' Warranty'
                                        );
                                    ?>

                                    <div class="flex items-center gap-2 flex-wrap">
                                        <p class="text-sm font-bold text-gray-900 truncate">
                                            <?php echo htmlspecialchars($warrantyProduct); ?>
                                        </p>

                                        <span class="inline-flex px-2 py-0.5 rounded-full border text-[10px] font-bold <?php echo dashboardStatusClass($warranty['status']); ?>">
                                            <?php echo dashboardStatusLabel($warranty['status']); ?>
                                        </span>
                                    </div>

                                    <p class="text-xs text-gray-500 mt-1">
                                        <?php echo htmlspecialchars($warranty['warranty_code']); ?>
                                        <span class="mx-1">&middot;</span>
                                        Ends <?php echo dashboardFormatDate($warranty['end_date']); ?>
                                    </p>
                                </div>

                                <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 shrink-0"></i>
                            </a>

                        <?php endforeach; ?>


                        <!-- Recent Inquiries -->

                        <?php foreach ($recentInquiries as $inquiry): ?>

                            <a
                                href="<?php echo APP_URL; ?>/customer/inquiries.php"
                                class="flex items-center gap-3.5 px-6 py-4 hover:bg-gray-50/80 transition-colors"
                            >
                                <div class="w-9 h-9 rounded-full bg-purple-50 text-purple-600 flex items-center justify-center shrink-0">
                                    <i data-lucide="message-circle" class="w-4 h-4"></i>
                                </div>

                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <p class="text-sm font-bold text-gray-900 truncate">
                                            <?php echo htmlspecialchars($inquiry['subject']); ?>
                                        </p>

                                        <span class="inline-flex px-2 py-0.5 rounded-full border text-[10px] font-bold <?php echo dashboardStatusClass($inquiry['status']); ?>">
                                            <?php echo dashboardStatusLabel($inquiry['status']); ?>
                                        </span>
                                    </div>

                                    <p class="text-xs text-gray-500 mt-1">
                                        <?php echo dashboardFormatDate($inquiry['created_at']); ?>
                                    </p>
                                </div>

                                <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 shrink-0"></i>
                            </a>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>


                <div class="px-6 py-4 border-t border-gray-100 bg-gray-50/60">
                    <div class="flex flex-wrap gap-x-5 gap-y-2">
                        <a href="<?php echo APP_URL; ?>/customer/orders.php" class="text-xs font-bold text-gray-600 hover:text-brand-red transition-colors">View orders</a>
                        <a href="<?php echo APP_URL; ?>/customer/repairs.php" class="text-xs font-bold text-gray-600 hover:text-brand-red transition-colors">View repairs</a>
                        <a href="<?php echo APP_URL; ?>/customer/warranties.php" class="text-xs font-bold text-gray-600 hover:text-brand-red transition-colors">View warranties</a>
                        <a href="<?php echo APP_URL; ?>/customer/inquiries.php" class="text-xs font-bold text-gray-600 hover:text-brand-red transition-colors">View inquiries</a>
                    </div>
                </div>

            </div>


            <!-- =================================================
                 NOTIFICATIONS
            ================================================== -->

            <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">

                <div class="px-6 py-5 border-b border-gray-100 flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-extrabold text-gray-900">Notifications</h2>
                        <p class="text-[13px] text-gray-500 mt-1">Important updates from us.</p>
                    </div>

                    <?php if ($stats['unread_notifications'] > 0): ?>
                        <span class="min-w-6 h-6 px-1.5 rounded-full bg-brand-red text-white text-[10px] font-bold flex items-center justify-center">
                            <?php echo $stats['unread_notifications']; ?>
                        </span>
                    <?php endif; ?>
                </div>


                <?php if (empty($recentNotifications)): ?>

                    <div class="px-6 py-16 text-center">
                        <div class="w-11 h-11 rounded-full bg-gray-50 text-gray-400 flex items-center justify-center mx-auto mb-3">
                            <i data-lucide="bell" class="w-5 h-5"></i>
                        </div>
                        <p class="text-sm font-bold text-gray-800">You're all caught up</p>
                        <p class="text-[13px] text-gray-500 mt-1">No new notifications.</p>
                    </div>

                <?php else: ?>

                    <div class="divide-y divide-gray-100">

                        <?php foreach ($recentNotifications as $notification): ?>

                            <div class="px-6 py-4 <?php echo ((int) $notification['is_read'] === 0) ? 'bg-red-50/40' : ''; ?>">

                                <div class="flex gap-3">

                                    <div class="w-8 h-8 rounded-full bg-gray-100 text-gray-500 flex items-center justify-center shrink-0">
                                        <i data-lucide="<?php echo htmlspecialchars(dashboardNotificationIcon($notification['type'] ?? '')); ?>" class="w-4 h-4"></i>
                                    </div>

                                    <div class="min-w-0 flex-1">

                                        <div class="flex items-start gap-2">
                                            <p class="text-xs font-bold text-gray-900 leading-5">
                                                <?php echo htmlspecialchars($notification['title']); ?>
                                            </p>

                                            <?php if ((int) $notification['is_read'] === 0): ?>
                                                <span class="w-1.5 h-1.5 rounded-full bg-brand-red mt-1.5 shrink-0"></span>
                                            <?php endif; ?>
                                        </div>

                                        <p class="text-[11px] text-gray-500 leading-5 mt-1">
                                            <?php echo htmlspecialchars($notification['message']); ?>
                                        </p>

                                        <p class="text-[10px] text-gray-400 mt-1.5">
                                            <?php echo dashboardFormatDate($notification['created_at']); ?>
                                        </p>

                                    </div>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </section>


    <!-- =========================================================
         6. QUICK SERVICE SUMMARY
    ========================================================== -->

    <section class="mb-10">

        <div class="mb-5">
            <h2 class="text-xl font-extrabold text-gray-900">Manage your services</h2>
            <p class="text-sm text-gray-500 mt-1">Access your complete service history whenever you need it.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">


            <!-- Orders -->
            <a
                href="<?php echo APP_URL; ?>/customer/orders.php"
                class="group flex items-center gap-4 bg-white border border-gray-200 rounded-xl p-4 hover:shadow-sm hover:border-gray-300 transition-all"
            >
                <div class="w-10 h-10 rounded-full bg-red-50 text-brand-red flex items-center justify-center shrink-0">
                    <i data-lucide="package" class="w-4 h-4"></i>
                </div>

                <div class="flex-1">
                    <p class="text-sm font-bold text-gray-900">My orders</p>
                    <p class="text-xs text-gray-500 mt-0.5">Track purchases and payments</p>
                </div>

                <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 group-hover:text-brand-red transition-colors"></i>
            </a>


            <!-- Repairs -->
            <a
                href="<?php echo APP_URL; ?>/customer/repairs.php"
                class="group flex items-center gap-4 bg-white border border-gray-200 rounded-xl p-4 hover:shadow-sm hover:border-blue-200 transition-all"
            >
                <div class="w-10 h-10 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center shrink-0">
                    <i data-lucide="wrench" class="w-4 h-4"></i>
                </div>

                <div class="flex-1">
                    <p class="text-sm font-bold text-gray-900">My repairs</p>
                    <p class="text-xs text-gray-500 mt-0.5">Track your device repairs</p>
                </div>

                <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 group-hover:text-blue-600 transition-colors"></i>
            </a>


            <!-- Warranties -->
            <a
                href="<?php echo APP_URL; ?>/customer/warranties.php"
                class="group flex items-center gap-4 bg-white border border-gray-200 rounded-xl p-4 hover:shadow-sm hover:border-emerald-200 transition-all"
            >
                <div class="w-10 h-10 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
                    <i data-lucide="shield-check" class="w-4 h-4"></i>
                </div>

                <div class="flex-1">
                    <p class="text-sm font-bold text-gray-900">My warranties</p>
                    <p class="text-xs text-gray-500 mt-0.5">View active product coverage</p>
                </div>

                <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 group-hover:text-emerald-600 transition-colors"></i>
            </a>


            <!-- Inquiries -->
            <a
                href="<?php echo APP_URL; ?>/customer/inquiries.php"
                class="group flex items-center gap-4 bg-white border border-gray-200 rounded-xl p-4 hover:shadow-sm hover:border-purple-200 transition-all"
            >
                <div class="w-10 h-10 rounded-full bg-purple-50 text-purple-600 flex items-center justify-center shrink-0">
                    <i data-lucide="message-circle" class="w-4 h-4"></i>
                </div>

                <div class="flex-1">
                    <p class="text-sm font-bold text-gray-900">Inquiries</p>
                    <p class="text-xs text-gray-500 mt-0.5">Send us a message and track your conversations</p>
                </div>

                <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 group-hover:text-purple-600 transition-colors"></i>
            </a>


            <!-- Profile -->
            <a
                href="<?php echo APP_URL; ?>/customer/profile.php"
                class="group flex items-center gap-4 bg-white border border-gray-200 rounded-xl p-4 hover:shadow-sm hover:border-gray-300 transition-all"
            >
                <div class="w-10 h-10 rounded-full bg-gray-100 text-gray-600 flex items-center justify-center shrink-0">
                    <i data-lucide="user-cog" class="w-4 h-4"></i>
                </div>

                <div class="flex-1">
                    <p class="text-sm font-bold text-gray-900">Profile settings</p>
                    <p class="text-xs text-gray-500 mt-0.5">Manage your account</p>
                </div>

                <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 group-hover:text-gray-600 transition-colors"></i>
            </a>


            <!-- Shop (spotlight tile) -->
            <a
                href="<?php echo APP_URL; ?>/products.php"
                class="group relative flex items-center gap-4 bg-gray-900 border border-gray-900 rounded-xl p-4 overflow-hidden hover:bg-black transition-colors"
            >
                <div class="absolute -right-6 -top-6 w-20 h-20 rounded-full bg-brand-red/20 blur-xl"></div>

                <div class="w-10 h-10 rounded-full bg-white/10 text-white flex items-center justify-center shrink-0 relative">
                    <i data-lucide="shopping-bag" class="w-4 h-4"></i>
                </div>

                <div class="flex-1 relative">
                    <p class="text-sm font-bold text-white">Continue shopping</p>
                    <p class="text-xs text-gray-400 mt-0.5">Explore our latest products</p>
                </div>

                <i data-lucide="arrow-up-right" class="w-4 h-4 text-gray-400 group-hover:text-white transition-colors relative"></i>
            </a>

        </div>

    </section>


    <!-- =========================================================
         7. SUPPORT CTA
    ========================================================== -->

    <section>

        <div class="rounded-[28px] bg-gray-50 border border-gray-200 px-6 py-7 sm:px-8 flex flex-col md:flex-row md:items-center md:justify-between gap-5">

            <div class="flex items-start gap-4">
                <div class="w-11 h-11 rounded-full bg-white border border-gray-200 text-brand-red flex items-center justify-center shrink-0">
                    <i data-lucide="headphones" class="w-5 h-5"></i>
                </div>

                <div>
                    <h2 class="text-base font-extrabold text-gray-900">Need help?</h2>
                    <p class="text-sm text-gray-500 mt-1 max-w-xl">
                        Our customer service team can help with orders, repairs,
                        warranties and other questions.
                    </p>
                </div>
            </div>

            <a
                href="<?php echo APP_URL; ?>/contact.php"
                class="inline-flex items-center justify-center gap-2 bg-brand-red hover:bg-brand-redHover text-white px-5 py-3 rounded-2xl text-sm font-bold transition-colors shrink-0"
            >
                <i data-lucide="message-circle" class="w-4 h-4"></i>
                Contact support
            </a>

        </div>

    </section>

</div>


<!-- =========================================================
     LUCIDE INITIALIZATION
========================================================= -->

<script>

    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }

</script>


<?php require_once __DIR__ . '/../includes/footer.php'; ?>