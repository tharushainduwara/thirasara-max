<?php

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';


/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

if (!isLoggedIn()) {

    setFlash(
        'error',
        'Please sign in to continue with your order.'
    );

    header(
        'Location: ' .
        APP_URL .
        '/login.php?redirect=checkout.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Current User
|--------------------------------------------------------------------------
*/

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {

    setFlash(
        'error',
        'Your session has expired. Please sign in again.'
    );

    header(
        'Location: ' .
        APP_URL .
        '/login.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

$db = Database::getConnection();


/*
|--------------------------------------------------------------------------
| Load Current User
|--------------------------------------------------------------------------
*/

$userStmt = $db->prepare("
    SELECT
        id,
        name,
        email,
        phone,
        address
    FROM users
    WHERE id = ?
      AND status = 'active'
    LIMIT 1
");

$userStmt->execute([
    $userId
]);

$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {

    setFlash(
        'error',
        'Unable to load your account information.'
    );

    header(
        'Location: ' .
        APP_URL .
        '/login.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Load Session Cart
|--------------------------------------------------------------------------
*/

$sessionCart = $_SESSION['cart'] ?? [];

if (!is_array($sessionCart) || empty($sessionCart)) {

    setFlash(
        'error',
        'Your shopping cart is empty.'
    );

    header(
        'Location: ' .
        APP_URL .
        '/cart.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Extract Product IDs and Quantities
|--------------------------------------------------------------------------
*/

$productIds = [];

foreach ($sessionCart as $key => $item) {

    if (is_array($item)) {

        $productId = (int) (
            $item['product_id']
            ?? $item['id']
            ?? $key
        );

        $quantity = (int) (
            $item['quantity']
            ?? $item['qty']
            ?? 1
        );

    } else {

        $productId = (int) $key;
        $quantity = (int) $item;
    }


    if ($productId > 0 && $quantity > 0) {

        $productIds[$productId] = $quantity;
    }
}


/*
|--------------------------------------------------------------------------
| Validate Cart
|--------------------------------------------------------------------------
*/

if (empty($productIds)) {

    unset($_SESSION['cart']);

    setFlash(
        'error',
        'Your shopping cart is empty.'
    );

    header(
        'Location: ' .
        APP_URL .
        '/cart.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Load Products + Inventory
|--------------------------------------------------------------------------
*/

$placeholders = implode(
    ',',
    array_fill(
        0,
        count($productIds),
        '?'
    )
);

$productStmt = $db->prepare("
    SELECT
        p.id,
        p.name,
        p.brand,
        p.price,
        p.image_url,
        p.status,
        COALESCE(i.stock_quantity, 0) AS stock_quantity
    FROM products p
    LEFT JOIN inventory i
        ON i.product_id = p.id
    WHERE p.id IN ($placeholders)
");

$productStmt->execute(
    array_keys($productIds)
);

$productRows = $productStmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Prepare Checkout Items
|--------------------------------------------------------------------------
*/

$checkoutItems = [];

$subtotal = 0.00;

foreach ($productRows as $product) {

    $productId = (int) $product['id'];

    $requestedQuantity =
        (int) $productIds[$productId];

    $availableStock =
        (int) $product['stock_quantity'];


    /*
    |--------------------------------------------------------------------------
    | Product Status
    |--------------------------------------------------------------------------
    */

    if ($product['status'] !== 'available') {

        setFlash(
            'error',
            $product['name'] . ' is currently unavailable.'
        );

        header(
            'Location: ' .
            APP_URL .
            '/cart.php'
        );

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | Stock Check
    |--------------------------------------------------------------------------
    */

    if ($availableStock < $requestedQuantity) {

        setFlash(
            'error',
            $product['name'] .
            ' has only ' .
            $availableStock .
            ' item(s) available.'
        );

        header(
            'Location: ' .
            APP_URL .
            '/cart.php'
        );

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | Price
    |--------------------------------------------------------------------------
    */

    $unitPrice =
        (float) $product['price'];

    $itemSubtotal =
        $unitPrice *
        $requestedQuantity;

    $subtotal += $itemSubtotal;


    $checkoutItems[] = [

        'id' => $productId,

        'name' => $product['name'],

        'brand' => $product['brand'],

        'price' => $unitPrice,

        'quantity' => $requestedQuantity,

        'subtotal' => $itemSubtotal,

        'image_url' => $product['image_url'],

        'stock_quantity' => $availableStock

    ];
}


/*
|--------------------------------------------------------------------------
| Verify All Cart Products Were Found
|--------------------------------------------------------------------------
*/

if (
    count($checkoutItems) !==
    count($productIds)
) {

    setFlash(
        'error',
        'One or more products in your cart are no longer available.'
    );

    header(
        'Location: ' .
        APP_URL .
        '/cart.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Payment / Shipping Constants
|--------------------------------------------------------------------------
*/

$COD_FEE = 350.00;

$paymentMethods = [

    'cash' => [
        'label' => 'Cash on Delivery',
        'fee' => $COD_FEE
    ],

    'bank_transfer' => [
        'label' => 'Bank Transfer',
        'fee' => 0.00
    ],

    'stripe' => [
        'label' => 'Card Payment',
        'fee' => 0.00
    ],

    'paypal' => [
        'label' => 'PayPal',
        'fee' => 0.00
    ]

];


/*
|--------------------------------------------------------------------------
| Form Values
|--------------------------------------------------------------------------
*/

$shippingAddress = trim(
    $_POST['shipping_address']
    ?? ($user['address'] ?? '')
);

$contactPhone = trim(
    $_POST['contact_phone']
    ?? ($user['phone'] ?? '')
);

$notes = trim(
    $_POST['notes']
    ?? ''
);

$paymentMethod =
    $_POST['payment_method']
    ?? 'cash';


/*
|--------------------------------------------------------------------------
| Calculate Initial Total
|--------------------------------------------------------------------------
*/

$shipping = 0.00;

$paymentFee =
    $paymentMethods[$paymentMethod]['fee']
    ?? 0.00;

$totalAmount =
    $subtotal +
    $shipping +
    $paymentFee;


/*
|--------------------------------------------------------------------------
| CSRF Token
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['checkout_csrf'])) {

    $_SESSION['checkout_csrf'] =
        bin2hex(
            random_bytes(32)
        );
}

$csrfToken =
    $_SESSION['checkout_csrf'];


/*
|--------------------------------------------------------------------------
| Process Order
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
    |--------------------------------------------------------------------------
    | Validate CSRF
    |--------------------------------------------------------------------------
    */

    $submittedToken =
        $_POST['csrf_token']
        ?? '';

    if (
        empty($_SESSION['checkout_csrf']) ||
        !hash_equals(
            $_SESSION['checkout_csrf'],
            $submittedToken
        )
    ) {

        setFlash(
            'error',
            'Invalid checkout request. Please try again.'
        );

        header(
            'Location: ' .
            APP_URL .
            '/checkout.php'
        );

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | Validate Shipping Address
    |--------------------------------------------------------------------------
    */

    if (
        $shippingAddress === '' ||
        strlen($shippingAddress) < 5
    ) {

        setFlash(
            'error',
            'Please enter a valid delivery address.'
        );

        goto render_checkout;
    }


    /*
    |--------------------------------------------------------------------------
    | Validate Phone
    |--------------------------------------------------------------------------
    */

    $phoneDigits = preg_replace(
        '/[^0-9]/',
        '',
        $contactPhone
    );

    if (
        strlen($phoneDigits) < 9 ||
        strlen($phoneDigits) > 15
    ) {

        setFlash(
            'error',
            'Please enter a valid contact phone number.'
        );

        goto render_checkout;
    }


    /*
    |--------------------------------------------------------------------------
    | Validate Notes
    |--------------------------------------------------------------------------
    */

    if (strlen($notes) > 1000) {

        setFlash(
            'error',
            'Order notes cannot exceed 1000 characters.'
        );

        goto render_checkout;
    }


    /*
    |--------------------------------------------------------------------------
    | Validate Payment Method
    |--------------------------------------------------------------------------
    */

    if (
        !array_key_exists(
            $paymentMethod,
            $paymentMethods
        )
    ) {

        setFlash(
            'error',
            'Please select a valid payment method.'
        );

        goto render_checkout;
    }


    /*
    |--------------------------------------------------------------------------
    | Calculate Payment Fee
    |--------------------------------------------------------------------------
    */

    $paymentFee =
        (float) $paymentMethods[$paymentMethod]['fee'];

    $shipping = 0.00;


    /*
    |--------------------------------------------------------------------------
    | Transaction
    |--------------------------------------------------------------------------
    */

    try {

        $db->beginTransaction();


        /*
        |--------------------------------------------------------------------------
        | Re-check Product Stock
        |--------------------------------------------------------------------------
        */

        foreach ($checkoutItems as $index => $item) {

            /*
            | Lock inventory row through the inventory table.
            */

            $stockStmt = $db->prepare("
                SELECT
                    p.id,
                    p.name,
                    p.price,
                    p.status,
                    COALESCE(i.stock_quantity, 0) AS stock_quantity
                FROM products p
                LEFT JOIN inventory i
                    ON i.product_id = p.id
                WHERE p.id = ?
                LIMIT 1
                FOR UPDATE
            ");

            $stockStmt->execute([
                $item['id']
            ]);

            $currentProduct =
                $stockStmt->fetch(PDO::FETCH_ASSOC);


            if (!$currentProduct) {

                throw new Exception(
                    'A product in your cart could not be found.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Product Status
            |--------------------------------------------------------------------------
            */

            if (
                $currentProduct['status']
                !== 'available'
            ) {

                throw new Exception(
                    $currentProduct['name'] .
                    ' is no longer available.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Current Stock
            |--------------------------------------------------------------------------
            */

            $currentStock =
                (int) $currentProduct['stock_quantity'];


            if (
                $currentStock <
                $item['quantity']
            ) {

                throw new Exception(
                    'Not enough stock available for ' .
                    $currentProduct['name'] .
                    '. Only ' .
                    $currentStock .
                    ' item(s) remain.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Always Use Current Database Price
            |--------------------------------------------------------------------------
            */

            $currentPrice =
                (float) $currentProduct['price'];

            $currentSubtotal =
                $currentPrice *
                $item['quantity'];


            $checkoutItems[$index]['price'] =
                $currentPrice;

            $checkoutItems[$index]['subtotal'] =
                $currentSubtotal;

            $checkoutItems[$index]['stock_quantity'] =
                $currentStock;
        }


        /*
        |--------------------------------------------------------------------------
        | Recalculate Subtotal
        |--------------------------------------------------------------------------
        */

        $subtotal = 0.00;

        foreach ($checkoutItems as $item) {

            $subtotal +=
                (float) $item['subtotal'];
        }


        /*
        |--------------------------------------------------------------------------
        | Calculate Final Total
        |--------------------------------------------------------------------------
        |
        | COD = subtotal + Rs.350
        | Other methods = subtotal
        |
        */

        $paymentFee =
            (float) $paymentMethods[$paymentMethod]['fee'];

        $shipping = 0.00;

        $totalAmount =
            $subtotal +
            $shipping +
            $paymentFee;


        /*
        |--------------------------------------------------------------------------
        | Generate Unique Order Number
        |--------------------------------------------------------------------------
        */

        $orderNumber = '';

        $orderCreated = false;

        for (
            $attempt = 0;
            $attempt < 10;
            $attempt++
        ) {

            $candidate =
                'TM-' .
                date('Ymd') .
                '-' .
                strtoupper(
                    bin2hex(
                        random_bytes(3)
                    )
                );


            $checkOrderStmt = $db->prepare("
                SELECT id
                FROM orders
                WHERE order_number = ?
                LIMIT 1
            ");

            $checkOrderStmt->execute([
                $candidate
            ]);


            if (!$checkOrderStmt->fetch()) {

                $orderNumber =
                    $candidate;

                $orderCreated = true;

                break;
            }
        }


        if (!$orderCreated) {

            throw new Exception(
                'Unable to generate a unique order number.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Create Order
        |--------------------------------------------------------------------------
        */

        $orderStmt = $db->prepare("
            INSERT INTO orders
            (
                order_number,
                user_id,
                total_amount,
                payment_status,
                order_status,
                shipping_address,
                contact_phone,
                notes
            )
            VALUES
            (
                ?,
                ?,
                ?,
                'pending',
                'placed',
                ?,
                ?,
                ?
            )
        ");


        $orderStmt->execute([

            $orderNumber,

            $userId,

            number_format(
                $totalAmount,
                2,
                '.',
                ''
            ),

            $shippingAddress,

            $phoneDigits,

            $notes !== ''
                ? $notes
                : null

        ]);


        /*
        |--------------------------------------------------------------------------
        | Get Order ID
        |--------------------------------------------------------------------------
        */

        $orderId =
            (int) $db->lastInsertId();


        if ($orderId <= 0) {

            throw new Exception(
                'Unable to create your order.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Insert Order Items
        |--------------------------------------------------------------------------
        */

        $orderItemStmt = $db->prepare("
            INSERT INTO order_items
            (
                order_id,
                product_id,
                quantity,
                unit_price,
                subtotal
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?
            )
        ");


        foreach ($checkoutItems as $item) {

            $orderItemStmt->execute([

                $orderId,

                $item['id'],

                $item['quantity'],

                number_format(
                    $item['price'],
                    2,
                    '.',
                    ''
                ),

                number_format(
                    $item['subtotal'],
                    2,
                    '.',
                    ''
                )

            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | Reduce Inventory
        |--------------------------------------------------------------------------
        */

        $inventoryStmt = $db->prepare("
            UPDATE inventory
            SET
                stock_quantity =
                    stock_quantity - ?,
                updated_at =
                    CURRENT_TIMESTAMP
            WHERE product_id = ?
              AND stock_quantity >= ?
        ");


        foreach ($checkoutItems as $item) {

            $inventoryStmt->execute([

                $item['quantity'],

                $item['id'],

                $item['quantity']

            ]);


            if (
                $inventoryStmt->rowCount() !== 1
            ) {

                throw new Exception(
                    'Stock changed while placing your order. Please try again.'
                );
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Payment Record
        |--------------------------------------------------------------------------
        */

        $transactionId =
            'PENDING-' .
            $orderNumber;


        $paymentPayload = json_encode([

            'source' => 'checkout.php',

            'order_number' =>
                $orderNumber,

            'user_id' =>
                $userId,

            'payment_method' =>
                $paymentMethod,

            'subtotal' =>
                round($subtotal, 2),

            'delivery_fee' =>
                round($shipping, 2),

            'payment_fee' =>
                round($paymentFee, 2),

            'total_amount' =>
                round($totalAmount, 2),

            'created_at' =>
                date('c')

        ], JSON_UNESCAPED_SLASHES);


        $paymentStmt = $db->prepare("
            INSERT INTO payments
            (
                order_id,
                transaction_id,
                payment_method,
                amount,
                payment_status,
                payment_payload
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                'pending',
                ?
            )
        ");


        $paymentStmt->execute([

            $orderId,

            $transactionId,

            $paymentMethod,

            number_format(
                $totalAmount,
                2,
                '.',
                ''
            ),

            $paymentPayload

        ]);


        /*
        |--------------------------------------------------------------------------
        | Commit Transaction
        |--------------------------------------------------------------------------
        */

        $db->commit();


        /*
        |--------------------------------------------------------------------------
        | Clear Cart
        |--------------------------------------------------------------------------
        */

        unset(
            $_SESSION['cart']
        );

        unset(
            $_SESSION['checkout_csrf']
        );


        /*
        |--------------------------------------------------------------------------
        | Save Last Order
        |--------------------------------------------------------------------------
        */

        $_SESSION['last_order'] = [

            'id' =>
                $orderId,

            'order_number' =>
                $orderNumber,

            'total_amount' =>
                $totalAmount

        ];


        /*
        |--------------------------------------------------------------------------
        | Success Message
        |--------------------------------------------------------------------------
        */

        setFlash(
            'success',
            'Your order has been placed successfully.'
        );


        /*
        |--------------------------------------------------------------------------
        | Redirect
        |--------------------------------------------------------------------------
        */

        header(
            'Location: ' .
            APP_URL .
            '/customer/orders.php?order=' .
            urlencode($orderNumber)
        );

        exit;


    } catch (Throwable $e) {

        /*
        |--------------------------------------------------------------------------
        | Rollback
        |--------------------------------------------------------------------------
        */

        if ($db->inTransaction()) {

            $db->rollBack();
        }


        error_log(
            'Checkout Error: ' .
            $e->getMessage()
        );


        setFlash(
            'error',
            $e->getMessage()
        );


        goto render_checkout;
    }
}


/*
|--------------------------------------------------------------------------
| Render Checkout
|--------------------------------------------------------------------------
*/

render_checkout:

$pageTitle =
    'Checkout';

$currentUser =
    $user;

$cartCount =
    0;

foreach ($checkoutItems as $item) {

    $cartCount +=
        $item['quantity'];
}


/*
|--------------------------------------------------------------------------
| Recalculate Display Total
|--------------------------------------------------------------------------
*/

$paymentFee =
    $paymentMethods[$paymentMethod]['fee']
    ?? 0.00;

$shipping =
    0.00;

$totalAmount =
    $subtotal +
    $shipping +
    $paymentFee;

?>

<?php require_once __DIR__ . '/includes/header.php'; ?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Checkout |
        <?php echo htmlspecialchars(APP_NAME); ?>
    </title>

    <meta
        name="description"
        content="Complete your Thirasara Max Mobile order."
    >


    <!-- Google Fonts -->

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet"
    >


    <!-- Tailwind -->

    <script src="https://cdn.tailwindcss.com"></script>

    <script>

        tailwind.config = {

            theme: {

                extend: {

                    fontFamily: {

                        sans: [
                            'Inter',
                            'sans-serif'
                        ]

                    },

                    colors: {

                        brand: {

                            red: '#e52b34',

                            redHover: '#c4232c',

                            dark: '#0a0a0c',

                            card: '#121216',

                            border: '#1f1f28'

                        }

                    }

                }

            }

        };

    </script>


    <!-- Lucide -->

    <script src="https://unpkg.com/lucide@latest"></script>

</head>


<body
    class="
        bg-gray-50
        text-gray-800
        flex
        flex-col
        min-h-screen
        font-sans
        antialiased
    "
>


<main
    class="
        flex-1
        py-8
        sm:py-10
        lg:py-14
    "
>

    <div
        class="
            max-w-7xl
            mx-auto
            px-4
            sm:px-6
            lg:px-8
        "
    >


        <!-- PAGE HEADER -->

        <div class="mb-8">

            <div
                class="
                    flex
                    items-center
                    gap-2
                    text-xs
                    font-semibold
                    uppercase
                    tracking-wider
                    text-brand-red
                    mb-3
                "
            >

                <span
                    class="
                        w-1.5
                        h-1.5
                        rounded-full
                        bg-brand-red
                    "
                ></span>

                Secure Checkout

            </div>


            <h1
                class="
                    text-3xl
                    sm:text-4xl
                    font-extrabold
                    tracking-tight
                    text-gray-900
                "
            >
                Complete Your Order
            </h1>


            <p
                class="
                    mt-2
                    text-sm
                    text-gray-500
                "
            >
                Review your items and enter your delivery details.
            </p>

        </div>


        <!-- FLASH MESSAGE -->

        <?php if (function_exists('displayFlash')): ?>

            <div class="mb-6">

                <?php displayFlash(); ?>

            </div>

        <?php endif; ?>


        <!-- CHECKOUT FORM -->

        <form
            id="checkout-form"
            method="POST"
            action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?php echo htmlspecialchars($csrfToken); ?>"
            >


            <div
                class="
                    grid
                    grid-cols-1
                    lg:grid-cols-12
                    gap-6
                    lg:gap-8
                "
            >


                <!-- LEFT -->

                <div
                    class="
                        lg:col-span-7
                        space-y-6
                    "
                >


                    <!-- DELIVERY CARD -->

                    <div
                        class="
                            bg-white
                            border
                            border-gray-200
                            rounded-2xl
                            shadow-sm
                            overflow-hidden
                        "
                    >

                        <div
                            class="
                                px-5
                                sm:px-6
                                py-5
                                border-b
                                border-gray-100
                                flex
                                items-center
                                gap-3
                            "
                        >

                            <div
                                class="
                                    w-10
                                    h-10
                                    rounded-xl
                                    bg-red-50
                                    text-brand-red
                                    flex
                                    items-center
                                    justify-center
                                "
                            >

                                <i
                                    data-lucide="map-pin"
                                    class="w-5 h-5"
                                ></i>

                            </div>


                            <div>

                                <h2
                                    class="
                                        text-lg
                                        font-bold
                                        text-gray-900
                                    "
                                >
                                    Delivery Information
                                </h2>

                                <p
                                    class="
                                        text-xs
                                        text-gray-500
                                        mt-0.5
                                    "
                                >
                                    Where should we deliver your order?
                                </p>

                            </div>

                        </div>


                        <div class="p-5 sm:p-6">

                            <div class="space-y-5">


                                <!-- NAME -->

                                <div>

                                    <label
                                        class="
                                            block
                                            text-sm
                                            font-semibold
                                            text-gray-700
                                            mb-2
                                        "
                                    >
                                        Full Name
                                    </label>

                                    <input
                                        type="text"
                                        value="<?php echo htmlspecialchars($user['name']); ?>"
                                        disabled
                                        class="
                                            w-full
                                            rounded-xl
                                            border
                                            border-gray-200
                                            bg-gray-50
                                            px-4
                                            py-3
                                            text-sm
                                            text-gray-600
                                        "
                                    >

                                </div>


                                <!-- EMAIL -->

                                <div>

                                    <label
                                        class="
                                            block
                                            text-sm
                                            font-semibold
                                            text-gray-700
                                            mb-2
                                        "
                                    >
                                        Email Address
                                    </label>

                                    <input
                                        type="email"
                                        value="<?php echo htmlspecialchars($user['email']); ?>"
                                        disabled
                                        class="
                                            w-full
                                            rounded-xl
                                            border
                                            border-gray-200
                                            bg-gray-50
                                            px-4
                                            py-3
                                            text-sm
                                            text-gray-600
                                        "
                                    >

                                </div>


                                <!-- PHONE -->

                                <div>

                                    <label
                                        for="contact_phone"
                                        class="
                                            block
                                            text-sm
                                            font-semibold
                                            text-gray-700
                                            mb-2
                                        "
                                    >
                                        Contact Phone

                                        <span class="text-brand-red">
                                            *
                                        </span>

                                    </label>

                                    <input
                                        type="tel"
                                        id="contact_phone"
                                        name="contact_phone"
                                        value="<?php echo htmlspecialchars($contactPhone); ?>"
                                        required
                                        maxlength="20"
                                        autocomplete="tel"
                                        placeholder="07XXXXXXXX"
                                        class="
                                            w-full
                                            rounded-xl
                                            border
                                            border-gray-300
                                            bg-white
                                            px-4
                                            py-3
                                            text-sm
                                            text-gray-900
                                            outline-none
                                            transition
                                            focus:border-brand-red
                                            focus:ring-2
                                            focus:ring-red-100
                                        "
                                    >

                                </div>


                                <!-- ADDRESS -->

                                <div>

                                    <label
                                        for="shipping_address"
                                        class="
                                            block
                                            text-sm
                                            font-semibold
                                            text-gray-700
                                            mb-2
                                        "
                                    >
                                        Delivery Address

                                        <span class="text-brand-red">
                                            *
                                        </span>

                                    </label>

                                    <textarea
                                        id="shipping_address"
                                        name="shipping_address"
                                        rows="4"
                                        required
                                        minlength="5"
                                        maxlength="1000"
                                        autocomplete="street-address"
                                        placeholder="Enter your complete delivery address"
                                        class="
                                            w-full
                                            rounded-xl
                                            border
                                            border-gray-300
                                            bg-white
                                            px-4
                                            py-3
                                            text-sm
                                            text-gray-900
                                            outline-none
                                            resize-none
                                            transition
                                            focus:border-brand-red
                                            focus:ring-2
                                            focus:ring-red-100
                                        "
                                    ><?php echo htmlspecialchars($shippingAddress); ?></textarea>

                                </div>


                                <!-- NOTES -->

                                <div>

                                    <label
                                        for="notes"
                                        class="
                                            block
                                            text-sm
                                            font-semibold
                                            text-gray-700
                                            mb-2
                                        "
                                    >
                                        Order Notes

                                        <span
                                            class="
                                                font-normal
                                                text-gray-400
                                            "
                                        >
                                            (Optional)
                                        </span>

                                    </label>

                                    <textarea
                                        id="notes"
                                        name="notes"
                                        rows="3"
                                        maxlength="1000"
                                        placeholder="Any special delivery instructions?"
                                        class="
                                            w-full
                                            rounded-xl
                                            border
                                            border-gray-300
                                            bg-white
                                            px-4
                                            py-3
                                            text-sm
                                            text-gray-900
                                            outline-none
                                            resize-none
                                            transition
                                            focus:border-brand-red
                                            focus:ring-2
                                            focus:ring-red-100
                                        "
                                    ><?php echo htmlspecialchars($notes); ?></textarea>

                                </div>

                            </div>

                        </div>

                    </div>


                    <!-- PAYMENT CARD -->

                    <div
                        class="
                            bg-white
                            border
                            border-gray-200
                            rounded-2xl
                            shadow-sm
                            overflow-hidden
                        "
                    >

                        <div
                            class="
                                px-5
                                sm:px-6
                                py-5
                                border-b
                                border-gray-100
                                flex
                                items-center
                                gap-3
                            "
                        >

                            <div
                                class="
                                    w-10
                                    h-10
                                    rounded-xl
                                    bg-red-50
                                    text-brand-red
                                    flex
                                    items-center
                                    justify-center
                                "
                            >

                                <i
                                    data-lucide="credit-card"
                                    class="w-5 h-5"
                                ></i>

                            </div>


                            <div>

                                <h2
                                    class="
                                        text-lg
                                        font-bold
                                        text-gray-900
                                    "
                                >
                                    Payment Method
                                </h2>

                                <p
                                    class="
                                        text-xs
                                        text-gray-500
                                        mt-0.5
                                    "
                                >
                                    Select how you would like to pay.
                                </p>

                            </div>

                        </div>


                        <div class="p-5 sm:p-6">

                            <div class="space-y-3">


                                <!-- CASH ON DELIVERY -->

                                <label
                                    class="
                                        payment-option
                                        flex
                                        items-center
                                        gap-4
                                        p-4
                                        rounded-xl
                                        border
                                        cursor-pointer
                                        transition
                                        hover:border-brand-red
                                        hover:bg-red-50
                                    "
                                >

                                    <input
                                        type="radio"
                                        name="payment_method"
                                        value="cash"
                                        <?php
                                        echo $paymentMethod === 'cash'
                                            ? 'checked'
                                            : '';
                                        ?>
                                        class="
                                            w-4
                                            h-4
                                            accent-brand-red
                                        "
                                    >


                                    <div
                                        class="
                                            w-10
                                            h-10
                                            rounded-lg
                                            bg-gray-100
                                            flex
                                            items-center
                                            justify-center
                                            text-gray-700
                                        "
                                    >

                                        <i
                                            data-lucide="banknote"
                                            class="w-5 h-5"
                                        ></i>

                                    </div>


                                    <div class="flex-1">

                                        <div
                                            class="
                                                font-semibold
                                                text-gray-900
                                            "
                                        >
                                            Cash on Delivery
                                        </div>

                                        <div
                                            class="
                                                text-xs
                                                text-gray-500
                                                mt-1
                                            "
                                        >
                                            Pay when your order is delivered.
                                        </div>

                                    </div>


                                    <div
                                        class="
                                            text-sm
                                            font-bold
                                            text-brand-red
                                        "
                                    >
                                        + Rs. 350
                                    </div>

                                </label>


                                <!-- BANK TRANSFER -->

                                <label
                                    class="
                                        payment-option
                                        flex
                                        items-center
                                        gap-4
                                        p-4
                                        rounded-xl
                                        border
                                        cursor-pointer
                                        transition
                                        hover:border-brand-red
                                        hover:bg-red-50
                                    "
                                >

                                    <input
                                        type="radio"
                                        name="payment_method"
                                        value="bank_transfer"
                                        <?php
                                        echo $paymentMethod === 'bank_transfer'
                                            ? 'checked'
                                            : '';
                                        ?>
                                        class="
                                            w-4
                                            h-4
                                            accent-brand-red
                                        "
                                    >


                                    <div
                                        class="
                                            w-10
                                            h-10
                                            rounded-lg
                                            bg-gray-100
                                            flex
                                            items-center
                                            justify-center
                                            text-gray-700
                                        "
                                    >

                                        <i
                                            data-lucide="building-2"
                                            class="w-5 h-5"
                                        ></i>

                                    </div>


                                    <div class="flex-1">

                                        <div
                                            class="
                                                font-semibold
                                                text-gray-900
                                            "
                                        >
                                            Bank Transfer
                                        </div>

                                        <div
                                            class="
                                                text-xs
                                                text-gray-500
                                                mt-1
                                            "
                                        >
                                            Transfer the payment using our bank details.
                                        </div>

                                    </div>

                                </label>


                                <!-- STRIPE -->

                                <label
                                    class="
                                        payment-option
                                        flex
                                        items-center
                                        gap-4
                                        p-4
                                        rounded-xl
                                        border
                                        cursor-pointer
                                        transition
                                        hover:border-brand-red
                                        hover:bg-red-50
                                    "
                                >

                                    <input
                                        type="radio"
                                        name="payment_method"
                                        value="stripe"
                                        <?php
                                        echo $paymentMethod === 'stripe'
                                            ? 'checked'
                                            : '';
                                        ?>
                                        class="
                                            w-4
                                            h-4
                                            accent-brand-red
                                        "
                                    >


                                    <div
                                        class="
                                            w-10
                                            h-10
                                            rounded-lg
                                            bg-gray-100
                                            flex
                                            items-center
                                            justify-center
                                            text-gray-700
                                        "
                                    >

                                        <i
                                            data-lucide="credit-card"
                                            class="w-5 h-5"
                                        ></i>

                                    </div>


                                    <div class="flex-1">

                                        <div
                                            class="
                                                font-semibold
                                                text-gray-900
                                            "
                                        >
                                            Card Payment
                                        </div>

                                        <div
                                            class="
                                                text-xs
                                                text-gray-500
                                                mt-1
                                            "
                                        >
                                            Secure online payment via Stripe.
                                        </div>

                                    </div>

                                </label>


                                <!-- PAYPAL -->

                                <label
                                    class="
                                        payment-option
                                        flex
                                        items-center
                                        gap-4
                                        p-4
                                        rounded-xl
                                        border
                                        cursor-pointer
                                        transition
                                        hover:border-brand-red
                                        hover:bg-red-50
                                    "
                                >

                                    <input
                                        type="radio"
                                        name="payment_method"
                                        value="paypal"
                                        <?php
                                        echo $paymentMethod === 'paypal'
                                            ? 'checked'
                                            : '';
                                        ?>
                                        class="
                                            w-4
                                            h-4
                                            accent-brand-red
                                        "
                                    >


                                    <div
                                        class="
                                            w-10
                                            h-10
                                            rounded-lg
                                            bg-gray-100
                                            flex
                                            items-center
                                            justify-center
                                            text-gray-700
                                        "
                                    >

                                        <i
                                            data-lucide="wallet"
                                            class="w-5 h-5"
                                        ></i>

                                    </div>


                                    <div class="flex-1">

                                        <div
                                            class="
                                                font-semibold
                                                text-gray-900
                                            "
                                        >
                                            PayPal
                                        </div>

                                        <div
                                            class="
                                                text-xs
                                                text-gray-500
                                                mt-1
                                            "
                                        >
                                            Pay securely using your PayPal account.
                                        </div>

                                    </div>

                                </label>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- RIGHT -->

                <div
                    class="
                        lg:col-span-5
                    "
                >

                    <div
                        class="
                            bg-white
                            border
                            border-gray-200
                            rounded-2xl
                            shadow-sm
                            overflow-hidden
                            lg:sticky
                            lg:top-28
                        "
                    >


                        <!-- SUMMARY HEADER -->

                        <div
                            class="
                                px-5
                                sm:px-6
                                py-5
                                border-b
                                border-gray-100
                            "
                        >

                            <h2
                                class="
                                    text-lg
                                    font-bold
                                    text-gray-900
                                "
                            >
                                Order Summary
                            </h2>

                            <p
                                class="
                                    text-xs
                                    text-gray-500
                                    mt-1
                                "
                            >

                                <?php echo $cartCount; ?>

                                item<?php echo $cartCount !== 1 ? 's' : ''; ?>

                                in your order

                            </p>

                        </div>


                        <!-- ITEMS -->

                        <div
                            class="
                                p-5
                                sm:p-6
                                space-y-4
                                max-h-[420px]
                                overflow-y-auto
                            "
                        >

                            <?php foreach ($checkoutItems as $item): ?>

                                <div
                                    class="
                                        flex
                                        gap-3
                                    "
                                >

                                    <div
                                        class="
                                            w-16
                                            h-16
                                            sm:w-20
                                            sm:h-20
                                            rounded-xl
                                            bg-gray-100
                                            border
                                            border-gray-100
                                            flex
                                            items-center
                                            justify-center
                                            overflow-hidden
                                            flex-shrink-0
                                        "
                                    >

                                        <?php if (!empty($item['image_url'])): ?>

                                            <img
                                                src="<?php
                                                echo APP_URL .
                                                    '/' .
                                                    ltrim(
                                                        $item['image_url'],
                                                        '/'
                                                    );
                                                ?>"
                                                alt="<?php echo htmlspecialchars($item['name']); ?>"
                                                class="
                                                    w-full
                                                    h-full
                                                    object-contain
                                                "
                                                onerror="
                                                    this.style.display='none';
                                                    this.nextElementSibling.style.display='flex';
                                                "
                                            >

                                            <div
                                                class="
                                                    hidden
                                                    w-full
                                                    h-full
                                                    items-center
                                                    justify-center
                                                    text-gray-400
                                                "
                                            >

                                                <i
                                                    data-lucide="image-off"
                                                    class="w-6 h-6"
                                                ></i>

                                            </div>

                                        <?php else: ?>

                                            <i
                                                data-lucide="package"
                                                class="
                                                    w-6
                                                    h-6
                                                    text-gray-400
                                                "
                                            ></i>

                                        <?php endif; ?>

                                    </div>


                                    <div
                                        class="
                                            min-w-0
                                            flex-1
                                        "
                                    >

                                        <h3
                                            class="
                                                text-sm
                                                font-semibold
                                                text-gray-900
                                                line-clamp-2
                                            "
                                        >
                                            <?php
                                            echo htmlspecialchars(
                                                $item['name']
                                            );
                                            ?>
                                        </h3>


                                        <?php if (!empty($item['brand'])): ?>

                                            <p
                                                class="
                                                    text-xs
                                                    text-gray-500
                                                    mt-1
                                                "
                                            >
                                                <?php
                                                echo htmlspecialchars(
                                                    $item['brand']
                                                );
                                                ?>
                                            </p>

                                        <?php endif; ?>


                                        <div
                                            class="
                                                flex
                                                items-center
                                                justify-between
                                                gap-2
                                                mt-2
                                            "
                                        >

                                            <span
                                                class="
                                                    text-xs
                                                    text-gray-500
                                                "
                                            >
                                                Qty:
                                                <?php
                                                echo $item['quantity'];
                                                ?>
                                            </span>


                                            <span
                                                class="
                                                    text-sm
                                                    font-bold
                                                    text-gray-900
                                                "
                                            >
                                                Rs.
                                                <?php
                                                echo number_format(
                                                    $item['subtotal'],
                                                    2
                                                );
                                                ?>
                                            </span>

                                        </div>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        </div>


                        <!-- TOTAL -->

                        <div
                            class="
                                border-t
                                border-gray-100
                                p-5
                                sm:p-6
                            "
                        >

                            <div
                                class="
                                    space-y-3
                                    text-sm
                                "
                            >


                                <!-- SUBTOTAL -->

                                <div
                                    class="
                                        flex
                                        justify-between
                                        text-gray-600
                                    "
                                >

                                    <span>
                                        Subtotal
                                    </span>

                                    <span
                                        id="subtotal-display"
                                        class="
                                            font-medium
                                            text-gray-900
                                        "
                                    >
                                        Rs.
                                        <?php
                                        echo number_format(
                                            $subtotal,
                                            2
                                        );
                                        ?>
                                    </span>

                                </div>


                                <!-- COD FEE -->

                                <div
                                    id="cod-fee-row"
                                    class="
                                        flex
                                        justify-between
                                        text-gray-600
                                        <?php
                                        echo $paymentMethod === 'cash'
                                            ? ''
                                            : 'hidden';
                                        ?>
                                    "
                                >

                                    <span>
                                        Cash on Delivery Fee
                                    </span>

                                    <span
                                        class="
                                            font-medium
                                            text-gray-900
                                        "
                                    >
                                        Rs. 350.00
                                    </span>

                                </div>

                            </div>


                            <!-- FINAL TOTAL -->

                            <div
                                class="
                                    mt-5
                                    pt-5
                                    border-t
                                    border-gray-100
                                "
                            >

                                <div
                                    class="
                                        flex
                                        items-end
                                        justify-between
                                        gap-4
                                    "
                                >

                                    <div>

                                        <p
                                            class="
                                                text-xs
                                                text-gray-500
                                            "
                                        >
                                            Total Amount
                                        </p>

                                        <p
                                            id="total-display"
                                            class="
                                                mt-1
                                                text-2xl
                                                sm:text-3xl
                                                font-extrabold
                                                text-gray-900
                                            "
                                        >
                                            Rs.
                                            <?php
                                            echo number_format(
                                                $totalAmount,
                                                2
                                            );
                                            ?>
                                        </p>

                                    </div>


                                    <div
                                        class="
                                            flex
                                            items-center
                                            gap-1.5
                                            text-xs
                                            text-green-600
                                            font-medium
                                        "
                                    >

                                        <i
                                            data-lucide="shield-check"
                                            class="w-4 h-4"
                                        ></i>

                                        Secure

                                    </div>

                                </div>

                            </div>


                            <!-- PLACE ORDER -->

                            <button
                                type="submit"
                                id="place-order-button"
                                class="
                                    mt-6
                                    w-full
                                    inline-flex
                                    items-center
                                    justify-center
                                    gap-2
                                    rounded-xl
                                    bg-brand-red
                                    px-5
                                    py-3.5
                                    text-sm
                                    font-bold
                                    text-white
                                    shadow-sm
                                    transition
                                    hover:bg-brand-redHover
                                    focus:outline-none
                                    focus:ring-2
                                    focus:ring-brand-red
                                    focus:ring-offset-2
                                    disabled:opacity-60
                                    disabled:cursor-not-allowed
                                "
                            >

                                <i
                                    data-lucide="shopping-bag"
                                    class="w-4 h-4"
                                ></i>

                                Place Order

                            </button>


                            <!-- BACK TO CART -->

                            <a
                                href="<?php echo APP_URL; ?>/cart.php"
                                class="
                                    mt-3
                                    w-full
                                    inline-flex
                                    items-center
                                    justify-center
                                    gap-2
                                    rounded-xl
                                    border
                                    border-gray-200
                                    bg-white
                                    px-5
                                    py-3
                                    text-sm
                                    font-semibold
                                    text-gray-700
                                    transition
                                    hover:bg-gray-50
                                "
                            >

                                <i
                                    data-lucide="arrow-left"
                                    class="w-4 h-4"
                                ></i>

                                Back to Cart

                            </a>


                            <!-- SECURITY -->

                            <div
                                class="
                                    mt-5
                                    flex
                                    items-start
                                    gap-2
                                    text-[11px]
                                    leading-5
                                    text-gray-400
                                "
                            >

                                <i
                                    data-lucide="lock"
                                    class="
                                        w-3.5
                                        h-3.5
                                        mt-0.5
                                        flex-shrink-0
                                    "
                                ></i>

                                <span>
                                    Your order information is securely
                                    processed by Thirasara Max Mobile.
                                </span>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </form>

    </div>

</main>


<script>

/*
|--------------------------------------------------------------------------
| Lucide Icons
|--------------------------------------------------------------------------
*/

if (typeof lucide !== 'undefined') {

    lucide.createIcons();

}


/*
|--------------------------------------------------------------------------
| Payment Options
|--------------------------------------------------------------------------
*/

const paymentOptions =
    document.querySelectorAll(
        '.payment-option'
    );

const paymentInputs =
    document.querySelectorAll(
        'input[name="payment_method"]'
    );


/*
|--------------------------------------------------------------------------
| Pricing
|--------------------------------------------------------------------------
*/

const subtotal =
    <?php echo json_encode(round($subtotal, 2)); ?>;

const codFee =
    350.00;

const totalDisplay =
    document.getElementById(
        'total-display'
    );

const codFeeRow =
    document.getElementById(
        'cod-fee-row'
    );


/*
|--------------------------------------------------------------------------
| Update Payment Styles
|--------------------------------------------------------------------------
*/

function updatePaymentStyles() {

    paymentOptions.forEach(
        function(option) {

            const radio =
                option.querySelector(
                    'input[type="radio"]'
                );

            if (
                radio &&
                radio.checked
            ) {

                option.classList.add(
                    'border-brand-red',
                    'bg-red-50'
                );

                option.classList.remove(
                    'border-gray-200'
                );

            } else {

                option.classList.remove(
                    'border-brand-red',
                    'bg-red-50'
                );

                option.classList.add(
                    'border-gray-200'
                );
            }

        }
    );
}


/*
|--------------------------------------------------------------------------
| Update Total
|--------------------------------------------------------------------------
*/

function updateTotal() {

    const selectedPayment =
        document.querySelector(
            'input[name="payment_method"]:checked'
        );

    let fee = 0;

    if (
        selectedPayment &&
        selectedPayment.value === 'cash'
    ) {

        fee = codFee;

        codFeeRow.classList.remove(
            'hidden'
        );

    } else {

        fee = 0;

        codFeeRow.classList.add(
            'hidden'
        );
    }


    const total =
        subtotal + fee;


    totalDisplay.textContent =
        'Rs. ' +
        total.toLocaleString(
            'en-LK',
            {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }
        );


    updatePaymentStyles();
}


/*
|--------------------------------------------------------------------------
| Payment Change Events
|--------------------------------------------------------------------------
*/

paymentInputs.forEach(
    function(input) {

        input.addEventListener(
            'change',
            updateTotal
        );

    }
);


/*
|--------------------------------------------------------------------------
| Initial State
|--------------------------------------------------------------------------
*/

updateTotal();


/*
|--------------------------------------------------------------------------
| Prevent Double Submission
|--------------------------------------------------------------------------
*/

const checkoutForm =
    document.getElementById(
        'checkout-form'
    );

const placeOrderButton =
    document.getElementById(
        'place-order-button'
    );


if (
    checkoutForm &&
    placeOrderButton
) {

    checkoutForm.addEventListener(
        'submit',
        function() {

            placeOrderButton.disabled =
                true;

            placeOrderButton.innerHTML = `
                <svg
                    class="animate-spin w-4 h-4"
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                >

                    <circle
                        class="opacity-25"
                        cx="12"
                        cy="12"
                        r="10"
                        stroke="currentColor"
                        stroke-width="4"
                    ></circle>

                    <path
                        class="opacity-75"
                        fill="currentColor"
                        d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"
                    ></path>

                </svg>

                Placing Order...
            `;

        }
    );

}

</script>


<?php require_once __DIR__ . '/includes/footer.php'; ?>

</body>

</html>