<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/repair_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo         = Database::getConnection();
$currentUser = getCurrentUser();
requireAuth(['admin']); // Strict admin-only access

$errors = [];
$name   = '';
$email  = '';
$phone  = '';
$role   = 'staff';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rp_csrf_check($_POST['csrf'] ?? '')) {
        rp_flash_set('error', 'Session expired. Please try again.');
    } else {
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $role     = $_POST['role'] ?? 'staff';
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        // Input Validations
        if (empty($name)) {
            $errors['name'] = 'Full name is required.';
        }
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email address is required.';
        }
        if (!in_array($role, ['staff', 'admin'])) {
            $role = 'staff';
        }
        if (strlen($password) < 6) {
            $errors['password'] = 'Password must be at least 6 characters long.';
        }
        if ($password !== $confirm) {
            $errors['confirm_password'] = 'Passwords do not match.';
        }

        if (empty($errors)) {
            try {
                // Check if email already exists
                $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
                $chk->execute([$email]);
                if ($chk->fetchColumn() > 0) {
                    $errors['email'] = 'An account with this email address already exists.';
                } else {
                    // Hash password and save new staff member
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                    $st = $pdo->prepare("INSERT INTO users (name, email, phone, password, role, status, created_at) VALUES (?, ?, ?, ?, ?, 'active', NOW())");
                    $st->execute([$name, $email, $phone, $passwordHash, $role]);

                    rp_flash_set('success', "New staff account for <strong>" . rp_e($name) . "</strong> created successfully!");
                    header('Location: ' . APP_URL . '/admin/users/index.php');
                    exit;
                }
            } catch (PDOException $e) {
                error_log('Admin add staff error: ' . $e->getMessage());
                rp_flash_set('error', 'Database error occurred while creating staff account.');
            }
        }
    }
}

$pageTitle = 'Add New Staff Member';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> | <?php echo APP_NAME; ?> Admin</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Stylesheet -->
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css">

    <!-- Tailwind -->
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

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="<?php echo APP_URL; ?>/assets/js/main.js" defer></script>
</head>

<body class="bg-gray-100 text-gray-800 font-sans antialiased">

    <div class="flex min-h-screen">

        <!-- SIDEBAR -->
        <?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>

        <!-- MAIN CONTENT CONTAINER -->
        <div class="flex-1 flex flex-col min-w-0">

            <!-- TOP BAR -->
            <header class="bg-white border-b border-gray-200 sticky top-0 z-20 flex items-center gap-4 px-6 h-16 flex-shrink-0">
                <button id="sidebar-open-btn" type="button" class="lg:hidden text-gray-500 hover:text-gray-900" aria-label="Open sidebar">
                    <i data-lucide="menu" class="w-5 h-5"></i>
                </button>

                <!-- Breadcrumbs -->
                <div class="flex-1 min-w-0 flex items-center gap-2 text-sm">
                    <span class="text-gray-400 font-medium hidden sm:inline">Admin</span>
                    <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-gray-300 hidden sm:inline flex-shrink-0"></i>
                    <a href="<?php echo APP_URL; ?>/admin/users/index.php" class="text-gray-500 hover:text-gray-900 font-medium">Users</a>
                    <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-gray-300 hidden sm:inline flex-shrink-0"></i>
                    <span class="font-bold text-gray-900">Create Staff</span>
                </div>

                <!-- Admin Profile Avatar -->
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center text-white text-sm font-bold flex-shrink-0">
                        <?php echo strtoupper(substr($currentUser['name'] ?? 'A', 0, 1)); ?>
                    </div>
                </div>
            </header>

            <!-- PAGE BODY -->
            <main class="flex-1 p-5 sm:p-7 overflow-auto">
                <div class="max-w-2xl mx-auto">

                    <!-- Page Title Header -->
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                        <div>
                            <h1 class="text-2xl font-extrabold text-gray-900 tracking-tight">Create Staff Account</h1>
                            <p class="text-sm text-gray-500 mt-0.5">Register a new technician or system administrator.</p>
                        </div>
                        <div>
                            <a href="<?php echo APP_URL; ?>/admin/users/index.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-gray-200 bg-white text-gray-700 font-bold text-xs hover:bg-gray-50 transition shadow-sm">
                                <i data-lucide="arrow-left" class="w-4 h-4"></i> Back to Users
                            </a>
                        </div>
                    </div>

                    <?php echo rp_flash_render(); ?>

                    <!-- Create Account Form -->
                    <form method="post" class="bg-white border border-gray-200 rounded-2xl shadow-sm p-6 sm:p-8 space-y-5">
                        <?php echo rp_csrf_field(); ?>

                        <!-- Full Name -->
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1.5">Full Name *</label>
                            <input type="text" name="name" value="<?php echo rp_e($name); ?>" required placeholder="e.g. John Doe"
                                class="w-full h-10 rounded-xl border border-gray-200 bg-gray-50 px-3.5 text-xs sm:text-sm font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                            <?php if (isset($errors['name'])): ?>
                                <p class="text-xs text-red-600 mt-1"><?php echo $errors['name']; ?></p>
                            <?php endif; ?>
                        </div>

                        <!-- Email & Phone Grid -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1.5">Email Address *</label>
                                <input type="email" name="email" value="<?php echo rp_e($email); ?>" required placeholder="staff@shop.com"
                                    class="w-full h-10 rounded-xl border border-gray-200 bg-gray-50 px-3.5 text-xs sm:text-sm font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                                <?php if (isset($errors['email'])): ?>
                                    <p class="text-xs text-red-600 mt-1"><?php echo $errors['email']; ?></p>
                                <?php endif; ?>
                            </div>

                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1.5">Phone Number</label>
                                <input type="text" name="phone" value="<?php echo rp_e($phone); ?>" placeholder="+94 77 123 4567"
                                    class="w-full h-10 rounded-xl border border-gray-200 bg-gray-50 px-3.5 text-xs sm:text-sm font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                            </div>
                        </div>

                        <!-- Access Role -->
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1.5">Account Role *</label>
                            <select name="role" class="w-full h-10 rounded-xl border border-gray-200 bg-gray-50 px-3 text-xs sm:text-sm font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                                <option value="staff" <?php echo $role === 'staff' ? 'selected' : ''; ?>>Technician / Staff (Can view & update repairs/warranties)</option>
                                <option value="admin" <?php echo $role === 'admin' ? 'selected' : ''; ?>>System Administrator (Full platform access)</option>
                            </select>
                        </div>

                        <!-- Passwords Grid -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-3 border-t border-gray-100">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1.5">Password *</label>
                                <input type="password" name="password" required placeholder="Min. 6 characters"
                                    class="w-full h-10 rounded-xl border border-gray-200 bg-gray-50 px-3.5 text-xs sm:text-sm font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                                <?php if (isset($errors['password'])): ?>
                                    <p class="text-xs text-red-600 mt-1"><?php echo $errors['password']; ?></p>
                                <?php endif; ?>
                            </div>

                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1.5">Confirm Password *</label>
                                <input type="password" name="confirm_password" required placeholder="Re-enter password"
                                    class="w-full h-10 rounded-xl border border-gray-200 bg-gray-50 px-3.5 text-xs sm:text-sm font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                                <?php if (isset($errors['confirm_password'])): ?>
                                    <p class="text-xs text-red-600 mt-1"><?php echo $errors['confirm_password']; ?></p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="pt-4 border-t border-gray-100 flex items-center justify-end gap-3">
                            <a href="<?php echo APP_URL; ?>/admin/users/index.php" class="h-10 px-5 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 text-xs font-bold transition inline-flex items-center justify-center">Cancel</a>
                            <button type="submit" class="h-10 px-6 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-xs font-bold uppercase tracking-wider transition shadow-sm inline-flex items-center justify-center">Create Account</button>
                        </div>
                    </form>

                </div>
            </main>
        </div>
    </div>

    <script>
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    </script>
</body>

</html>