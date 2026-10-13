<?php

use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../vendor/autoload.php';


/**
 * Generate a PDF invoice for an order and save to a temp file.
 *
 * Returns the absolute path of the generated temp PDF, or null on failure.
 *
 * @param int   $orderId  DB primary key of the order
 * @param array $order    Full order row (with customer_name, customer_email, etc.)
 * @param array $items    Array of order-item rows
 * @param array $payment  Payment row
 * @return string|null
 */
function generateInvoicePdf(int $orderId, array $order, array $items, array $payment): ?string
{
    try {
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(buildInvoiceHtml($orderId, $order, $items, $payment));
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $pdfContent = $dompdf->output();

        $dir     = sys_get_temp_dir();
        $tmpFile = $dir . DIRECTORY_SEPARATOR . 'inv_' . $orderId . '_' . uniqid() . '.pdf';
        file_put_contents($tmpFile, $pdfContent);

        return $tmpFile;

    } catch (\Throwable $e) {
        error_log('Invoice PDF Generation Error (order #' . $orderId . '): ' . $e->getMessage());
        return null;
    }
}


/**
 * Build the HTML content for the PDF invoice.
 */
function buildInvoiceHtml(int $orderId, array $order, array $items, array $payment): string
{
    $orderNum   = htmlspecialchars($order['order_number']    ?? '');
    $custName   = htmlspecialchars($order['customer_name']   ?? 'Customer');
    $custEmail  = htmlspecialchars($order['customer_email']  ?? '');
    $custPhone  = htmlspecialchars($order['contact_phone']   ?? ($order['customer_phone_registered'] ?? ''));
    $address    = nl2br(htmlspecialchars($order['shipping_address'] ?? ''));
    $orderDate  = date('d M Y, h:i A', strtotime($order['created_at'] ?? 'now'));
    $oStatus    = ucfirst($order['order_status']   ?? '');
    $pStatus    = ucfirst($order['payment_status'] ?? '');
    $payMethod  = strtoupper(str_replace('_', ' ', $payment['payment_method'] ?? 'N/A'));
    $grandTotal = number_format((float)($order['total_amount'] ?? 0), 2);

    $invNumber = $order['invoice_number'] ?? ('INV-' . date('Ymd', strtotime($order['created_at'] ?? 'now')) . '-' . sprintf('%04d', $orderId));
    $invNumber = htmlspecialchars($invNumber);

    // Build items rows
    $itemRows = '';
    $rowNum   = 0;
    foreach ($items as $item) {
        $rowNum++;
        $bg      = $rowNum % 2 === 0 ? '#f9f9f9' : '#ffffff';
        $name    = htmlspecialchars($item['name']  ?? 'Product');
        $brand   = htmlspecialchars($item['brand'] ?? '');
        $qty     = (int)($item['quantity']   ?? 1);
        $price   = number_format((float)($item['unit_price'] ?? 0), 2);
        $sub     = number_format((float)($item['subtotal']   ?? 0), 2);
        $itemRows .= "
        <tr style='background:{$bg};'>
            <td style='padding:8px 10px;border:1px solid #e5e5e5;'>
                <strong>{$name}</strong>" . ($brand ? "<br><small style='color:#888;font-size:10px;'>{$brand}</small>" : '') . "
            </td>
            <td style='padding:8px 10px;text-align:center;border:1px solid #e5e5e5;'>{$qty}</td>
            <td style='padding:8px 10px;text-align:right;border:1px solid #e5e5e5;'>Rs. {$price}</td>
            <td style='padding:8px 10px;text-align:right;font-weight:bold;border:1px solid #e5e5e5;'>Rs. {$sub}</td>
        </tr>";
    }

    return "<!DOCTYPE html>
<html>
<head>
<meta charset='UTF-8'>
<style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size:12px; color:#1a1a1a; background:#fff; }
    .page { padding:36px; }
    .header { display:table; width:100%; border-bottom:3px solid #e52b34; padding-bottom:18px; margin-bottom:20px; }
    .header-left { display:table-cell; vertical-align:middle; }
    .header-right { display:table-cell; text-align:right; vertical-align:middle; }
    .company-name { font-size:20px; font-weight:900; color:#e52b34; letter-spacing:1px; text-transform:uppercase; }
    .company-sub { font-size:10px; color:#888; margin-top:2px; }
    .invoice-label { font-size:26px; font-weight:900; color:#1a1a1a; letter-spacing:2px; text-transform:uppercase; }
    .meta-table { width:100%; margin-bottom:18px; }
    .meta-table td { padding:3px 0; font-size:11px; }
    .meta-label { color:#888; width:120px; }
    .section-title { font-size:10px; text-transform:uppercase; letter-spacing:.8px; color:#888; font-weight:bold; margin-bottom:6px; }
    .info-box { display:table; width:100%; margin-bottom:18px; }
    .info-col { display:table-cell; width:50%; vertical-align:top; padding-right:10px; }
    .info-col:last-child { padding-right:0; padding-left:10px; }
    .info-val { font-size:12px; color:#333; margin-top:2px; }
    .items-table { width:100%; border-collapse:collapse; margin-bottom:18px; font-size:11px; }
    .items-table th { background:#e52b34; color:#fff; padding:8px 10px; text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.5px; border:1px solid #e52b34; }
    .items-table th.right { text-align:right; }
    .items-table th.center { text-align:center; }
    .totals-table { width:240px; margin-left:auto; border-collapse:collapse; }
    .totals-table td { padding:5px 8px; font-size:12px; border:1px solid #e5e5e5; }
    .totals-table .grand { font-weight:900; font-size:14px; background:#e52b34; color:#fff; }
    .status-badge { display:inline-block; padding:2px 10px; border-radius:20px; font-size:10px; font-weight:bold; color:#fff; }
    .footer { border-top:1px solid #eee; margin-top:24px; padding-top:14px; text-align:center; font-size:10px; color:#888; }
    .footer strong { color:#1a1a1a; }
</style>
</head>
<body>
<div class='page'>

    <!-- Header -->
    <div class='header'>
        <div class='header-left'>
            <div class='company-name'>Thirasara Max</div>
            <div class='company-sub'>Mobile Accessories &amp; Repairs &nbsp;|&nbsp; Galle - Colombo Rd, Wadduwa</div>
            <div class='company-sub'>Tel: 077 123 4567 &nbsp;|&nbsp; Email: info@thirasaramax.lk</div>
        </div>
        <div class='header-right'>
            <div class='invoice-label'>Invoice</div>
            <div style='margin-top:6px;font-size:11px;color:#555;'>
                <strong>{$invNumber}</strong><br>
                Order Ref: #{$orderNum}<br>
                Date: {$orderDate}
            </div>
        </div>
    </div>

    <!-- Bill To / Status -->
    <div class='info-box'>
        <div class='info-col'>
            <div class='section-title'>Bill To</div>
            <div class='info-val'><strong>{$custName}</strong></div>
            " . ($custEmail ? "<div class='info-val'>{$custEmail}</div>" : '') . "
            " . ($custPhone ? "<div class='info-val'>Tel: {$custPhone}</div>" : '') . "
            <div class='info-val' style='margin-top:4px;'>{$address}</div>
        </div>
        <div class='info-col'>
            <div class='section-title'>Order Info</div>
            <table class='meta-table'>
                <tr><td class='meta-label'>Order Status:</td>
                    <td><strong>{$oStatus}</strong></td></tr>
                <tr><td class='meta-label'>Payment Status:</td>
                    <td><strong>{$pStatus}</strong></td></tr>
                <tr><td class='meta-label'>Payment Method:</td>
                    <td>{$payMethod}</td></tr>
            </table>
        </div>
    </div>

    <!-- Items Table -->
    <table class='items-table'>
        <thead>
            <tr>
                <th>Item Description</th>
                <th class='center' style='width:50px;'>Qty</th>
                <th class='right' style='width:100px;'>Unit Price</th>
                <th class='right' style='width:100px;'>Subtotal</th>
            </tr>
        </thead>
        <tbody>
            {$itemRows}
        </tbody>
    </table>

    <!-- Totals -->
    <table class='totals-table'>
        <tr><td>Subtotal</td><td style='text-align:right;'>Rs. {$grandTotal}</td></tr>
        <tr><td>Shipping</td><td style='text-align:right;'>Included</td></tr>
        <tr class='grand'><td>Total Amount</td><td style='text-align:right;'>Rs. {$grandTotal}</td></tr>
    </table>

    <!-- Footer -->
    <div class='footer'>
        <strong>Thank you for choosing Thirasara Max!</strong><br>
        Goods sold are covered under respective manufacturer warranty terms. Retain this invoice for warranty claims.<br>
        Support: 077 123 4567 &nbsp;|&nbsp; support@thirasaramax.lk &nbsp;|&nbsp; Galle - Colombo Rd, Wadduwa<br>
        &copy; " . date('Y') . " Thirasara Max Mobile. All rights reserved.
    </div>

</div>
</body>
</html>";
}
