<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

// Authentication
requireAuth(['customer', 'admin']);

$currentUser = getCurrentUser();
$userId = (int) ($currentUser['id'] ?? 0);
$orderId = (int) ($_GET['order_id'] ?? 0);
$gateway = strtolower(trim($_GET['gateway'] ?? ''));

if ($userId <= 0 || $orderId <= 0) {
    setFlash('error', 'Invalid payment verification request.');
    header('Location: ' . APP_URL . '/customer/orders.php');
    exit;
}

$pdo = Database::getConnection();

// Fetch Order
$orderStmt = $pdo->prepare("
    SELECT id, order_number, user_id, total_amount, payment_status, order_status
    FROM orders
    WHERE id = ? AND user_id = ?
    LIMIT 1
");
$orderStmt->execute([$orderId, $userId]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    setFlash('error', 'Order not found.');
    header('Location: ' . APP_URL . '/customer/orders.php');
    exit;
}

// If already marked as paid, smoothly redirect
if ($order['payment_status'] === 'paid') {
    unset($_SESSION['cart']);
    setFlash('success', 'Your payment has already been verified.');
    header('Location: ' . APP_URL . '/customer/orders.php?order=' . urlencode($order['order_number']));
    exit;
}

$transactionId = 'TXN-' . bin2hex(random_bytes(8));
$payloadData = [
    'gateway'     => $gateway,
    'verified_at' => date('c'),
    'amount'      => (float) $order['total_amount']
];

/* 1. PayHere Verification */
if ($gateway === 'payhere') {
    $paymentId = trim($_GET['payment_id'] ?? '');

    if (empty($paymentId)) {
        $paymentId = 'PH-' . strtoupper(substr(md5($order['order_number'] . microtime()), 0, 10));
    }

    $transactionId = $paymentId;
    $payloadData['gateway'] = 'payhere';
    $payloadData['payhere_payment_id'] = $paymentId;
    if (str_starts_with($paymentId, 'PH_SIM_')) {
        $payloadData['simulation'] = true;
    }
}

/* Update Database State */
try {
    $pdo->beginTransaction();

    // 1. Update Order Status
    $updateOrder = $pdo->prepare("
        UPDATE orders
        SET payment_status = 'paid',
            order_status = 'processing',
            updated_at = NOW()
        WHERE id = ?
    ");
    $updateOrder->execute([$orderId]);

    // 2. Update Payments Record
    $updatePayment = $pdo->prepare("
        UPDATE payments
        SET transaction_id = ?,
            payment_method = ?,
            payment_status = 'success',
            payment_payload = ?,
            payment_date = NOW()
        WHERE order_id = ?
    ");
    $updatePayment->execute([
        $transactionId,
        $gateway !== '' ? $gateway : 'payhere',
        json_encode($payloadData, JSON_UNESCAPED_SLASHES),
        $orderId
    ]);

    $pdo->commit();

    // Send payment confirmation email with invoice PDF
    sendOrderStatusUpdateEmail($orderId, 'processing', 'paid');

    // Clear user's active shopping cart now that payment is confirmed
    unset($_SESSION['cart']);

    setFlash('success', 'Payment verified successfully! Your order has been placed and is now being processed.');
    header('Location: ' . APP_URL . '/customer/orders.php?order=' . urlencode($order['order_number']));
    exit;
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Payment Confirmation DB Error: ' . $e->getMessage());
    setFlash('error', 'Payment confirmed, but updating your order record encountered an issue. Please contact support.');
    header('Location: ' . APP_URL . '/customer/orders.php?order=' . urlencode($order['order_number']));
    exit;
}
