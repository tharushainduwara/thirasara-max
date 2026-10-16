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

$pdo         = Database::getConnection();
$currentUser = getCurrentUser();
requireAuth(['staff', 'admin']);
$meId    = (int)$currentUser['id'];
$isAdmin = ($currentUser['role'] ?? '') === 'admin';

$id   = (int)($_GET['id'] ?? 0);
$self = APP_URL . '/staff/repair-detail.php?id=' . $id;

function rp_load_repair(PDO $pdo, int $id)
{
    $s = $pdo->prepare('SELECT r.*, u.name AS customer_name, u.email AS customer_email, u.phone AS customer_phone,
                               t.name AS tech_name
                        FROM repairs r
                        JOIN users u ON u.id = r.user_id
                        LEFT JOIN users t ON t.id = r.technician_id
                        WHERE r.id = ?');
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC);
}
function rp_parts_total(PDO $pdo, int $repairId): float
{
    $s = $pdo->prepare('SELECT COALESCE(SUM(quantity * unit_cost), 0) FROM repair_parts WHERE repair_id = ?');
    $s->execute([$repairId]);
    return (float)$s->fetchColumn();
}

$repair = rp_load_repair($pdo, $id);
if (!$repair) {
    rp_flash_set('error', 'Repair not found.');
    header('Location: ' . APP_URL . '/staff/repairs.php');
    exit;
}

/* ---------------------------- POST ACTIONS ---------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $status = $repair['repair_status'];
    $open   = rp_is_open($status);
    $mine   = $repair['technician_id'] !== null && (int)$repair['technician_id'] === $meId;
    $canEdit = $open && ($isAdmin || $mine);
    $custId  = (int)$repair['user_id'];
    $code    = $repair['repair_code'];

    try {
        if (!rp_csrf_check($_POST['csrf'] ?? '')) throw new RuntimeException('Session expired. Please try again.');

        switch ($action) {

            case 'assign': {
                    if (!$open) throw new RuntimeException('This repair is closed.');
                    if (!$isAdmin && $repair['technician_id'] !== null) throw new RuntimeException('Only an admin can reassign this repair.');
                    $target = $isAdmin ? (int)($_POST['technician_id'] ?? 0) : $meId;

                    $t = $pdo->prepare("SELECT id, name FROM users WHERE id = ? AND role IN ('staff','admin') AND status = 'active'");
                    $t->execute([$target]);
                    $tech = $t->fetch(PDO::FETCH_ASSOC);
                    if (!$tech) throw new RuntimeException('Choose a valid technician.');

                    $pdo->beginTransaction();
                    $u = $pdo->prepare("UPDATE repairs
                                    SET technician_id = ?, repair_status = IF(repair_status = 'pending', 'assigned', repair_status)
                                    WHERE id = ? AND repair_status IN ('pending','assigned','in_progress')");
                    $u->execute([$tech['id'], $id]);
                    if ($status === 'pending') {
                        rp_notify($pdo, $custId, 'Technician assigned', "Repair {$code} has been assigned to a technician and will be looked at soon.");
                    }
                    $pdo->commit();
                    rp_flash_set('success', 'Assigned to ' . $tech['name'] . '.');
                    break;
                }

            case 'save_details': {
                    if (!$canEdit) throw new RuntimeException('You can only edit repairs assigned to you.');
                    $diag = trim($_POST['diagnosis_notes'] ?? '');
                    $note = trim($_POST['repair_notes'] ?? '');
                    $est  = ($_POST['estimated_cost'] ?? '') === '' ? 0 : $_POST['estimated_cost'];
                    if (!is_numeric($est) || $est < 0 || $est > 99999999) throw new RuntimeException('Enter a valid estimated cost.');
                    if (mb_strlen($diag) > 5000 || mb_strlen($note) > 5000) throw new RuntimeException('Notes are too long (max 5000 characters).');
                    $warranty = !empty($_POST['is_warranty_covered']) ? 1 : 0;

                    $pdo->prepare('UPDATE repairs SET diagnosis_notes = ?, repair_notes = ?, estimated_cost = ?, is_warranty_covered = ? WHERE id = ?')
                        ->execute([$diag ?: null, $note ?: null, round((float)$est, 2), $warranty, $id]);
                    rp_flash_set('success', 'Details saved.');
                    break;
                }

            case 'add_part': {
                    if (!$canEdit) throw new RuntimeException('You can only edit repairs assigned to you.');
                    $type = ($_POST['line_type'] ?? 'part') === 'labour' ? 'labour' : 'part';
                    $desc = trim($_POST['description'] ?? '');
                    $qty  = (int)($_POST['quantity'] ?? 1);
                    $cost = $_POST['unit_cost'] ?? '';
                    if (mb_strlen($desc) < 2 || mb_strlen($desc) > 200) throw new RuntimeException('Enter a description (2-200 characters).');
                    if ($qty < 1 || $qty > 999) throw new RuntimeException('Quantity must be between 1 and 999.');
                    if (!is_numeric($cost) || $cost < 0 || $cost > 9999999) throw new RuntimeException('Enter a valid unit cost.');

                    $pdo->prepare('INSERT INTO repair_parts (repair_id, line_type, description, quantity, unit_cost, created_by) VALUES (?,?,?,?,?,?)')
                        ->execute([$id, $type, $desc, $qty, round((float)$cost, 2), $meId]);
                    rp_flash_set('success', ucfirst($type) . ' added.');
                    break;
                }

            case 'delete_part': {
                    if (!$canEdit) throw new RuntimeException('You can only edit repairs assigned to you.');
                    $pdo->prepare('DELETE FROM repair_parts WHERE id = ? AND repair_id = ?')
                        ->execute([(int)($_POST['part_id'] ?? 0), $id]);
                    rp_flash_set('success', 'Line removed.');
                    break;
                }

            case 'set_status': {
                    if (!$canEdit) throw new RuntimeException('You can only edit repairs assigned to you.');
                    $to = $_POST['to'] ?? '';
                    $allowed = ['assigned' => ['in_progress', 'cancelled'], 'in_progress' => ['cancelled'], 'pending' => ['cancelled']];
                    if (!in_array($to, $allowed[$status] ?? [], true)) throw new RuntimeException('That status change is not allowed.');

                    $pdo->beginTransaction();
                    $u = $pdo->prepare('UPDATE repairs SET repair_status = ? WHERE id = ? AND repair_status = ?');
                    $u->execute([$to, $id, $status]);
                    if (!$u->rowCount()) throw new RuntimeException('Someone else just updated this repair. Reload and try again.');
                    if ($to === 'in_progress') rp_notify($pdo, $custId, 'Repair started', "Work has started on your repair {$code}.");
                    if ($to === 'cancelled')   rp_notify($pdo, $custId, 'Repair cancelled', "Your repair {$code} has been cancelled. Please contact us for details.");
                    $pdo->commit();
                    rp_flash_set('success', $to === 'in_progress' ? 'Work started.' : 'Repair cancelled.');
                    break;
                }

            case 'complete': {
                    if (!$canEdit || $status !== 'in_progress') throw new RuntimeException('Only an in-progress repair assigned to you can be completed.');
                    if (trim((string)$repair['diagnosis_notes']) === '') throw new RuntimeException('Add diagnosis notes before completing.');

                    $pdo->beginTransaction();
                    $total = rp_parts_total($pdo, $id);
                    $final = $repair['is_warranty_covered'] ? 0.00 : $total;

                    $u = $pdo->prepare("UPDATE repairs SET repair_status = 'completed', final_cost = ?, completed_at = NOW()
                                    WHERE id = ? AND repair_status = 'in_progress'");
                    $u->execute([$final, $id]);
                    if (!$u->rowCount()) throw new RuntimeException('Someone else just updated this repair. Reload and try again.');

                    $msg = "Your repair {$code} is complete and ready for pickup."
                        . ($final > 0 ? ' Amount due: ' . rp_money($final) . '.' : ($repair['is_warranty_covered'] ? ' It was covered by warranty.' : ''));
                    rp_notify($pdo, $custId, 'Repair completed', $msg);
                    $pdo->commit();
                    rp_flash_set('success', 'Repair marked as completed.');
                    break;
                }

            default:
                throw new RuntimeException('Unknown action.');
        }
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        rp_flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Repair detail: ' . $e->getMessage());
        rp_flash_set('error', 'Something went wrong. Please try again.');
    }
    header('Location: ' . $self);
    exit;
}

/* ---------------------------- VIEW DATA ---------------------------- */
$status  = $repair['repair_status'];
$open    = rp_is_open($status);
$mine    = $repair['technician_id'] !== null && (int)$repair['technician_id'] === $meId;
$canEdit = $open && ($isAdmin || $mine);

$parts = $pdo->prepare('SELECT * FROM repair_parts WHERE repair_id = ? ORDER BY id');
$parts->execute([$id]);
$parts = $parts->fetchAll(PDO::FETCH_ASSOC);

$partsSub = 0;
$labourSub = 0;
foreach ($parts as $p) {
    $line = $p['quantity'] * $p['unit_cost'];
    if ($p['line_type'] === 'labour') $labourSub += $line;
    else $partsSub += $line;
}
$total   = $partsSub + $labourSub;
$payable = $repair['is_warranty_covered'] ? 0 : $total;

$staffList = [];
if ($isAdmin && $open) {
    $staffList = $pdo->query("SELECT id, name, role FROM users WHERE role IN ('staff','admin') AND status = 'active' ORDER BY name")
        ->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Repair ' . $repair['repair_code'];
$inputCls  = 'mt-1.5 w-full px-4 py-2.5 rounded-xl border border-gray-200 bg-white text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-red/30 focus:border-brand-red transition disabled:bg-gray-50 disabled:text-gray-500';
$labelCls  = 'block text-xs font-bold uppercase tracking-wider text-gray-500';
$btnRed    = 'inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-brand-red hover:bg-brand-redHover text-white font-semibold text-sm transition';
$btnDark   = 'inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-brand-dark hover:opacity-90 text-white font-semibold text-sm transition';
$btnLight  = 'inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-white border border-gray-200 hover:bg-gray-50 text-gray-700 font-semibold text-sm transition';
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
                    <a href="<?php echo APP_URL; ?>/staff/repairs.php" class="text-gray-500 hover:text-gray-900 hidden sm:inline">Repairs</a>
                    <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-gray-300 hidden sm:inline flex-shrink-0"></i>
                    <span class="font-bold text-gray-900 font-mono"><?php echo rp_e($repair['repair_code']); ?></span>
                </div>

                <div class="flex items-center gap-3">
                    <a href="profile.php" class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center text-white text-sm font-bold flex-shrink-0">
                        <?php echo strtoupper(substr($currentUser['name'] ?? 'S', 0, 1)); ?>
                    </a>
                </div>
            </header>

            <!-- PAGE CONTENT -->
            <main class="flex-1 p-5 sm:p-7 overflow-auto">

                <section class="bg-gradient-to-r from-gray-900 via-zinc-900 to-gray-900 text-white border-b-4 border-brand-red py-10 px-6 sm:px-8 rounded-3xl overflow-hidden mb-6 shadow-sm">
                    <a href="<?php echo APP_URL; ?>/staff/repairs.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-gray-400 hover:text-white mb-3 uppercase tracking-wider transition">
                        <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> All repairs
                    </a>
                    <div class="flex flex-wrap items-center gap-4">
                        <h1 class="text-2xl sm:text-3xl font-black uppercase tracking-tight text-white"><?php echo rp_e($repair['repair_code']); ?></h1>
                        <?php echo rp_status_badge($status); ?>
                    </div>
                    <p class="mt-1 text-gray-300 text-xs sm:text-sm font-medium"><?php echo rp_e($repair['device_model']); ?> · booked <?php echo rp_datetime($repair['created_at']); ?></p>
                </section>

                <?php echo rp_flash_render(); ?>

                <?php if ($open && !$canEdit): ?>
                    <div class="mb-6 flex gap-3 p-4 rounded-xl bg-amber-50 border border-amber-100 text-amber-800 text-sm">
                        <i data-lucide="lock" class="w-5 h-5 flex-shrink-0"></i>
                        <span><?php echo $repair['technician_id'] ? 'This repair is assigned to ' . rp_e($repair['tech_name']) . '. You can view it but not edit it.' : 'Claim this repair to start editing it.'; ?></span>
                    </div>
                <?php endif; ?>

                <div class="grid lg:grid-cols-3 gap-6 lg:gap-8 items-start">

                    <!-- ============ LEFT: work area ============ -->
                    <div class="lg:col-span-2 space-y-6">

                        <!-- Customer & issue -->
                        <div class="bg-white border border-gray-200 rounded-2xl shadow-sm p-6">
                            <p class="text-xs font-bold uppercase tracking-widest text-brand-red mb-4">Customer &amp; Device</p>
                            <dl class="grid sm:grid-cols-2 gap-4 text-sm">
                                <div>
                                    <dt class="text-xs text-gray-400 font-semibold">Customer</dt>
                                    <dd class="font-semibold text-gray-900"><?php echo rp_e($repair['customer_name']); ?></dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-gray-400 font-semibold">Phone</dt>
                                    <dd class="font-semibold text-gray-900"><?php echo rp_e($repair['customer_phone'] ?: '—'); ?></dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-gray-400 font-semibold">Email</dt>
                                    <dd class="font-semibold text-gray-900 break-all"><?php echo rp_e($repair['customer_email']); ?></dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-gray-400 font-semibold">Serial / IMEI</dt>
                                    <dd class="font-semibold text-gray-900"><?php echo rp_e($repair['serial_or_imei'] ?: '—'); ?></dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-gray-400 font-semibold">Preferred date</dt>
                                    <dd class="font-semibold text-gray-900"><?php echo rp_date($repair['preferred_date']); ?></dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-gray-400 font-semibold">Technician</dt>
                                    <dd class="font-semibold text-gray-900"><?php echo rp_e($repair['tech_name'] ?: 'Unassigned'); ?></dd>
                                </div>
                            </dl>
                            <div class="mt-5 pt-5 border-t border-gray-100">
                                <p class="text-xs text-gray-400 font-semibold">Reported issue</p>
                                <p class="mt-1 text-sm text-gray-800 whitespace-pre-line"><?php echo rp_e($repair['issue_description']); ?></p>
                            </div>
                        </div>

                        <!-- Diagnosis / notes / estimate -->
                        <form method="post" class="bg-white border border-gray-200 rounded-2xl shadow-sm p-6">
                            <?php echo rp_csrf_field(); ?>
                            <input type="hidden" name="action" value="save_details">
                            <p class="text-xs font-bold uppercase tracking-widest text-brand-red mb-4">Diagnosis &amp; Notes</p>

                            <label class="<?php echo $labelCls; ?>">Diagnosis</label>
                            <textarea name="diagnosis_notes" rows="4" maxlength="5000" <?php echo $canEdit ? '' : 'disabled'; ?>
                                class="<?php echo $inputCls; ?>" placeholder="What is actually wrong with the device?"><?php echo rp_e($repair['diagnosis_notes']); ?></textarea>

                            <label class="<?php echo $labelCls; ?> mt-4">Repair notes</label>
                            <textarea name="repair_notes" rows="3" maxlength="5000" <?php echo $canEdit ? '' : 'disabled'; ?>
                                class="<?php echo $inputCls; ?>" placeholder="Work done, test results, anything the next person should know."><?php echo rp_e($repair['repair_notes']); ?></textarea>

                            <div class="grid sm:grid-cols-2 gap-4 mt-4 items-end">
                                <div>
                                    <label class="<?php echo $labelCls; ?>">Estimated cost (Rs.)</label>
                                    <input name="estimated_cost" type="number" step="0.01" min="0" <?php echo $canEdit ? '' : 'disabled'; ?>
                                        value="<?php echo rp_e((float)$repair['estimated_cost'] > 0 ? $repair['estimated_cost'] : ''); ?>" class="<?php echo $inputCls; ?>" placeholder="0.00">
                                </div>
                                <label class="flex items-center gap-3 px-4 py-2.5 rounded-xl border border-gray-200 bg-gray-50 text-sm font-semibold text-gray-700 cursor-pointer">
                                    <input type="checkbox" name="is_warranty_covered" value="1" <?php echo $repair['is_warranty_covered'] ? 'checked' : ''; ?> <?php echo $canEdit ? '' : 'disabled'; ?>
                                        class="w-4 h-4 rounded text-brand-red focus:ring-brand-red">
                                    Covered by warranty (customer pays Rs. 0)
                                </label>
                            </div>

                            <?php if ($canEdit): ?>
                                <button class="<?php echo $btnDark; ?> mt-5"><i data-lucide="save" class="w-4 h-4"></i> Save details</button>
                            <?php endif; ?>
                        </form>

                        <!-- Parts & labour -->
                        <div class="bg-white border border-gray-200 rounded-2xl shadow-sm p-6">
                            <p class="text-xs font-bold uppercase tracking-widest text-brand-red mb-4">Replacement Parts &amp; Labour</p>

                            <?php if (!$parts): ?>
                                <p class="text-sm text-gray-400 py-4">No parts or labour added yet.</p>
                            <?php else: ?>
                                <div class="overflow-x-auto -mx-2">
                                    <table class="w-full text-sm">
                                        <thead class="text-[11px] uppercase tracking-wider text-gray-400">
                                            <tr>
                                                <th class="text-left font-bold px-2 py-2">Item</th>
                                                <th class="text-right font-bold px-2 py-2">Qty</th>
                                                <th class="text-right font-bold px-2 py-2">Unit</th>
                                                <th class="text-right font-bold px-2 py-2">Line total</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            <?php foreach ($parts as $p): ?>
                                                <tr>
                                                    <td class="px-2 py-3 text-gray-800"><?php echo rp_e($p['description']); ?>
                                                        <?php if ($p['line_type'] === 'labour'): ?><span class="ml-1 text-[10px] font-bold uppercase px-1.5 py-0.5 rounded bg-blue-50 text-blue-600">Labour</span><?php endif; ?>
                                                    </td>
                                                    <td class="px-2 py-3 text-right text-gray-600"><?php echo (int)$p['quantity']; ?></td>
                                                    <td class="px-2 py-3 text-right text-gray-600 whitespace-nowrap"><?php echo rp_money($p['unit_cost']); ?></td>
                                                    <td class="px-2 py-3 text-right font-semibold text-gray-900 whitespace-nowrap"><?php echo rp_money($p['quantity'] * $p['unit_cost']); ?></td>
                                                    <td class="px-2 py-3 text-right">
                                                        <?php if ($canEdit): ?>
                                                            <form method="post" onsubmit="return confirm('Remove this line?');">
                                                                <?php echo rp_csrf_field(); ?>
                                                                <input type="hidden" name="action" value="delete_part">
                                                                <input type="hidden" name="part_id" value="<?php echo (int)$p['id']; ?>">
                                                                <button class="text-gray-300 hover:text-red-600" title="Remove"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                            <?php if ($canEdit): ?>
                                <form method="post" class="mt-5 pt-5 border-t border-gray-100 grid grid-cols-2 sm:grid-cols-12 gap-3 items-end">
                                    <?php echo rp_csrf_field(); ?>
                                    <input type="hidden" name="action" value="add_part">
                                    <div class="col-span-2 sm:col-span-4">
                                        <label class="<?php echo $labelCls; ?>">Description</label>
                                        <input name="description" required maxlength="200" class="<?php echo $inputCls; ?>" placeholder="e.g. A54 screen assembly">
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="<?php echo $labelCls; ?>">Type</label>
                                        <select name="line_type" class="<?php echo $inputCls; ?>">
                                            <option value="part">Part</option>
                                            <option value="labour">Labour</option>
                                        </select>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="<?php echo $labelCls; ?>">Qty</label>
                                        <input name="quantity" type="number" min="1" max="999" value="1" required class="<?php echo $inputCls; ?>">
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="<?php echo $labelCls; ?>">Unit (Rs.)</label>
                                        <input name="unit_cost" type="number" step="0.01" min="0" required class="<?php echo $inputCls; ?>">
                                    </div>
                                    <div class="col-span-2 sm:col-span-2">
                                        <button class="<?php echo $btnRed; ?> w-full mt-1.5"><i data-lucide="plus" class="w-4 h-4"></i> Add</button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- ============ RIGHT: actions & totals ============ -->
                    <aside class="space-y-6">

                        <!-- Actions -->
                        <div class="bg-white border border-gray-200 rounded-2xl shadow-sm p-6">
                            <p class="text-xs font-bold uppercase tracking-widest text-brand-red mb-4">Actions</p>
                            <div class="space-y-3">

                                <?php if ($open && !$repair['technician_id']): ?>
                                    <form method="post"><?php echo rp_csrf_field(); ?><input type="hidden" name="action" value="assign">
                                        <button class="<?php echo $btnRed; ?> w-full"><i data-lucide="hand" class="w-4 h-4"></i> Claim this repair</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($isAdmin && $open): ?>
                                    <form method="post" class="flex gap-2"><?php echo rp_csrf_field(); ?><input type="hidden" name="action" value="assign">
                                        <select name="technician_id" class="<?php echo $inputCls; ?> !mt-0 flex-1">
                                            <?php foreach ($staffList as $s): ?>
                                                <option value="<?php echo (int)$s['id']; ?>" <?php echo (int)$s['id'] === (int)$repair['technician_id'] ? 'selected' : ''; ?>><?php echo rp_e($s['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="<?php echo $btnLight; ?>">Assign</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($canEdit && $status === 'assigned'): ?>
                                    <form method="post"><?php echo rp_csrf_field(); ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="to" value="in_progress">
                                        <button class="<?php echo $btnDark; ?> w-full"><i data-lucide="play" class="w-4 h-4"></i> Start work</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($canEdit && $status === 'in_progress'): ?>
                                    <form method="post" onsubmit="return confirm('Mark as completed? The customer will be notified and the final cost will be locked in.');">
                                        <?php echo rp_csrf_field(); ?><input type="hidden" name="action" value="complete">
                                        <button class="<?php echo $btnRed; ?> w-full !bg-green-600 hover:!bg-green-700"><i data-lucide="check-circle-2" class="w-4 h-4"></i> Mark as completed</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($canEdit): ?>
                                    <form method="post" onsubmit="return confirm('Cancel this repair? The customer will be notified.');">
                                        <?php echo rp_csrf_field(); ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="to" value="cancelled">
                                        <button class="<?php echo $btnLight; ?> w-full !text-red-600"><i data-lucide="x-circle" class="w-4 h-4"></i> Cancel repair</button>
                                    </form>
                                <?php endif; ?>

                                <?php if (!$open): ?>
                                    <p class="text-sm text-gray-500">This repair is <?php echo $status === 'completed' ? 'completed' : 'cancelled'; ?>. No further actions.</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Cost summary -->
                        <div class="bg-brand-dark rounded-2xl p-6 text-white shadow-sm">
                            <p class="text-xs font-bold uppercase tracking-widest text-brand-red mb-4">Cost Summary</p>
                            <dl class="space-y-2.5 text-sm">
                                <div class="flex justify-between">
                                    <dt class="text-gray-400">Estimate</dt>
                                    <dd class="font-semibold"><?php echo (float)$repair['estimated_cost'] > 0 ? rp_money($repair['estimated_cost']) : '—'; ?></dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-gray-400">Parts</dt>
                                    <dd class="font-semibold"><?php echo rp_money($partsSub); ?></dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-gray-400">Labour</dt>
                                    <dd class="font-semibold"><?php echo rp_money($labourSub); ?></dd>
                                </div>
                                <div class="flex justify-between border-t border-white/10 pt-2.5">
                                    <dt class="text-gray-400">Total</dt>
                                    <dd class="font-semibold"><?php echo rp_money($total); ?></dd>
                                </div>
                                <?php if ($repair['is_warranty_covered']): ?>
                                    <div class="flex justify-between text-green-400">
                                        <dt>Warranty</dt>
                                        <dd class="font-semibold">− <?php echo rp_money($total); ?></dd>
                                    </div>
                                <?php endif; ?>
                                <div class="flex justify-between border-t border-white/10 pt-2.5 text-base">
                                    <dt class="font-bold"><?php echo $status === 'completed' ? 'Final cost' : 'Customer pays'; ?></dt>
                                    <dd class="font-extrabold text-brand-red"><?php echo rp_money($status === 'completed' ? $repair['final_cost'] : $payable); ?></dd>
                                </div>
                            </dl>
                            <?php if ($repair['completed_at']): ?>
                                <p class="mt-4 text-xs text-gray-500">Completed <?php echo rp_datetime($repair['completed_at']); ?></p>
                            <?php endif; ?>
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