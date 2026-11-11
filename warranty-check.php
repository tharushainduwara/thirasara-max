<?php

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/warranty_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUser = getCurrentUser();
$pageTitle   = 'Check Digital Warranty';
$searchCode  = trim($_GET['code'] ?? '');

$warranty = null;
$searched = false;
$searchError = '';

if ($searchCode !== '') {
    $searched = true;
    try {
        $pdo = Database::getConnection();
        $warranty = getWarrantyDetails($pdo, $searchCode);

        if (!$warranty) {
            // Check by order number, repair code, or customer phone number
            $cleanPhone = preg_replace('/\D/', '', $searchCode);
            $altStmt = $pdo->prepare("
                SELECT w.warranty_code 
                FROM warranties w
                LEFT JOIN orders o ON o.id = w.order_id
                LEFT JOIN repairs r ON r.id = w.repair_id
                LEFT JOIN users u ON u.id = w.user_id
                WHERE o.order_number = ? 
                   OR r.repair_code = ? 
                   OR (? != '' AND u.phone = ?)
                ORDER BY w.id DESC
                LIMIT 1
            ");
            $altStmt->execute([$searchCode, $searchCode, $cleanPhone, $cleanPhone]);
            $altCode = $altStmt->fetchColumn();

            if ($altCode) {
                $warranty = getWarrantyDetails($pdo, $altCode);
            }
        }

        if (!$warranty) {
            $searchError = "No warranty record found matching '" . htmlspecialchars($searchCode) . "'. Please verify your code.";
        }
    } catch (Throwable $e) {
        $searchError = "Unable to process warranty verification: " . $e->getMessage();
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- HERO BANNER -->
<section class="bg-gradient-to-r from-gray-950 via-zinc-900 to-black text-white border-b-4 border-brand-red py-12 mb-10 overflow-hidden rounded-2xl mx-4 sm:mx-6 lg:mx-8 mt-4 shadow-xl">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded bg-brand-red/90 text-white text-[10px] font-bold uppercase tracking-wider mb-3 shadow-sm">
            <i data-lucide="shield-check" class="w-3.5 h-3.5"></i> Digital Verification
        </span>
        <h1 class="text-3xl sm:text-4xl font-black uppercase tracking-tight text-white">
            Digital <span class="text-brand-red">Warranty</span> Check
        </h1>
        <p class="text-gray-300 text-xs sm:text-sm mt-2 max-w-xl mx-auto leading-relaxed">
            Verify official warranty validity, coverage period, and expiration date for mobile accessories and repairs purchased from Thirasara Max.
        </p>
    </div>
</section>

<!-- MAIN CONTENT WRAPPER -->
<main class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 mb-20">

    <!-- SEARCH BOX -->
    <div class="bg-white border border-gray-200 rounded-2xl p-6 sm:p-8 shadow-sm mb-8">
        <form method="GET" action="<?php echo APP_URL; ?>/warranty-check.php" class="space-y-4">
            <div>
                <label for="code" class="block text-[11px] font-bold uppercase tracking-wider text-gray-700 mb-1.5">
                    Enter Warranty Code, Order #, or Mobile Number
                </label>
                <div class="relative">
                    <input 
                        type="text" 
                        id="code" 
                        name="code" 
                        required 
                        placeholder="e.g. TM-WAR-20261025-XXXX, TM-ORD-..., or 07XXXXXXXX" 
                        value="<?php echo htmlspecialchars($searchCode); ?>"
                        class="w-full pl-11 pr-4 py-3 rounded-xl border border-gray-300 bg-gray-50 text-sm font-semibold tracking-wider text-gray-900 placeholder-gray-400 uppercase focus:bg-white focus:outline-none focus:border-brand-red focus:ring-1 focus:ring-brand-red transition">
                    <i data-lucide="search" class="w-5 h-5 text-gray-400 absolute left-3.5 top-1/2 -translate-y-1/2"></i>
                </div>
                <p class="text-[11px] text-gray-500 mt-2">
                    Tip: Walk-in in-store customers can enter their contact phone number or the code printed on their shop receipt.
                </p>
            </div>

            <button 
                type="submit" 
                class="w-full inline-flex items-center justify-center gap-2 px-6 py-3.5 rounded-xl bg-brand-red hover:bg-brand-redHover text-white font-bold text-sm tracking-wide transition shadow-sm hover:shadow">
                <i data-lucide="shield-check" class="w-4 h-4"></i>
                Verify Warranty
            </button>
        </form>
    </div>

    <!-- ERROR ALERT -->
    <?php if ($searchError !== ''): ?>
        <div class="p-5 rounded-2xl bg-red-50 border border-red-200 text-red-800 text-sm flex items-start gap-3 shadow-sm mb-8">
            <i data-lucide="alert-circle" class="w-5 h-5 text-brand-red flex-shrink-0 mt-0.5"></i>
            <div>
                <p class="font-bold">Verification Failed</p>
                <p class="text-xs text-red-700 mt-0.5"><?php echo $searchError; ?></p>
            </div>
        </div>
    <?php endif; ?>

    <!-- RESULT DISPLAY -->
    <?php if ($warranty): ?>
        <?php
            $status = $warranty['status'];
            $isValid = $warranty['is_currently_valid'];
            
            $statusBadgeClass = match($status) {
                'active'  => 'bg-green-100 text-green-800 border-green-300',
                'claimed' => 'bg-blue-100 text-blue-800 border-blue-300',
                'expired' => 'bg-amber-100 text-amber-800 border-amber-300',
                'void'    => 'bg-red-100 text-red-800 border-red-300',
                default   => 'bg-gray-100 text-gray-800 border-gray-300'
            };

            $statusIcon = match($status) {
                'active'  => 'check-circle',
                'claimed' => 'clock',
                'expired' => 'calendar-x',
                'void'    => 'slash',
                default   => 'help-circle'
            };
        ?>

        <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-md">
            
            <!-- Result Header Banner -->
            <div class="p-6 bg-gradient-to-r <?php echo $isValid ? 'from-green-900 to-emerald-950' : 'from-gray-900 to-zinc-900'; ?> text-white flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold border uppercase tracking-wider <?php echo $statusBadgeClass; ?>">
                        <i data-lucide="<?php echo $statusIcon; ?>" class="w-3.5 h-3.5"></i>
                        Status: <?php echo ucfirst($status); ?>
                    </span>
                    <h2 class="text-xl sm:text-2xl font-black text-white mt-2">
                        <?php echo htmlspecialchars($warranty['warranty_code']); ?>
                    </h2>
                    <p class="text-xs text-gray-300 mt-1">
                        Type: <span class="capitalize font-semibold"><?php echo htmlspecialchars($warranty['warranty_type']); ?> Warranty</span>
                    </p>
                </div>

                <div class="text-left sm:text-right">
                    <p class="text-[10px] uppercase font-bold text-gray-300 tracking-wider">Product Warranty Coverage</p>
                    <p class="text-2xl font-black text-white">
                        <?php echo (int) $warranty['coverage_months']; ?> Months
                    </p>
                    <div class="mt-2">
                        <a href="warranty-check.php?download=pdf&code=<?php echo urlencode($warranty['warranty_code']); ?>" 
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white/10 hover:bg-white/20 text-white font-bold text-xs transition border border-white/20">
                            <i data-lucide="download" class="w-3.5 h-3.5 text-brand-red"></i> Download Certificate PDF
                        </a>
                    </div>
                </div>
            </div>

            <div class="p-6 sm:p-8 space-y-6">
                <!-- Product / Item Info -->
                <div class="flex items-center gap-4 pb-6 border-b border-gray-100">
                    <div class="w-16 h-16 rounded-xl bg-gray-100 border border-gray-200 overflow-hidden flex-shrink-0 flex items-center justify-center">
                        <?php if (!empty($warranty['product_image'])): ?>
                            <img src="<?php echo APP_URL . '/' . ltrim($warranty['product_image'], '/'); ?>" 
                                 alt="<?php echo htmlspecialchars($warranty['display_title']); ?>"
                                 class="w-full h-full object-cover">
                        <?php else: ?>
                            <i data-lucide="<?php echo $warranty['warranty_type'] === 'repair' ? 'wrench' : 'smartphone'; ?>" class="w-8 h-8 text-gray-400"></i>
                        <?php endif; ?>
                    </div>
                    <div>
                        <span class="text-[10px] uppercase font-extrabold tracking-wider text-brand-red">
                            <?php echo htmlspecialchars($warranty['product_brand'] ?? 'Thirasara Max'); ?> &bull; <?php echo htmlspecialchars($warranty['product_category'] ?? 'Mobile Accessory'); ?>
                        </span>
                        <h3 class="text-base sm:text-lg font-bold text-gray-900 leading-snug">
                            <?php echo htmlspecialchars($warranty['display_title']); ?>
                        </h3>
                        <?php if (!empty($warranty['order_number'])): ?>
                            <p class="text-xs text-gray-500 mt-0.5">Associated Order: <span class="font-semibold text-gray-700"><?php echo htmlspecialchars($warranty['order_number']); ?></span></p>
                        <?php elseif (!empty($warranty['repair_code'])): ?>
                            <p class="text-xs text-gray-500 mt-0.5">Repair Job Code: <span class="font-semibold text-gray-700"><?php echo htmlspecialchars($warranty['repair_code']); ?></span></p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Timeline & Progress Bar -->
                <div>
                    <div class="flex justify-between items-center text-xs font-semibold text-gray-700 mb-2">
                        <span>Coverage Timeline</span>
                        <span class="<?php echo $isValid ? 'text-green-700' : 'text-gray-500'; ?> font-bold">
                            <?php if ($isValid): ?>
                                <?php echo $warranty['days_remaining']; ?> days remaining
                            <?php elseif ($status === 'expired'): ?>
                                Expired <?php echo abs($warranty['days_remaining']); ?> days ago
                            <?php else: ?>
                                Claimed
                            <?php endif; ?>
                        </span>
                    </div>

                    <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                        <div class="h-3 rounded-full transition-all duration-500 <?php echo $isValid ? 'bg-green-600' : ($status === 'expired' ? 'bg-amber-500' : 'bg-brand-red'); ?>"
                             style="width: <?php echo $warranty['percent_elapsed']; ?>%">
                        </div>
                    </div>

                    <div class="flex justify-between text-[11px] text-gray-500 mt-2 font-medium">
                        <div>
                            <span class="block text-gray-400 text-[10px] uppercase">Start Date</span>
                            <?php echo date('M d, Y', strtotime($warranty['start_date'])); ?>
                        </div>
                        <div class="text-right">
                            <span class="block text-gray-400 text-[10px] uppercase">Expiry Date</span>
                            <?php echo date('M d, Y', strtotime($warranty['end_date'])); ?>
                        </div>
                    </div>
                </div>

                <!-- Detailed Key-Value Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 bg-gray-50 p-4 rounded-xl border border-gray-100 text-xs">
                    <div>
                        <p class="text-gray-500">Registered Customer:</p>
                        <p class="font-bold text-gray-800">
                            <?php
                                $nameParts = explode(' ', trim($warranty['customer_name'] ?? 'Customer'));
                                echo htmlspecialchars($nameParts[0] . (isset($nameParts[1]) ? ' ' . substr($nameParts[1], 0, 1) . '***' : ''));
                            ?>
                        </p>
                    </div>
                    <div>
                        <p class="text-gray-500">Product Warranty Duration:</p>
                        <p class="font-bold text-gray-800"><?php echo (int) $warranty['coverage_months']; ?> Months Official Coverage</p>
                    </div>
                    <div>
                        <p class="text-gray-500">Warranty Policy Type:</p>
                        <p class="font-bold <?php echo ($warranty['coverage_model'] ?? '') === 'repair_service' ? 'text-green-700' : 'text-indigo-700'; ?>">
                            <?php echo htmlspecialchars($warranty['coverage_headline'] ?? 'Standard Warranty'); ?>
                        </p>
                    </div>
                    <div>
                        <p class="text-gray-500">Service / Exchange Policy:</p>
                        <p class="font-semibold text-gray-700 leading-snug">
                            <?php echo htmlspecialchars($warranty['coverage_terms'] ?? 'Coverage provided as per store terms.'); ?>
                        </p>
                    </div>
                </div>

                <!-- Action Button for Customer -->
                <div class="pt-2 flex flex-col sm:flex-row gap-3">
                    <?php if ($currentUser): ?>
                        <?php if ($currentUser['id'] == $warranty['user_id'] && $isValid): ?>
                            <a href="<?php echo APP_URL; ?>/customer/warranties.php" 
                               class="flex-1 inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl <?php echo ($warranty['coverage_model'] ?? '') === 'repair_service' ? 'bg-blue-600 hover:bg-blue-700' : 'bg-indigo-600 hover:bg-indigo-700'; ?> text-white font-bold text-xs uppercase tracking-wider transition">
                                <i data-lucide="<?php echo ($warranty['coverage_model'] ?? '') === 'repair_service' ? 'file-text' : 'refresh-cw'; ?>" class="w-4 h-4"></i> 
                                <?php echo ($warranty['coverage_model'] ?? '') === 'repair_service' ? 'File Service Claim' : 'Request Product Exchange'; ?>
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <a href="<?php echo APP_URL; ?>/login.php" 
                           class="flex-1 inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-gray-900 hover:bg-black text-white font-bold text-xs uppercase tracking-wider transition">
                            <i data-lucide="log-in" class="w-4 h-4"></i> Log In to Manage Warranties
                        </a>
                    <?php endif; ?>

                    <?php if (($warranty['coverage_model'] ?? '') === 'repair_service'): ?>
                        <a href="<?php echo APP_URL; ?>/repair-booking.php" 
                           class="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl border border-gray-300 hover:bg-gray-100 text-gray-800 font-bold text-xs uppercase tracking-wider transition">
                            <i data-lucide="wrench" class="w-4 h-4"></i> Book Service / Repair
                        </a>
                    <?php else: ?>
                        <div class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-[11px] font-semibold">
                            <i data-lucide="info" class="w-4 h-4 text-amber-600 flex-shrink-0"></i>
                            Non-repairable item: Eligible for 1-to-1 replacement exchange only.
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    <?php endif; ?>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
