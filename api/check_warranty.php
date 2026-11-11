<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/warranty_helpers.php';

$code = trim($_GET['code'] ?? $_POST['code'] ?? '');

if ($code === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Please provide a valid warranty code.'
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $pdo = Database::getConnection();
    $warranty = getWarrantyDetails($pdo, $code);

    if (!$warranty) {
        // Fallback: check if the user entered an order number, repair code, or phone number
        $cleanPhone = preg_replace('/\D/', '', $code);
        $altStmt = $pdo->prepare("
            SELECT w.warranty_code 
            FROM warranties w
            LEFT JOIN orders o ON o.id = w.order_id
            LEFT JOIN repairs r ON r.id = w.repair_id
            LEFT JOIN users u ON u.id = w.user_id
            WHERE o.order_number = ? 
               OR r.repair_code = ? 
               OR (? != '' AND u.phone = ?)
            ORDER BY w.id DESC
            LIMIT 1
        ");
        $altStmt->execute([$code, $code, $cleanPhone, $cleanPhone]);
        $altCode = $altStmt->fetchColumn();

        if ($altCode) {
            $warranty = getWarrantyDetails($pdo, $altCode);
        }
    }

    if (!$warranty) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'found'   => false,
            'message' => 'No warranty record found matching code: ' . htmlspecialchars($code)
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Mask customer name for public verification privacy (e.g., "John D***")
    $nameParts = explode(' ', trim($warranty['customer_name'] ?? 'Customer'));
    $maskedName = $nameParts[0];
    if (isset($nameParts[1])) {
        $maskedName .= ' ' . substr($nameParts[1], 0, 1) . '***';
    }

    $response = [
        'success' => true,
        'found'   => true,
        'warranty' => [
            'id'                     => (int) $warranty['id'],
            'warranty_code'          => $warranty['warranty_code'],
            'type'                   => $warranty['warranty_type'],
            'title'                  => $warranty['display_title'],
            'customer_name'          => $maskedName,
            'category'               => $warranty['product_category'] ?? ($warranty['warranty_type'] === 'repair' ? 'Repair Service' : 'Accessory'),
            'brand'                  => $warranty['product_brand'] ?? 'Thirasara Max',
            'product_image'          => $warranty['product_image'] ? (APP_URL . '/' . ltrim($warranty['product_image'], '/')) : null,
            'start_date'             => $warranty['start_date'],
            'end_date'               => $warranty['end_date'],
            'coverage_months'        => (int) $warranty['coverage_months'],
            'status'                 => $warranty['status'],
            'is_valid'               => $warranty['is_currently_valid'],
            'is_repairable'          => ((int) ($warranty['is_repairable'] ?? 0) === 1),
            'coverage_model'         => $warranty['coverage_model'] ?? 'replacement_exchange',
            'coverage_headline'      => $warranty['coverage_headline'] ?? 'Warranty Coverage',
            'coverage_terms'         => $warranty['coverage_terms'] ?? '',
            'days_remaining'         => (int) $warranty['days_remaining'],
            'days_elapsed'           => (int) $warranty['days_elapsed'],
            'percent_elapsed'        => (int) $warranty['percent_elapsed'],
            'order_number'           => $warranty['order_number'] ?? null,
            'repair_code'            => $warranty['repair_code'] ?? null,
            'claim_date'             => $warranty['claim_date'] ?? null
        ]
    ];

    echo json_encode($response, JSON_UNESCAPED_SLASHES);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while validating the warranty: ' . $e->getMessage()
    ], JSON_UNESCAPED_SLASHES);
    exit;
}
