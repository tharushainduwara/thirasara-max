<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/repair_helpers.php';
require_once __DIR__ . '/../../includes/warranty_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo         = Database::getConnection();
$currentUser = getCurrentUser();
requireAuth(['admin', 'staff']);

$errors = [];
$name    = '';
$phone   = '';
$email   = '';
$address = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rp_csrf_check($_POST['csrf'] ?? '')) {
        rp_flash_set('error', 'Session expired. Please try again.');
    } else {
        $name    = trim($_POST['name'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if ($name === '') {
            $errors['name'] = 'Full name is required.';
        }

        $cleanPhone = preg_replace('/\D/', '', $phone);
        if ($cleanPhone === '') {
            $errors['phone'] = 'A contact phone number is required.';
        } elseif (strlen($cleanPhone) < 9 || strlen($cleanPhone) > 10) {
            $errors['phone'] = 'Please enter a valid phone number (e.g. 0771234567).';
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address, or leave it blank.';
        }

        if (empty($errors)) {
            try {
                // Check if customer phone already exists
                $chkPhone = $pdo->prepare("SELECT id, name FROM users WHERE phone = ? LIMIT 1");
                $chkPhone->execute([$cleanPhone]);
                $existing = $chkPhone->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    $errors['phone'] = "A customer with this phone number already exists: {$existing['name']}.";
                } elseif ($email !== '') {
                    $chkEmail = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                    $chkEmail->execute([$email]);
                    if ($chkEmail->fetch()) {
                        $errors['email'] = 'A customer with this email address already exists.';
                    }
                }

                if (empty($errors)) {
                    $newCustId = getOrCreateCustomer($pdo, $name, $cleanPhone, $email !== '' ? $email : null, $address !== '' ? $address : null);
                    rp_flash_set('success', "In-store customer '{$name}' registered successfully.");
                    header('Location: ' . APP_URL . '/admin/users/index.php?role=customer');
                    exit;
                }
            } catch (Throwable $e) {
                error_log('Add customer error: ' . $e->getMessage());
                rp_flash_set('error', 'Database error: ' . $e->getMessage());
            }
        }
    }
}

$pageTitle = 'Register In-Store Customer';
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
                    <a href="index.php" class="text-gray-500 hover:text-gray-800 font-medium">Users</a>
                    <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-gray-300 hidden sm:inline flex-shrink-0"></i>
                    <span class="font-bold text-gray-900">Register In-Store Customer</span>
                </div>
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center text-white text-sm font-bold flex-shrink-0">
                        <?php echo strtoupper(substr($currentUser['name'] ?? 'A', 0, 1)); ?>
                    </div>
                </div>
            </header>

            <!-- MAIN CONTENT -->
            <main class="flex-1 p-5 sm:p-7 max-w-3xl mx-auto w-full">

                <div class="flex items-center justify-between gap-4 mb-6">
                    <div>
                        <h1 class="text-2xl font-black text-gray-900 tracking-tight">Register In-Store Customer</h1>
                        <p class="text-xs text-gray-500 mt-1">Add walk-in clients who visit the physical store for purchases, warranties, or repair services.</p>
                    </div>
                    <a href="index.php" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-gray-300 bg-white text-xs font-bold text-gray-700 hover:bg-gray-50 transition shadow-sm">
                        <i data-lucide="arrow-left" class="w-4 h-4"></i> Back to Users
                    </a>
                </div>

                <?php echo rp_flash_render(); ?>

                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm p-6 sm:p-8">
                    <form method="POST" action="" class="space-y-5">
                        <input type="hidden" name="csrf" value="<?php echo rp_e(rp_csrf_token()); ?>">

                        <!-- Customer Name -->
                        <div>
                            <label for="name" class="block text-xs font-bold uppercase tracking-wider text-gray-700 mb-1.5">
                                Customer Full Name <span class="text-brand-red">*</span>
                            </label>
                            <input type="text" id="name" name="name" required value="<?php echo htmlspecialchars($name); ?>"
                                   placeholder="e.g. Ruwan Jayawardena"
                                   class="w-full px-3.5 py-2.5 rounded-xl border <?php echo isset($errors['name']) ? 'border-red-400 bg-red-50' : 'border-gray-300 bg-white'; ?> text-xs font-semibold text-gray-800 focus:outline-none focus:border-brand-red">
                            <?php if (isset($errors['name'])): ?>
                                <p class="text-[11px] text-red-600 mt-1 font-semibold"><?php echo $errors['name']; ?></p>
                            <?php endif; ?>
                        </div>

                        <!-- Phone Number -->
                        <div>
                            <label for="phone" class="block text-xs font-bold uppercase tracking-wider text-gray-700 mb-1.5">
                                Mobile / Contact Phone <span class="text-brand-red">*</span>
                            </label>
                            <input type="tel" id="phone" name="phone" required maxlength="15" value="<?php echo htmlspecialchars($phone); ?>"
                                   placeholder="07XXXXXXXX (Used by customer for warranty tracking)"
                                   class="w-full px-3.5 py-2.5 rounded-xl border <?php echo isset($errors['phone']) ? 'border-red-400 bg-red-50' : 'border-gray-300 bg-white'; ?> text-xs font-semibold text-gray-800 focus:outline-none focus:border-brand-red">
                            <?php if (isset($errors['phone'])): ?>
                                <p class="text-[11px] text-red-600 mt-1 font-semibold"><?php echo $errors['phone']; ?></p>
                            <?php else: ?>
                                <p class="text-[11px] text-gray-400 mt-1">This number will be used to look up warranties and repairs for this client.</p>
                            <?php endif; ?>
                        </div>

                        <!-- Email Address -->
                        <div>
                            <label for="email" class="block text-xs font-bold uppercase tracking-wider text-gray-700 mb-1.5">
                                Email Address <span class="text-gray-400 font-normal lowercase">(optional)</span>
                            </label>
                            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>"
                                   placeholder="customer@example.com (Leave blank if walk-in customer has no email)"
                                   class="w-full px-3.5 py-2.5 rounded-xl border <?php echo isset($errors['email']) ? 'border-red-400 bg-red-50' : 'border-gray-300 bg-white'; ?> text-xs font-semibold text-gray-800 focus:outline-none focus:border-brand-red">
                            <?php if (isset($errors['email'])): ?>
                                <p class="text-[11px] text-red-600 mt-1 font-semibold"><?php echo $errors['email']; ?></p>
                            <?php else: ?>
                                <p class="text-[11px] text-gray-400 mt-1">If blank, an in-store identifier account will be generated automatically.</p>
                            <?php endif; ?>
                        </div>

                        <!-- Address -->
                        <div>
                            <label for="address" class="block text-xs font-bold uppercase tracking-wider text-gray-700 mb-1.5">
                                Address / Town <span class="text-gray-400 font-normal lowercase">(optional)</span>
                            </label>
                            <textarea id="address" name="address" rows="2" placeholder="e.g. 45 Main Street, Colombo 03"
                                      class="w-full px-3.5 py-2.5 rounded-xl border border-gray-300 bg-white text-xs font-semibold text-gray-800 focus:outline-none focus:border-brand-red"><?php echo htmlspecialchars($address); ?></textarea>
                        </div>

                        <!-- Submit Action -->
                        <div class="pt-4 flex items-center justify-end gap-3 border-t border-gray-100">
                            <a href="index.php" class="px-4 py-2.5 rounded-xl border border-gray-300 text-gray-600 text-xs font-bold hover:bg-gray-50 transition">
                                Cancel
                            </a>
                            <button type="submit" class="px-5 py-2.5 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-xs font-bold transition shadow-sm flex items-center gap-1.5">
                                <i data-lucide="user-plus" class="w-4 h-4"></i> Create Customer Account
                            </button>
                        </div>
                    </form>
                </div>
            </main>
        </div>
    </div>
</body>
</html>
