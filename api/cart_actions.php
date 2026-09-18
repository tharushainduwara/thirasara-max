<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

/* Always respond with JSON.*/

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* Only POST is accepted */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

/* Start session if not already active */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* Initialise the cart if it doesn't exist */
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

/* Input — accept JSON body OR classic form-encoded POST  */
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (str_contains($contentType, 'application/json')) {
    $raw    = file_get_contents('php://input');
    $body   = json_decode($raw, true) ?? [];
} else {
    $body   = $_POST;
}

$action    = trim($body['action']    ?? '');
$productId = (int) ($body['product_id'] ?? 0);
$quantity  = (int) ($body['quantity']  ?? 1);

/* Helper functions */

/* Return the cart-array key for a product, or null if not present. */
function findCartKey(int $productId): int|string|null
{
    foreach ($_SESSION['cart'] as $key => $item) {
        if (is_array($item) && (int) ($item['product_id'] ?? 0) === $productId) {
            return $key;
        }
    }
    return null;
}

/* Count total items (sum of quantities) in the cart. */
function cartTotalCount(): int
{
    $total = 0;
    foreach ($_SESSION['cart'] as $item) {
        if (is_array($item)) {
            $total += max(0, (int) ($item['quantity'] ?? 0));
        }
    }
    return $total;
}

/* Re-index the session cart and return the new count. */
function reindexCart(): int
{
    $_SESSION['cart'] = array_values($_SESSION['cart']);
    return cartTotalCount();
}

/* Fetch live product + stock info from the DB. */
function fetchProduct(PDO $pdo, int $productId): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.name,
            p.category,
            p.brand,
            p.price,
            p.status,
            COALESCE(i.stock_quantity,    0) AS stock_quantity,
            COALESCE(i.low_stock_threshold, 5) AS low_stock_threshold
        FROM products p
        LEFT JOIN inventory i ON i.product_id = p.id
        WHERE p.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $productId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* Send a JSON success response and exit. */
function ok(array $payload = []): never
{
    echo json_encode(array_merge(['success' => true], $payload), JSON_UNESCAPED_UNICODE);
    exit;
}

/* Send a JSON error response and exit. */
function fail(string $message, int $httpCode = 422): never
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/* Route the action*/
try {
    $pdo = Database::getConnection();

    /* ACTION: add */
    if ($action === 'add') {

        if ($productId <= 0) {
            fail('Invalid product ID.');
        }

        $quantity = max(1, $quantity);

        $product = fetchProduct($pdo, $productId);

        if (!$product) {
            fail('Product not found.', 404);
        }

        if ($product['status'] === 'discontinued') {
            fail('This product is no longer available.');
        }

        $stock = (int) $product['stock_quantity'];

        if ($stock <= 0) {
            fail('This product is currently out of stock.');
        }

        /* How many are already in the cart? */
        $existingKey = findCartKey($productId);
        $existingQty = 0;

        if ($existingKey !== null) {
            $existingQty = (int) ($_SESSION['cart'][$existingKey]['quantity'] ?? 0);
        }

        $newQty = $existingQty + $quantity;

        if ($newQty > $stock) {
            fail('Only ' . $stock . ' unit(s) available. You already have ' . $existingQty . ' in your cart.');
        }

        /* Save */
        if ($existingKey !== null) {
            $_SESSION['cart'][$existingKey]['quantity'] = $newQty;
        } else {
            $_SESSION['cart'][] = [
                'product_id' => $productId,
                'quantity'   => $quantity,
            ];
        }

        ok([
            'message'    => htmlspecialchars($product['name']) . ' added to your cart.',
            'cart_count' => reindexCart(),
            'product'    => [
                'id'    => (int) $product['id'],
                'name'  => $product['name'],
                'price' => (float) $product['price'],
            ],
            'quantity_in_cart' => $newQty,
        ]);
    }

    /* ACTION: update */
    if ($action === 'update') {

        if ($productId <= 0) {
            fail('Invalid product ID.');
        }

        $cartKey = findCartKey($productId);

        if ($cartKey === null) {
            fail('Product is not in your cart.', 404);
        }

        /* quantity = 0 → treat as remove */
        if ($quantity <= 0) {
            unset($_SESSION['cart'][$cartKey]);
            ok([
                'message'    => 'Item removed from your cart.',
                'cart_count' => reindexCart(),
                'removed'    => true,
            ]);
        }

        $product = fetchProduct($pdo, $productId);

        if (!$product || $product['status'] === 'discontinued') {
            unset($_SESSION['cart'][$cartKey]);
            ok([
                'message'    => 'Item removed — product is no longer available.',
                'cart_count' => reindexCart(),
                'removed'    => true,
            ]);
        }

        $stock = (int) $product['stock_quantity'];

        if ($stock <= 0) {
            unset($_SESSION['cart'][$cartKey]);
            ok([
                'message'    => htmlspecialchars($product['name']) . ' is now out of stock and was removed.',
                'cart_count' => reindexCart(),
                'removed'    => true,
            ]);
        }

        /* Cap at available stock */
        $adjusted = false;
        if ($quantity > $stock) {
            $quantity = $stock;
            $adjusted = true;
        }

        $_SESSION['cart'][$cartKey]['quantity'] = $quantity;

        ok([
            'message'      => $adjusted
                ? 'Quantity adjusted to ' . $quantity . ' (max available).'
                : 'Cart updated.',
            'cart_count'   => reindexCart(),
            'quantity'     => $quantity,
            'adjusted'     => $adjusted,
            'line_total'   => round((float) $product['price'] * $quantity, 2),
        ]);
    }

    /* ACTION: remove */
    if ($action === 'remove') {

        if ($productId <= 0) {
            fail('Invalid product ID.');
        }

        $cartKey = findCartKey($productId);

        if ($cartKey === null) {
            fail('Product is not in your cart.', 404);
        }

        unset($_SESSION['cart'][$cartKey]);

        ok([
            'message'    => 'Item removed from your cart.',
            'cart_count' => reindexCart(),
        ]);
    }

    /* ACTION: clear */
    if ($action === 'clear') {

        $_SESSION['cart'] = [];

        ok([
            'message'    => 'Your cart has been cleared.',
            'cart_count' => 0,
        ]);
    }

    /*  ACTION: get */
    if ($action === 'get') {

        if (empty($_SESSION['cart'])) {
            ok(['items' => [], 'cart_count' => 0, 'subtotal' => 0]);
        }

        $ids = array_values(array_unique(array_filter(
            array_map(
                fn($item) => is_array($item) ? (int) ($item['product_id'] ?? 0) : 0,
                $_SESSION['cart']
            ),
            fn($id) => $id > 0
        )));

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $pdo->prepare("
            SELECT
                p.id,
                p.name,
                p.category,
                p.brand,
                p.price,
                p.image_url,
                p.status,
                COALESCE(i.stock_quantity, 0)       AS stock_quantity,
                COALESCE(i.low_stock_threshold, 5)  AS low_stock_threshold
            FROM products p
            LEFT JOIN inventory i ON i.product_id = p.id
            WHERE p.id IN ($placeholders)
        ");
        $stmt->execute($ids);

        $dbProducts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dbProducts[(int) $row['id']] = $row;
        }

        $items    = [];
        $subtotal = 0.0;

        foreach ($_SESSION['cart'] as $key => $cartItem) {
            if (!is_array($cartItem)) {
                continue;
            }

            $pid = (int) ($cartItem['product_id'] ?? 0);
            $qty = max(1, (int) ($cartItem['quantity'] ?? 1));

            if (!isset($dbProducts[$pid])) {
                unset($_SESSION['cart'][$key]);
                continue;
            }

            $p     = $dbProducts[$pid];
            $stock = (int) $p['stock_quantity'];

            if ($p['status'] === 'discontinued') {
                unset($_SESSION['cart'][$key]);
                continue;
            }

            /* Cap qty silently */
            if ($stock > 0 && $qty > $stock) {
                $qty = $stock;
                $_SESSION['cart'][$key]['quantity'] = $qty;
            }

            $lineTotal  = round((float) $p['price'] * $qty, 2);
            $subtotal  += $lineTotal;

            $items[] = [
                'product_id'  => $pid,
                'name'        => $p['name'],
                'category'    => $p['category'],
                'brand'       => $p['brand'],
                'price'       => (float) $p['price'],
                'image_url'   => $p['image_url'],
                'quantity'    => $qty,
                'stock'       => $stock,
                'available'   => $stock > 0,
                'line_total'  => $lineTotal,
            ];
        }

        reindexCart();

        ok([
            'items'      => $items,
            'cart_count' => cartTotalCount(),
            'subtotal'   => round($subtotal, 2),
        ]);
    }

    /* Unknown action */
    fail('Unknown action. Supported: add | update | remove | clear | get');

} catch (PDOException $e) {
    error_log('cart_actions API error: ' . $e->getMessage());
    fail('A database error occurred. Please try again.', 500);
}
