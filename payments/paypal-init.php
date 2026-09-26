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

$db = Database::getConnection();

// Fetch Order
$orderStmt = $db->prepare("
    SELECT id, order_number, user_id, total_amount, payment_status, order_status, shipping_address
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

// Convert LKR total to USD for PayPal
$exchangeRate = defined('USD_EXCHANGE_RATE') && USD_EXCHANGE_RATE > 0 ? USD_EXCHANGE_RATE : 305.00;
$totalLkr = (float) $order['total_amount'];
$totalUsd = number_format(max(0.01, round($totalLkr / $exchangeRate, 2)), 2, '.', '');

// PayPal API Credentials
$paypalMode   = defined('PAYPAL_MODE') ? PAYPAL_MODE : 'sandbox';
$clientId     = defined('PAYPAL_CLIENT_ID') ? PAYPAL_CLIENT_ID : '';
$clientSecret = defined('PAYPAL_CLIENT_SECRET') ? PAYPAL_CLIENT_SECRET : '';

$isPlaceholder = empty($clientId) 
    || empty($clientSecret) 
    || str_contains($clientId, 'placeholder') 
    || str_contains($clientSecret, 'placeholder');

// If real credentials provided, communicate with PayPal REST API
if (!$isPlaceholder) {
    $apiBase = ($paypalMode === 'live') ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';

    // 1. Obtain OAuth2 Access Token
    $ch = curl_init($apiBase . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_USERPWD        => $clientId . ':' . $clientSecret,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Accept-Language: en_US'
        ],
        CURLOPT_TIMEOUT        => 30
    ]);

    $authResponse = curl_exec($ch);
    $authHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $authData = json_decode((string) $authResponse, true);
    $accessToken = $authData['access_token'] ?? null;

    if ($authHttpCode === 200 && !empty($accessToken)) {
        // 2. Create PayPal Order
        $orderPayload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'reference_id' => (string) $order['id'],
                    'description'  => 'Order ' . $order['order_number'],
                    'amount' => [
                        'currency_code' => 'USD',
                        'value'         => $totalUsd
                    ]
                ]
            ],
            'application_context' => [
                'brand_name'          => APP_NAME,
                'landing_page'        => 'NO_PREFERENCE',
                'user_action'         => 'PAY_NOW',
                'return_url'          => APP_URL . '/payments/success.php?gateway=paypal&order_id=' . $order['id'],
                'cancel_url'          => APP_URL . '/payments/cancel.php?order_id=' . $order['id']
            ]
        ];

        $chOrder = curl_init($apiBase . '/v2/checkout/orders');
        curl_setopt_array($chOrder, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($orderPayload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken
            ],
            CURLOPT_TIMEOUT        => 30
        ]);

        $orderResponse = curl_exec($chOrder);
        $orderHttpCode = curl_getinfo($chOrder, CURLINFO_HTTP_CODE);
        curl_close($chOrder);

        $orderData = json_decode((string) $orderResponse, true);

        if ($orderHttpCode === 201 && isset($orderData['id'])) {
            $paypalOrderId = $orderData['id'];

            // Log pending transaction ID
            $updatePayStmt = $db->prepare("
                UPDATE payments 
                SET transaction_id = ?, payment_payload = ? 
                WHERE order_id = ?
            ");
            $updatePayStmt->execute([
                $paypalOrderId,
                json_encode([
                    'paypal_order_id' => $paypalOrderId,
                    'amount_usd'      => $totalUsd,
                    'exchange_rate'   => $exchangeRate,
                    'created_at'      => date('c')
                ]),
                $order['id']
            ]);

            // Locate approval URL
            $approvalUrl = null;
            foreach ($orderData['links'] ?? [] as $link) {
                if (($link['rel'] ?? '') === 'approve') {
                    $approvalUrl = $link['href'];
                    break;
                }
            }

            if ($approvalUrl) {
                header('Location: ' . $approvalUrl);
                exit;
            }
        }

        error_log('PayPal Order Creation Failed: ' . (string) $orderResponse);
        setFlash('error', 'PayPal initialization failed. Please try another payment method.');
        header('Location: ' . APP_URL . '/checkout.php');
        exit;
    }
}

// Development Simulation Screen (Rendered when test placeholder keys are detected)
$simulatedToken = 'SIMULATED_PAYPAL_' . bin2hex(random_bytes(10));
$pageTitle = 'PayPal Gateway (Test Mode)';
require_once __DIR__ . '/../includes/header.php';
?>

<main class="flex-1 bg-gray-50 py-12">
    <div class="max-w-2xl mx-auto px-4 sm:px-6">
        
        <div class="bg-white border border-gray-200 rounded-3xl shadow-sm overflow-hidden">
            <!-- Header bar with PayPal branding -->
            <div class="bg-[#003087] px-8 py-6 text-white flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-white/20 flex items-center justify-center font-bold text-lg">
                        <i data-lucide="wallet" class="w-6 h-6 text-[#0079C1]"></i>
                    </div>
                    <div>
                        <h1 class="text-xl font-bold">PayPal Checkout</h1>
                        <p class="text-xs text-white/80">Sandbox / Developer Testing Mode</p>
                    </div>
                </div>
                <span class="px-3 py-1 bg-[#0079C1] rounded-full text-xs font-semibold uppercase tracking-wider">
                    Sandbox
                </span>
            </div>

            <div class="p-8 space-y-6">
                <!-- Info Notice -->
                <div class="p-4 rounded-2xl bg-sky-50/70 border border-sky-100 text-sm text-sky-950">
                    <div class="flex items-center gap-2 font-bold text-sky-900 mb-1">
                        <i data-lucide="info" class="w-4 h-4 text-[#0079C1]"></i>
                        Developer Test Simulation
                    </div>
                    Placeholder PayPal keys are detected in your <code class="font-mono text-xs bg-sky-100 px-1.5 py-0.5 rounded">.env</code> file. You can simulate an approved PayPal transaction below. When you configure live/sandbox credentials, this page will automatically redirect to PayPal's official approval flow.
                </div>

                <!-- Currency Conversion Notice -->
                <div class="p-4 rounded-2xl bg-amber-50/80 border border-amber-200 text-xs text-amber-900 flex items-start gap-2.5">
                    <i data-lucide="refresh-cw" class="w-4 h-4 mt-0.5 text-amber-700 shrink-0"></i>
                    <div>
                        <span class="font-bold">PayPal LKR Currency Conversion:</span> PayPal does not process Sri Lankan Rupees directly. The order total has been converted to USD at the configured exchange rate (1 USD = <?= number_format($exchangeRate, 2) ?> LKR).
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
                        <span class="font-semibold text-gray-900"><?= htmlspecialchars($currentUser['name']) ?> (<?= htmlspecialchars($currentUser['email']) ?>)</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500">LKR Amount</span>
                        <span class="font-semibold text-gray-700">Rs. <?= number_format($totalLkr, 2) ?></span>
                    </div>
                    <div class="pt-3 border-t border-gray-200 flex justify-between items-center">
                        <span class="text-base font-bold text-gray-900">Converted USD Total</span>
                        <span class="text-2xl font-black text-[#003087]">$<?= $totalUsd ?> USD</span>
                    </div>
                </div>

                <!-- Simulation Action Buttons -->
                <div class="space-y-3 pt-2">
                    <a href="<?= APP_URL ?>/payments/success.php?gateway=paypal&token=<?= urlencode($simulatedToken) ?>&order_id=<?= (int)$order['id'] ?>"
                        class="w-full inline-flex items-center justify-center gap-2 bg-[#FFC439] hover:bg-[#F2BA36] text-gray-900 py-3.5 px-6 rounded-xl font-bold text-sm shadow-sm transition">
                        <i data-lucide="check-circle-2" class="w-4 h-4"></i>
                        Simulate PayPal Approval ($<?= $totalUsd ?> USD)
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
                <span>Powered by PayPal REST API</span>
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
