<?php
/* Staff Sidebar Navigation */

$_staffUser   = getCurrentUser();
$_staffScript = basename($_SERVER['PHP_SELF']);
$_staffDir    = basename(dirname($_SERVER['PHP_SELF']));

/**
 * Render one sidebar link.
 */
function _staffSidebarLink(
    string $href,
    string $icon,
    string $label,
    bool   $active,
    int    $badge = 0
): void {
    $base = $active
        ? 'flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-semibold bg-brand-red text-white shadow-sm'
        : 'flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-gray-400 hover:text-white hover:bg-white/5 transition-colors';

    echo '<a href="' . htmlspecialchars($href) . '" class="' . $base . '">';
    echo '<i data-lucide="' . htmlspecialchars($icon) . '" class="w-4 h-4 flex-shrink-0"></i>';
    echo '<span class="flex-1">' . htmlspecialchars($label) . '</span>';

    if ($badge > 0) {
        echo '<span class="ml-auto min-w-5 h-5 px-1.5 text-[10px] font-bold rounded-full flex items-center justify-center '
            . ($active ? 'bg-white/20 text-white' : 'bg-brand-red text-white')
            . '">' . $badge . '</span>';
    }

    echo '</a>';
}

function _staffSidebarSection(string $label): void
{
    echo '<p class="px-3 pt-5 pb-1 text-[10px] uppercase tracking-widest font-bold text-gray-600">'
        . htmlspecialchars($label)
        . '</p>';
}

/* Fetch live pending / assigned counts */
$_staffBadges = ['unassigned' => 0, 'my_jobs' => 0];

try {
    $pdo = Database::getConnection();
    $uid = (int)($_staffUser['id'] ?? 0);

    // Unassigned repairs
    $row = $pdo->query("
        SELECT COUNT(*) AS cnt 
        FROM repairs 
        WHERE repair_status = 'pending' AND technician_id IS NULL
    ")->fetch(PDO::FETCH_ASSOC);
    $_staffBadges['unassigned'] = (int)($row['cnt'] ?? 0);

    // My active assigned jobs
    if ($uid > 0) {
        $st = $pdo->prepare("
            SELECT COUNT(*) AS cnt 
            FROM repairs 
            WHERE technician_id = ? AND repair_status IN ('assigned', 'in_progress')
        ");
        $st->execute([$uid]);
        $_staffBadges['my_jobs'] = (int)($st->fetchColumn() ?? 0);
    }
} catch (Throwable $e) {
    /* Silently ignore badge errors */
}

$_isStaffPage = fn(string $file): bool => $_staffScript === $file && $_staffDir === 'staff';
?>

<!-- STAFF SIDEBAR SHELL -->
<aside
    id="staff-sidebar"
    class="fixed inset-y-0 left-0 z-40 w-64 bg-brand-dark flex flex-col min-h-screen transition-transform duration-300 transform -translate-x-full lg:translate-x-0 lg:static lg:inset-auto flex-shrink-0">

    <!-- BRAND LOGO -->
    <div class="flex items-center gap-3 px-5 h-20 border-b border-brand-border flex-shrink-0">
        <a href="<?php echo APP_URL; ?>/staff/dashboard.php" class="flex items-center gap-3 min-w-0">
            <img
                src="<?php echo APP_URL; ?>/assets/images/Logo.png"
                alt="Thirasara Max"
                class="h-9 w-9 rounded-full object-cover flex-shrink-0"
                onerror="this.src='<?php echo APP_URL; ?>/assets/images/Logo.jpg'">

            <div class="min-w-0">
                <p class="text-white text-sm font-bold leading-tight truncate">
                    Thirasara Max
                </p>
                <p class="text-[10px] text-brand-red font-semibold uppercase tracking-wider">
                    Staff Portal
                </p>
            </div>
        </a>

        <!-- Mobile Close Button -->
        <button
            type="button"
            id="sidebar-close-btn"
            class="ml-auto lg:hidden text-gray-400 hover:text-white transition"
            aria-label="Close sidebar">
            <i data-lucide="x" class="w-5 h-5"></i>
        </button>
    </div>

    <!-- NAVIGATION LINKS -->
    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-0.5 scrollbar-thin">

        <?php _staffSidebarSection('Main Menu'); ?>

        <?php _staffSidebarLink(
            APP_URL . '/staff/dashboard.php',
            'layout-dashboard',
            'Dashboard',
            $_isStaffPage('dashboard.php')
        ); ?>

        <?php _staffSidebarSection('Repair Operations'); ?>

        <?php _staffSidebarLink(
            APP_URL . '/staff/repairs.php?scope=mine',
            'wrench',
            'My Active Jobs',
            $_isStaffPage('repairs.php') && ($_GET['scope'] ?? '') === 'mine',
            $_staffBadges['my_jobs']
        ); ?>

        <?php _staffSidebarLink(
            APP_URL . '/staff/repairs.php?scope=unassigned',
            'inbox',
            'Unassigned Queue',
            $_isStaffPage('repairs.php') && ($_GET['scope'] ?? '') === 'unassigned',
            $_staffBadges['unassigned']
        ); ?>

        <?php _staffSidebarLink(
            APP_URL . '/staff/repairs.php',
            'list',
            'All Repairs',
            $_isStaffPage('repairs.php') && empty($_GET['scope'])
        ); ?>

        <?php _staffSidebarSection('Warranty & Support'); ?>

        <?php _staffSidebarLink(
            APP_URL . '/staff/warranties.php',
            'shield-check',
            'Warranties',
            $_isStaffPage('warranties.php')
        ); ?>

        <?php _staffSidebarSection('Account Settings'); ?>

        <?php _staffSidebarLink(
            APP_URL . '/staff/profile.php',
            'user',
            'My Profile',
            $_isStaffPage('profile.php')
        ); ?>

    </nav>

    <!-- QUICK STOREFRONT LINK -->
    <div class="px-3 py-3 border-t border-brand-border space-y-0.5">
        <?php _staffSidebarLink(
            APP_URL . '/products.php',
            'store',
            'View Storefront',
            false
        ); ?>
    </div>

    <!-- LOGGED-IN STAFF CARD -->
    <?php if ($_staffUser): ?>
        <div class="flex items-center gap-3 px-4 py-4 border-t border-brand-border bg-black/20 flex-shrink-0">
            <div class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center text-white text-sm font-bold flex-shrink-0">
                <?php echo strtoupper(substr($_staffUser['name'] ?? 'S', 0, 1)); ?>
            </div>

            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-white truncate">
                    <?php echo htmlspecialchars($_staffUser['name']); ?>
                </p>
                <p class="text-[10px] uppercase tracking-wider font-bold text-brand-red">
                    <?php echo htmlspecialchars($_staffUser['role']); ?>
                </p>
            </div>

            <a
                href="<?php echo APP_URL; ?>/logout.php"
                class="text-gray-500 hover:text-brand-red transition flex-shrink-0"
                title="Sign out">
                <i data-lucide="log-out" class="w-4 h-4"></i>
            </a>
        </div>
    <?php endif; ?>

</aside>

<!-- MOBILE OVERLAY -->
<div
    id="sidebar-overlay"
    class="fixed inset-0 bg-black/50 z-30 hidden lg:hidden"
    aria-hidden="true">
</div>