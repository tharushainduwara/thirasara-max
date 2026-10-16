<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/repair_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure staff or admin is logged in
requireAuth(['admin', 'staff']);

$currentUser = getCurrentUser();
$pdo = Database::getConnection();
$userId = (int)$currentUser['id'];

$errors = [];
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rp_csrf_check($_POST['csrf'] ?? '')) {
        $errors[] = 'Session expired. Please try again.';
    } else {
        $action = trim($_POST['action'] ?? '');

        if ($action === 'update_profile') {
            $name  = trim($_POST['name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');

            if (empty($name)) {
                $errors[] = 'Full name is required.';
            }

            if (empty($errors)) {
                try {
                    $stmt = $pdo->prepare("UPDATE users SET name = ?, phone = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$name, $phone, $userId]);

                    // Update session name
                    $_SESSION['user_name'] = $name;
                    $currentUser['name'] = $name;

                    setFlash('success', 'Profile details updated successfully.');
                    header('Location: profile.php');
                    exit;
                } catch (PDOException $e) {
                    error_log('Staff profile update error: ' . $e->getMessage());
                    $errors[] = 'Failed to update profile information.';
                }
            }
        } elseif ($action === 'update_password') {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword     = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                $errors[] = 'All password fields are required.';
            } elseif (strlen($newPassword) < 6) {
                $errors[] = 'New password must be at least 6 characters long.';
            } elseif ($newPassword !== $confirmPassword) {
                $errors[] = 'New passwords do not match.';
            } else {
                try {
                    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
                    $stmt->execute([$userId]);
                    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$userRow || !password_verify($currentPassword, $userRow['password'])) {
                        $errors[] = 'Incorrect current password.';
                    } else {
                        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                        $updStmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                        $updStmt->execute([$newHash, $userId]);

                        setFlash('success', 'Password updated successfully.');
                        header('Location: profile.php');
                        exit;
                    }
                } catch (PDOException $e) {
                    error_log('Staff password update error: ' . $e->getMessage());
                    $errors[] = 'Failed to update password.';
                }
            }
        }
    }
}

// Fetch fresh user record
$stmt = $pdo->prepare("SELECT id, name, email, phone, role, status, created_at FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

$pageTitle = 'My Profile';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> | <?php echo APP_NAME; ?> Staff</title>

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

        <!-- STAFF SIDEBAR -->
        <?php require_once __DIR__ . '/../includes/staff_sidebar.php'; ?>

        <!-- MAIN CONTENT CONTAINER -->
        <div class="flex-1 flex flex-col min-w-0">

            <!-- TOP BAR -->
            <header class="bg-white border-b border-gray-200 sticky top-0 z-20 flex items-center gap-4 px-6 h-16 flex-shrink-0">
                <button id="sidebar-open-btn" type="button" class="lg:hidden text-gray-500 hover:text-gray-900" aria-label="Open sidebar">
                    <i data-lucide="menu" class="w-5 h-5"></i>
                </button>

                <div class="flex-1 min-w-0 flex items-center gap-2 text-sm">
                    <span class="text-gray-400 font-medium hidden sm:inline">Staff Portal</span>
                    <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-gray-300 hidden sm:inline flex-shrink-0"></i>
                    <span class="font-bold text-gray-900">My Profile</span>
                </div>

                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center text-white text-sm font-bold flex-shrink-0">
                        <?php echo strtoupper(substr($staff['name'] ?? 'S', 0, 1)); ?>
                    </div>
                </div>
            </header>

            <!-- PAGE BODY -->
            <main class="flex-1 p-5 sm:p-7 overflow-auto max-w-4xl w-full mx-auto">

                <?php if (function_exists('displayFlash')): ?><div class="mb-6"><?php displayFlash(); ?></div><?php endif; ?>
                <?php if ($errors): ?>
                    <div class="mb-6 p-4 rounded-xl bg-red-50 border border-red-200 text-red-700 text-xs sm:text-sm font-semibold">
                        <ul class="space-y-1"><?php foreach ($errors as $err): ?><li><?php echo rp_e($err); ?></li><?php endforeach; ?></ul>
                    </div>
                <?php endif; ?>

                <!-- PROFILE HERO BANNER -->
                <section class="bg-gradient-to-r from-gray-900 via-zinc-900 to-gray-900 text-white border-b-4 border-brand-red py-8 px-6 rounded-3xl overflow-hidden mb-8 shadow-sm">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div class="flex items-center gap-4">
                            <div class="w-16 h-16 rounded-2xl bg-brand-red flex items-center justify-center text-white text-2xl font-black shadow-md flex-shrink-0">
                                <?php echo strtoupper(substr($staff['name'] ?? 'S', 0, 1)); ?>
                            </div>
                            <div>
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded bg-brand-red text-white text-[10px] font-bold uppercase tracking-wider mb-1">
                                    <?php echo rp_e(ucfirst($staff['role'])); ?> Account
                                </span>
                                <h1 class="text-2xl font-black uppercase tracking-tight text-white"><?php echo rp_e($staff['name']); ?></h1>
                                <p class="text-gray-300 text-xs mt-0.5"><?php echo rp_e($staff['email']); ?></p>
                            </div>
                        </div>
                        <div>
                            <a href="<?php echo APP_URL; ?>/staff/dashboard.php" class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl bg-white/10 hover:bg-white/20 border border-white/10 text-white font-bold text-xs uppercase tracking-wider transition">
                                <i data-lucide="arrow-left" class="w-4 h-4"></i> Back to Dashboard
                            </a>
                        </div>
                    </div>
                </section>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                    <!-- EDIT PROFILE FORM -->
                    <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
                        <h2 class="text-xs font-bold uppercase tracking-wider text-brand-red mb-4 flex items-center gap-2">
                            <i data-lucide="user" class="w-4 h-4"></i> Personal Information
                        </h2>

                        <form method="POST" class="space-y-4">
                            <?php echo rp_csrf_field(); ?>
                            <input type="hidden" name="action" value="update_profile">

                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1.5">Full Name</label>
                                <input type="text" name="name" value="<?php echo rp_e($staff['name']); ?>" required
                                    class="w-full h-10 px-3.5 rounded-xl border border-gray-200 bg-gray-50 text-xs sm:text-sm font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                            </div>

                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1.5">Email Address (Read-only)</label>
                                <input type="email" value="<?php echo rp_e($staff['email']); ?>" disabled
                                    class="w-full h-10 px-3.5 rounded-xl border border-gray-200 bg-gray-100 text-xs sm:text-sm font-semibold text-gray-500 cursor-not-allowed">
                            </div>

                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1.5">Phone Number</label>
                                <input type="text" name="phone" value="<?php echo rp_e($staff['phone'] ?? ''); ?>" placeholder="+94 77 123 4567"
                                    class="w-full h-10 px-3.5 rounded-xl border border-gray-200 bg-gray-50 text-xs sm:text-sm font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                            </div>

                            <button type="submit" class="w-full h-10 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-xs font-bold uppercase tracking-wider transition shadow-sm">
                                Save Changes
                            </button>
                        </form>
                    </div>

                    <!-- CHANGE PASSWORD FORM -->
                    <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
                        <h2 class="text-xs font-bold uppercase tracking-wider text-brand-red mb-4 flex items-center gap-2">
                            <i data-lucide="lock" class="w-4 h-4"></i> Security &amp; Password
                        </h2>

                        <form method="POST" class="space-y-4">
                            <?php echo rp_csrf_field(); ?>
                            <input type="hidden" name="action" value="update_password">

                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1.5">Current Password</label>
                                <input type="password" name="current_password" required placeholder="••••••••"
                                    class="w-full h-10 px-3.5 rounded-xl border border-gray-200 bg-gray-50 text-xs sm:text-sm font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                            </div>

                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1.5">New Password</label>
                                <input type="password" name="new_password" required placeholder="Min. 6 characters"
                                    class="w-full h-10 px-3.5 rounded-xl border border-gray-200 bg-gray-50 text-xs sm:text-sm font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                            </div>

                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1.5">Confirm New Password</label>
                                <input type="password" name="confirm_password" required placeholder="Re-enter new password"
                                    class="w-full h-10 px-3.5 rounded-xl border border-gray-200 bg-gray-50 text-xs sm:text-sm font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                            </div>

                            <button type="submit" class="w-full h-10 rounded-xl bg-gray-900 hover:bg-black text-white text-xs font-bold uppercase tracking-wider transition shadow-sm">
                                Update Password
                            </button>
                        </form>
                    </div>

                </div>
            </main>
        </div>
    </div>

    <script>
        if (typeof lucide !== 'undefined') lucide.createIcons();
    </script>
</body>

</html>