<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/mailer.php';

// Only accept POST requests from PayHere
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$merchantId      = (string) ($_POST['merchant_id'] ?? '');
$orderNumber     = (string) ($_POST['order_id'] ?? '');
$paymentId       = (string) ($_POST['payment_id'] ?? '');
$payhereAmount   = (string) ($_POST['payhere_amount'] ?? '');
$payhereCurrency = (string) ($_POST['payhere_currency'] ?? '');
$statusCode      = (string) ($_POST['status_code'] ?? '');
$md5sig          = (string) ($_POST['md5sig'] ?? '');
$internalOrderId = (int) ($_POST['custom_1'] ?? 0);

$merchantSecret  = defined('PAYHERE_MERCHANT_SECRET') ? PAYHERE_MERCHANT_SECRET : '';

// Generate signature to verify authenticity
$localMd5sig = strtoupper(
    md5(
        $merchantId . 
        $orderNumber . 
        $payhereAmount . 
        $payhereCurrency . 
        $statusCode . 
        strtoupper(md5($merchantSecret))
    )
);

// Log IPN notification
error_log("PayHere IPN Received: Order={$orderNumber}, Status={$statusCode}, PaymentId={$paymentId}");

if (!hash_equals($localMd5sig, $md5sig)) {
    error_log("PayHere Signature Verification Failed for Order {$orderNumber}. Local: {$localMd5sig}, Received: {$md5sig}");
    http_response_code(400);
    exit('Invalid Signature');
}

$db = Database::getConnection();

// Locate order
if ($internalOrderId > 0) {
    $stmt = $db->prepare("SELECT id, order_number, payment_status FROM orders WHERE id = ? LIMIT 1");
    $stmt->execute([$internalOrderId]);
} else {
    $stmt = $db->prepare("SELECT id, order_number, payment_status FROM orders WHERE order_number = ? LIMIT 1");
    $stmt->execute([$orderNumber]);
}
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    error_log("PayHere IPN: Order not found: {$orderNumber}");
    http_response_code(404);
    exit('Order Not Found');
}

$orderId = (int) $order['id'];

// Status Code 2 = SUCCESS
if ((int) $statusCode === 2) {
    if ($order['payment_status'] !== 'paid') {
        try {
            $db->beginTransaction();

            $updateOrder = $db->prepare("
                UPDATE orders
                SET payment_status = 'paid',
                    order_status = 'processing',
                    updated_at = NOW()
                WHERE id = ?
            ");
            $updateOrder->execute([$orderId]);

            $updatePayment = $db->prepare("
                UPDATE payments
                SET transaction_id = ?,
                    payment_method = 'payhere',
                    payment_status = 'success',
                    payment_payload = ?,
                    payment_date = NOW()
                WHERE order_id = ?
            ");
            $updatePayment->execute([
                $paymentId,
                json_encode($_POST, JSON_UNESCAPED_SLASHES),
                $orderId
            ]);

            $db->commit();
            error_log("PayHere IPN: Order {$orderNumber} successfully marked as PAID");

            // Send payment confirmation email with invoice PDF
            sendOrderStatusUpdateEmail($orderId, 'processing', 'paid');

        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("PayHere IPN DB Exception: " . $e->getMessage());
            http_response_code(500);
            exit('Database Error');
        }
    }
} elseif ((int) $statusCode === -1 || (int) $statusCode === -2) {
    // Payment Failed or Cancelled - restore inventory
    if ($order['payment_status'] === 'pending') {
        try {
            $db->beginTransaction();

            // Fetch items
            $itemStmt = $db->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
            $itemStmt->execute([$orderId]);
            $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

            // Restore stock
            $stockStmt = $db->prepare("UPDATE inventory SET stock_quantity = stock_quantity + ?, updated_at = CURRENT_TIMESTAMP WHERE product_id = ?");
            foreach ($items as $it) {
                $stockStmt->execute([(int)$it['quantity'], (int)$it['product_id']]);
            }

            // Mark cancelled
            $db->prepare("UPDATE orders SET payment_status = 'failed', order_status = 'cancelled', updated_at = NOW() WHERE id = ?")->execute([$orderId]);
            $db->prepare("UPDATE payments SET payment_status = 'failed', payment_payload = ? WHERE order_id = ?")->execute([
                json_encode($_POST, JSON_UNESCAPED_SLASHES),
                $orderId
            ]);

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        }
    }
}

http_response_code(200);
echo 'OK';
