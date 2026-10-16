<?php

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$pdo         = Database::getConnection();
$currentUser = getCurrentUser();

/* Fetch featured products (latest 8 available) */
$featuredProducts = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            p.id, p.name, p.category, p.brand, p.price, p.image_url,
            p.warranty_period_months, p.is_repairable,
            COALESCE(i.stock_quantity, 0)      AS stock_quantity,
            COALESCE(i.low_stock_threshold, 5) AS low_stock_threshold
        FROM products p
        LEFT JOIN inventory i ON i.product_id = p.id
        WHERE p.status = 'available'
          AND COALESCE(i.stock_quantity, 0) > 0
        ORDER BY p.created_at DESC
        LIMIT 8
    ");
    $stmt->execute();
    $featuredProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Homepage featured products: ' . $e->getMessage());
}

/* Fetch distinct categories */
$categories = [];
try {
    $cats = $pdo->query("
        SELECT category, COUNT(*) AS cnt
        FROM products
        WHERE status = 'available'
        GROUP BY category
        ORDER BY cnt DESC
        LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);
    $categories = $cats;
} catch (PDOException $e) { /* silent */
}

/* Stats for trust strip */
$siteStats = ['products' => 0, 'customers' => 0, 'repairs' => 0];
try {
    $siteStats['products']  = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status='available'")->fetchColumn();
    $siteStats['customers'] = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetchColumn();
    $siteStats['repairs']   = (int) $pdo->query("SELECT COUNT(*) FROM repairs WHERE repair_status='completed'")->fetchColumn();
} catch (PDOException $e) { /* silent */
}

/* Category icons map */
$categoryIcons = [
    'Accessories'  => 'headphones',
    'Spare Parts'  => 'cpu',
    'Chargers'     => 'zap',
    'Cases'        => 'smartphone',
    'Cables'       => 'cable',
    'Screens'      => 'monitor',
    'Batteries'    => 'battery-charging',
    'Audio'        => 'music',
    'Storage'      => 'hard-drive',
    'Protection'   => 'shield',
];

function categoryIcon(string $cat, array $map): string
{
    foreach ($map as $key => $icon) {
        if (stripos($cat, $key) !== false) return $icon;
    }
    return 'package';
}
?>
<?php require_once __DIR__ . '/includes/header.php'; ?>

<!-- HERO BANNER -->
<section class="bg-gradient-to-r from-gray-900 via-zinc-900 to-gray-900 text-white border-b-4 rounded-t-3xl overflow-hidden border-brand-red py-12 lg:py-16">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid lg:grid-cols-12 gap-8 items-center">

            <div class="lg:col-span-7">
                <span class="inline-flex items-center gap-2 px-3 py-1 rounded-md bg-brand-red text-white text-xs font-bold uppercase tracking-wider mb-4 shadow-sm">
                    <i data-lucide="map-pin" class="w-3.5 h-3.5"></i> Wadduwa, Sri Lanka
                </span>

                <h1 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-white leading-tight tracking-tight">
                    Genuine Phone Parts, Accessories & <span class="text-brand-red underline decoration-brand-red/40 decoration-4">Expert Repairs</span>
                </h1>

                <p class="mt-4 text-gray-300 text-sm sm:text-base max-w-xl leading-relaxed">
                    Quality smartphone accessories, original hardware components, and certified technician repairs with digital warranty support.
                </p>

                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="<?php echo APP_URL; ?>/products.php"
                        class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-brand-red hover:bg-brand-redHover text-white font-bold text-sm transition shadow-md">
                        <i data-lucide="shopping-bag" class="w-4 h-4"></i>
                        Shop Accessories
                    </a>
                    <a href="<?php echo APP_URL; ?>/repair-booking.php"
                        class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-white text-gray-900 hover:bg-gray-100 font-bold text-sm transition shadow-md">
                        <i data-lucide="wrench" class="w-4 h-4 text-brand-red"></i>
                        Book Repair
                    </a>
                </div>
            </div>

            <!-- Quick Service Badges -->
            <div class="lg:col-span-5 grid grid-cols-2 gap-3.5">
                <div class="bg-white/10 backdrop-blur border border-white/10 p-4 rounded-xl">
                    <div class="w-8 h-8 rounded-lg bg-brand-red text-white flex items-center justify-center mb-2">
                        <i data-lucide="shield-check" class="w-4 h-4"></i>
                    </div>
                    <h3 class="font-bold text-sm text-white">100% Original</h3>
                    <p class="text-xs text-gray-300 mt-0.5">Authentic components</p>
                </div>

                <div class="bg-white/10 backdrop-blur border border-white/10 p-4 rounded-xl">
                    <div class="w-8 h-8 rounded-lg bg-blue-500 text-white flex items-center justify-center mb-2">
                        <i data-lucide="truck" class="w-4 h-4"></i>
                    </div>
                    <h3 class="font-bold text-sm text-white">Island Delivery</h3>
                    <p class="text-xs text-gray-300 mt-0.5">Fast doorstep dispatch</p>
                </div>

                <div class="bg-white/10 backdrop-blur border border-white/10 p-4 rounded-xl">
                    <div class="w-8 h-8 rounded-lg bg-emerald-500 text-white flex items-center justify-center mb-2">
                        <i data-lucide="file-check" class="w-4 h-4"></i>
                    </div>
                    <h3 class="font-bold text-sm text-white">Online Warranty</h3>
                    <p class="text-xs text-gray-300 mt-0.5">Digital verification</p>
                </div>

                <div class="bg-white/10 backdrop-blur border border-white/10 p-4 rounded-xl">
                    <div class="w-8 h-8 rounded-lg bg-amber-500 text-white flex items-center justify-center mb-2">
                        <i data-lucide="clock" class="w-4 h-4"></i>
                    </div>
                    <h3 class="font-bold text-sm text-white">Quick Turnaround</h3>
                    <p class="text-xs text-gray-300 mt-0.5">Same-day service</p>
                </div>
            </div>

        </div>
    </div>
</section>

<!-- STATS BAR (Dark & Clean) -->
<section class="bg-gray-950 text-gray-300 py-4 font-semibold border-b border-gray-800 rounded-b-3xl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-center text-xs sm:text-sm uppercase tracking-wider">
            <div><span class="text-white font-extrabold"><?php echo $siteStats['products'] ?: '50+'; ?></span> Products Available</div>
            <div><span class="text-white font-extrabold"><?php echo $siteStats['customers'] ?: '500+'; ?></span> Happy Customers</div>
            <div><span class="text-white font-extrabold"><?php echo $siteStats['repairs'] ?: '200+'; ?></span> Devices Repaired</div>
            <div class="text-brand-red font-extrabold">Trusted Store in Wadduwa</div>
        </div>
    </div>
</section>

<!-- CATEGORIES -->
<?php if (!empty($categories)): ?>
    <section class="py-12 bg-gray-100">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <div class="mb-6 flex items-center justify-between">
                <h2 class="text-xl sm:text-2xl font-black text-gray-900 uppercase tracking-tight">Explore Categories</h2>
                <a href="<?php echo APP_URL; ?>/products.php" class="text-xs font-bold text-brand-red hover:underline">View All &rarr;</a>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3.5">
                <?php foreach ($categories as $cat):
                    $icon = categoryIcon($cat['category'], $categoryIcons);
                ?>
                    <a href="<?php echo APP_URL; ?>/products.php?category=<?php echo urlencode($cat['category']); ?>"
                        class="bg-white hover:bg-red-50 border-2 border-gray-200 hover:border-brand-red rounded-xl p-4 text-center transition-all shadow-sm hover:shadow">
                        <div class="w-10 h-10 mx-auto rounded-lg bg-gray-100 text-gray-800 flex items-center justify-center mb-2">
                            <i data-lucide="<?php echo $icon; ?>" class="w-5 h-5"></i>
                        </div>
                        <span class="block text-xs font-bold text-gray-900"><?php echo htmlspecialchars($cat['category']); ?></span>
                        <span class="text-[11px] font-semibold text-gray-500"><?php echo $cat['cnt']; ?> items</span>
                    </a>
                <?php endforeach; ?>
            </div>

        </div>
    </section>
<?php endif; ?>

<!-- FEATURED PRODUCTS -->
<?php if (!empty($featuredProducts)): ?>
    <section class="py-12 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <div class="mb-6 flex items-center justify-between">
                <div>
                    <span class="text-xs font-bold text-brand-red uppercase tracking-wider">Top Items</span>
                    <h2 class="text-1xl sm:text-2xl font-black text-gray-900 uppercase tracking-tight">Featured Products</h2>
                </div>
                <a href="<?php echo APP_URL; ?>/products.php" class="text-xs font-bold text-brand-red hover:underline">See All Products &rarr;</a>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 sm:gap-6">
                <?php foreach ($featuredProducts as $product):
                    $stock    = (int) $product['stock_quantity'];
                    $thresh   = (int) $product['low_stock_threshold'];
                    $lowStock = $stock > 0 && $stock <= $thresh;
                    $imgUrl   = $product['image_url'] ?? '';
                    if ($imgUrl && !str_starts_with($imgUrl, 'http')) {
                        $imgUrl = APP_URL . '/' . ltrim($imgUrl, '/');
                    }
                ?>
                    <div class="bg-white border-2 border-gray-200 rounded-xl overflow-hidden hover:border-gray-400 hover:shadow-md transition-all flex flex-col justify-between">

                        <div class="relative aspect-square bg-gray-50 border-b border-gray-200">
                            <?php if ($imgUrl): ?>
                                <img src="<?php echo htmlspecialchars($imgUrl); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" class="w-full h-full object-cover">
                            <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center text-gray-300">
                                    <i data-lucide="smartphone" class="w-12 h-12"></i>
                                </div>
                            <?php endif; ?>

                            <?php if ($lowStock): ?>
                                <span class="absolute top-2 left-2 px-2 py-0.5 rounded bg-amber-500 text-white text-[10px] font-extrabold uppercase">Low Stock</span>
                            <?php endif; ?>
                        </div>

                        <div class="p-3.5 flex flex-col justify-between flex-grow">
                            <div>
                                <span class="text-[10px] font-bold text-brand-red uppercase"><?php echo htmlspecialchars($product['brand'] ?: $product['category']); ?></span>
                                <h3 class="text-xs sm:text-sm font-bold text-gray-900 line-clamp-2 mt-0.5">
                                    <a href="<?php echo APP_URL; ?>/product-details.php?id=<?php echo $product['id']; ?>" class="hover:underline">
                                        <?php echo htmlspecialchars($product['name']); ?>
                                    </a>
                                </h3>
                            </div>

                            <div class="mt-3 pt-2 border-t border-gray-100 flex items-center justify-between">
                                <span class="text-sm sm:text-base font-extrabold text-gray-900">Rs. <?php echo number_format((float)$product['price'], 2); ?></span>
                                <a href="<?php echo APP_URL; ?>/product-details.php?id=<?php echo $product['id']; ?>" class="px-2.5 py-1.5 rounded-lg bg-gray-900 text-white text-xs font-bold hover:bg-brand-red transition">
                                    View
                                </a>
                            </div>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>

        </div>
    </section>
<?php endif; ?>

<!-- SERVICES -->
<section class="py-12 bg-gray-100 border-t border-gray-200">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <div class="text-center max-w-lg mx-auto mb-8">
            <h2 class="text-xl sm:text-2xl font-black text-gray-900 uppercase tracking-tight">Our Services</h2>
            <p class="text-xs text-gray-600 mt-1">Complete mobile solutions under one roof at Thirasara Max Mobile.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
            <div class="bg-white border-2 border-gray-200 rounded-xl p-5 shadow-sm">
                <div class="w-10 h-10 rounded-lg bg-red-100 text-brand-red flex items-center justify-center mb-3">
                    <i data-lucide="wrench" class="w-5 h-5"></i>
                </div>
                <h3 class="font-extrabold text-sm text-gray-900 mb-1">Device Repair Booking</h3>
                <p class="text-xs text-gray-600 mb-4">Book screen, battery, and motherboard repairs handled by local experts.</p>
                <a href="<?php echo APP_URL; ?>/repair-booking.php" class="text-xs font-bold text-brand-red hover:underline">Book a Repair &rarr;</a>
            </div>

            <div class="bg-white border-2 border-gray-200 rounded-xl p-5 shadow-sm">
                <div class="w-10 h-10 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center mb-3">
                    <i data-lucide="shield-check" class="w-5 h-5"></i>
                </div>
                <h3 class="font-extrabold text-sm text-gray-900 mb-1">Warranty Checker</h3>
                <p class="text-xs text-gray-600 mb-4">Check active warranty coverage for your purchases online instantly.</p>
                <a href="<?php echo APP_URL; ?>/warranty-check.php" class="text-xs font-bold text-emerald-700 hover:underline">Check Warranty &rarr;</a>
            </div>

            <div class="bg-white border-2 border-gray-200 rounded-xl p-5 shadow-sm">
                <div class="w-10 h-10 rounded-lg bg-blue-100 text-blue-700 flex items-center justify-center mb-3">
                    <i data-lucide="map-pin" class="w-5 h-5"></i>
                </div>
                <h3 class="font-extrabold text-sm text-gray-900 mb-1">Repair Tracker</h3>
                <p class="text-xs text-gray-600 mb-4">Track your device status live using your unique repair code.</p>
                <a href="<?php echo APP_URL; ?>/repair-track.php" class="text-xs font-bold text-blue-700 hover:underline">Track Repair &rarr;</a>
            </div>
        </div>

    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>