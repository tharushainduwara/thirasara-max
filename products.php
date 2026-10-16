<?php

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Products & Accessories';

$currentUser = getCurrentUser();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = Database::getConnection();

/* Cart Count */
$cartCount = 0;

if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $cartItem) {
        $cartCount += isset($cartItem['quantity']) ? (int) $cartItem['quantity'] : 0;
    }
}

/* Search / Filter / Sort */
$search = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? '');
$brand = trim($_GET['brand'] ?? '');
$sort = $_GET['sort'] ?? 'latest';

/* Allowed Sort Options */
$allowedSorts = [
    'latest' => 'p.created_at DESC',
    'oldest' => 'p.created_at ASC',
    'price_low' => 'p.price ASC',
    'price_high' => 'p.price DESC',
    'name_az' => 'p.name ASC',
    'name_za' => 'p.name DESC',
];

$orderBy = $allowedSorts[$sort] ?? $allowedSorts['latest'];

/* Load Categories */
$categories = [];

try {
    $categoryStmt = $pdo->query("
        SELECT DISTINCT category
        FROM products
        WHERE status != 'discontinued'
          AND category IS NOT NULL
          AND category != ''
        ORDER BY category ASC
    ");

    $categories = $categoryStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $categories = [];
}

/* Load Brands */
$brands = [];

try {
    $brandStmt = $pdo->query("
        SELECT DISTINCT brand
        FROM products
        WHERE status != 'discontinued'
          AND brand IS NOT NULL
          AND brand != ''
        ORDER BY brand ASC
    ");

    $brands = $brandStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $brands = [];
}

/*
| Build Product Query
| LEFT JOIN is used because a product may exist before
| an inventory record has been created.
*/
$products = [];

try {
    $sql = "
        SELECT 
            p.id, p.name, p.category, p.brand, p.description, p.price,
            p.is_repairable, p.warranty_period_months, p.image_url, p.status, p.created_at,
            COALESCE(i.stock_quantity, 0) AS stock_quantity,
            COALESCE(i.low_stock_threshold, 5) AS low_stock_threshold
        FROM products p
        LEFT JOIN inventory i ON i.product_id = p.id
        WHERE p.status != 'discontinued'
    ";

    $params = [];

    /* Search */
    if ($search !== '') {
        $sql .= "
            AND (
                p.name LIKE :search_name
                OR p.brand LIKE :search_brand
                OR p.category LIKE :search_category
                OR p.description LIKE :search_description
            )
        ";

        $searchValue = '%' . $search . '%';

        $params[':search_name'] = $searchValue;
        $params[':search_brand'] = $searchValue;
        $params[':search_category'] = $searchValue;
        $params[':search_description'] = $searchValue;
    }

    /* Category Filter */
    if ($category !== '') {
        $sql .= " AND p.category = :category ";
        $params[':category'] = $category;
    }

    /* Brand Filter */
    if ($brand !== '') {
        $sql .= " AND p.brand = :brand ";
        $params[':brand'] = $brand;
    }

    /* Sorting */
    $sql .= " ORDER BY {$orderBy} ";

    /* Execute Query */
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Products page database error: ' . $e->getMessage());
    $products = [];
}

/* Helper: Product Stock Status */
function getStockStatus(array $product): array
{
    $stock = (int) ($product['stock_quantity'] ?? 0);
    $threshold = (int) ($product['low_stock_threshold'] ?? 5);

    if ($stock <= 0) {
        return [
            'label' => 'Out of Stock',
            'class' => 'bg-red-500 text-white',
            'available' => false
        ];
    }

    if ($stock <= $threshold) {
        return [
            'label' => 'Only ' . $stock . ' left',
            'class' => 'bg-amber-500 text-white',
            'available' => true
        ];
    }

    return [
        'label' => 'In Stock',
        'class' => 'bg-emerald-600 text-white',
        'available' => true
    ];
}

/* Helper: Safe Image URL */
function productImageUrl(?string $imageUrl): string
{
    if (empty($imageUrl)) {
        return '';
    }

    if (
        str_starts_with($imageUrl, 'http://') ||
        str_starts_with($imageUrl, 'https://') ||
        str_starts_with($imageUrl, '//')
    ) {
        return $imageUrl;
    }

    return APP_URL . '/' . ltrim($imageUrl, '/');
}

$currentScript = basename($_SERVER['PHP_SELF']);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> | <?php echo htmlspecialchars(APP_NAME); ?></title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
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

    <style>
        /* Hide scrollbar for horizontal scrolling rows */
        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }
        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
    </style>
</head>

<body class="bg-gray-100 text-gray-800 flex flex-col min-h-screen font-sans antialiased">

    <?php require_once __DIR__ . '/includes/header.php'; ?>

    <!-- PAGE HEADER -->
    <section class="bg-gradient-to-r from-gray-900 via-zinc-900 to-gray-900 text-white border-b-4 border-brand-red py-10 rounded-3xl overflow-hidden">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded bg-brand-red text-white text-[10px] font-bold uppercase tracking-wider mb-2">
                        Catalog
                    </span>
                    <h1 class="text-2xl sm:text-3xl font-black uppercase tracking-tight">Products & Accessories</h1>
                    <p class="text-gray-300 text-xs sm:text-sm mt-1">Explore our full stock of authentic mobile parts, chargers, and accessories.</p>
                </div>
                <div class="text-xs font-bold text-gray-900 bg-white px-4 py-2 rounded-xl self-start md:self-auto shadow-sm">
                    Showing <span class="text-brand-red font-black"><?php echo count($products); ?></span> items
                </div>
            </div>
        </div>
    </section>

    <!-- MAIN CONTENT -->
    <main class="flex-1 py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <!-- Search + Filter Bar -->
            <form method="GET" action="<?php echo APP_URL; ?>/products.php" class="bg-white rounded-xl border-2 border-gray-200 p-4 sm:p-5 mb-8 shadow-sm">
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-3">

                    <!-- Search Input -->
                    <div class="lg:col-span-5 relative">
                        <label for="search" class="sr-only">Search products</label>
                        <i data-lucide="search" class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400"></i>
                        <input
                            type="search"
                            id="search"
                            name="search"
                            value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
                            placeholder="Search products, brands, parts..."
                            autocomplete="off"
                            class="w-full h-10 pl-10 pr-20 rounded-lg border border-gray-300 bg-gray-50 focus:bg-white focus:border-brand-red outline-none text-xs sm:text-sm transition">
                        <button type="submit" class="absolute right-1 top-1/2 -translate-y-1/2 h-8 px-3 rounded-md bg-brand-red hover:bg-brand-redHover text-white text-xs font-bold transition">
                            Search
                        </button>
                    </div>

                    <!-- Category Filter -->
                    <div class="lg:col-span-2">
                        <label for="category" class="sr-only">Category</label>
                        <select id="category" name="category" class="w-full h-10 px-3 rounded-lg border border-gray-300 bg-gray-50 focus:bg-white focus:border-brand-red outline-none text-xs sm:text-sm transition text-gray-700">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $itemCategory): ?>
                                <option value="<?php echo htmlspecialchars($itemCategory, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $category === $itemCategory ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($itemCategory); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Brand Filter -->
                    <div class="lg:col-span-2">
                        <label for="brand" class="sr-only">Brand</label>
                        <select id="brand" name="brand" class="w-full h-10 px-3 rounded-lg border border-gray-300 bg-gray-50 focus:bg-white focus:border-brand-red outline-none text-xs sm:text-sm transition text-gray-700">
                            <option value="">All Brands</option>
                            <?php foreach ($brands as $itemBrand): ?>
                                <option value="<?php echo htmlspecialchars($itemBrand, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $brand === $itemBrand ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($itemBrand); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Sort Options -->
                    <div class="lg:col-span-3">
                        <label for="sort" class="sr-only">Sort products</label>
                        <select id="sort" name="sort" class="w-full h-10 px-3 rounded-lg border border-gray-300 bg-gray-50 focus:bg-white focus:border-brand-red outline-none text-xs sm:text-sm transition text-gray-700">
                            <option value="latest" <?php echo $sort === 'latest' ? 'selected' : ''; ?>>Sort: Latest Arrivals</option>
                            <option value="price_low" <?php echo $sort === 'price_low' ? 'selected' : ''; ?>>Price: Low to High</option>
                            <option value="price_high" <?php echo $sort === 'price_high' ? 'selected' : ''; ?>>Price: High to Low</option>
                            <option value="name_az" <?php echo $sort === 'name_az' ? 'selected' : ''; ?>>Name: A - Z</option>
                            <option value="name_za" <?php echo $sort === 'name_za' ? 'selected' : ''; ?>>Name: Z - A</option>
                        </select>
                    </div>

                </div>

                <!-- Active Filters Row -->
                <?php if ($search !== '' || $category !== '' || $brand !== ''): ?>
                <div class="flex flex-wrap items-center gap-2 mt-4 pt-3 border-t border-gray-200 text-xs">
                    <span class="text-gray-500 font-bold">Active filters:</span>
                    <?php if ($search !== ''): ?>
                        <span class="px-2 py-0.5 rounded bg-gray-100 text-gray-800 font-semibold">Search: "<?php echo htmlspecialchars($search); ?>"</span>
                    <?php endif; ?>
                    <?php if ($category !== ''): ?>
                        <span class="px-2 py-0.5 rounded bg-gray-100 text-gray-800 font-semibold">Category: <?php echo htmlspecialchars($category); ?></span>
                    <?php endif; ?>
                    <?php if ($brand !== ''): ?>
                        <span class="px-2 py-0.5 rounded bg-gray-100 text-gray-800 font-semibold">Brand: <?php echo htmlspecialchars($brand); ?></span>
                    <?php endif; ?>
                    <a href="<?php echo APP_URL; ?>/products.php" class="text-brand-red font-bold hover:underline ml-auto flex items-center gap-1">
                        <i data-lucide="x" class="w-3.5 h-3.5"></i> Clear all
                    </a>
                </div>
                <?php endif; ?>
            </form>

            <!-- PRODUCT SECTIONS BY CATEGORY (HORIZONTAL SCROLLING) -->
            <?php if (!empty($products)): ?>

                <?php
                $grouped = [];
                foreach ($products as $product) {
                    $cat = !empty($product['category']) ? $product['category'] : 'Other';
                    $grouped[$cat][] = $product;
                }
                ksort($grouped);
                $sectionIndex = 0;
                ?>

                <?php foreach ($grouped as $catName => $catProducts): ?>
                    <?php $rowId = 'cat-row-' . $sectionIndex++; ?>

                    <section class="mb-12">
                        <!-- Category Header with Scroll Buttons -->
                        <div class="flex items-center justify-between mb-4 pb-2 border-b-2 border-gray-200">
                            <div class="flex items-center gap-2.5">
                                <h2 class="text-lg sm:text-xl font-black text-gray-900 uppercase tracking-tight">
                                    <?php echo htmlspecialchars($catName); ?>
                                </h2>
                                <span class="text-xs font-bold bg-gray-200 text-gray-800 px-2 py-0.5 rounded">
                                    <?php echo count($catProducts); ?>
                                </span>
                            </div>

                            <!-- Scroll Arrows -->
                            <div class="flex items-center gap-1.5">
                                <button type="button" onclick="scrollRow('<?php echo $rowId; ?>', -1)" class="w-8 h-8 rounded-lg bg-white border-2 border-gray-200 hover:border-gray-400 text-gray-700 flex items-center justify-center transition shadow-sm" aria-label="Scroll left">
                                    <i data-lucide="chevron-left" class="w-4 h-4"></i>
                                </button>
                                <button type="button" onclick="scrollRow('<?php echo $rowId; ?>', 1)" class="w-8 h-8 rounded-lg bg-white border-2 border-gray-200 hover:border-gray-400 text-gray-700 flex items-center justify-center transition shadow-sm" aria-label="Scroll right">
                                    <i data-lucide="chevron-right" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Horizontal Scrolling Row Container -->
                        <div id="<?php echo $rowId; ?>" class="flex gap-4 overflow-x-auto no-scrollbar scroll-smooth pb-2 pt-1 px-1">
                            <?php foreach ($catProducts as $product): 
                                $stockStatus = getStockStatus($product);
                                $imageUrl = productImageUrl($product['image_url'] ?? null);
                                $productName = $product['name'] ?? 'Product';
                            ?>
                            <div class="w-[220px] sm:w-[240px] flex-shrink-0 bg-white border-2 border-gray-200 rounded-xl overflow-hidden hover:border-gray-400 hover:shadow-md transition-all flex flex-col justify-between">
                                
                                <!-- Image Container -->
                                <div class="relative aspect-square bg-gray-50 border-b border-gray-200 overflow-hidden">
                                    <?php if ($imageUrl): ?>
                                        <img src="<?php echo htmlspecialchars($imageUrl); ?>"
                                             alt="<?php echo htmlspecialchars($productName); ?>"
                                             class="w-full h-full object-cover">
                                    <?php else: ?>
                                        <div class="w-full h-full flex items-center justify-center text-gray-300">
                                            <i data-lucide="smartphone" class="w-12 h-12"></i>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Stock Badge -->
                                    <span class="absolute top-2 left-2 px-2 py-0.5 rounded text-[10px] font-extrabold uppercase tracking-wider <?php echo $stockStatus['class']; ?>">
                                        <?php echo htmlspecialchars($stockStatus['label']); ?>
                                    </span>

                                    <!-- Repairable Badge -->
                                    <?php if (!empty($product['is_repairable'])): ?>
                                    <span class="absolute bottom-2 left-2 inline-flex items-center gap-1 px-2 py-0.5 rounded bg-gray-900 text-white text-[10px] font-bold">
                                        <i data-lucide="wrench" class="w-3 h-3"></i> Repairable
                                    </span>
                                    <?php endif; ?>
                                </div>

                                <!-- Content Details -->
                                <div class="p-3.5 flex flex-col flex-grow justify-between">
                                    <div>
                                        <span class="text-[10px] font-bold text-brand-red uppercase tracking-wider">
                                            <?php echo htmlspecialchars($product['brand'] ?: $product['category']); ?>
                                        </span>
                                        <h3 class="text-xs sm:text-sm font-bold text-gray-900 line-clamp-2 mt-0.5">
                                            <a href="<?php echo APP_URL; ?>/product-details.php?id=<?php echo (int) $product['id']; ?>" class="hover:underline">
                                                <?php echo htmlspecialchars($productName); ?>
                                            </a>
                                        </h3>
                                    </div>

                                    <div class="mt-3 pt-2 border-t border-gray-100">
                                        <div class="flex items-center justify-between mb-2">
                                            <span class="text-sm sm:text-base font-extrabold text-gray-900">
                                                Rs. <?php echo number_format((float) $product['price'], 2); ?>
                                            </span>
                                        </div>

                                        <div class="grid grid-cols-2 gap-2">
                                            <a href="<?php echo APP_URL; ?>/product-details.php?id=<?php echo (int) $product['id']; ?>"
                                               class="w-full h-8 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-800 text-xs font-bold flex items-center justify-center transition">
                                                Details
                                            </a>
                                            <?php if ($stockStatus['available']): ?>
                                            <form method="POST" action="<?php echo APP_URL; ?>/cart.php">
                                                <input type="hidden" name="action" value="add">
                                                <input type="hidden" name="product_id" value="<?php echo (int) $product['id']; ?>">
                                                <input type="hidden" name="quantity" value="1">
                                                <button type="submit" class="w-full h-8 rounded-lg bg-brand-red hover:bg-brand-redHover text-white text-xs font-bold flex items-center justify-center transition">
                                                    Add
                                                </button>
                                            </form>
                                            <?php else: ?>
                                            <button type="button" disabled class="w-full h-8 rounded-lg bg-gray-100 text-gray-400 text-xs font-bold flex items-center justify-center cursor-not-allowed">
                                                Out
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                            </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>

            <?php else: ?>
                <!-- EMPTY STATE -->
                <div class="bg-white rounded-xl border-2 border-gray-200 py-16 px-6 text-center shadow-sm">
                    <div class="w-16 h-16 mx-auto rounded-xl bg-gray-100 flex items-center justify-center mb-3 text-gray-400">
                        <i data-lucide="package-search" class="w-8 h-8"></i>
                    </div>
                    <h2 class="text-base font-bold text-gray-900">No matching products found</h2>
                    <p class="text-xs text-gray-500 mt-1 max-w-sm mx-auto">Try modifying your search keywords or resetting your filter selections.</p>
                    <a href="<?php echo APP_URL; ?>/products.php" class="inline-flex items-center gap-2 mt-5 px-5 py-2.5 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-xs font-bold transition">
                        Reset All Filters
                    </a>
                </div>
            <?php endif; ?>

        </div>
    </main>

    <!-- Scroll Row JS Function -->
    <script>
        function scrollRow(rowId, direction) {
            const row = document.getElementById(rowId);
            if (row) {
                const scrollAmount = 260 * direction;
                row.scrollBy({ left: scrollAmount, behavior: 'smooth' });
            }
        }
    </script>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>

</body>
</html>