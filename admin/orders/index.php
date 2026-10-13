<?php

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/mailer.php';

requireAuth(['admin']);

$currentUser = getCurrentUser();
$pdo         = Database::getConnection();

/* ── POST Handler: Quick Status & Payment Update ───────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Security check failed. Please try again.');
    } else {
        $action = trim($_POST['action'] ?? '');
        $orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);

        if ($orderId && $orderId > 0) {
            if ($action === 'update_order_status') {
                $newStatus = trim($_POST['order_status'] ?? '');

                $validStatuses = [
                    'placed',
                    'processing',
                    'shipped',
                    'delivered',
                    'cancelled'
                ];

                if (in_array($newStatus, $validStatuses, true)) {
                    try {
                        // Get the current order status + payment_status first
                        $checkStmt = $pdo->prepare("
                SELECT order_status, payment_status
                FROM orders
                WHERE id = :id
                LIMIT 1
            ");
                        $checkStmt->execute([':id' => $orderId]);

                        $currentRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
                        $currentStatus = $currentRow['order_status'] ?? false;
                        $currentPaymentStatus = $currentRow['payment_status'] ?? 'pending';

                        if ($currentStatus === false) {
                            setFlash('error', 'Order not found.');
                        }
                        // Cancelled orders are final and cannot be reactivated
                        elseif ($currentStatus === 'cancelled') {
                            setFlash(
                                'error',
                                "Order #{$orderId} is already cancelled and cannot be reactivated."
                            );
                        } else {
                            $stmt = $pdo->prepare("
                    UPDATE orders
                    SET order_status = :status,
                        updated_at = NOW()
                    WHERE id = :id
                ");

                            $stmt->execute([
                                ':status' => $newStatus,
                                ':id'     => $orderId
                            ]);

                            setFlash(
                                'success',
                                "Order #{$orderId} status updated to " . ucfirst($newStatus) . '.'
                            );

                            // Send status update email to customer
                            sendOrderStatusUpdateEmail($orderId, $newStatus, $currentPaymentStatus);
                        }
                    } catch (PDOException $e) {
                        error_log('Error updating order status: ' . $e->getMessage());
                        setFlash('error', 'Database error updating order status.');
                    }
                } else {
                    setFlash('error', 'Invalid status value.');
                }
            } elseif ($action === 'update_payment_status') {
                $newPayment = trim($_POST['payment_status'] ?? '');
                $validPayments = ['pending', 'paid', 'failed', 'refunded'];

                if (in_array($newPayment, $validPayments, true)) {
                    try {
                        // Fetch current order_status to pass to email function
                        $curStmt = $pdo->prepare("SELECT order_status FROM orders WHERE id = :id LIMIT 1");
                        $curStmt->execute([':id' => $orderId]);
                        $currentOrderStatus = (string)($curStmt->fetchColumn() ?: 'placed');

                        $stmt = $pdo->prepare("UPDATE orders SET payment_status = :payment, updated_at = NOW() WHERE id = :id");
                        $stmt->execute([':payment' => $newPayment, ':id' => $orderId]);
                        setFlash('success', "Order #{$orderId} payment status updated to " . ucfirst($newPayment) . '.');

                        // Send payment status update email to customer
                        sendOrderStatusUpdateEmail($orderId, $currentOrderStatus, $newPayment);

                    } catch (PDOException $e) {
                        error_log('Error updating payment status: ' . $e->getMessage());
                        setFlash('error', 'Database error updating payment status.');
                    }
                } else {
                    setFlash('error', 'Invalid payment status value.');
                }
            }
        }
    }

    // Preserve query parameters on redirect
    $redirectUrl = APP_URL . '/admin/orders/index.php';
    if (!empty($_SERVER['QUERY_STRING'])) {
        $redirectUrl .= '?' . $_SERVER['QUERY_STRING'];
    }
    header('Location: ' . $redirectUrl);
    exit;
}

/* ── Filter & Search Parameters ────────────────────────────────────── */
$search    = trim($_GET['search'] ?? '');
$status    = trim($_GET['status'] ?? '');
$payment   = trim($_GET['payment'] ?? '');
$sort      = trim($_GET['sort'] ?? 'newest');
$page      = max(1, (int) ($_GET['page'] ?? 1));
$perPage   = 15;
$offset    = ($page - 1) * $perPage;

/* ── Build Query ───────────────────────────────────────────────────── */
$whereClauses = [];
$params       = [];

if ($search !== '') {
    $cleanSearch = trim($search, "# \t\n\r\0\x0B");
    $whereClauses[] = "(
        o.order_number LIKE ?
        OR u.name LIKE ?
        OR u.email LIKE ?
        OR o.contact_phone LIKE ?
        OR CAST(o.id AS CHAR) = ?
        OR EXISTS (
            SELECT 1 FROM order_items oi
            JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id = o.id AND p.name LIKE ?
        )
    )";
    $like = '%' . $cleanSearch . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $cleanSearch;
    $params[] = $like;
}

if ($status !== '' && in_array($status, ['placed', 'processing', 'shipped', 'delivered', 'cancelled'], true)) {
    $whereClauses[] = "o.order_status = ?";
    $params[] = $status;
}

if ($payment !== '' && in_array($payment, ['pending', 'paid', 'failed', 'refunded'], true)) {
    $whereClauses[] = "o.payment_status = ?";
    $params[] = $payment;
}

$whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

/* Sorting */
$orderSql = match ($sort) {
    'oldest'      => 'ORDER BY o.created_at ASC',
    'amount_high' => 'ORDER BY o.total_amount DESC',
    'amount_low'  => 'ORDER BY o.total_amount ASC',
    default       => 'ORDER BY o.created_at DESC',
};

/* ── Execute Count Query ───────────────────────────────────────────── */
try {
    $countSql = "
        SELECT COUNT(*)
        FROM orders o
        LEFT JOIN users u ON u.id = o.user_id
        $whereSql
    ";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalOrders = (int) $countStmt->fetchColumn();
} catch (PDOException $e) {
    error_log('Orders count error: ' . $e->getMessage());
    $totalOrders = 0;
}

$totalPages = max(1, (int) ceil($totalOrders / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

/* ── Fetch Paginated Orders ────────────────────────────────────────── */
try {
    $dataSql = "
        SELECT
            o.id,
            o.order_number,
            o.user_id,
            o.total_amount,
            o.payment_status,
            o.order_status,
            o.shipping_address,
            o.contact_phone,
            o.notes,
            o.created_at,
            o.updated_at,
            u.name  AS customer_name,
            u.email AS customer_email,
            COALESCE((
                SELECT SUM(quantity)
                FROM order_items
                WHERE order_id = o.id
            ), 0) AS total_items
        FROM orders o
        LEFT JOIN users u ON u.id = o.user_id
        $whereSql
        $orderSql
        LIMIT $perPage OFFSET $offset
    ";

    $stmt = $pdo->prepare($dataSql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Orders query error: ' . $e->getMessage());
    $orders = [];
}

/* ── Summary Statistics ────────────────────────────────────────────── */
try {
    $summarySql = "
        SELECT
            COUNT(*) AS total_count,
            COUNT(CASE WHEN order_status = 'placed' THEN 1 END) AS placed_count,
            COUNT(CASE WHEN order_status = 'processing' THEN 1 END) AS processing_count,
            COUNT(CASE WHEN order_status = 'shipped' THEN 1 END) AS shipped_count,
            COUNT(CASE WHEN order_status = 'delivered' THEN 1 END) AS delivered_count,
            COUNT(CASE WHEN order_status = 'cancelled' THEN 1 END) AS cancelled_count,
            COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END), 0) AS total_revenue
        FROM orders
    ";
    $stats = $pdo->query($summarySql)->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Orders summary error: ' . $e->getMessage());
    $stats = [
        'total_count'      => 0,
        'placed_count'     => 0,
        'processing_count' => 0,
        'shipped_count'    => 0,
        'delivered_count'  => 0,
        'cancelled_count'  => 0,
        'total_revenue'    => 0,
    ];
}

/* ── Helpers for Badges ────────────────────────────────────────────── */
function orderStatusBadgeClass(string $status): string
{
    return match ($status) {
        'placed'     => 'bg-blue-100 text-blue-700 border-blue-200',
        'processing' => 'bg-amber-100 text-amber-700 border-amber-200',
        'shipped'    => 'bg-purple-100 text-purple-700 border-purple-200',
        'delivered'  => 'bg-emerald-100 text-emerald-700 border-emerald-200',
        'cancelled'  => 'bg-rose-100 text-rose-700 border-rose-200',
        default      => 'bg-gray-100 text-gray-700 border-gray-200',
    };
}

function paymentStatusBadgeClass(string $status): string
{
    return match ($status) {
        'paid'     => 'bg-emerald-100 text-emerald-700 border-emerald-200',
        'pending'  => 'bg-amber-100 text-amber-700 border-amber-200',
        'failed'   => 'bg-rose-100 text-rose-700 border-rose-200',
        'refunded' => 'bg-gray-100 text-gray-700 border-gray-200',
        default    => 'bg-gray-100 text-gray-700 border-gray-200',
    };
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders Management | <?php echo APP_NAME; ?> Admin</title>

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
    <script>
        window.__APP_URL__ = '<?php echo rtrim(APP_URL, '/'); ?>';
    </script>
    <script src="<?php echo APP_URL; ?>/assets/js/main.js" defer></script>
</head>

<body class="bg-gray-100 text-gray-800 font-sans antialiased">

    <div class="flex min-h-screen">

        <!-- SIDEBAR -->
        <?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>

        <!-- MAIN CONTENT -->
        <div class="flex-1 flex flex-col min-w-0">

            <!-- TOP BAR -->
            <header class="bg-white border-b border-gray-200 sticky top-0 z-20 flex items-center gap-4 px-6 h-16 flex-shrink-0">

                <button id="sidebar-open-btn" type="button"
                    class="lg:hidden text-gray-500 hover:text-gray-900" aria-label="Open sidebar">
                    <i data-lucide="menu" class="w-5 h-5"></i>
                </button>

                <!-- Breadcrumbs -->
                <div class="flex-1 min-w-0 flex items-center gap-2 text-sm">
                    <span class="text-gray-400 font-medium hidden sm:inline">Admin</span>
                    <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-gray-300 hidden sm:inline flex-shrink-0"></i>
                    <span class="font-bold text-gray-900">Orders Management</span>
                </div>

                <div class="flex items-center gap-3">
                    <span class="text-xs text-gray-500 hidden sm:inline">
                        <?php echo number_format($totalOrders); ?> order<?php echo $totalOrders === 1 ? '' : 's'; ?>
                    </span>

                    <!-- Avatar -->
                    <div class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center text-white text-sm font-bold flex-shrink-0">
                        <?php echo strtoupper(substr($currentUser['name'] ?? 'A', 0, 1)); ?>
                    </div>
                </div>

            </header>

            <!-- PAGE BODY -->
            <main class="flex-1 p-5 sm:p-7 overflow-auto">

                <!-- Flash messages -->
                <?php displayFlash(); ?>

                <!-- PAGE TITLE & HEADER -->
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                    <div>
                        <h1 class="text-2xl font-extrabold text-gray-900 tracking-tight">Orders</h1>
                        <p class="text-sm text-gray-500 mt-0.5">Track, process, and manage customer shipments &amp; payments.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="<?php echo APP_URL; ?>/admin/orders/index.php"
                            class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl border border-gray-200 bg-white text-gray-600 hover:bg-gray-50 text-xs font-semibold transition">
                            <i data-lucide="rotate-cw" class="w-3.5 h-3.5"></i>
                            Refresh
                        </a>
                    </div>
                </div>

                <!-- ── SUMMARY KPI STAT CARDS ─────────────────────────────── -->
                <div class="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-6 gap-3.5 mb-7">

                    <!-- Total Orders -->
                    <a href="<?php echo APP_URL; ?>/admin/orders/index.php"
                        class="bg-white rounded-2xl border border-gray-200 p-4 shadow-sm hover:border-gray-300 transition group flex flex-col justify-between">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-semibold text-gray-500">All Orders</span>
                            <div class="w-7 h-7 rounded-lg bg-gray-100 flex items-center justify-center text-gray-500 group-hover:bg-gray-200 transition">
                                <i data-lucide="shopping-bag" class="w-3.5 h-3.5"></i>
                            </div>
                        </div>
                        <p class="text-2xl font-extrabold text-gray-900"><?php echo number_format($stats['total_count']); ?></p>
                    </a>

                    <!-- Placed / New -->
                    <a href="<?php echo APP_URL; ?>/admin/orders/index.php?status=placed"
                        class="bg-white rounded-2xl border <?php echo $status === 'placed' ? 'border-blue-500 ring-2 ring-blue-500/20' : 'border-gray-200'; ?> p-4 shadow-sm hover:border-blue-300 transition group flex flex-col justify-between">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-semibold text-blue-600">New Placed</span>
                            <div class="w-7 h-7 rounded-lg bg-blue-50 flex items-center justify-center text-blue-600 group-hover:bg-blue-100 transition">
                                <i data-lucide="clock" class="w-3.5 h-3.5"></i>
                            </div>
                        </div>
                        <p class="text-2xl font-extrabold text-blue-700"><?php echo number_format($stats['placed_count']); ?></p>
                    </a>

                    <!-- Processing -->
                    <a href="<?php echo APP_URL; ?>/admin/orders/index.php?status=processing"
                        class="bg-white rounded-2xl border <?php echo $status === 'processing' ? 'border-amber-500 ring-2 ring-amber-500/20' : 'border-gray-200'; ?> p-4 shadow-sm hover:border-amber-300 transition group flex flex-col justify-between">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-semibold text-amber-600">Processing</span>
                            <div class="w-7 h-7 rounded-lg bg-amber-50 flex items-center justify-center text-amber-600 group-hover:bg-amber-100 transition">
                                <i data-lucide="box" class="w-3.5 h-3.5"></i>
                            </div>
                        </div>
                        <p class="text-2xl font-extrabold text-amber-700"><?php echo number_format($stats['processing_count']); ?></p>
                    </a>

                    <!-- Shipped -->
                    <a href="<?php echo APP_URL; ?>/admin/orders/index.php?status=shipped"
                        class="bg-white rounded-2xl border <?php echo $status === 'shipped' ? 'border-purple-500 ring-2 ring-purple-500/20' : 'border-gray-200'; ?> p-4 shadow-sm hover:border-purple-300 transition group flex flex-col justify-between">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-semibold text-purple-600">Shipped</span>
                            <div class="w-7 h-7 rounded-lg bg-purple-50 flex items-center justify-center text-purple-600 group-hover:bg-purple-100 transition">
                                <i data-lucide="truck" class="w-3.5 h-3.5"></i>
                            </div>
                        </div>
                        <p class="text-2xl font-extrabold text-purple-700"><?php echo number_format($stats['shipped_count']); ?></p>
                    </a>

                    <!-- Delivered -->
                    <a href="<?php echo APP_URL; ?>/admin/orders/index.php?status=delivered"
                        class="bg-white rounded-2xl border <?php echo $status === 'delivered' ? 'border-emerald-500 ring-2 ring-emerald-500/20' : 'border-gray-200'; ?> p-4 shadow-sm hover:border-emerald-300 transition group flex flex-col justify-between">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-semibold text-emerald-600">Delivered</span>
                            <div class="w-7 h-7 rounded-lg bg-emerald-50 flex items-center justify-center text-emerald-600 group-hover:bg-emerald-100 transition">
                                <i data-lucide="check-circle" class="w-3.5 h-3.5"></i>
                            </div>
                        </div>
                        <p class="text-2xl font-extrabold text-emerald-700"><?php echo number_format($stats['delivered_count']); ?></p>
                    </a>

                    <!-- Revenue -->
                    <div class="bg-white rounded-2xl border border-gray-200 p-4 shadow-sm flex flex-col justify-between">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-semibold text-gray-500">Paid Revenue</span>
                            <div class="w-7 h-7 rounded-lg bg-emerald-50 flex items-center justify-center text-emerald-600">
                                <i data-lucide="dollar-sign" class="w-3.5 h-3.5"></i>
                            </div>
                        </div>
                        <p class="text-xl font-extrabold text-gray-900 truncate" title="Rs. <?php echo number_format($stats['total_revenue'], 2); ?>">
                            Rs.<?php echo number_format($stats['total_revenue'], 0); ?>
                        </p>
                    </div>

                </div>

                <!-- ── FILTERS & SEARCH BAR ───────────────────────────────── -->
                <form method="GET" action="<?php echo APP_URL; ?>/admin/orders/index.php" class="bg-white rounded-2xl border border-gray-200 shadow-sm p-4 sm:p-5 mb-6">

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-3">

                        <!-- Search Input -->
                        <div class="lg:col-span-5">
                            <label for="search" class="sr-only">Search orders</label>
                            <div class="relative">
                                <i data-lucide="search" class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400"></i>
                                <input
                                    type="search"
                                    id="search"
                                    name="search"
                                    value="<?php echo htmlspecialchars($search); ?>"
                                    placeholder="Search by Order #, Customer Name, Email, Phone..."
                                    class="w-full h-10 pl-10 pr-24 rounded-xl border border-gray-200 bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/10 outline-none text-xs sm:text-sm transition">
                                <button type="submit"
                                    class="absolute right-1.5 top-1/2 -translate-y-1/2 h-7 px-2.5 rounded-lg bg-brand-red hover:bg-brand-redHover text-white text-xs font-bold transition">
                                    Search
                                </button>
                            </div>
                        </div>

                        <!-- Order Status Filter -->
                        <div class="lg:col-span-2">
                            <select
                                name="status"
                                onchange="this.form.submit()"
                                class="w-full h-10 px-3 rounded-xl border border-gray-200 bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/10 outline-none text-xs sm:text-sm transition text-gray-700">
                                <option value="">All Order Statuses</option>
                                <option value="placed" <?php echo $status === 'placed'     ? 'selected' : ''; ?>>Placed (New)</option>
                                <option value="processing" <?php echo $status === 'processing' ? 'selected' : ''; ?>>Processing</option>
                                <option value="shipped" <?php echo $status === 'shipped'    ? 'selected' : ''; ?>>Shipped</option>
                                <option value="delivered" <?php echo $status === 'delivered'  ? 'selected' : ''; ?>>Delivered</option>
                                <option value="cancelled" <?php echo $status === 'cancelled'  ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>

                        <!-- Payment Status Filter -->
                        <div class="lg:col-span-2">
                            <select
                                name="payment"
                                onchange="this.form.submit()"
                                class="w-full h-10 px-3 rounded-xl border border-gray-200 bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/10 outline-none text-xs sm:text-sm transition text-gray-700">
                                <option value="">All Payments</option>
                                <option value="pending" <?php echo $payment === 'pending'  ? 'selected' : ''; ?>>Pending</option>
                                <option value="paid" <?php echo $payment === 'paid'     ? 'selected' : ''; ?>>Paid</option>
                                <option value="failed" <?php echo $payment === 'failed'   ? 'selected' : ''; ?>>Failed</option>
                                <option value="refunded" <?php echo $payment === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                            </select>
                        </div>

                        <!-- Sort -->
                        <div class="lg:col-span-3">
                            <select
                                name="sort"
                                onchange="this.form.submit()"
                                class="w-full h-10 px-3 rounded-xl border border-gray-200 bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/10 outline-none text-xs sm:text-sm transition text-gray-700">
                                <option value="newest" <?php echo $sort === 'newest'      ? 'selected' : ''; ?>>Sort: Newest First</option>
                                <option value="oldest" <?php echo $sort === 'oldest'      ? 'selected' : ''; ?>>Sort: Oldest First</option>
                                <option value="amount_high" <?php echo $sort === 'amount_high' ? 'selected' : ''; ?>>Sort: Total (High to Low)</option>
                                <option value="amount_low" <?php echo $sort === 'amount_low'  ? 'selected' : ''; ?>>Sort: Total (Low to High)</option>
                            </select>
                        </div>

                    </div>

                    <!-- Active Filter Tags & Reset -->
                    <?php if ($search !== '' || $status !== '' || $payment !== '' || $sort !== 'newest'): ?>
                        <div class="flex flex-wrap items-center justify-between gap-2 mt-4 pt-3 border-t border-gray-100">
                            <div class="flex flex-wrap items-center gap-1.5 text-xs">
                                <span class="text-gray-400 font-medium">Active filters:</span>
                                <?php if ($search !== ''): ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-gray-100 text-gray-700 font-medium">
                                        Search: "<?php echo htmlspecialchars($search); ?>"
                                    </span>
                                <?php endif; ?>
                                <?php if ($status !== ''): ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-blue-50 text-blue-700 font-medium">
                                        Status: <?php echo ucfirst($status); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($payment !== ''): ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-emerald-50 text-emerald-700 font-medium">
                                        Payment: <?php echo ucfirst($payment); ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <a href="<?php echo APP_URL; ?>/admin/orders/index.php"
                                class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-red hover:underline">
                                <i data-lucide="x" class="w-3.5 h-3.5"></i>
                                Clear all filters
                            </a>
                        </div>
                    <?php endif; ?>

                </form>

                <!-- ── ORDERS TABLE ───────────────────────────────────────── -->
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden mb-6">

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead>
                                <tr class="bg-gray-50 border-b border-gray-200 text-[11px] font-bold text-gray-500 uppercase tracking-wider">
                                    <th class="px-5 py-3.5">Order</th>
                                    <th class="px-4 py-3.5">Customer</th>
                                    <th class="px-4 py-3.5 text-center">Items</th>
                                    <th class="px-4 py-3.5 text-right">Total Amount</th>
                                    <th class="px-4 py-3.5 text-center">Payment</th>
                                    <th class="px-4 py-3.5 text-center">Order Status</th>
                                    <th class="px-4 py-3.5 text-center">Quick Update</th>
                                    <th class="px-5 py-3.5 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php if (!empty($orders)): ?>
                                    <?php foreach ($orders as $ord): ?>
                                        <tr class="hover:bg-gray-50/80 transition-colors group">

                                            <!-- Order Number & Date -->
                                            <td class="px-5 py-3.5">
                                                <a href="<?php echo APP_URL; ?>/admin/orders/view.php?id=<?php echo $ord['id']; ?>"
                                                    class="font-bold text-gray-900 hover:text-brand-red transition text-xs sm:text-sm font-mono block">
                                                    #<?php echo htmlspecialchars($ord['order_number']); ?>
                                                </a>

                                                <span class="text-[11px] text-gray-400 block mt-1">
                                                    <?php echo date('M d, Y h:i A', strtotime($ord['created_at'])); ?>
                                                </span>
                                            </td>

                                            <!-- Customer -->
                                            <td class="px-4 py-3.5">
                                                <div>
                                                    <p class="font-semibold text-gray-900 text-xs sm:text-sm">
                                                        <?php echo htmlspecialchars($ord['customer_name'] ?? 'Unknown'); ?>
                                                    </p>
                                                    <p class="text-[11px] text-gray-400 mt-0.5">
                                                        <?php echo htmlspecialchars($ord['customer_email'] ?? ''); ?>
                                                    </p>
                                                    <p class="text-[11px] text-gray-400">
                                                        <?php echo htmlspecialchars($ord['contact_phone'] ?? ''); ?>
                                                    </p>
                                                </div>
                                            </td>

                                            <!-- Items count -->
                                            <td class="px-4 py-3.5 text-center">
                                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-gray-100 text-gray-700 text-xs font-semibold">
                                                    <i data-lucide="package" class="w-3 h-3 text-gray-400"></i>
                                                    <?php echo (int) $ord['total_items']; ?>
                                                </span>
                                            </td>

                                            <!-- Total -->
                                            <td class="px-4 py-3.5 text-right font-extrabold text-gray-900 text-xs sm:text-sm">
                                                Rs.<?php echo number_format((float) $ord['total_amount'], 2); ?>
                                            </td>

                                            <!-- Payment Status -->
                                            <td class="px-4 py-3.5 text-center">
                                                <span class="inline-block px-2.5 py-0.5 rounded-full text-[11px] font-bold border <?php echo paymentStatusBadgeClass($ord['payment_status']); ?>">
                                                    <?php echo ucfirst($ord['payment_status']); ?>
                                                </span>
                                            </td>

                                            <!-- Order Status -->
                                            <td class="px-4 py-3.5 text-center">
                                                <span class="inline-block px-2.5 py-0.5 rounded-full text-[11px] font-bold border <?php echo orderStatusBadgeClass($ord['order_status']); ?>">
                                                    <?php echo ucfirst($ord['order_status']); ?>
                                                </span>
                                            </td>

                                            <!-- Quick Status Update -->
                                            <td class="px-4 py-3.5 text-center">
                                                <?php if ($ord['order_status'] === 'cancelled'): ?>

                                                    <span
                                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg
                           bg-rose-50 text-rose-700 border border-rose-200
                           text-[11px] font-bold"
                                                        title="Cancelled orders cannot be reactivated">

                                                        <i data-lucide="lock" class="w-3 h-3"></i>
                                                        Cancelled
                                                    </span>

                                                <?php else: ?>

                                                    <form method="POST" action="" class="inline-block">

                                                        <input
                                                            type="hidden"
                                                            name="csrf_token"
                                                            value="<?php echo getCsrfToken(); ?>">

                                                        <input
                                                            type="hidden"
                                                            name="action"
                                                            value="update_order_status">

                                                        <input
                                                            type="hidden"
                                                            name="order_id"
                                                            value="<?php echo $ord['id']; ?>">

                                                        <select
                                                            name="order_status"
                                                            onchange="
                            if (
                                confirm(
                                    'Update status of order #<?php echo htmlspecialchars($ord['order_number']); ?> to ' +
                                    this.options[this.selectedIndex].text +
                                    '?'
                                )
                            ) {
                                this.form.submit();
                            } else {
                                this.value = '<?php echo $ord['order_status']; ?>';
                            }
                        "
                                                            class="text-[11px] font-semibold py-1 px-2 rounded-lg
                               border border-gray-200 bg-white
                               hover:border-gray-300 focus:outline-none
                               focus:ring-1 focus:ring-brand-red
                               transition cursor-pointer">

                                                            <option value="placed"
                                                                <?php echo $ord['order_status'] === 'placed' ? 'selected' : ''; ?>>
                                                                Placed
                                                            </option>

                                                            <option value="processing"
                                                                <?php echo $ord['order_status'] === 'processing' ? 'selected' : ''; ?>>
                                                                Processing
                                                            </option>

                                                            <option value="shipped"
                                                                <?php echo $ord['order_status'] === 'shipped' ? 'selected' : ''; ?>>
                                                                Shipped
                                                            </option>

                                                            <option value="delivered"
                                                                <?php echo $ord['order_status'] === 'delivered' ? 'selected' : ''; ?>>
                                                                Delivered
                                                            </option>

                                                            <option value="cancelled"
                                                                <?php echo $ord['order_status'] === 'cancelled' ? 'selected' : ''; ?>>
                                                                Cancelled
                                                            </option>

                                                        </select>

                                                    </form>

                                                <?php endif; ?>
                                            </td>

                                            <!-- Actions -->
                                            <td class="px-5 py-3.5 text-right">
                                                <div class="flex items-center justify-end gap-1.5">

                                                    <a
                                                        href="<?php echo APP_URL; ?>/admin/orders/view.php?id=<?php echo $ord['id']; ?>"
                                                        title="View order details and invoice"
                                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg
                           border border-gray-200 bg-white hover:bg-gray-50
                           text-gray-700 text-xs font-semibold transition shadow-2xs">

                                                        <i data-lucide="eye" class="w-3.5 h-3.5 text-gray-500"></i>
                                                        <span>View</span>

                                                    </a>

                                                </div>
                                            </td>

                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="py-14 text-center">
                                            <div class="flex flex-col items-center justify-center max-w-sm mx-auto text-gray-400">
                                                <div class="w-14 h-14 rounded-2xl bg-gray-100 flex items-center justify-center mb-3 text-gray-400">
                                                    <i data-lucide="inbox" class="w-7 h-7"></i>
                                                </div>
                                                <p class="font-bold text-gray-800 text-base">No orders found</p>
                                                <p class="text-xs text-gray-500 mt-1">
                                                    <?php echo ($search !== '' || $status !== '' || $payment !== '') ? 'Try clearing your active filters or searching for something else.' : 'No customer orders have been placed yet.'; ?>
                                                </p>
                                                <?php if ($search !== '' || $status !== '' || $payment !== ''): ?>
                                                    <a href="<?php echo APP_URL; ?>/admin/orders/index.php"
                                                        class="mt-4 px-4 py-2 rounded-xl bg-gray-900 text-white text-xs font-semibold hover:bg-gray-800 transition">
                                                        Clear filters
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- ── PAGINATION ─────────────────────────────────────────── -->
                    <?php if ($totalPages > 1): ?>
                        <div class="px-6 py-4 border-t border-gray-100 flex flex-col sm:flex-row items-center justify-between gap-3 bg-gray-50/50">
                            <p class="text-xs text-gray-500 text-center sm:text-left">
                                Showing <span class="font-semibold text-gray-700"><?php echo $offset + 1; ?></span> to
                                <span class="font-semibold text-gray-700"><?php echo min($totalOrders, $offset + $perPage); ?></span>
                                of <span class="font-semibold text-gray-700"><?php echo number_format($totalOrders); ?></span> orders
                            </p>

                            <div class="flex items-center gap-1.5">
                                <?php
                                $queryParams = $_GET;
                                $buildPageUrl = function ($p) use ($queryParams) {
                                    $queryParams['page'] = $p;
                                    return '?' . http_build_query($queryParams);
                                };
                                ?>

                                <!-- Previous -->
                                <?php if ($page > 1): ?>
                                    <a href="<?php echo $buildPageUrl($page - 1); ?>"
                                        class="w-8 h-8 rounded-lg flex items-center justify-center border border-gray-200 bg-white text-gray-600 hover:bg-gray-50 transition text-xs font-medium"
                                        aria-label="Previous page">
                                        <i data-lucide="chevron-left" class="w-4 h-4"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="w-8 h-8 rounded-lg flex items-center justify-center border border-gray-100 text-gray-300 cursor-not-allowed">
                                        <i data-lucide="chevron-left" class="w-4 h-4"></i>
                                    </span>
                                <?php endif; ?>

                                <!-- Page Numbers (smart window) -->
                                <?php
                                $startPage = max(1, $page - 2);
                                $endPage   = min($totalPages, $page + 2);

                                if ($startPage > 1) {
                                    echo '<a href="' . $buildPageUrl(1) . '" class="w-8 h-8 rounded-lg flex items-center justify-center border border-gray-200 bg-white text-gray-700 hover:bg-gray-50 transition text-xs font-semibold">1</a>';
                                    if ($startPage > 2) {
                                        echo '<span class="px-1 text-gray-400 text-xs">…</span>';
                                    }
                                }

                                for ($p = $startPage; $p <= $endPage; $p++):
                                    $isCurrent = ($p === $page);
                                ?>
                                    <a href="<?php echo $buildPageUrl($p); ?>"
                                        class="w-8 h-8 rounded-lg flex items-center justify-center text-xs font-bold transition <?php echo $isCurrent ? 'bg-brand-red text-white shadow-2xs' : 'border border-gray-200 bg-white text-gray-700 hover:bg-gray-50'; ?>">
                                        <?php echo $p; ?>
                                    </a>
                                <?php endfor; ?>

                                <?php
                                if ($endPage < $totalPages) {
                                    if ($endPage < $totalPages - 1) {
                                        echo '<span class="px-1 text-gray-400 text-xs">…</span>';
                                    }
                                    echo '<a href="' . $buildPageUrl($totalPages) . '" class="w-8 h-8 rounded-lg flex items-center justify-center border border-gray-200 bg-white text-gray-700 hover:bg-gray-50 transition text-xs font-semibold">' . $totalPages . '</a>';
                                }
                                ?>

                                <!-- Next -->
                                <?php if ($page < $totalPages): ?>
                                    <a href="<?php echo $buildPageUrl($page + 1); ?>"
                                        class="w-8 h-8 rounded-lg flex items-center justify-center border border-gray-200 bg-white text-gray-600 hover:bg-gray-50 transition text-xs font-medium"
                                        aria-label="Next page">
                                        <i data-lucide="chevron-right" class="w-4 h-4"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="w-8 h-8 rounded-lg flex items-center justify-center border border-gray-100 text-gray-300 cursor-not-allowed">
                                        <i data-lucide="chevron-right" class="w-4 h-4"></i>
                                    </span>
                                <?php endif; ?>

                            </div>
                        </div>
                    <?php endif; ?>

                </div>

                <!-- Footer -->
                <p class="text-center text-xs text-gray-400 pb-2">
                    <?php echo APP_NAME; ?> &mdash; Admin Panel &mdash; v<?php echo APP_VERSION; ?>
                </p>

            </main>

        </div><!-- /main content -->

    </div><!-- /flex wrapper -->

    <script>
        lucide.createIcons();
    </script>

</body>

</html>