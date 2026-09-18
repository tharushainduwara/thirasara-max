<?php

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = 'Shopping Cart';
$pdo = Database::getConnection();

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

/*Helper Functions*/

function redirectToCart(): void
{
    header('Location: ' . APP_URL . '/cart.php');
    exit;
}

function getCartCount(): int
{
    $count = 0;
    if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
        foreach ($_SESSION['cart'] as $item) {
            if (is_array($item)) {
                $count += max(0, (int) ($item['quantity'] ?? 0));
            }
        }
    }
    return $count;
}

function findCartItemIndex(int $productId): int|string|null
{
    foreach ($_SESSION['cart'] as $key => $item) {
        if (is_array($item) && (int) ($item['product_id'] ?? 0) === $productId) {
            return $key;
        }
    }
    return null;
}

function cartProductImageUrl(?string $imageUrl): string
{
    if (empty($imageUrl)) {
        return '';
    }

    if (
        str_starts_with($imageUrl, 'http://') ||
        str_starts_with($imageUrl, 'https://') ||
        str_starts_with($imageUrl, '//')
    ) {
        return $imageUrl;
    }

    return APP_URL . '/' . ltrim($imageUrl, '/');
}

function setCartMessage(string $message, string $type = 'success'): void
{
    $_SESSION['cart_message'] = [
        'message' => $message,
        'type'    => $type
    ];
}

/* Request Handling: POST Actions*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    // Action: Add Item
    if ($action === 'add') {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $quantity  = max(1, (int) ($_POST['quantity'] ?? 1));

        if ($productId <= 0) {
            setCartMessage('Invalid product.', 'error');
            redirectToCart();
        }

        try {
            $stmt = $pdo->prepare("
                SELECT
                    p.id,
                    p.name,
                    p.price,
                    p.status,
                    COALESCE(i.stock_quantity, 0) AS stock_quantity
                FROM products p
                LEFT JOIN inventory i ON i.product_id = p.id
                WHERE p.id = :product_id
                LIMIT 1
            ");
            $stmt->execute([':product_id' => $productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                setCartMessage('The selected product could not be found.', 'error');
                redirectToCart();
            }

            if ($product['status'] === 'discontinued') {
                setCartMessage('This product is no longer available.', 'error');
                redirectToCart();
            }

            $stockQuantity = (int) $product['stock_quantity'];
            if ($stockQuantity <= 0) {
                setCartMessage('This product is currently out of stock.', 'error');
                redirectToCart();
            }

            $existingIndex    = findCartItemIndex($productId);
            $existingQuantity = $existingIndex !== null
                ? (int) ($_SESSION['cart'][$existingIndex]['quantity'] ?? 0)
                : 0;

            $newQuantity = $existingQuantity + $quantity;

            if ($newQuantity > $stockQuantity) {
                setCartMessage("Only {$stockQuantity} unit(s) are available.", 'error');
                redirectToCart();
            }

            if ($existingIndex !== null) {
                $_SESSION['cart'][$existingIndex]['quantity'] = $newQuantity;
            } else {
                $_SESSION['cart'][] = [
                    'product_id' => $productId,
                    'quantity'   => $quantity
                ];
            }

            setCartMessage($product['name'] . ' added to your cart.', 'success');
        } catch (PDOException $e) {
            error_log('Cart add error: ' . $e->getMessage());
            setCartMessage('Something went wrong while adding the product.', 'error');
        }

        redirectToCart();
    }

    // Action: Update Quantity
    if ($action === 'update') {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $quantity  = (int) ($_POST['quantity'] ?? 0);

        if ($productId <= 0) {
            setCartMessage('Invalid product.', 'error');
            redirectToCart();
        }

        $cartIndex = findCartItemIndex($productId);
        if ($cartIndex === null) {
            setCartMessage('Product was not found in your cart.', 'error');
            redirectToCart();
        }

        if ($quantity <= 0) {
            unset($_SESSION['cart'][$cartIndex]);
            $_SESSION['cart'] = array_values($_SESSION['cart']);
            setCartMessage('Product removed from your cart.', 'success');
            redirectToCart();
        }

        try {
            $stmt = $pdo->prepare("
                SELECT
                    p.id,
                    p.name,
                    p.status,
                    COALESCE(i.stock_quantity, 0) AS stock_quantity
                FROM products p
                LEFT JOIN inventory i ON i.product_id = p.id
                WHERE p.id = :product_id
                LIMIT 1
            ");
            $stmt->execute([':product_id' => $productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product || $product['status'] === 'discontinued') {
                unset($_SESSION['cart'][$cartIndex]);
                $_SESSION['cart'] = array_values($_SESSION['cart']);
                setCartMessage('A product in your cart is no longer available.', 'error');
                redirectToCart();
            }

            $stockQuantity = (int) $product['stock_quantity'];
            if ($stockQuantity <= 0) {
                unset($_SESSION['cart'][$cartIndex]);
                $_SESSION['cart'] = array_values($_SESSION['cart']);
                setCartMessage($product['name'] . ' is now out of stock.', 'error');
                redirectToCart();
            }

            if ($quantity > $stockQuantity) {
                $_SESSION['cart'][$cartIndex]['quantity'] = $stockQuantity;
                setCartMessage("Only {$stockQuantity} unit(s) are available. Quantity was adjusted.", 'error');
                redirectToCart();
            }

            $_SESSION['cart'][$cartIndex]['quantity'] = $quantity;
            setCartMessage('Cart updated successfully.', 'success');
        } catch (PDOException $e) {
            error_log('Cart update error: ' . $e->getMessage());
            setCartMessage('Something went wrong while updating your cart.', 'error');
        }

        redirectToCart();
    }

    // Action: Remove Item
    if ($action === 'remove') {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $cartIndex = findCartItemIndex($productId);

        if ($cartIndex !== null) {
            unset($_SESSION['cart'][$cartIndex]);
            $_SESSION['cart'] = array_values($_SESSION['cart']);
            setCartMessage('Product removed from your cart.', 'success');
        }

        redirectToCart();
    }

    // Action: Clear Cart
    if ($action === 'clear') {
        $_SESSION['cart'] = [];
        setCartMessage('Your cart has been cleared.', 'success');
        redirectToCart();
    }
}

/*View Data Loading*/

$cartProducts = [];
$subtotal     = 0.0;
$cartCount    = getCartCount();

if (!empty($_SESSION['cart'])) {
    $productIds = [];
    foreach ($_SESSION['cart'] as $item) {
        if (is_array($item)) {
            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId > 0) {
                $productIds[] = $productId;
            }
        }
    }

    $productIds = array_values(array_unique($productIds));

    if (!empty($productIds)) {
        try {
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $stmt = $pdo->prepare("
                SELECT
                    p.id,
                    p.name,
                    p.category,
                    p.brand,
                    p.description,
                    p.price,
                    p.image_url,
                    p.status,
                    p.warranty_period_months,
                    p.is_repairable,
                    COALESCE(i.stock_quantity, 0) AS stock_quantity,
                    COALESCE(i.low_stock_threshold, 5) AS low_stock_threshold
                FROM products p
                LEFT JOIN inventory i ON i.product_id = p.id
                WHERE p.id IN ($placeholders)
            ");
            $stmt->execute($productIds);
            $databaseProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $productsById = [];
            foreach ($databaseProducts as $product) {
                $productsById[(int) $product['id']] = $product;
            }

            foreach ($_SESSION['cart'] as $key => $cartItem) {
                if (!is_array($cartItem)) {
                    continue;
                }

                $productId = (int) ($cartItem['product_id'] ?? 0);
                $quantity  = max(1, (int) ($cartItem['quantity'] ?? 1));

                if (!isset($productsById[$productId])) {
                    unset($_SESSION['cart'][$key]);
                    continue;
                }

                $product = $productsById[$productId];
                if ($product['status'] === 'discontinued') {
                    unset($_SESSION['cart'][$key]);
                    continue;
                }

                $stockQuantity = (int) $product['stock_quantity'];

                if ($stockQuantity <= 0) {
                    $cartProducts[] = [
                        'cart_key'     => $key,
                        'product'      => $product,
                        'quantity'     => $quantity,
                        'line_total'   => 0.0,
                        'available'    => false,
                        'max_quantity' => 0
                    ];
                    continue;
                }

                if ($quantity > $stockQuantity) {
                    $quantity = $stockQuantity;
                    $_SESSION['cart'][$key]['quantity'] = $quantity;
                }

                $lineTotal  = (float) $product['price'] * $quantity;
                $subtotal  += $lineTotal;

                $cartProducts[] = [
                    'cart_key'     => $key,
                    'product'      => $product,
                    'quantity'     => $quantity,
                    'line_total'   => $lineTotal,
                    'available'    => true,
                    'max_quantity' => $stockQuantity
                ];
            }

            $_SESSION['cart'] = array_values($_SESSION['cart']);
        } catch (PDOException $e) {
            error_log('Cart fetch products error: ' . $e->getMessage());
        }
    }
}

$cartCount   = getCartCount();
$cartMessage = $_SESSION['cart_message'] ?? null;
unset($_SESSION['cart_message']);

require_once __DIR__ . '/includes/header.php';
?>

<!-- CART PAGE HERO -->
<section class="bg-brand-dark text-white rounded-3xl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 sm:py-14">
        <div class="max-w-3xl">
            <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-white/5 border border-white/10 text-xs font-semibold text-gray-300 mb-5">
                <span class="w-1.5 h-1.5 rounded-full bg-brand-red"></span>
                THIRASARA MAX MOBILE
            </div>
            <h1 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold tracking-tight">Shopping Cart</h1>
            <p class="mt-4 text-gray-400 text-sm sm:text-base leading-relaxed">
                Review your selected products before continuing with your order.
            </p>
        </div>
    </div>
</section>

<!-- MAIN CART CONTENT -->
<main class="flex-1">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10">

        <!-- FLASH NOTIFICATION -->
        <?php if ($cartMessage): ?>
            <?php
            $isSuccess    = ($cartMessage['type'] ?? '') === 'success';
            $messageClass = $isSuccess ? 'bg-green-50 border-green-200 text-green-700' : 'bg-red-50 border-red-200 text-red-700';
            $icon         = $isSuccess ? 'check-circle' : 'alert-circle';
            ?>
            <div class="mb-6 flex items-start gap-3 px-4 py-3.5 rounded-xl border <?= $messageClass ?>">
                <i data-lucide="<?= $icon ?>" class="w-5 h-5 flex-shrink-0 mt-0.5"></i>
                <p class="text-sm font-medium"><?= htmlspecialchars($cartMessage['message']) ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($cartProducts)): ?>
            <!-- CART HEADER BAR -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <div>
                    <h2 class="text-xl font-bold text-gray-900">Your Items</h2>
                    <p class="mt-1 text-sm text-gray-500">
                        <?= $cartCount ?> item<?= $cartCount === 1 ? '' : 's' ?> in your cart
                    </p>
                </div>

                <form method="POST" action="<?= APP_URL ?>/cart.php" onsubmit="return confirm('Are you sure you want to clear your cart?');">
                    <input type="hidden" name="action" value="clear">
                    <button type="submit" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-gray-200 bg-white hover:bg-red-50 hover:border-red-200 text-gray-600 hover:text-brand-red text-sm font-semibold transition">
                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                        Clear Cart
                    </button>
                </form>
            </div>

            <!-- CART LAYOUT -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:gap-8">
                <!-- ITEMS COLUMN -->
                <div class="lg:col-span-2 space-y-4">
                    <?php foreach ($cartProducts as $cartItem): ?>
                        <?php
                        $product     = $cartItem['product'];
                        $productId   = (int) $product['id'];
                        $productName = $product['name'];
                        $quantity    = (int) $cartItem['quantity'];
                        $price       = (float) $product['price'];
                        $lineTotal   = (float) $cartItem['line_total'];
                        $available   = $cartItem['available'];
                        $maxQuantity = (int) $cartItem['max_quantity'];
                        $imageUrl    = cartProductImageUrl($product['image_url'] ?? null);
                        ?>
                        <article class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                            <div class="p-4 sm:p-5 flex flex-col sm:flex-row gap-4">
                                <!-- Image -->
                                <a href="<?= APP_URL ?>/product-details.php?id=<?= $productId ?>" class="flex-shrink-0">
                                    <div class="w-full sm:w-28 h-28 rounded-xl bg-gray-100 overflow-hidden flex items-center justify-center">
                                        <?php if ($imageUrl): ?>
                                            <img src="<?= htmlspecialchars($imageUrl) ?>"
                                                alt="<?= htmlspecialchars($productName) ?>"
                                                class="w-full h-full object-cover"
                                                loading="lazy"
                                                onerror="this.style.display='none'; this.nextElementSibling.classList.remove('hidden');">
                                            <div class="hidden flex-col items-center justify-center text-gray-400">
                                                <i data-lucide="image-off" class="w-8 h-8"></i>
                                            </div>
                                        <?php else: ?>
                                            <div class="flex flex-col items-center justify-center text-gray-400">
                                                <i data-lucide="package" class="w-8 h-8"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </a>

                                <!-- Details -->
                                <div class="flex-1 min-w-0">
                                    <?php if (!empty($product['brand'])): ?>
                                        <p class="text-[10px] uppercase tracking-wider font-bold text-brand-red mb-1">
                                            <?= htmlspecialchars($product['brand']) ?>
                                        </p>
                                    <?php endif; ?>

                                    <a href="<?= APP_URL ?>/product-details.php?id=<?= $productId ?>" class="block">
                                        <h3 class="text-base sm:text-lg font-bold text-gray-900 hover:text-brand-red transition">
                                            <?= htmlspecialchars($productName) ?>
                                        </h3>
                                    </a>

                                    <p class="text-xs text-gray-500 mt-1">
                                        <?= htmlspecialchars($product['category'] ?? '') ?>
                                    </p>

                                    <div class="mt-3 flex items-center gap-2">
                                        <span class="text-base font-bold text-gray-900">
                                            Rs. <?= number_format($price, 2) ?>
                                        </span>
                                        <span class="text-xs text-gray-400">each</span>
                                    </div>

                                    <!-- Status Notices -->
                                    <?php if (!$available): ?>
                                        <div class="mt-3 inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-red-50 border border-red-100 text-red-600 text-xs font-semibold">
                                            <i data-lucide="package-x" class="w-3.5 h-3.5"></i>
                                            Currently out of stock
                                        </div>
                                    <?php elseif ($maxQuantity <= 5): ?>
                                        <div class="mt-3 inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-amber-50 border border-amber-100 text-amber-700 text-xs font-semibold">
                                            <i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i>
                                            Only <?= $maxQuantity ?> left
                                        </div>
                                    <?php endif; ?>

                                    <!-- Controls -->
                                    <div class="mt-4 flex flex-wrap items-center gap-3">
                                        <?php if ($available): ?>
                                            <form method="POST" action="<?= APP_URL ?>/cart.php" class="flex items-center gap-2">
                                                <input type="hidden" name="action" value="update">
                                                <input type="hidden" name="product_id" value="<?= $productId ?>">

                                                <div class="flex items-center border border-gray-200 rounded-xl overflow-hidden bg-gray-50">
                                                    <button type="button"
                                                        class="quantity-minus w-9 h-9 flex items-center justify-center text-gray-500 hover:text-brand-red hover:bg-white transition"
                                                        data-target="quantity-<?= $productId ?>">
                                                        <i data-lucide="minus" class="w-4 h-4"></i>
                                                    </button>

                                                    <input type="number"
                                                        id="quantity-<?= $productId ?>"
                                                        name="quantity"
                                                        value="<?= $quantity ?>"
                                                        min="1"
                                                        max="<?= $maxQuantity ?>"
                                                        class="w-12 h-9 text-center text-sm font-semibold bg-transparent border-x border-gray-200 outline-none">

                                                    <button type="button"
                                                        class="quantity-plus w-9 h-9 flex items-center justify-center text-gray-500 hover:text-brand-red hover:bg-white transition"
                                                        data-target="quantity-<?= $productId ?>">
                                                        <i data-lucide="plus" class="w-4 h-4"></i>
                                                    </button>
                                                </div>

                                                <button type="submit"
                                                    class="px-3 py-2 rounded-lg bg-gray-100 hover:bg-brand-red hover:text-white text-gray-600 text-xs font-semibold transition">
                                                    Update
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <!-- Remove Button -->
                                        <form method="POST" action="<?= APP_URL ?>/cart.php">
                                            <input type="hidden" name="action" value="remove">
                                            <input type="hidden" name="product_id" value="<?= $productId ?>">
                                            <button type="submit"
                                                class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 text-xs font-semibold transition">
                                                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                                Remove
                                            </button>
                                        </form>
                                    </div>
                                </div>

                                <!-- Line Total -->
                                <div class="sm:text-right flex sm:block items-center justify-between border-t sm:border-t-0 pt-4 sm:pt-0">
                                    <p class="text-xs text-gray-400 mb-1">Total</p>
                                    <p class="text-lg font-extrabold text-gray-900">
                                        Rs. <?= number_format($lineTotal, 2) ?>
                                    </p>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <!-- SIDEBAR / SUMMARY -->
                <aside>
                    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5 sm:p-6 lg:sticky lg:top-24">
                        <h2 class="text-lg font-bold text-gray-900">Order Summary</h2>

                        <div class="mt-5 space-y-4">
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-500">Items</span>
                                <span class="font-semibold text-gray-900"><?= $cartCount ?></span>
                            </div>

                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-500">Subtotal</span>
                                <span class="font-semibold text-gray-900">Rs. <?= number_format($subtotal, 2) ?></span>
                            </div>

                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-500">Delivery</span>
                                <span class="font-semibold text-gray-900">Calculated at checkout</span>
                            </div>

                            <div class="border-t border-gray-200 pt-4">
                                <div class="flex items-center justify-between">
                                    <span class="text-base font-bold text-gray-900">Total</span>
                                    <span class="text-xl font-extrabold text-brand-red">
                                        Rs. <?= number_format($subtotal, 2) ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Checkout Button -->
                        <?php
                        $hasUnavailable = false;
                        foreach ($cartProducts as $item) {
                            if (!$item['available']) {
                                $hasUnavailable = true;
                                break;
                            }
                        }
                        ?>

                        <?php if (!$hasUnavailable && $subtotal > 0): ?>
                            <a href="<?= APP_URL ?>/checkout.php"
                                class="mt-6 w-full h-12 inline-flex items-center justify-center gap-2 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-sm font-bold transition shadow-sm hover:shadow-md">
                                Proceed to Checkout
                                <i data-lucide="arrow-right" class="w-4 h-4"></i>
                            </a>
                        <?php else: ?>
                            <button type="button" disabled
                                class="mt-6 w-full h-12 inline-flex items-center justify-center gap-2 rounded-xl bg-gray-100 text-gray-400 text-sm font-bold cursor-not-allowed">
                                <i data-lucide="alert-circle" class="w-4 h-4"></i>
                                Update Cart to Continue
                            </button>
                        <?php endif; ?>

                        <a href="<?= APP_URL ?>/products.php"
                            class="mt-3 w-full h-11 inline-flex items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 text-sm font-semibold transition">
                            <i data-lucide="arrow-left" class="w-4 h-4"></i>
                            Continue Shopping
                        </a>

                        <!-- Trust Information -->
                        <div class="mt-6 pt-5 border-t border-gray-100 space-y-3">
                            <div class="flex items-start gap-3">
                                <div class="w-8 h-8 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0">
                                    <i data-lucide="shield-check" class="w-4 h-4 text-brand-red"></i>
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-gray-900">Warranty Support</p>
                                    <p class="text-[11px] text-gray-500 mt-0.5">Warranty information is shown for eligible products.</p>
                                </div>
                            </div>

                            <div class="flex items-start gap-3">
                                <div class="w-8 h-8 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0">
                                    <i data-lucide="package-check" class="w-4 h-4 text-brand-red"></i>
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-gray-900">Stock Checked</p>
                                    <p class="text-[11px] text-gray-500 mt-0.5">Product availability is checked before checkout.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>
        <?php else: ?>
            <!-- EMPTY CART STATE -->
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm py-16 px-6 text-center">
                <div class="w-20 h-20 mx-auto rounded-2xl bg-gray-100 flex items-center justify-center mb-5">
                    <i data-lucide="shopping-cart" class="w-10 h-10 text-gray-400"></i>
                </div>
                <h2 class="text-2xl font-bold text-gray-900">Your cart is empty</h2>
                <p class="mt-2 max-w-md mx-auto text-sm text-gray-500 leading-relaxed">
                    You haven't added any products to your cart yet. Browse our mobile accessories and products to get started.
                </p>
                <a href="<?= APP_URL ?>/products.php"
                    class="inline-flex items-center gap-2 mt-6 px-6 py-3 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-sm font-bold transition shadow-sm">
                    <i data-lucide="shopping-bag" class="w-4 h-4"></i>
                    Browse Products
                </a>
            </div>
        <?php endif; ?>

    </div>
</main>

<script>
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }

    // Stepper Handlers
    document.querySelectorAll('.quantity-minus').forEach(button => {
        button.addEventListener('click', function() {
            const input = document.getElementById(this.dataset.target);
            if (!input) return;
            const current = parseInt(input.value || '1', 10);
            const min = parseInt(input.min || '1', 10);
            if (current > min) {
                input.value = current - 1;
            }
        });
    });

    document.querySelectorAll('.quantity-plus').forEach(button => {
        button.addEventListener('click', function() {
            const input = document.getElementById(this.dataset.target);
            if (!input) return;
            const current = parseInt(input.value || '1', 10);
            const max = parseInt(input.max || '999', 10);
            if (current < max) {
                input.value = current + 1;
            }
        });
    });

    // Input Bounds Clamping
    document.querySelectorAll('input[type="number"]').forEach(input => {
        input.addEventListener('change', function() {
            let value = parseInt(this.value || '1', 10);
            const min = parseInt(this.min || '1', 10);
            const max = parseInt(this.max || '999', 10);
            this.value = Math.max(min, Math.min(value, max));
        });
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>