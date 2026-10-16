<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/repair_helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$pdo         = Database::getConnection();
$currentUser = getCurrentUser();
requireAuth(['customer', 'staff', 'admin']);
$uid = (int)$currentUser['id'];

/* Cancel a repair that is still pending */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    if (!rp_csrf_check($_POST['csrf'] ?? '')) {
        rp_flash_set('error', 'Session expired. Please try again.');
    } else {
        try {
            $st = $pdo->prepare("UPDATE repairs SET repair_status = 'cancelled'
                                 WHERE id = ? AND user_id = ? AND repair_status = 'pending'");
            $st->execute([(int)($_POST['id'] ?? 0), $uid]);
            if ($st->rowCount()) rp_flash_set('success', 'Your repair booking was cancelled.');
            else rp_flash_set('error', 'This repair can no longer be cancelled. Please contact us.');
        } catch (PDOException $e) {
            error_log('Customer cancel repair: ' . $e->getMessage());
            rp_flash_set('error', 'Something went wrong. Please try again.');
        }
    }
    header('Location: ' . APP_URL . '/customer/repairs.php');
    exit;
}

/* Filters + pagination */
$filter  = $_GET['filter'] ?? 'all';
$filters = [
    'all'       => ['All',       ''],
    'active'    => ['Active',    "AND r.repair_status IN ('pending','assigned','in_progress')"],
    'completed' => ['Completed', "AND r.repair_status = 'completed'"],
    'cancelled' => ['Cancelled', "AND r.repair_status = 'cancelled'"],
];
if (!isset($filters[$filter])) $filter = 'all';
$perPage = 8;
$page    = max(1, (int)($_GET['page'] ?? 1));

$repairs = [];
$partsByRepair = [];
$total = 0;
try {
    $c = $pdo->prepare("SELECT COUNT(*) FROM repairs r WHERE r.user_id = ? {$filters[$filter][1]}");
    $c->execute([$uid]);
    $total = (int)$c->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    $page  = min($page, $pages);

    $st = $pdo->prepare("SELECT r.*, t.name AS tech_name
                         FROM repairs r LEFT JOIN users t ON t.id = r.technician_id
                         WHERE r.user_id = ? {$filters[$filter][1]}
                         ORDER BY r.created_at DESC
                         LIMIT ? OFFSET ?");
    $st->bindValue(1, $uid, PDO::PARAM_INT);
    $st->bindValue(2, $perPage, PDO::PARAM_INT);
    $st->bindValue(3, ($page - 1) * $perPage, PDO::PARAM_INT);
    $st->execute();
    $repairs = $st->fetchAll(PDO::FETCH_ASSOC);

    if ($repairs) {
        $ids = array_map('intval', array_column($repairs, 'id'));
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $ps  = $pdo->prepare("SELECT repair_id, line_type, description, quantity, unit_cost
                              FROM repair_parts WHERE repair_id IN ($in) ORDER BY id");
        $ps->execute($ids);
        foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $p) $partsByRepair[$p['repair_id']][] = $p;
    }
} catch (PDOException $e) {
    error_log('Customer repairs: ' . $e->getMessage());
    $pages = 1;
}

$pageTitle = 'My Repairs';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<main class="flex-1 bg-gray-50 py-8 sm:py-10 lg:py-14">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Page Header -->
        <div class="mb-8 flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-brand-red mb-3">
                    <span class="w-1.5 h-1.5 rounded-full bg-brand-red"></span>
                    Customer Account
                </div>
                <h1 class="text-3xl sm:text-4xl font-extrabold tracking-tight text-gray-900">My Repairs</h1>
                <p class="mt-2 text-sm text-gray-500">Your repair history and live status in one place.</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <a href="<?php echo APP_URL; ?>/repair-booking.php" class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-red px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-redHover"><i data-lucide="plus" class="w-4 h-4"></i> New Repair</a>
            </div>
        </div>


        <?php echo rp_flash_render(); ?>

        <!-- Filter tabs -->
        <div class="flex flex-wrap gap-2 mb-6">
            <?php foreach ($filters as $key => [$label]): ?>
                <a href="?filter=<?php echo $key; ?>"
                    class="px-4 py-2 rounded-xl text-sm font-semibold transition <?php echo $key === $filter ? 'bg-brand-dark text-white' : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50'; ?>">
                    <?php echo $label; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (!$repairs): ?>
            <div class="bg-white border border-gray-200 rounded-2xl shadow-sm p-12 text-center">
                <div class="w-14 h-14 mx-auto rounded-2xl bg-gray-50 flex items-center justify-center mb-4">
                    <i data-lucide="wrench" class="w-7 h-7 text-gray-300"></i>
                </div>
                <h2 class="text-base font-bold text-gray-900">No repairs here yet</h2>
                <p class="text-sm text-gray-500 mt-1">Book a repair and it will show up here.</p>
                <a href="<?php echo APP_URL; ?>/repair-booking.php" class="inline-flex mt-5 text-sm font-bold text-brand-red hover:underline">Book a repair →</a>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">
                <?php foreach ($repairs as $r):
                    $parts = $partsByRepair[$r['id']] ?? [];
                    $partsTotal = 0;
                    foreach ($parts as $p) $partsTotal += $p['quantity'] * $p['unit_cost'];
                ?>
                    <article class="bg-white border border-gray-200 rounded-2xl shadow-sm p-5 sm:p-6">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-lg font-extrabold tracking-widest text-brand-red"><?php echo rp_e($r['repair_code']); ?></p>
                                <h2 class="text-sm font-bold text-gray-900 mt-0.5"><?php echo rp_e($r['device_model']); ?></h2>
                            </div>
                            <?php echo rp_status_badge($r['repair_status']); ?>
                        </div>

                        <div class="mt-4"><?php echo rp_progress_bar($r['repair_status']); ?></div>

                        <dl class="mt-5 grid grid-cols-2 sm:grid-cols-4 gap-4 text-xs">
                            <div>
                                <dt class="text-gray-400 font-semibold uppercase tracking-wide">Booked</dt>
                                <dd class="mt-1 font-semibold text-gray-900"><?php echo rp_date($r['created_at']); ?></dd>
                            </div>
                            <div>
                                <dt class="text-gray-400 font-semibold uppercase tracking-wide">Preferred</dt>
                                <dd class="mt-1 font-semibold text-gray-900"><?php echo rp_date($r['preferred_date']); ?></dd>
                            </div>
                            <div>
                                <dt class="text-gray-400 font-semibold uppercase tracking-wide">Technician</dt>
                                <dd class="mt-1 font-semibold text-gray-900"><?php echo rp_e($r['tech_name'] ?: 'Not yet assigned'); ?></dd>
                            </div>
                            <div>
                                <dt class="text-gray-400 font-semibold uppercase tracking-wide"><?php echo $r['repair_status'] === 'completed' ? 'Final cost' : 'Estimate'; ?></dt>
                                <dd class="mt-1 font-bold text-gray-900">
                                    <?php
                                    if ($r['repair_status'] === 'completed') echo rp_money($r['final_cost']);
                                    elseif ((float)$r['estimated_cost'] > 0) echo rp_money($r['estimated_cost']);
                                    else echo '<span class="text-gray-400 font-medium">Pending</span>';
                                    ?>
                                </dd>
                            </div>
                        </dl>

                        <?php if ($r['is_warranty_covered']): ?>
                            <div class="mt-4 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-green-50 border border-green-100 text-green-700 text-xs font-semibold">
                                <i data-lucide="shield-check" class="w-3.5 h-3.5"></i> Covered by warranty
                            </div>
                        <?php endif; ?>

                        <details class="mt-4 group">
                            <summary class="cursor-pointer text-xs font-bold text-gray-500 hover:text-gray-900 list-none inline-flex items-center gap-1.5">
                                <i data-lucide="chevron-down" class="w-4 h-4 transition-transform group-open:rotate-180"></i> Details
                            </summary>
                            <div class="mt-3 space-y-4 text-sm">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-wide text-gray-400">Reported issue</p>
                                    <p class="mt-1 text-gray-700 whitespace-pre-line"><?php echo rp_e($r['issue_description']); ?></p>
                                </div>
                                <?php if ($parts): ?>
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-gray-400">Cost breakdown</p>
                                        <div class="mt-2 divide-y divide-gray-100 border border-gray-100 rounded-xl">
                                            <?php foreach ($parts as $p): ?>
                                                <div class="flex justify-between gap-4 px-4 py-2.5 text-sm">
                                                    <span class="text-gray-700"><?php echo rp_e($p['description']); ?>
                                                        <span class="text-gray-400">× <?php echo (int)$p['quantity']; ?><?php echo $p['line_type'] === 'labour' ? ' · labour' : ''; ?></span></span>
                                                    <span class="font-semibold text-gray-900"><?php echo rp_money($p['quantity'] * $p['unit_cost']); ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                            <div class="flex justify-between px-4 py-2.5 text-sm bg-gray-50 rounded-b-xl">
                                                <span class="font-bold text-gray-900">Subtotal</span>
                                                <span class="font-bold text-gray-900"><?php echo rp_money($partsTotal); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <?php if ($r['completed_at']): ?>
                                    <p class="text-xs text-gray-500">Completed on <?php echo rp_datetime($r['completed_at']); ?></p>
                                <?php endif; ?>
                            </div>
                        </details>

                        <?php if ($r['repair_status'] === 'pending'): ?>
                            <form method="post" class="mt-4" onsubmit="return confirm('Cancel this repair booking?');">
                                <?php echo rp_csrf_field(); ?>
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                <button class="text-xs font-bold text-red-600 hover:underline">Cancel booking</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php echo rp_pagination($page, $pages, ['filter' => $filter]); ?>
        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>