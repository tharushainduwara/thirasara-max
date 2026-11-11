<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/repair_helpers.php';

requireAuth(['admin']);

$currentUser = getCurrentUser();
$pdo = Database::getConnection();
$adminId = (int)$currentUser['id'];

$userId = (int)($_GET['id'] ?? 0);
if ($userId <= 0) {
    setFlash('error', 'Invalid user specified.');
    header('Location: index.php');
    exit;
}

// Fetch user
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    setFlash('error', 'User not found.');
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rp_csrf_check($_POST['csrf'] ?? '')) {
        $error = 'Session expired. Please try again.';
    } else {
        $name     = trim($_POST['name'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $phone    = rp_normalize_phone(trim($_POST['phone'] ?? '')) ?? trim($_POST['phone'] ?? '');
        $address  = trim($_POST['address'] ?? '');
        $role     = trim($_POST['role'] ?? $user['role']);
        $status   = trim($_POST['status'] ?? $user['status']);
        $password = $_POST['new_password'] ?? '';

        $validRoles    = ['customer', 'staff', 'admin'];
        $validStatuses = ['active', 'inactive', 'suspended'];

        if ($name === '') {
            $error = 'Full name is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'A valid email address is required.';
        } elseif (!in_array($role, $validRoles, true)) {
            $error = 'Invalid user role selected.';
        } elseif (!in_array($status, $validStatuses, true)) {
            $error = 'Invalid account status selected.';
        } elseif ($userId === $adminId && $role !== 'admin') {
            $error = 'You cannot demote your own administrator account.';
        } elseif ($userId === $adminId && $status !== 'active') {
            $error = 'You cannot deactivate your own administrator account.';
        } else {
            // Check email uniqueness
            $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
            $chk->execute([$email, $userId]);
            if ($chk->fetch()) {
                $error = 'That email address is already assigned to another account.';
            } else {
                try {
                    if (!empty($password)) {
                        if (strlen($password) < 6) {
                            throw new RuntimeException('New password must be at least 6 characters.');
                        }
                        $hashed = password_hash($password, PASSWORD_BCRYPT);
                        $upd = $pdo->prepare("
                            UPDATE users 
                            SET name = ?, email = ?, phone = ?, address = ?, role = ?, status = ?, password = ?, updated_at = NOW()
                            WHERE id = ?
                        ");
                        $upd->execute([$name, $email, $phone, $address, $role, $status, $hashed, $userId]);
                    } else {
                        $upd = $pdo->prepare("
                            UPDATE users 
                            SET name = ?, email = ?, phone = ?, address = ?, role = ?, status = ?, updated_at = NOW()
                            WHERE id = ?
                        ");
                        $upd->execute([$name, $email, $phone, $address, $role, $status, $userId]);
                    }

                    setFlash('success', 'User account updated successfully.');
                    header("Location: index.php");
                    exit;

                } catch (Throwable $e) {
                    error_log('Admin user update error: ' . $e->getMessage());
                    $error = $e->getMessage();
                }
            }
        }
    }
}

$pageTitle = 'Edit User: ' . $user['name'];
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
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
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
        <?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>

        <div class="flex-1 flex flex-col min-w-0">
            <!-- TOP BAR -->
            <header class="bg-white border-b border-gray-200 sticky top-0 z-20 flex items-center gap-4 px-6 h-16 flex-shrink-0">
                <button id="sidebar-open-btn" type="button" class="lg:hidden text-gray-500 hover:text-gray-900" aria-label="Open sidebar">
                    <i data-lucide="menu" class="w-5 h-5"></i>
                </button>
                <div class="flex-1 min-w-0 flex items-center gap-2 text-sm">
                    <a href="index.php" class="text-gray-400 font-medium hover:text-brand-red transition hidden sm:inline">Users &amp; Staff</a>
                    <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-gray-300 hidden sm:inline flex-shrink-0"></i>
                    <span class="font-bold text-gray-900 truncate">Edit User #<?php echo (int)$user['id']; ?></span>
                </div>
                <div class="flex items-center gap-3">
                    <a href="view.php?id=<?php echo (int)$user['id']; ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-gray-200 text-xs font-semibold text-gray-700 hover:bg-gray-50 transition">
                        <i data-lucide="eye" class="w-3.5 h-3.5 text-brand-red"></i> View Profile
                    </a>
                    <a href="index.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-gray-200 text-xs font-semibold text-gray-500 hover:bg-gray-50 transition">
                        <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Back
                    </a>
                </div>
            </header>

            <main class="flex-1 p-5 sm:p-7 max-w-3xl mx-auto w-full">
                <div class="mb-6">
                    <h1 class="text-2xl font-extrabold text-gray-900 tracking-tight">Edit Account Details</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Update personal details, assign system roles, or change password.</p>
                </div>

                <?php if ($error): ?>
                    <div class="mb-6 flex items-center gap-3 p-4 bg-rose-50 border border-rose-200 rounded-2xl text-rose-800 text-sm">
                        <i data-lucide="alert-circle" class="w-5 h-5 flex-shrink-0 text-rose-600"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm p-6 sm:p-8">
                    <form method="POST" action="" class="space-y-5">
                        <?php echo rp_csrf_field(); ?>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1.5">Full Name *</label>
                                <input type="text" name="name" required value="<?php echo htmlspecialchars($user['name']); ?>"
                                    class="w-full h-11 px-3.5 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1.5">Email Address *</label>
                                <input type="email" name="email" required value="<?php echo htmlspecialchars($user['email']); ?>"
                                    class="w-full h-11 px-3.5 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1.5">Phone Number</label>
                                <input type="text" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="0771234567"
                                    class="w-full h-11 px-3.5 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1.5">System Role *</label>
                                <select name="role" class="w-full h-11 px-3.5 rounded-xl border border-gray-200 bg-gray-50 text-xs font-bold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                                    <option value="customer" <?php echo $user['role'] === 'customer' ? 'selected' : ''; ?>>Customer</option>
                                    <option value="staff" <?php echo $user['role'] === 'staff' ? 'selected' : ''; ?>>Technician / Staff</option>
                                    <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Administrator</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1.5">Account Status *</label>
                                <select name="status" class="w-full h-11 px-3.5 rounded-xl border border-gray-200 bg-gray-50 text-xs font-bold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                                    <option value="active" <?php echo $user['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $user['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="suspended" <?php echo $user['status'] === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1.5">Reset Password (Leave blank to keep current)</label>
                                <input type="password" name="new_password" placeholder="Enter new password to reset"
                                    class="w-full h-11 px-3.5 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-1.5">Postal / Delivery Address</label>
                            <textarea name="address" rows="3" placeholder="Customer address..."
                                class="w-full p-3 rounded-xl border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition"><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
                        </div>

                        <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                            <a href="index.php" class="px-5 py-2.5 rounded-xl border border-gray-200 text-gray-600 text-xs font-bold hover:bg-gray-50 transition">
                                Cancel
                            </a>
                            <button type="submit" class="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl bg-brand-red text-white text-xs font-black uppercase tracking-wider hover:bg-brand-redHover transition shadow-md">
                                <i data-lucide="check" class="w-4 h-4"></i> Save Account Changes
                            </button>
                        </div>
                    </form>
                </div>
            </main>
        </div>
    </div>
    <script>
        if (typeof lucide !== 'undefined') lucide.createIcons();
    </script>
</body>

</html>
