<?php

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/invoice_generator.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = Database::getConnection();
$currentUser = getCurrentUser();
$currentUserId = (int) ($currentUser['id'] ?? 0);
$currentUserRole = (string) ($currentUser['role'] ?? '');

/* --------------------------------------------------------------------------
 * 1. Resolve Target Order
 * Accepts ?order=TM-XXXXXXXX or ?order_id=123 or falls back to $_SESSION['last_order']
 * -------------------------------------------------------------------------- */
$orderNumber = trim($_GET['order'] ?? '');
$orderIdParam = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT) ?: 0;

if (empty($orderNumber) && $orderIdParam <= 0) {
    if (!empty($_SESSION['last_order']['order_number'])) {
        $orderNumber = (string) $_SESSION['last_order']['order_number'];
    } elseif (!empty($_SESSION['last_order']['id'])) {
        $orderIdParam = (int) $_SESSION['last_order']['id'];
    }
}

if (empty($orderNumber) && $orderIdParam <= 0) {
    if (isLoggedIn()) {
        setFlash('info', 'Please select an order to view its confirmation.');
        header('Location: ' . APP_URL . '/customer/orders.php');
        exit;
    }
    setFlash('error', 'No order specified. Please sign in to check your order history.');
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

/* --------------------------------------------------------------------------
 * 2. Fetch Order Data
 * -------------------------------------------------------------------------- */
try {
    if (!empty($orderNumber)) {
        $orderStmt = $pdo->prepare("
            SELECT 
                o.*,
                u.name AS customer_name,
                u.email AS customer_email,
                u.phone AS customer_phone_registered,
                inv.invoice_number,
                inv.id AS invoice_id,
                inv.pdf_path AS invoice_pdf_path
            FROM orders o
            LEFT JOIN users u ON u.id = o.user_id
            LEFT JOIN invoices inv ON inv.order_id = o.id
            WHERE o.order_number = ?
            LIMIT 1
        ");
        $orderStmt->execute([$orderNumber]);
    } else {
        $orderStmt = $pdo->prepare("
            SELECT 
                o.*,
                u.name AS customer_name,
                u.email AS customer_email,
                u.phone AS customer_phone_registered,
                inv.invoice_number,
                inv.id AS invoice_id,
                inv.pdf_path AS invoice_pdf_path
            FROM orders o
            LEFT JOIN users u ON u.id = o.user_id
            LEFT JOIN invoices inv ON inv.order_id = o.id
            WHERE o.id = ?
            LIMIT 1
        ");
        $orderStmt->execute([$orderIdParam]);
    }

    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Order confirmation fetch error: ' . $e->getMessage());
    $order = null;
}

if (!$order) {
    setFlash('error', 'We could not locate the requested order.');
    if (isLoggedIn()) {
        header('Location: ' . APP_URL . '/customer/orders.php');
    } else {
        header('Location: ' . APP_URL . '/index.php');
    }
    exit;
}

$orderId = (int) $order['id'];
$orderNumber = (string) $order['order_number'];

/* --------------------------------------------------------------------------
 * 3. Access Control Verification
 * Allows: Owner of the order, Store Admin, or session holding last_order matching this order
 * -------------------------------------------------------------------------- */
$isOwner = ($currentUserId > 0 && $currentUserId === (int) $order['user_id']);
$isAdmin = ($currentUserRole === 'admin');
$isJustPlacedInSession = (
    !empty($_SESSION['last_order']['order_number']) &&
    $_SESSION['last_order']['order_number'] === $orderNumber
);

if (!$isOwner && !$isAdmin && !$isJustPlacedInSession) {
    if (!isLoggedIn()) {
        setFlash('error', 'Please sign in to view this order confirmation.');
        header('Location: ' . APP_URL . '/login.php?redirect=' . urlencode('order-confirmation.php?order=' . $orderNumber));
        exit;
    }
    setFlash('error', 'You do not have permission to view that order confirmation.');
    header('Location: ' . APP_URL . '/customer/orders.php');
    exit;
}

/* --------------------------------------------------------------------------
 * 4. Fetch Order Line Items & Payment Information
 * -------------------------------------------------------------------------- */
try {
    $itemsStmt = $pdo->prepare("
        SELECT 
            oi.id, oi.product_id, oi.quantity, oi.unit_price, oi.subtotal,
            p.name, p.brand, p.category, p.image_url, p.is_repairable, p.warranty_period_months
        FROM order_items oi
        LEFT JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ?
        ORDER BY oi.id ASC
    ");
    $itemsStmt->execute([$orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $payStmt = $pdo->prepare("
        SELECT id, transaction_id, payment_method, amount, payment_status, payment_payload, payment_date
        FROM payments
        WHERE order_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $payStmt->execute([$orderId]);
    $payment = $payStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'transaction_id' => 'N/A',
        'payment_method' => 'cash',
        'amount'         => $order['total_amount'],
        'payment_status' => $order['payment_status'],
        'payment_date'   => $order['created_at']
    ];
} catch (PDOException $e) {
    error_log('Order details fetch error: ' . $e->getMessage());
    $items = [];
    $payment = [];
}

/* --------------------------------------------------------------------------
 * 5. Handle PDF Invoice Download
 * -------------------------------------------------------------------------- */
$downloadAction = trim($_GET['download'] ?? $_GET['action'] ?? '');
if ($downloadAction === 'pdf' || $downloadAction === 'download_invoice') {
    // Ensure invoice number exists
    if (empty($order['invoice_number'])) {
        $invCandidate = 'INV-' . date('Ymd', strtotime($order['created_at'])) . '-' . sprintf('%04d', $orderId);
        try {
            $insertInv = $pdo->prepare("
                INSERT INTO invoices (invoice_number, order_id, invoice_type, total_amount)
                VALUES (?, ?, 'order', ?)
                ON DUPLICATE KEY UPDATE id=id
            ");
            $insertInv->execute([$invCandidate, $orderId, $order['total_amount']]);
            $order['invoice_number'] = $invCandidate;
        } catch (Throwable $e) {
            $order['invoice_number'] = $invCandidate;
        }
    }

    $tempPdfPath = generateInvoicePdf($orderId, $order, $items, $payment);

    if ($tempPdfPath && file_exists($tempPdfPath)) {
        $downloadFileName = 'Invoice-' . $order['order_number'] . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $downloadFileName . '"');
        header('Content-Length: ' . filesize($tempPdfPath));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        readfile($tempPdfPath);
        @unlink($tempPdfPath);
        exit;
    } else {
        setFlash('error', 'Unable to generate PDF invoice at this moment. Please try again.');
        header('Location: ' . APP_URL . '/order-confirmation.php?order=' . urlencode($orderNumber));
        exit;
    }
}

/* --------------------------------------------------------------------------
 * 6. View Calculations & Formatters
 * -------------------------------------------------------------------------- */
$pageTitle = 'Order Confirmed — ' . $orderNumber;

$itemsSubtotal = 0.00;
foreach ($items as $item) {
    $itemsSubtotal += (float) ($item['subtotal'] ?? 0);
}

$grandTotal = (float) $order['total_amount'];
$deliveryFee = 0.00; // Free delivery
$paymentFee = max(0.00, round($grandTotal - $itemsSubtotal - $deliveryFee, 2));

function formatLKR(float $amount): string
{
    return 'Rs. ' . number_format($amount, 2);
}

function getOrderImageUrl(?string $url): string
{
    if (empty($url)) {
        return APP_URL . '/assets/images/placeholder-product.svg';
    }
    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
        return $url;
    }
    return APP_URL . '/' . ltrim($url, '/');
}

$orderStatus = strtolower((string) ($order['order_status'] ?? 'placed'));
$paymentStatus = strtolower((string) ($order['payment_status'] ?? 'pending'));
$paymentMethod = strtolower((string) ($payment['payment_method'] ?? 'cash'));

$statusSteps = [
    'placed'     => ['label' => 'Order Placed', 'icon' => 'check-circle-2', 'desc' => 'Order received & logged'],
    'processing' => ['label' => 'Processing',   'icon' => 'package',        'desc' => 'Items picking & packing'],
    'shipped'    => ['label' => 'Shipped',      'icon' => 'truck',          'desc' => 'Dispatched with courier'],
    'delivered'  => ['label' => 'Delivered',    'icon' => 'home',           'desc' => 'Delivered to recipient']
];

$stepHierarchy = ['placed' => 1, 'processing' => 2, 'shipped' => 3, 'delivered' => 4, 'cancelled' => -1];
$currentStepRank = $stepHierarchy[$orderStatus] ?? 1;

require_once __DIR__ . '/includes/header.php';
?>

<style>
@media print {
    header, footer, .no-print, nav, #quick-actions, .mobile-nav {
        display: none !important;
    }
    body {
        background: #ffffff !important;
        color: #000000 !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    .print-only {
        display: block !important;
    }
    .shadow-sm, .shadow-md, .shadow-lg, .shadow-xl {
        box-shadow: none !important;
    }
    .border {
        border-color: #cccccc !important;
    }
}
.print-only {
    display: none;
}
</style>

<div class="bg-gray-50 min-h-screen py-10">
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- BREADCRUMB -->
        <nav class="flex items-center gap-2 text-xs font-semibold text-gray-500 mb-6 uppercase tracking-wider no-print">
            <a href="<?= APP_URL ?>" class="hover:text-brand-red transition">Home</a>
            <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-gray-400"></i>
            <a href="<?= APP_URL ?>/products.php" class="hover:text-brand-red transition">Products</a>
            <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-gray-400"></i>
            <span class="text-gray-900 font-bold">Order Confirmation</span>
        </nav>

        <!-- PRINT HEADER (Only visible when printing) -->
        <div class="print-only mb-6 pb-4 border-b border-gray-300">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-black text-brand-red uppercase tracking-wider">Thirasara Max Mobile</h1>
                    <p class="text-xs text-gray-600">Mobile Accessories, Retail & Technical Repair Center</p>
                </div>
                <div class="text-right">
                    <p class="text-sm font-bold text-gray-900">Official Order Receipt</p>
                    <p class="text-xs text-gray-600">Order: <?= htmlspecialchars($orderNumber) ?></p>
                </div>
            </div>
        </div>

        <!-- HERO SUCCESS BANNER -->
        <div class="bg-white rounded-2xl border-2 border-emerald-500/20 p-6 sm:p-8 mb-8 shadow-sm relative overflow-hidden">
            <div class="absolute top-0 right-0 -mt-10 -mr-10 w-44 h-44 bg-emerald-500/5 rounded-full blur-2xl pointer-events-none"></div>

            <div class="flex flex-col sm:flex-row items-center sm:items-start gap-5 text-center sm:text-left">
                <!-- Checkmark Icon -->
                <div class="w-16 h-16 rounded-2xl bg-emerald-100 text-emerald-600 flex items-center justify-center flex-shrink-0 shadow-inner">
                    <i data-lucide="check" class="w-9 h-9 stroke-[2.5]"></i>
                </div>

                <div class="flex-1 min-w-0">
                    <div class="flex flex-wrap items-center justify-center sm:justify-start gap-2 mb-2">
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-black uppercase tracking-wider bg-emerald-100 text-emerald-800">
                            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span> Order Confirmed
                        </span>
                        <span class="text-xs text-gray-500 font-medium">
                            Placed on <?= date('d M Y, h:i A', strtotime($order['created_at'])) ?>
                        </span>
                    </div>

                    <h1 class="text-2xl sm:text-3xl font-black text-gray-900 tracking-tight">
                        Thank you, <?= htmlspecialchars($order['customer_name'] ?? 'Valued Customer') ?>!
                    </h1>
                    <p class="text-sm text-gray-600 mt-1 max-w-2xl leading-relaxed">
                        Your order has been recorded successfully. A confirmation receipt has been dispatched to
                        <strong class="text-gray-900"><?= htmlspecialchars($order['customer_email'] ?? 'your email') ?></strong>.
                    </p>

                    <!-- Order Code Copy Badge -->
                    <div class="mt-4 inline-flex items-center gap-2 bg-gray-100 hover:bg-gray-200/80 px-3.5 py-1.5 rounded-xl border border-gray-200 transition group cursor-pointer"
                         onclick="copyOrderCode('<?= htmlspecialchars($orderNumber) ?>')" title="Click to copy order reference">
                        <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Order No:</span>
                        <span class="font-mono font-black text-sm text-brand-red tracking-wider" id="orderRefText">
                            <?= htmlspecialchars($orderNumber) ?>
                        </span>
                        <i data-lucide="copy" class="w-3.5 h-3.5 text-gray-400 group-hover:text-brand-red transition"></i>
                        <span id="copyTooltip" class="text-[11px] font-bold text-emerald-600 hidden">Copied!</span>
                    </div>
                </div>

                <!-- Quick Action Buttons on Banner (no-print) -->
                <div class="flex flex-col sm:flex-row lg:flex-col gap-2 w-full sm:w-auto mt-2 sm:mt-0 flex-shrink-0 no-print" id="quick-actions">
                    <a href="<?= APP_URL ?>/order-confirmation.php?order=<?= urlencode($orderNumber) ?>&download=pdf"
                       class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-xs font-bold transition shadow-sm">
                        <i data-lucide="download" class="w-4 h-4"></i> Download Tax Invoice
                    </a>
                    <button type="button" onclick="window.print()"
                            class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-white hover:bg-gray-100 text-gray-800 border border-gray-300 text-xs font-bold transition">
                        <i data-lucide="printer" class="w-4 h-4 text-gray-600"></i> Print Receipt
                    </button>
                </div>
            </div>
        </div>

        <!-- ORDER PROGRESS LIFECYCLE STEPPER (no-print if cancelled) -->
        <?php if ($orderStatus === 'cancelled'): ?>
            <div class="bg-red-50 border-2 border-red-200 rounded-2xl p-4 sm:p-5 mb-8 flex items-center gap-3.5 text-red-700">
                <i data-lucide="alert-octagon" class="w-6 h-6 flex-shrink-0"></i>
                <div class="text-xs sm:text-sm font-medium">
                    <strong class="font-bold">This order has been cancelled.</strong> If you have any inquiries regarding this cancellation or payments made, please contact customer support.
                </div>
            </div>
        <?php else: ?>
            <div class="bg-white rounded-2xl border border-gray-200 p-6 sm:p-8 mb-8 shadow-sm">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-sm font-black text-gray-900 uppercase tracking-wider flex items-center gap-2">
                        <i data-lucide="activity" class="w-4 h-4 text-brand-red"></i> Order Fulfillment Status
                    </h2>
                    <span class="px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider
                        <?= $orderStatus === 'delivered' ? 'bg-emerald-100 text-emerald-800' : ($orderStatus === 'shipped' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800') ?>">
                        <?= htmlspecialchars(ucfirst($orderStatus)) ?>
                    </span>
                </div>

                <!-- Stepper Bar -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 relative">
                    <?php 
                    $stepIndex = 1;
                    foreach ($statusSteps as $key => $meta): 
                        $isCompleted = ($currentStepRank >= $stepIndex);
                        $isCurrent   = ($currentStepRank === $stepIndex);
                    ?>
                        <div class="flex flex-col items-center text-center relative p-3 rounded-xl transition
                            <?= $isCurrent ? 'bg-brand-red/5 border border-brand-red/20' : ($isCompleted ? 'bg-gray-50' : 'opacity-50') ?>">
                            
                            <div class="w-10 h-10 rounded-full flex items-center justify-center mb-2 font-bold text-xs transition
                                <?= $isCompleted ? 'bg-emerald-500 text-white' : ($isCurrent ? 'bg-brand-red text-white ring-4 ring-brand-red/20' : 'bg-gray-200 text-gray-500') ?>">
                                <?php if ($isCompleted && !$isCurrent): ?>
                                    <i data-lucide="check" class="w-5 h-5 stroke-[3]"></i>
                                <?php else: ?>
                                    <i data-lucide="<?= $meta['icon'] ?>" class="w-4 h-4"></i>
                                <?php endif; ?>
                            </div>

                            <p class="text-xs font-bold text-gray-900"><?= $meta['label'] ?></p>
                            <p class="text-[10px] text-gray-500 mt-0.5 leading-tight"><?= $meta['desc'] ?></p>
                        </div>
                    <?php 
                        $stepIndex++;
                    endforeach; 
                    ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- MAIN DETAILS 2-COLUMN GRID -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-10">

            <!-- LEFT 2 COLUMNS: ITEMS LIST & SHIPPING INFO -->
            <div class="lg:col-span-2 space-y-8">

                <!-- LINE ITEMS -->
                <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden shadow-sm">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between bg-gray-50/50">
                        <h2 class="text-xs font-black text-gray-900 uppercase tracking-wider flex items-center gap-2">
                            <i data-lucide="shopping-bag" class="w-4 h-4 text-brand-red"></i> 
                            Items Purchased (<?= count($items) ?>)
                        </h2>
                        <span class="text-xs font-medium text-gray-500">Itemized Breakdown</span>
                    </div>

                    <div class="divide-y divide-gray-100">
                        <?php if (empty($items)): ?>
                            <div class="p-8 text-center text-gray-400 text-sm">
                                No items found in this order.
                            </div>
                        <?php else: ?>
                            <?php foreach ($items as $item): ?>
                                <div class="p-5 sm:p-6 flex items-start gap-4 hover:bg-gray-50/50 transition">
                                    <!-- Image -->
                                    <img src="<?= htmlspecialchars(getOrderImageUrl($item['image_url'] ?? null)) ?>"
                                         alt="<?= htmlspecialchars($item['name'] ?? 'Product') ?>"
                                         class="w-16 h-16 sm:w-20 sm:h-20 rounded-xl object-cover bg-gray-100 border border-gray-200 flex-shrink-0"
                                         onerror="this.src='<?= APP_URL ?>/assets/images/placeholder-product.svg'">

                                    <!-- Content -->
                                    <div class="flex-1 min-w-0">
                                        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-1">
                                            <div>
                                                <?php if (!empty($item['brand'])): ?>
                                                    <span class="text-[10px] font-black uppercase tracking-wider text-brand-red">
                                                        <?= htmlspecialchars($item['brand']) ?>
                                                    </span>
                                                <?php endif; ?>
                                                <h3 class="text-sm font-bold text-gray-900 leading-snug">
                                                    <?= htmlspecialchars($item['name'] ?? 'Product Name') ?>
                                                </h3>
                                            </div>

                                            <div class="text-left sm:text-right mt-1 sm:mt-0">
                                                <p class="text-sm font-black text-gray-900">
                                                    <?= formatLKR((float) ($item['subtotal'] ?? 0)) ?>
                                                </p>
                                                <p class="text-[11px] text-gray-500">
                                                    <?= formatLKR((float) ($item['unit_price'] ?? 0)) ?> × <?= (int) ($item['quantity'] ?? 1) ?>
                                                </p>
                                            </div>
                                        </div>

                                        <!-- Warranty / Repairable Badges -->
                                        <div class="mt-2.5 flex flex-wrap items-center gap-1.5">
                                            <?php if (!empty($item['warranty_period_months'])): ?>
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-bold bg-zinc-100 text-zinc-700">
                                                    <i data-lucide="shield-check" class="w-3 h-3 text-emerald-600"></i>
                                                    <?= (int) $item['warranty_period_months'] ?> Months Warranty
                                                </span>
                                            <?php endif; ?>

                                            <?php if (!empty($item['is_repairable'])): ?>
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-bold bg-amber-50 text-amber-700">
                                                    <i data-lucide="wrench" class="w-3 h-3 text-amber-600"></i> Repair Eligible
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- SHIPPING & RECIPIENT DETAILS -->
                <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
                    <h2 class="text-xs font-black text-gray-900 uppercase tracking-wider flex items-center gap-2 mb-4">
                        <i data-lucide="map-pin" class="w-4 h-4 text-brand-red"></i> Shipping & Contact Information
                    </h2>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 text-xs">
                        <div>
                            <span class="block text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">
                                Delivery Address
                            </span>
                            <div class="p-3.5 bg-gray-50 rounded-xl border border-gray-200 text-gray-900 font-medium leading-relaxed">
                                <?= nl2br(htmlspecialchars($order['shipping_address'] ?? 'No address specified')) ?>
                            </div>
                        </div>

                        <div>
                            <span class="block text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">
                                Contact & Recipient
                            </span>
                            <div class="p-3.5 bg-gray-50 rounded-xl border border-gray-200 text-gray-900 font-medium space-y-1.5">
                                <p class="flex items-center gap-2">
                                    <i data-lucide="user" class="w-3.5 h-3.5 text-gray-400"></i>
                                    <span><?= htmlspecialchars($order['customer_name'] ?? 'Customer') ?></span>
                                </p>
                                <p class="flex items-center gap-2">
                                    <i data-lucide="phone" class="w-3.5 h-3.5 text-gray-400"></i>
                                    <a href="tel:<?= htmlspecialchars($order['contact_phone'] ?? '') ?>" class="hover:text-brand-red">
                                        <?= htmlspecialchars($order['contact_phone'] ?? 'N/A') ?>
                                    </a>
                                </p>
                                <p class="flex items-center gap-2">
                                    <i data-lucide="mail" class="w-3.5 h-3.5 text-gray-400"></i>
                                    <span><?= htmlspecialchars($order['customer_email'] ?? 'N/A') ?></span>
                                </p>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($order['notes'])): ?>
                        <div class="mt-4 pt-4 border-t border-gray-100">
                            <span class="block text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">
                                Delivery Instructions / Customer Notes
                            </span>
                            <p class="text-xs text-gray-700 italic bg-amber-50/50 border border-amber-100 p-3 rounded-xl">
                                "<?= htmlspecialchars($order['notes']) ?>"
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

            <!-- RIGHT 1 COLUMN: FINANCIAL SUMMARY & SUPPORT -->
            <div class="space-y-6">

                <!-- PAYMENT & TOTALS CARD -->
                <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
                    <h2 class="text-xs font-black text-gray-900 uppercase tracking-wider flex items-center gap-2 mb-4 pb-3 border-b border-gray-100">
                        <i data-lucide="credit-card" class="w-4 h-4 text-brand-red"></i> Payment Summary
                    </h2>

                    <div class="space-y-3 text-xs">
                        <div class="flex justify-between items-center text-gray-600">
                            <span>Items Subtotal</span>
                            <span class="font-bold text-gray-900"><?= formatLKR($itemsSubtotal) ?></span>
                        </div>

                        <div class="flex justify-between items-center text-gray-600">
                            <span>Delivery Fee</span>
                            <span class="font-bold text-emerald-600">
                                <?= $deliveryFee > 0 ? formatLKR($deliveryFee) : 'FREE' ?>
                            </span>
                        </div>

                        <?php if ($paymentFee > 0): ?>
                            <div class="flex justify-between items-center text-gray-600">
                                <span>Processing / COD Fee</span>
                                <span class="font-bold text-gray-900"><?= formatLKR($paymentFee) ?></span>
                            </div>
                        <?php endif; ?>

                        <div class="pt-3 border-t border-gray-200 flex justify-between items-baseline">
                            <div>
                                <span class="block text-sm font-black text-gray-900">Total Amount</span>
                                <span class="text-[10px] text-gray-400">All Taxes & Charges Included</span>
                            </div>
                            <span class="text-xl font-black text-brand-red">
                                <?= formatLKR($grandTotal) ?>
                            </span>
                        </div>
                    </div>

                    <!-- Payment Status Pill Box -->
                    <div class="mt-5 p-3.5 rounded-xl border <?= $paymentStatus === 'paid' ? 'bg-emerald-50 border-emerald-200 text-emerald-900' : 'bg-amber-50 border-amber-200 text-amber-900' ?>">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Method</span>
                            <span class="text-xs font-black uppercase">
                                <?= htmlspecialchars(str_replace('_', ' ', $paymentMethod === 'payhere' ? 'PayHere Gateway' : 'Cash on Delivery')) ?>
                            </span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Status</span>
                            <span class="inline-flex items-center gap-1 text-xs font-bold">
                                <?php if ($paymentStatus === 'paid'): ?>
                                    <i data-lucide="check-circle" class="w-3.5 h-3.5 text-emerald-600"></i> Payment Verified
                                <?php else: ?>
                                    <i data-lucide="clock" class="w-3.5 h-3.5 text-amber-600"></i> Due on Delivery
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php if (!empty($payment['transaction_id']) && $payment['transaction_id'] !== 'N/A' && !str_starts_with($payment['transaction_id'], 'PENDING-')): ?>
                            <div class="mt-2 pt-2 border-t border-gray-200/50 flex justify-between text-[10px]">
                                <span class="text-gray-500">Txn Ref:</span>
                                <span class="font-mono text-gray-700"><?= htmlspecialchars($payment['transaction_id']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- INVOICE INFO & OFFICIAL DOCUMENTATION CARD -->
                <div class="bg-zinc-900 text-white rounded-2xl p-6 shadow-md border-t-4 border-brand-red">
                    <div class="flex items-center gap-2 mb-3">
                        <i data-lucide="file-text" class="w-4 h-4 text-brand-red"></i>
                        <h3 class="text-xs font-black uppercase tracking-wider">Official Tax Invoice</h3>
                    </div>
                    <p class="text-xs text-gray-300 leading-relaxed mb-4">
                        A computerized PDF tax invoice has been generated for your purchase containing full itemization, warranty registration numbers, and digital verification codes.
                    </p>
                    <a href="<?= APP_URL ?>/order-confirmation.php?order=<?= urlencode($orderNumber) ?>&download=pdf"
                       class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-xs font-bold transition shadow-sm">
                        <i data-lucide="download" class="w-4 h-4"></i> Download PDF Invoice
                    </a>
                </div>

                <!-- NEXT STEPS / NAVIGATION (no-print) -->
                <div class="bg-white rounded-2xl border border-gray-200 p-6 space-y-2.5 no-print">
                    <h3 class="text-xs font-black text-gray-900 uppercase tracking-wider mb-2">What would you like to do next?</h3>
                    
                    <?php if (isLoggedIn()): ?>
                        <a href="<?= APP_URL ?>/customer/orders.php"
                           class="w-full inline-flex items-center justify-between px-4 py-2.5 rounded-xl bg-gray-50 hover:bg-gray-100 border border-gray-200 text-xs font-bold text-gray-800 transition">
                            <span class="flex items-center gap-2">
                                <i data-lucide="package" class="w-4 h-4 text-brand-red"></i> View Order History
                            </span>
                            <i data-lucide="arrow-right" class="w-4 h-4 text-gray-400"></i>
                        </a>
                    <?php endif; ?>

                    <a href="<?= APP_URL ?>/products.php"
                       class="w-full inline-flex items-center justify-between px-4 py-2.5 rounded-xl bg-gray-50 hover:bg-gray-100 border border-gray-200 text-xs font-bold text-gray-800 transition">
                        <span class="flex items-center gap-2">
                            <i data-lucide="shopping-cart" class="w-4 h-4 text-brand-red"></i> Continue Shopping
                        </span>
                        <i data-lucide="arrow-right" class="w-4 h-4 text-gray-400"></i>
                    </a>

                    <a href="<?= APP_URL ?>/repair-booking.php"
                       class="w-full inline-flex items-center justify-between px-4 py-2.5 rounded-xl bg-gray-50 hover:bg-gray-100 border border-gray-200 text-xs font-bold text-gray-800 transition">
                        <span class="flex items-center gap-2">
                            <i data-lucide="wrench" class="w-4 h-4 text-brand-red"></i> Book a Repair Service
                        </span>
                        <i data-lucide="arrow-right" class="w-4 h-4 text-gray-400"></i>
                    </a>
                </div>

            </div>

        </div>

    </div>
</div>

<script>
function copyOrderCode(code) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(code).then(() => {
            showCopyFeedback();
        }).catch(() => {
            fallbackCopy(code);
        });
    } else {
        fallbackCopy(code);
    }
}

function fallbackCopy(code) {
    const tempInput = document.createElement('input');
    tempInput.value = code;
    document.body.appendChild(tempInput);
    tempInput.select();
    document.execCommand('copy');
    document.body.removeChild(tempInput);
    showCopyFeedback();
}

function showCopyFeedback() {
    const tip = document.getElementById('copyTooltip');
    if (tip) {
        tip.classList.remove('hidden');
        setTimeout(() => {
            tip.classList.add('hidden');
        }, 2000);
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
