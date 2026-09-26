<?php
/* Admin Sidebar */

$_sidebarUser    = getCurrentUser();
$_sidebarScript  = basename($_SERVER['PHP_SELF']);
$_sidebarDir     = basename(dirname($_SERVER['PHP_SELF']));

/**
 * Render one sidebar nav link.
 *
 * @param string $href        Full URL
 * @param string $icon        Lucide icon name
 * @param string $label       Link text
 * @param bool   $active      Whether this link is the current page
 * @param int    $badge       Optional count badge (0 = hidden)
 */
function _sidebarLink(
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

/* Render a section divider label inside the sidebar */
function _sidebarSection(string $label): void
{
    echo '<p class="px-3 pt-5 pb-1 text-[10px] uppercase tracking-widest font-bold text-gray-600">'
        . htmlspecialchars($label)
        . '</p>';
}

/* Live badge counts  (low-stock, pending orders, pending repairs) */

$_badges = ['low_stock' => 0, 'pending_orders' => 0, 'pending_repairs' => 0, 'pending_inquiries' => 0];

try {
    $pdo = Database::getConnection();

    /* Low-stock products */
    $row = $pdo->query("
        SELECT COUNT(*) AS cnt
        FROM inventory
        WHERE stock_quantity <= low_stock_threshold
          AND stock_quantity > 0
    ")->fetch(PDO::FETCH_ASSOC);
    $_badges['low_stock'] = (int) ($row['cnt'] ?? 0);

    /* Pending orders */
    $row = $pdo->query("
        SELECT COUNT(*) AS cnt
        FROM orders
        WHERE order_status = 'placed'
    ")->fetch(PDO::FETCH_ASSOC);
    $_badges['pending_orders'] = (int) ($row['cnt'] ?? 0);

    /* Pending / assigned repairs */
    $row = $pdo->query("
        SELECT COUNT(*) AS cnt
        FROM repairs
        WHERE repair_status IN ('pending', 'assigned')
    ")->fetch(PDO::FETCH_ASSOC);
    $_badges['pending_repairs'] = (int) ($row['cnt'] ?? 0);

    /* Pending inquiries */
    $row = $pdo->query("
        SELECT COUNT(*) AS cnt
        FROM inquiries
        WHERE status = 'pending'
    ")->fetch(PDO::FETCH_ASSOC);
    $_badges['pending_inquiries'] = (int) ($row['cnt'] ?? 0);
} catch (Throwable $e) {
    /* Silently ignore — badges just won't show */
}

/* Active-page detection helpers */
$_inDir = fn(string $dir): bool => $_sidebarDir === $dir;
$_isPage = fn(string $file): bool =>
$_sidebarScript === $file && $_sidebarDir === 'admin';
?>

<!-- SIDEBAR SHELL -->

<aside
    id="admin-sidebar"
    class="fixed inset-y-0 left-0 z-40 w-64 bg-brand-dark flex flex-col min-h-screen transition-transform duration-300 transform -translate-x-full lg:translate-x-0 lg:static lg:inset-auto flex-shrink-0">

    <!--LOGO / BRAND -->
    <div class="flex items-center gap-3 px-5 h-20 border-b border-brand-border flex-shrink-0">

        <a
            href="<?php echo APP_URL; ?>/admin/dashboard.php"
            class="flex items-center gap-3 min-w-0">

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
                    Admin Panel
                </p>
            </div>

        </a>

        <!-- Collapse button (mobile) -->
        <button
            type="button"
            id="sidebar-close-btn"
            class="ml-auto lg:hidden text-gray-400 hover:text-white transition"
            aria-label="Close sidebar">
            <i data-lucide="x" class="w-5 h-5"></i>
        </button>

    </div>


    <!-- NAVIGATION -->
    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-0.5 scrollbar-thin">

        <!-- Overview -->
        <?php _sidebarSection('Overview'); ?>

        <?php _sidebarLink(
            APP_URL . '/admin/dashboard.php',
            'layout-dashboard',
            'Dashboard',
            $_isPage('dashboard.php')
        ); ?>


        <!-- Catalogue -->
        <?php _sidebarSection('Catalogue'); ?>

        <?php _sidebarLink(
            APP_URL . '/admin/products/index.php',
            'package',
            'Products',
            $_inDir('products')
        ); ?>

        <?php _sidebarLink(
            APP_URL . '/admin/inventory/index.php',
            'warehouse',
            'Inventory',
            $_inDir('inventory'),
            $_badges['low_stock']
        ); ?>


        <!-- Sales -->
        <?php _sidebarSection('Sales'); ?>

        <?php _sidebarLink(
            APP_URL . '/admin/orders/index.php',
            'shopping-bag',
            'Orders',
            $_inDir('orders'),
            $_badges['pending_orders']
        ); ?>

        <?php _sidebarLink(
            APP_URL . '/admin/warranties/index.php',
            'shield-check',
            'Warranties',
            $_inDir('warranties')
        ); ?>


        <!-- Service -->
        <?php _sidebarSection('Service'); ?>

        <?php _sidebarLink(
            APP_URL . '/admin/repairs/index.php',
            'wrench',
            'Repairs',
            $_inDir('repairs'),
            $_badges['pending_repairs']
        ); ?>

        <?php _sidebarLink(
            APP_URL . '/admin/inquiries/index.php',
            'message-circle',
            'Inquiries',
            $_inDir('inquiries'),
            $_badges['pending_inquiries']
        ); ?>


        <!-- Administration -->
        <?php _sidebarSection('Administration'); ?>

        <?php _sidebarLink(
            APP_URL . '/admin/users/index.php',
            'users',
            'Users & Staff',
            $_inDir('users')
        ); ?>

        <?php _sidebarLink(
            APP_URL . '/admin/reports/index.php',
            'bar-chart-2',
            'Reports',
            $_inDir('reports')
        ); ?>

    </nav>


    <!--  QUICK LINKS  (bottom strip) -->
    <div class="px-3 py-3 border-t border-brand-border space-y-0.5">

        <?php _sidebarLink(
            APP_URL . '/products.php',
            'store',
            'View Storefront',
            false
        ); ?>

        <?php _sidebarLink(
            APP_URL . '/logout.php',
            'log-out',
            'Sign Out',
            false
        ); ?>

    </div>


    <!-- LOGGED-IN USER CARD -->
    <?php if ($_sidebarUser): ?>

        <div class="flex items-center gap-3 px-4 py-4 border-t border-brand-border bg-black/20 flex-shrink-0">

            <div class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center text-white text-sm font-bold flex-shrink-0">
                <?php echo strtoupper(substr($_sidebarUser['name'] ?? 'A', 0, 1)); ?>
            </div>

            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-white truncate">
                    <?php echo htmlspecialchars($_sidebarUser['name']); ?>
                </p>
                <p class="text-[10px] uppercase tracking-wider font-bold text-brand-red">
                    <?php echo htmlspecialchars($_sidebarUser['role']); ?>
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


<!--  MOBILE OVERLAY (tapping outside closes the sidebar) -->
<div
    id="sidebar-overlay"
    class="fixed inset-0 bg-black/50 z-30 hidden lg:hidden"
    aria-hidden="true">
</div>