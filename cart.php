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

<!-- CART PAGE HERO (High-Contrast Style) -->
<section class="bg-gradient-to-r from-gray-900 via-zinc-900 to-gray-900 text-white border-b-4 border-brand-red py-12 mb-10 overflow-hidden rounded-3xl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded bg-brand-red text-white text-[10px] font-bold uppercase tracking-wider mb-3 shadow-sm">
            <span class="w-1.5 h-1.5 bg-white rounded-full"></span> THIRASARA MAX MOBILE
        </span>
        <h1 class="text-3xl sm:text-4xl font-black uppercase tracking-tight text-white">Shopping Cart</h1>
        <p class="text-gray-300 text-xs sm:text-sm mt-2 leading-relaxed">
            Review your selected products before continuing with your order.
        </p>
    </div>
</section>

<!-- MAIN CART CONTENT -->
<main class="flex-1 pb-16">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- FLASH NOTIFICATION -->
        <?php if ($cartMessage): ?>
            <?php
            $isSuccess    = ($cartMessage['type'] ?? '') === 'success';
            $messageClass = $isSuccess ? 'bg-emerald-50 border-2 border-emerald-200 text-emerald-700' : 'bg-red-50 border-2 border-red-200 text-red-700';
            $icon         = $isSuccess ? 'check-circle' : 'alert-circle';
            ?>
            <div class="mb-6 flex items-start gap-3 px-4 py-3.5 rounded-xl <?= $messageClass ?> text-xs sm:text-sm font-bold shadow-sm">
                <i data-lucide="<?= $icon ?>" class="w-5 h-5 flex-shrink-0 mt-0.5"></i>
                <p><?= htmlspecialchars($cartMessage['message']) ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($cartProducts)): ?>
            <!-- CART HEADER BAR -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6 pb-3 border-b-2 border-gray-200">
                <div>
                    <h2 class="text-xl font-black uppercase tracking-tight text-gray-900">Your Items</h2>
                    <p class="text-xs text-gray-500 font-medium mt-0.5">
                        <?= $cartCount ?> item<?= $cartCount === 1 ? '' : 's' ?> in your cart
                    </p>
                </div>

                <form method="POST" action="<?= APP_URL ?>/cart.php" onsubmit="return confirm('Are you sure you want to clear your cart?');">
                    <input type="hidden" name="action" value="clear">
                    <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl border-2 border-gray-200 bg-white hover:bg-red-50 hover:border-red-300 text-gray-700 hover:text-brand-red text-xs font-bold transition shadow-sm">
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
                        $product    = $cartItem['product'];
                        $productId  = (int) $product['id'];
                        $productName = $product['name'];
                        $quantity    = (int) $cartItem['quantity'];
                        $price       = (float) $product['price'];
                        $lineTotal   = (float) $cartItem['line_total'];
                        $available   = $cartItem['available'];
                        $maxQuantity = (int) $cartItem['max_quantity'];
                        $imageUrl    = cartProductImageUrl($product['image_url'] ?? null);
                        ?>
                        <article class="bg-white rounded-xl border-2 border-gray-200 shadow-sm overflow-hidden hover:border-gray-300 transition-all">
                            <div class="p-4 sm:p-5 flex flex-col sm:flex-row gap-4">
                                <!-- Image -->
                                <a href="<?= APP_URL ?>/product-details.php?id=<?= $productId ?>" class="flex-shrink-0">
                                    <div class="w-full sm:w-24 h-24 rounded-lg bg-gray-50 border border-gray-200 overflow-hidden flex items-center justify-center">
                                        <?php if ($imageUrl): ?>
                                            <img src="<?= htmlspecialchars($imageUrl) ?>"
                                                alt="<?= htmlspecialchars($productName) ?>"
                                                class="w-full h-full object-cover"
                                                loading="lazy"
                                                onerror="this.style.display='none'; this.nextElementSibling.classList.remove('hidden');">
                                            <div class="hidden flex-col items-center justify-center text-gray-400">
                                                <i data-lucide="image-off" class="w-6 h-6"></i>
                                            </div>
                                        <?php else: ?>
                                            <div class="flex flex-col items-center justify-center text-gray-300">
                                                <i data-lucide="smartphone" class="w-8 h-8"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </a>

                                <!-- Details -->
                                <div class="flex-1 min-w-0">
                                    <?php if (!empty($product['brand'])): ?>
                                        <p class="text-[10px] uppercase tracking-wider font-extrabold text-brand-red mb-0.5">
                                            <?= htmlspecialchars($product['brand']) ?>
                                        </p>
                                    <?php endif; ?>

                                    <a href="<?= APP_URL ?>/product-details.php?id=<?= $productId ?>" class="block">
                                        <h3 class="text-xs sm:text-sm font-bold text-gray-900 hover:text-brand-red transition line-clamp-2">
                                            <?= htmlspecialchars($productName) ?>
                                        </h3>
                                    </a>

                                    <p class="text-[11px] text-gray-500 mt-0.5">
                                        <?= htmlspecialchars($product['category'] ?? '') ?>
                                    </p>

                                    <div class="mt-2 flex items-center gap-1.5">
                                        <span class="text-sm font-extrabold text-gray-900">
                                            Rs. <?= number_format($price, 2) ?>
                                        </span>
                                        <span class="text-[11px] text-gray-400">each</span>
                                    </div>

                                    <!-- Status Notices -->
                                    <?php if (!$available): ?>
                                        <div class="mt-2 inline-flex items-center gap-1 px-2 py-1 rounded bg-red-500 text-white text-[10px] font-extrabold uppercase">
                                            <i data-lucide="package-x" class="w-3 h-3"></i>
                                            Currently out of stock
                                        </div>
                                    <?php elseif ($maxQuantity <= 5): ?>
                                        <div class="mt-2 inline-flex items-center gap-1 px-2 py-1 rounded bg-amber-500 text-white text-[10px] font-extrabold uppercase">
                                            <i data-lucide="alert-triangle" class="w-3 h-3"></i>
                                            Only <?= $maxQuantity ?> left
                                        </div>
                                    <?php endif; ?>

                                    <!-- Controls -->
                                    <div class="mt-3.5 flex flex-wrap items-center gap-3">
                                        <?php if ($available): ?>
                                            <form method="POST" action="<?= APP_URL ?>/cart.php" class="flex items-center gap-2">
                                                <input type="hidden" name="action" value="update">
                                                <input type="hidden" name="product_id" value="<?= $productId ?>">

                                                <div class="flex items-center border border-gray-300 rounded-lg overflow-hidden bg-gray-50">
                                                    <button type="button"
                                                        class="quantity-minus w-8 h-8 flex items-center justify-center text-gray-600 hover:text-brand-red hover:bg-white transition"
                                                        data-target="quantity-<?= $productId ?>">
                                                        <i data-lucide="minus" class="w-3.5 h-3.5"></i>
                                                    </button>

                                                    <input type="number"
                                                        id="quantity-<?= $productId ?>"
                                                        name="quantity"
                                                        value="<?= $quantity ?>"
                                                        min="1"
                                                        max="<?= $maxQuantity ?>"
                                                        class="w-10 h-8 text-center text-xs font-bold bg-transparent border-x border-gray-300 outline-none">

                                                    <button type="button"
                                                        class="quantity-plus w-8 h-8 flex items-center justify-center text-gray-600 hover:text-brand-red hover:bg-white transition"
                                                        data-target="quantity-<?= $productId ?>">
                                                        <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                                                    </button>
                                                </div>

                                                <button type="submit"
                                                    class="px-3 py-2 rounded-lg bg-gray-100 hover:bg-brand-red hover:text-white text-gray-700 text-xs font-bold transition">
                                                    Update
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <!-- Remove Button -->
                                        <form method="POST" action="<?= APP_URL ?>/cart.php">
                                            <input type="hidden" name="action" value="remove">
                                            <input type="hidden" name="product_id" value="<?= $productId ?>">
                                            <button type="submit"
                                                class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 text-xs font-bold transition">
                                                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                                Remove
                                            </button>
                                        </form>
                                    </div>
                                </div>

                                <!-- Line Total -->
                                <div class="sm:text-right flex sm:block items-center justify-between border-t sm:border-t-0 pt-3 sm:pt-0">
                                    <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-0.5">Total</p>
                                    <p class="text-base sm:text-lg font-black text-gray-900">
                                        Rs. <?= number_format($lineTotal, 2) ?>
                                    </p>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <!-- SIDEBAR / SUMMARY -->
                <aside>
                    <div class="bg-white rounded-xl border-2 border-gray-200 shadow-sm p-5 sm:p-6 lg:sticky lg:top-24">
                        <h2 class="text-sm font-black uppercase tracking-tight text-gray-900 pb-3 border-b-2 border-gray-100">Order Summary</h2>

                        <div class="mt-4 space-y-3 text-xs">
                            <div class="flex items-center justify-between">
                                <span class="text-gray-500 font-medium">Items</span>
                                <span class="font-bold text-gray-900"><?= $cartCount ?></span>
                            </div>

                            <div class="flex items-center justify-between">
                                <span class="text-gray-500 font-medium">Subtotal</span>
                                <span class="font-bold text-gray-900">Rs. <?= number_format($subtotal, 2) ?></span>
                            </div>

                            <div class="flex items-center justify-between">
                                <span class="text-gray-500 font-medium">Delivery</span>
                                <span class="font-bold text-gray-700">Calculated at checkout</span>
                            </div>

                            <div class="border-t-2 border-gray-100 pt-3 mt-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm font-black uppercase tracking-tight text-gray-900">Total</span>
                                    <span class="text-lg font-black text-brand-red">
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
                                class="mt-5 w-full h-11 inline-flex items-center justify-center gap-2 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-xs sm:text-sm font-bold transition shadow-sm">
                                Proceed to Checkout
                                <i data-lucide="arrow-right" class="w-4 h-4"></i>
                            </a>
                        <?php else: ?>
                            <button type="button" disabled
                                class="mt-5 w-full h-11 inline-flex items-center justify-center gap-2 rounded-xl bg-gray-100 text-gray-400 text-xs sm:text-sm font-bold cursor-not-allowed">
                                <i data-lucide="alert-circle" class="w-4 h-4"></i>
                                Update Cart to Continue
                            </button>
                        <?php endif; ?>

                        <a href="<?= APP_URL ?>/products.php"
                            class="mt-2.5 w-full h-10 inline-flex items-center justify-center gap-2 rounded-xl border-2 border-gray-200 bg-white hover:bg-gray-100 text-gray-800 text-xs sm:text-sm font-bold transition">
                            <i data-lucide="arrow-left" class="w-4 h-4"></i>
                            Continue Shopping
                        </a>

                        <!-- Trust Information -->
                        <div class="mt-5 pt-4 border-t-2 border-gray-100 space-y-3">
                            <div class="flex items-start gap-2.5">
                                <div class="w-7 h-7 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0 text-brand-red">
                                    <i data-lucide="shield-check" class="w-4 h-4"></i>
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-gray-900">Warranty Support</p>
                                    <p class="text-[10px] text-gray-500 mt-0.5 leading-relaxed">Warranty information is shown for eligible products.</p>
                                </div>
                            </div>

                            <div class="flex items-start gap-2.5">
                                <div class="w-7 h-7 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0 text-brand-red">
                                    <i data-lucide="package-check" class="w-4 h-4"></i>
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-gray-900">Stock Checked</p>
                                    <p class="text-[10px] text-gray-500 mt-0.5 leading-relaxed">Product availability is checked before checkout.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>
        <?php else: ?>
            <!-- EMPTY CART STATE -->
            <div class="bg-white rounded-xl border-2 border-gray-200 shadow-sm py-16 px-6 text-center">
                <div class="w-16 h-16 mx-auto rounded-xl bg-gray-100 flex items-center justify-center mb-4 text-gray-400">
                    <i data-lucide="shopping-cart" class="w-8 h-8"></i>
                </div>
                <h2 class="text-lg font-black uppercase tracking-tight text-gray-900">Your cart is empty</h2>
                <p class="mt-1 max-w-sm mx-auto text-xs sm:text-sm text-gray-500 leading-relaxed">
                    You haven't added any products to your cart yet. Browse our mobile accessories and products to get started.
                </p>
                <a href="<?= APP_URL ?>/products.php"
                    class="inline-flex items-center gap-2 mt-5 px-5 py-2.5 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-xs sm:text-sm font-bold transition shadow-sm">
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