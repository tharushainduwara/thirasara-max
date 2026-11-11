<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/warranty_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUser = getCurrentUser();
requireAuth(['customer', 'staff', 'admin']);
$uid = (int) $currentUser['id'];
$pdo = Database::getConnection();

// Synchronize all warranty statuses (active -> expired)
syncWarrantyStatuses($pdo);

// Auto-sync: generate warranties for any customer orders that haven't been processed yet
try {
    $custOrdersStmt = $pdo->prepare("SELECT id FROM orders WHERE user_id = ?");
    $custOrdersStmt->execute([$uid]);
    while ($ordId = $custOrdersStmt->fetchColumn()) {
        ensureOrderWarranties($pdo, (int) $ordId);
    }
} catch (Throwable $e) {
    error_log('Customer warranty auto-sync error: ' . $e->getMessage());
}

$feedback = ['type' => '', 'message' => ''];

// Handle PDF Certificate Download
if (isset($_GET['download']) && $_GET['download'] === 'pdf') {
    $warCode = trim($_GET['code'] ?? '');
    if ($warCode !== '') {
        $warDetails = getWarrantyDetails($pdo, $warCode);
        if ($warDetails && (int)$warDetails['user_id'] === $uid) {
            $tempPdf = generateWarrantyCertificatePdf($warDetails);
            if ($tempPdf && file_exists($tempPdf)) {
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="Warranty-Certificate-' . $warDetails['warranty_code'] . '.pdf"');
                header('Content-Length: ' . filesize($tempPdf));
                header('Cache-Control: private, max-age=0, must-revalidate');
                header('Pragma: public');
                readfile($tempPdf);
                @unlink($tempPdf);
                exit;
            }
        }
    }
    setFlash('error', 'Unable to download warranty certificate.');
    header('Location: ' . APP_URL . '/customer/warranties.php');
    exit;
}

// Handle Warranty Claim Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_claim') {
    $warrantyId = (int) ($_POST['warranty_id'] ?? 0);
    $description = trim($_POST['claim_description'] ?? '');

    if ($warrantyId <= 0 || $description === '') {
        $feedback = ['type' => 'error', 'message' => 'Please provide a clear description of the defect or issue.'];
    } else {
        $res = submitWarrantyClaim($pdo, $warrantyId, $uid, $description);
        $feedback = [
            'type' => $res['success'] ? 'success' : 'error',
            'message' => $res['message']
        ];
    }
}

// Stats for user
$stats = ['total' => 0, 'active' => 0, 'claimed' => 0, 'expired' => 0];
try {
    $statStmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_cnt,
            SUM(CASE WHEN status = 'claimed' THEN 1 ELSE 0 END) AS claimed_cnt,
            SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) AS expired_cnt
        FROM warranties
        WHERE user_id = ?
    ");
    $statStmt->execute([$uid]);
    $stRow = $statStmt->fetch(PDO::FETCH_ASSOC);
    if ($stRow) {
        $stats['total']   = (int) $stRow['total'];
        $stats['active']  = (int) $stRow['active_cnt'];
        $stats['claimed'] = (int) $stRow['claimed_cnt'];
        $stats['expired'] = (int) $stRow['expired_cnt'];
    }
} catch (Throwable $e) {
    error_log('Warranty stats error: ' . $e->getMessage());
}

// Filter handling
$filter = $_GET['filter'] ?? 'all';
$validFilters = ['all', 'active', 'claimed', 'expired'];
if (!in_array($filter, $validFilters, true)) {
    $filter = 'all';
}

$whereClause = "WHERE w.user_id = :uid";
if ($filter !== 'all') {
    $whereClause .= " AND w.status = :status";
}

$query = "
    SELECT 
        w.*,
        p.name AS product_name,
        p.brand AS product_brand,
        p.category AS product_category,
        p.image_url AS product_image,
        COALESCE(p.warranty_period_months, 6) AS product_warranty_months,
        COALESCE(p.is_repairable, 0) AS is_repairable,
        o.order_number,
        r.repair_code,
        r.device_model
    FROM warranties w
    LEFT JOIN products p ON p.id = w.product_id
    LEFT JOIN orders o ON o.id = w.order_id
    LEFT JOIN repairs r ON r.id = w.repair_id
    {$whereClause}
    ORDER BY w.created_at DESC
";

$stmt = $pdo->prepare($query);
$stmt->bindValue(':uid', $uid, PDO::PARAM_INT);
if ($filter !== 'all') {
    $stmt->bindValue(':status', $filter, PDO::PARAM_STR);
}
$stmt->execute();
$warranties = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'My Warranties';
require_once __DIR__ . '/../includes/header.php';
?>

<main class="flex-1 bg-gray-50 py-8 sm:py-10 lg:py-12">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- HEADER SECTION -->
        <div class="mb-8 flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-brand-red mb-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-brand-red"></span>
                    Customer Account
                </div>
                <h1 class="text-3xl sm:text-4xl font-extrabold tracking-tight text-gray-900">
                    My Warranties
                </h1>
                <p class="mt-1 text-sm text-gray-500">
                    Track digital warranty certificates and coverage duration for your purchased products and repairs.
                </p>
            </div>
            <div class="flex items-center gap-3">
                <a href="<?php echo APP_URL; ?>/warranty-check.php" 
                   class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 font-semibold text-xs transition shadow-sm">
                    <i data-lucide="shield-check" class="w-4 h-4 text-brand-red"></i>
                    Verify Any Code
                </a>
            </div>
        </div>

        <!-- FEEDBACK ALERTS -->
        <?php if ($feedback['message'] !== ''): ?>
            <div class="mb-6 p-4 rounded-xl text-sm font-medium flex items-center gap-3 shadow-sm <?php echo $feedback['type'] === 'success' ? 'bg-green-50 text-green-800 border border-green-200' : 'bg-red-50 text-red-800 border border-red-200'; ?>">
                <i data-lucide="<?php echo $feedback['type'] === 'success' ? 'check-circle' : 'alert-circle'; ?>" class="w-5 h-5 flex-shrink-0"></i>
                <p><?php echo htmlspecialchars($feedback['message']); ?></p>
            </div>
        <?php endif; ?>

        <!-- METRIC SUMMARY CARDS -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <div class="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm">
                <div class="flex items-center justify-between text-gray-500 mb-2">
                    <span class="text-xs font-bold uppercase tracking-wider">Total</span>
                    <i data-lucide="shield" class="w-5 h-5 text-gray-400"></i>
                </div>
                <p class="text-2xl font-black text-gray-900"><?php echo $stats['total']; ?></p>
                <p class="text-[11px] text-gray-400 mt-1">Registered Certificates</p>
            </div>

            <div class="bg-white border border-green-200 rounded-2xl p-5 shadow-sm">
                <div class="flex items-center justify-between text-green-700 mb-2">
                    <span class="text-xs font-bold uppercase tracking-wider">Active</span>
                    <i data-lucide="check-circle" class="w-5 h-5 text-green-600"></i>
                </div>
                <p class="text-2xl font-black text-green-700"><?php echo $stats['active']; ?></p>
                <p class="text-[11px] text-green-600 mt-1">Under Valid Coverage</p>
            </div>

            <div class="bg-white border border-blue-200 rounded-2xl p-5 shadow-sm">
                <div class="flex items-center justify-between text-blue-700 mb-2">
                    <span class="text-xs font-bold uppercase tracking-wider">Claimed</span>
                    <i data-lucide="clock" class="w-5 h-5 text-blue-600"></i>
                </div>
                <p class="text-2xl font-black text-blue-700"><?php echo $stats['claimed']; ?></p>
                <p class="text-[11px] text-blue-600 mt-1">Pending Store Review</p>
            </div>

            <div class="bg-white border border-amber-200 rounded-2xl p-5 shadow-sm">
                <div class="flex items-center justify-between text-amber-700 mb-2">
                    <span class="text-xs font-bold uppercase tracking-wider">Expired</span>
                    <i data-lucide="calendar-x" class="w-5 h-5 text-amber-600"></i>
                </div>
                <p class="text-2xl font-black text-amber-700"><?php echo $stats['expired']; ?></p>
                <p class="text-[11px] text-amber-600 mt-1">Coverage Concluded</p>
            </div>
        </div>

        <!-- FILTER TABS -->
        <div class="flex flex-wrap gap-2 mb-6">
            <a href="?filter=all" 
               class="px-4 py-2 rounded-xl text-xs font-bold transition <?php echo $filter === 'all' ? 'bg-brand-dark text-white' : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50'; ?>">
                All Certificates (<?php echo $stats['total']; ?>)
            </a>
            <a href="?filter=active" 
               class="px-4 py-2 rounded-xl text-xs font-bold transition <?php echo $filter === 'active' ? 'bg-brand-dark text-white' : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50'; ?>">
                Active Coverage (<?php echo $stats['active']; ?>)
            </a>
            <a href="?filter=claimed" 
               class="px-4 py-2 rounded-xl text-xs font-bold transition <?php echo $filter === 'claimed' ? 'bg-brand-dark text-white' : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50'; ?>">
                Claimed (<?php echo $stats['claimed']; ?>)
            </a>
            <a href="?filter=expired" 
               class="px-4 py-2 rounded-xl text-xs font-bold transition <?php echo $filter === 'expired' ? 'bg-brand-dark text-white' : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50'; ?>">
                Expired (<?php echo $stats['expired']; ?>)
            </a>
        </div>

        <!-- WARRANTIES LIST -->
        <?php if (empty($warranties)): ?>
            <div class="bg-white border border-gray-200 rounded-2xl shadow-sm p-12 text-center">
                <div class="w-14 h-14 mx-auto rounded-2xl bg-gray-50 flex items-center justify-center mb-4">
                    <i data-lucide="shield-off" class="w-7 h-7 text-gray-300"></i>
                </div>
                <h3 class="text-base font-bold text-gray-900">No Warranty Certificates Found</h3>
                <p class="text-xs text-gray-500 mt-1 max-w-sm mx-auto">
                    <?php if ($filter !== 'all'): ?>
                        You don't have any warranties in this category.
                    <?php else: ?>
                        When you purchase eligible mobile products or complete repairs with us, your digital warranties will automatically appear here.
                    <?php endif; ?>
                </p>
                <div class="mt-6 flex justify-center gap-3">
                    <a href="<?php echo APP_URL; ?>/products.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-brand-red text-white text-xs font-bold hover:bg-brand-redHover transition">
                        <i data-lucide="shopping-bag" class="w-4 h-4"></i> Explore Products
                    </a>
                </div>
            </div>
        <?php else: ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <?php foreach ($warranties as $item): ?>
                    <?php
                        $st = $item['status'];
                        $isRepair = ($item['warranty_type'] === 'repair');
                        $title = $isRepair ? ($item['device_model'] ? 'Repair: ' . $item['device_model'] : 'Service Repair') : ($item['product_name'] ?? 'Product Item');
                        $coverageMonths = $isRepair ? 3 : (int) $item['product_warranty_months'];
                        $isRepairable = $isRepair || ((int) ($item['is_repairable'] ?? 0) === 1);
                        
                        $endTs = strtotime($item['end_date']);
                        $todayTs = strtotime(date('Y-m-d'));
                        $daysLeft = (int) round(($endTs - $todayTs) / 86400);

                        $badgeCls = match($st) {
                            'active'  => 'bg-green-100 text-green-800 border-green-200',
                            'claimed' => 'bg-blue-100 text-blue-800 border-blue-200',
                            'expired' => 'bg-amber-100 text-amber-800 border-amber-200',
                            'void'    => 'bg-red-100 text-red-800 border-red-200',
                            default   => 'bg-gray-100 text-gray-800 border-gray-200'
                        };
                    ?>

                    <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm hover:shadow-md transition flex flex-col justify-between">
                        
                        <div>
                            <!-- Top Status & Code Header -->
                            <div class="flex items-start justify-between gap-3 mb-4">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold border uppercase tracking-wide <?php echo $badgeCls; ?>">
                                            <span class="w-1.5 h-1.5 rounded-full <?php echo $st === 'active' ? 'bg-green-600' : ($st === 'claimed' ? 'bg-blue-600' : 'bg-gray-400'); ?>"></span>
                                            <?php echo ucfirst($st); ?>
                                        </span>
                                        <?php if ($isRepairable): ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-green-50 text-green-700 border border-green-200">
                                                <i data-lucide="wrench" class="w-3 h-3"></i> Serviceable
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-indigo-50 text-indigo-700 border border-indigo-200">
                                                <i data-lucide="refresh-cw" class="w-3 h-3"></i> 1-to-1 Exchange
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex items-center gap-2 mt-2">
                                        <span class="font-mono text-xs font-black tracking-wider text-gray-900 bg-gray-100 px-2.5 py-1 rounded-lg">
                                            <?php echo htmlspecialchars($item['warranty_code']); ?>
                                        </span>
                                        <button type="button" 
                                                onclick="copyCode('<?php echo htmlspecialchars($item['warranty_code']); ?>')"
                                                title="Copy Warranty Code"
                                                class="text-gray-400 hover:text-gray-700 transition">
                                            <i data-lucide="copy" class="w-3.5 h-3.5"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="text-right">
                                    <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400 block">Product Warranty</span>
                                    <span class="text-sm font-extrabold text-brand-red"><?php echo $coverageMonths; ?> Months</span>
                                </div>
                            </div>

                            <!-- Product Info -->
                            <div class="flex items-center gap-3.5 mb-5 pb-5 border-b border-gray-100">
                                <div class="w-14 h-14 rounded-xl bg-gray-50 border border-gray-200 flex-shrink-0 flex items-center justify-center overflow-hidden">
                                    <?php if (!empty($item['product_image'])): ?>
                                        <img src="<?php echo APP_URL . '/' . ltrim($item['product_image'], '/'); ?>" 
                                             alt="<?php echo htmlspecialchars($title); ?>"
                                             class="w-full h-full object-cover">
                                    <?php else: ?>
                                        <i data-lucide="<?php echo $isRepair ? 'wrench' : 'smartphone'; ?>" class="w-6 h-6 text-gray-400"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-[10px] uppercase font-bold text-gray-400 tracking-wider">
                                        <?php echo htmlspecialchars($item['product_brand'] ?? ($isRepair ? 'Repair Service' : 'Store Accessory')); ?>
                                    </p>
                                    <h4 class="text-sm font-bold text-gray-900 truncate">
                                        <?php echo htmlspecialchars($title); ?>
                                    </h4>
                                    <p class="text-xs text-gray-500 mt-0.5">
                                        <?php if (!empty($item['order_number'])): ?>
                                            Order: <span class="font-semibold text-gray-700"><?php echo htmlspecialchars($item['order_number']); ?></span>
                                        <?php elseif (!empty($item['repair_code'])): ?>
                                            Repair: <span class="font-semibold text-gray-700"><?php echo htmlspecialchars($item['repair_code']); ?></span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>

                            <!-- Dates & Validity -->
                            <div class="grid grid-cols-2 gap-2 text-xs mb-4">
                                <div class="bg-gray-50 p-2.5 rounded-xl border border-gray-100">
                                    <span class="block text-[10px] uppercase font-bold text-gray-400">Issued On</span>
                                    <span class="font-semibold text-gray-800"><?php echo date('M d, Y', strtotime($item['start_date'])); ?></span>
                                </div>
                                <div class="bg-gray-50 p-2.5 rounded-xl border border-gray-100">
                                    <span class="block text-[10px] uppercase font-bold text-gray-400">Valid Until</span>
                                    <span class="font-semibold text-gray-800"><?php echo date('M d, Y', strtotime($item['end_date'])); ?></span>
                                </div>
                            </div>

                            <!-- Claim description notice if claimed -->
                            <?php if ($st === 'claimed' && !empty($item['claim_description'])): ?>
                                <div class="mb-4 p-3 bg-blue-50 border border-blue-100 rounded-xl text-xs text-blue-900">
                                    <p class="font-bold flex items-center gap-1.5">
                                        <i data-lucide="info" class="w-3.5 h-3.5"></i> Claim Filed:
                                    </p>
                                    <p class="mt-1 text-blue-800 text-[11px] leading-relaxed">
                                        <?php echo htmlspecialchars($item['claim_description']); ?>
                                    </p>
                                    <?php if (!empty($item['claim_date'])): ?>
                                        <p class="text-[10px] text-blue-500 mt-1">Submitted on <?php echo date('M d, Y', strtotime($item['claim_date'])); ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Card Action Strip -->
                        <div class="pt-3 border-t border-gray-100 flex items-center justify-between gap-3">
                            <span class="text-xs font-semibold <?php echo $st === 'active' ? 'text-green-700' : 'text-gray-400'; ?>">
                                <?php if ($st === 'active'): ?>
                                    <i data-lucide="clock" class="w-3.5 h-3.5 inline mr-1"></i> <?php echo $daysLeft; ?> days remaining
                                <?php elseif ($st === 'claimed'): ?>
                                    <span class="text-blue-700 font-bold">Review in progress</span>
                                <?php else: ?>
                                    Coverage finished
                                <?php endif; ?>
                            </span>

                            <div class="flex items-center gap-2">
                                <a href="?download=pdf&code=<?php echo urlencode($item['warranty_code']); ?>" 
                                   title="Download Official Certificate" 
                                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 font-bold text-xs transition shadow-sm">
                                    <i data-lucide="award" class="w-3.5 h-3.5 text-brand-red"></i> Certificate PDF
                                </a>
                                <?php if ($st === 'active'): ?>
                                    <?php if ($isRepairable): ?>
                                        <button type="button" 
                                                onclick="openClaimModal(<?php echo (int) $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['warranty_code'])); ?>', '<?php echo htmlspecialchars(addslashes($title)); ?>', true)"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs transition shadow-sm">
                                            <i data-lucide="wrench" class="w-3.5 h-3.5"></i> Service Claim
                                        </button>
                                    <?php else: ?>
                                        <button type="button" 
                                                onclick="openClaimModal(<?php echo (int) $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['warranty_code'])); ?>', '<?php echo htmlspecialchars(addslashes($title)); ?>', false)"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs transition shadow-sm">
                                            <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i> Request Exchange
                                        </button>
                                    <?php endif; ?>
                                <?php elseif ($st === 'expired'): ?>
                                    <?php if ($isRepairable): ?>
                                        <a href="<?php echo APP_URL; ?>/repair-booking.php" 
                                           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-800 font-bold text-xs transition">
                                            <i data-lucide="wrench" class="w-3.5 h-3.5"></i> Book Repair
                                        </a>
                                    <?php else: ?>
                                        <span class="text-[10px] text-gray-400 font-bold uppercase tracking-wider px-2 py-1 bg-gray-50 rounded-lg border border-gray-100">
                                            Non-Repairable Item
                                        </span>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <a href="<?php echo APP_URL; ?>/warranty-check.php?code=<?php echo urlencode($item['warranty_code']); ?>" 
                                   target="_blank"
                                   class="p-2 text-gray-400 hover:text-gray-700 transition" 
                                   title="Public Certificate View">
                                    <i data-lucide="external-link" class="w-4 h-4"></i>
                                </a>
                            </div>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>

    </div>
</main>

<!-- CLAIM MODAL -->
<div id="claimModal" class="fixed inset-0 z-50 bg-black/60 hidden items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-lg w-full p-6 sm:p-8 shadow-2xl relative animate-in fade-in zoom-in-95">
        <button type="button" onclick="closeClaimModal()" class="absolute top-5 right-5 text-gray-400 hover:text-gray-700">
            <i data-lucide="x" class="w-5 h-5"></i>
        </button>

        <div class="flex items-center gap-3 mb-4">
            <div id="modalIconBox" class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center flex-shrink-0">
                <i data-lucide="shield-alert" class="w-5 h-5"></i>
            </div>
            <div>
                <h3 class="text-lg font-bold text-gray-900" id="modalClaimHeading">Submit Warranty Claim</h3>
                <p class="text-xs text-gray-500" id="modalWarrantyCode"></p>
            </div>
        </div>

        <!-- Policy Explainer Banner inside modal -->
        <div id="modalPolicyBanner" class="p-3 rounded-xl mb-4 text-xs font-medium border"></div>

        <form method="POST" action="" class="space-y-4">
            <input type="hidden" name="action" value="submit_claim">
            <input type="hidden" name="warranty_id" id="modalWarrantyId" value="">

            <div>
                <label class="block text-xs font-bold text-gray-700 mb-1.5">Product / Item</label>
                <input type="text" id="modalItemTitle" disabled class="w-full px-3 py-2 bg-gray-100 border border-gray-200 rounded-xl text-xs font-semibold text-gray-700">
            </div>

            <div>
                <label for="claim_description" class="block text-xs font-bold text-gray-700 mb-1.5" id="modalDescLabel">
                    Defect or Issue Description <span class="text-brand-red">*</span>
                </label>
                <textarea 
                    id="claim_description" 
                    name="claim_description" 
                    rows="4" 
                    required 
                    placeholder="Describe the defect or reason for exchange..."
                    class="w-full px-3 py-2.5 rounded-xl border border-gray-300 text-xs focus:outline-none focus:border-brand-red focus:ring-1 focus:ring-brand-red transition"></textarea>
                <p class="text-[11px] text-gray-500 mt-1" id="modalDescHint">Our technical team will review the claim and contact you.</p>
            </div>

            <div class="pt-2 flex justify-end gap-3">
                <button type="button" onclick="closeClaimModal()" class="px-4 py-2.5 rounded-xl border border-gray-200 text-gray-600 font-bold text-xs hover:bg-gray-50 transition">
                    Cancel
                </button>
                <button type="submit" id="modalSubmitBtn" class="px-5 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs transition shadow-sm">
                    Submit Request
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function copyCode(code) {
    navigator.clipboard.writeText(code).then(() => {
        alert('Warranty code copied: ' + code);
    }).catch(() => {
        prompt('Copy warranty code:', code);
    });
}

function openClaimModal(id, code, title, isRepairable) {
    document.getElementById('modalWarrantyId').value = id;
    document.getElementById('modalWarrantyCode').textContent = 'Certificate: ' + code;
    document.getElementById('modalItemTitle').value = title;

    const heading = document.getElementById('modalClaimHeading');
    const policyBanner = document.getElementById('modalPolicyBanner');
    const descLabel = document.getElementById('modalDescLabel');
    const descHint = document.getElementById('modalDescHint');
    const submitBtn = document.getElementById('modalSubmitBtn');
    const iconBox = document.getElementById('modalIconBox');

    if (isRepairable) {
        heading.textContent = 'Submit Warranty Service Claim';
        policyBanner.className = 'p-3 rounded-xl mb-4 text-xs font-medium border bg-green-50 border-green-200 text-green-900';
        policyBanner.innerHTML = '<strong>Repairable Device:</strong> This item is eligible for diagnostic inspection and certified hardware repair under warranty.';
        descLabel.innerHTML = 'Describe Device Malfunction <span class="text-brand-red">*</span>';
        descHint.textContent = 'Our technicians will review the hardware defect and contact you to schedule service.';
        submitBtn.className = 'px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs transition shadow-sm';
        submitBtn.textContent = 'Submit Service Claim';
        iconBox.className = 'w-10 h-10 rounded-xl bg-green-50 text-green-600 flex items-center justify-center flex-shrink-0';
    } else {
        heading.textContent = 'Request Product Exchange (1-to-1)';
        policyBanner.className = 'p-3 rounded-xl mb-4 text-xs font-medium border bg-indigo-50 border-indigo-200 text-indigo-900';
        policyBanner.innerHTML = '<strong>1-to-1 Replacement:</strong> Non-repairable accessory. Within your warranty window, you are entitled to exchange this item for a brand new working unit!';
        descLabel.innerHTML = 'Defect Reason for Replacement <span class="text-brand-red">*</span>';
        descHint.textContent = 'Our store team will prepare a new replacement unit for you to collect or receive.';
        submitBtn.className = 'px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs transition shadow-sm';
        submitBtn.textContent = 'Request New Replacement Unit';
        iconBox.className = 'w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center flex-shrink-0';
    }

    document.getElementById('claimModal').classList.remove('hidden');
    document.getElementById('claimModal').classList.add('flex');
}

function closeClaimModal() {
    document.getElementById('claimModal').classList.add('hidden');
    document.getElementById('claimModal').classList.remove('flex');
}
</script>
    document.getElementById('claimModal').classList.add('hidden');
    document.getElementById('claimModal').classList.remove('flex');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
