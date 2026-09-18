<?php

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$pdo         = Database::getConnection();
$currentUser = getCurrentUser();

/*Fetch featured products (latest 8 available) */
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

/* Fetch distinct categories*/
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
} catch (PDOException $e) { /* silent */ }

/* Stats for trust strip */
$siteStats = ['products' => 0, 'customers' => 0, 'repairs' => 0];
try {
    $siteStats['products']  = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status='available'")->fetchColumn();
    $siteStats['customers'] = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetchColumn();
    $siteStats['repairs']   = (int) $pdo->query("SELECT COUNT(*) FROM repairs WHERE repair_status='completed'")->fetchColumn();
} catch (PDOException $e) { /* silent */ }

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

function categoryIcon(string $cat, array $map): string {
    foreach ($map as $key => $icon) {
        if (stripos($cat, $key) !== false) return $icon;
    }
    return 'package';
}
?>
<?php require_once __DIR__ . '/includes/header.php'; ?>


<!-- HERO SECTION -->
<section class="relative bg-brand-dark overflow-hidden rounded-3xl">

    <!-- Background glow blobs -->
    <div class="absolute top-0 left-0 w-96 h-96 bg-brand-red/20 rounded-full blur-3xl -translate-x-1/2 -translate-y-1/2 pointer-events-none"></div>
    <div class="absolute bottom-0 right-0 w-80 h-80 bg-blue-600/10 rounded-full blur-3xl translate-x-1/3 translate-y-1/3 pointer-events-none"></div>

    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 sm:py-28 lg:py-32">
        <div class="grid lg:grid-cols-2 gap-12 items-center">

            <!-- Text -->
            <div class="text-center lg:text-left">

                <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-brand-red/10 border border-brand-red/20 text-brand-red text-xs font-semibold mb-6">
                    <span class="w-1.5 h-1.5 bg-brand-red rounded-full animate-pulse"></span>
                    Sri Lanka's Trusted Mobile Shop
                </div>

                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold text-white leading-tight tracking-tight">
                    Everything Your
                    <span class="text-brand-red"> Phone Needs</span>
                </h1>

                <p class="mt-5 text-gray-400 text-base sm:text-lg leading-relaxed max-w-lg mx-auto lg:mx-0">
                    Premium accessories, genuine spare parts, and expert repair services — all under one roof at Thirasara Max Mobile.
                </p>

                <div class="mt-8 flex flex-col sm:flex-row gap-3 justify-center lg:justify-start">
                    <a href="<?php echo APP_URL; ?>/products.php"
                       class="inline-flex items-center justify-center gap-2 px-7 py-3.5 rounded-2xl bg-brand-red hover:bg-brand-redHover text-white font-semibold text-sm transition shadow-lg shadow-brand-red/30">
                        <i data-lucide="shopping-bag" class="w-4 h-4"></i>
                        Shop Now
                    </a>
                    <a href="<?php echo APP_URL; ?>/repair-booking.php"
                       class="inline-flex items-center justify-center gap-2 px-7 py-3.5 rounded-2xl bg-white/5 hover:bg-white/10 border border-white/10 text-white font-semibold text-sm transition">
                        <i data-lucide="wrench" class="w-4 h-4"></i>
                        Book a Repair
                    </a>
                </div>

                <!-- Trust badges -->
                <div class="mt-10 flex flex-wrap gap-5 justify-center lg:justify-start">
                    <div class="flex items-center gap-2 text-gray-400 text-xs">
                        <i data-lucide="shield-check" class="w-4 h-4 text-green-400"></i>
                        Genuine Products
                    </div>
                    <div class="flex items-center gap-2 text-gray-400 text-xs">
                        <i data-lucide="truck" class="w-4 h-4 text-blue-400"></i>
                        Island-wide Delivery
                    </div>
                    <div class="flex items-center gap-2 text-gray-400 text-xs">
                        <i data-lucide="refresh-cw" class="w-4 h-4 text-amber-400"></i>
                        Easy Returns
                    </div>
                    <div class="flex items-center gap-2 text-gray-400 text-xs">
                        <i data-lucide="headphones" class="w-4 h-4 text-purple-400"></i>
                        24/7 Support
                    </div>
                </div>

            </div>

            <!-- Hero visual — floating phone mockup with accessory bubbles -->
            <div class="hidden lg:flex items-center justify-center relative select-none">

                <!-- Central phone icon -->
                <div class="relative w-56 h-56 bg-gradient-to-br from-white/5 to-white/0 border border-white/10 rounded-[3rem] flex items-center justify-center shadow-2xl">
                    <i data-lucide="smartphone" class="w-28 h-28 text-white/20"></i>
                    <!-- Red accent ring -->
                    <div class="absolute inset-0 rounded-[3rem] border-2 border-brand-red/30 scale-110"></div>
                    <div class="absolute inset-0 rounded-[3rem] border border-brand-red/10 scale-125"></div>
                </div>

                <!-- Floating accessory bubbles -->
                <?php
                $bubbles = [
                    ['icon'=>'headphones',       'label'=>'Audio',     'pos'=>'top-0 left-4',     'color'=>'bg-purple-500/20 border-purple-500/30'],
                    ['icon'=>'battery-charging', 'label'=>'Battery',   'pos'=>'top-8 right-0',    'color'=>'bg-green-500/20 border-green-500/30'],
                    ['icon'=>'zap',              'label'=>'Chargers',  'pos'=>'bottom-4 right-4', 'color'=>'bg-yellow-500/20 border-yellow-500/30'],
                    ['icon'=>'shield',           'label'=>'Cases',     'pos'=>'bottom-0 left-12', 'color'=>'bg-blue-500/20 border-blue-500/30'],
                    ['icon'=>'cpu',              'label'=>'Parts',     'pos'=>'top-1/2 -left-4',  'color'=>'bg-brand-red/20 border-brand-red/30'],
                ];
                foreach ($bubbles as $b):
                ?>
                <div class="absolute <?php echo $b['pos']; ?> flex flex-col items-center gap-1 group cursor-default">
                    <div class="w-12 h-12 rounded-2xl <?php echo $b['color']; ?> border backdrop-blur-sm flex items-center justify-center shadow-lg group-hover:scale-110 transition-transform duration-300">
                        <i data-lucide="<?php echo $b['icon']; ?>" class="w-5 h-5 text-white/70"></i>
                    </div>
                    <span class="text-[10px] text-gray-500 font-medium"><?php echo $b['label']; ?></span>
                </div>
                <?php endforeach; ?>

            </div>

        </div>
    </div>

</section>


<!-- STATS STRIP -->
<section class="py-10 sm:py-12 mt-2">

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-8 text-center">

            <div class="flex flex-col items-center gap-1.5">
                <span class="text-3xl sm:text-4xl font-extrabold text-gray-900"><?php echo $siteStats['products'] ?: '50+'; ?></span>
                <span class="text-xs text-gray-500 font-medium uppercase tracking-wide">Products Available</span>
            </div>

            <div class="flex flex-col items-center gap-1.5">
                <span class="text-3xl sm:text-4xl font-extrabold text-gray-900"><?php echo $siteStats['customers'] ?: '500+'; ?></span>
                <span class="text-xs text-gray-500 font-medium uppercase tracking-wide">Happy Customers</span>
            </div>

            <div class="flex flex-col items-center gap-1.5">
                <span class="text-3xl sm:text-4xl font-extrabold text-gray-900"><?php echo $siteStats['repairs'] ?: '200+'; ?></span>
                <span class="text-xs text-gray-500 font-medium uppercase tracking-wide">Repairs Completed</span>
            </div>

            <div class="flex flex-col items-center gap-1.5">
                <span class="text-3xl sm:text-4xl font-extrabold text-brand-red">5★</span>
                <span class="text-xs text-gray-500 font-medium uppercase tracking-wide">Customer Rating</span>
            </div>

        </div>
    </div>
</section>



<!-- CATEGORIES GRID -->
<?php if (!empty($categories)): ?>
<section class="bg-gray-50 py-14 sm:py-16">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Heading -->
        <div class="text-center mb-10">
            <p class="text-xs font-bold uppercase tracking-widest text-brand-red mb-2">Browse by Category</p>
            <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-900">What are you looking for?</h2>
        </div>

        <!-- Category pills -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            <?php
            $catColors = [
                'bg-red-50 border-red-100 hover:bg-red-100 text-red-600',
                'bg-blue-50 border-blue-100 hover:bg-blue-100 text-blue-600',
                'bg-purple-50 border-purple-100 hover:bg-purple-100 text-purple-600',
                'bg-amber-50 border-amber-100 hover:bg-amber-100 text-amber-600',
                'bg-green-50 border-green-100 hover:bg-green-100 text-green-600',
                'bg-sky-50 border-sky-100 hover:bg-sky-100 text-sky-600',
            ];
            foreach ($categories as $i => $cat):
                $color = $catColors[$i % count($catColors)];
                $icon  = categoryIcon($cat['category'], $categoryIcons);
            ?>
            <a href="<?php echo APP_URL; ?>/products.php?category=<?php echo urlencode($cat['category']); ?>"
               class="group flex flex-col items-center gap-3 p-5 bg-white border rounded-2xl shadow-sm hover:shadow-md transition-all duration-200 <?php echo $color; ?>">
                <div class="w-12 h-12 rounded-xl bg-current/10 flex items-center justify-center transition-transform group-hover:scale-110 duration-200">
                    <i data-lucide="<?php echo $icon; ?>" class="w-6 h-6"></i>
                </div>
                <div class="text-center">
                    <p class="text-sm font-semibold text-gray-800"><?php echo htmlspecialchars($cat['category']); ?></p>
                    <p class="text-xs text-gray-400 mt-0.5"><?php echo $cat['cnt']; ?> items</p>
                </div>
            </a>
            <?php endforeach; ?>
        </div>

    </div>
</section>
<?php endif; ?>


<!--FEATURED PRODUCTS -->
<?php if (!empty($featuredProducts)): ?>
<section class="bg-white py-14 sm:py-16">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Heading -->
        <div class="flex items-end justify-between mb-10">
            <div>
                <p class="text-xs font-bold uppercase tracking-widest text-brand-red mb-2">Fresh Arrivals</p>
                <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-900">Featured Products</h2>
            </div>
            <a href="<?php echo APP_URL; ?>/products.php"
               class="hidden sm:inline-flex items-center gap-1.5 text-sm font-semibold text-brand-red hover:underline">
                View all <i data-lucide="arrow-right" class="w-4 h-4"></i>
            </a>
        </div>

        <!-- Products grid -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 sm:gap-5">
            <?php foreach ($featuredProducts as $product):
                $stock   = (int) $product['stock_quantity'];
                $thresh  = (int) $product['low_stock_threshold'];
                $lowStock = $stock > 0 && $stock <= $thresh;
                $imgUrl   = $product['image_url'] ?? '';
                if ($imgUrl && !str_starts_with($imgUrl, 'http')) {
                    $imgUrl = APP_URL . '/' . ltrim($imgUrl, '/');
                }
            ?>
            <a href="<?php echo APP_URL; ?>/product-details.php?id=<?php echo $product['id']; ?>"
               class="product-card group bg-white border border-gray-100 rounded-2xl overflow-hidden shadow-sm hover:shadow-lg transition-all duration-300">

                <!-- Image -->
                <div class="relative aspect-square bg-gray-50 overflow-hidden">
                    <?php if ($imgUrl): ?>
                        <img src="<?php echo htmlspecialchars($imgUrl); ?>"
                             alt="<?php echo htmlspecialchars($product['name']); ?>"
                             class="product-image w-full h-full object-cover"
                             loading="lazy"
                             onerror="this.style.display='none';this.nextElementSibling.classList.remove('hidden')">
                        <div class="hidden w-full h-full flex items-center justify-center text-gray-300">
                            <i data-lucide="image-off" class="w-10 h-10"></i>
                        </div>
                    <?php else: ?>
                        <div class="w-full h-full flex flex-col items-center justify-center text-gray-200">
                            <i data-lucide="smartphone" class="w-14 h-14"></i>
                        </div>
                    <?php endif; ?>

                    <!-- Low stock badge -->
                    <?php if ($lowStock): ?>
                    <div class="absolute top-2 left-2 px-2 py-0.5 rounded-full bg-amber-500 text-white text-[10px] font-bold">
                        Only <?php echo $stock; ?> left
                    </div>
                    <?php endif; ?>

                    <!-- Quick-view overlay -->
                    <div class="absolute inset-0 bg-brand-dark/40 opacity-0 group-hover:opacity-100 transition-opacity duration-300 flex items-center justify-center">
                        <span class="px-4 py-2 bg-white text-gray-900 text-xs font-bold rounded-xl shadow">
                            View Details
                        </span>
                    </div>
                </div>

                <!-- Info -->
                <div class="p-3.5">
                    <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider mb-1">
                        <?php echo htmlspecialchars($product['brand'] ?: $product['category']); ?>
                    </p>
                    <h3 class="text-sm font-semibold text-gray-900 line-clamp-2 leading-snug">
                        <?php echo htmlspecialchars($product['name']); ?>
                    </h3>
                    <div class="flex items-center justify-between mt-3">
                        <span class="text-base font-extrabold text-brand-red">
                            Rs. <?php echo number_format((float)$product['price'], 2); ?>
                        </span>
                        <div class="w-8 h-8 rounded-xl bg-brand-red flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-200 flex-shrink-0">
                            <i data-lucide="plus" class="w-4 h-4 text-white"></i>
                        </div>
                    </div>
                </div>

            </a>
            <?php endforeach; ?>
        </div>

        <!-- Mobile view all -->
        <div class="mt-8 text-center sm:hidden">
            <a href="<?php echo APP_URL; ?>/products.php"
               class="inline-flex items-center gap-2 px-6 py-3 rounded-2xl bg-brand-red text-white text-sm font-semibold hover:bg-brand-redHover transition">
                View All Products <i data-lucide="arrow-right" class="w-4 h-4"></i>
            </a>
        </div>

    </div>
</section>
<?php endif; ?>


<!-- SERVICES BANNER  (3 service cards) -->
<section class="bg-gray-50 py-14 sm:py-16">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <div class="text-center mb-10">
            <p class="text-xs font-bold uppercase tracking-widest text-brand-red mb-2">What We Offer</p>
            <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-900">Our Services</h2>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">

            <!-- Repair -->
            <div class="group bg-white rounded-2xl border border-gray-100 shadow-sm p-6 hover:shadow-md hover:border-brand-red/20 transition-all duration-300">
                <div class="w-12 h-12 rounded-2xl bg-brand-red/10 flex items-center justify-center mb-4 group-hover:scale-110 transition-transform duration-300">
                    <i data-lucide="wrench" class="w-6 h-6 text-brand-red"></i>
                </div>
                <h3 class="text-base font-bold text-gray-900 mb-2">Device Repair</h3>
                <p class="text-sm text-gray-500 leading-relaxed mb-4">
                    Screen replacements, battery swaps, charging port fixes and more — done by certified technicians.
                </p>
                <a href="<?php echo APP_URL; ?>/repair-booking.php"
                   class="inline-flex items-center gap-1.5 text-xs font-bold text-brand-red hover:underline">
                    Book a Repair <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
                </a>
            </div>

            <!-- Warranty -->
            <div class="group bg-white rounded-2xl border border-gray-100 shadow-sm p-6 hover:shadow-md hover:border-green-200 transition-all duration-300">
                <div class="w-12 h-12 rounded-2xl bg-green-50 flex items-center justify-center mb-4 group-hover:scale-110 transition-transform duration-300">
                    <i data-lucide="shield-check" class="w-6 h-6 text-green-600"></i>
                </div>
                <h3 class="text-base font-bold text-gray-900 mb-2">Warranty Cover</h3>
                <p class="text-sm text-gray-500 leading-relaxed mb-4">
                    Every product comes with a warranty certificate. Check your warranty status anytime online.
                </p>
                <a href="<?php echo APP_URL; ?>/warranty-check.php"
                   class="inline-flex items-center gap-1.5 text-xs font-bold text-green-600 hover:underline">
                    Check Warranty <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
                </a>
            </div>

            <!-- Track -->
            <div class="group bg-white rounded-2xl border border-gray-100 shadow-sm p-6 hover:shadow-md hover:border-blue-200 transition-all duration-300">
                <div class="w-12 h-12 rounded-2xl bg-blue-50 flex items-center justify-center mb-4 group-hover:scale-110 transition-transform duration-300">
                    <i data-lucide="map-pin" class="w-6 h-6 text-blue-600"></i>
                </div>
                <h3 class="text-base font-bold text-gray-900 mb-2">Track Your Repair</h3>
                <p class="text-sm text-gray-500 leading-relaxed mb-4">
                    Get real-time updates on your device repair status using your unique repair code.
                </p>
                <a href="<?php echo APP_URL; ?>/repair-track.php"
                   class="inline-flex items-center gap-1.5 text-xs font-bold text-blue-600 hover:underline">
                    Track Now <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
                </a>
            </div>

        </div>
    </div>
</section>


<!-- WHY CHOOSE US  (features strip) -->
<section class="bg-brand-dark py-14 sm:py-16 rounded-3xl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <div class="text-center mb-10">
            <p class="text-xs font-bold uppercase tracking-widest text-brand-red mb-2">Why Us</p>
            <h2 class="text-2xl sm:text-3xl font-extrabold text-white">Why Choose Thirasara Max?</h2>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-6">

            <?php
            $features = [
                ['icon'=>'badge-check',    'title'=>'100% Genuine',      'desc'=>'We only stock authentic, quality-tested products.',           'color'=>'text-green-400'],
                ['icon'=>'truck',          'title'=>'Fast Delivery',      'desc'=>'Island-wide delivery with real-time order tracking.',         'color'=>'text-blue-400'],
                ['icon'=>'clock',          'title'=>'Quick Repairs',      'desc'=>'Most repairs completed same day or within 24 hours.',          'color'=>'text-amber-400'],
                ['icon'=>'headphones',     'title'=>'Always Here',        'desc'=>'Our support team is available to help whenever you need.',     'color'=>'text-purple-400'],
            ];
            foreach ($features as $f):
            ?>
            <div class="flex flex-col items-center text-center gap-3 p-5">
                <div class="w-12 h-12 rounded-2xl bg-white/5 border border-white/10 flex items-center justify-center">
                    <i data-lucide="<?php echo $f['icon']; ?>" class="w-6 h-6 <?php echo $f['color']; ?>"></i>
                </div>
                <h3 class="text-sm font-bold text-white"><?php echo $f['title']; ?></h3>
                <p class="text-xs text-gray-500 leading-relaxed"><?php echo $f['desc']; ?></p>
            </div>
            <?php endforeach; ?>

        </div>

    </div>
</section>


<!-- CTA BANNER -->
<section class="bg-brand-red py-12 sm:py-14 rounded-3xl mt-6">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">

        <h2 class="text-2xl sm:text-3xl font-extrabold text-white mb-3">
            Ready to upgrade your phone experience?
        </h2>
        <p class="text-red-100 text-sm sm:text-base mb-8 max-w-xl mx-auto">
            Browse our full collection of accessories and spare parts, or get your device repaired by our expert team today.
        </p>
        <div class="flex flex-col sm:flex-row gap-3 justify-center">
            <a href="<?php echo APP_URL; ?>/products.php"
               class="inline-flex items-center justify-center gap-2 px-7 py-3.5 rounded-2xl bg-white text-brand-red font-bold text-sm hover:bg-gray-100 transition shadow-lg">
                <i data-lucide="shopping-bag" class="w-4 h-4"></i>
                Shop Now
            </a>
            <?php if (!$currentUser): ?>
            <a href="<?php echo APP_URL; ?>/register.php"
               class="inline-flex items-center justify-center gap-2 px-7 py-3.5 rounded-2xl bg-white/10 hover:bg-white/20 border border-white/20 text-white font-bold text-sm transition">
                <i data-lucide="user-plus" class="w-4 h-4"></i>
                Create Account
            </a>
            <?php endif; ?>
        </div>

    </div>
</section>


<?php require_once __DIR__ . '/includes/footer.php'; ?>
