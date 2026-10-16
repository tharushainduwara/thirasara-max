<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Authentication
requireAuth(['customer', 'admin']);

$currentUser = getCurrentUser();
$userId = (int) ($currentUser['id'] ?? 0);
$orderId = (int) ($_GET['order_id'] ?? 0);

if ($userId <= 0 || $orderId <= 0) {
    setFlash('error', 'Invalid order payment request.');
    header('Location: ' . APP_URL . '/cart.php');
    exit;
}

$pdo = Database::getConnection();

// Fetch Order
$orderStmt = $pdo->prepare("
    SELECT id, order_number, user_id, total_amount, payment_status, order_status, shipping_address, contact_phone
    FROM orders
    WHERE id = ? AND user_id = ?
    LIMIT 1
");
$orderStmt->execute([$orderId, $userId]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    setFlash('error', 'Order not found.');
    header('Location: ' . APP_URL . '/customer/orders.php');
    exit;
}

if ($order['payment_status'] === 'paid') {
    setFlash('info', 'This order has already been paid for.');
    header('Location: ' . APP_URL . '/customer/orders.php?order=' . urlencode($order['order_number']));
    exit;
}

// Fetch user profile for address / name parts
$userStmt = $pdo->prepare("SELECT name, email, phone, address FROM users WHERE id = ? LIMIT 1");
$userStmt->execute([$userId]);
$userProfile = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$fullName = trim($userProfile['name'] ?? $currentUser['name'] ?? 'Customer');
$nameParts = explode(' ', $fullName, 2);
$firstName = $nameParts[0] ?? 'Customer';
$lastName  = $nameParts[1] ?? 'Customer';

$email   = trim($userProfile['email'] ?? $currentUser['email'] ?? 'customer@example.com');
$phone   = trim($order['contact_phone'] ?? $userProfile['phone'] ?? '0770000000');
$address = trim($order['shipping_address'] ?? $userProfile['address'] ?? 'Sri Lanka');
$city    = 'Colombo'; // Default city if not separately stored

// PayHere Configurations
$payhereMode    = defined('PAYHERE_MODE') ? PAYHERE_MODE : 'sandbox';
$merchantId     = defined('PAYHERE_MERCHANT_ID') ? PAYHERE_MERCHANT_ID : '';
$merchantSecret = defined('PAYHERE_MERCHANT_SECRET') ? PAYHERE_MERCHANT_SECRET : '';

$payhereCheckoutUrl = ($payhereMode === 'live')
    ? 'https://www.payhere.lk/pay/checkout'
    : 'https://sandbox.payhere.lk/pay/checkout';

$amountFormatted = number_format((float) $order['total_amount'], 2, '.', '');
$currency = 'LKR';

// PayHere Hash Generation:
// hash = strtoupper(md5(merchant_id + order_id + amount + currency + strtoupper(md5(merchant_secret))))
$hashedSecret = strtoupper(md5($merchantSecret));
$payhereHash  = strtoupper(md5($merchantId . $order['order_number'] . $amountFormatted . $currency . $hashedSecret));

$returnUrl = APP_URL . '/payments/success.php?gateway=payhere&order_id=' . $order['id'];
$cancelUrl = APP_URL . '/payments/cancel.php?order_id=' . $order['id'];
$notifyUrl = APP_URL . '/payments/payhere-notify.php';

// Log pending PayHere transaction in payments table
$updatePayStmt = $pdo->prepare("
    UPDATE payments 
    SET payment_method = 'payhere',
        transaction_id = ?,
        payment_payload = ? 
    WHERE order_id = ?
");
$updatePayStmt->execute([
    'PENDING-PH-' . $order['order_number'],
    json_encode([
        'gateway'      => 'payhere',
        'order_number' => $order['order_number'],
        'amount'       => $amountFormatted,
        'mode'         => $payhereMode,
        'initiated_at' => date('c')
    ], JSON_UNESCAPED_SLASHES),
    $order['id']
]);

// Check if credentials are placeholder
$isPlaceholder = empty($merchantId)
    || empty($merchantSecret)
    || str_contains($merchantSecret, 'placeholder');

// If real credentials provided, auto-submit to PayHere Checkout Gateway
if (!$isPlaceholder) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Connecting to PayHere Secure Payment Gateway...</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-gray-50 flex items-center justify-center min-h-screen p-4" onload="document.getElementById('payhere_form').submit();">
        <div class="max-w-md w-full bg-white border border-gray-200 rounded-3xl p-8 text-center shadow-lg">
            <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-teal-50 text-teal-600 flex items-center justify-center animate-pulse">
                <svg class="w-8 h-8 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                </svg>
            </div>
            <h2 class="text-xl font-black text-gray-900 mb-1">Redirecting to PayHere</h2>
            <p class="text-sm text-gray-500 mb-6">Connecting you to Sri Lanka's official payment gateway. Please do not refresh.</p>

            <div class="p-4 rounded-2xl bg-gray-50 border border-gray-100 text-left text-xs space-y-1 mb-6">
                <div class="flex justify-between"><span class="text-gray-400">Order:</span> <span class="font-bold text-gray-800"><?= htmlspecialchars($order['order_number']) ?></span></div>
                <div class="flex justify-between"><span class="text-gray-400">Total:</span> <span class="font-bold text-gray-800">Rs. <?= $amountFormatted ?></span></div>
            </div>

            <form id="payhere_form" method="POST" action="<?= htmlspecialchars($payhereCheckoutUrl) ?>">
                <input type="hidden" name="merchant_id" value="<?= htmlspecialchars($merchantId) ?>">
                <input type="hidden" name="return_url" value="<?= htmlspecialchars($returnUrl) ?>">
                <input type="hidden" name="cancel_url" value="<?= htmlspecialchars($cancelUrl) ?>">
                <input type="hidden" name="notify_url" value="<?= htmlspecialchars($notifyUrl) ?>">
                <input type="hidden" name="order_id" value="<?= htmlspecialchars($order['order_number']) ?>">
                <input type="hidden" name="items" value="Order <?= htmlspecialchars($order['order_number']) ?>">
                <input type="hidden" name="currency" value="<?= htmlspecialchars($currency) ?>">
                <input type="hidden" name="amount" value="<?= htmlspecialchars($amountFormatted) ?>">
                <input type="hidden" name="first_name" value="<?= htmlspecialchars($firstName) ?>">
                <input type="hidden" name="last_name" value="<?= htmlspecialchars($lastName) ?>">
                <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                <input type="hidden" name="phone" value="<?= htmlspecialchars($phone) ?>">
                <input type="hidden" name="address" value="<?= htmlspecialchars($address) ?>">
                <input type="hidden" name="city" value="<?= htmlspecialchars($city) ?>">
                <input type="hidden" name="country" value="Sri Lanka">
                <input type="hidden" name="hash" value="<?= htmlspecialchars($payhereHash) ?>">
                <input type="hidden" name="custom_1" value="<?= (int) $order['id'] ?>">
                <input type="hidden" name="custom_2" value="<?= (int) $userId ?>">

                <button type="submit" class="text-xs text-teal-600 font-semibold hover:underline">
                    Click here if you are not redirected automatically
                </button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Development Simulation Screen (Rendered when test placeholder keys are detected)
$simulatedPaymentId = 'PH_SIM_' . strtoupper(bin2hex(random_bytes(6)));
$pageTitle = 'PayHere Sri Lanka (Test Mode)';
require_once __DIR__ . '/../includes/header.php';
?>

<main class="flex-1 bg-gray-50 py-12">
    <div class="max-w-2xl mx-auto px-4 sm:px-6">
        
        <div class="bg-white border border-gray-200 rounded-3xl shadow-sm overflow-hidden">
            <!-- Header bar with PayHere Sri Lanka branding -->
            <div class="bg-gradient-to-r from-[#00487C] to-[#00B5B8] px-8 py-6 text-white flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-white/20 flex items-center justify-center font-black text-lg">
                        <i data-lucide="credit-card" class="w-6 h-6 text-white"></i>
                    </div>
                    <div>
                        <h1 class="text-xl font-bold flex items-center gap-2">
                            PayHere Sri Lanka
                        </h1>
                        <p class="text-xs text-white/80">Cards, eZ Cash, mCash, FriMi, Genie & Internet Banking</p>
                    </div>
                </div>
                <span class="px-3 py-1 bg-white/20 rounded-full text-xs font-semibold uppercase tracking-wider">
                    Sandbox
                </span>
            </div>

            <div class="p-8 space-y-6">
                <!-- Info Notice -->
                <div class="p-4 rounded-2xl bg-teal-50/70 border border-teal-100 text-sm text-teal-950">
                    <div class="flex items-center gap-2 font-bold text-teal-900 mb-1">
                        <i data-lucide="info" class="w-4 h-4 text-[#00B5B8]"></i>
                        Developer Test Simulation
                    </div>
                    Placeholder PayHere credentials are detected in your <code class="font-mono text-xs bg-teal-100 px-1.5 py-0.5 rounded">.env</code> file. You can simulate an approved PayHere Sri Lanka payment below. Once your official PayHere Merchant ID and Secret are configured, customers will automatically be redirected to the secure PayHere checkout page.
                </div>

                <!-- Payment Methods Supported -->
                <div class="p-4 rounded-2xl bg-gray-50 border border-gray-200 text-xs text-gray-600">
                    <div class="font-bold text-gray-800 mb-2 flex items-center gap-1.5">
                        <i data-lucide="shield-check" class="w-4 h-4 text-teal-600"></i>
                        PayHere Supported Sri Lankan Payment Methods:
                    </div>
                    <div class="flex flex-wrap gap-2 text-[11px] font-medium text-gray-700">
                        <span class="bg-white border border-gray-200 px-2 py-1 rounded-md">Visa / Mastercard</span>
                        <span class="bg-white border border-gray-200 px-2 py-1 rounded-md">American Express</span>
                        <span class="bg-white border border-gray-200 px-2 py-1 rounded-md">eZ Cash</span>
                        <span class="bg-white border border-gray-200 px-2 py-1 rounded-md">mCash</span>
                        <span class="bg-white border border-gray-200 px-2 py-1 rounded-md">FriMi</span>
                        <span class="bg-white border border-gray-200 px-2 py-1 rounded-md">Genie</span>
                        <span class="bg-white border border-gray-200 px-2 py-1 rounded-md">Sampath Vishwa</span>
                    </div>
                </div>

                <!-- Order Summary Breakdown -->
                <div class="border border-gray-100 rounded-2xl p-5 bg-gray-50/50 space-y-3">
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500">Order Number</span>
                        <span class="font-mono font-bold text-gray-900"><?= htmlspecialchars($order['order_number']) ?></span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500">Customer</span>
                        <span class="font-semibold text-gray-900"><?= htmlspecialchars($fullName) ?> (<?= htmlspecialchars($email) ?>)</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500">Currency</span>
                        <span class="font-semibold text-gray-700">Sri Lankan Rupee (LKR)</span>
                    </div>
                    <div class="pt-3 border-t border-gray-200 flex justify-between items-center">
                        <span class="text-base font-bold text-gray-900">Total Charge</span>
                        <span class="text-2xl font-black text-teal-700">Rs. <?= $amountFormatted ?></span>
                    </div>
                </div>

                <!-- Simulation Action Buttons -->
                <div class="space-y-3 pt-2">
                    <a href="<?= APP_URL ?>/payments/success.php?gateway=payhere&payment_id=<?= urlencode($simulatedPaymentId) ?>&order_id=<?= (int)$order['id'] ?>"
                        class="w-full inline-flex items-center justify-center gap-2 bg-[#00B5B8] hover:bg-[#009c9f] text-white py-3.5 px-6 rounded-xl font-bold text-sm shadow-sm transition">
                        <i data-lucide="check-circle-2" class="w-4 h-4"></i>
                        Simulate Successful PayHere Payment (Rs. <?= $amountFormatted ?> LKR)
                    </a>

                    <a href="<?= APP_URL ?>/payments/cancel.php?order_id=<?= (int)$order['id'] ?>"
                        class="w-full inline-flex items-center justify-center gap-2 bg-white hover:bg-gray-100 border border-gray-300 text-gray-700 py-3.5 px-6 rounded-xl font-semibold text-sm transition">
                        <i data-lucide="x-circle" class="w-4 h-4 text-red-500"></i>
                        Cancel Payment & Return to Cart
                    </a>
                </div>
            </div>

            <!-- Footer note -->
            <div class="px-8 py-4 bg-gray-50 border-t border-gray-100 text-xs text-gray-500 flex items-center justify-between">
                <span>Thirasara Max Payments</span>
                <span>Powered by PayHere Sri Lanka</span>
            </div>
        </div>

    </div>
</main>

<script>
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
