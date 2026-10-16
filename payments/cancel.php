<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Authentication
requireAuth(['customer', 'admin']);

$currentUser = getCurrentUser();
$userId = (int) ($currentUser['id'] ?? 0);
$orderId = (int) ($_GET['order_id'] ?? 0);

if ($userId <= 0 || $orderId <= 0) {
    header('Location: ' . APP_URL . '/cart.php');
    exit;
}

$pdo = Database::getConnection();

// Fetch Order
$orderStmt = $pdo->prepare("
    SELECT id, order_number, user_id, payment_status, order_status
    FROM orders
    WHERE id = ? AND user_id = ?
    LIMIT 1
");
$orderStmt->execute([$orderId, $userId]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Location: ' . APP_URL . '/cart.php');
    exit;
}

// If already paid, do not cancel
if ($order['payment_status'] === 'paid') {
    setFlash('info', 'This order has already been paid for and confirmed.');
    header('Location: ' . APP_URL . '/customer/orders.php?order=' . urlencode($order['order_number']));
    exit;
}

// If already cancelled, just notify and redirect
if ($order['order_status'] === 'cancelled') {
    setFlash('warning', 'Payment was aborted and this order is already marked as cancelled.');
    header('Location: ' . APP_URL . '/cart.php');
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Fetch order items to restore reserved stock
    $itemStmt = $pdo->prepare("
        SELECT product_id, quantity 
        FROM order_items 
        WHERE order_id = ?
    ");
    $itemStmt->execute([$orderId]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Return stock to inventory
    $stockStmt = $pdo->prepare("
        UPDATE inventory 
        SET stock_quantity = stock_quantity + ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE product_id = ?
    ");

    foreach ($items as $item) {
        $stockStmt->execute([
            (int) $item['quantity'],
            (int) $item['product_id']
        ]);
    }

    // 3. Update Order state to cancelled / failed
    $updateOrder = $pdo->prepare("
        UPDATE orders 
        SET payment_status = 'failed',
            order_status = 'cancelled',
            updated_at = NOW()
        WHERE id = ?
    ");
    $updateOrder->execute([$orderId]);

    // 4. Update Payment log
    $updatePayment = $pdo->prepare("
        UPDATE payments 
        SET payment_status = 'failed',
            payment_payload = ?
        WHERE order_id = ?
    ");
    $cancelPayload = json_encode([
        'reason'       => 'User cancelled or aborted gateway authorization',
        'cancelled_at' => date('c')
    ], JSON_UNESCAPED_SLASHES);
    $updatePayment->execute([$cancelPayload, $orderId]);

    $pdo->commit();

    setFlash('warning', 'Payment was cancelled. Your pending order was cancelled and inventory was released. You may try again whenever you are ready.');
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Cancel Order Error: ' . $e->getMessage());
    setFlash('error', 'An issue occurred while processing payment cancellation.');
}

header('Location: ' . APP_URL . '/cart.php');
exit;
