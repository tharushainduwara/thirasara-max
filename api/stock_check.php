<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

/*
 * Always respond with JSON.
 * Allow cross-origin from the same app origin only.
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* Only GET is accepted */
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

/* 
 * Input parsing
 *
 * Supports two modes:
 *   1. Single product  → ?product_id=5
 *   2. Multiple products → ?ids=5,12,7   (comma-separated)
 */
$singleId  = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
$multiIds  = [];

if (isset($_GET['ids']) && $_GET['ids'] !== '') {
    $rawIds   = explode(',', $_GET['ids']);
    $multiIds = array_values(
        array_unique(
            array_filter(
                array_map('intval', $rawIds),
                fn($id) => $id > 0
            )
        )
    );
}

/* Collect all requested IDs (single + multi, de-duped) */
$requestedIds = array_values(
    array_unique(
        array_filter(
            array_merge(
                $singleId > 0 ? [$singleId] : [],
                $multiIds
            )
        )
    )
);

if (empty($requestedIds)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'No product ID supplied. Use ?product_id=X or ?ids=X,Y,Z'
    ]);
    exit;
}

/* Safety cap — never query more than 100 products at once */
if (count($requestedIds) > 100) {
    http_response_code(400);
    echo json_encode(['error' => 'Too many IDs requested. Max 100 per call.']);
    exit;
}

/*  Database query */
try {
    $pdo = Database::getConnection();

    /* Build a safe IN (?, ?, …) placeholder list */
    $placeholders = implode(',', array_fill(0, count($requestedIds), '?'));

    $sql = "
        SELECT
            p.id,
            p.name,
            p.category,
            p.brand,
            p.price,
            p.status                                    AS product_status,
            COALESCE(i.stock_quantity,    0)            AS stock_quantity,
            COALESCE(i.low_stock_threshold, 5)          AS low_stock_threshold,
            i.last_restocked_at
        FROM products p
        LEFT JOIN inventory i ON i.product_id = p.id
        WHERE p.id IN ($placeholders)
          AND p.status != 'discontinued'
        ORDER BY p.id ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($requestedIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('stock_check API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error. Please try again later.']);
    exit;
}

/*  Build response */
function resolveStockStatus(int $qty, int $threshold): array
{
    if ($qty <= 0) {
        return [
            'label'     => 'Out of Stock',
            'status'    => 'out_of_stock',
            'available' => false,
        ];
    }

    if ($qty <= $threshold) {
        return [
            'label'     => 'Only ' . $qty . ' left',
            'status'    => 'low_stock',
            'available' => true,
        ];
    }

    return [
        'label'     => 'In Stock',
        'status'    => 'in_stock',
        'available' => true,
    ];
}

$products = [];

foreach ($rows as $row) {
    $qty       = (int) $row['stock_quantity'];
    $threshold = (int) $row['low_stock_threshold'];
    $stockInfo = resolveStockStatus($qty, $threshold);

    $products[(int) $row['id']] = [
        'id'                  => (int) $row['id'],
        'name'                => $row['name'],
        'category'            => $row['category'],
        'brand'               => $row['brand'],
        'price'               => (float) $row['price'],
        'product_status'      => $row['product_status'],
        'stock_quantity'      => $qty,
        'low_stock_threshold' => $threshold,
        'last_restocked_at'   => $row['last_restocked_at'],
        'stock_label'         => $stockInfo['label'],
        'stock_status'        => $stockInfo['status'],   // in_stock | low_stock | out_of_stock
        'available'           => $stockInfo['available'],
    ];
}

/* Flag any requested IDs that were not found in the DB */
$notFound = [];
foreach ($requestedIds as $rid) {
    if (!isset($products[$rid])) {
        $notFound[] = $rid;
    }
}

/* Single-product shorthand: unwrap the array for convenience */
if ($singleId > 0 && empty($multiIds)) {
    if (isset($products[$singleId])) {
        echo json_encode([
            'success' => true,
            'product' => $products[$singleId],
        ], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error'   => 'Product not found or discontinued.',
            'id'      => $singleId,
        ]);
    }
    exit;
}

/* Multi-product response */
echo json_encode([
    'success'   => true,
    'products'  => array_values($products),
    'not_found' => $notFound,
    'count'     => count($products),
], JSON_UNESCAPED_UNICODE);
