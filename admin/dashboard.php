<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth(['admin']);

$currentUser = getCurrentUser();
$pdo         = Database::getConnection();

/* STAT QUERIES */
$stats = [];

try {

    /* --- Revenue --- */
    $row = $pdo->query("
        SELECT
            COALESCE(SUM(total_amount), 0)                         AS total_revenue,
            COALESCE(SUM(CASE WHEN MONTH(created_at) = MONTH(CURDATE())
                              AND YEAR(created_at)  = YEAR(CURDATE())
                         THEN total_amount END), 0)                AS revenue_this_month,
            COUNT(*)                                               AS total_orders,
            SUM(CASE WHEN order_status = 'placed'    THEN 1 END)   AS pending_orders,
            SUM(CASE WHEN order_status = 'delivered' THEN 1 END)   AS delivered_orders,
            SUM(CASE WHEN order_status = 'cancelled' THEN 1 END)   AS cancelled_orders
        FROM orders
    ")->fetch(PDO::FETCH_ASSOC);
    $stats['revenue']          = (float)  ($row['total_revenue']    ?? 0);
    $stats['revenue_month']    = (float)  ($row['revenue_this_month'] ?? 0);
    $stats['total_orders']     = (int)    ($row['total_orders']     ?? 0);
    $stats['pending_orders']   = (int)    ($row['pending_orders']   ?? 0);
    $stats['delivered_orders'] = (int)    ($row['delivered_orders'] ?? 0);
    $stats['cancelled_orders'] = (int)    ($row['cancelled_orders'] ?? 0);

    /* --- Products & Inventory --- */
    $row = $pdo->query("
        SELECT
            COUNT(*)                                                         AS total_products,
            SUM(CASE WHEN status = 'available'    THEN 1 END)                AS available_products,
            SUM(CASE WHEN status = 'out_of_stock' THEN 1 END)                AS out_of_stock,
            SUM(CASE WHEN status = 'discontinued' THEN 1 END)                AS discontinued
        FROM products
    ")->fetch(PDO::FETCH_ASSOC);
    $stats['total_products']     = (int) ($row['total_products']     ?? 0);
    $stats['available_products'] = (int) ($row['available_products'] ?? 0);
    $stats['out_of_stock']       = (int) ($row['out_of_stock']       ?? 0);

    $row = $pdo->query("
        SELECT COUNT(*) AS cnt
        FROM inventory
        WHERE stock_quantity > 0
          AND stock_quantity <= low_stock_threshold
    ")->fetch(PDO::FETCH_ASSOC);
    $stats['low_stock_count'] = (int) ($row['cnt'] ?? 0);

    /* --- Repairs --- */
    $row = $pdo->query("
        SELECT
            COUNT(*)                                                           AS total_repairs,
            SUM(CASE WHEN repair_status = 'pending'     THEN 1 END)            AS pending_repairs,
            SUM(CASE WHEN repair_status = 'in_progress' THEN 1 END)            AS in_progress_repairs,
            SUM(CASE WHEN repair_status = 'completed'   THEN 1 END)            AS completed_repairs,
            COALESCE(SUM(CASE WHEN repair_status = 'completed'
                         THEN final_cost END), 0)                              AS repair_revenue
        FROM repairs
    ")->fetch(PDO::FETCH_ASSOC);
    $stats['total_repairs']      = (int)   ($row['total_repairs']      ?? 0);
    $stats['pending_repairs']    = (int)   ($row['pending_repairs']    ?? 0);
    $stats['in_progress_repairs'] = (int)   ($row['in_progress_repairs'] ?? 0);
    $stats['completed_repairs']  = (int)   ($row['completed_repairs']  ?? 0);
    $stats['repair_revenue']     = (float) ($row['repair_revenue']     ?? 0);

    /* --- Users --- */
    $row = $pdo->query("
        SELECT
            COUNT(*)                                              AS total_users,
            SUM(CASE WHEN role = 'customer' THEN 1 END)           AS customers,
            SUM(CASE WHEN role = 'staff'    THEN 1 END)           AS staff,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) AS new_today
        FROM users
    ")->fetch(PDO::FETCH_ASSOC);
    $stats['total_users'] = (int) ($row['total_users'] ?? 0);
    $stats['customers']   = (int) ($row['customers']   ?? 0);
    $stats['staff']       = (int) ($row['staff']       ?? 0);
    $stats['new_today']   = (int) ($row['new_today']   ?? 0);

    /* --- Warranties --- */
    $row = $pdo->query("
        SELECT
            SUM(CASE WHEN status = 'active'  THEN 1 END) AS active_warranties,
            SUM(CASE WHEN status = 'claimed' THEN 1 END) AS claimed_warranties,
            SUM(CASE WHEN status = 'expired' THEN 1 END) AS expired_warranties
        FROM warranties
    ")->fetch(PDO::FETCH_ASSOC);
    $stats['active_warranties']  = (int) ($row['active_warranties']  ?? 0);
    $stats['claimed_warranties'] = (int) ($row['claimed_warranties'] ?? 0);

    /* --- Inquiries --- */
    $row = $pdo->query("
        SELECT
            SUM(CASE WHEN status = 'pending'  THEN 1 END) AS pending_inquiries,
            SUM(CASE WHEN status = 'resolved' THEN 1 END) AS resolved_inquiries
        FROM inquiries
    ")->fetch(PDO::FETCH_ASSOC);
    $stats['pending_inquiries'] = (int) ($row['pending_inquiries'] ?? 0);

    /* Recent Orders  (latest 7)  */
    $recentOrders = $pdo->query("
        SELECT
            o.id,
            o.order_number,
            o.total_amount,
            o.order_status,
            o.payment_status,
            o.created_at,
            u.name  AS customer_name
        FROM orders o
        JOIN users  u ON u.id = o.user_id
        ORDER BY o.created_at DESC
        LIMIT 7
    ")->fetchAll(PDO::FETCH_ASSOC);

    /* Recent Repairs (latest 6)  */
    $recentRepairs = $pdo->query("
        SELECT
            r.id,
            r.repair_code,
            r.device_model,
            r.repair_status,
            r.created_at,
            u.name AS customer_name
        FROM repairs r
        JOIN users   u ON u.id = r.user_id
        ORDER BY r.created_at DESC
        LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);

    /*  Low-stock products (up to 6)  */
    $lowStockProducts = $pdo->query("
        SELECT
            p.id,
            p.name,
            p.category,
            i.stock_quantity,
            i.low_stock_threshold
        FROM inventory i
        JOIN products p ON p.id = i.product_id
        WHERE i.stock_quantity > 0
          AND i.stock_quantity <= i.low_stock_threshold
        ORDER BY i.stock_quantity ASC
        LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);

    /*  Monthly revenue  — last 6 months for sparkline */
    $monthlyRevenue = $pdo->query("
        SELECT
            DATE_FORMAT(created_at, '%b %Y') AS month_label,
            DATE_FORMAT(created_at, '%Y-%m') AS month_key,
            COALESCE(SUM(total_amount), 0)   AS revenue
        FROM orders
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
          AND order_status != 'cancelled'
        GROUP BY month_key, month_label
        ORDER BY month_key ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Admin dashboard error: ' . $e->getMessage());
}

/*  HELPERS */
function formatRs(float $v): string
{
    return 'Rs. ' . number_format($v, 2);
}

function orderStatusBadge(string $status): string
{
    return match ($status) {
        'placed'     => 'bg-blue-100 text-blue-700',
        'processing' => 'bg-yellow-100 text-yellow-700',
        'shipped'    => 'bg-purple-100 text-purple-700',
        'delivered'  => 'bg-green-100 text-green-700',
        'cancelled'  => 'bg-red-100 text-red-700',
        default      => 'bg-gray-100 text-gray-600',
    };
}

function repairStatusBadge(string $status): string
{
    return match ($status) {
        'pending'     => 'bg-yellow-100 text-yellow-700',
        'assigned'    => 'bg-blue-100 text-blue-700',
        'in_progress' => 'bg-purple-100 text-purple-700',
        'completed'   => 'bg-green-100 text-green-700',
        'cancelled'   => 'bg-red-100 text-red-700',
        default       => 'bg-gray-100 text-gray-600',
    };
}

function paymentBadge(string $status): string
{
    return match ($status) {
        'paid'     => 'bg-green-100 text-green-700',
        'pending'  => 'bg-yellow-100 text-yellow-700',
        'failed'   => 'bg-red-100 text-red-700',
        'refunded' => 'bg-gray-100 text-gray-600',
        default    => 'bg-gray-100 text-gray-600',
    };
}
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | <?php echo APP_NAME; ?> Admin</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Custom Stylesheet -->
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

    <!-- Expose app URL + load global JS -->
    <script>window.__APP_URL__ = '<?php echo rtrim(APP_URL, '/'); ?>';</script>
    <script src="<?php echo APP_URL; ?>/assets/js/main.js" defer></script>


</head>

<body class="bg-gray-100 text-gray-800 font-sans antialiased">

    <div class="flex min-h-screen">

        <!--  SIDEBAR -->
        <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

        <!--  MAIN CONTENT -->
        <div class="flex-1 flex flex-col min-w-0">

            <!-- TOP BAR -->
            <header class="bg-white border-b border-gray-200 sticky top-0 z-20 flex items-center gap-4 px-6 h-16 flex-shrink-0">

                <!-- Hamburger (mobile) -->
                <button
                    id="sidebar-open-btn"
                    type="button"
                    class="lg:hidden text-gray-500 hover:text-gray-900"
                    aria-label="Open sidebar">
                    <i data-lucide="menu" class="w-5 h-5"></i>
                </button>

                <div class="flex-1 min-w-0">
                    <h1 class="text-lg font-bold text-gray-900 truncate">Dashboard</h1>
                    <p class="text-xs text-gray-400 hidden sm:block">
                        <?php echo date('l, d F Y'); ?>
                    </p>
                </div>

                <div class="flex items-center gap-3">

                    <!-- Low-stock alert pill -->
                    <?php if ($stats['low_stock_count'] > 0): ?>
                        <a
                            href="<?php echo APP_URL; ?>/admin/inventory/index.php"
                            class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-amber-50 border border-amber-200 text-amber-700 text-xs font-semibold hover:bg-amber-100 transition">
                            <i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i>
                            <?php echo $stats['low_stock_count']; ?> Low Stock
                        </a>
                    <?php endif; ?>

                    <!-- Pending inquiries pill -->
                    <?php if ($stats['pending_inquiries'] > 0): ?>
                        <a
                            href="<?php echo APP_URL; ?>/admin/inquiries/index.php"
                            class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-blue-50 border border-blue-200 text-blue-700 text-xs font-semibold hover:bg-blue-100 transition">
                            <i data-lucide="message-circle" class="w-3.5 h-3.5"></i>
                            <?php echo $stats['pending_inquiries']; ?> Inquiries
                        </a>
                    <?php endif; ?>

                    <!-- Avatar -->
                    <div class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center text-white text-sm font-bold flex-shrink-0">
                        <?php echo strtoupper(substr($currentUser['name'] ?? 'A', 0, 1)); ?>
                    </div>

                </div>

            </header>

            <!-- PAGE BODY -->
            <main class="flex-1 p-5 sm:p-7 space-y-7 overflow-auto">

                <!-- WELCOME STRIP  -->
                <div class="bg-brand-dark text-white rounded-2xl px-6 py-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">

                    <div>
                        <p class="text-gray-400 text-sm">Welcome back,</p>
                        <h2 class="text-2xl font-extrabold mt-0.5">
                            <?php echo htmlspecialchars($currentUser['name']); ?> 👋
                        </h2>
                        <p class="text-gray-400 text-xs mt-1">Here's what's happening with your store today.</p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <a href="<?php echo APP_URL; ?>/admin/products/add.php"
                            class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-sm font-semibold transition">
                            <i data-lucide="plus" class="w-4 h-4"></i> Add Product
                        </a>
                        <a href="<?php echo APP_URL; ?>/admin/orders/index.php"
                            class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-white text-sm font-semibold transition">
                            <i data-lucide="shopping-bag" class="w-4 h-4"></i> View Orders
                        </a>
                    </div>

                </div>


                <!-- KPI STAT CARDS  (row 1 — Revenue / Orders / Repairs / Users) -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">

                    <!-- Total Revenue -->
                    <div class="stat-card bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
                        <div class="flex items-start justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Total Revenue</p>
                                <p class="text-2xl font-extrabold text-gray-900 mt-1">
                                    Rs.<?php echo number_format($stats['revenue'], 0); ?>
                                </p>
                                <p class="text-xs text-gray-400 mt-1">
                                    This month: <span class="text-green-600 font-semibold">Rs.<?php echo number_format($stats['revenue_month'], 0); ?></span>
                                </p>
                            </div>
                            <div class="w-10 h-10 rounded-xl bg-green-50 flex items-center justify-center flex-shrink-0">
                                <i data-lucide="trending-up" class="w-5 h-5 text-green-600"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Orders -->
                    <div class="stat-card bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
                        <div class="flex items-start justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Orders</p>
                                <p class="text-2xl font-extrabold text-gray-900 mt-1"><?php echo number_format($stats['total_orders']); ?></p>
                                <p class="text-xs text-gray-400 mt-1">
                                    <span class="text-blue-600 font-semibold"><?php echo $stats['pending_orders']; ?> pending</span>
                                    &nbsp;·&nbsp;
                                    <?php echo $stats['delivered_orders']; ?> delivered
                                </p>
                            </div>
                            <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center flex-shrink-0">
                                <i data-lucide="shopping-bag" class="w-5 h-5 text-blue-600"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Repairs -->
                    <div class="stat-card bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
                        <div class="flex items-start justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Repairs</p>
                                <p class="text-2xl font-extrabold text-gray-900 mt-1"><?php echo number_format($stats['total_repairs']); ?></p>
                                <p class="text-xs text-gray-400 mt-1">
                                    <span class="text-amber-600 font-semibold"><?php echo $stats['pending_repairs']; ?> pending</span>
                                    &nbsp;·&nbsp;
                                    <?php echo $stats['completed_repairs']; ?> done
                                </p>
                            </div>
                            <div class="w-10 h-10 rounded-xl bg-amber-50 flex items-center justify-center flex-shrink-0">
                                <i data-lucide="wrench" class="w-5 h-5 text-amber-600"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Users -->
                    <div class="stat-card bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
                        <div class="flex items-start justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Customers</p>
                                <p class="text-2xl font-extrabold text-gray-900 mt-1"><?php echo number_format($stats['customers']); ?></p>
                                <p class="text-xs text-gray-400 mt-1">
                                    <?php if ($stats['new_today'] > 0): ?>
                                        <span class="text-green-600 font-semibold">+<?php echo $stats['new_today']; ?> today</span>
                                        &nbsp;·&nbsp;
                                    <?php endif; ?>
                                    <?php echo $stats['staff']; ?> staff
                                </p>
                            </div>
                            <div class="w-10 h-10 rounded-xl bg-purple-50 flex items-center justify-center flex-shrink-0">
                                <i data-lucide="users" class="w-5 h-5 text-purple-600"></i>
                            </div>
                        </div>
                    </div>

                </div>


                <!-- KPI ROW 2  — Products / Low Stock / Warranties / Repair Revenue   -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">

                    <!-- Products -->
                    <div class="stat-card bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-8 h-8 rounded-lg bg-brand-red/10 flex items-center justify-center">
                                <i data-lucide="package" class="w-4 h-4 text-brand-red"></i>
                            </div>
                            <p class="text-sm font-semibold text-gray-600">Products</p>
                        </div>
                        <p class="text-2xl font-extrabold text-gray-900"><?php echo $stats['total_products']; ?></p>
                        <div class="flex gap-3 mt-2 text-xs text-gray-400">
                            <span class="text-green-600 font-medium"><?php echo $stats['available_products']; ?> active</span>
                            <span><?php echo $stats['out_of_stock']; ?> OOS</span>
                        </div>
                    </div>

                    <!-- Low Stock -->
                    <div class="stat-card bg-white rounded-2xl border <?php echo $stats['low_stock_count'] > 0 ? 'border-amber-200' : 'border-gray-200'; ?> p-5 shadow-sm">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-8 h-8 rounded-lg <?php echo $stats['low_stock_count'] > 0 ? 'bg-amber-50' : 'bg-gray-100'; ?> flex items-center justify-center">
                                <i data-lucide="alert-triangle" class="w-4 h-4 <?php echo $stats['low_stock_count'] > 0 ? 'text-amber-600' : 'text-gray-400'; ?>"></i>
                            </div>
                            <p class="text-sm font-semibold text-gray-600">Low Stock</p>
                        </div>
                        <p class="text-2xl font-extrabold <?php echo $stats['low_stock_count'] > 0 ? 'text-amber-600' : 'text-gray-900'; ?>">
                            <?php echo $stats['low_stock_count']; ?>
                        </p>
                        <p class="text-xs text-gray-400 mt-2">products need restocking</p>
                    </div>

                    <!-- Warranties -->
                    <div class="stat-card bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-8 h-8 rounded-lg bg-emerald-50 flex items-center justify-center">
                                <i data-lucide="shield-check" class="w-4 h-4 text-emerald-600"></i>
                            </div>
                            <p class="text-sm font-semibold text-gray-600">Warranties</p>
                        </div>
                        <p class="text-2xl font-extrabold text-gray-900"><?php echo $stats['active_warranties']; ?></p>
                        <p class="text-xs text-gray-400 mt-2"><?php echo $stats['claimed_warranties']; ?> claimed</p>
                    </div>

                    <!-- Repair Revenue -->
                    <div class="stat-card bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-8 h-8 rounded-lg bg-indigo-50 flex items-center justify-center">
                                <i data-lucide="banknote" class="w-4 h-4 text-indigo-600"></i>
                            </div>
                            <p class="text-sm font-semibold text-gray-600">Repair Revenue</p>
                        </div>
                        <p class="text-2xl font-extrabold text-gray-900">
                            Rs.<?php echo number_format($stats['repair_revenue'], 0); ?>
                        </p>
                        <p class="text-xs text-gray-400 mt-2"><?php echo $stats['in_progress_repairs']; ?> in progress</p>
                    </div>

                </div>


                <!-- MAIN GRID  — Recent Orders  +  Low Stock  (side by side)  -->
                <div class="grid grid-cols-1 xl:grid-cols-5 gap-6">

                    <!-- Recent Orders  (3/5 width) -->
                    <div class="xl:col-span-3 bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">

                        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                            <div>
                                <h3 class="text-base font-bold text-gray-900">Recent Orders</h3>
                                <p class="text-xs text-gray-400 mt-0.5">Latest <?php echo count($recentOrders); ?> orders</p>
                            </div>
                            <a
                                href="<?php echo APP_URL; ?>/admin/orders/index.php"
                                class="text-xs font-semibold text-brand-red hover:underline flex items-center gap-1">
                                View all <i data-lucide="arrow-right" class="w-3 h-3"></i>
                            </a>
                        </div>

                        <?php if (!empty($recentOrders)): ?>

                            <div class="table-wrap">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="bg-gray-50 border-b border-gray-100">
                                            <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Order</th>
                                            <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Customer</th>
                                            <th class="text-right px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Amount</th>
                                            <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                                            <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Payment</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-50">
                                        <?php foreach ($recentOrders as $order): ?>
                                            <tr class="hover:bg-gray-50 transition-colors">
                                                <td class="px-6 py-3.5">
                                                    <a
                                                        href="<?php echo APP_URL; ?>/admin/orders/view.php?id=<?php echo $order['id']; ?>"
                                                        class="font-semibold text-gray-900 hover:text-brand-red transition text-xs">
                                                        #<?php echo htmlspecialchars($order['order_number']); ?>
                                                    </a>
                                                    <p class="text-[10px] text-gray-400 mt-0.5">
                                                        <?php echo date('d M Y', strtotime($order['created_at'])); ?>
                                                    </p>
                                                </td>
                                                <td class="px-4 py-3.5 text-xs text-gray-700 font-medium truncate max-w-[120px]">
                                                    <?php echo htmlspecialchars($order['customer_name']); ?>
                                                </td>
                                                <td class="px-4 py-3.5 text-right text-xs font-bold text-gray-900">
                                                    Rs.<?php echo number_format((float)$order['total_amount'], 2); ?>
                                                </td>
                                                <td class="px-4 py-3.5 text-center">
                                                    <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold <?php echo orderStatusBadge($order['order_status']); ?>">
                                                        <?php echo ucfirst($order['order_status']); ?>
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3.5 text-center">
                                                    <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold <?php echo paymentBadge($order['payment_status']); ?>">
                                                        <?php echo ucfirst($order['payment_status']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                        <?php else: ?>
                            <div class="flex flex-col items-center justify-center py-12 text-gray-400">
                                <i data-lucide="inbox" class="w-10 h-10 mb-3"></i>
                                <p class="text-sm">No orders yet.</p>
                            </div>
                        <?php endif; ?>

                    </div>


                    <!-- Low Stock Alert Panel  (2/5 width) -->
                    <div class="xl:col-span-2 bg-white rounded-2xl border <?php echo !empty($lowStockProducts) ? 'border-amber-200' : 'border-gray-200'; ?> shadow-sm overflow-hidden">

                        <div class="flex items-center justify-between px-5 py-4 border-b <?php echo !empty($lowStockProducts) ? 'border-amber-100 bg-amber-50' : 'border-gray-100'; ?>">
                            <div class="flex items-center gap-2">
                                <i data-lucide="alert-triangle" class="w-4 h-4 <?php echo !empty($lowStockProducts) ? 'text-amber-600' : 'text-gray-400'; ?>"></i>
                                <h3 class="text-base font-bold <?php echo !empty($lowStockProducts) ? 'text-amber-900' : 'text-gray-900'; ?>">Low Stock</h3>
                            </div>
                            <a
                                href="<?php echo APP_URL; ?>/admin/inventory/index.php"
                                class="text-xs font-semibold text-brand-red hover:underline flex items-center gap-1">
                                Manage <i data-lucide="arrow-right" class="w-3 h-3"></i>
                            </a>
                        </div>

                        <?php if (!empty($lowStockProducts)): ?>

                            <ul class="divide-y divide-gray-100">
                                <?php foreach ($lowStockProducts as $lsp): ?>
                                    <li class="flex items-center gap-3 px-5 py-3.5 hover:bg-gray-50 transition-colors">
                                        <div class="w-8 h-8 rounded-lg bg-amber-100 flex items-center justify-center flex-shrink-0">
                                            <i data-lucide="package" class="w-4 h-4 text-amber-600"></i>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-semibold text-gray-800 truncate">
                                                <?php echo htmlspecialchars($lsp['name']); ?>
                                            </p>
                                            <p class="text-[11px] text-gray-400">
                                                <?php echo htmlspecialchars($lsp['category']); ?>
                                            </p>
                                        </div>
                                        <div class="text-right flex-shrink-0">
                                            <span class="inline-block px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[11px] font-bold">
                                                <?php echo $lsp['stock_quantity']; ?> left
                                            </span>
                                            <p class="text-[10px] text-gray-400 mt-0.5">
                                                min <?php echo $lsp['low_stock_threshold']; ?>
                                            </p>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>

                        <?php else: ?>
                            <div class="flex flex-col items-center justify-center py-12 text-gray-400">
                                <i data-lucide="check-circle" class="w-10 h-10 mb-3 text-green-400"></i>
                                <p class="text-sm font-medium text-green-600">All products are well stocked!</p>
                            </div>
                        <?php endif; ?>

                    </div>

                </div>


                <!-- RECENT REPAIRS  -->
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">

                    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                        <div>
                            <h3 class="text-base font-bold text-gray-900">Recent Repairs</h3>
                            <p class="text-xs text-gray-400 mt-0.5">Latest <?php echo count($recentRepairs); ?> repair jobs</p>
                        </div>
                        <a
                            href="<?php echo APP_URL; ?>/admin/repairs/index.php"
                            class="text-xs font-semibold text-brand-red hover:underline flex items-center gap-1">
                            View all <i data-lucide="arrow-right" class="w-3 h-3"></i>
                        </a>
                    </div>

                    <?php if (!empty($recentRepairs)): ?>

                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x divide-gray-100">
                            <?php foreach ($recentRepairs as $repair): ?>
                                <div class="flex items-start gap-3 px-5 py-4 hover:bg-gray-50 transition-colors">
                                    <div class="w-9 h-9 rounded-xl bg-gray-100 flex items-center justify-center flex-shrink-0 mt-0.5">
                                        <i data-lucide="smartphone" class="w-4 h-4 text-gray-500"></i>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-xs font-bold text-brand-red">
                                            <?php echo htmlspecialchars($repair['repair_code']); ?>
                                        </p>
                                        <p class="text-sm font-semibold text-gray-900 truncate mt-0.5">
                                            <?php echo htmlspecialchars($repair['device_model']); ?>
                                        </p>
                                        <p class="text-xs text-gray-400 truncate">
                                            <?php echo htmlspecialchars($repair['customer_name']); ?>
                                        </p>
                                        <div class="flex items-center gap-2 mt-1.5">
                                            <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold <?php echo repairStatusBadge($repair['repair_status']); ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $repair['repair_status'])); ?>
                                            </span>
                                            <span class="text-[10px] text-gray-400">
                                                <?php echo date('d M', strtotime($repair['created_at'])); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <a
                                        href="<?php echo APP_URL; ?>/admin/repairs/view.php?id=<?php echo $repair['id']; ?>"
                                        class="flex-shrink-0 text-gray-300 hover:text-brand-red transition mt-1">
                                        <i data-lucide="arrow-up-right" class="w-4 h-4"></i>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>

                    <?php else: ?>
                        <div class="flex flex-col items-center justify-center py-12 text-gray-400">
                            <i data-lucide="wrench" class="w-10 h-10 mb-3"></i>
                            <p class="text-sm">No repairs recorded yet.</p>
                        </div>
                    <?php endif; ?>

                </div>


                <!-- MONTHLY REVENUE BAR CHART  (pure CSS bars)  -->
                <?php if (!empty($monthlyRevenue)): ?>

                    <?php
                    $maxRev = max(array_column($monthlyRevenue, 'revenue'));
                    if ($maxRev <= 0) $maxRev = 1;
                    ?>

                    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">

                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h3 class="text-base font-bold text-gray-900">Revenue — Last 6 Months</h3>
                                <p class="text-xs text-gray-400 mt-0.5">Excluding cancelled orders</p>
                            </div>
                            <a
                                href="<?php echo APP_URL; ?>/admin/reports/sales-report.php"
                                class="text-xs font-semibold text-brand-red hover:underline flex items-center gap-1">
                                Full report <i data-lucide="arrow-right" class="w-3 h-3"></i>
                            </a>
                        </div>

                        <div class="flex items-end gap-3 h-40">
                            <?php foreach ($monthlyRevenue as $m): ?>
                                <?php
                                $pct    = ($maxRev > 0) ? (float)$m['revenue'] / $maxRev * 100 : 0;
                                $height = max(4, round($pct));
                                ?>
                                <div class="flex flex-col items-center gap-1.5 flex-1">
                                    <span class="text-[10px] font-semibold text-gray-500">
                                        Rs.<?php echo number_format((float)$m['revenue'] / 1000, 1); ?>k
                                    </span>
                                    <div class="w-full rounded-t-lg bg-brand-red/90 hover:bg-brand-red transition-all duration-300"
                                        style="height:<?php echo $height; ?>%"></div>
                                    <span class="text-[10px] text-gray-400 text-center leading-tight">
                                        <?php echo htmlspecialchars($m['month_label']); ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                    </div>

                <?php endif; ?>


                <!--  QUICK ACTION TILES  -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">

                    <?php
                    $tiles = [
                        ['icon' => 'package',        'label' => 'Add Product',    'url' => APP_URL . '/admin/products/add.php',      'color' => 'text-brand-red bg-red-50'],
                        ['icon' => 'users',          'label' => 'Add Staff',      'url' => APP_URL . '/admin/users/add-staff.php',   'color' => 'text-purple-600 bg-purple-50'],
                        ['icon' => 'warehouse',      'label' => 'Inventory',      'url' => APP_URL . '/admin/inventory/index.php',   'color' => 'text-amber-600 bg-amber-50'],
                        ['icon' => 'bar-chart-2',    'label' => 'Sales Report',   'url' => APP_URL . '/admin/reports/sales-report.php', 'color' => 'text-blue-600 bg-blue-50'],
                        ['icon' => 'shield-check',   'label' => 'Warranties',     'url' => APP_URL . '/admin/warranties/index.php',  'color' => 'text-emerald-600 bg-emerald-50'],
                        ['icon' => 'message-circle', 'label' => 'Inquiries',      'url' => APP_URL . '/admin/inquiries/index.php',   'color' => 'text-sky-600 bg-sky-50'],
                    ];
                    ?>

                    <?php foreach ($tiles as $tile): ?>
                        <a
                            href="<?php echo $tile['url']; ?>"
                            class="bg-white rounded-2xl border border-gray-200 shadow-sm p-4 flex flex-col items-center gap-2 hover:shadow-md hover:border-gray-300 transition group">
                            <div class="w-10 h-10 rounded-xl <?php echo $tile['color']; ?> flex items-center justify-center group-hover:scale-110 transition-transform">
                                <i data-lucide="<?php echo $tile['icon']; ?>" class="w-5 h-5"></i>
                            </div>
                            <p class="text-xs font-semibold text-gray-700 text-center leading-tight">
                                <?php echo $tile['label']; ?>
                            </p>
                        </a>
                    <?php endforeach; ?>

                </div>


                <!-- Footer -->
                <p class="text-center text-xs text-gray-400 pb-2">
                    <?php echo APP_NAME; ?> &mdash; Admin Panel &mdash; v<?php echo APP_VERSION; ?>
                </p>

            </main>

        </div><!-- /main content -->

</div><!-- /flex wrapper -->

<script>lucide.createIcons();</script>

</body>

</html>