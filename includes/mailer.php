<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/invoice_generator.php';


/* ──────────────────────────────────────────────────────────────────────────
 * SHARED SMTP CONFIGURATION HELPER
 * ────────────────────────────────────────────────────────────────────────── */
function configureSMTP(PHPMailer $mail): void
{
    $mail->isSMTP();
    $mail->Host       = $_ENV['MAIL_HOST']     ?? 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = $_ENV['MAIL_USERNAME'] ?? '';
    $mail->Password   = $_ENV['MAIL_PASSWORD'] ?? '';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = (int) ($_ENV['MAIL_PORT'] ?? 587);

    $mail->setFrom(
        $_ENV['MAIL_FROM_EMAIL'] ?? '',
        $_ENV['MAIL_FROM_NAME']  ?? 'Thirasara Max'
    );
}


/* ──────────────────────────────────────────────────────────────────────────
 * PASSWORD RESET EMAIL
 * ────────────────────────────────────────────────────────────────────────── */
function sendPasswordResetEmail(
    string $email,
    string $name,
    string $resetLink
): bool {

    $mail = new PHPMailer(true);

    try {

        configureSMTP($mail);
        $mail->addAddress($email, $name);

        $mail->isHTML(true);
        $mail->Subject = 'Reset Your Thirasara Max Password';

        $mail->Body = '
        <!DOCTYPE html>
        <html>
        <body style="margin:0;padding:30px;background:#f5f5f5;font-family:Arial,sans-serif;">
            <div style="max-width:600px;margin:auto;background:#ffffff;padding:35px;border-radius:12px;">
                <h2 style="color:#e52b34;margin-top:0;">Thirasara Max</h2>
                <p>Hello ' . htmlspecialchars($name) . ',</p>
                <p>We received a request to reset the password for your Thirasara Max account.</p>
                <p>Click the button below to create a new password:</p>
                <div style="text-align:center;margin:30px 0;">
                    <a href="' . htmlspecialchars($resetLink) . '"
                       style="display:inline-block;padding:13px 25px;background:#e52b34;color:#ffffff;text-decoration:none;border-radius:7px;font-weight:bold;">
                        Reset Password
                    </a>
                </div>
                <p>This link will expire in <strong>30 minutes</strong>.</p>
                <p style="color:#777;font-size:13px;">If you did not request a password reset, you can safely ignore this email.</p>
                <hr style="border:0;border-top:1px solid #eeeeee;margin:25px 0;">
                <p style="color:#999;font-size:12px;">© ' . date('Y') . ' Thirasara Max. All rights reserved.</p>
            </div>
        </body>
        </html>';

        $mail->AltBody =
            "Hello $name,\n\n" .
            "We received a request to reset your Thirasara Max password.\n\n" .
            "Reset your password here:\n$resetLink\n\n" .
            "This link expires in 30 minutes.\n\n" .
            "If you did not request this, you can ignore this email.";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log('Password Reset Email Error: ' . $mail->ErrorInfo);
        return false;
    }
}


/* ──────────────────────────────────────────────────────────────────────────
 * ORDER CONFIRMATION EMAIL  (sent immediately when order is placed)
 * Parameters: $orderId (int) — DB primary key of the order
 * ────────────────────────────────────────────────────────────────────────── */
function sendOrderConfirmationEmail(int $orderId): bool
{
    // Load order data
    $data = loadOrderEmailData($orderId);
    if (!$data) {
        error_log("sendOrderConfirmationEmail: Order #{$orderId} not found.");
        return false;
    }

    ['order' => $order, 'items' => $items, 'payment' => $payment] = $data;

    $customerEmail = (string) ($order['customer_email'] ?? '');
    $customerName  = (string) ($order['customer_name']  ?? 'Valued Customer');

    if (empty($customerEmail)) {
        error_log("sendOrderConfirmationEmail: No email for order #{$orderId}.");
        return false;
    }

    // Generate PDF invoice
    $pdfPath = generateInvoicePdf($orderId, $order, $items, $payment);

    $mail = new PHPMailer(true);
    try {
        configureSMTP($mail);
        $mail->addAddress($customerEmail, $customerName);

        $mail->isHTML(true);
        $mail->Subject = '✅ Order Confirmed — ' . $order['order_number'] . ' | Thirasara Max';

        $mail->Body = buildOrderEmailHtml($order, $items, $payment, 'confirmed');
        $mail->AltBody = buildOrderEmailPlain($order, 'confirmed');

        // Attach PDF invoice if generated
        if ($pdfPath && file_exists($pdfPath)) {
            $invNum = $order['invoice_number'] ?? ('INV-' . date('Ymd', strtotime($order['created_at'])) . '-' . sprintf('%04d', $orderId));
            $mail->addAttachment($pdfPath, $invNum . '.pdf');
        }

        $mail->send();

        // Clean up temp PDF
        if ($pdfPath && file_exists($pdfPath)) {
            @unlink($pdfPath);
        }

        error_log("Order confirmation email sent for order #{$orderId} to {$customerEmail}");
        return true;

    } catch (Exception $e) {
        error_log('Order Confirmation Email Error: ' . $mail->ErrorInfo);
        if ($pdfPath && file_exists($pdfPath)) {
            @unlink($pdfPath);
        }
        return false;
    }
}


/* ──────────────────────────────────────────────────────────────────────────
 * ORDER STATUS UPDATE EMAIL  (sent when admin changes order/payment status)
 * Parameters:
 *   $orderId        — DB primary key
 *   $newOrderStatus — e.g. 'processing', 'shipped', 'delivered', 'cancelled'
 *   $newPaymentStatus — e.g. 'paid', 'pending', 'refunded'
 * ────────────────────────────────────────────────────────────────────────── */
function sendOrderStatusUpdateEmail(
    int    $orderId,
    string $newOrderStatus,
    string $newPaymentStatus
): bool {
    $data = loadOrderEmailData($orderId);
    if (!$data) {
        error_log("sendOrderStatusUpdateEmail: Order #{$orderId} not found.");
        return false;
    }

    ['order' => $order, 'items' => $items, 'payment' => $payment] = $data;

    // Merge the new statuses (DB may not have committed yet at call time)
    $order['order_status']   = $newOrderStatus;
    $order['payment_status'] = $newPaymentStatus;

    $customerEmail = (string) ($order['customer_email'] ?? '');
    $customerName  = (string) ($order['customer_name']  ?? 'Valued Customer');

    if (empty($customerEmail)) {
        error_log("sendOrderStatusUpdateEmail: No email for order #{$orderId}.");
        return false;
    }

    // Determine email type from statuses
    $emailType = 'status_update';
    if ($newOrderStatus === 'shipped') {
        $emailType = 'shipped';
    } elseif ($newOrderStatus === 'delivered') {
        $emailType = 'delivered';
    } elseif ($newOrderStatus === 'cancelled') {
        $emailType = 'cancelled';
    } elseif ($newPaymentStatus === 'paid') {
        $emailType = 'paid';
    } elseif ($newPaymentStatus === 'refunded') {
        $emailType = 'refunded';
    }

    // Attach PDF invoice for delivered / paid orders
    $pdfPath = null;
    if (in_array($emailType, ['delivered', 'paid'], true)) {
        $pdfPath = generateInvoicePdf($orderId, $order, $items, $payment);
    }

    $subjectMap = [
        'shipped'       => '🚚 Your Order Has Been Shipped — ' . $order['order_number'],
        'delivered'     => '📦 Order Delivered — ' . $order['order_number'],
        'cancelled'     => '❌ Order Cancelled — ' . $order['order_number'],
        'paid'          => '💳 Payment Confirmed — ' . $order['order_number'],
        'refunded'      => '💰 Order Refunded — ' . $order['order_number'],
        'status_update' => '📋 Order Update — ' . $order['order_number'],
    ];

    $subject = ($subjectMap[$emailType] ?? $subjectMap['status_update']) . ' | Thirasara Max';

    $mail = new PHPMailer(true);
    try {
        configureSMTP($mail);
        $mail->addAddress($customerEmail, $customerName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = buildOrderEmailHtml($order, $items, $payment, $emailType);
        $mail->AltBody = buildOrderEmailPlain($order, $emailType);

        if ($pdfPath && file_exists($pdfPath)) {
            $invNum = $order['invoice_number'] ?? ('INV-' . date('Ymd', strtotime($order['created_at'])) . '-' . sprintf('%04d', $orderId));
            $mail->addAttachment($pdfPath, $invNum . '.pdf');
        }

        $mail->send();

        if ($pdfPath && file_exists($pdfPath)) {
            @unlink($pdfPath);
        }

        error_log("Order status update email ({$emailType}) sent for order #{$orderId} to {$customerEmail}");
        return true;

    } catch (Exception $e) {
        error_log('Order Status Update Email Error: ' . $mail->ErrorInfo);
        if ($pdfPath && file_exists($pdfPath)) {
            @unlink($pdfPath);
        }
        return false;
    }
}


/* ──────────────────────────────────────────────────────────────────────────
 * INTERNAL HELPERS
 * ────────────────────────────────────────────────────────────────────────── */

/**
 * Fetch all order data needed for emails from the database.
 * Returns ['order'=>[...], 'items'=>[[...]], 'payment'=>[...]] or null.
 */
function loadOrderEmailData(int $orderId): ?array
{
    require_once __DIR__ . '/../config/database.php';
    $pdo = Database::getConnection();

    $stmt = $pdo->prepare("
        SELECT
            o.*,
            u.name    AS customer_name,
            u.email   AS customer_email,
            u.phone   AS customer_phone_registered,
            inv.invoice_number
        FROM orders o
        LEFT JOIN users    u   ON u.id   = o.user_id
        LEFT JOIN invoices inv ON inv.order_id = o.id
        WHERE o.id = ?
        LIMIT 1
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        return null;
    }

    $itemStmt = $db->prepare("
        SELECT
            oi.quantity, oi.unit_price, oi.subtotal,
            p.name, p.brand
        FROM order_items oi
        LEFT JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ?
        ORDER BY oi.id ASC
    ");
    $itemStmt->execute([$orderId]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    $payStmt = $db->prepare("
        SELECT transaction_id, payment_method, amount, payment_status, payment_payload, payment_date
        FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1
    ");
    $payStmt->execute([$orderId]);
    $payment = $payStmt->fetch(PDO::FETCH_ASSOC);

    return ['order' => $order, 'items' => $items, 'payment' => $payment ?: []];
}


/**
 * Build the HTML email body for order-related notifications.
 */
function buildOrderEmailHtml(array $order, array $items, array $payment, string $type): string
{
    $brandColor = '#e52b34';
    $orderNum   = htmlspecialchars($order['order_number'] ?? '');
    $name       = htmlspecialchars($order['customer_name'] ?? 'Valued Customer');
    $total      = 'Rs. ' . number_format((float)($order['total_amount'] ?? 0), 2);
    $orderDate  = date('d M Y, h:i A', strtotime($order['created_at'] ?? 'now'));
    $oStatus    = ucfirst($order['order_status']   ?? '');
    $pStatus    = ucfirst($order['payment_status'] ?? '');
    $address    = nl2br(htmlspecialchars($order['shipping_address'] ?? ''));
    $phone      = htmlspecialchars($order['contact_phone'] ?? '');
    $payMethod  = strtoupper(str_replace('_', ' ', $payment['payment_method'] ?? 'N/A'));

    // Header message based on type
    $headlines = [
        'confirmed'     => ['🎉 Order Confirmed!',    "Your order <strong>#{$orderNum}</strong> has been placed successfully. We're preparing it for you!"],
        'paid'          => ['💳 Payment Confirmed!',   "Great news, <strong>{$name}</strong>! Your payment for order <strong>#{$orderNum}</strong> has been confirmed."],
        'shipped'       => ['🚚 Your Order Is On Its Way!', "Your order <strong>#{$orderNum}</strong> has been shipped and is on its way to you!"],
        'delivered'     => ['📦 Order Delivered!',     "Your order <strong>#{$orderNum}</strong> has been delivered. We hope you love your purchase!"],
        'cancelled'     => ['❌ Order Cancelled',      "Your order <strong>#{$orderNum}</strong> has been cancelled. If you have questions, please contact support."],
        'refunded'      => ['💰 Refund Processed',     "A refund has been initiated for order <strong>#{$orderNum}</strong>. Please allow 3–7 business days."],
        'status_update' => ['📋 Order Update',         "Your order <strong>#{$orderNum}</strong> has been updated. See the details below."],
    ];

    [$headline, $intro] = $headlines[$type] ?? $headlines['status_update'];

    // Build items rows
    $itemRows = '';
    foreach ($items as $item) {
        $itemName = htmlspecialchars($item['name'] ?? 'Product');
        $brand    = htmlspecialchars($item['brand'] ?? '');
        $qty      = (int)($item['quantity'] ?? 1);
        $price    = 'Rs. ' . number_format((float)($item['unit_price'] ?? 0), 2);
        $sub      = 'Rs. ' . number_format((float)($item['subtotal'] ?? 0), 2);
        $itemRows .= "
        <tr>
            <td style='padding:10px 12px;border-bottom:1px solid #f0f0f0;'>
                <strong style='color:#1a1a1a;'>{$itemName}</strong>
                " . ($brand ? "<br><small style='color:#888;'>{$brand}</small>" : '') . "
            </td>
            <td style='padding:10px 12px;border-bottom:1px solid #f0f0f0;text-align:center;color:#555;'>{$qty}</td>
            <td style='padding:10px 12px;border-bottom:1px solid #f0f0f0;text-align:right;color:#555;'>{$price}</td>
            <td style='padding:10px 12px;border-bottom:1px solid #f0f0f0;text-align:right;font-weight:bold;color:#1a1a1a;'>{$sub}</td>
        </tr>";
    }

    // Status badge colours
    $statusColours = [
        'placed'     => '#6b7280', 'processing' => '#3b82f6',
        'shipped'    => '#f59e0b', 'delivered'  => '#10b981',
        'cancelled'  => '#ef4444', 'pending'    => '#f59e0b',
        'paid'       => '#10b981', 'failed'     => '#ef4444',
        'refunded'   => '#8b5cf6',
    ];
    $oColor = $statusColours[strtolower($order['order_status']   ?? '')] ?? '#6b7280';
    $pColor = $statusColours[strtolower($order['payment_status'] ?? '')] ?? '#6b7280';

    return "<!DOCTYPE html>
<html lang='en'>
<head><meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1.0'></head>
<body style='margin:0;padding:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f4f4f4;padding:30px 0;'>
<tr><td align='center'>
<table width='620' cellpadding='0' cellspacing='0' style='background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);'>

<!-- Header -->
<tr><td style='background:{$brandColor};padding:28px 32px;'>
    <table width='100%'><tr>
        <td><span style='font-size:22px;font-weight:900;color:#fff;letter-spacing:1px;'>THIRASARA MAX</span><br>
            <span style='font-size:11px;color:rgba(255,255,255,.8);'>Mobile Accessories &amp; Repairs</span></td>
        <td align='right'><span style='font-size:11px;color:rgba(255,255,255,.8);'>Order No.<br></span>
            <strong style='font-size:15px;color:#fff;'>#{$orderNum}</strong></td>
    </tr></table>
</td></tr>

<!-- Headline -->
<tr><td style='padding:28px 32px 20px;border-bottom:1px solid #f0f0f0;'>
    <h1 style='margin:0 0 8px;font-size:22px;color:#1a1a1a;'>{$headline}</h1>
    <p style='margin:0;color:#555;font-size:14px;line-height:1.6;'>Hello <strong>{$name}</strong>, {$intro}</p>
</td></tr>

<!-- Status Badges -->
<tr><td style='padding:20px 32px;background:#fafafa;border-bottom:1px solid #f0f0f0;'>
    <table width='100%'><tr>
        <td width='50%'>
            <span style='font-size:10px;text-transform:uppercase;color:#999;letter-spacing:.5px;'>Order Status</span><br>
            <span style='display:inline-block;margin-top:4px;padding:4px 12px;background:{$oColor};color:#fff;border-radius:20px;font-size:12px;font-weight:bold;'>{$oStatus}</span>
        </td>
        <td width='50%'>
            <span style='font-size:10px;text-transform:uppercase;color:#999;letter-spacing:.5px;'>Payment Status</span><br>
            <span style='display:inline-block;margin-top:4px;padding:4px 12px;background:{$pColor};color:#fff;border-radius:20px;font-size:12px;font-weight:bold;'>{$pStatus}</span>
        </td>
    </tr></table>
</td></tr>

<!-- Order Details -->
<tr><td style='padding:20px 32px;'>
    <table width='100%' style='font-size:13px;'><tr>
        <td width='33%'><span style='color:#999;font-size:11px;'>Order Date</span><br><strong>{$orderDate}</strong></td>
        <td width='33%'><span style='color:#999;font-size:11px;'>Payment Method</span><br><strong>{$payMethod}</strong></td>
        <td width='33%'><span style='color:#999;font-size:11px;'>Deliver To</span><br><strong style='font-size:12px;'>{$phone}</strong></td>
    </tr></table>
    <p style='margin:12px 0 0;font-size:12px;color:#666;'><strong>Delivery Address:</strong> {$address}</p>
</td></tr>

<!-- Items Table -->
<tr><td style='padding:0 32px 20px;'>
    <table width='100%' cellpadding='0' cellspacing='0' style='font-size:13px;border:1px solid #e8e8e8;border-radius:8px;overflow:hidden;'>
    <thead>
        <tr style='background:#f8f8f8;'>
            <th style='padding:10px 12px;text-align:left;font-size:11px;color:#777;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #e8e8e8;'>Item</th>
            <th style='padding:10px 12px;text-align:center;font-size:11px;color:#777;text-transform:uppercase;border-bottom:1px solid #e8e8e8;'>Qty</th>
            <th style='padding:10px 12px;text-align:right;font-size:11px;color:#777;text-transform:uppercase;border-bottom:1px solid #e8e8e8;'>Unit Price</th>
            <th style='padding:10px 12px;text-align:right;font-size:11px;color:#777;text-transform:uppercase;border-bottom:1px solid #e8e8e8;'>Subtotal</th>
        </tr>
    </thead>
    <tbody>{$itemRows}</tbody>
    <tfoot>
        <tr style='background:#f8f8f8;'>
            <td colspan='3' style='padding:12px;text-align:right;font-weight:bold;color:#1a1a1a;font-size:14px;'>Grand Total:</td>
            <td style='padding:12px;text-align:right;font-weight:900;color:{$brandColor};font-size:16px;'>{$total}</td>
        </tr>
    </tfoot>
    </table>
</td></tr>

<!-- Invoice Note (only when PDF is actually attached) -->
" . (in_array($type, ['confirmed', 'paid', 'delivered'], true) ? "
<tr><td style='padding:0 32px 24px;'>
    <p style='margin:0;font-size:12px;color:#666;background:#fef9ec;border:1px solid #fde68a;border-radius:6px;padding:10px 14px;'>
        📎 A PDF invoice is attached to this email for your records.
    </p>
</td></tr>
" : '') . "
<!-- Footer -->
<tr><td style='background:#f8f8f8;padding:20px 32px;border-top:1px solid #eeeeee;text-align:center;'>
    <p style='margin:0 0 6px;font-size:13px;color:#333;font-weight:bold;'>Need help? We're here for you.</p>
    <p style='margin:0;font-size:12px;color:#888;'>📞 077 123 4567 &nbsp;|&nbsp; ✉️ support@thirasaramax.lk</p>
    <p style='margin:10px 0 0;font-size:11px;color:#aaa;'>© " . date('Y') . " Thirasara Max Mobile. All rights reserved.</p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>";
}


/**
 * Plain-text fallback for order emails.
 */
function buildOrderEmailPlain(array $order, string $type): string
{
    $num    = $order['order_number'] ?? '';
    $name   = $order['customer_name'] ?? 'Customer';
    $total  = 'Rs. ' . number_format((float)($order['total_amount'] ?? 0), 2);
    $oStat  = ucfirst($order['order_status']   ?? '');
    $pStat  = ucfirst($order['payment_status'] ?? '');

    $lines = [
        'confirmed'     => "Your order #{$num} has been placed successfully!",
        'paid'          => "Your payment for order #{$num} has been confirmed.",
        'shipped'       => "Your order #{$num} has been shipped.",
        'delivered'     => "Your order #{$num} has been delivered.",
        'cancelled'     => "Your order #{$num} has been cancelled.",
        'refunded'      => "A refund has been initiated for order #{$num}.",
        'status_update' => "Your order #{$num} status has been updated.",
    ];

    return "Hello {$name},\n\n" .
        ($lines[$type] ?? $lines['status_update']) . "\n\n" .
        "Order Number : #{$num}\n" .
        "Order Status : {$oStat}\n" .
        "Payment      : {$pStat}\n" .
        "Total Amount : {$total}\n\n" .
        (in_array($type, ['confirmed', 'paid', 'delivered'], true)
            ? "A PDF invoice is attached to this email.\n\n"
            : '') .
        "Questions? Contact us:\n" .
        "Phone: 077 123 4567\n" .
        "Email: support@thirasaramax.lk\n\n" .
        "Thank you for choosing Thirasara Max!\n" .
        "© " . date('Y') . " Thirasara Max Mobile.";
}