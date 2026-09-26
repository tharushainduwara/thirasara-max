<?php

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth(['admin']);

$currentUser = getCurrentUser();
$pdo         = Database::getConnection();

/* ── Order Identification ──────────────────────────────────────────── */
$orderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$orderNumber = trim($_GET['order'] ?? '');

if (!$orderId && $orderNumber === '') {
    setFlash('error', 'No order specified.');
    header('Location: ' . APP_URL . '/admin/orders/index.php');
    exit;
}

/* ── Helper: Resolve image thumbnail URL ───────────────────────────── */
function productThumbUrl(?string $imageUrl): string
{
    if (empty($imageUrl)) {
        return '';
    }
    if (
        str_starts_with($imageUrl, 'http://') ||
        str_starts_with($imageUrl, 'https://') ||
        str_starts_with($imageUrl, '//')
    ) {
        return $imageUrl;
    }
    return APP_URL . '/' . ltrim($imageUrl, '/');
}

/* ── POST Handler: Status & Notes Updates ──────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Security check failed. Please try again.');
    } else {
        $action = trim($_POST['action'] ?? '');
        $targetOrderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);

        if ($targetOrderId && $targetOrderId > 0) {
            if ($action === 'update_order') {
                $newOrderStatus   = trim($_POST['order_status'] ?? '');
                $newPaymentStatus = trim($_POST['payment_status'] ?? '');
                $adminNotes       = trim($_POST['notes'] ?? '');

                $validOrderStatuses   = ['placed', 'processing', 'shipped', 'delivered', 'cancelled'];
                $validPaymentStatuses = ['pending', 'paid', 'failed', 'refunded'];

                if (!in_array($newOrderStatus, $validOrderStatuses, true)) {
                    setFlash('error', 'Invalid order status selected.');
                } elseif (!in_array($newPaymentStatus, $validPaymentStatuses, true)) {
                    setFlash('error', 'Invalid payment status selected.');
                } else {
                    try {
                        $updateStmt = $pdo->prepare("
                            UPDATE orders SET
                                order_status   = :order_status,
                                payment_status = :payment_status,
                                notes          = :notes,
                                updated_at     = NOW()
                            WHERE id = :id
                        ");
                        $updateStmt->execute([
                            ':order_status'   => $newOrderStatus,
                            ':payment_status' => $newPaymentStatus,
                            ':notes'          => $adminNotes !== '' ? $adminNotes : null,
                            ':id'             => $targetOrderId,
                        ]);

                        // Generate invoice record in invoices table if paid or delivered and not exists
                        if ($newPaymentStatus === 'paid' || $newOrderStatus === 'delivered') {
                            $invCheck = $pdo->prepare("SELECT id FROM invoices WHERE order_id = :oid LIMIT 1");
                            $invCheck->execute([':oid' => $targetOrderId]);
                            if (!$invCheck->fetch()) {
                                $invNumber = 'INV-' . date('Ymd') . '-' . sprintf('%04d', $targetOrderId);
                                $totalQuery = $pdo->prepare("SELECT total_amount FROM orders WHERE id = ?");
                                $totalQuery->execute([$targetOrderId]);
                                $tot = (float) $totalQuery->fetchColumn();

                                $invInsert = $pdo->prepare("
                                    INSERT INTO invoices (invoice_number, order_id, invoice_type, total_amount, generated_at)
                                    VALUES (:inv_num, :oid, 'order', :tot, NOW())
                                ");
                                $invInsert->execute([
                                    ':inv_num' => $invNumber,
                                    ':oid'     => $targetOrderId,
                                    ':tot'     => $tot,
                                ]);
                            }
                        }

                        setFlash('success', 'Order details and statuses updated successfully.');
                    } catch (PDOException $e) {
                        error_log('Admin order update error: ' . $e->getMessage());
                        setFlash('error', 'Database error updating order.');
                    }
                }
            }
        }
    }

    $redir = APP_URL . '/admin/orders/view.php?id=' . ($orderId ?: $targetOrderId);
    header('Location: ' . $redir);
    exit;
}

/* ── Fetch Order Details ───────────────────────────────────────────── */
try {
    if ($orderId) {
        $stmt = $pdo->prepare("
            SELECT
                o.*,
                u.name  AS customer_name,
                u.email AS customer_email,
                u.phone AS customer_phone_registered,
                u.address AS customer_address_registered
            FROM orders o
            LEFT JOIN users u ON u.id = o.user_id
            WHERE o.id = ?
            LIMIT 1
        ");
        $stmt->execute([$orderId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT
                o.*,
                u.name  AS customer_name,
                u.email AS customer_email,
                u.phone AS customer_phone_registered,
                u.address AS customer_address_registered
            FROM orders o
            LEFT JOIN users u ON u.id = o.user_id
            WHERE o.order_number = ?
            LIMIT 1
        ");
        $stmt->execute([$orderNumber]);
    }

    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        setFlash('error', 'Order not found.');
        header('Location: ' . APP_URL . '/admin/orders/index.php');
        exit;
    }

    $orderId = (int) $order['id'];

    /* ── Fetch Order Items ─────────────────────────────────────────── */
    $itemsStmt = $pdo->prepare("
        SELECT
            oi.id, oi.product_id, oi.quantity, oi.unit_price, oi.subtotal,
            p.name, p.brand, p.category, p.image_url, p.warranty_period_months
        FROM order_items oi
        LEFT JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ?
        ORDER BY oi.id ASC
    ");
    $itemsStmt->execute([$orderId]);
    $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    /* ── Fetch Payment Record ──────────────────────────────────────── */
    $payStmt = $pdo->prepare("
        SELECT id, transaction_id, payment_method, amount, payment_status, payment_payload, payment_date
        FROM payments
        WHERE order_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $payStmt->execute([$orderId]);
    $payment = $payStmt->fetch(PDO::FETCH_ASSOC);

    /* ── Fetch Invoice Record ──────────────────────────────────────── */
    $invStmt = $pdo->prepare("
        SELECT id, invoice_number, generated_at
        FROM invoices
        WHERE order_id = ?
        LIMIT 1
    ");
    $invStmt->execute([$orderId]);
    $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Admin order view query error: ' . $e->getMessage());
    setFlash('error', 'Database error loading order details.');
    header('Location: ' . APP_URL . '/admin/orders/index.php');
    exit;
}

/* ── Calculate Financials & Fee Breakdown ───────────────────────── */
$calculatedItemsSubtotal = 0.0;
foreach ($orderItems as $item) {
    $calculatedItemsSubtotal += (float) ($item['subtotal'] ?? 0);
}
$orderGrandTotal = (float) ($order['total_amount'] ?? 0);

// Parse payment payload if stored during checkout
$paymentPayload = [];
if (!empty($payment['payment_payload'])) {
    $decoded = json_decode((string) $payment['payment_payload'], true);
    if (is_array($decoded)) {
        $paymentPayload = $decoded;
    }
}

$paymentMethodCode = strtolower((string) ($payment['payment_method'] ?? ''));
$isCashOnDelivery = in_array($paymentMethodCode, ['cash', 'cod', 'cash_on_delivery'], true);

$payloadDelivery   = isset($paymentPayload['delivery_fee']) ? (float) $paymentPayload['delivery_fee'] : null;
$payloadPaymentFee = isset($paymentPayload['payment_fee'])  ? (float) $paymentPayload['payment_fee']  : null;

$priceDifference = max(0.0, round($orderGrandTotal - $calculatedItemsSubtotal, 2));

if ($payloadDelivery !== null || $payloadPaymentFee !== null) {
    $deliveryFee = max(0.0, (float) ($payloadDelivery ?? 0));
    $codFee      = max(0.0, (float) ($payloadPaymentFee ?? 0));
} elseif ($priceDifference > 0) {
    if ($isCashOnDelivery) {
        $codFee      = $priceDifference;
        $deliveryFee = 0.0;
    } else {
        $deliveryFee = $priceDifference;
        $codFee      = 0.0;
    }
} else {
    $deliveryFee = 0.0;
    $codFee      = 0.0;
}

$shippingHandlingTotal = $deliveryFee + $codFee;
$invoiceNumber = !empty($invoice['invoice_number'])
    ? $invoice['invoice_number']
    : ('INV-' . date('Ymd', strtotime($order['created_at'])) . '-' . sprintf('%04d', $orderId));

/* ── Stepper Logic ─────────────────────────────────────────────────── */
$statusSteps = ['placed', 'processing', 'shipped', 'delivered'];
$currentStatus = $order['order_status'];
$isCancelled = ($currentStatus === 'cancelled');

function getStepIndex(string $status, array $steps): int
{
    $idx = array_search($status, $steps, true);
    return $idx !== false ? $idx : 0;
}
$currentStepIdx = getStepIndex($currentStatus, $statusSteps);

/* ── Badges ────────────────────────────────────────────────────────── */
function orderBadge(string $status): string
{
    return match ($status) {
        'placed'     => 'bg-blue-100 text-blue-800 border-blue-200',
        'processing' => 'bg-amber-100 text-amber-800 border-amber-200',
        'shipped'    => 'bg-purple-100 text-purple-800 border-purple-200',
        'delivered'  => 'bg-emerald-100 text-emerald-800 border-emerald-200',
        'cancelled'  => 'bg-rose-100 text-rose-800 border-rose-200',
        default      => 'bg-gray-100 text-gray-800 border-gray-200',
    };
}

function paymentBadge(string $status): string
{
    return match ($status) {
        'paid'     => 'bg-emerald-100 text-emerald-800 border-emerald-200',
        'pending'  => 'bg-amber-100 text-amber-800 border-amber-200',
        'failed'   => 'bg-rose-100 text-rose-800 border-rose-200',
        'refunded' => 'bg-gray-100 text-gray-800 border-gray-200',
        default    => 'bg-gray-100 text-gray-800 border-gray-200',
    };
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order #<?php echo htmlspecialchars($order['order_number']); ?> | <?php echo APP_NAME; ?> Admin</title>

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
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: {
                        brand: {
                            red:      '#e52b34',
                            redHover: '#c4232c',
                            dark:     '#0a0a0c',
                            card:     '#121216',
                            border:   '#1f1f28'
                        }
                    }
                }
            }
        }
    </script>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>window.__APP_URL__ = '<?php echo rtrim(APP_URL, '/'); ?>';</script>
    <script src="<?php echo APP_URL; ?>/assets/js/main.js" defer></script>

    <style>
        .print-only {
            display: none !important;
        }

        @media print {
            @page {
                size: A4 portrait;
                margin: 10mm 12mm;
            }
            body {
                background: #ffffff !important;
                color: #111827 !important;
                margin: 0 !important;
                padding: 0 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            #admin-sidebar,
            #sidebar-overlay,
            header,
            #screen-order-view,
            .no-print {
                display: none !important;
            }
            #printable-invoice,
            .print-only {
                display: block !important;
            }
        }
    </style>
</head>

<body class="bg-gray-100 text-gray-800 font-sans antialiased">

<div id="screen-order-view" class="flex min-h-screen no-print">

    <!-- SIDEBAR -->
    <?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>

    <!-- MAIN CONTENT -->
    <div class="flex-1 flex flex-col min-w-0">

        <!-- TOP BAR (hidden on print) -->
        <header class="bg-white border-b border-gray-200 sticky top-0 z-20 flex items-center gap-4 px-6 h-16 flex-shrink-0 no-print">

            <button id="sidebar-open-btn" type="button"
                class="lg:hidden text-gray-500 hover:text-gray-900" aria-label="Open sidebar">
                <i data-lucide="menu" class="w-5 h-5"></i>
            </button>

            <!-- Breadcrumbs -->
            <div class="flex-1 min-w-0 flex items-center gap-2 text-sm">
                <a href="<?php echo APP_URL; ?>/admin/orders/index.php"
                    class="text-gray-400 hover:text-brand-red transition font-medium hidden sm:inline">Orders</a>
                <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-gray-300 hidden sm:inline flex-shrink-0"></i>
                <span class="font-bold text-gray-900 truncate">Order #<?php echo htmlspecialchars($order['order_number']); ?></span>
            </div>

            <div class="flex items-center gap-3">
                <button type="button" onclick="window.print()"
                    class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-gray-900 hover:bg-gray-800 text-white text-xs font-semibold transition shadow-sm">
                    <i data-lucide="printer" class="w-3.5 h-3.5"></i>
                    <span>Print Invoice</span>
                </button>

                <a href="<?php echo APP_URL; ?>/admin/orders/index.php"
                    class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl border border-gray-200 text-sm font-semibold text-gray-600 hover:bg-gray-50 transition">
                    <i data-lucide="arrow-left" class="w-4 h-4"></i>
                    <span class="hidden sm:inline">Back</span>
                </a>

                <!-- Avatar -->
                <div class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center text-white text-sm font-bold flex-shrink-0">
                    <?php echo strtoupper(substr($currentUser['name'] ?? 'A', 0, 1)); ?>
                </div>
            </div>

        </header>

        <!-- PAGE BODY -->
        <main class="flex-1 p-5 sm:p-7 space-y-6 max-w-7xl w-full mx-auto">

            <!-- Flash messages -->
            <div class="no-print">
                <?php displayFlash(); ?>
            </div>

            <!-- ── HERO ORDER HEADER ──────────────────────────────────── -->
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 print-card">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-gray-100 pb-5">
                    <div>
                        <div class="flex flex-wrap items-center gap-3">
                            <h1 class="text-2xl font-extrabold text-gray-900 font-mono">
                                #<?php echo htmlspecialchars($order['order_number']); ?>
                            </h1>
                            <span class="inline-block px-3 py-1 rounded-full text-xs font-bold border <?php echo orderBadge($order['order_status']); ?>">
                                <?php echo ucfirst($order['order_status']); ?>
                            </span>
                            <span class="inline-block px-3 py-1 rounded-full text-xs font-bold border <?php echo paymentBadge($order['payment_status']); ?>">
                                Payment: <?php echo ucfirst($order['payment_status']); ?>
                            </span>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold border border-gray-200 bg-gray-50 text-gray-700">
                                <i data-lucide="<?php echo $isCashOnDelivery ? 'banknote' : 'credit-card'; ?>" class="w-3.5 h-3.5 text-gray-500"></i>
                                <?php
                                if ($isCashOnDelivery) {
                                    echo 'Cash on Delivery (COD)';
                                } elseif (!empty($payment['payment_method'])) {
                                    echo ucwords(str_replace('_', ' ', $payment['payment_method']));
                                } else {
                                    echo 'Card / Bank / Online';
                                }
                                ?>
                            </span>
                        </div>
                        <p class="text-xs text-gray-500 mt-2 flex flex-wrap items-center gap-3">
                            <span>Placed on: <strong class="text-gray-700"><?php echo date('d M Y, h:i A', strtotime($order['created_at'])); ?></strong></span>
                            <?php if (!empty($order['updated_at']) && $order['updated_at'] !== $order['created_at']): ?>
                                <span>&bull;</span>
                                <span>Last updated: <strong class="text-gray-700"><?php echo date('d M Y, h:i A', strtotime($order['updated_at'])); ?></strong></span>
                            <?php endif; ?>
                            <?php if ($invoice): ?>
                                <span>&bull;</span>
                                <span>Invoice: <strong class="font-mono text-gray-800"><?php echo htmlspecialchars($invoice['invoice_number']); ?></strong></span>
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="text-left md:text-right">
                        <p class="text-xs uppercase tracking-wider font-semibold text-gray-400">Total Order Amount</p>
                        <p class="text-3xl font-extrabold text-gray-900 mt-0.5">
                            Rs.<?php echo number_format((float) $order['total_amount'], 2); ?>
                        </p>
                    </div>
                </div>

                <!-- ── STATUS PROGRESS TRACKER (hidden on print) ────────── -->
                <div class="pt-6 no-print">
                    <?php if ($isCancelled): ?>
                        <div class="bg-rose-50 border border-rose-200 rounded-xl p-4 flex items-center gap-3 text-rose-800">
                            <i data-lucide="x-circle" class="w-6 h-6 text-rose-500 flex-shrink-0"></i>
                            <div>
                                <p class="text-sm font-bold">This order has been cancelled.</p>
                                <p class="text-xs text-rose-600 mt-0.5">Inventory reservations and status have been updated accordingly.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="relative">
                            <!-- Progress Bar Background -->
                            <div class="hidden sm:block absolute top-1/2 left-0 right-0 h-1 bg-gray-100 -translate-y-1/2 z-0"></div>
                            <?php
                            $pct = match ($currentStepIdx) {
                                0 => 12,
                                1 => 40,
                                2 => 70,
                                3 => 100,
                                default => 0
                            };
                            ?>
                            <div class="hidden sm:block absolute top-1/2 left-0 h-1 bg-brand-red -translate-y-1/2 z-0 transition-all duration-500"
                                style="width: <?php echo $pct; ?>%;"></div>

                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 relative z-10">
                                <?php
                                $stepsMeta = [
                                    'placed'     => ['label' => 'Order Placed', 'icon' => 'shopping-cart'],
                                    'processing' => ['label' => 'Processing',   'icon' => 'package'],
                                    'shipped'    => ['label' => 'Shipped',      'icon' => 'truck'],
                                    'delivered'  => ['label' => 'Delivered',    'icon' => 'check-circle'],
                                ];
                                foreach ($statusSteps as $sIdx => $sKey):
                                    $isPassed  = ($sIdx <= $currentStepIdx);
                                    $isCurrent = ($sIdx === $currentStepIdx);
                                    $meta      = $stepsMeta[$sKey];
                                ?>
                                    <div class="flex items-center sm:flex-col sm:text-center gap-3 sm:gap-2">
                                        <div class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0 border-2 transition-all <?php
                                            if ($isPassed) {
                                                echo 'bg-brand-red border-brand-red text-white shadow-sm';
                                            } else {
                                                echo 'bg-white border-gray-200 text-gray-400';
                                            }
                                        ?>">
                                            <i data-lucide="<?php echo $meta['icon']; ?>" class="w-4 h-4"></i>
                                        </div>
                                        <div>
                                            <p class="text-xs font-bold <?php echo $isPassed ? 'text-gray-900' : 'text-gray-400'; ?>">
                                                <?php echo $meta['label']; ?>
                                            </p>
                                            <p class="text-[10px] text-gray-400">
                                                <?php echo $isCurrent ? 'Current Status' : ($isPassed ? 'Completed' : 'Pending'); ?>
                                            </p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

            <!-- ── MAIN TWO-COLUMN GRID ───────────────────────────────── -->
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 items-start">

                <!-- ── LEFT / MAIN COLUMN (Items & Invoicing) ─────────── -->
                <div class="xl:col-span-2 space-y-6">

                    <!-- Order Items Card -->
                    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden print-card">
                        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <i data-lucide="package" class="w-4 h-4 text-brand-red"></i>
                                <h2 class="text-sm font-bold text-gray-900">Ordered Items (<?php echo count($orderItems); ?>)</h2>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-sm text-left">
                                <thead>
                                    <tr class="bg-gray-50 border-b border-gray-100 text-[11px] font-bold text-gray-500 uppercase tracking-wider">
                                        <th class="px-6 py-3.5">Product</th>
                                        <th class="px-4 py-3.5 text-right">Price</th>
                                        <th class="px-4 py-3.5 text-center">Qty</th>
                                        <th class="px-6 py-3.5 text-right">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php
                                    $calculatedTotal = 0;
                                    foreach ($orderItems as $item):
                                        $thumb = productThumbUrl($item['image_url'] ?? '');
                                        $calculatedTotal += (float) $item['subtotal'];
                                    ?>
                                        <tr class="hover:bg-gray-50/60 transition-colors">
                                            <td class="px-6 py-4">
                                                <div class="flex items-center gap-3">
                                                    <?php if (!empty($thumb)): ?>
                                                        <img src="<?php echo htmlspecialchars($thumb); ?>"
                                                            alt="<?php echo htmlspecialchars($item['name'] ?? 'Product'); ?>"
                                                            class="w-12 h-12 rounded-xl object-contain bg-gray-50 border border-gray-100 p-1 flex-shrink-0"
                                                            onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                                        <div class="w-12 h-12 rounded-xl bg-gray-100 items-center justify-center text-gray-400 flex-shrink-0 hidden">
                                                            <i data-lucide="package" class="w-5 h-5"></i>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="w-12 h-12 rounded-xl bg-gray-100 flex items-center justify-center text-gray-400 flex-shrink-0">
                                                            <i data-lucide="package" class="w-5 h-5"></i>
                                                        </div>
                                                    <?php endif; ?>

                                                    <div class="min-w-0">
                                                        <?php if (!empty($item['product_id'])): ?>
                                                            <a href="<?php echo APP_URL; ?>/admin/products/edit.php?id=<?php echo $item['product_id']; ?>"
                                                                class="font-bold text-gray-900 hover:text-brand-red transition text-sm truncate block">
                                                                <?php echo htmlspecialchars($item['name'] ?? 'Unknown Product'); ?>
                                                            </a>
                                                        <?php else: ?>
                                                            <p class="font-bold text-gray-900 text-sm truncate">
                                                                <?php echo htmlspecialchars($item['name'] ?? 'Product'); ?>
                                                            </p>
                                                        <?php endif; ?>

                                                        <p class="text-xs text-gray-400 mt-0.5">
                                                            <?php echo htmlspecialchars($item['brand'] ?? '—'); ?>
                                                            &bull; <?php echo htmlspecialchars($item['category'] ?? 'General'); ?>
                                                            <?php if (!empty($item['warranty_period_months'])): ?>
                                                                &bull; <span class="text-emerald-600 font-semibold"><?php echo (int) $item['warranty_period_months']; ?> mo. warranty</span>
                                                            <?php endif; ?>
                                                        </p>
                                                    </div>
                                                </div>
                                            </td>

                                            <td class="px-4 py-4 text-right font-medium text-gray-700">
                                                Rs.<?php echo number_format((float) $item['unit_price'], 2); ?>
                                            </td>

                                            <td class="px-4 py-4 text-center font-bold text-gray-900">
                                                &times; <?php echo (int) $item['quantity']; ?>
                                            </td>

                                            <td class="px-6 py-4 text-right font-extrabold text-gray-900">
                                                Rs.<?php echo number_format((float) $item['subtotal'], 2); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Calculation breakdown -->
                        <div class="bg-gray-50/60 p-6 border-t border-gray-100 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                            <div class="text-xs text-gray-400">
                                Total items: <strong class="text-gray-700"><?php echo array_sum(array_column($orderItems, 'quantity')); ?> units</strong>
                            </div>

                            <div class="w-full sm:w-72 space-y-2 text-sm">
                                <div class="flex justify-between text-gray-600">
                                    <span>Items Subtotal:</span>
                                    <span class="font-medium text-gray-800">Rs.<?php echo number_format($calculatedItemsSubtotal, 2); ?></span>
                                </div>

                                <?php if ($deliveryFee > 0 && $codFee > 0): ?>
                                    <div class="flex justify-between text-gray-600">
                                        <span>Delivery Fee:</span>
                                        <span class="font-medium text-gray-800">Rs.<?php echo number_format($deliveryFee, 2); ?></span>
                                    </div>
                                    <div class="flex justify-between text-gray-600">
                                        <span>Cash on Delivery (COD) Fee:</span>
                                        <span class="font-medium text-gray-900">Rs.<?php echo number_format($codFee, 2); ?></span>
                                    </div>
                                <?php elseif ($codFee > 0): ?>
                                    <div class="flex justify-between text-gray-600">
                                        <span>Shipping / Handling (COD):</span>
                                        <span class="font-medium text-gray-900">Rs.<?php echo number_format($codFee, 2); ?></span>
                                    </div>
                                <?php elseif ($deliveryFee > 0): ?>
                                    <div class="flex justify-between text-gray-600">
                                        <span>Shipping & Handling:</span>
                                        <span class="font-medium text-gray-900">Rs.<?php echo number_format($deliveryFee, 2); ?></span>
                                    </div>
                                <?php else: ?>
                                    <div class="flex justify-between text-gray-600">
                                        <span>Shipping / Handling:</span>
                                        <span class="text-emerald-600 font-medium">Free</span>
                                    </div>
                                <?php endif; ?>

                                <div class="border-t border-gray-200 pt-2 flex justify-between text-base font-extrabold text-gray-900">
                                    <span>Total:</span>
                                    <span class="text-brand-red">Rs.<?php echo number_format($orderGrandTotal, 2); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Information Card -->
                    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 print-card">
                        <div class="flex items-center gap-2 mb-4">
                            <i data-lucide="credit-card" class="w-4 h-4 text-brand-red"></i>
                            <h2 class="text-sm font-bold text-gray-900">Payment Summary</h2>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div class="p-4 rounded-xl bg-gray-50 border border-gray-100">
                                <p class="text-xs text-gray-400 font-medium">Payment Status</p>
                                <p class="mt-1 font-bold text-sm">
                                    <span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-bold border <?php echo paymentBadge($order['payment_status']); ?>">
                                        <?php echo ucfirst($order['payment_status']); ?>
                                    </span>
                                </p>
                            </div>

                            <div class="p-4 rounded-xl bg-gray-50 border border-gray-100">
                                <p class="text-xs text-gray-400 font-medium">Payment Method</p>
                                <p class="mt-1 font-bold text-gray-900 text-sm">
                                    <?php
                                    if ($isCashOnDelivery) {
                                        echo 'Cash on Delivery (COD)';
                                    } elseif (!empty($payment['payment_method'])) {
                                        echo ucwords(str_replace('_', ' ', $payment['payment_method']));
                                    } else {
                                        echo 'Card / Bank / Online';
                                    }
                                    ?>
                                </p>
                                <?php if ($codFee > 0): ?>
                                    <span class="text-[11px] text-gray-500 font-medium block mt-0.5">
                                        + Rs. <?php echo number_format($codFee, 2); ?> Handling Fee
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="p-4 rounded-xl bg-gray-50 border border-gray-100">
                                <p class="text-xs text-gray-400 font-medium">Transaction Reference</p>
                                <p class="mt-1 font-mono text-xs font-bold text-gray-800 truncate" title="<?php echo htmlspecialchars($payment['transaction_id'] ?? '—'); ?>">
                                    <?php echo htmlspecialchars($payment['transaction_id'] ?? 'TRX-' . strtoupper(substr(md5($order['order_number']), 0, 10))); ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Customer Notes / Instructions -->
                    <?php if (!empty($order['notes'])): ?>
                        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 print-card">
                            <div class="flex items-center gap-2 mb-2">
                                <i data-lucide="file-text" class="w-4 h-4 text-brand-red"></i>
                                <h2 class="text-sm font-bold text-gray-900">Notes &amp; Special Instructions</h2>
                            </div>
                            <p class="text-sm text-gray-600 bg-amber-50/50 border border-amber-100 rounded-xl p-4 leading-relaxed">
                                <?php echo nl2br(htmlspecialchars($order['notes'])); ?>
                            </p>
                        </div>
                    <?php endif; ?>

                </div><!-- /left column -->


                <!-- ── RIGHT COLUMN (Customer info & Management) ──────── -->
                <div class="space-y-6">

                    <!-- Status Management Card (hidden on print) -->
                    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 no-print">
                        <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-100">
                            <i data-lucide="sliders" class="w-4 h-4 text-brand-red"></i>
                            <h2 class="text-sm font-bold text-gray-900">Order Management</h2>
                        </div>

                        <form method="POST" action="" class="space-y-4">
                            <input type="hidden" name="csrf_token" value="<?php echo getCsrfToken(); ?>">
                            <input type="hidden" name="action" value="update_order">
                            <input type="hidden" name="order_id" value="<?php echo $orderId; ?>">

                            <!-- Order Status -->
                            <div>
                                <label for="order_status" class="block text-xs font-bold text-gray-700 mb-1.5 uppercase tracking-wider">
                                    Order Fulfillment Status
                                </label>
                                <select
                                    id="order_status"
                                    name="order_status"
                                    class="w-full px-3 py-2.5 text-sm rounded-xl border border-gray-200 bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 outline-none transition font-medium">
                                    <option value="placed"     <?php echo $order['order_status'] === 'placed'     ? 'selected' : ''; ?>>Placed (New)</option>
                                    <option value="processing" <?php echo $order['order_status'] === 'processing' ? 'selected' : ''; ?>>Processing</option>
                                    <option value="shipped"    <?php echo $order['order_status'] === 'shipped'    ? 'selected' : ''; ?>>Shipped</option>
                                    <option value="delivered"  <?php echo $order['order_status'] === 'delivered'  ? 'selected' : ''; ?>>Delivered</option>
                                    <option value="cancelled"  <?php echo $order['order_status'] === 'cancelled'  ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>

                            <!-- Payment Status -->
                            <div>
                                <label for="payment_status" class="block text-xs font-bold text-gray-700 mb-1.5 uppercase tracking-wider">
                                    Payment Status
                                </label>
                                <select
                                    id="payment_status"
                                    name="payment_status"
                                    class="w-full px-3 py-2.5 text-sm rounded-xl border border-gray-200 bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 outline-none transition font-medium">
                                    <option value="pending"  <?php echo $order['payment_status'] === 'pending'  ? 'selected' : ''; ?>>Pending</option>
                                    <option value="paid"     <?php echo $order['payment_status'] === 'paid'     ? 'selected' : ''; ?>>Paid</option>
                                    <option value="failed"   <?php echo $order['payment_status'] === 'failed'   ? 'selected' : ''; ?>>Failed</option>
                                    <option value="refunded" <?php echo $order['payment_status'] === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                                </select>
                            </div>

                            <!-- Admin Notes -->
                            <div>
                                <label for="notes" class="block text-xs font-bold text-gray-700 mb-1.5 uppercase tracking-wider">
                                    Order Notes / Delivery Details
                                </label>
                                <textarea
                                    id="notes"
                                    name="notes"
                                    rows="3"
                                    placeholder="Add delivery tracking number, internal notes, or instructions…"
                                    class="w-full px-3 py-2 text-sm rounded-xl border border-gray-200 bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 outline-none transition resize-none"><?php echo htmlspecialchars($order['notes'] ?? ''); ?></textarea>
                            </div>

                            <button
                                type="submit"
                                class="w-full py-2.5 px-4 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-xs font-bold transition flex items-center justify-center gap-2 shadow-sm">
                                <i data-lucide="check" class="w-4 h-4"></i>
                                Save Order Updates
                            </button>
                        </form>
                    </div>

                    <!-- Customer Information Card -->
                    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 print-card">
                        <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-100">
                            <i data-lucide="user" class="w-4 h-4 text-brand-red"></i>
                            <h2 class="text-sm font-bold text-gray-900">Customer Details</h2>
                        </div>

                        <div class="space-y-3.5">
                            <div>
                                <p class="text-xs text-gray-400 font-medium">Customer Name</p>
                                <p class="text-sm font-bold text-gray-900 mt-0.5">
                                    <?php echo htmlspecialchars($order['customer_name'] ?? 'Guest Customer'); ?>
                                </p>
                            </div>

                            <div>
                                <p class="text-xs text-gray-400 font-medium">Email Address</p>
                                <?php if (!empty($order['customer_email'])): ?>
                                    <a href="mailto:<?php echo htmlspecialchars($order['customer_email']); ?>"
                                        class="text-sm font-medium text-brand-red hover:underline mt-0.5 block truncate">
                                        <?php echo htmlspecialchars($order['customer_email']); ?>
                                    </a>
                                <?php else: ?>
                                    <p class="text-sm text-gray-500">—</p>
                                <?php endif; ?>
                            </div>

                            <div>
                                <p class="text-xs text-gray-400 font-medium">Contact Phone</p>
                                <a href="tel:<?php echo htmlspecialchars($order['contact_phone']); ?>"
                                    class="text-sm font-semibold text-gray-800 hover:text-brand-red mt-0.5 block">
                                    <?php echo htmlspecialchars($order['contact_phone'] ?: ($order['customer_phone_registered'] ?? '—')); ?>
                                </a>
                            </div>

                            <div class="pt-2 border-t border-gray-100 text-xs text-gray-400 flex justify-between">
                                <span>User Account:</span>
                                <span class="font-mono font-semibold text-gray-700">ID #<?php echo (int) $order['user_id']; ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Shipping / Delivery Card -->
                    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 print-card">
                        <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-100">
                            <i data-lucide="map-pin" class="w-4 h-4 text-brand-red"></i>
                            <h2 class="text-sm font-bold text-gray-900">Shipping Address</h2>
                        </div>

                        <p class="text-sm text-gray-700 bg-gray-50 rounded-xl p-3.5 border border-gray-100 leading-relaxed font-medium">
                            <?php echo nl2br(htmlspecialchars($order['shipping_address'])); ?>
                        </p>

                        <div class="mt-3 flex items-center gap-2 text-xs text-gray-500">
                            <i data-lucide="phone" class="w-3.5 h-3.5 text-gray-400"></i>
                            <span>Recipient contact: <strong><?php echo htmlspecialchars($order['contact_phone']); ?></strong></span>
                        </div>
                    </div>

                </div><!-- /right column -->

            </div><!-- /grid -->

            <!-- Footer (hidden on print) -->
            <p class="text-center text-xs text-gray-400 pt-4 pb-6 no-print">
                <?php echo APP_NAME; ?> &mdash; Admin Panel &mdash; v<?php echo APP_VERSION; ?>
            </p>

        </main>

    </div><!-- /main content -->

</div><!-- /#screen-order-view -->

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- DEDICATED PRINTABLE INVOICE / RECEIPT (Visible ONLY on print)      -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<div id="printable-invoice" class="print-only p-8 bg-white text-gray-900 font-sans max-w-4xl mx-auto">

    <!-- Invoice Header -->
    <div class="flex justify-between items-start border-b-2 border-gray-900 pb-5 mb-6">
        <div>
            <div class="flex items-center gap-3 mb-2">
                <img src="<?php echo APP_URL; ?>/assets/images/Logo.png" alt="Logo" class="w-10 h-10 object-contain"
                     onerror="this.src='<?php echo APP_URL; ?>/assets/images/Logo.jpg';">
                <div>
                    <h1 class="text-xl font-black uppercase tracking-tight text-gray-900">
                        <?php echo APP_NAME; ?>
                    </h1>
                    <p class="text-[11px] text-gray-600 font-medium">Official Sales Invoice &amp; Warranty Document</p>
                </div>
            </div>
            <p class="text-xs text-gray-500 leading-relaxed mt-1">
                Nawala Road, Colombo, Sri Lanka<br>
                Hotline: 077 123 4567 &bull; Email: info@thirasaramax.lk
            </p>
        </div>

        <div class="text-right">
            <h2 class="text-2xl font-black text-gray-900 tracking-wider">INVOICE</h2>
            <div class="mt-2 space-y-1 text-xs">
                <p><span class="text-gray-500">Invoice No:</span> <strong class="font-mono text-gray-900"><?php echo htmlspecialchars($invoiceNumber); ?></strong></p>
                <p><span class="text-gray-500">Order Ref:</span> <strong class="font-mono text-gray-900">#<?php echo htmlspecialchars($order['order_number']); ?></strong></p>
                <p><span class="text-gray-500">Date:</span> <strong class="text-gray-900"><?php echo date('d M Y, h:i A', strtotime($order['created_at'])); ?></strong></p>
                <p><span class="text-gray-500">Payment:</span> <strong class="uppercase px-1.5 py-0.5 border border-gray-300 rounded text-[10px]"><?php echo strtoupper($order['payment_status']); ?></strong></p>
            </div>
        </div>
    </div>

    <!-- Customer & Delivery 2-Column Info -->
    <div class="grid grid-cols-2 gap-8 border-b border-gray-200 pb-5 mb-6 text-xs">
        <div>
            <h3 class="font-extrabold text-gray-900 uppercase tracking-wider text-[11px] mb-2 border-b border-gray-100 pb-1">
                Customer (Billed To)
            </h3>
            <p class="text-sm font-bold text-gray-900"><?php echo htmlspecialchars($order['customer_name'] ?? 'Guest Customer'); ?></p>
            <p class="text-gray-600 mt-1">Phone: <strong><?php echo htmlspecialchars($order['contact_phone'] ?: ($order['customer_phone_registered'] ?? '—')); ?></strong></p>
            <?php if (!empty($order['customer_email'])): ?>
                <p class="text-gray-600">Email: <?php echo htmlspecialchars($order['customer_email']); ?></p>
            <?php endif; ?>
            <p class="text-gray-400 mt-1">Account: Customer #<?php echo (int) $order['user_id']; ?></p>
        </div>

        <div>
            <h3 class="font-extrabold text-gray-900 uppercase tracking-wider text-[11px] mb-2 border-b border-gray-100 pb-1">
                Delivery Address (Shipped To)
            </h3>
            <p class="text-gray-800 font-medium leading-relaxed">
                <?php echo nl2br(htmlspecialchars($order['shipping_address'])); ?>
            </p>
            <p class="text-gray-600 mt-1">Recipient Contact: <strong><?php echo htmlspecialchars($order['contact_phone']); ?></strong></p>
            <p class="text-gray-500 mt-1">Fulfillment Status: <strong class="uppercase"><?php echo htmlspecialchars($order['order_status']); ?></strong></p>
        </div>
    </div>

    <!-- Ordered Items Table -->
    <table class="w-full text-xs border-collapse mb-6">
        <thead>
            <tr class="border-b-2 border-gray-900 text-left uppercase text-[10px] tracking-wider text-gray-700 bg-gray-50">
                <th class="py-2 px-3 w-8">#</th>
                <th class="py-2 px-3">Item Description</th>
                <th class="py-2 px-3 text-right">Price</th>
                <th class="py-2 px-3 text-center w-16">Qty</th>
                <th class="py-2 px-3 text-right">Subtotal</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
            <?php foreach ($orderItems as $idx => $item): ?>
                <tr>
                    <td class="py-2.5 px-3 text-gray-400 font-medium"><?php echo $idx + 1; ?></td>
                    <td class="py-2.5 px-3">
                        <p class="font-bold text-gray-900"><?php echo htmlspecialchars($item['name'] ?? 'Product'); ?></p>
                        <p class="text-[11px] text-gray-500">
                            <?php echo htmlspecialchars($item['brand'] ?? '—'); ?> &bull;
                            <?php echo htmlspecialchars($item['category'] ?? 'General'); ?>
                            <?php if (!empty($item['warranty_period_months'])): ?>
                                &bull; <strong><?php echo (int) $item['warranty_period_months']; ?> Months Warranty</strong>
                            <?php endif; ?>
                        </p>
                    </td>
                    <td class="py-2.5 px-3 text-right text-gray-800 font-medium">
                        Rs.<?php echo number_format((float) $item['unit_price'], 2); ?>
                    </td>
                    <td class="py-2.5 px-3 text-center text-gray-900 font-bold">
                        <?php echo (int) $item['quantity']; ?>
                    </td>
                    <td class="py-2.5 px-3 text-right text-gray-900 font-bold">
                        Rs.<?php echo number_format((float) $item['subtotal'], 2); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Totals and Payment Notes Row -->
    <div class="flex justify-between items-start gap-8 border-t-2 border-gray-900 pt-4 mb-6">
        <!-- Payment Details & Instructions (Left) -->
        <div class="flex-1 space-y-3 text-xs">
            <div class="border border-gray-200 rounded p-3 bg-gray-50">
                <p class="font-bold text-gray-900 uppercase tracking-wider text-[10px] mb-1.5">Payment Details</p>
                <div class="grid grid-cols-2 gap-2 text-[11px]">
                    <div>
                        <span class="text-gray-500">Method:</span>
                        <strong class="text-gray-900">
                            <?php echo $isCashOnDelivery ? 'Cash on Delivery (COD)' : (!empty($payment['payment_method']) ? ucwords(str_replace('_', ' ', $payment['payment_method'])) : 'Card / Bank'); ?>
                        </strong>
                    </div>
                    <div>
                        <span class="text-gray-500">Status:</span>
                        <strong class="uppercase text-gray-900"><?php echo htmlspecialchars($order['payment_status']); ?></strong>
                    </div>
                    <div class="col-span-2">
                        <span class="text-gray-500">Reference:</span>
                        <span class="font-mono text-gray-700"><?php echo htmlspecialchars($payment['transaction_id'] ?? 'TRX-' . strtoupper(substr(md5($order['order_number']), 0, 10))); ?></span>
                    </div>
                </div>
            </div>

            <?php if (!empty($order['notes'])): ?>
                <div class="border border-gray-200 rounded p-3">
                    <p class="font-bold text-gray-700 uppercase tracking-wider text-[10px] mb-1">Customer Notes / Instructions</p>
                    <p class="text-gray-600 italic text-[11px]"><?php echo nl2br(htmlspecialchars($order['notes'])); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Financial Summary (Right) -->
        <div class="w-64 space-y-2 text-xs">
            <div class="flex justify-between text-gray-600">
                <span>Items Subtotal:</span>
                <span class="font-semibold text-gray-900">Rs.<?php echo number_format($calculatedItemsSubtotal, 2); ?></span>
            </div>

            <?php if ($deliveryFee > 0 && $codFee > 0): ?>
                <div class="flex justify-between text-gray-600">
                    <span>Delivery Fee:</span>
                    <span class="font-semibold text-gray-900">Rs.<?php echo number_format($deliveryFee, 2); ?></span>
                </div>
                <div class="flex justify-between text-gray-600">
                    <span>Cash on Delivery Fee:</span>
                    <span class="font-semibold text-gray-900">Rs.<?php echo number_format($codFee, 2); ?></span>
                </div>
            <?php elseif ($codFee > 0): ?>
                <div class="flex justify-between text-gray-600">
                    <span>Shipping / Handling (COD):</span>
                    <span class="font-semibold text-gray-900">Rs.<?php echo number_format($codFee, 2); ?></span>
                </div>
            <?php elseif ($deliveryFee > 0): ?>
                <div class="flex justify-between text-gray-600">
                    <span>Shipping &amp; Handling:</span>
                    <span class="font-semibold text-gray-900">Rs.<?php echo number_format($deliveryFee, 2); ?></span>
                </div>
            <?php else: ?>
                <div class="flex justify-between text-gray-600">
                    <span>Shipping / Handling:</span>
                    <span class="text-gray-600 font-semibold">Free</span>
                </div>
            <?php endif; ?>

            <div class="border-t border-gray-300 pt-2 flex justify-between text-sm font-extrabold text-gray-900">
                <span>Total Amount:</span>
                <span>Rs.<?php echo number_format($orderGrandTotal, 2); ?></span>
            </div>
        </div>
    </div>

    <!-- Terms / Warranty Footer -->
    <div class="border-t border-gray-200 pt-4 text-center text-[10px] text-gray-500 space-y-1">
        <p class="font-bold text-gray-800">Thank you for choosing <?php echo APP_NAME; ?>!</p>
        <p>Goods sold are covered under respective warranty terms. Retain this invoice for warranty verification.</p>
        <p>Support Hotline: 077 123 4567 &bull; Email: support@thirasaramax.lk &bull; Nawala Road, Colombo, Sri Lanka</p>
    </div>

</div>

<script>lucide.createIcons();</script>

</body>
</html>
