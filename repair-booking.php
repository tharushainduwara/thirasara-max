<?php

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/repair_helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

/* Repairs require customer login */
if (!isLoggedIn()) {
    setFlash('error', 'Please sign in to book a repair.');
    header('Location: ' . APP_URL . '/login.php?redirect=repair-booking.php');
    exit;
}
$userId = (int)($_SESSION['user_id'] ?? 0);
$pdo    = Database::getConnection();

$u = $pdo->prepare("SELECT id, name FROM users WHERE id = ? AND status = 'active' LIMIT 1");
$u->execute([$userId]);
$user = $u->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    setFlash('error', 'Unable to load your account. Please sign in again.');
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

/* Eligible store purchases - ONLY products purchased from the store */
$eligible = [];
$loadError = false;
try {
    $st = $pdo->prepare("
        SELECT oi.id AS item_id, oi.quantity,
               p.id AS product_id, p.name, p.brand, p.category,
               o.order_number, o.created_at AS ordered_at
        FROM order_items oi
        JOIN orders   o ON o.id = oi.order_id
        JOIN products p ON p.id = oi.product_id
        WHERE o.user_id = ?
          AND o.order_status = 'delivered'
          AND p.is_repairable = 1
          AND (SELECT COUNT(*) FROM repairs r
               WHERE r.order_item_id = oi.id
                 AND r.repair_status IN ('pending','assigned','in_progress')) < oi.quantity
        ORDER BY o.created_at DESC, oi.id ASC
    ");
    $st->execute([$userId]);
    $eligible = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Repair eligible purchases: ' . $e->getMessage());
    $loadError = true;
}

$errors = [];
$code   = null;
$old    = [
    'item'   => '',
    'serial' => '',
    'issue'  => '',
    'date'   => date('Y-m-d', strtotime('+3 days'))
];

// Prefill: ?item=<order_item id> or ?product=<product id>
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (isset($_GET['item'])) {
        $old['item'] = (string)(int)$_GET['item'];
    } elseif (isset($_GET['product'])) {
        foreach ($eligible as $row) {
            if ((int)$row['product_id'] === (int)$_GET['product']) {
                $old['item'] = (string)$row['item_id'];
                break;
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rp_csrf_check($_POST['csrf'] ?? '')) {
        $errors[] = 'Session expired. Please try again.';
    }
    foreach ($old as $k => $_) {
        $old[$k] = trim((string)($_POST[$k] ?? ''));
    }

    if (empty($eligible)) {
        $errors[] = 'Repairs are exclusively available for devices purchased from Thirasara Max Mobile. No eligible delivered purchases were found.';
    } else {
        $chosen = null;
        foreach ($eligible as $row) {
            if ((int)$row['item_id'] === (int)$old['item']) {
                $chosen = $row;
                break;
            }
        }

        if (!$chosen) {
            $errors[] = 'Please select one of your eligible store-purchased devices.';
        } else {
            $deviceModel = trim(($chosen['brand'] ? $chosen['brand'] . ' ' : '') . $chosen['name']);
            $prodId = (int)$chosen['product_id'];
            $orderItemId = (int)$chosen['item_id'];
        }
    }

    if (mb_strlen($old['serial']) > 100) $errors[] = 'Serial / IMEI is too long.';
    if (mb_strlen($old['issue']) < 10 || mb_strlen($old['issue']) > 2000) $errors[] = 'Describe the issue (10 to 2000 characters).';

    $d = DateTime::createFromFormat('Y-m-d', $old['date']);
    $today = new DateTime('today');
    if (!$d || $d->format('Y-m-d') !== $old['date']) $errors[] = 'Choose a valid date.';
    elseif ($d < $today || $d > (clone $today)->modify('+60 days')) $errors[] = 'Preferred date must be within the next 60 days.';

    if (!$errors && isset($chosen)) {
        try {
            $pdo->beginTransaction();

            $chk = $pdo->prepare("SELECT COUNT(*) FROM repairs
                                  WHERE order_item_id = ? AND repair_status IN ('pending','assigned','in_progress')");
            $chk->execute([$chosen['item_id']]);
            if ((int)$chk->fetchColumn() >= (int)$chosen['quantity']) {
                throw new RuntimeException('There is already an open repair for this purchased device.');
            }

            $code = rp_make_code($pdo);
            $pdo->prepare('INSERT INTO repairs
                (repair_code, user_id, product_id, order_item_id, device_model, serial_or_imei, issue_description, preferred_date)
                VALUES (?,?,?,?,?,?,?,?)')
                ->execute([
                    $code,
                    $userId,
                    $prodId,
                    $orderItemId,
                    $deviceModel,
                    $old['serial'] ?: null,
                    $old['issue'],
                    $old['date']
                ]);

            rp_notify($pdo, $userId, 'Repair booking received', "Your repair {$code} for {$deviceModel} has been received.");
            $pdo->commit();
        } catch (RuntimeException $ex) {
            $pdo->rollBack();
            $code = null;
            $errors[] = $ex->getMessage();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Repair booking: ' . $ex->getMessage());
            $code = null;
            $errors[] = 'Could not save your booking. Please try again.';
        }
    }
}

$currentUser = $user;
$pageTitle   = 'Book a Repair';
$inputCls    = 'mt-1.5 w-full px-3 py-2.5 sm:px-4 sm:py-3 rounded-lg border border-gray-300 bg-gray-50 text-xs sm:text-sm text-gray-900 placeholder-gray-400 focus:bg-white focus:outline-none focus:border-brand-red transition';
$labelCls    = 'block text-[10px] font-bold uppercase tracking-wider text-gray-700 mt-4 first:mt-0';

require_once __DIR__ . '/includes/header.php';
?>

<!-- PAGE HERO -->
<section class="bg-gradient-to-r from-gray-900 via-zinc-900 to-gray-900 text-white border-b-4 border-brand-red py-12 mb-10 overflow-hidden rounded-3xl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded bg-brand-red text-white text-[10px] font-bold uppercase tracking-wider mb-3 shadow-sm">
                    Store Device Service
                </span>
                <h1 class="text-3xl sm:text-4xl font-black uppercase tracking-tight text-white">Book a Repair</h1>
                <p class="text-gray-300 text-xs sm:text-sm mt-2">Exclusive technical service and repair care for devices purchased from Thirasara Max Mobile.</p>
            </div>
            <div class="flex items-center gap-3">
                <a href="<?php echo APP_URL; ?>/customer/repairs.php"
                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-white text-gray-900 hover:bg-gray-100 px-4 py-2.5 text-xs sm:text-sm font-bold shadow-sm transition">
                    <i data-lucide="wrench" class="w-4 h-4 text-brand-red"></i> My Repairs
                </a>
            </div>
        </div>
    </div>
</section>

<main class="flex-1 pb-16">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <?php if (function_exists('displayFlash')): ?><div class="mb-6"><?php displayFlash(); ?></div><?php endif; ?>

        <div class="grid lg:grid-cols-3 gap-6 lg:gap-8 items-start">

            <div class="lg:col-span-2">
                <?php if ($code): ?>
                    <div class="bg-white border-2 border-gray-200 rounded-xl shadow-sm p-8 text-center">
                        <div class="w-14 h-14 mx-auto rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center mb-4">
                            <i data-lucide="check-circle-2" class="w-7 h-7"></i>
                        </div>
                        <h2 class="text-xl sm:text-2xl font-black uppercase tracking-tight text-gray-900">Booking received!</h2>
                        <p class="text-xs text-gray-500 mt-1">Your repair reference code:</p>
                        <div class="mt-4 inline-flex items-center gap-3 px-5 py-3 rounded-xl bg-gray-50 border-2 border-dashed border-gray-300">
                            <span id="repairCode" class="text-xl sm:text-2xl font-black tracking-widest text-brand-red"><?php echo rp_e($code); ?></span>
                            <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('repairCode').textContent);this.querySelector('span').textContent='Copied'"
                                class="inline-flex items-center gap-1 text-xs font-bold text-gray-600 hover:text-gray-900">
                                <i data-lucide="copy" class="w-4 h-4"></i><span>Copy</span>
                            </button>
                        </div>
                        <div class="mt-6 flex flex-col sm:flex-row gap-3 justify-center">
                            <a href="<?php echo APP_URL; ?>/customer/repairs.php"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-red px-5 py-2.5 text-xs sm:text-sm font-bold text-white shadow-sm transition hover:bg-brand-redHover">
                                <i data-lucide="wrench" class="w-4 h-4"></i> View My Repairs
                            </a>
                            <a href="<?php echo APP_URL; ?>/repair-track.php"
                                class="inline-flex items-center justify-center rounded-xl border-2 border-gray-200 bg-white px-5 py-2.5 text-xs sm:text-sm font-bold text-gray-800 transition hover:bg-gray-100">
                                Track Status
                            </a>
                        </div>
                    </div>

                <?php elseif (empty($eligible)): ?>
                    <!-- NO ELIGIBLE STORE PURCHASES -->
                    <div class="bg-white border-2 border-dashed border-gray-300 rounded-2xl shadow-sm p-8 sm:p-12 text-center">
                        <div class="w-16 h-16 mx-auto rounded-2xl bg-amber-50 border border-amber-200 text-amber-600 flex items-center justify-center mb-4">
                            <i data-lucide="shield-alert" class="w-8 h-8"></i>
                        </div>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded bg-amber-100 text-amber-800 text-[10px] font-bold uppercase tracking-wider mb-2">
                            Policy Notice
                        </span>
                        <h2 class="text-xl sm:text-2xl font-black uppercase tracking-tight text-gray-900">Repairs Exclusively for Store Purchases</h2>
                        <p class="text-xs sm:text-sm text-gray-600 max-w-lg mx-auto mt-2 leading-relaxed">
                            Thirasara Max Mobile provides repair and warranty servicing <strong>exclusively for mobile devices and electronics purchased directly from our store</strong>. We could not find any delivered, repairable devices in your order history.
                        </p>

                        <div class="mt-8 flex flex-col sm:flex-row gap-3 justify-center">
                            <a href="<?php echo APP_URL; ?>/products.php"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-red px-6 py-3 text-xs sm:text-sm font-bold text-white shadow-sm transition hover:bg-brand-redHover">
                                <i data-lucide="shopping-bag" class="w-4 h-4"></i> Browse Store Devices
                            </a>
                            <a href="<?php echo APP_URL; ?>/customer/orders.php"
                                class="inline-flex items-center justify-center gap-2 rounded-xl border-2 border-gray-200 bg-white px-6 py-3 text-xs sm:text-sm font-bold text-gray-800 transition hover:bg-gray-100">
                                <i data-lucide="package" class="w-4 h-4 text-gray-500"></i> My Orders
                            </a>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- ELIGIBLE STORE PURCHASES FORM -->
                    <div class="bg-white border-2 border-gray-200 rounded-xl shadow-sm p-6 sm:p-8">

                        <?php if ($errors): ?>
                            <div class="mb-5 flex gap-3 p-4 rounded-xl bg-red-50 border-2 border-red-200 text-red-700 text-xs sm:text-sm font-semibold">
                                <i data-lucide="alert-circle" class="w-5 h-5 flex-shrink-0 mt-0.5"></i>
                                <ul class="space-y-1"><?php foreach ($errors as $er): ?><li><?php echo rp_e($er); ?></li><?php endforeach; ?></ul>
                            </div>
                        <?php endif; ?>

                        <form method="post" autocomplete="off" novalidate>
                            <?php echo rp_csrf_field(); ?>

                            <div class="mb-5 flex items-center gap-3 p-3.5 rounded-xl bg-gray-50 border border-gray-200 text-xs sm:text-sm text-gray-800 font-semibold">
                                <i data-lucide="user-check" class="w-5 h-5 text-emerald-600"></i>
                                Booking as <strong class="text-gray-900"><?php echo rp_e($user['name']); ?></strong>
                            </div>

                            <label class="<?php echo $labelCls; ?>">Select Store Purchased Device *</label>
                            <p class="text-[11px] text-gray-500 mb-1.5">Choose which delivered device from your orders needs service:</p>
                            <select name="item" required class="<?php echo $inputCls; ?>">
                                <option value="">-- Choose a delivered product from your purchases --</option>
                                <?php foreach ($eligible as $row): ?>
                                    <option value="<?php echo (int)$row['item_id']; ?>" <?php echo (string)$row['item_id'] === $old['item'] ? 'selected' : ''; ?>>
                                        <?php echo rp_e(trim(($row['brand'] ? $row['brand'] . ' ' : '') . $row['name'])); ?>
                                        (Order #<?php echo rp_e($row['order_number']); ?> &bull; <?php echo date('M j, Y', strtotime($row['ordered_at'])); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <label class="<?php echo $labelCls; ?>">Device Serial / IMEI <span class="normal-case font-medium text-gray-400">(optional)</span></label>
                            <input name="serial" maxlength="100" value="<?php echo rp_e($old['serial']); ?>" placeholder="15-digit IMEI or serial number on back / box" class="<?php echo $inputCls; ?>">

                            <label class="<?php echo $labelCls; ?>">Fault / Issue Description *</label>
                            <textarea name="issue" rows="4" required maxlength="2000" class="<?php echo $inputCls; ?>" placeholder="Describe the problem in detail: broken display, battery drain, power button issue, charging port failure..."><?php echo rp_e($old['issue']); ?></textarea>

                            <label class="<?php echo $labelCls; ?>">Preferred Service Visit Date *</label>
                            <input name="date" type="date" required min="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d', strtotime('+60 days')); ?>" value="<?php echo rp_e($old['date']); ?>" class="<?php echo $inputCls; ?>">

                            <button type="submit"
                                class="mt-6 w-full inline-flex items-center justify-center gap-2 rounded-xl bg-brand-red px-5 py-3 text-xs sm:text-sm font-bold text-white shadow-sm transition hover:bg-brand-redHover">
                                <i data-lucide="send" class="w-4 h-4"></i> Submit Repair Booking
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

            <!-- SIDEBAR -->
            <aside class="space-y-5">
                <div class="bg-white border-2 border-gray-200 rounded-xl shadow-sm p-5">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-brand-red mb-3">Service Guarantee</p>
                    <ul class="space-y-2.5 text-xs text-gray-600 font-medium">
                        <li class="flex gap-2.5 items-start">
                            <i data-lucide="shield-check" class="w-4 h-4 text-emerald-600 mt-0.5 flex-shrink-0"></i>
                            <span>Exclusively for devices purchased from Thirasara Max Mobile.</span>
                        </li>
                        <li class="flex gap-2.5 items-start">
                            <i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-600 mt-0.5 flex-shrink-0"></i>
                            <span>Certified technicians &amp; 100% genuine replacement parts.</span>
                        </li>
                        <li class="flex gap-2.5 items-start">
                            <i data-lucide="award" class="w-4 h-4 text-emerald-600 mt-0.5 flex-shrink-0"></i>
                            <span>Active store warranty honors free diagnostic &amp; eligible component repairs.</span>
                        </li>
                        <li class="flex gap-2.5 items-start">
                            <i data-lucide="map-pin" class="w-4 h-4 text-indigo-500 mt-0.5 flex-shrink-0"></i>
                            <span class="text-indigo-600">Walk-in with your invoice or warranty certificate to our Wadduwa branch.</span>
                        </li>
                    </ul>
                </div>

                <div class="bg-gray-50 border border-gray-200 rounded-xl p-5">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-gray-700 mb-2">Need Help?</p>
                    <p class="text-xs text-gray-500 mb-3">Questions about your warranty or repair coverage?</p>
                    <a href="<?php echo APP_URL; ?>/warranty-check.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-brand-red hover:underline">
                        <i data-lucide="search" class="w-3.5 h-3.5"></i> Check Warranty Status &rarr;
                    </a>
                </div>
            </aside>

        </div>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
