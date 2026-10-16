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

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    setFlash('error', 'Invalid repair ID.');
    header('Location: index.php');
    exit;
}

// Handle Form Submission: Status & Technician Update
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rp_csrf_check($_POST['csrf'] ?? '')) {
        $errors[] = 'Session expired. Please try again.';
    } else {
        $status      = trim($_POST['repair_status'] ?? '');
        $techId      = (int)($_POST['technician_id'] ?? 0);
        $estCost     = (float)($_POST['estimated_cost'] ?? 0);
        $finalCost   = (float)($_POST['final_cost'] ?? 0);
        $repairNotes = trim($_POST['repair_notes'] ?? '');

        $validStatuses = ['pending', 'assigned', 'in_progress', 'completed', 'cancelled'];
        if (!in_array($status, $validStatuses, true)) {
            $errors[] = 'Invalid repair status selected.';
        }

        if (!$errors) {
            try {
                $completedAt = ($status === 'completed') ? date('Y-m-d H:i:s') : null;

                $upd = $pdo->prepare("
                    UPDATE repairs 
                    SET repair_status = ?,
                        technician_id = ?,
                        estimated_cost = ?,
                        final_cost = ?,
                        repair_notes = ?,
                        completed_at = COALESCE(completed_at, ?)
                    WHERE id = ?
                ");
                $upd->execute([
                    $status,
                    $techId > 0 ? $techId : null,
                    $estCost,
                    $finalCost,
                    $repairNotes,
                    $completedAt,
                    $id
                ]);

                // Fetch customer ID to send notification
                $custStmt = $pdo->prepare("SELECT user_id, repair_code FROM repairs WHERE id = ?");
                $custStmt->execute([$id]);
                $repData = $custStmt->fetch(PDO::FETCH_ASSOC);

                if ($repData) {
                    rp_notify($pdo, (int)$repData['user_id'], 'Repair Status Updated', "Your repair {$repData['repair_code']} is now " . str_replace('_', ' ', $status) . ".");
                }

                setFlash('success', 'Repair updated successfully.');
                header("Location: view.php?id={$id}");
                exit;
            } catch (PDOException $e) {
                error_log('Update repair error: ' . $e->getMessage());
                $errors[] = 'Failed to update repair record.';
            }
        }
    }
}

// Fetch Repair Details
$stmt = $pdo->prepare("
    SELECT r.*, u.name AS customer_name, u.email AS customer_email, u.phone AS customer_phone,
           p.name AS product_name, p.brand AS product_brand, o.order_number
    FROM repairs r
    JOIN users u ON u.id = r.user_id
    LEFT JOIN products p ON p.id = r.product_id
    LEFT JOIN order_items oi ON oi.id = r.order_item_id
    LEFT JOIN orders o ON o.id = oi.order_id
    WHERE r.id = ?
    LIMIT 1
");
$stmt->execute([$id]);
$repair = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$repair) {
    setFlash('error', 'Repair booking not found.');
    header('Location: index.php');
    exit;
}

// Fetch Technicians List
$techStmt = $pdo->query("SELECT id, name FROM users WHERE role IN ('admin', 'staff', 'technician') ORDER BY name ASC");
$technicians = $techStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "Repair Details - " . $repair['repair_code'];
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
        <?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>

        <div class="flex-1 flex flex-col min-w-0">
            <!-- TOP BAR -->
            <header class="bg-white border-b border-gray-200 sticky top-0 z-20 flex items-center gap-4 px-6 h-16 flex-shrink-0">
                <button id="sidebar-open-btn" type="button" class="lg:hidden text-gray-500 hover:text-gray-900" aria-label="Open sidebar">
                    <i data-lucide="menu" class="w-5 h-5"></i>
                </button>
                <div class="flex-1 min-w-0 flex items-center gap-2 text-sm">
                    <span class="text-gray-400 font-medium hidden sm:inline">Admin</span>
                    <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-gray-300 hidden sm:inline flex-shrink-0"></i>
                    <a href="index.php" class="text-gray-500 hover:text-gray-900 font-medium">Repairs</a>
                    <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-gray-300 hidden sm:inline flex-shrink-0"></i>
                    <span class="font-bold text-gray-900"><?php echo rp_e($repair['repair_code']); ?></span>
                </div>
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center text-white text-sm font-bold flex-shrink-0">
                        <?php echo strtoupper(substr($currentUser['name'] ?? 'A', 0, 1)); ?>
                    </div>
                </div>
            </header>

            <main class="flex-1 p-5 sm:p-7 overflow-auto">
                <?php if (function_exists('displayFlash')): ?><div class="mb-6"><?php displayFlash(); ?></div><?php endif; ?>
                <?php if ($errors): ?>
                    <div class="mb-6 p-4 rounded-xl bg-red-50 border border-red-200 text-red-700 text-xs sm:text-sm font-semibold">
                        <ul class="space-y-1"><?php foreach ($errors as $er): ?><li><?php echo rp_e($er); ?></li><?php endforeach; ?></ul>
                    </div>
                <?php endif; ?>

                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                    <div>
                        <h1 class="text-2xl font-extrabold text-gray-900 tracking-tight">Repair Details</h1>
                        <p class="text-sm text-gray-500 mt-0.5">Manage diagnostics, costs, and technician assignments.</p>
                    </div>
                    <div>
                        <a href="index.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-gray-200 bg-white text-gray-700 font-bold text-xs hover:bg-gray-50 transition shadow-sm">
                            <i data-lucide="arrow-left" class="w-4 h-4"></i> Back to Repairs
                        </a>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- DETAILS COLUMN -->
                    <div class="lg:col-span-2 space-y-6">
                        <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
                            <h2 class="text-xs font-bold uppercase tracking-wider text-brand-red mb-4">Customer &amp; Product Info</h2>
                            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs sm:text-sm">
                                <div>
                                    <dt class="text-gray-400 font-bold uppercase text-[10px]">Customer Name</dt>
                                    <dd class="font-bold text-gray-900 mt-0.5"><?php echo rp_e($repair['customer_name']); ?></dd>
                                </div>
                                <div>
                                    <dt class="text-gray-400 font-bold uppercase text-[10px]">Contact Info</dt>
                                    <dd class="font-semibold text-gray-800 mt-0.5">
                                        <?php echo rp_e($repair['customer_phone']); ?><br>
                                        <span class="text-xs text-gray-400"><?php echo rp_e($repair['customer_email']); ?></span>
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-gray-400 font-bold uppercase text-[10px]">Device Model</dt>
                                    <dd class="font-bold text-gray-900 mt-0.5"><?php echo rp_e($repair['device_model']); ?></dd>
                                </div>
                                <div>
                                    <dt class="text-gray-400 font-bold uppercase text-[10px]">Serial / IMEI</dt>
                                    <dd class="font-mono text-gray-800 mt-0.5"><?php echo rp_e($repair['serial_or_imei'] ?: 'N/A'); ?></dd>
                                </div>
                                <div>
                                    <dt class="text-gray-400 font-bold uppercase text-[10px]">Associated Order</dt>
                                    <dd class="font-bold text-gray-900 mt-0.5">Order #<?php echo rp_e($repair['order_number'] ?: 'Direct Booking'); ?></dd>
                                </div>
                                <div>
                                    <dt class="text-gray-400 font-bold uppercase text-[10px]">Preferred Date</dt>
                                    <dd class="font-semibold text-gray-800 mt-0.5"><?php echo rp_e($repair['preferred_date']); ?></dd>
                                </div>
                            </dl>

                            <div class="mt-6 pt-4 border-t border-gray-100">
                                <dt class="text-gray-400 font-bold uppercase text-[10px] mb-1.5">Issue Description</dt>
                                <dd class="p-3.5 bg-gray-50 border border-gray-200 rounded-xl text-xs font-medium text-gray-800 leading-relaxed">
                                    <?php echo nl2br(rp_e($repair['issue_description'])); ?>
                                </dd>
                            </div>
                        </div>
                    </div>

                    <!-- MANAGEMENT SIDEBAR -->
                    <aside class="space-y-6">
                        <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
                            <h2 class="text-xs font-bold uppercase tracking-wider text-brand-red mb-4">Update Status &amp; Cost</h2>

                            <form method="POST" class="space-y-4">
                                <?php echo rp_csrf_field(); ?>

                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1.5">Status</label>
                                    <select name="repair_status" required class="w-full h-10 px-3 rounded-xl border border-gray-200 bg-gray-50 text-xs font-bold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                                        <option value="pending" <?php echo $repair['repair_status'] === 'pending' ? 'selected' : ''; ?>>Received (Pending)</option>
                                        <option value="assigned" <?php echo $repair['repair_status'] === 'assigned' ? 'selected' : ''; ?>>Technician Assigned</option>
                                        <option value="in_progress" <?php echo $repair['repair_status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                        <option value="completed" <?php echo $repair['repair_status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                        <option value="cancelled" <?php echo $repair['repair_status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1.5">Assigned Technician</label>
                                    <select name="technician_id" class="w-full h-10 px-3 rounded-xl border border-gray-200 bg-gray-50 text-xs font-bold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                                        <option value="0">-- Unassigned --</option>
                                        <?php foreach ($technicians as $t): ?>
                                            <option value="<?php echo (int)$t['id']; ?>" <?php echo (int)$repair['technician_id'] === (int)$t['id'] ? 'selected' : ''; ?>>
                                                <?php echo rp_e($t['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1.5">Estimated Cost (Rs.)</label>
                                    <input type="number" step="0.01" name="estimated_cost" value="<?php echo rp_e((string)$repair['estimated_cost']); ?>"
                                        class="w-full h-10 px-3 rounded-xl border border-gray-200 bg-gray-50 text-xs font-bold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                                </div>

                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1.5">Final Cost (Rs.)</label>
                                    <input type="number" step="0.01" name="final_cost" value="<?php echo rp_e((string)$repair['final_cost']); ?>"
                                        class="w-full h-10 px-3 rounded-xl border border-gray-200 bg-gray-50 text-xs font-bold text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition">
                                </div>

                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1.5">Repair Notes</label>
                                    <textarea name="repair_notes" rows="3" class="w-full p-3 rounded-xl border border-gray-200 bg-gray-50 text-xs font-medium text-gray-900 focus:bg-white focus:outline-none focus:border-brand-red transition"><?php echo rp_e($repair['repair_notes']); ?></textarea>
                                </div>

                                <button type="submit" class="w-full h-11 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-xs font-bold uppercase tracking-wider shadow-sm transition">
                                    Save Changes
                                </button>
                            </form>
                        </div>
                    </aside>
                </div>
            </main>
        </div>
    </div>
    <script>
        if (typeof lucide !== 'undefined') lucide.createIcons();
    </script>
</body>

</html>