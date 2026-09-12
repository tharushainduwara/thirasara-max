<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

/*Authentication*/

if (!isLoggedIn()) {

    setFlash(
        'error',
        'Please sign in to view your orders.'
    );

    header(
        'Location: ' .
            APP_URL .
            '/login.php?redirect=customer/orders.php'
    );

    exit;
}


/*Current User*/

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


/*CSRF Token Used to protect the order cancellation form (and any other state-changing form on this page) from CSRF attacks.*/

if (empty($_SESSION['csrf_token'])) {

    $_SESSION['csrf_token'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION['csrf_token'];


/* Database*/

$db = Database::getConnection();


/* Current User*/

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


/* Cart Count*/

$cartCount = 0;

if (
    isset($_SESSION['cart']) &&
    is_array($_SESSION['cart'])
) {

    foreach ($_SESSION['cart'] as $cartItem) {

        if (is_array($cartItem)) {

            $cartCount += (int) (
                $cartItem['quantity']
                ?? $cartItem['qty']
                ?? 0
            );
        } else {

            $cartCount += (int) $cartItem;
        }
    }
}


/*Selected Order*/

$selectedOrderNumber =
    trim(
        $_GET['order']
            ?? ''
    );

$selectedOrder = null;

$selectedOrderItems = [];

$selectedPayment = null;


/* Load Selected Order*/

if ($selectedOrderNumber !== '') {

    $orderStmt = $db->prepare("
        SELECT
            o.id,
            o.order_number,
            o.user_id,
            o.total_amount,
            o.payment_status,
            o.order_status,
            o.shipping_address,
            o.contact_phone,
            o.notes,
            o.created_at,
            o.updated_at
        FROM orders o
        WHERE o.order_number = ?
        AND o.user_id = ?
        LIMIT 1
    ");

    $orderStmt->execute([
        $selectedOrderNumber,
        $userId
    ]);

    $selectedOrder =
        $orderStmt->fetch(
            PDO::FETCH_ASSOC
        );


    /* Order Not Found */

    if (!$selectedOrder) {

        setFlash(
            'error',
            'The requested order could not be found.'
        );

        header(
            'Location: ' .
                APP_URL .
                '/customer/orders.php'
        );

        exit;
    }


    /* Load Order Items*/

    $itemsStmt = $db->prepare("
        SELECT
            oi.id,
            oi.product_id,
            oi.quantity,
            oi.unit_price,
            oi.subtotal,
            p.name,
            p.brand,
            p.image_url,
            p.category
        FROM order_items oi
        INNER JOIN products p
            ON p.id = oi.product_id
        WHERE oi.order_id = ?
        ORDER BY oi.id ASC
    ");

    $itemsStmt->execute([
        $selectedOrder['id']
    ]);

    $selectedOrderItems =
        $itemsStmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    /* Load Payment */

    $paymentStmt = $db->prepare("
        SELECT
            id,
            transaction_id,
            payment_method,
            amount,
            payment_status,
            payment_date
        FROM payments
        WHERE order_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");

    $paymentStmt->execute([
        $selectedOrder['id']
    ]);

    $selectedPayment =
        $paymentStmt->fetch(
            PDO::FETCH_ASSOC
        );
}


/* Load All Customer Orders*/

$ordersStmt = $db->prepare("
    SELECT
        o.id,
        o.order_number,
        o.total_amount,
        o.payment_status,
        o.order_status,
        o.shipping_address,
        o.contact_phone,
        o.created_at,
        o.updated_at,

        (
            SELECT COUNT(*)
            FROM order_items oi
            WHERE oi.order_id = o.id
        ) AS item_count

    FROM orders o

    WHERE o.user_id = ?

    ORDER BY o.created_at DESC
");

$ordersStmt->execute([
    $userId
]);

$orders =
    $ordersStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/* Order Statistics*/

$totalOrders =
    count($orders);

$activeOrders = 0;

$completedOrders = 0;

$cancelledOrders = 0;

foreach ($orders as $order) {

    if (
        in_array(
            $order['order_status'],
            [
                'placed',
                'processing',
                'shipped'
            ],
            true
        )
    ) {

        $activeOrders++;
    }

    if (
        $order['order_status'] ===
        'delivered'
    ) {

        $completedOrders++;
    }

    if (
        $order['order_status'] ===
        'cancelled'
    ) {

        $cancelledOrders++;
    }
}


/* Page Settings*/

$pageTitle =
    $selectedOrder
    ? 'Order ' . $selectedOrder['order_number']
    : 'My Orders';

$currentUser =
    $user;

$currentScript =
    basename($_SERVER['PHP_SELF']);


/* Helper Functions*/

function orderStatusClass(
    string $status
): string {

    return match ($status) {

        'placed' =>
        'bg-blue-50 text-blue-700 border-blue-200',

        'processing' =>
        'bg-amber-50 text-amber-700 border-amber-200',

        'shipped' =>
        'bg-purple-50 text-purple-700 border-purple-200',

        'delivered' =>
        'bg-green-50 text-green-700 border-green-200',

        'cancelled' =>
        'bg-red-50 text-red-700 border-red-200',

        default =>
        'bg-gray-50 text-gray-700 border-gray-200'
    };
}


function paymentStatusClass(
    string $status
): string {

    return match ($status) {

        'paid',
        'success' =>
        'bg-green-50 text-green-700 border-green-200',

        'pending' =>
        'bg-amber-50 text-amber-700 border-amber-200',

        'failed' =>
        'bg-red-50 text-red-700 border-red-200',

        'refunded' =>
        'bg-purple-50 text-purple-700 border-purple-200',

        default =>
        'bg-gray-50 text-gray-700 border-gray-200'
    };
}


function formatOrderStatus(
    string $status
): string {

    return ucwords(
        str_replace(
            '_',
            ' ',
            $status
        )
    );
}


function formatPaymentMethod(
    string $method
): string {

    return match ($method) {

        'cash' =>
        'Cash on Delivery',

        'bank_transfer' =>
        'Bank Transfer',

        'stripe' =>
        'Card Payment',

        'paypal' =>
        'PayPal',

        default =>
        ucwords(
            str_replace(
                '_',
                ' ',
                $method
            )
        )
    };
}


/* Cancellation Eligibility*/

function isOrderCancellable(
    string $status
): bool {

    return in_array(
        $status,
        [
            'placed',
            'processing'
        ],
        true
    );
}

?>

<?php require_once __DIR__ . '/../includes/header.php'; ?>


<!-- MAIN -->

<main
    class="
        flex-1
        bg-gray-50
        py-8
        sm:py-10
        lg:py-14
    ">

    <div
        class="
            max-w-7xl
            mx-auto
            px-4
            sm:px-6
            lg:px-8
        ">


        <!-- PAGE HEADER -->

        <div
            class="
                mb-8
                flex
                flex-col
                sm:flex-row
                sm:items-end
                sm:justify-between
                gap-4
            ">

            <div>

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
                    ">

                    <span
                        class="
                            w-1.5
                            h-1.5
                            rounded-full
                            bg-brand-red
                        "></span>

                    Customer Account

                </div>


                <h1
                    class="
                        text-3xl
                        sm:text-4xl
                        font-extrabold
                        tracking-tight
                        text-gray-900
                    ">

                    <?php if ($selectedOrder): ?>

                        Order Details

                    <?php else: ?>

                        My Orders

                    <?php endif; ?>

                </h1>


                <p
                    class="
                        mt-2
                        text-sm
                        text-gray-500
                    ">

                    <?php if ($selectedOrder): ?>

                        Review your order information and items.

                    <?php else: ?>

                        View and track your Thirasara Max Mobile purchases.

                    <?php endif; ?>

                </p>

            </div>


            <div
                class="
                    flex
                    flex-wrap
                    items-center
                    gap-3
                ">

                <?php if ($selectedOrder): ?>

                    <?php if (isOrderCancellable($selectedOrder['order_status'])): ?>

                        <button
                            type="button"
                            onclick="openCancelModal(<?php
                                                        echo htmlspecialchars(
                                                            json_encode($selectedOrder['order_number']),
                                                            ENT_QUOTES
                                                        );
                                                        ?>)"
                            class="
                                inline-flex
                                items-center
                                justify-center
                                gap-2
                                rounded-xl
                                border
                                border-red-200
                                bg-red-50
                                px-4
                                py-2.5
                                text-sm
                                font-semibold
                                text-brand-red
                                shadow-sm
                                transition
                                hover:bg-red-100
                            ">

                            <i
                                data-lucide="circle-x"
                                class="w-4 h-4"></i>

                            Cancel Order

                        </button>

                    <?php endif; ?>


                    <a
                        href="<?php echo APP_URL; ?>/customer/orders.php"
                        class="
                            inline-flex
                            items-center
                            justify-center
                            gap-2
                            rounded-xl
                            border
                            border-gray-200
                            bg-white
                            px-4
                            py-2.5
                            text-sm
                            font-semibold
                            text-gray-700
                            shadow-sm
                            transition
                            hover:bg-gray-50
                        ">

                        <i
                            data-lucide="arrow-left"
                            class="w-4 h-4"></i>

                        All Orders

                    </a>

                <?php else: ?>

                    <a
                        href="<?php echo APP_URL; ?>/products.php"
                        class="
                            inline-flex
                            items-center
                            justify-center
                            gap-2
                            rounded-xl
                            bg-brand-red
                            px-4
                            py-2.5
                            text-sm
                            font-semibold
                            text-white
                            shadow-sm
                            transition
                            hover:bg-brand-redHover
                        ">

                        <i
                            data-lucide="shopping-bag"
                            class="w-4 h-4"></i>

                        Shop Products

                    </a>

                <?php endif; ?>

            </div>

        </div>


        <!-- FLASH MESSAGE -->

        <?php if (function_exists('displayFlash')): ?>

            <div class="mb-6">

                <?php displayFlash(); ?>

            </div>

        <?php endif; ?>


        <?php if ($selectedOrder): ?>


            <!-- ORDER DETAILS -->

            <div class="space-y-6">


                <!-- ORDER HEADER CARD -->

                <div
                    class="
                        bg-white
                        border
                        border-gray-200
                        rounded-2xl
                        shadow-sm
                        overflow-hidden
                    ">

                    <div
                        class="
                            p-5
                            sm:p-6
                            flex
                            flex-col
                            md:flex-row
                            md:items-center
                            md:justify-between
                            gap-5
                        ">

                        <div>

                            <div
                                class="
                                    flex
                                    flex-wrap
                                    items-center
                                    gap-3
                                ">

                                <h2
                                    class="
                                        text-xl
                                        sm:text-2xl
                                        font-extrabold
                                        text-gray-900
                                    ">
                                    <?php
                                    echo htmlspecialchars(
                                        $selectedOrder['order_number']
                                    );
                                    ?>
                                </h2>


                                <span
                                    class="
                                        inline-flex
                                        items-center
                                        gap-1.5
                                        rounded-full
                                        border
                                        px-3
                                        py-1
                                        text-xs
                                        font-semibold
                                        <?php
                                        echo orderStatusClass(
                                            $selectedOrder['order_status']
                                        );
                                        ?>
                                    ">

                                    <?php
                                    echo htmlspecialchars(
                                        formatOrderStatus(
                                            $selectedOrder['order_status']
                                        )
                                    );
                                    ?>

                                </span>

                            </div>


                            <p
                                class="
                                    mt-2
                                    text-sm
                                    text-gray-500
                                ">

                                Placed on
                                <span class="font-medium text-gray-700">

                                    <?php
                                    echo date(
                                        'd M Y, h:i A',
                                        strtotime(
                                            $selectedOrder['created_at']
                                        )
                                    );
                                    ?>

                                </span>

                            </p>

                        </div>


                        <div
                            class="
                                text-left
                                md:text-right
                            ">

                            <p
                                class="
                                    text-xs
                                    text-gray-500
                                ">
                                Order Total
                            </p>

                            <p
                                class="
                                    mt-1
                                    text-2xl
                                    font-extrabold
                                    text-gray-900
                                ">

                                Rs.
                                <?php
                                echo number_format(
                                    (float) $selectedOrder['total_amount'],
                                    2
                                );
                                ?>

                            </p>

                        </div>

                    </div>

                </div>


                <!-- STATUS TIMELINE -->

                <div
                    class="
                        bg-white
                        border
                        border-gray-200
                        rounded-2xl
                        shadow-sm
                        p-5
                        sm:p-6
                    ">

                    <div class="mb-6">

                        <h2
                            class="
                                text-lg
                                font-bold
                                text-gray-900
                            ">
                            Order Status
                        </h2>

                        <p
                            class="
                                mt-1
                                text-xs
                                text-gray-500
                            ">
                            Track the progress of your order.
                        </p>

                    </div>


                    <?php

                    $statusSteps = [
                        'placed' => [
                            'label' => 'Order Placed',
                            'icon' => 'clipboard-check'
                        ],
                        'processing' => [
                            'label' => 'Processing',
                            'icon' => 'package'
                        ],
                        'shipped' => [
                            'label' => 'Shipped',
                            'icon' => 'truck'
                        ],
                        'delivered' => [
                            'label' => 'Delivered',
                            'icon' => 'circle-check'
                        ]
                    ];

                    $statusOrder = [
                        'placed',
                        'processing',
                        'shipped',
                        'delivered'
                    ];

                    $currentStatus =
                        $selectedOrder['order_status'];

                    $currentIndex =
                        array_search(
                            $currentStatus,
                            $statusOrder,
                            true
                        );

                    if ($currentIndex === false) {
                        $currentIndex = -1;
                    }

                    ?>


                    <?php if ($currentStatus === 'cancelled'): ?>

                        <div
                            class="
                                rounded-xl
                                border
                                border-red-200
                                bg-red-50
                                p-4
                                flex
                                items-start
                                gap-3
                            ">

                            <div
                                class="
                                    w-9
                                    h-9
                                    rounded-lg
                                    bg-red-100
                                    text-red-600
                                    flex
                                    items-center
                                    justify-center
                                    flex-shrink-0
                                ">

                                <i
                                    data-lucide="circle-x"
                                    class="w-5 h-5"></i>

                            </div>

                            <div>

                                <p
                                    class="
                                        text-sm
                                        font-bold
                                        text-red-800
                                    ">
                                    Order Cancelled
                                </p>

                                <p
                                    class="
                                        mt-1
                                        text-xs
                                        text-red-600
                                    ">
                                    This order has been cancelled.
                                </p>

                            </div>

                        </div>

                    <?php else: ?>

                        <div
                            class="
                                grid
                                grid-cols-2
                                md:grid-cols-4
                                gap-4
                            ">

                            <?php foreach (
                                $statusSteps
                                as $statusKey => $statusData
                            ): ?>

                                <?php

                                $stepIndex =
                                    array_search(
                                        $statusKey,
                                        $statusOrder,
                                        true
                                    );

                                $isComplete =
                                    $stepIndex <= $currentIndex;

                                ?>

                                <div
                                    class="
                                        relative
                                    ">

                                    <div
                                        class="
                                            flex
                                            flex-col
                                            items-center
                                            text-center
                                        ">

                                        <div
                                            class="
                                                w-11
                                                h-11
                                                rounded-full
                                                flex
                                                items-center
                                                justify-center
                                                <?php
                                                echo $isComplete
                                                    ? 'bg-brand-red text-white'
                                                    : 'bg-gray-100 text-gray-400';
                                                ?>
                                            ">

                                            <i
                                                data-lucide="<?php
                                                                echo $statusData['icon'];
                                                                ?>"
                                                class="w-5 h-5"></i>

                                        </div>


                                        <p
                                            class="
                                                mt-3
                                                text-xs
                                                font-semibold
                                                <?php
                                                echo $isComplete
                                                    ? 'text-gray-900'
                                                    : 'text-gray-400';
                                                ?>
                                            ">
                                            <?php
                                            echo htmlspecialchars(
                                                $statusData['label']
                                            );
                                            ?>
                                        </p>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                </div>


                <!-- ORDER ITEMS -->

                <div
                    class="
                        bg-white
                        border
                        border-gray-200
                        rounded-2xl
                        shadow-sm
                        overflow-hidden
                    ">

                    <div
                        class="
                            px-5
                            sm:px-6
                            py-5
                            border-b
                            border-gray-100
                        ">

                        <h2
                            class="
                                text-lg
                                font-bold
                                text-gray-900
                            ">
                            Items in This Order
                        </h2>

                        <p
                            class="
                                mt-1
                                text-xs
                                text-gray-500
                            ">
                            <?php
                            echo count(
                                $selectedOrderItems
                            );
                            ?>
                            product<?php
                                    echo count($selectedOrderItems) !== 1
                                        ? 's'
                                        : '';
                                    ?>
                        </p>

                    </div>


                    <div class="divide-y divide-gray-100">

                        <?php if (empty($selectedOrderItems)): ?>

                            <div
                                class="
                                    p-8
                                    text-center
                                ">

                                <i
                                    data-lucide="package-x"
                                    class="
                                        w-10
                                        h-10
                                        mx-auto
                                        text-gray-300
                                    "></i>

                                <p
                                    class="
                                        mt-3
                                        text-sm
                                        text-gray-500
                                    ">
                                    No items were found for this order.
                                </p>

                            </div>

                        <?php else: ?>

                            <?php foreach (
                                $selectedOrderItems
                                as $item
                            ): ?>

                                <div
                                    class="
                                        p-5
                                        sm:px-6
                                        flex
                                        gap-4
                                    ">

                                    <!-- IMAGE -->

                                    <div
                                        class="
                                            w-20
                                            h-20
                                            sm:w-24
                                            sm:h-24
                                            rounded-xl
                                            bg-gray-100
                                            border
                                            border-gray-100
                                            overflow-hidden
                                            flex
                                            items-center
                                            justify-center
                                            flex-shrink-0
                                        ">

                                        <?php
                                        $imageUrl =
                                            $item['image_url']
                                            ?? '';
                                        ?>

                                        <?php if ($imageUrl !== ''): ?>

                                            <img
                                                src="<?php
                                                        echo APP_URL .
                                                            '/' .
                                                            ltrim(
                                                                $imageUrl,
                                                                '/'
                                                            );
                                                        ?>"
                                                alt="<?php
                                                        echo htmlspecialchars(
                                                            $item['name']
                                                        );
                                                        ?>"
                                                class="
                                                    w-full
                                                    h-full
                                                    object-contain
                                                "
                                                onerror="
                                                    this.style.display='none';
                                                    this.nextElementSibling.style.display='flex';
                                                ">

                                            <div
                                                class="
                                                    hidden
                                                    w-full
                                                    h-full
                                                    items-center
                                                    justify-center
                                                    text-gray-400
                                                ">

                                                <i
                                                    data-lucide="image-off"
                                                    class="w-7 h-7"></i>

                                            </div>

                                        <?php else: ?>

                                            <i
                                                data-lucide="package"
                                                class="
                                                    w-7
                                                    h-7
                                                    text-gray-400
                                                "></i>

                                        <?php endif; ?>

                                    </div>


                                    <!-- DETAILS -->

                                    <div
                                        class="
                                            flex-1
                                            min-w-0
                                        ">

                                        <div
                                            class="
                                                flex
                                                flex-col
                                                sm:flex-row
                                                sm:items-start
                                                sm:justify-between
                                                gap-3
                                            ">

                                            <div>

                                                <h3
                                                    class="
                                                        text-sm
                                                        sm:text-base
                                                        font-bold
                                                        text-gray-900
                                                    ">

                                                    <?php
                                                    echo htmlspecialchars(
                                                        $item['name']
                                                    );
                                                    ?>

                                                </h3>


                                                <div
                                                    class="
                                                        mt-1
                                                        flex
                                                        flex-wrap
                                                        items-center
                                                        gap-x-2
                                                        gap-y-1
                                                    ">

                                                    <?php if (
                                                        !empty($item['brand'])
                                                    ): ?>

                                                        <span
                                                            class="
                                                                text-xs
                                                                text-gray-500
                                                            ">

                                                            <?php
                                                            echo htmlspecialchars(
                                                                $item['brand']
                                                            );
                                                            ?>

                                                        </span>

                                                    <?php endif; ?>


                                                    <?php if (
                                                        !empty($item['category'])
                                                    ): ?>

                                                        <span
                                                            class="
                                                                text-gray-300
                                                            ">
                                                            •
                                                        </span>

                                                        <span
                                                            class="
                                                                text-xs
                                                                text-gray-500
                                                            ">

                                                            <?php
                                                            echo htmlspecialchars(
                                                                $item['category']
                                                            );
                                                            ?>

                                                        </span>

                                                    <?php endif; ?>

                                                </div>

                                            </div>


                                            <div
                                                class="
                                                    text-left
                                                    sm:text-right
                                                ">

                                                <p
                                                    class="
                                                        text-sm
                                                        font-extrabold
                                                        text-gray-900
                                                    ">

                                                    Rs.
                                                    <?php
                                                    echo number_format(
                                                        (float) $item['subtotal'],
                                                        2
                                                    );
                                                    ?>

                                                </p>

                                                <p
                                                    class="
                                                        mt-1
                                                        text-xs
                                                        text-gray-500
                                                    ">

                                                    Rs.
                                                    <?php
                                                    echo number_format(
                                                        (float) $item['unit_price'],
                                                        2
                                                    );
                                                    ?>

                                                    ×

                                                    <?php
                                                    echo (int) $item['quantity'];
                                                    ?>

                                                </p>

                                            </div>

                                        </div>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>


                    <!-- TOTAL -->

                    <div
                        class="
                            border-t
                            border-gray-100
                            bg-gray-50
                            p-5
                            sm:p-6
                        ">

                        <div
                            class="
                                max-w-sm
                                ml-auto
                                space-y-3
                            ">

                            <div
                                class="
                                    flex
                                    justify-between
                                    text-sm
                                    text-gray-600
                                ">

                                <span>
                                    Items
                                </span>

                                <span>
                                    <?php
                                    echo count(
                                        $selectedOrderItems
                                    );
                                    ?>
                                </span>

                            </div>


                            <div
                                class="
                                    flex
                                    justify-between
                                    items-center
                                    pt-3
                                    border-t
                                    border-gray-200
                                ">

                                <span
                                    class="
                                        text-base
                                        font-bold
                                        text-gray-900
                                    ">
                                    Total
                                </span>

                                <span
                                    class="
                                        text-xl
                                        font-extrabold
                                        text-gray-900
                                    ">

                                    Rs.
                                    <?php
                                    echo number_format(
                                        (float) $selectedOrder['total_amount'],
                                        2
                                    );
                                    ?>

                                </span>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- DELIVERY + PAYMENT -->

                <div
                    class="
                        grid
                        grid-cols-1
                        md:grid-cols-2
                        gap-6
                    ">


                    <!-- DELIVERY -->

                    <div
                        class="
                            bg-white
                            border
                            border-gray-200
                            rounded-2xl
                            shadow-sm
                            p-5
                            sm:p-6
                        ">

                        <div
                            class="
                                flex
                                items-center
                                gap-3
                                mb-5
                            ">

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
                                ">

                                <i
                                    data-lucide="map-pin"
                                    class="w-5 h-5"></i>

                            </div>

                            <div>

                                <h2
                                    class="
                                        text-base
                                        font-bold
                                        text-gray-900
                                    ">
                                    Delivery Details
                                </h2>

                                <p
                                    class="
                                        text-xs
                                        text-gray-500
                                        mt-0.5
                                    ">
                                    Shipping information
                                </p>

                            </div>

                        </div>


                        <div class="space-y-4">

                            <div>

                                <p
                                    class="
                                        text-xs
                                        font-medium
                                        text-gray-400
                                        uppercase
                                        tracking-wide
                                    ">
                                    Contact Phone
                                </p>

                                <p
                                    class="
                                        mt-1
                                        text-sm
                                        font-medium
                                        text-gray-800
                                    ">

                                    <?php
                                    echo htmlspecialchars(
                                        $selectedOrder['contact_phone']
                                    );
                                    ?>

                                </p>

                            </div>


                            <div>

                                <p
                                    class="
                                        text-xs
                                        font-medium
                                        text-gray-400
                                        uppercase
                                        tracking-wide
                                    ">
                                    Delivery Address
                                </p>

                                <p
                                    class="
                                        mt-1
                                        text-sm
                                        leading-6
                                        text-gray-700
                                        whitespace-pre-line
                                    ">

                                    <?php
                                    echo htmlspecialchars(
                                        $selectedOrder['shipping_address']
                                    );
                                    ?>

                                </p>

                            </div>


                            <?php if (
                                !empty($selectedOrder['notes'])
                            ): ?>

                                <div>

                                    <p
                                        class="
                                            text-xs
                                            font-medium
                                            text-gray-400
                                            uppercase
                                            tracking-wide
                                        ">
                                        Order Notes
                                    </p>

                                    <p
                                        class="
                                            mt-1
                                            text-sm
                                            leading-6
                                            text-gray-700
                                            whitespace-pre-line
                                        ">

                                        <?php
                                        echo htmlspecialchars(
                                            $selectedOrder['notes']
                                        );
                                        ?>

                                    </p>

                                </div>

                            <?php endif; ?>

                        </div>

                    </div>


                    <!-- PAYMENT -->

                    <div
                        class="
                            bg-white
                            border
                            border-gray-200
                            rounded-2xl
                            shadow-sm
                            p-5
                            sm:p-6
                        ">

                        <div
                            class="
                                flex
                                items-center
                                gap-3
                                mb-5
                            ">

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
                                ">

                                <i
                                    data-lucide="credit-card"
                                    class="w-5 h-5"></i>

                            </div>

                            <div>

                                <h2
                                    class="
                                        text-base
                                        font-bold
                                        text-gray-900
                                    ">
                                    Payment Details
                                </h2>

                                <p
                                    class="
                                        text-xs
                                        text-gray-500
                                        mt-0.5
                                    ">
                                    Payment information
                                </p>

                            </div>

                        </div>


                        <?php if ($selectedPayment): ?>

                            <div class="space-y-4">


                                <div
                                    class="
                                        flex
                                        items-center
                                        justify-between
                                        gap-4
                                    ">

                                    <span
                                        class="
                                            text-sm
                                            text-gray-500
                                        ">
                                        Method
                                    </span>

                                    <span
                                        class="
                                            text-sm
                                            font-semibold
                                            text-gray-900
                                        ">

                                        <?php
                                        echo htmlspecialchars(
                                            formatPaymentMethod(
                                                $selectedPayment['payment_method']
                                            )
                                        );
                                        ?>

                                    </span>

                                </div>


                                <div
                                    class="
                                        flex
                                        items-center
                                        justify-between
                                        gap-4
                                    ">

                                    <span
                                        class="
                                            text-sm
                                            text-gray-500
                                        ">
                                        Amount
                                    </span>

                                    <span
                                        class="
                                            text-sm
                                            font-bold
                                            text-gray-900
                                        ">

                                        Rs.
                                        <?php
                                        echo number_format(
                                            (float) $selectedPayment['amount'],
                                            2
                                        );
                                        ?>

                                    </span>

                                </div>


                                <div
                                    class="
                                        flex
                                        items-center
                                        justify-between
                                        gap-4
                                    ">

                                    <span
                                        class="
                                            text-sm
                                            text-gray-500
                                        ">
                                        Status
                                    </span>

                                    <span
                                        class="
                                            inline-flex
                                            items-center
                                            rounded-full
                                            border
                                            px-2.5
                                            py-1
                                            text-xs
                                            font-semibold
                                            <?php
                                            echo paymentStatusClass(
                                                $selectedPayment['payment_status']
                                            );
                                            ?>
                                        ">

                                        <?php
                                        echo htmlspecialchars(
                                            ucfirst(
                                                $selectedPayment['payment_status']
                                            )
                                        );
                                        ?>

                                    </span>

                                </div>


                                <div
                                    class="
                                        pt-3
                                        border-t
                                        border-gray-100
                                    ">

                                    <p
                                        class="
                                            text-xs
                                            text-gray-400
                                        ">
                                        Transaction ID
                                    </p>

                                    <p
                                        class="
                                            mt-1
                                            text-xs
                                            font-mono
                                            text-gray-700
                                            break-all
                                        ">

                                        <?php
                                        echo htmlspecialchars(
                                            $selectedPayment['transaction_id']
                                        );
                                        ?>

                                    </p>

                                </div>

                            </div>

                        <?php else: ?>

                            <div
                                class="
                                    rounded-xl
                                    border
                                    border-amber-200
                                    bg-amber-50
                                    p-4
                                ">

                                <div
                                    class="
                                        flex
                                        items-start
                                        gap-3
                                    ">

                                    <i
                                        data-lucide="clock-3"
                                        class="
                                            w-5
                                            h-5
                                            text-amber-600
                                            flex-shrink-0
                                        "></i>

                                    <div>

                                        <p
                                            class="
                                                text-sm
                                                font-semibold
                                                text-amber-800
                                            ">
                                            Payment Pending
                                        </p>

                                        <p
                                            class="
                                                mt-1
                                                text-xs
                                                leading-5
                                                text-amber-700
                                            ">
                                            Payment information is not
                                            available yet.
                                        </p>

                                    </div>

                                </div>

                            </div>

                        <?php endif; ?>

                    </div>

                </div>


            </div>


        <?php else: ?>


            <!-- ORDER STATISTICS-->

            <div
                class="
                    grid
                    grid-cols-2
                    lg:grid-cols-4
                    gap-4
                    mb-8
                ">


                <!-- TOTAL -->

                <div
                    class="
                        bg-white
                        border
                        border-gray-200
                        rounded-2xl
                        p-4
                        sm:p-5
                        shadow-sm
                    ">

                    <div
                        class="
                            flex
                            items-center
                            justify-between
                            gap-3
                        ">

                        <div>

                            <p
                                class="
                                    text-xs
                                    font-medium
                                    text-gray-500
                                ">
                                Total Orders
                            </p>

                            <p
                                class="
                                    mt-1
                                    text-2xl
                                    font-extrabold
                                    text-gray-900
                                ">
                                <?php
                                echo $totalOrders;
                                ?>
                            </p>

                        </div>

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
                            ">

                            <i
                                data-lucide="shopping-bag"
                                class="w-5 h-5"></i>

                        </div>

                    </div>

                </div>


                <!-- ACTIVE -->

                <div
                    class="
                        bg-white
                        border
                        border-gray-200
                        rounded-2xl
                        p-4
                        sm:p-5
                        shadow-sm
                    ">

                    <div
                        class="
                            flex
                            items-center
                            justify-between
                            gap-3
                        ">

                        <div>

                            <p
                                class="
                                    text-xs
                                    font-medium
                                    text-gray-500
                                ">
                                Active
                            </p>

                            <p
                                class="
                                    mt-1
                                    text-2xl
                                    font-extrabold
                                    text-gray-900
                                ">
                                <?php
                                echo $activeOrders;
                                ?>
                            </p>

                        </div>

                        <div
                            class="
                                w-10
                                h-10
                                rounded-xl
                                bg-blue-50
                                text-blue-600
                                flex
                                items-center
                                justify-center
                            ">

                            <i
                                data-lucide="package"
                                class="w-5 h-5"></i>

                        </div>

                    </div>

                </div>


                <!-- COMPLETED -->

                <div
                    class="
                        bg-white
                        border
                        border-gray-200
                        rounded-2xl
                        p-4
                        sm:p-5
                        shadow-sm
                    ">

                    <div
                        class="
                            flex
                            items-center
                            justify-between
                            gap-3
                        ">

                        <div>

                            <p
                                class="
                                    text-xs
                                    font-medium
                                    text-gray-500
                                ">
                                Delivered
                            </p>

                            <p
                                class="
                                    mt-1
                                    text-2xl
                                    font-extrabold
                                    text-gray-900
                                ">
                                <?php
                                echo $completedOrders;
                                ?>
                            </p>

                        </div>

                        <div
                            class="
                                w-10
                                h-10
                                rounded-xl
                                bg-green-50
                                text-green-600
                                flex
                                items-center
                                justify-center
                            ">

                            <i
                                data-lucide="circle-check"
                                class="w-5 h-5"></i>

                        </div>

                    </div>

                </div>


                <!-- CANCELLED -->

                <div
                    class="
                        bg-white
                        border
                        border-gray-200
                        rounded-2xl
                        p-4
                        sm:p-5
                        shadow-sm
                    ">

                    <div
                        class="
                            flex
                            items-center
                            justify-between
                            gap-3
                        ">

                        <div>

                            <p
                                class="
                                    text-xs
                                    font-medium
                                    text-gray-500
                                ">
                                Cancelled
                            </p>

                            <p
                                class="
                                    mt-1
                                    text-2xl
                                    font-extrabold
                                    text-gray-900
                                ">
                                <?php
                                echo $cancelledOrders;
                                ?>
                            </p>

                        </div>

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
                            ">

                            <i
                                data-lucide="circle-x"
                                class="w-5 h-5"></i>

                        </div>

                    </div>

                </div>

            </div>


            <!-- ORDERS LIST -->

            <div
                class="
                    bg-white
                    border
                    border-gray-200
                    rounded-2xl
                    shadow-sm
                    overflow-hidden
                ">

                <div
                    class="
                        px-5
                        sm:px-6
                        py-5
                        border-b
                        border-gray-100
                        flex
                        flex-col
                        sm:flex-row
                        sm:items-center
                        sm:justify-between
                        gap-3
                    ">

                    <div>

                        <h2
                            class="
                                text-lg
                                font-bold
                                text-gray-900
                            ">
                            Order History
                        </h2>

                        <p
                            class="
                                mt-1
                                text-xs
                                text-gray-500
                            ">
                            Your recent purchases
                        </p>

                    </div>

                </div>


                <?php if (empty($orders)): ?>


                    <!-- EMPTY ORDERS -->

                    <div
                        class="
                            px-6
                            py-16
                            text-center
                        ">

                        <div
                            class="
                                mx-auto
                                w-16
                                h-16
                                rounded-2xl
                                bg-gray-100
                                text-gray-400
                                flex
                                items-center
                                justify-center
                            ">

                            <i
                                data-lucide="shopping-bag"
                                class="w-8 h-8"></i>

                        </div>


                        <h3
                            class="
                                mt-5
                                text-lg
                                font-bold
                                text-gray-900
                            ">
                            No Orders Yet
                        </h3>


                        <p
                            class="
                                mt-2
                                max-w-md
                                mx-auto
                                text-sm
                                leading-6
                                text-gray-500
                            ">
                            You haven't placed an order yet.
                            Explore our products and accessories
                            to get started.
                        </p>


                        <a
                            href="<?php echo APP_URL; ?>/products.php"
                            class="
                                mt-6
                                inline-flex
                                items-center
                                justify-center
                                gap-2
                                rounded-xl
                                bg-brand-red
                                px-5
                                py-3
                                text-sm
                                font-semibold
                                text-white
                                transition
                                hover:bg-brand-redHover
                            ">

                            <i
                                data-lucide="shopping-bag"
                                class="w-4 h-4"></i>

                            Browse Products

                        </a>

                    </div>


                <?php else: ?>


                    <!-- DESKTOP TABLE -->

                    <div class="hidden md:block overflow-x-auto">

                        <table
                            class="
                                w-full
                                text-left
                            ">

                            <thead
                                class="
                                    bg-gray-50
                                    border-b
                                    border-gray-100
                                ">

                                <tr>

                                    <th
                                        class="
                                            px-6
                                            py-4
                                            text-[11px]
                                            font-bold
                                            uppercase
                                            tracking-wider
                                            text-gray-500
                                        ">
                                        Order
                                    </th>

                                    <th
                                        class="
                                            px-6
                                            py-4
                                            text-[11px]
                                            font-bold
                                            uppercase
                                            tracking-wider
                                            text-gray-500
                                        ">
                                        Date
                                    </th>

                                    <th
                                        class="
                                            px-6
                                            py-4
                                            text-[11px]
                                            font-bold
                                            uppercase
                                            tracking-wider
                                            text-gray-500
                                        ">
                                        Items
                                    </th>

                                    <th
                                        class="
                                            px-6
                                            py-4
                                            text-[11px]
                                            font-bold
                                            uppercase
                                            tracking-wider
                                            text-gray-500
                                        ">
                                        Status
                                    </th>

                                    <th
                                        class="
                                            px-6
                                            py-4
                                            text-[11px]
                                            font-bold
                                            uppercase
                                            tracking-wider
                                            text-gray-500
                                        ">
                                        Payment
                                    </th>

                                    <th
                                        class="
                                            px-6
                                            py-4
                                            text-[11px]
                                            font-bold
                                            uppercase
                                            tracking-wider
                                            text-gray-500
                                            text-right
                                        ">
                                        Total
                                    </th>

                                    <th
                                        class="
                                            px-6
                                            py-4
                                        "></th>

                                </tr>

                            </thead>


                            <tbody
                                class="
                                    divide-y
                                    divide-gray-100
                                ">

                                <?php foreach (
                                    $orders
                                    as $order
                                ): ?>

                                    <tr
                                        class="
                                            hover:bg-gray-50
                                            transition
                                        ">

                                        <td
                                            class="
                                                px-6
                                                py-5
                                            ">

                                            <div
                                                class="
                                                    text-sm
                                                    font-bold
                                                    text-gray-900
                                                ">

                                                <?php
                                                echo htmlspecialchars(
                                                    $order['order_number']
                                                );
                                                ?>

                                            </div>

                                        </td>


                                        <td
                                            class="
                                                px-6
                                                py-5
                                            ">

                                            <div
                                                class="
                                                    text-sm
                                                    text-gray-700
                                                ">

                                                <?php
                                                echo date(
                                                    'd M Y',
                                                    strtotime(
                                                        $order['created_at']
                                                    )
                                                );
                                                ?>

                                            </div>

                                            <div
                                                class="
                                                    mt-1
                                                    text-xs
                                                    text-gray-400
                                                ">

                                                <?php
                                                echo date(
                                                    'h:i A',
                                                    strtotime(
                                                        $order['created_at']
                                                    )
                                                );
                                                ?>

                                            </div>

                                        </td>


                                        <td
                                            class="
                                                px-6
                                                py-5
                                            ">

                                            <span
                                                class="
                                                    text-sm
                                                    text-gray-700
                                                ">

                                                <?php
                                                echo (int)
                                                $order['item_count'];
                                                ?>

                                                item<?php
                                                    echo (int)
                                                    $order['item_count'] !== 1
                                                        ? 's'
                                                        : '';
                                                    ?>

                                            </span>

                                        </td>


                                        <td
                                            class="
                                                px-6
                                                py-5
                                            ">

                                            <span
                                                class="
                                                    inline-flex
                                                    items-center
                                                    rounded-full
                                                    border
                                                    px-2.5
                                                    py-1
                                                    text-xs
                                                    font-semibold
                                                    <?php
                                                    echo orderStatusClass(
                                                        $order['order_status']
                                                    );
                                                    ?>
                                                ">

                                                <?php
                                                echo htmlspecialchars(
                                                    formatOrderStatus(
                                                        $order['order_status']
                                                    )
                                                );
                                                ?>

                                            </span>

                                        </td>


                                        <td
                                            class="
                                                px-6
                                                py-5
                                            ">

                                            <span
                                                class="
                                                    inline-flex
                                                    items-center
                                                    rounded-full
                                                    border
                                                    px-2.5
                                                    py-1
                                                    text-xs
                                                    font-semibold
                                                    <?php
                                                    echo paymentStatusClass(
                                                        $order['payment_status']
                                                    );
                                                    ?>
                                                ">

                                                <?php
                                                echo htmlspecialchars(
                                                    ucfirst(
                                                        $order['payment_status']
                                                    )
                                                );
                                                ?>

                                            </span>

                                        </td>


                                        <td
                                            class="
                                                px-6
                                                py-5
                                                text-right
                                            ">

                                            <span
                                                class="
                                                    text-sm
                                                    font-bold
                                                    text-gray-900
                                                ">

                                                Rs.
                                                <?php
                                                echo number_format(
                                                    (float) $order['total_amount'],
                                                    2
                                                );
                                                ?>

                                            </span>

                                        </td>


                                        <td
                                            class="
                                                px-6
                                                py-5
                                                text-right
                                            ">

                                            <div
                                                class="
                                                    flex
                                                    items-center
                                                    justify-end
                                                    gap-2
                                                ">

                                                <?php if (
                                                    isOrderCancellable(
                                                        $order['order_status']
                                                    )
                                                ): ?>

                                                    <button
                                                        type="button"
                                                        onclick="openCancelModal(<?php
                                                                                    echo htmlspecialchars(
                                                                                        json_encode($order['order_number']),
                                                                                        ENT_QUOTES
                                                                                    );
                                                                                    ?>)"
                                                        class="
                                                            inline-flex
                                                            items-center
                                                            justify-center
                                                            gap-1.5
                                                            rounded-lg
                                                            border
                                                            border-red-200
                                                            bg-red-50
                                                            px-3
                                                            py-2
                                                            text-xs
                                                            font-semibold
                                                            text-brand-red
                                                            transition
                                                            hover:bg-red-100
                                                        ">

                                                        <i
                                                            data-lucide="circle-x"
                                                            class="w-3.5 h-3.5"></i>

                                                        Cancel

                                                    </button>

                                                <?php endif; ?>


                                                <a
                                                    href="<?php
                                                            echo APP_URL;
                                                            ?>/customer/orders.php?order=<?php
                                                                                            echo urlencode(
                                                                                                $order['order_number']
                                                                                            );
                                                                                            ?>"
                                                    class="
                                                        inline-flex
                                                        items-center
                                                        justify-center
                                                        gap-1.5
                                                        rounded-lg
                                                        border
                                                        border-gray-200
                                                        bg-white
                                                        px-3
                                                        py-2
                                                        text-xs
                                                        font-semibold
                                                        text-gray-700
                                                        transition
                                                        hover:bg-gray-50
                                                        hover:border-gray-300
                                                    ">

                                                    View

                                                    <i
                                                        data-lucide="arrow-right"
                                                        class="w-3.5 h-3.5"></i>

                                                </a>

                                            </div>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>


                    <!-- MOBILE CARDS -->

                    <div
                        class="
                            md:hidden
                            divide-y
                            divide-gray-100
                        ">

                        <?php foreach (
                            $orders
                            as $order
                        ): ?>

                            <div class="p-5">

                                <div
                                    class="
                                        flex
                                        items-start
                                        justify-between
                                        gap-3
                                    ">

                                    <div>

                                        <p
                                            class="
                                                text-sm
                                                font-bold
                                                text-gray-900
                                            ">

                                            <?php
                                            echo htmlspecialchars(
                                                $order['order_number']
                                            );
                                            ?>

                                        </p>

                                        <p
                                            class="
                                                mt-1
                                                text-xs
                                                text-gray-500
                                            ">

                                            <?php
                                            echo date(
                                                'd M Y, h:i A',
                                                strtotime(
                                                    $order['created_at']
                                                )
                                            );
                                            ?>

                                        </p>

                                    </div>


                                    <span
                                        class="
                                            inline-flex
                                            items-center
                                            rounded-full
                                            border
                                            px-2.5
                                            py-1
                                            text-[11px]
                                            font-semibold
                                            <?php
                                            echo orderStatusClass(
                                                $order['order_status']
                                            );
                                            ?>
                                        ">

                                        <?php
                                        echo htmlspecialchars(
                                            formatOrderStatus(
                                                $order['order_status']
                                            )
                                        );
                                        ?>

                                    </span>

                                </div>


                                <div
                                    class="
                                        mt-5
                                        grid
                                        grid-cols-2
                                        gap-4
                                    ">

                                    <div>

                                        <p
                                            class="
                                                text-[11px]
                                                text-gray-400
                                                uppercase
                                                tracking-wide
                                            ">
                                            Items
                                        </p>

                                        <p
                                            class="
                                                mt-1
                                                text-sm
                                                font-semibold
                                                text-gray-800
                                            ">

                                            <?php
                                            echo (int)
                                            $order['item_count'];
                                            ?>

                                            item<?php
                                                echo (int)
                                                $order['item_count'] !== 1
                                                    ? 's'
                                                    : '';
                                                ?>

                                        </p>

                                    </div>


                                    <div>

                                        <p
                                            class="
                                                text-[11px]
                                                text-gray-400
                                                uppercase
                                                tracking-wide
                                            ">
                                            Payment
                                        </p>

                                        <span
                                            class="
                                                mt-1
                                                inline-flex
                                                items-center
                                                rounded-full
                                                border
                                                px-2
                                                py-1
                                                text-[11px]
                                                font-semibold
                                                <?php
                                                echo paymentStatusClass(
                                                    $order['payment_status']
                                                );
                                                ?>
                                            ">

                                            <?php
                                            echo htmlspecialchars(
                                                ucfirst(
                                                    $order['payment_status']
                                                )
                                            );
                                            ?>

                                        </span>

                                    </div>

                                </div>


                                <div
                                    class="
                                        mt-5
                                        pt-4
                                        border-t
                                        border-gray-100
                                        flex
                                        items-center
                                        justify-between
                                        gap-4
                                    ">

                                    <div>

                                        <p
                                            class="
                                                text-[11px]
                                                text-gray-400
                                                uppercase
                                                tracking-wide
                                            ">
                                            Total
                                        </p>

                                        <p
                                            class="
                                                mt-1
                                                text-lg
                                                font-extrabold
                                                text-gray-900
                                            ">

                                            Rs.
                                            <?php
                                            echo number_format(
                                                (float) $order['total_amount'],
                                                2
                                            );
                                            ?>

                                        </p>

                                    </div>


                                    <div
                                        class="
                                            flex
                                            items-center
                                            gap-2
                                        ">

                                        <?php if (
                                            isOrderCancellable(
                                                $order['order_status']
                                            )
                                        ): ?>

                                            <button
                                                type="button"
                                                onclick="openCancelModal(<?php
                                                                            echo htmlspecialchars(
                                                                                json_encode($order['order_number']),
                                                                                ENT_QUOTES
                                                                            );
                                                                            ?>)"
                                                class="
                                                    inline-flex
                                                    items-center
                                                    justify-center
                                                    gap-1.5
                                                    rounded-xl
                                                    border
                                                    border-red-200
                                                    bg-red-50
                                                    px-4
                                                    py-2.5
                                                    text-xs
                                                    font-bold
                                                    text-brand-red
                                                    transition
                                                    hover:bg-red-100
                                                ">

                                                <i
                                                    data-lucide="circle-x"
                                                    class="w-3.5 h-3.5"></i>

                                                Cancel

                                            </button>

                                        <?php endif; ?>


                                        <a
                                            href="<?php
                                                    echo APP_URL;
                                                    ?>/customer/orders.php?order=<?php
                                                                                    echo urlencode(
                                                                                        $order['order_number']
                                                                                    );
                                                                                    ?>"
                                            class="
                                                inline-flex
                                                items-center
                                                justify-center
                                                gap-1.5
                                                rounded-xl
                                                bg-brand-red
                                                px-4
                                                py-2.5
                                                text-xs
                                                font-bold
                                                text-white
                                                transition
                                                hover:bg-brand-redHover
                                            ">

                                            View Order

                                            <i
                                                data-lucide="arrow-right"
                                                class="w-3.5 h-3.5"></i>

                                        </a>

                                    </div>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </div>

        <?php endif; ?>

    </div>

</main>


<!-- CANCEL ORDER MODAL -->

<div
    id="cancelOrderModal"
    class="
        hidden
        fixed
        inset-0
        z-50
        flex
        items-center
        justify-center
        p-4
    "
    role="dialog"
    aria-modal="true"
    aria-labelledby="cancelOrderModalTitle">

    <!-- BACKDROP -->

    <div
        class="
            absolute
            inset-0
            bg-gray-900/60
        "
        onclick="closeCancelModal()"></div>


    <!-- DIALOG -->

    <div
        class="
            relative
            w-full
            max-w-md
            bg-white
            rounded-2xl
            shadow-xl
            overflow-hidden
        ">

        <div class="p-6">

            <div
                class="
                    flex
                    items-start
                    gap-4
                ">

                <div
                    class="
                        w-11
                        h-11
                        rounded-xl
                        bg-red-50
                        text-brand-red
                        flex
                        items-center
                        justify-center
                        flex-shrink-0
                    ">

                    <i
                        data-lucide="triangle-alert"
                        class="w-5 h-5"></i>

                </div>

                <div>

                    <h2
                        id="cancelOrderModalTitle"
                        class="
                            text-lg
                            font-bold
                            text-gray-900
                        ">
                        Cancel this order?
                    </h2>

                    <p
                        class="
                            mt-2
                            text-sm
                            leading-6
                            text-gray-600
                        ">
                        You're about to cancel order
                        <span
                            id="cancelOrderNumber"
                            class="font-semibold text-gray-900"></span>.
                        This will release the reserved stock back
                        into inventory. This action cannot be undone.
                    </p>

                </div>

            </div>


            <form
                method="POST"
                action="<?php echo APP_URL; ?>/customer/cancel_order.php"
                class="mt-6">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo htmlspecialchars($csrfToken); ?>">

                <input
                    type="hidden"
                    name="order_number"
                    id="cancelOrderNumberInput"
                    value="">

                <div
                    class="
                        flex
                        flex-col-reverse
                        sm:flex-row
                        sm:justify-end
                        gap-3
                    ">

                    <button
                        type="button"
                        onclick="closeCancelModal()"
                        class="
                            inline-flex
                            items-center
                            justify-center
                            rounded-xl
                            border
                            border-gray-200
                            bg-white
                            px-4
                            py-2.5
                            text-sm
                            font-semibold
                            text-gray-700
                            transition
                            hover:bg-gray-50
                        ">
                        Keep Order
                    </button>

                    <button
                        type="submit"
                        class="
                            inline-flex
                            items-center
                            justify-center
                            gap-2
                            rounded-xl
                            bg-brand-red
                            px-4
                            py-2.5
                            text-sm
                            font-semibold
                            text-white
                            transition
                            hover:bg-brand-redHover
                        ">

                        <i
                            data-lucide="circle-x"
                            class="w-4 h-4"></i>

                        Yes, Cancel Order

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<script>
    /* Lucide Icons */

    if (typeof lucide !== 'undefined') {

        lucide.createIcons();

    }


    /* Cancel Order Modal */

    function openCancelModal(orderNumber) {

        document.getElementById('cancelOrderNumber').textContent = orderNumber;
        document.getElementById('cancelOrderNumberInput').value = orderNumber;

        document.getElementById('cancelOrderModal').classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
    }

    function closeCancelModal() {

        document.getElementById('cancelOrderModal').classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }

    document.addEventListener('keydown', function(event) {

        if (event.key === 'Escape') {
            closeCancelModal();
        }
    });
</script>


<?php require_once __DIR__ . '/../includes/footer.php'; ?>