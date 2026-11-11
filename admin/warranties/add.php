<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/warranty_helpers.php';

requireAuth(['admin', 'staff']);

$currentUser = getCurrentUser();
$pdo = Database::getConnection();

$error = '';
$success = '';

// Fetch all registered customers
$customers = $pdo->query("
    SELECT id, name, email, phone 
    FROM users 
    WHERE role = 'customer' 
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all available products with their specific warranty_period_months
$products = $pdo->query("
    SELECT id, name, category, brand, price, COALESCE(warranty_period_months, 6) AS warranty_period_months 
    FROM products 
    WHERE status != 'discontinued'
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerMode = trim($_POST['customer_mode'] ?? 'existing');
    $userId       = (int) ($_POST['user_id'] ?? 0);
    $warrantyType = trim($_POST['warranty_type'] ?? 'product');
    $productId    = !empty($_POST['product_id']) ? (int) $_POST['product_id'] : null;
    $startDate    = trim($_POST['start_date'] ?? date('Y-m-d'));
    $duration     = (int) ($_POST['duration_months'] ?? 6);
    $status       = trim($_POST['status'] ?? 'active');

    // Handle Walk-In Customer creation
    if ($customerMode === 'walkin') {
        $walkinName    = trim($_POST['walkin_name'] ?? '');
        $walkinPhone   = trim($_POST['walkin_phone'] ?? '');
        $walkinEmail   = trim($_POST['walkin_email'] ?? '');
        $walkinAddress = trim($_POST['walkin_address'] ?? '');

        if ($walkinName === '') {
            $error = 'Customer name is required for walk-in registration.';
        } elseif ($walkinPhone === '') {
            $error = 'Customer phone number is required for in-store walk-in customers.';
        } else {
            $userId = getOrCreateCustomer($pdo, $walkinName, $walkinPhone, $walkinEmail, $walkinAddress);
        }
    }

    if ($error === '') {
        if ($userId <= 0) {
            $error = 'Please select or register a customer.';
        } elseif ($warrantyType === 'product' && empty($productId)) {
            $error = 'Please select a product for product warranty.';
        } elseif ($duration <= 0) {
            $error = 'Warranty duration in months must be greater than 0.';
        } elseif (!strtotime($startDate)) {
            $error = 'Invalid start date provided.';
        } else {
            try {
                $endDate = date('Y-m-d', strtotime("+{$duration} months", strtotime($startDate)));
                if ($endDate < date('Y-m-d') && $status === 'active') {
                    $status = 'expired';
                }

                $prefix = ($warrantyType === 'repair') ? 'TM-REP' : 'TM-WAR';
                $code = generateWarrantyCode($prefix);

                // Double check uniqueness
                $chk = $pdo->prepare("SELECT id FROM warranties WHERE warranty_code = ?");
                $chk->execute([$code]);
                if ($chk->fetch()) {
                    $code = generateWarrantyCode($prefix);
                }

                $ins = $pdo->prepare("
                    INSERT INTO warranties (
                        warranty_code, user_id, product_id, warranty_type, 
                        start_date, end_date, status, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                ");
                $ins->execute([
                    $code,
                    $userId,
                    $productId,
                    $warrantyType,
                    $startDate,
                    $endDate,
                    $status
                ]);

                $dest = (($currentUser['role'] ?? '') === 'staff')
                    ? APP_URL . '/staff/warranties.php?created=' . urlencode($code)
                    : APP_URL . '/admin/warranties/index.php?created=' . urlencode($code);
                header('Location: ' . $dest);
                exit;

            } catch (Throwable $e) {
                $error = 'Failed to register warranty: ' . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Register Manual Warranty';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> | <?php echo APP_NAME; ?> Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif']
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
        }
    </script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="<?php echo APP_URL; ?>/assets/js/main.js" defer></script>
</head>

<body class="bg-gray-100 text-gray-800 font-sans antialiased">
    <div class="flex min-h-screen">
        <?php 
        if (($currentUser['role'] ?? '') === 'staff') {
            require_once __DIR__ . '/../../includes/staff_sidebar.php';
        } else {
            require_once __DIR__ . '/../../includes/sidebar.php';
        }
        ?>

        <div class="flex-1 flex flex-col min-w-0">
            <!-- TOP BAR -->
            <header class="bg-white border-b border-gray-200 sticky top-0 z-20 flex items-center gap-4 px-6 h-16 flex-shrink-0">
                <button id="sidebar-open-btn" type="button" class="lg:hidden text-gray-500 hover:text-gray-900" aria-label="Open sidebar">
                    <i data-lucide="menu" class="w-5 h-5"></i>
                </button>
                <div class="flex-1 min-w-0 flex items-center gap-2 text-sm">
                    <span class="text-gray-400 font-medium hidden sm:inline">Admin</span>
                    <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-gray-300 hidden sm:inline flex-shrink-0"></i>
                    <a href="index.php" class="text-gray-500 hover:text-gray-800 font-medium">Warranties</a>
                    <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-gray-300 hidden sm:inline flex-shrink-0"></i>
                    <span class="font-bold text-gray-900">Register Manual Warranty</span>
                </div>
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center text-white text-sm font-bold flex-shrink-0">
                        <?php echo strtoupper(substr($currentUser['name'] ?? 'A', 0, 1)); ?>
                    </div>
                </div>
            </header>

            <!-- MAIN CONTENT -->
            <main class="flex-1 p-6 sm:p-8 max-w-4xl mx-auto w-full">
                <div class="mb-6 flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-black text-gray-900 tracking-tight">Manual Warranty Issuance</h1>
                        <p class="text-xs text-gray-500 mt-1">Issue warranty certificates for in-store walk-in sales or extended service contracts.</p>
                    </div>
                    <a href="index.php" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-gray-300 bg-white text-xs font-bold text-gray-700 hover:bg-gray-50 transition shadow-sm">
                        <i data-lucide="arrow-left" class="w-4 h-4"></i> Back to Warranties
                    </a>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="mb-6 p-4 rounded-xl bg-red-50 border border-red-200 text-red-800 text-xs font-semibold flex items-center gap-2.5">
                        <i data-lucide="alert-circle" class="w-4 h-4 flex-shrink-0"></i>
                        <p><?php echo htmlspecialchars($error); ?></p>
                    </div>
                <?php endif; ?>

                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm p-6 sm:p-8">
                    <form method="POST" action="" class="space-y-6">

                        <!-- Customer Selection (Dual Mode: Existing or In-Store Walk-in) -->
                        <div class="bg-gray-50 border border-gray-200 rounded-2xl p-5">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                                <div>
                                    <span class="text-xs font-bold uppercase tracking-wider text-gray-800">Customer Identification</span>
                                    <p class="text-[11px] text-gray-500">Select an existing account or register a walk-in visitor instantly.</p>
                                </div>
                                <div class="flex items-center gap-2 bg-white p-1 rounded-xl border border-gray-200 text-xs font-bold self-start sm:self-auto">
                                    <label class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg cursor-pointer transition text-gray-700 has-[:checked]:bg-brand-red has-[:checked]:text-white">
                                        <input type="radio" name="customer_mode" value="existing" checked class="hidden" onchange="toggleCustomerMode('existing')">
                                        <i data-lucide="user-check" class="w-3.5 h-3.5"></i> Existing User
                                    </label>
                                    <label class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg cursor-pointer transition text-gray-700 has-[:checked]:bg-brand-red has-[:checked]:text-white">
                                        <input type="radio" name="customer_mode" value="walkin" class="hidden" onchange="toggleCustomerMode('walkin')">
                                        <i data-lucide="user-plus" class="w-3.5 h-3.5"></i> New Walk-In
                                    </label>
                                </div>
                            </div>

                            <!-- Mode 1: Existing Customer Dropdown -->
                            <div id="existingCustomerSection">
                                <label for="user_id" class="block text-xs font-semibold text-gray-700 mb-1.5">
                                    Select Registered Customer <span class="text-brand-red">*</span>
                                </label>
                                <select id="user_id" name="user_id" required class="w-full px-3.5 py-2.5 rounded-xl border border-gray-300 bg-white text-xs font-semibold text-gray-800 focus:outline-none focus:border-brand-red">
                                    <option value="">-- Choose Customer --</option>
                                    <?php foreach ($customers as $c): ?>
                                        <option value="<?php echo (int) $c['id']; ?>">
                                            <?php echo htmlspecialchars($c['name']); ?> (<?php echo htmlspecialchars($c['phone'] ?? $c['email']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Mode 2: New Walk-In Customer Input -->
                            <div id="walkinCustomerSection" class="hidden space-y-3 pt-2 border-t border-gray-200 mt-2">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <label for="walkin_name" class="block text-xs font-semibold text-gray-700 mb-1">
                                            Customer Full Name <span class="text-brand-red">*</span>
                                        </label>
                                        <input type="text" id="walkin_name" name="walkin_name" placeholder="e.g. Kasun Perera"
                                               class="w-full px-3 py-2 rounded-xl border border-gray-300 bg-white text-xs font-semibold text-gray-800 focus:outline-none focus:border-brand-red">
                                    </div>
                                    <div>
                                        <label for="walkin_phone" class="block text-xs font-semibold text-gray-700 mb-1">
                                            Phone Number <span class="text-brand-red">*</span>
                                        </label>
                                        <input type="tel" id="walkin_phone" name="walkin_phone" placeholder="07XXXXXXXX"
                                               class="w-full px-3 py-2 rounded-xl border border-gray-300 bg-white text-xs font-semibold text-gray-800 focus:outline-none focus:border-brand-red">
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <label for="walkin_email" class="block text-xs font-semibold text-gray-700 mb-1">
                                            Email Address <span class="text-gray-400 font-normal">(Optional)</span>
                                        </label>
                                        <input type="email" id="walkin_email" name="walkin_email" placeholder="customer@example.com"
                                               class="w-full px-3 py-2 rounded-xl border border-gray-300 bg-white text-xs font-semibold text-gray-800 focus:outline-none focus:border-brand-red">
                                    </div>
                                    <div>
                                        <label for="walkin_address" class="block text-xs font-semibold text-gray-700 mb-1">
                                            Address / City <span class="text-gray-400 font-normal">(Optional)</span>
                                        </label>
                                        <input type="text" id="walkin_address" name="walkin_address" placeholder="e.g. Colombo 03"
                                               class="w-full px-3 py-2 rounded-xl border border-gray-300 bg-white text-xs font-semibold text-gray-800 focus:outline-none focus:border-brand-red">
                                    </div>
                                </div>
                                <p class="text-[11px] text-gray-500 flex items-center gap-1.5">
                                    <i data-lucide="info" class="w-3.5 h-3.5 text-indigo-600 flex-shrink-0"></i>
                                    A customer account will automatically be created and linked to this warranty. The customer can track this warranty using their phone number on the verification portal.
                                </p>
                            </div>
                        </div>

                        <!-- Warranty Type -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="warranty_type" class="block text-xs font-bold uppercase tracking-wider text-gray-700 mb-1.5">
                                    Warranty Type <span class="text-brand-red">*</span>
                                </label>
                                <select id="warranty_type" name="warranty_type" required class="w-full px-3.5 py-2.5 rounded-xl border border-gray-300 text-xs font-semibold text-gray-800 focus:outline-none focus:border-brand-red">
                                    <option value="product" selected>Product Purchase Warranty</option>
                                    <option value="repair">Repair Service Warranty</option>
                                </select>
                            </div>

                            <div>
                                <label for="status" class="block text-xs font-bold uppercase tracking-wider text-gray-700 mb-1.5">
                                    Initial Status
                                </label>
                                <select id="status" name="status" class="w-full px-3.5 py-2.5 rounded-xl border border-gray-300 text-xs font-semibold text-gray-800 focus:outline-none focus:border-brand-red">
                                    <option value="active" selected>Active</option>
                                    <option value="claimed">Claimed</option>
                                    <option value="expired">Expired</option>
                                    <option value="void">Void</option>
                                </select>
                            </div>
                        </div>

                        <!-- Product Selection -->
                        <div id="productSelectWrapper">
                            <label for="product_id" class="block text-xs font-bold uppercase tracking-wider text-gray-700 mb-1.5">
                                Select Product (Loads Product Warranty Term) <span class="text-brand-red">*</span>
                            </label>
                            <select id="product_id" name="product_id" class="w-full px-3.5 py-2.5 rounded-xl border border-gray-300 text-xs font-semibold text-gray-800 focus:outline-none focus:border-brand-red">
                                <option value="">-- Select Product --</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?php echo (int) $p['id']; ?>" data-months="<?php echo (int) $p['warranty_period_months']; ?>">
                                        <?php echo htmlspecialchars($p['name']); ?> (<?php echo (int) $p['warranty_period_months']; ?> Months Official Warranty)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-[11px] text-gray-500 mt-1">
                                Selecting a product automatically sets the duration to that product's specific warranty period.
                            </p>
                        </div>

                        <!-- Dates and Months -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="start_date" class="block text-xs font-bold uppercase tracking-wider text-gray-700 mb-1.5">
                                    Start / Purchase Date <span class="text-brand-red">*</span>
                                </label>
                                <input type="date" id="start_date" name="start_date" value="<?php echo date('Y-m-d'); ?>" required
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-gray-300 text-xs font-semibold text-gray-800 focus:outline-none focus:border-brand-red">
                            </div>

                            <div>
                                <label for="duration_months" class="block text-xs font-bold uppercase tracking-wider text-gray-700 mb-1.5">
                                    Duration (Months) <span class="text-brand-red">*</span>
                                </label>
                                <input type="number" id="duration_months" name="duration_months" min="1" max="60" value="6" required
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-gray-300 text-xs font-semibold text-gray-800 focus:outline-none focus:border-brand-red">
                            </div>
                        </div>

                        <!-- Estimated Expiry Preview -->
                        <div class="p-4 bg-gray-50 rounded-xl border border-gray-200 text-xs flex items-center justify-between">
                            <span class="text-gray-600 font-semibold">Calculated Expiry Date:</span>
                            <span id="calculatedExpiryDisplay" class="font-bold text-brand-red text-sm">--</span>
                        </div>

                        <!-- Submit Buttons -->
                        <div class="pt-4 flex items-center justify-end gap-3 border-t border-gray-100">
                            <a href="index.php" class="px-5 py-2.5 rounded-xl border border-gray-300 text-gray-700 text-xs font-bold hover:bg-gray-50 transition">
                                Cancel
                            </a>
                            <button type="submit" class="px-6 py-2.5 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-xs font-bold transition shadow-sm">
                                Create Digital Certificate
                            </button>
                        </div>

                    </form>
                </div>
            </main>
        </div>
    </div>

    <script>
    const productSelect = document.getElementById('product_id');
    const durationInput = document.getElementById('duration_months');
    const startDateInput = document.getElementById('start_date');
    const expiryDisplay = document.getElementById('calculatedExpiryDisplay');
    const typeSelect = document.getElementById('warranty_type');
    const productWrapper = document.getElementById('productSelectWrapper');

    function updateExpiry() {
        const start = new Date(startDateInput.value);
        const months = parseInt(durationInput.value, 10) || 0;
        if (!isNaN(start.getTime()) && months > 0) {
            const exp = new Date(start);
            exp.setMonth(exp.getMonth() + months);
            expiryDisplay.textContent = exp.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
        } else {
            expiryDisplay.textContent = '--';
        }
    }

    productSelect.addEventListener('change', function() {
        const selected = this.options[this.selectedIndex];
        const months = selected.getAttribute('data-months');
        if (months) {
            durationInput.value = months;
            updateExpiry();
        }
    });

    typeSelect.addEventListener('change', function() {
        if (this.value === 'repair') {
            durationInput.value = 3; // Standard repair warranty 3 months
            productWrapper.classList.add('opacity-50', 'pointer-events-none');
            productSelect.required = false;
        } else {
            productWrapper.classList.remove('opacity-50', 'pointer-events-none');
            productSelect.required = true;
            const selected = productSelect.options[productSelect.selectedIndex];
            if (selected && selected.getAttribute('data-months')) {
                durationInput.value = selected.getAttribute('data-months');
            } else {
                durationInput.value = 6;
            }
        }
        updateExpiry();
    });

    function toggleCustomerMode(mode) {
        const existingSec = document.getElementById('existingCustomerSection');
        const walkinSec = document.getElementById('walkinCustomerSection');
        const userIdSelect = document.getElementById('user_id');
        const walkinName = document.getElementById('walkin_name');
        const walkinPhone = document.getElementById('walkin_phone');

        if (mode === 'walkin') {
            existingSec.classList.add('hidden');
            walkinSec.classList.remove('hidden');
            userIdSelect.required = false;
            walkinName.required = true;
            walkinPhone.required = true;
        } else {
            existingSec.classList.remove('hidden');
            walkinSec.classList.add('hidden');
            userIdSelect.required = true;
            walkinName.required = false;
            walkinPhone.required = false;
        }
    }

    startDateInput.addEventListener('change', updateExpiry);
    durationInput.addEventListener('input', updateExpiry);
    updateExpiry();
    </script>
</body>
</html>
