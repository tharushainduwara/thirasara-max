<?php

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = 'Shopping Cart';

$pdo = Database::getConnection();

/* Initialize Cart*/

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

/*Helper: Redirect Back To Cart*/

function redirectToCart(): void
{
    header('Location: ' . APP_URL . '/cart.php');
    exit;
}

/* Helper: Get Cart Count*/

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

/* Helper: Find Cart Item*/

function findCartItemIndex(int $productId): int|string|null
{
    foreach ($_SESSION['cart'] as $key => $item) {

        if (!is_array($item)) {
            continue;
        }

        if ((int) ($item['product_id'] ?? 0) === $productId) {
            return $key;
        }
    }

    return null;
}

/* Helper: Product Image URL*/

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

/* Helper: Flash Message*/

function setCartMessage(string $message, string $type = 'success'): void
{
    $_SESSION['cart_message'] = [
        'message' => $message,
        'type' => $type
    ];
}

/*Handle Cart Actions*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = trim($_POST['action'] ?? '');

    /* Add Product*/

    if ($action === 'add') {

        $productId = (int) ($_POST['product_id'] ?? 0);
        $quantity = max(1, (int) ($_POST['quantity'] ?? 1));

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
                LEFT JOIN inventory i
                    ON i.product_id = p.id
                WHERE p.id = :product_id
                LIMIT 1
            ");

            $stmt->execute([
                ':product_id' => $productId
            ]);

            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {

                setCartMessage(
                    'The selected product could not be found.',
                    'error'
                );

                redirectToCart();
            }

            if ($product['status'] === 'discontinued') {

                setCartMessage(
                    'This product is no longer available.',
                    'error'
                );

                redirectToCart();
            }

            $stockQuantity = (int) $product['stock_quantity'];

            if ($stockQuantity <= 0) {

                setCartMessage(
                    'This product is currently out of stock.',
                    'error'
                );

                redirectToCart();
            }

            /* Existing Quantity*/

            $existingIndex = findCartItemIndex($productId);

            $existingQuantity = 0;

            if ($existingIndex !== null) {
                $existingQuantity = (int) (
                    $_SESSION['cart'][$existingIndex]['quantity'] ?? 0
                );
            }

            $newQuantity = $existingQuantity + $quantity;

            if ($newQuantity > $stockQuantity) {

                setCartMessage(
                    'Only ' . $stockQuantity . ' unit(s) are available.',
                    'error'
                );

                redirectToCart();
            }

            /* Add ,Update Cart*/

            if ($existingIndex !== null) {

                $_SESSION['cart'][$existingIndex]['quantity'] = $newQuantity;
            } else {

                $_SESSION['cart'][] = [
                    'product_id' => $productId,
                    'quantity' => $quantity
                ];
            }

            setCartMessage(
                $product['name'] . ' added to your cart.',
                'success'
            );
        } catch (PDOException $e) {

            error_log(
                'Cart add product error: ' . $e->getMessage()
            );

            setCartMessage(
                'Something went wrong while adding the product.',
                'error'
            );
        }

        redirectToCart();
    }

    /* Update Cart*/

    if ($action === 'update') {

        $productId = (int) ($_POST['product_id'] ?? 0);
        $quantity = (int) ($_POST['quantity'] ?? 0);

        if ($productId <= 0) {

            setCartMessage(
                'Invalid product.',
                'error'
            );

            redirectToCart();
        }

        $cartIndex = findCartItemIndex($productId);

        if ($cartIndex === null) {

            setCartMessage(
                'Product was not found in your cart.',
                'error'
            );

            redirectToCart();
        }

        /* Quantity 0 = Remove*/

        if ($quantity <= 0) {

            unset($_SESSION['cart'][$cartIndex]);

            $_SESSION['cart'] = array_values($_SESSION['cart']);

            setCartMessage(
                'Product removed from your cart.',
                'success'
            );

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
                LEFT JOIN inventory i
                    ON i.product_id = p.id
                WHERE p.id = :product_id
                LIMIT 1
            ");

            $stmt->execute([
                ':product_id' => $productId
            ]);

            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {

                unset($_SESSION['cart'][$cartIndex]);

                $_SESSION['cart'] = array_values($_SESSION['cart']);

                setCartMessage(
                    'A product in your cart is no longer available.',
                    'error'
                );

                redirectToCart();
            }

            if ($product['status'] === 'discontinued') {

                unset($_SESSION['cart'][$cartIndex]);

                $_SESSION['cart'] = array_values($_SESSION['cart']);

                setCartMessage(
                    $product['name'] . ' is no longer available.',
                    'error'
                );

                redirectToCart();
            }

            $stockQuantity = (int) $product['stock_quantity'];

            if ($stockQuantity <= 0) {

                unset($_SESSION['cart'][$cartIndex]);

                $_SESSION['cart'] = array_values($_SESSION['cart']);

                setCartMessage(
                    $product['name'] . ' is now out of stock.',
                    'error'
                );

                redirectToCart();
            }

            if ($quantity > $stockQuantity) {

                $_SESSION['cart'][$cartIndex]['quantity'] = $stockQuantity;

                setCartMessage(
                    'Only ' . $stockQuantity . ' unit(s) are available. Cart quantity was adjusted.',
                    'error'
                );

                redirectToCart();
            }

            $_SESSION['cart'][$cartIndex]['quantity'] = $quantity;

            setCartMessage(
                'Cart updated successfully.',
                'success'
            );
        } catch (PDOException $e) {

            error_log(
                'Cart update error: ' . $e->getMessage()
            );

            setCartMessage(
                'Something went wrong while updating your cart.',
                'error'
            );
        }

        redirectToCart();
    }

    /* Remove Product */

    if ($action === 'remove') {

        $productId = (int) ($_POST['product_id'] ?? 0);

        $cartIndex = findCartItemIndex($productId);

        if ($cartIndex !== null) {

            unset($_SESSION['cart'][$cartIndex]);

            $_SESSION['cart'] = array_values($_SESSION['cart']);

            setCartMessage(
                'Product removed from your cart.',
                'success'
            );
        }

        redirectToCart();
    }

    /*Clear Cart*/

    if ($action === 'clear') {

        $_SESSION['cart'] = [];

        setCartMessage(
            'Your cart has been cleared.',
            'success'
        );

        redirectToCart();
    }
}

/*Load Cart Products*/

$cartProducts = [];
$subtotal = 0;
$cartCount = getCartCount();

if (!empty($_SESSION['cart'])) {

    $productIds = [];

    foreach ($_SESSION['cart'] as $item) {

        if (!is_array($item)) {
            continue;
        }

        $productId = (int) ($item['product_id'] ?? 0);

        if ($productId > 0) {
            $productIds[] = $productId;
        }
    }

    $productIds = array_values(array_unique($productIds));

    if (!empty($productIds)) {

        try {

            $placeholders = implode(
                ',',
                array_fill(0, count($productIds), '?')
            );

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

                LEFT JOIN inventory i
                    ON i.product_id = p.id

                WHERE p.id IN ($placeholders)
            ");

            $stmt->execute($productIds);

            $databaseProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $productsById = [];

            foreach ($databaseProducts as $product) {
                $productsById[(int) $product['id']] = $product;
            }

            /*Build Final Cart*/

            foreach ($_SESSION['cart'] as $key => $cartItem) {

                if (!is_array($cartItem)) {
                    continue;
                }

                $productId = (int) ($cartItem['product_id'] ?? 0);
                $quantity = max(
                    1,
                    (int) ($cartItem['quantity'] ?? 1)
                );

                if (!isset($productsById[$productId])) {

                    unset($_SESSION['cart'][$key]);

                    continue;
                }

                $product = $productsById[$productId];

                /* Remove discontinued products*/

                if ($product['status'] === 'discontinued') {

                    unset($_SESSION['cart'][$key]);

                    continue;
                }

                $stockQuantity = (int) $product['stock_quantity'];

                /*Product has no stock*/

                if ($stockQuantity <= 0) {

                    $cartProducts[] = [
                        'cart_key' => $key,
                        'product' => $product,
                        'quantity' => $quantity,
                        'line_total' => 0,
                        'available' => false,
                        'max_quantity' => 0
                    ];

                    continue;
                }

                /* Adjust quantity if stock changed*/

                if ($quantity > $stockQuantity) {

                    $quantity = $stockQuantity;

                    $_SESSION['cart'][$key]['quantity'] = $quantity;
                }

                $lineTotal =
                    (float) $product['price'] * $quantity;

                $subtotal += $lineTotal;

                $cartProducts[] = [
                    'cart_key' => $key,
                    'product' => $product,
                    'quantity' => $quantity,
                    'line_total' => $lineTotal,
                    'available' => true,
                    'max_quantity' => $stockQuantity
                ];
            }

            $_SESSION['cart'] = array_values($_SESSION['cart']);
        } catch (PDOException $e) {

            error_log(
                'Cart load error: ' . $e->getMessage()
            );
        }
    }
}

/* Recalculate Cart Count*/

$cartCount = getCartCount();

/* Flash Message*/

$cartMessage = $_SESSION['cart_message'] ?? null;

unset($_SESSION['cart_message']);

?>

<?php require_once __DIR__ . '/includes/header.php'; ?>


<!--CART PAGE -->

<section class="bg-brand-dark text-white rounded-3xl">

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 sm:py-14">

        <div class="max-w-3xl">

            <div
                class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-white/5 border border-white/10 text-xs font-semibold text-gray-300 mb-5">

                <span
                    class="w-1.5 h-1.5 rounded-full bg-brand-red"></span>

                THIRASARA MAX MOBILE

            </div>

            <h1
                class="text-3xl sm:text-4xl lg:text-5xl font-extrabold tracking-tight">

                Shopping Cart

            </h1>

            <p
                class="mt-4 text-gray-400 text-sm sm:text-base leading-relaxed">

                Review your selected products before continuing with your order.

            </p>

        </div>

    </div>

</section>


<!-- MAIN CART CONTENT-->

<main class="flex-1">

    <div
        class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10">


        <!-- FLASH MESSAGE-->

        <?php if ($cartMessage): ?>

            <?php
            $isSuccess = ($cartMessage['type'] ?? '') === 'success';

            $messageClass = $isSuccess
                ? 'bg-green-50 border-green-200 text-green-700'
                : 'bg-red-50 border-red-200 text-red-700';

            $icon = $isSuccess
                ? 'check-circle'
                : 'alert-circle';
            ?>

            <div
                class="mb-6 flex items-start gap-3 px-4 py-3.5 rounded-xl border <?php echo $messageClass; ?>">

                <i
                    data-lucide="<?php echo $icon; ?>"
                    class="w-5 h-5 flex-shrink-0 mt-0.5"></i>

                <p class="text-sm font-medium">
                    <?php echo htmlspecialchars($cartMessage['message']); ?>
                </p>

            </div>

        <?php endif; ?>


        <?php if (!empty($cartProducts)): ?>


            <!-- CART HEADER-->

            <div
                class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">

                <div>

                    <h2 class="text-xl font-bold text-gray-900">
                        Your Items
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">

                        <?php echo $cartCount; ?>

                        item<?php echo $cartCount === 1 ? '' : 's'; ?>

                        in your cart

                    </p>

                </div>


                <!-- Clear Cart -->

                <form
                    method="POST"
                    action="<?php echo APP_URL; ?>/cart.php"
                    onsubmit="return confirm('Are you sure you want to clear your cart?');">

                    <input
                        type="hidden"
                        name="action"
                        value="clear">

                    <button
                        type="submit"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-gray-200 bg-white hover:bg-red-50 hover:border-red-200 text-gray-600 hover:text-brand-red text-sm font-semibold transition">

                        <i
                            data-lucide="trash-2"
                            class="w-4 h-4"></i>

                        Clear Cart

                    </button>

                </form>

            </div>


            <!-- CART + SUMMARY-->

            <div
                class="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:gap-8">


                <!-- CART ITEMS-->

                <div
                    class="lg:col-span-2 space-y-4">


                    <?php foreach ($cartProducts as $cartItem): ?>

                        <?php

                        $product = $cartItem['product'];

                        $productId = (int) $product['id'];

                        $productName = $product['name'];

                        $quantity = (int) $cartItem['quantity'];

                        $price = (float) $product['price'];

                        $lineTotal = (float) $cartItem['line_total'];

                        $available = $cartItem['available'];

                        $maxQuantity = (int) $cartItem['max_quantity'];

                        $imageUrl = cartProductImageUrl(
                            $product['image_url'] ?? null
                        );

                        ?>


                        <article
                            class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">


                            <div
                                class="p-4 sm:p-5 flex flex-col sm:flex-row gap-4">


                                <!-- Product Image -->

                                <a
                                    href="<?php echo APP_URL; ?>/product-details.php?id=<?php echo $productId; ?>"
                                    class="flex-shrink-0">

                                    <div
                                        class="w-full sm:w-28 h-28 rounded-xl bg-gray-100 overflow-hidden flex items-center justify-center">

                                        <?php if ($imageUrl): ?>

                                            <img
                                                src="<?php echo htmlspecialchars($imageUrl); ?>"
                                                alt="<?php echo htmlspecialchars($productName); ?>"
                                                class="w-full h-full object-cover"
                                                loading="lazy"
                                                onerror="this.style.display='none'; this.nextElementSibling.classList.remove('hidden');">

                                            <div
                                                class="hidden flex-col items-center justify-center text-gray-400">

                                                <i
                                                    data-lucide="image-off"
                                                    class="w-8 h-8"></i>

                                            </div>

                                        <?php else: ?>

                                            <div
                                                class="flex flex-col items-center justify-center text-gray-400">

                                                <i
                                                    data-lucide="package"
                                                    class="w-8 h-8"></i>

                                            </div>

                                        <?php endif; ?>

                                    </div>

                                </a>


                                <!-- Product Information -->

                                <div class="flex-1 min-w-0">

                                    <?php if (!empty($product['brand'])): ?>

                                        <p
                                            class="text-[10px] uppercase tracking-wider font-bold text-brand-red mb-1">

                                            <?php
                                            echo htmlspecialchars(
                                                $product['brand']
                                            );
                                            ?>

                                        </p>

                                    <?php endif; ?>


                                    <a
                                        href="<?php echo APP_URL; ?>/product-details.php?id=<?php echo $productId; ?>"
                                        class="block">

                                        <h3
                                            class="text-base sm:text-lg font-bold text-gray-900 hover:text-brand-red transition">

                                            <?php
                                            echo htmlspecialchars(
                                                $productName
                                            );
                                            ?>

                                        </h3>

                                    </a>


                                    <p
                                        class="text-xs text-gray-500 mt-1">

                                        <?php
                                        echo htmlspecialchars(
                                            $product['category']
                                        );
                                        ?>

                                    </p>


                                    <!-- Price -->

                                    <div
                                        class="mt-3 flex items-center gap-2">

                                        <span
                                            class="text-base font-bold text-gray-900">

                                            Rs.
                                            <?php
                                            echo number_format(
                                                $price,
                                                2
                                            );
                                            ?>

                                        </span>

                                        <span class="text-xs text-gray-400">
                                            each
                                        </span>

                                    </div>


                                    <!-- Stock Warning -->

                                    <?php if (!$available): ?>

                                        <div
                                            class="mt-3 inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-red-50 border border-red-100 text-red-600 text-xs font-semibold">

                                            <i
                                                data-lucide="package-x"
                                                class="w-3.5 h-3.5"></i>

                                            Currently out of stock

                                        </div>

                                    <?php elseif ($maxQuantity <= 5): ?>

                                        <div
                                            class="mt-3 inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-amber-50 border border-amber-100 text-amber-700 text-xs font-semibold">

                                            <i
                                                data-lucide="alert-triangle"
                                                class="w-3.5 h-3.5"></i>

                                            Only <?php echo $maxQuantity; ?> left

                                        </div>

                                    <?php endif; ?>


                                    <!-- Quantity + Remove -->

                                    <div
                                        class="mt-4 flex flex-wrap items-center gap-3">


                                        <?php if ($available): ?>

                                            <form
                                                method="POST"
                                                action="<?php echo APP_URL; ?>/cart.php"
                                                class="flex items-center gap-2">

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="update">

                                                <input
                                                    type="hidden"
                                                    name="product_id"
                                                    value="<?php echo $productId; ?>">


                                                <div
                                                    class="flex items-center border border-gray-200 rounded-xl overflow-hidden bg-gray-50">

                                                    <button
                                                        type="button"
                                                        class="quantity-minus w-9 h-9 flex items-center justify-center text-gray-500 hover:text-brand-red hover:bg-white transition"
                                                        data-target="quantity-<?php echo $productId; ?>">

                                                        <i
                                                            data-lucide="minus"
                                                            class="w-4 h-4"></i>

                                                    </button>


                                                    <input
                                                        type="number"
                                                        id="quantity-<?php echo $productId; ?>"
                                                        name="quantity"
                                                        value="<?php echo $quantity; ?>"
                                                        min="1"
                                                        max="<?php echo $maxQuantity; ?>"
                                                        class="w-12 h-9 text-center text-sm font-semibold bg-transparent border-x border-gray-200 outline-none">


                                                    <button
                                                        type="button"
                                                        class="quantity-plus w-9 h-9 flex items-center justify-center text-gray-500 hover:text-brand-red hover:bg-white transition"
                                                        data-target="quantity-<?php echo $productId; ?>">

                                                        <i
                                                            data-lucide="plus"
                                                            class="w-4 h-4"></i>

                                                    </button>

                                                </div>


                                                <button
                                                    type="submit"
                                                    class="px-3 py-2 rounded-lg bg-gray-100 hover:bg-brand-red hover:text-white text-gray-600 text-xs font-semibold transition">

                                                    Update

                                                </button>

                                            </form>

                                        <?php endif; ?>


                                        <!-- Remove -->

                                        <form
                                            method="POST"
                                            action="<?php echo APP_URL; ?>/cart.php">

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="remove">

                                            <input
                                                type="hidden"
                                                name="product_id"
                                                value="<?php echo $productId; ?>">

                                            <button
                                                type="submit"
                                                class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 text-xs font-semibold transition">

                                                <i
                                                    data-lucide="trash-2"
                                                    class="w-3.5 h-3.5"></i>

                                                Remove

                                            </button>

                                        </form>

                                    </div>

                                </div>


                                <!-- Line Total -->

                                <div
                                    class="sm:text-right flex sm:block items-center justify-between border-t sm:border-t-0 pt-4 sm:pt-0">

                                    <p
                                        class="text-xs text-gray-400 mb-1">

                                        Total

                                    </p>

                                    <p
                                        class="text-lg font-extrabold text-gray-900">

                                        Rs.
                                        <?php
                                        echo number_format(
                                            $lineTotal,
                                            2
                                        );
                                        ?>

                                    </p>

                                </div>

                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>


                <!-- ORDER SUMMARY -->

                <aside>

                    <div
                        class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5 sm:p-6 lg:sticky lg:top-24">


                        <h2
                            class="text-lg font-bold text-gray-900">

                            Order Summary

                        </h2>


                        <div
                            class="mt-5 space-y-4">


                            <!-- Items -->

                            <div
                                class="flex items-center justify-between text-sm">

                                <span class="text-gray-500">
                                    Items
                                </span>

                                <span
                                    class="font-semibold text-gray-900">

                                    <?php echo $cartCount; ?>

                                </span>

                            </div>


                            <!-- Subtotal -->

                            <div
                                class="flex items-center justify-between text-sm">

                                <span class="text-gray-500">
                                    Subtotal
                                </span>

                                <span
                                    class="font-semibold text-gray-900">

                                    Rs.
                                    <?php
                                    echo number_format(
                                        $subtotal,
                                        2
                                    );
                                    ?>

                                </span>

                            </div>


                            <!-- Delivery -->

                            <div
                                class="flex items-center justify-between text-sm">

                                <span class="text-gray-500">
                                    Delivery
                                </span>

                                <span
                                    class="font-semibold text-gray-900">

                                    Calculated at checkout

                                </span>

                            </div>


                            <div
                                class="border-t border-gray-200 pt-4">

                                <div
                                    class="flex items-center justify-between">

                                    <span
                                        class="text-base font-bold text-gray-900">

                                        Total

                                    </span>

                                    <span
                                        class="text-xl font-extrabold text-brand-red">

                                        Rs.
                                        <?php
                                        echo number_format(
                                            $subtotal,
                                            2
                                        );
                                        ?>

                                    </span>

                                </div>

                            </div>

                        </div>


                        <!-- Checkout -->

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

                            <a
                                href="<?php echo APP_URL; ?>/checkout.php"
                                class="mt-6 w-full h-12 inline-flex items-center justify-center gap-2 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-sm font-bold transition shadow-sm hover:shadow-md">

                                Proceed to Checkout

                                <i
                                    data-lucide="arrow-right"
                                    class="w-4 h-4"></i>

                            </a>

                        <?php else: ?>

                            <button
                                type="button"
                                disabled
                                class="mt-6 w-full h-12 inline-flex items-center justify-center gap-2 rounded-xl bg-gray-100 text-gray-400 text-sm font-bold cursor-not-allowed">

                                <i
                                    data-lucide="alert-circle"
                                    class="w-4 h-4"></i>

                                Update Cart to Continue

                            </button>

                        <?php endif; ?>


                        <!-- Continue Shopping -->

                        <a
                            href="<?php echo APP_URL; ?>/products.php"
                            class="mt-3 w-full h-11 inline-flex items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 text-sm font-semibold transition">

                            <i
                                data-lucide="arrow-left"
                                class="w-4 h-4"></i>

                            Continue Shopping

                        </a>


                        <!-- Trust Information -->

                        <div
                            class="mt-6 pt-5 border-t border-gray-100 space-y-3">


                            <div
                                class="flex items-start gap-3">

                                <div
                                    class="w-8 h-8 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0">

                                    <i
                                        data-lucide="shield-check"
                                        class="w-4 h-4 text-brand-red"></i>

                                </div>

                                <div>

                                    <p
                                        class="text-xs font-bold text-gray-900">

                                        Warranty Support

                                    </p>

                                    <p
                                        class="text-[11px] text-gray-500 mt-0.5">

                                        Warranty information is shown for eligible products.

                                    </p>

                                </div>

                            </div>


                            <div
                                class="flex items-start gap-3">

                                <div
                                    class="w-8 h-8 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0">

                                    <i
                                        data-lucide="package-check"
                                        class="w-4 h-4 text-brand-red"></i>

                                </div>

                                <div>

                                    <p
                                        class="text-xs font-bold text-gray-900">

                                        Stock Checked

                                    </p>

                                    <p
                                        class="text-[11px] text-gray-500 mt-0.5">

                                        Product availability is checked before checkout.

                                    </p>

                                </div>

                            </div>

                        </div>

                    </div>

                </aside>

            </div>


        <?php else: ?>


            <!-- EMPTY CART-->

            <div
                class="bg-white rounded-2xl border border-gray-200 shadow-sm py-16 px-6 text-center">


                <div
                    class="w-20 h-20 mx-auto rounded-2xl bg-gray-100 flex items-center justify-center mb-5">

                    <i
                        data-lucide="shopping-cart"
                        class="w-10 h-10 text-gray-400"></i>

                </div>


                <h2
                    class="text-2xl font-bold text-gray-900">

                    Your cart is empty

                </h2>


                <p
                    class="mt-2 max-w-md mx-auto text-sm text-gray-500 leading-relaxed">

                    You haven't added any products to your cart yet.
                    Browse our mobile accessories and products to get started.

                </p>


                <a
                    href="<?php echo APP_URL; ?>/products.php"
                    class="inline-flex items-center gap-2 mt-6 px-6 py-3 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-sm font-bold transition shadow-sm">

                    <i
                        data-lucide="shopping-bag"
                        class="w-4 h-4"></i>

                    Browse Products

                </a>

            </div>


        <?php endif; ?>


    </div>

</main>


<script>
    /* Lucide Icons*/

    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }


    /* Quantity Buttons*/

    document.querySelectorAll('.quantity-minus').forEach(function(button) {

        button.addEventListener('click', function() {

            const targetId = this.getAttribute('data-target');
            const input = document.getElementById(targetId);

            if (!input) {
                return;
            }

            const currentValue = parseInt(input.value || '1', 10);
            const minValue = parseInt(input.min || '1', 10);

            if (currentValue > minValue) {
                input.value = currentValue - 1;
            }

        });

    });


    document.querySelectorAll('.quantity-plus').forEach(function(button) {

        button.addEventListener('click', function() {

            const targetId = this.getAttribute('data-target');
            const input = document.getElementById(targetId);

            if (!input) {
                return;
            }

            const currentValue = parseInt(input.value || '1', 10);
            const maxValue = parseInt(input.max || '999', 10);

            if (currentValue < maxValue) {
                input.value = currentValue + 1;
            }

        });

    });


    /*Prevent Quantity Above Stock*/

    document.querySelectorAll('input[type="number"]').forEach(function(input) {

        input.addEventListener('change', function() {

            let value = parseInt(this.value || '1', 10);

            const min = parseInt(this.min || '1', 10);
            const max = parseInt(this.max || '999', 10);

            if (value < min) {
                value = min;
            }

            if (value > max) {
                value = max;
            }

            this.value = value;

        });

    });
</script>


<?php require_once __DIR__ . '/includes/footer.php'; ?>