<?php

declare(strict_types=1);

/**
 * Thirasara Max Mobile - Inventory & Stock Helpers
 * Module 2.0: Real-time stock control, restocking, depletion alerts, and audit logging.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/mailer.php';

/**
 * Adjust stock quantity for a product with audit logging and automated status synchronization.
 *
 * @param PDO         $pdo
 * @param int         $productId
 * @param int         $qtyChange    Positive for restock/returns, negative for sales/write-offs
 * @param string      $changeType   'restock', 'adjustment', 'order_sale', 'repair_part', 'return'
 * @param string|null $notes
 * @param int|null    $userId
 * @return array ['success' => bool, 'old_stock' => int, 'new_stock' => int, 'status' => string]
 */
function adjustStock(
    PDO $pdo,
    int $productId,
    int $qtyChange,
    string $changeType = 'restock',
    ?string $notes = null,
    ?int $userId = null
): array {
    // Check if inventory row exists, create if missing
    $stmt = $pdo->prepare("SELECT id, stock_quantity, low_stock_threshold FROM inventory WHERE product_id = ?");
    $stmt->execute([$productId]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$inv) {
        $defaultThreshold = (int) (defined('LOW_STOCK_THRESHOLD') ? LOW_STOCK_THRESHOLD : 5);
        $ins = $pdo->prepare("
            INSERT INTO inventory (product_id, stock_quantity, low_stock_threshold) 
            VALUES (?, 0, ?)
        ");
        $ins->execute([$productId, $defaultThreshold]);
        $inv = ['stock_quantity' => 0, 'low_stock_threshold' => $defaultThreshold];
    }

    $oldStock = (int) $inv['stock_quantity'];
    $threshold = (int) $inv['low_stock_threshold'];
    $newStock = max(0, $oldStock + $qtyChange);

    // Update inventory table
    if ($changeType === 'restock' || $qtyChange > 0) {
        $upd = $pdo->prepare("
            UPDATE inventory 
            SET stock_quantity = ?, 
                last_restocked_at = CURRENT_TIMESTAMP, 
                updated_at = CURRENT_TIMESTAMP 
            WHERE product_id = ?
        ");
    } else {
        $upd = $pdo->prepare("
            UPDATE inventory 
            SET stock_quantity = ?, 
                updated_at = CURRENT_TIMESTAMP 
            WHERE product_id = ?
        ");
    }
    $upd->execute([$newStock, $productId]);

    // Synchronize product catalog status
    $prodStmt = $pdo->prepare("SELECT name, status FROM products WHERE id = ?");
    $prodStmt->execute([$productId]);
    $prod = $prodStmt->fetch(PDO::FETCH_ASSOC);
    $prodName = $prod['name'] ?? 'Product';
    $currentStatus = $prod['status'] ?? 'available';

    $newProdStatus = $currentStatus;
    if ($currentStatus !== 'discontinued') {
        if ($newStock <= 0) {
            $newProdStatus = 'out_of_stock';
        } else {
            $newProdStatus = 'available';
        }

        if ($newProdStatus !== $currentStatus) {
            $updProd = $pdo->prepare("UPDATE products SET status = ? WHERE id = ?");
            $updProd->execute([$newProdStatus, $productId]);
        }
    }

    // Log movement in inventory_logs
    try {
        $logStmt = $pdo->prepare("
            INSERT INTO inventory_logs (
                product_id, change_type, quantity_change, resulting_stock, notes, user_id, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        $logStmt->execute([
            $productId,
            $changeType,
            $qtyChange,
            $newStock,
            $notes,
            $userId
        ]);
    } catch (Throwable $e) {
        error_log('Inventory Log Error: ' . $e->getMessage());
    }

    // Trigger in-app notification if stock drops to low or out of stock
    if ($qtyChange < 0 || $newStock <= $threshold) {
        try {
            $catName = $prod['category'] ?? '';
            if ($newStock === 0) {
                $notifStmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, title, message, type, created_at)
                    VALUES (NULL, 'Out of Stock Alert', ?, 'low_stock', CURRENT_TIMESTAMP)
                ");
                $notifStmt->execute(["'{$prodName}' is completely out of stock! Restock immediately to avoid lost sales."]);
                if (function_exists('sendLowStockAlertEmail')) {
                    sendLowStockAlertEmail($prodName, 0, $threshold, $catName);
                }
            } elseif ($newStock <= $threshold) {
                $notifStmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, title, message, type, created_at)
                    VALUES (NULL, 'Low Stock Alert', ?, 'low_stock', CURRENT_TIMESTAMP)
                ");
                $notifStmt->execute(["'{$prodName}' has reached low stock level ({$newStock} units remaining, threshold: {$threshold})."]);
                if (function_exists('sendLowStockAlertEmail')) {
                    sendLowStockAlertEmail($prodName, $newStock, $threshold, $catName);
                }
            }
        } catch (Throwable $e) {
            error_log('Stock Notification Error: ' . $e->getMessage());
        }
    }

    return [
        'success'   => true,
        'old_stock' => $oldStock,
        'new_stock' => $newStock,
        'status'    => $newProdStatus
    ];
}

/**
 * Set exact stock count for a product.
 */
function setExactStock(PDO $pdo, int $productId, int $exactQty, ?string $notes = null, ?int $userId = null): array
{
    $stmt = $pdo->prepare("SELECT stock_quantity FROM inventory WHERE product_id = ?");
    $stmt->execute([$productId]);
    $current = (int) ($stmt->fetchColumn() ?: 0);
    $diff = $exactQty - $current;

    return adjustStock($pdo, $productId, $diff, 'adjustment', $notes ?: "Manual stock count adjustment to {$exactQty}", $userId);
}

/**
 * Update the custom low-stock threshold for a product.
 */
function updateStockThreshold(PDO $pdo, int $productId, int $newThreshold): bool
{
    $stmt = $pdo->prepare("
        INSERT INTO inventory (product_id, stock_quantity, low_stock_threshold)
        VALUES (?, 0, ?)
        ON DUPLICATE KEY UPDATE low_stock_threshold = VALUES(low_stock_threshold)
    ");
    return $stmt->execute([$productId, max(1, $newThreshold)]);
}

/**
 * Fetch comprehensive inventory KPI metrics.
 */
function getInventoryStats(PDO $pdo): array
{
    $sql = "
        SELECT 
            COUNT(p.id) AS total_products,
            COALESCE(SUM(COALESCE(i.stock_quantity, 0)), 0) AS total_units,
            COALESCE(SUM(COALESCE(i.stock_quantity, 0) * p.price), 0.00) AS total_valuation,
            COALESCE(SUM(CASE WHEN COALESCE(i.stock_quantity, 0) <= COALESCE(i.low_stock_threshold, 5) AND COALESCE(i.stock_quantity, 0) > 0 THEN 1 ELSE 0 END), 0) AS low_stock_count,
            COALESCE(SUM(CASE WHEN COALESCE(i.stock_quantity, 0) = 0 THEN 1 ELSE 0 END), 0) AS out_of_stock_count,
            COALESCE(SUM(CASE WHEN COALESCE(i.stock_quantity, 0) > COALESCE(i.low_stock_threshold, 5) THEN 1 ELSE 0 END), 0) AS healthy_count
        FROM products p
        LEFT JOIN inventory i ON i.product_id = p.id
        WHERE p.status != 'discontinued'
    ";
    $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);

    return [
        'total_products'      => (int) ($row['total_products'] ?? 0),
        'total_units'         => (int) ($row['total_units'] ?? 0),
        'total_valuation'     => (float) ($row['total_valuation'] ?? 0),
        'low_stock_count'     => (int) ($row['low_stock_count'] ?? 0),
        'out_of_stock_count'  => (int) ($row['out_of_stock_count'] ?? 0),
        'healthy_count'       => (int) ($row['healthy_count'] ?? 0),
        'alerts_total'        => (int) ($row['low_stock_count'] ?? 0) + (int) ($row['out_of_stock_count'] ?? 0)
    ];
}
