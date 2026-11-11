<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/repair_helpers.php';
require_once __DIR__ . '/../../includes/warranty_helpers.php';

requireAuth(['admin', 'staff']);

$currentUser = getCurrentUser();
$pdo = Database::getConnection();

$error = '';
$success = '';

// Fetch active customers
$customers = $pdo->query("
    SELECT id, name, email, phone 
    FROM users 
    WHERE role = 'customer' AND status = 'active'
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch technicians / staff
$technicians = $pdo->query("
    SELECT id, name, email 
    FROM users 
    WHERE role IN ('staff', 'admin') AND status = 'active'
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch store products catalog (store purchased models)
$storeProducts = $pdo->query("
    SELECT id, name, brand, category 
    FROM products 
    ORDER BY brand ASC, name ASC
")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rp_csrf_check($_POST['csrf'] ?? '')) {
        $error = 'Security session expired. Please refresh and try again.';
    } else {
        $customerMode = trim($_POST['customer_mode'] ?? 'existing');
        $userId       = (int)($_POST['user_id'] ?? 0);
        $productId    = (int)($_POST['product_id'] ?? 0);
        $deviceModel  = trim($_POST['device_model'] ?? '');
        $serialImei   = trim($_POST['serial_or_imei'] ?? '');
        $issueDesc    = trim($_POST['issue_description'] ?? '');
        $estCost      = (float)($_POST['estimated_cost'] ?? 0);
        $techId       = (int)($_POST['technician_id'] ?? 0);
        $prefDate     = trim($_POST['preferred_date'] ?? date('Y-m-d', strtotime('+3 days')));
        $isWarranty   = isset($_POST['is_warranty_covered']) ? 1 : 0;
        $diagNotes    = trim($_POST['diagnosis_notes'] ?? '');

        // Auto-fill device model from selected store product if model was blank
        if ($productId > 0 && $deviceModel === '') {
            $pStmt = $pdo->prepare("SELECT name, brand FROM products WHERE id = ? LIMIT 1");
            $pStmt->execute([$productId]);
            if ($prod = $pStmt->fetch(PDO::FETCH_ASSOC)) {
                $deviceModel = trim(($prod['brand'] ? $prod['brand'] . ' ' : '') . $prod['name']);
            }
        }

        // Walk-in customer registration
        if ($customerMode === 'walkin') {
            $walkinName    = trim($_POST['walkin_name'] ?? '');
            $walkinPhone   = trim($_POST['walkin_phone'] ?? '');
            $walkinEmail   = trim($_POST['walkin_email'] ?? '');
            $walkinAddress = trim($_POST['walkin_address'] ?? '');

            if ($walkinName === '') {
                $error = 'Customer name is required for walk-in repair intake.';
            } elseif ($walkinPhone === '') {
                $error = 'Customer phone number is required for tracking and notifications.';
            } else {
                $userId = getOrCreateCustomer($pdo, $walkinName, $walkinPhone, $walkinEmail, $walkinAddress);
            }
        }

        if ($error === '') {
            if ($userId <= 0) {
                $error = 'Please select or register a valid customer.';
            } elseif ($deviceModel === '') {
                $error = 'Device brand and model is required (or select a Store Product Catalog model).';
            } elseif (mb_strlen($issueDesc) < 5) {
                $error = 'Please provide an issue description (minimum 5 characters).';
            } else {
                try {
                    $code = rp_make_code($pdo);
                    $repairStatus = ($techId > 0) ? 'assigned' : 'pending';

                    $stmt = $pdo->prepare("
                        INSERT INTO repairs (
                            repair_code, user_id, technician_id, product_id, device_model, serial_or_imei,
                            issue_description, repair_status, diagnosis_notes, is_warranty_covered,
                            estimated_cost, preferred_date, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");

                    $stmt->execute([
                        $code,
                        $userId,
                        $techId > 0 ? $techId : null,
                        $productId > 0 ? $productId : null,
                        $deviceModel,
                        $serialImei !== '' ? $serialImei : null,
                        $issueDesc,
                        $repairStatus,
                        $diagNotes !== '' ? $diagNotes : null,
                        $isWarranty,
                        $estCost,
                        !empty($prefDate) ? $prefDate : null
                    ]);

                    $repairId = (int)$pdo->lastInsertId();

                    // Notify customer
                    rp_notify(
                        $pdo,
                        $userId,
                        'Repair Intake Created',
                        "Your device {$deviceModel} has been logged for service under reference {$code}."
                    );

                    setFlash('success', "Repair ticket #{$code} created successfully!");
                    header("Location: view.php?id={$repairId}");
                    exit;

                } catch (Throwable $e) {
                    error_log('Admin repair add error: ' . $e->getMessage());
                    $error = 'Failed to create repair ticket: ' . $e->getMessage();
                }
            }
        }
    }
}

$pageTitle = 'New Repair Intake Ticket';
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
                    colors: {
                        brand: {
                            red: '#D32F2F',
                            redHover: '#B71C1C',
                            dark: '#111827',
                            gray: '#F9FAFB'
                        }
                    }
                }
            }
        }
    </script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>

<body class="bg-gray-50 font-sans antialiased text-gray-900">
    <div class="flex h-screen overflow-hidden">
        <?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>

        <div class="flex-1 flex flex-col overflow-y-auto">
            <!-- Header -->
            <header class="bg-white border-b border-gray-200 px-6 py-4 flex items-center justify-between sticky top-0 z-10">
                <div class="flex items-center gap-3">
                    <a href="<?php echo APP_URL; ?>/admin/repairs/index.php" class="p-2 rounded-lg hover:bg-gray-100 text-gray-500 hover:text-gray-900 transition">
                        <i data-lucide="arrow-left" class="w-5 h-5"></i>
                    </a>
                    <div>
                        <h1 class="text-xl font-black uppercase tracking-tight text-gray-900">New Repair Intake</h1>
                        <p class="text-xs text-gray-500">Log customer service and link store purchase</p>
                    </div>
                </div>
            </header>

            <main class="flex-1 p-6 max-w-4xl">
                <?php if ($error): ?>
                    <div class="mb-6 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-bold flex items-center gap-3">
                        <i data-lucide="alert-circle" class="w-5 h-5 flex-shrink-0 text-rose-600"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" class="space-y-6">
                    <?php echo rp_csrf_field(); ?>

                    <!-- 1. Customer Section -->
                    <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
                        <h2 class="text-sm font-black uppercase tracking-wider text-gray-900 mb-4 flex items-center gap-2">
                            <i data-lucide="user" class="w-4 h-4 text-brand-red"></i> 1. Customer Information
                        </h2>

                        <div class="flex gap-4 mb-4">
                            <label class="inline-flex items-center gap-2 text-xs font-bold text-gray-700 cursor-pointer">
                                <input type="radio" name="customer_mode" value="existing" checked onchange="toggleCustomerMode(this.value)" class="text-brand-red focus:ring-brand-red">
                                Existing Registered Customer
                            </label>
                            <label class="inline-flex items-center gap-2 text-xs font-bold text-gray-700 cursor-pointer">
                                <input type="radio" name="customer_mode" value="walkin" onchange="toggleCustomerMode(this.value)" class="text-brand-red focus:ring-brand-red">
                                Walk-in / In-Store Customer
                            </label>
                        </div>

                        <!-- Existing Customer Select -->
                        <div id="existingCustomerBox">
                            <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1.5">Select Registered Customer</label>
                            <select name="user_id" class="w-full h-11 px-3.5 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                                <option value="">-- Choose Customer --</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?php echo (int)$c['id']; ?>">
                                        <?php echo htmlspecialchars($c['name'] . ' (' . ($c['phone'] ?: $c['email']) . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Walk-in Customer Fields -->
                        <div id="walkinCustomerBox" class="hidden grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1.5">Customer Name *</label>
                                <input type="text" name="walkin_name" placeholder="e.g. Ruwan Perera"
                                    class="w-full h-11 px-3.5 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1.5">Phone Number *</label>
                                <input type="text" name="walkin_phone" placeholder="0771234567"
                                    class="w-full h-11 px-3.5 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1.5">Email Address (Optional)</label>
                                <input type="email" name="walkin_email" placeholder="customer@example.com"
                                    class="w-full h-11 px-3.5 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1.5">Postal Address (Optional)</label>
                                <input type="text" name="walkin_address" placeholder="Town, District"
                                    class="w-full h-11 px-3.5 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                            </div>
                        </div>
                    </div>

                    <!-- 2. Device & Issue Details -->
                    <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
                        <h2 class="text-sm font-black uppercase tracking-wider text-gray-900 mb-4 flex items-center gap-2">
                            <i data-lucide="smartphone" class="w-4 h-4 text-brand-red"></i> 2. Device & Fault Information
                        </h2>

                        <!-- Store Product Catalog Link -->
                        <div class="mb-4">
                            <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1.5">Store Product Model (Select store catalog item)</label>
                            <select name="product_id" id="storeProductSelect" onchange="autoFillProduct(this)" class="w-full h-11 px-3.5 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                                <option value="">-- Choose from Store Purchased Product Models --</option>
                                <?php foreach ($storeProducts as $sp): ?>
                                    <option value="<?php echo (int)$sp['id']; ?>" data-name="<?php echo htmlspecialchars(trim(($sp['brand'] ? $sp['brand'] . ' ' : '') . $sp['name'])); ?>">
                                        <?php echo htmlspecialchars(trim(($sp['brand'] ? $sp['brand'] . ' ' : '') . $sp['name'])); ?> (<?php echo htmlspecialchars($sp['category']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1.5">Device Model / Brand *</label>
                                <input type="text" name="device_model" id="deviceModelInput" required placeholder="e.g. Apple iPhone 15 Pro, Samsung Galaxy S23"
                                    class="w-full h-11 px-3.5 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1.5">Serial / IMEI Number</label>
                                <input type="text" name="serial_or_imei" placeholder="15-digit IMEI or Serial number"
                                    class="w-full h-11 px-3.5 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1.5">Reported Issue / Symptoms *</label>
                            <textarea name="issue_description" rows="3" required placeholder="Describe the problem reported by the customer (e.g. broken display, battery draining quickly, no power)..."
                                class="w-full p-3.5 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition"></textarea>
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1.5">Initial Diagnosis Notes</label>
                            <textarea name="diagnosis_notes" rows="2" placeholder="Initial physical inspection observations (e.g. heavy scratches, liquid indicator tripped, frame dented)..."
                                class="w-full p-3.5 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition"></textarea>
                        </div>
                    </div>

                    <!-- 3. Assignment & Costing -->
                    <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
                        <h2 class="text-sm font-black uppercase tracking-wider text-gray-900 mb-4 flex items-center gap-2">
                            <i data-lucide="settings" class="w-4 h-4 text-brand-red"></i> 3. Assignment & Schedule
                        </h2>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1.5">Assign Technician</label>
                                <select name="technician_id" class="w-full h-11 px-3.5 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                                    <option value="">-- Unassigned (Pending Queue) --</option>
                                    <?php foreach ($technicians as $t): ?>
                                        <option value="<?php echo (int)$t['id']; ?>">
                                            <?php echo htmlspecialchars($t['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1.5">Estimated Cost (LKR)</label>
                                <input type="number" step="0.01" min="0" name="estimated_cost" value="0.00"
                                    class="w-full h-11 px-3.5 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1.5">Promised Ready Date</label>
                                <input type="date" name="preferred_date" value="<?php echo date('Y-m-d', strtotime('+3 days')); ?>"
                                    class="w-full h-11 px-3.5 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                            </div>
                        </div>

                        <div class="pt-2">
                            <label class="inline-flex items-center gap-2 text-xs font-bold text-gray-800 cursor-pointer">
                                <input type="checkbox" name="is_warranty_covered" value="1" class="rounded text-brand-red focus:ring-brand-red">
                                <span>Covered by Shop / Manufacturer Warranty (Free of charge)</span>
                            </label>
                        </div>
                    </div>

                    <!-- Submit Actions -->
                    <div class="flex items-center justify-end gap-3 pt-2">
                        <a href="<?php echo APP_URL; ?>/admin/repairs/index.php" class="px-5 py-2.5 rounded-xl border border-gray-200 text-gray-600 text-xs font-bold hover:bg-gray-50 transition">
                            Cancel
                        </a>
                        <button type="submit" class="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl bg-brand-red text-white text-xs font-black uppercase tracking-wider hover:bg-brand-redHover transition shadow-md">
                            <i data-lucide="check" class="w-4 h-4"></i> Create Repair Ticket
                        </button>
                    </div>
                </form>
            </main>
        </div>
    </div>

    <script>
        function toggleCustomerMode(mode) {
            const existingBox = document.getElementById('existingCustomerBox');
            const walkinBox = document.getElementById('walkinCustomerBox');
            if (mode === 'walkin') {
                existingBox.classList.add('hidden');
                walkinBox.classList.remove('hidden');
            } else {
                existingBox.classList.remove('hidden');
                walkinBox.classList.add('hidden');
            }
        }

        function autoFillProduct(sel) {
            const opt = sel.options[sel.selectedIndex];
            if (opt && opt.dataset.name) {
                const input = document.getElementById('deviceModelInput');
                if (input) input.value = opt.dataset.name;
            }
        }

        if (typeof lucide !== 'undefined') lucide.createIcons();
    </script>
</body>

</html>
