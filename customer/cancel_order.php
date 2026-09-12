<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

/*Authentication*/

if (!isLoggedIn()) {

    setFlash(
        'error',
        'Please sign in to manage your orders.'
    );

    header(
        'Location: ' .
        APP_URL .
        '/login.php?redirect=customer/orders.php'
    );

    exit;
}


/*Current User*/

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {

    setFlash(
        'error',
        'Your session has expired. Please sign in again.'
    );

    header(
        'Location: ' .
        APP_URL .
        '/login.php'
    );

    exit;
}


/*Method Guard*/

if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'
) {

    setFlash(
        'error',
        'Invalid request method.'
    );

    header(
        'Location: ' .
        APP_URL .
        '/customer/orders.php'
    );

    exit;
}


/*CSRF Protection*/

$submittedToken = (string) (
    $_POST['csrf_token']
    ?? ''
);

$sessionToken = (string) (
    $_SESSION['csrf_token']
    ?? ''
);

if (
    $sessionToken === '' ||
    $submittedToken === '' ||
    !hash_equals(
        $sessionToken,
        $submittedToken
    )
) {

    setFlash(
        'error',
        'Your session could not be verified. Please try again.'
    );

    header(
        'Location: ' .
        APP_URL .
        '/customer/orders.php'
    );

    exit;
}


/* Input Validation*/

$orderNumber =
    trim(
        (string) (
            $_POST['order_number']
            ?? ''
        )
    );

if ($orderNumber === '') {

    setFlash(
        'error',
        'No order was specified for cancellation.'
    );

    header(
        'Location: ' .
        APP_URL .
        '/customer/orders.php'
    );

    exit;
}


/*Cancellation Eligibility*/

$cancellableStatuses = [
    'placed',
    'processing'
];


/*Database*/

$db = Database::getConnection();


/* Cancel The Order*/

try {

    $db->beginTransaction();


    /* Lock The Order Row*/

    $orderStmt = $db->prepare("
        SELECT
            id,
            order_number,
            user_id,
            order_status,
            payment_status
        FROM orders
        WHERE order_number = ?
        AND user_id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $orderStmt->execute([
        $orderNumber,
        $userId
    ]);

    $order =
        $orderStmt->fetch(
            PDO::FETCH_ASSOC
        );


    /*Ownership Check*/

    if (!$order) {

        $db->rollBack();

        setFlash(
            'error',
            'The requested order could not be found.'
        );

        header(
            'Location: ' .
            APP_URL .
            '/customer/orders.php'
        );

        exit;
    }


    /*Status Check */

    if (
        !in_array(
            $order['order_status'],
            $cancellableStatuses,
            true
        )
    ) {

        $db->rollBack();

        setFlash(
            'error',
            'This order can no longer be cancelled because ' .
            'it has already ' .
            (
                $order['order_status'] === 'cancelled'
                    ? 'been cancelled.'
                    : 'progressed past processing.'
            )
        );

        header(
            'Location: ' .
            APP_URL .
            '/customer/orders.php?order=' .
            urlencode($order['order_number'])
        );

        exit;
    }


    /*
    |----------------------------------------------------------------
    | Determine New Payment Status
    |----------------------------------------------------------------
    |
    | - paid      -> refunded (a refund now needs to be processed)
    | - pending   -> failed   (the pending payment will not be collected)
    | - failed    -> unchanged
    | - refunded  -> unchanged
    |----------------------------------------------------------------
    */

    $newPaymentStatus = match ($order['payment_status']) {
        'paid' => 'refunded',
        'pending' => 'failed',
        default => $order['payment_status'],
    };


    /*Update The Order*/

    $placeholders =
        implode(
            ',',
            array_fill(
                0,
                count($cancellableStatuses),
                '?'
            )
        );

    $updateOrderStmt = $db->prepare("
        UPDATE orders
        SET
            order_status = 'cancelled',
            payment_status = ?,
            updated_at = NOW()
        WHERE id = ?
        AND user_id = ?
        AND order_status IN ({$placeholders})
    ");

    $updateOrderStmt->execute(
        array_merge(
            [
                $newPaymentStatus,
                $order['id'],
                $userId
            ],
            $cancellableStatuses
        )
    );

    if ($updateOrderStmt->rowCount() !== 1) {

        $db->rollBack();

        setFlash(
            'error',
            'This order could not be cancelled. ' .
            'Please refresh and try again.'
        );

        header(
            'Location: ' .
            APP_URL .
            '/customer/orders.php?order=' .
            urlencode($order['order_number'])
        );

        exit;
    }


    /*Load Order Items*/

    $itemsStmt = $db->prepare("
        SELECT
            product_id,
            quantity
        FROM order_items
        WHERE order_id = ?
    ");

    $itemsStmt->execute([
        $order['id']
    ]);

    $items =
        $itemsStmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    /* Restore Stock*/

    $restoreStockStmt = $db->prepare("
        INSERT INTO inventory
            (product_id, stock_quantity, low_stock_threshold, updated_at)
        VALUES
            (?, ?, 5, NOW())
        ON DUPLICATE KEY UPDATE
            stock_quantity = stock_quantity + VALUES(stock_quantity),
            updated_at = NOW()
    ");

    $reactivateProductStmt = $db->prepare("
        UPDATE products
        SET
            status = 'available',
            updated_at = NOW()
        WHERE id = ?
        AND status = 'out_of_stock'
    ");

    foreach ($items as $item) {

        $quantity = (int) $item['quantity'];

        if ($quantity <= 0) {
            continue;
        }

        $restoreStockStmt->execute([
            (int) $item['product_id'],
            $quantity
        ]);

        $reactivateProductStmt->execute([
            (int) $item['product_id']
        ]);
    }


    /*Update Pending Payment Record */

    if ($order['payment_status'] === 'pending') {

        $updatePaymentStmt = $db->prepare("
            UPDATE payments
            SET payment_status = 'failed'
            WHERE order_id = ?
            AND payment_status = 'pending'
        ");

        $updatePaymentStmt->execute([
            $order['id']
        ]);
    }


    /* Notify The Customer*/

    $notifyStmt = $db->prepare("
        INSERT INTO notifications
            (user_id, title, message, type, is_read, email_sent, created_at)
        VALUES
            (?, ?, ?, 'general', 0, 0, NOW())
    ");

    $notifyStmt->execute([
        $userId,
        'Order Cancelled',
        'Your order ' .
        $order['order_number'] .
        ' has been cancelled and any reserved stock has been released.'
    ]);


    $db->commit();


    setFlash(
        'success',
        'Order ' .
        $order['order_number'] .
        ' has been cancelled successfully.'
    );

    header(
        'Location: ' .
        APP_URL .
        '/customer/orders.php?order=' .
        urlencode($order['order_number'])
    );

    exit;

} catch (Throwable $exception) {

    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log(
        '[cancel_order] Failed to cancel order "' .
        $orderNumber .
        '" for user ' .
        $userId .
        ': ' .
        $exception->getMessage()
    );

    setFlash(
        'error',
        'Something went wrong while cancelling your order. ' .
        'Please try again or contact support.'
    );

    header(
        'Location: ' .
        APP_URL .
        '/customer/orders.php'
    );

    exit;
}