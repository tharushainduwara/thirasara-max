<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

/* Authentication & Access Control */
requireAuth(['customer', 'admin']);

$currentUser = getCurrentUser();
$userId = (int) ($currentUser['id'] ?? 0);
if ($userId <= 0) {
    setFlash('error', 'Session expired. Please sign in again.');
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

$db = Database::getConnection();

/* Fetch Full User Record */
$userStmt = $db->prepare("
    SELECT id, name, email, password, phone, address, role, status, google_id, created_at, updated_at
    FROM users
    WHERE id = ?
    LIMIT 1
");
$userStmt->execute([$userId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    setFlash('error', 'User account not found.');
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

$hasExistingPassword = !empty($user['password']);
$isGoogleUser = !empty($user['google_id']);

/* Handle Form Submissions (POST) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($submittedToken)) {
        setFlash('error', 'Security token mismatch. Please try submitting the form again.');
        header('Location: ' . APP_URL . '/customer/profile.php');
        exit;
    }

    $action = trim($_POST['action'] ?? '');

    /* 1. Update Personal Profile Details */
    if ($action === 'update_profile') {
        $name    = trim($_POST['name'] ?? '');
        $email   = strtolower(trim($_POST['email'] ?? ''));
        $rawPhone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');

        $errors = [];

        // Validate Name
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $errors[] = 'Full Name must be between 2 and 100 characters.';
        }

        // Validate Email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) {
            $errors[] = 'Please enter a valid email address.';
        } else {
            // Check for uniqueness if email changed
            if ($email !== strtolower((string)$user['email'])) {
                $emailCheckStmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
                $emailCheckStmt->execute([$email, $userId]);
                if ($emailCheckStmt->fetch()) {
                    $errors[] = 'This email address is already in use by another account.';
                }
            }
        }

        // Validate Phone (Schema: VARCHAR(10))
        $cleanPhone = null;
        if ($rawPhone !== '') {
            // Strip out non-numeric characters (spaces, dashes, etc.)
            $digitsOnly = preg_replace('/\D/', '', $rawPhone);
            // If phone starts with 94 and is 11 digits (e.g. 94771234567), convert to 0771234567
            if (strlen($digitsOnly) === 11 && str_starts_with($digitsOnly, '94')) {
                $digitsOnly = '0' . substr($digitsOnly, 2);
            }

            if (strlen($digitsOnly) !== 10) {
                $errors[] = 'Phone number must be exactly 10 digits (e.g., 0771234567).';
            } else {
                $cleanPhone = $digitsOnly;
            }
        }

        // Validate Address length
        if (mb_strlen($address) > 500) {
            $errors[] = 'Address cannot exceed 500 characters.';
        }

        if (!empty($errors)) {
            setFlash('error', implode(' ', $errors));
            header('Location: ' . APP_URL . '/customer/profile.php');
            exit;
        }

        try {
            $updateStmt = $db->prepare("
                UPDATE users
                SET name = ?, email = ?, phone = ?, address = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([
                $name,
                $email,
                $cleanPhone,
                $address !== '' ? $address : null,
                $userId
            ]);

            // Sync active session variables
            $_SESSION['user_name']  = $name;
            $_SESSION['user_email'] = $email;

            setFlash('success', 'Your profile details have been successfully updated.');
        } catch (PDOException $e) {
            setFlash('error', 'Failed to update profile. Please try again.');
        }

        header('Location: ' . APP_URL . '/customer/profile.php');
        exit;
    }

    /* 2. Update Password */
    if ($action === 'update_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword     = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        $errors = [];

        // If user already has a password set, require and verify the current password
        if ($hasExistingPassword) {
            if ($currentPassword === '') {
                $errors[] = 'Please enter your current password.';
            } elseif (!password_verify($currentPassword, (string) $user['password'])) {
                $errors[] = 'The current password you entered is incorrect.';
            }
        }

        // Validate New Password
        if (strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters long.';
        } elseif (strlen($newPassword) > 72) {
            $errors[] = 'New password cannot exceed 72 characters.';
        }

        // Verify Match
        if ($newPassword !== $confirmPassword) {
            $errors[] = 'New password and confirmation do not match.';
        }

        if (!empty($errors)) {
            setFlash('error', implode(' ', $errors));
            header('Location: ' . APP_URL . '/customer/profile.php');
            exit;
        }

        try {
            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $pwStmt = $db->prepare("
                UPDATE users
                SET password = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $pwStmt->execute([$newPasswordHash, $userId]);

            setFlash('success', 'Your password has been changed successfully.');
        } catch (PDOException $e) {
            setFlash('error', 'Failed to update password. Please try again.');
        }

        header('Location: ' . APP_URL . '/customer/profile.php');
        exit;
    }

    // Unknown action fallback
    header('Location: ' . APP_URL . '/customer/profile.php');
    exit;
}

/* User Account Summary & KPI Statistics */
$stats = [
    'total_orders'      => 0,
    'active_orders'     => 0,
    'total_spent'       => 0.0,
    'active_repairs'    => 0,
    'active_warranties' => 0
];

try {
    // Orders
    $orderQuery = $db->prepare("
        SELECT
            COUNT(*) AS total_orders,
            COALESCE(SUM(CASE WHEN order_status NOT IN ('delivered', 'cancelled') THEN 1 ELSE 0 END), 0) AS active_orders,
            COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END), 0) AS total_spent
        FROM orders
        WHERE user_id = ?
    ");
    $orderQuery->execute([$userId]);
    if ($row = $orderQuery->fetch(PDO::FETCH_ASSOC)) {
        $stats['total_orders']  = (int) $row['total_orders'];
        $stats['active_orders'] = (int) $row['active_orders'];
        $stats['total_spent']   = (float) $row['total_spent'];
    }

    // Repairs
    $repairQuery = $db->prepare("
        SELECT COALESCE(SUM(CASE WHEN repair_status NOT IN ('completed', 'cancelled') THEN 1 ELSE 0 END), 0) AS active_repairs
        FROM repairs
        WHERE user_id = ?
    ");
    $repairQuery->execute([$userId]);
    if ($row = $repairQuery->fetch(PDO::FETCH_ASSOC)) {
        $stats['active_repairs'] = (int) $row['active_repairs'];
    }

    // Warranties
    $warrantyQuery = $db->prepare("
        SELECT COUNT(*) AS active_warranties
        FROM warranties
        WHERE user_id = ? AND status = 'active'
    ");
    $warrantyQuery->execute([$userId]);
    if ($row = $warrantyQuery->fetch(PDO::FETCH_ASSOC)) {
        $stats['active_warranties'] = (int) $row['active_warranties'];
    }
} catch (Exception $e) {
    // Silently fall back to zeros if tables are empty
}

/* Cart Item Count for Header badge */
$cartCount = 0;
if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        if (is_array($item)) {
            $cartCount += (int) ($item['quantity'] ?? $item['qty'] ?? 0);
        } else {
            $cartCount += (int) $item;
        }
    }
}

$csrfToken = getCsrfToken();
$memberSince = !empty($user['created_at']) ? date('M Y', strtotime($user['created_at'])) : 'Recent';
$userInitials = strtoupper(substr(trim($user['name'] ?? 'U'), 0, 1));
if (str_contains(trim($user['name'] ?? ''), ' ')) {
    $parts = explode(' ', trim($user['name']));
    $userInitials = strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
}

require_once __DIR__ . '/../includes/header.php';
?>

<main class="flex-1 bg-gray-50 py-8 sm:py-10 lg:py-12">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Flash Alert Messages -->
        <?php if (function_exists('displayFlash')): ?>
            <div class="mb-6">
                <?php displayFlash(); ?>
            </div>
        <?php endif; ?>

        <!-- Portal Header & Navigation Banner -->
        <div class="mb-8 bg-white border border-gray-200 rounded-[28px] shadow-sm overflow-hidden">
            <div class="h-2 w-full bg-gradient-to-r from-brand-red via-red-500 to-gray-900"></div>

            <div class="p-6 sm:p-8 lg:p-10">
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
                    
                    <!-- User Avatar & Identity -->
                    <div class="flex items-center gap-5 sm:gap-6">
                        <div class="w-16 h-16 sm:w-20 sm:h-20 rounded-2xl bg-gradient-to-br from-brand-red to-red-700 text-white flex items-center justify-center font-extrabold text-2xl sm:text-3xl shadow-md shadow-brand-red/20 shrink-0">
                            <?= htmlspecialchars($userInitials) ?>
                        </div>

                        <div>
                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                <h1 class="text-2xl sm:text-3xl font-black text-gray-900 tracking-tight">
                                    <?= htmlspecialchars($user['name']) ?>
                                </h1>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold uppercase tracking-wider bg-gray-100 text-gray-800">
                                    <?= htmlspecialchars(ucfirst($user['role'] ?? 'customer')) ?>
                                </span>
                                <?php if ($isGoogleUser): ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 border border-blue-200">
                                        <i data-lucide="check-circle-2" class="w-3.5 h-3.5"></i>
                                        Google Account
                                    </span>
                                <?php endif; ?>
                            </div>

                            <p class="text-sm text-gray-500 flex flex-wrap items-center gap-x-4 gap-y-1">
                                <span class="flex items-center gap-1.5">
                                    <i data-lucide="mail" class="w-4 h-4 text-gray-400"></i>
                                    <?= htmlspecialchars($user['email']) ?>
                                </span>
                                <span class="flex items-center gap-1.5">
                                    <i data-lucide="calendar" class="w-4 h-4 text-gray-400"></i>
                                    Member since <?= htmlspecialchars($memberSince) ?>
                                </span>
                            </p>
                        </div>
                    </div>

                    <!-- Portal Navigation Tabs / Quick Actions -->
                    <div class="flex flex-wrap items-center gap-2.5 pt-4 lg:pt-0 border-t lg:border-t-0 border-gray-100">
                        <a href="<?= APP_URL ?>/customer/dashboard.php"
                            class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-gray-200 bg-white text-gray-700 text-sm font-semibold hover:bg-gray-50 hover:border-gray-300 transition-colors shadow-sm">
                            <i data-lucide="layout-dashboard" class="w-4 h-4 text-gray-500"></i>
                            Dashboard
                        </a>

                        <a href="<?= APP_URL ?>/customer/orders.php"
                            class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-gray-200 bg-white text-gray-700 text-sm font-semibold hover:bg-gray-50 hover:border-gray-300 transition-colors shadow-sm">
                            <i data-lucide="package" class="w-4 h-4 text-gray-500"></i>
                            My Orders
                        </a>

                        <a href="<?= APP_URL ?>/customer/profile.php"
                            class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-gray-900 text-white text-sm font-semibold shadow-sm transition-colors">
                            <i data-lucide="user" class="w-4 h-4 text-brand-red"></i>
                            Profile
                        </a>

                        <a href="<?= APP_URL ?>/products.php"
                            class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-sm font-semibold shadow-sm transition-colors">
                            <i data-lucide="shopping-bag" class="w-4 h-4"></i>
                            Shop
                        </a>
                    </div>

                </div>
            </div>
        </div>

        <!-- Account KPI Overview -->
        <section class="mb-8">
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-5">
                <!-- Orders Placed -->
                <div class="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm hover:border-gray-300 transition">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-bold uppercase tracking-wider text-gray-400">Total Orders</span>
                        <div class="w-9 h-9 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                            <i data-lucide="package" class="w-4 h-4"></i>
                        </div>
                    </div>
                    <div class="text-2xl font-black text-gray-900"><?= $stats['total_orders'] ?></div>
                    <div class="mt-1 text-xs text-gray-500 flex items-center gap-1.5">
                        <span class="inline-block w-2 h-2 rounded-full <?= $stats['active_orders'] > 0 ? 'bg-amber-500 animate-pulse' : 'bg-gray-300' ?>"></span>
                        <?= $stats['active_orders'] ?> currently active
                    </div>
                </div>

                <!-- Total Purchases -->
                <div class="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm hover:border-gray-300 transition">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-bold uppercase tracking-wider text-gray-400">Total Purchases</span>
                        <div class="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                            <i data-lucide="credit-card" class="w-4 h-4"></i>
                        </div>
                    </div>
                    <div class="text-2xl font-black text-gray-900">Rs. <?= number_format($stats['total_spent'], 2) ?></div>
                    <div class="mt-1 text-xs text-emerald-600 font-semibold flex items-center gap-1">
                        <i data-lucide="shield-check" class="w-3.5 h-3.5"></i>
                        Verified Paid Orders
                    </div>
                </div>

                <!-- Active Repairs -->
                <div class="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm hover:border-gray-300 transition">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-bold uppercase tracking-wider text-gray-400">Active Repairs</span>
                        <div class="w-9 h-9 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                            <i data-lucide="wrench" class="w-4 h-4"></i>
                        </div>
                    </div>
                    <div class="text-2xl font-black text-gray-900"><?= $stats['active_repairs'] ?></div>
                    <div class="mt-1 text-xs text-gray-500">
                        <a href="<?= APP_URL ?>/repair-track.php" class="text-brand-red font-medium hover:underline inline-flex items-center gap-0.5">
                            Track repairs <i data-lucide="arrow-right" class="w-3 h-3"></i>
                        </a>
                    </div>
                </div>

                <!-- Active Warranties -->
                <div class="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm hover:border-gray-300 transition">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-bold uppercase tracking-wider text-gray-400">Warranties</span>
                        <div class="w-9 h-9 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center">
                            <i data-lucide="shield" class="w-4 h-4"></i>
                        </div>
                    </div>
                    <div class="text-2xl font-black text-gray-900"><?= $stats['active_warranties'] ?></div>
                    <div class="mt-1 text-xs text-gray-500">
                        <a href="<?= APP_URL ?>/warranty-check.php" class="text-brand-red font-medium hover:underline inline-flex items-center gap-0.5">
                            Check warranty <i data-lucide="arrow-right" class="w-3 h-3"></i>
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <!-- Main Content Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">

            <!-- Left / Main Column: Personal Details & Shipping Address -->
            <div class="lg:col-span-7 space-y-8">
                
                <!-- Personal Information Card -->
                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-6 py-5 border-b border-gray-100 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-red-50 text-brand-red flex items-center justify-center">
                                <i data-lucide="user-check" class="w-5 h-5"></i>
                            </div>
                            <div>
                                <h2 class="text-lg font-bold text-gray-900">Personal Information</h2>
                                <p class="text-xs text-gray-500">Manage your basic identity and default delivery address.</p>
                            </div>
                        </div>
                    </div>

                    <form action="<?= APP_URL ?>/customer/profile.php" method="POST" class="p-6 sm:p-8 space-y-6">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="update_profile">

                        <!-- Full Name -->
                        <div>
                            <label for="name" class="block text-xs font-bold uppercase tracking-wider text-gray-700 mb-2">
                                Full Name <span class="text-brand-red">*</span>
                            </label>
                            <div class="relative rounded-xl shadow-sm">
                                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-gray-400">
                                    <i data-lucide="user" class="w-4 h-4"></i>
                                </div>
                                <input type="text"
                                    name="name"
                                    id="name"
                                    required
                                    minlength="2"
                                    maxlength="100"
                                    value="<?= htmlspecialchars($user['name'] ?? '') ?>"
                                    class="block w-full pl-10 pr-4 py-3 rounded-xl border border-gray-300 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-red focus:border-transparent transition-all"
                                    placeholder="e.g. John Silva">
                            </div>
                        </div>

                        <!-- Email Address -->
                        <div>
                            <label for="email" class="block text-xs font-bold uppercase tracking-wider text-gray-700 mb-2">
                                Email Address <span class="text-brand-red">*</span>
                            </label>
                            <div class="relative rounded-xl shadow-sm">
                                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-gray-400">
                                    <i data-lucide="mail" class="w-4 h-4"></i>
                                </div>
                                <input type="email"
                                    name="email"
                                    id="email"
                                    required
                                    maxlength="100"
                                    value="<?= htmlspecialchars($user['email'] ?? '') ?>"
                                    class="block w-full pl-10 pr-4 py-3 rounded-xl border border-gray-300 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-red focus:border-transparent transition-all"
                                    placeholder="john@example.com">
                            </div>
                            <p class="mt-1.5 text-xs text-gray-500">This email is used for receiving order updates, invoices, and logging in.</p>
                        </div>

                        <!-- Phone Number -->
                        <div>
                            <label for="phone" class="block text-xs font-bold uppercase tracking-wider text-gray-700 mb-2">
                                Contact Phone Number
                            </label>
                            <div class="relative rounded-xl shadow-sm">
                                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-gray-400">
                                    <i data-lucide="phone" class="w-4 h-4"></i>
                                </div>
                                <input type="tel"
                                    name="phone"
                                    id="phone"
                                    maxlength="10"
                                    pattern="[0-9]{10}"
                                    value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
                                    class="block w-full pl-10 pr-4 py-3 rounded-xl border border-gray-300 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-red focus:border-transparent transition-all"
                                    placeholder="0771234567">
                            </div>
                            <p class="mt-1.5 text-xs text-gray-500">10-digit Sri Lankan phone number (e.g. 0771234567) used by courier for deliveries.</p>
                        </div>

                        <!-- Shipping / Delivery Address -->
                        <div>
                            <label for="address" class="block text-xs font-bold uppercase tracking-wider text-gray-700 mb-2">
                                Default Shipping / Delivery Address
                            </label>
                            <div class="relative rounded-xl shadow-sm">
                                <div class="absolute top-3.5 left-3.5 pointer-events-none text-gray-400">
                                    <i data-lucide="map-pin" class="w-4 h-4"></i>
                                </div>
                                <textarea
                                    name="address"
                                    id="address"
                                    rows="3"
                                    maxlength="500"
                                    class="block w-full pl-10 pr-4 py-3 rounded-xl border border-gray-300 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-red focus:border-transparent transition-all"
                                    placeholder="Street Address, Apartment/Suite, City, Postal Code"><?= htmlspecialchars($user['address'] ?? '') ?></textarea>
                            </div>
                            <p class="mt-1.5 text-xs text-gray-500">Auto-fills as your destination shipping address during checkout.</p>
                        </div>

                        <!-- Submit Button -->
                        <div class="pt-4 border-t border-gray-100 flex items-center justify-between">
                            <span class="text-xs text-gray-400">All changes take effect immediately.</span>
                            <button type="submit"
                                class="inline-flex items-center justify-center gap-2 bg-brand-red hover:bg-brand-redHover text-white px-6 py-3 rounded-xl text-sm font-bold shadow-sm transition-all hover:shadow-md">
                                <i data-lucide="save" class="w-4 h-4"></i>
                                Save Changes
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Recent Activity & Quick Support Guide -->
                <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
                    <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wider mb-4 flex items-center gap-2">
                        <i data-lucide="sparkles" class="w-4 h-4 text-brand-red"></i>
                        Customer Portal Shortcuts
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <a href="<?= APP_URL ?>/customer/orders.php"
                            class="p-4 rounded-xl border border-gray-100 bg-gray-50/70 hover:bg-white hover:border-gray-300 hover:shadow-sm transition flex items-start gap-3">
                            <div class="p-2.5 rounded-lg bg-blue-100/60 text-blue-700">
                                <i data-lucide="package-search" class="w-4 h-4"></i>
                            </div>
                            <div>
                                <h4 class="text-sm font-bold text-gray-900">Track Order Status</h4>
                                <p class="text-xs text-gray-500 mt-0.5">View courier progress and printable invoices.</p>
                            </div>
                        </a>

                        <a href="<?= APP_URL ?>/repair-booking.php"
                            class="p-4 rounded-xl border border-gray-100 bg-gray-50/70 hover:bg-white hover:border-gray-300 hover:shadow-sm transition flex items-start gap-3">
                            <div class="p-2.5 rounded-lg bg-red-100/60 text-brand-red">
                                <i data-lucide="wrench" class="w-4 h-4"></i>
                            </div>
                            <div>
                                <h4 class="text-sm font-bold text-gray-900">Book Device Repair</h4>
                                <p class="text-xs text-gray-500 mt-0.5">Schedule technician diagnostics and repair.</p>
                            </div>
                        </a>
                    </div>
                </div>

            </div>

            <!-- Right Column: Password & Account Security -->
            <div class="lg:col-span-5 space-y-8">
                
                <!-- Password & Security Card -->
                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-6 py-5 border-b border-gray-100 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-gray-100 text-gray-700 flex items-center justify-center">
                                <i data-lucide="lock" class="w-5 h-5"></i>
                            </div>
                            <div>
                                <h2 class="text-lg font-bold text-gray-900">Security & Password</h2>
                                <p class="text-xs text-gray-500">Update your account login credentials.</p>
                            </div>
                        </div>
                    </div>

                    <form action="<?= APP_URL ?>/customer/profile.php" method="POST" class="p-6 sm:p-8 space-y-5">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="update_password">

                        <?php if ($hasExistingPassword): ?>
                            <!-- Current Password -->
                            <div>
                                <label for="current_password" class="block text-xs font-bold uppercase tracking-wider text-gray-700 mb-2">
                                    Current Password <span class="text-brand-red">*</span>
                                </label>
                                <div class="relative rounded-xl shadow-sm">
                                    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-gray-400">
                                        <i data-lucide="key-round" class="w-4 h-4"></i>
                                    </div>
                                    <input type="password"
                                        name="current_password"
                                        id="current_password"
                                        required
                                        class="block w-full pl-10 pr-10 py-3 rounded-xl border border-gray-300 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-red focus:border-transparent transition-all"
                                        placeholder="••••••••">
                                    <button type="button"
                                        onclick="togglePasswordVisibility('current_password', this)"
                                        class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-gray-400 hover:text-gray-600 focus:outline-none"
                                        title="Toggle visibility">
                                        <i data-lucide="eye" class="w-4 h-4"></i>
                                    </button>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Google OAuth info if no local password -->
                            <div class="p-4 rounded-xl border border-blue-200 bg-blue-50/70 text-xs text-blue-900 leading-relaxed">
                                <div class="font-bold flex items-center gap-1.5 text-blue-800 mb-1">
                                    <i data-lucide="info" class="w-4 h-4"></i>
                                    Google Sign-In Active
                                </div>
                                You currently log in via your Google account. You can create a direct password below if you wish to also sign in using email and password.
                            </div>
                        <?php endif; ?>

                        <!-- New Password -->
                        <div>
                            <label for="new_password" class="block text-xs font-bold uppercase tracking-wider text-gray-700 mb-2">
                                <?= $hasExistingPassword ? 'New Password' : 'Set Account Password' ?> <span class="text-brand-red">*</span>
                            </label>
                            <div class="relative rounded-xl shadow-sm">
                                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-gray-400">
                                    <i data-lucide="lock" class="w-4 h-4"></i>
                                </div>
                                <input type="password"
                                    name="new_password"
                                    id="new_password"
                                    required
                                    minlength="8"
                                    maxlength="72"
                                    class="block w-full pl-10 pr-10 py-3 rounded-xl border border-gray-300 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-red focus:border-transparent transition-all"
                                    placeholder="At least 8 characters">
                                <button type="button"
                                    onclick="togglePasswordVisibility('new_password', this)"
                                    class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-gray-400 hover:text-gray-600 focus:outline-none"
                                    title="Toggle visibility">
                                    <i data-lucide="eye" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Confirm New Password -->
                        <div>
                            <label for="confirm_password" class="block text-xs font-bold uppercase tracking-wider text-gray-700 mb-2">
                                Confirm New Password <span class="text-brand-red">*</span>
                            </label>
                            <div class="relative rounded-xl shadow-sm">
                                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-gray-400">
                                    <i data-lucide="check" class="w-4 h-4"></i>
                                </div>
                                <input type="password"
                                    name="confirm_password"
                                    id="confirm_password"
                                    required
                                    minlength="8"
                                    maxlength="72"
                                    class="block w-full pl-10 pr-10 py-3 rounded-xl border border-gray-300 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-red focus:border-transparent transition-all"
                                    placeholder="Repeat new password">
                                <button type="button"
                                    onclick="togglePasswordVisibility('confirm_password', this)"
                                    class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-gray-400 hover:text-gray-600 focus:outline-none"
                                    title="Toggle visibility">
                                    <i data-lucide="eye" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Password Helper -->
                        <div class="p-3.5 rounded-xl bg-gray-50 border border-gray-200 text-xs text-gray-600 space-y-1">
                            <div class="font-bold text-gray-700">Password requirements:</div>
                            <ul class="list-disc list-inside space-y-0.5 text-gray-500 pl-1">
                                <li>Minimum 8 characters</li>
                                <li>Both new passwords must match exactly</li>
                            </ul>
                        </div>

                        <!-- Submit Password Button -->
                        <div class="pt-3">
                            <button type="submit"
                                class="w-full inline-flex items-center justify-center gap-2 bg-gray-900 hover:bg-black text-white px-5 py-3 rounded-xl text-sm font-bold shadow-sm transition-all">
                                <i data-lucide="shield-check" class="w-4 h-4"></i>
                                <?= $hasExistingPassword ? 'Update Password' : 'Save Password' ?>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Account Status & Connected Accounts -->
                <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm space-y-5">
                    <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wider flex items-center gap-2">
                        <i data-lucide="shield" class="w-4 h-4 text-gray-500"></i>
                        Account Summary
                    </h3>

                    <div class="divide-y divide-gray-100 text-sm">
                        <div class="py-3 flex items-center justify-between">
                            <span class="text-gray-500">Account ID</span>
                            <span class="font-mono font-bold text-gray-800">#<?= htmlspecialchars((string) $user['id']) ?></span>
                        </div>

                        <div class="py-3 flex items-center justify-between">
                            <span class="text-gray-500">Role</span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold uppercase bg-gray-100 text-gray-800">
                                <?= htmlspecialchars($user['role'] ?? 'customer') ?>
                            </span>
                        </div>

                        <div class="py-3 flex items-center justify-between">
                            <span class="text-gray-500">Status</span>
                            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-full">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                <?= htmlspecialchars(ucfirst($user['status'] ?? 'active')) ?>
                            </span>
                        </div>

                        <div class="py-3 flex items-center justify-between">
                            <span class="text-gray-500">Google OAuth</span>
                            <?php if ($isGoogleUser): ?>
                                <span class="inline-flex items-center gap-1 text-xs font-semibold text-blue-700 bg-blue-50 border border-blue-200 px-2 py-0.5 rounded-full">
                                    <i data-lucide="check" class="w-3 h-3"></i> Linked
                                </span>
                            <?php else: ?>
                                <span class="text-xs text-gray-400">Not linked</span>
                            <?php endif; ?>
                        </div>

                        <div class="py-3 flex items-center justify-between">
                            <span class="text-gray-500">Registration Date</span>
                            <span class="text-xs font-semibold text-gray-700">
                                <?= !empty($user['created_at']) ? date('M d, Y', strtotime($user['created_at'])) : '-' ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Need Help Card -->
                <div class="bg-gradient-to-br from-gray-900 to-black text-white rounded-2xl p-6 shadow-sm">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-9 h-9 rounded-xl bg-white/10 flex items-center justify-center text-brand-red">
                            <i data-lucide="headphones" class="w-5 h-5"></i>
                        </div>
                        <h4 class="font-bold text-white text-base">Need Assistance?</h4>
                    </div>
                    <p class="text-xs text-gray-300 leading-relaxed mb-4">
                        If you need to update account email or request data deletion, contact our support team.
                    </p>
                    <a href="<?= APP_URL ?>/contact.php"
                        class="inline-flex items-center justify-center gap-2 w-full py-2.5 rounded-xl bg-white/10 hover:bg-white/20 border border-white/10 text-white text-xs font-semibold transition">
                        <i data-lucide="message-square" class="w-4 h-4"></i>
                        Contact Customer Support
                    </a>
                </div>

            </div>

        </div>

    </div>
</main>

<script>
    // Eye toggle for password inputs
    function togglePasswordVisibility(inputId, btn) {
        const input = document.getElementById(inputId);
        if (!input) return;

        if (input.type === 'password') {
            input.type = 'text';
            btn.innerHTML = '<i data-lucide="eye-off" class="w-4 h-4"></i>';
        } else {
            input.type === 'password';
            input.type = 'password';
            btn.innerHTML = '<i data-lucide="eye" class="w-4 h-4"></i>';
        }
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    }

    // Initialize Lucide icons
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
