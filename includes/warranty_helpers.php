<?php

declare(strict_types=1);

/**
 * Thirasara Max Mobile - Warranty Management Helpers
 * Handles automatic warranty generation, synchronization, validation, and claims.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

/**
 * Generate a unique, recognizable warranty code.
 * Format: TM-WAR-YYYYMMDD-XXXX or TM-REP-YYYYMMDD-XXXX
 */
function generateWarrantyCode(string $prefix = 'TM-WAR'): string
{
    $datePart = date('Ymd');
    $randomPart = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    return sprintf('%s-%s-%s', $prefix, $datePart, $randomPart);
}

/**
 * Automatically update any expired warranties.
 * Changes status from 'active' to 'expired' where end_date < CURRENT_DATE().
 */
function syncWarrantyStatuses(PDO $pdo): void
{
    try {
        $stmt = $pdo->prepare("
            UPDATE warranties
            SET status = 'expired'
            WHERE status = 'active'
              AND end_date < CURRENT_DATE()
        ");
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('Warranty Sync Error: ' . $e->getMessage());
    }
}

/**
 * Ensure warranty records exist for an order.
 * Respects individual product warranty periods (product.warranty_period_months).
 * If a product has warranty_period_months = 0, no warranty is generated.
 *
 * @param PDO $pdo
 * @param int $orderId
 * @return array Generated or existing warranty records
 */
function ensureOrderWarranties(PDO $pdo, int $orderId): array
{
    syncWarrantyStatuses($pdo);

    // Check if order exists
    $orderStmt = $pdo->prepare("
        SELECT id, user_id, order_number, created_at, order_status, payment_status 
        FROM orders 
        WHERE id = ?
    ");
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        return [];
    }

    $userId = (int) $order['user_id'];
    $orderDate = !empty($order['created_at']) ? date('Y-m-d', strtotime($order['created_at'])) : date('Y-m-d');

    // Fetch existing warranties for this order
    $existStmt = $pdo->prepare("SELECT id, warranty_code, product_id FROM warranties WHERE order_id = ?");
    $existStmt->execute([$orderId]);
    $existing = $existStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($existing)) {
        return $existing;
    }

    // Fetch order items with each product's specific warranty_period_months
    $itemsStmt = $pdo->prepare("
        SELECT 
            oi.id AS order_item_id,
            oi.product_id,
            oi.quantity,
            p.name AS product_name,
            COALESCE(p.warranty_period_months, 6) AS warranty_period_months
        FROM order_items oi
        JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ?
    ");
    $itemsStmt->execute([$orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $created = [];
    $insertStmt = $pdo->prepare("
        INSERT INTO warranties (
            warranty_code, user_id, product_id, order_id, 
            warranty_type, start_date, end_date, status
        ) VALUES (?, ?, ?, ?, 'product', ?, ?, ?)
    ");

    foreach ($items as $item) {
        $warrantyMonths = (int) $item['warranty_period_months'];
        
        // If product has 0 months warranty, skip warranty generation
        if ($warrantyMonths <= 0) {
            continue;
        }

        $startDate = $orderDate;
        $endDate = date('Y-m-d', strtotime("+{$warrantyMonths} months", strtotime($startDate)));
        $initialStatus = ($endDate < date('Y-m-d')) ? 'expired' : 'active';
        $quantity = max(1, (int) $item['quantity']);

        // Generate a distinct warranty record for each purchased unit
        for ($i = 0; $i < $quantity; $i++) {
            $code = generateWarrantyCode('TM-WAR');

            // Ensure uniqueness
            $chk = $pdo->prepare("SELECT id FROM warranties WHERE warranty_code = ?");
            $chk->execute([$code]);
            if ($chk->fetch()) {
                $code = generateWarrantyCode('TM-WAR');
            }

            $insertStmt->execute([
                $code,
                $userId,
                $item['product_id'],
                $orderId,
                $startDate,
                $endDate,
                $initialStatus
            ]);

            $created[] = [
                'id' => (int) $pdo->lastInsertId(),
                'warranty_code' => $code,
                'product_id' => $item['product_id'],
                'product_name' => $item['product_name'],
                'warranty_period_months' => $warrantyMonths,
                'end_date' => $endDate
            ];
        }
    }

    return $created;
}

/**
 * Ensure warranty record exists for a completed repair.
 *
 * @param PDO $pdo
 * @param int $repairId
 * @param int $warrantyMonths Standard repair warranty months (default 3 months)
 * @return string|null Warranty code if created
 */
function ensureRepairWarranty(PDO $pdo, int $repairId, int $warrantyMonths = 3): ?string
{
    syncWarrantyStatuses($pdo);

    // Check repair record
    $repairStmt = $pdo->prepare("
        SELECT id, user_id, repair_code, device_model, completed_at, is_warranty_covered
        FROM repairs 
        WHERE id = ? AND repair_status = 'completed'
    ");
    $repairStmt->execute([$repairId]);
    $repair = $repairStmt->fetch(PDO::FETCH_ASSOC);

    if (!$repair) {
        return null;
    }

    // Check if already created
    $chkStmt = $pdo->prepare("SELECT warranty_code FROM warranties WHERE repair_id = ?");
    $chkStmt->execute([$repairId]);
    $existing = $chkStmt->fetchColumn();
    if ($existing) {
        return $existing;
    }

    $startDate = !empty($repair['completed_at']) ? date('Y-m-d', strtotime($repair['completed_at'])) : date('Y-m-d');
    $endDate = date('Y-m-d', strtotime("+{$warrantyMonths} months", strtotime($startDate)));
    $status = ($endDate < date('Y-m-d')) ? 'expired' : 'active';
    $code = generateWarrantyCode('TM-REP');

    $ins = $pdo->prepare("
        INSERT INTO warranties (
            warranty_code, user_id, repair_id, warranty_type, start_date, end_date, status
        ) VALUES (?, ?, ?, 'repair', ?, ?, ?)
    ");
    $ins->execute([
        $code,
        (int) $repair['user_id'],
        $repairId,
        $startDate,
        $endDate,
        $status
    ]);

    return $code;
}

/**
 * Get comprehensive warranty details with joined entities and calculated metrics.
 */
function getWarrantyDetails(PDO $pdo, string $codeOrId): ?array
{
    syncWarrantyStatuses($pdo);

    $isNumeric = ctype_digit(trim($codeOrId));
    $sql = "
        SELECT 
            w.*,
            u.name AS customer_name,
            u.email AS customer_email,
            u.phone AS customer_phone,
            p.name AS product_name,
            p.category AS product_category,
            p.brand AS product_brand,
            p.price AS product_price,
            p.image_url AS product_image,
            COALESCE(p.warranty_period_months, 6) AS product_warranty_months,
            COALESCE(p.is_repairable, 0) AS is_repairable,
            o.order_number,
            o.created_at AS order_created_at,
            r.repair_code,
            r.device_model,
            r.serial_or_imei
        FROM warranties w
        JOIN users u ON u.id = w.user_id
        LEFT JOIN products p ON p.id = w.product_id
        LEFT JOIN orders o ON o.id = w.order_id
        LEFT JOIN repairs r ON r.id = w.repair_id
        WHERE " . ($isNumeric ? "w.id = ?" : "w.warranty_code = ?") . "
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([trim($codeOrId)]);
    $w = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$w) {
        return null;
    }

    // Calculate time metrics
    $startTs = strtotime($w['start_date']);
    $endTs = strtotime($w['end_date']);
    $todayTs = strtotime(date('Y-m-d'));

    $totalDays = max(1, (int) round(($endTs - $startTs) / 86400));
    $daysRemaining = (int) round(($endTs - $todayTs) / 86400);
    $daysElapsed = max(0, (int) round(($todayTs - $startTs) / 86400));

    $percentElapsed = min(100, max(0, round(($daysElapsed / $totalDays) * 100)));

    $w['total_days'] = $totalDays;
    $w['days_remaining'] = $daysRemaining;
    $w['days_elapsed'] = $daysElapsed;
    $w['percent_elapsed'] = $percentElapsed;
    $w['is_currently_valid'] = ($w['status'] === 'active' && $daysRemaining >= 0);

    // Title & Coverage model representation
    $isRepairable = ((int) ($w['is_repairable'] ?? 0) === 1);
    if ($w['warranty_type'] === 'repair') {
        $w['display_title']    = $w['device_model'] ? ('Repair: ' . $w['device_model']) : 'Service Repair Warranty';
        $w['coverage_months']   = (int) round($totalDays / 30);
        $w['coverage_model']    = 'repair_service';
        $w['coverage_headline'] = 'Service & Repair Warranty';
        $w['coverage_terms']    = 'Covers hardware diagnostics, replacement parts, and technician labor.';
    } elseif ($isRepairable) {
        $w['display_title']    = $w['product_name'] ?? 'Repairable Device';
        $w['coverage_months']   = (int) $w['product_warranty_months'];
        $w['coverage_model']    = 'repair_service';
        $w['coverage_headline'] = 'Service & Repair Warranty';
        $w['coverage_terms']    = 'This device is officially repairable. Covered for certified service and hardware repairs under warranty.';
    } else {
        $w['display_title']    = $w['product_name'] ?? 'Product Accessory';
        $w['coverage_months']   = (int) $w['product_warranty_months'];
        $w['coverage_model']    = 'replacement_exchange';
        $w['coverage_headline'] = '1-to-1 Replacement Exchange Warranty';
        $w['coverage_terms']    = 'Non-repairable accessory. Covered for 1-to-1 replacement with a brand new working unit if defective within warranty.';
    }

    return $w;
}

/**
 * Submit a customer warranty claim.
 */
function submitWarrantyClaim(PDO $pdo, int $warrantyId, int $userId, string $claimDescription): array
{
    syncWarrantyStatuses($pdo);

    $stmt = $pdo->prepare("SELECT id, status, end_date FROM warranties WHERE id = ? AND user_id = ?");
    $stmt->execute([$warrantyId, $userId]);
    $w = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$w) {
        return ['success' => false, 'message' => 'Warranty record not found or access denied.'];
    }

    if ($w['status'] === 'void') {
        return ['success' => false, 'message' => 'This warranty has been marked as void. Claims cannot be filed.'];
    }

    if ($w['status'] === 'claimed') {
        return ['success' => false, 'message' => 'A claim has already been submitted for this warranty.'];
    }

    if ($w['status'] === 'expired' || strtotime($w['end_date']) < strtotime(date('Y-m-d'))) {
        return ['success' => false, 'message' => 'This warranty has expired. Claims must be submitted within the active coverage window.'];
    }

    $update = $pdo->prepare("
        UPDATE warranties 
        SET status = 'claimed',
            claim_description = ?,
            claim_date = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $update->execute([trim($claimDescription), $warrantyId]);

    return ['success' => true, 'message' => 'Warranty claim submitted successfully. Our team will review and contact you shortly.'];
}

/**
 * Lookup an existing customer by phone or email, or create a new customer record for walk-in in-store clients.
 */
function getOrCreateCustomer(
    PDO $pdo, 
    string $name, 
    string $phone, 
    ?string $email = null, 
    ?string $address = null
): int {
    $cleanPhone = preg_replace('/\D/', '', $phone);
    if (strlen($cleanPhone) > 10) {
        $cleanPhone = substr($cleanPhone, -10);
    }
    $cleanEmail = !empty($email) ? trim($email) : '';

    // Check if customer exists by phone
    if ($cleanPhone !== '') {
        $st = $pdo->prepare("SELECT id FROM users WHERE phone = ? LIMIT 1");
        $st->execute([$cleanPhone]);
        $existingId = $st->fetchColumn();
        if ($existingId) {
            return (int) $existingId;
        }
    }

    // Check if customer exists by email
    if ($cleanEmail !== '') {
        $st = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $st->execute([$cleanEmail]);
        $existingId = $st->fetchColumn();
        if ($existingId) {
            return (int) $existingId;
        }
    }

    // Generate valid email if customer did not provide one
    if ($cleanEmail === '') {
        $uniqueSuffix = $cleanPhone !== '' ? $cleanPhone : uniqid();
        $cleanEmail = 'walkin.' . $uniqueSuffix . '@thirasaramax.local';
        
        // Ensure email uniqueness
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $chk->execute([$cleanEmail]);
        if ($chk->fetchColumn()) {
            $cleanEmail = 'walkin.' . $uniqueSuffix . '.' . substr(bin2hex(random_bytes(2)), 0, 4) . '@thirasaramax.local';
        }
    }

    // Temporary secure password hash
    $tempPass = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);

    $ins = $pdo->prepare("
        INSERT INTO users (name, email, phone, address, password, role, status)
        VALUES (?, ?, ?, ?, ?, 'customer', 'active')
    ");
    $ins->execute([
        trim($name),
        $cleanEmail,
        $cleanPhone !== '' ? $cleanPhone : null,
        !empty($address) ? trim($address) : null,
        $tempPass
    ]);

    return (int) $pdo->lastInsertId();
}


/**
 * Generate an official PDF Warranty Certificate in landscape A4 format.
 *
 * @param array $w Complete warranty row with customer & item details
 * @return string|null Temporary file path or null on error
 */
function generateWarrantyCertificatePdf(array $w): ?string
{
    try {
        require_once __DIR__ . '/../vendor/autoload.php';

        $options = new \Dompdf\Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml(buildWarrantyCertificateHtml($w));
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $pdfContent = $dompdf->output();

        $dir = sys_get_temp_dir();
        $tmpFile = $dir . DIRECTORY_SEPARATOR . 'cert_' . ($w['id'] ?? uniqid()) . '_' . uniqid() . '.pdf';
        file_put_contents($tmpFile, $pdfContent);

        return $tmpFile;
    } catch (\Throwable $e) {
        error_log('Warranty Certificate PDF Error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Build HTML layout for the Official Warranty Certificate.
 */
function buildWarrantyCertificateHtml(array $w): string
{
    $code       = htmlspecialchars($w['warranty_code'] ?? 'TM-WAR-CERT');
    $custName   = htmlspecialchars($w['customer_name'] ?? 'Authorized Cardholder');
    $custPhone  = htmlspecialchars($w['customer_phone'] ?? 'N/A');
    $itemTitle  = htmlspecialchars($w['display_title'] ?? ($w['product_name'] ?? ($w['device_model'] ?? 'Certified Mobile Accessory')));
    $headline   = htmlspecialchars($w['coverage_headline'] ?? 'Standard Product Warranty');
    $terms      = htmlspecialchars($w['coverage_terms'] ?? 'Guarantees hardware component functionality under manufacturer service guidelines.');
    $startDate  = date('d F Y', strtotime($w['start_date'] ?? 'now'));
    $endDate    = date('d F Y', strtotime($w['end_date'] ?? '+6 months'));
    $status     = strtoupper($w['status'] ?? 'ACTIVE');
    $serial     = htmlspecialchars($w['serial_or_imei'] ?? 'N/A');
    $verifyUrl  = APP_URL . '/warranty-check.php?code=' . urlencode($w['warranty_code'] ?? '');
    

    return "<!DOCTYPE html>
<html>
<head>
<meta charset='UTF-8'>
<style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family: 'DejaVu Sans', Arial, sans-serif; background:#fff; color:#1f2937; padding:25px; }
    .cert-border-outer { border: 6px double #e52b34; padding: 18px; border-radius: 8px; height: 100%; position: relative; }
    .cert-border-inner { border: 1.5px solid #d1d5db; padding: 25px 35px; border-radius: 4px; height: 100%; background: #ffffff; }
    .header-table { width: 100%; border-bottom: 2px solid #e52b34; padding-bottom: 15px; margin-bottom: 20px; }
    .brand-title { font-size: 22px; font-weight: 900; color: #e52b34; text-transform: uppercase; letter-spacing: 2px; }
    .brand-sub { font-size: 9px; color: #6b7280; text-transform: uppercase; letter-spacing: 1px; margin-top: 2px; }
    .cert-badge { font-size: 16px; font-weight: 900; color: #111827; text-transform: uppercase; letter-spacing: 1.5px; text-align: right; }
    .cert-code { font-size: 12px; font-family: monospace; font-weight: bold; color: #e52b34; margin-top: 3px; }
    .cert-body { text-align: center; margin: 15px 0 20px; }
    .cert-presented { font-size: 11px; text-transform: uppercase; letter-spacing: 2px; color: #9ca3af; font-weight: bold; }
    .recipient-name { font-size: 24px; font-weight: 900; color: #111827; margin: 6px 0 12px; }
    .item-box { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 12px 20px; margin: 0 auto 15px; width: 90%; }
    .item-title { font-size: 15px; font-weight: 800; color: #e52b34; }
    .item-meta { font-size: 10px; color: #4b5563; margin-top: 4px; }
    .terms-box { font-size: 10px; color: #4b5563; line-height: 1.5; margin: 0 auto 20px; width: 85%; }
    .footer-table { width: 100%; margin-top: 15px; border-top: 1px solid #e5e7eb; padding-top: 15px; }
    .footer-col { vertical-align: bottom; }
    .seal-box { display: inline-block; border: 2px dashed #e52b34; color: #e52b34; padding: 8px 14px; border-radius: 50px; font-size: 9px; font-weight: 900; letter-spacing: 1px; text-transform: uppercase; }
    .sig-line { width: 180px; border-top: 1.5px solid #111827; margin: 30px auto 4px; font-size: 10px; font-weight: bold; text-align: center; color: #374151; }
</style>
</head>
<body>
<div class='cert-border-outer'>
    <div class='cert-border-inner'>
        <!-- Header -->
        <table class='header-table'>
            <tr>
                <td style='vertical-align: middle;'>
                    <div class='brand-title'>Thirasara Max Mobile</div>
                    <div class='brand-sub'>Official Service &amp; Retail Network &bull; Wadduwa, Sri Lanka</div>
                </td>
                <td style='vertical-align: middle; text-align: right;'>
                    <div class='cert-badge'>Digital Warranty Certificate</div>
                    <div class='cert-code'>{$code}</div>
                </td>
            </tr>
        </table>

        <!-- Body -->
        <div class='cert-body'>
            <div class='cert-presented'>This Document Officially Certifies Protection For</div>
            <div class='recipient-name'>{$custName}</div>

            <div class='item-box'>
                <div class='item-title'>{$itemTitle}</div>
                <div class='item-meta'>
                    <strong>Coverage Type:</strong> {$headline} &nbsp;&bull;&nbsp;
                    <strong>Serial / IMEI:</strong> <span style='font-family: monospace;'>{$serial}</span>
                </div>
            </div>

            <div class='terms-box'>
                <p><strong>Coverage Scope:</strong> {$terms}</p>
                <p style='margin-top: 4px;'>
                    Effective From: <strong>{$startDate}</strong> &nbsp;|&nbsp;
                    Valid Through: <strong>{$endDate}</strong> &nbsp;|&nbsp;
                    Certification Status: <strong style='color: #16a34a;'>{$status}</strong>
                </p>
            </div>
        </div>

        <!-- Footer / QR / Signatures -->
        <table class='footer-table'>
            <tr>
                <td class='footer-col' style='width: 210px; text-align: left;'>
                    <div style='border: 1.5px solid #111827; background: #f9fafb; border-radius: 6px; padding: 8px 12px; text-align: center;'>
                        <div style='font-size: 8px; text-transform: uppercase; font-weight: bold; color: #6b7280; letter-spacing: 0.5px;'>DIGITAL VERIFICATION</div>
                        <div style='font-size: 11px; font-family: monospace; font-weight: 900; color: #e52b34; margin: 3px 0;'>{$code}</div>
                        <div style='font-size: 7.5px; color: #4b5563; word-break: break-all; line-height: 1.2;'>{$verifyUrl}</div>
                    </div>
                    <div style='font-size: 7.5px; color: #6b7280; margin-top: 4px; text-align: center;'>Verify validity online anytime</div>
                </td>
                <td class='footer-col' style='text-align: center;'>
                    <div class='seal-box'>&bull; Verified Digital Certificate &bull;</div>
                    <div style='font-size: 8px; color: #9ca3af; margin-top: 6px;'>Thirasara Max Security Seal &bull; Wadduwa</div>
                </td>
                <td class='footer-col' style='width: 220px; text-align: right;'>
                    <div class='sig-line'>Authorized Signature &amp; Stamp</div>
                    <div style='font-size: 8px; color: #9ca3af; text-align: center;'>Technical &amp; Warranty Administration</div>
                </td>
            </tr>
        </table>
    </div>
</div>
</body>
</html>";
}