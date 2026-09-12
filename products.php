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

/*Cart Count*/
$cartCount = 0;

if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $cartItem) {
        $cartCount += isset($cartItem['quantity'])
            ? (int) $cartItem['quantity']
            : 0;
    }
}

/*Search / Filter / Sort*/
$search = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? '');
$brand = trim($_GET['brand'] ?? '');
$sort = $_GET['sort'] ?? 'latest';

/*Allowed Sort Options*/
$allowedSorts = [
    'latest' => 'p.created_at DESC',
    'oldest' => 'p.created_at ASC',
    'price_low' => 'p.price ASC',
    'price_high' => 'p.price DESC',
    'name_az' => 'p.name ASC',
    'name_za' => 'p.name DESC',
];

$orderBy = $allowedSorts[$sort] ?? $allowedSorts['latest'];

/*Load Categories*/
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

/*Load Brands*/
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
            p.id,
            p.name,
            p.category,
            p.brand,
            p.description,
            p.price,
            p.is_repairable,
            p.warranty_period_months,
            p.image_url,
            p.status,
            p.created_at,

            COALESCE(i.stock_quantity, 0) AS stock_quantity,
            COALESCE(i.low_stock_threshold, 5) AS low_stock_threshold

        FROM products p

        LEFT JOIN inventory i
            ON i.product_id = p.id

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

        $sql .= "
            AND p.category = :category
        ";

        $params[':category'] = $category;
    }

    /*Brand Filter */
    if ($brand !== '') {

        $sql .= "
            AND p.brand = :brand
        ";

        $params[':brand'] = $brand;
    }

    /* Sorting  */
    $sql .= "
        ORDER BY {$orderBy}
    ";

    /*Execute Query */
    $stmt = $pdo->prepare($sql);

    $stmt->execute($params);

    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    /*
    | Log the real database error so it can be
    | checked in PHP/XAMPP logs.
    */
    error_log(
        'Products page database error: ' . $e->getMessage()
    );

    $products = [];
}

/*Helper: Product Image*/
function getProductImage(?string $imageUrl): string
{
    if (empty($imageUrl)) {
        return '';
    }

    return $imageUrl;
}

/*Helper: Product Stock Status*/
function getStockStatus(array $product): array
{
    $stock = (int) ($product['stock_quantity'] ?? 0);
    $threshold = (int) ($product['low_stock_threshold'] ?? 5);

    if ($stock <= 0) {

        return [
            'label' => 'Out of Stock',
            'class' => 'bg-red-100 text-red-700 border-red-200',
            'dot' => 'bg-red-500',
            'available' => false
        ];
    }

    if ($stock <= $threshold) {

        return [
            'label' => 'Only ' . $stock . ' left',
            'class' => 'bg-amber-100 text-amber-700 border-amber-200',
            'dot' => 'bg-amber-500',
            'available' => true
        ];
    }

    return [
        'label' => 'In Stock',
        'class' => 'bg-green-100 text-green-700 border-green-200',
        'dot' => 'bg-green-500',
        'available' => true
    ];
}

/*Helper: Safe Image URL*/
function productImageUrl(?string $imageUrl): string
{
    if (empty($imageUrl)) {
        return '';
    }

    /*
     * If the database contains an absolute URL,
     * use it directly.
     */
    if (
        str_starts_with($imageUrl, 'http://') ||
        str_starts_with($imageUrl, 'https://') ||
        str_starts_with($imageUrl, '//')
    ) {
        return $imageUrl;
    }

    /* Otherwise treat it as an application-relative path. */
    return APP_URL . '/' . ltrim($imageUrl, '/');
}

$currentScript = basename($_SERVER['PHP_SELF']);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>
        <?php echo htmlspecialchars($pageTitle); ?>
        |
        <?php echo htmlspecialchars(APP_NAME); ?>
    </title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">

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
        html {
            scroll-behavior: smooth;
        }

        .product-card {
            transition:
                transform 0.25s ease,
                box-shadow 0.25s ease,
                border-color 0.25s ease;
        }

        .product-card:hover {
            transform: translateY(-4px);
        }

        .product-image {
            transition: transform 0.35s ease;
        }

        .product-card:hover .product-image {
            transform: scale(1.04);
        }

        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .filter-scroll::-webkit-scrollbar {
            height: 4px;
        }

        .filter-scroll::-webkit-scrollbar-track {
            background: #f3f4f6;
        }

        .filter-scroll::-webkit-scrollbar-thumb {
            background: #d1d5db;
            border-radius: 999px;
        }
    </style>

</head>


<body class="bg-gray-50 text-gray-800 flex flex-col min-h-screen font-sans antialiased">

    <?php require_once __DIR__ . '/includes/header.php'; ?>

    <!--PAGE HEADER -->

    <section class="bg-brand-dark text-white rounded-3xl shadow-sm">

        <div
            class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 sm:py-16">

            <div class="max-w-3xl">

                <div
                    class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-white/5 border border-white/10 text-xs font-semibold text-gray-300 mb-5">

                    <span
                        class="w-1.5 h-1.5 rounded-full bg-brand-red"></span>

                    THIRASARA MAX MOBILE

                </div>


                <h1
                    class="text-3xl sm:text-4xl lg:text-5xl font-extrabold tracking-tight">
                    Products & Accessories
                </h1>


                <p
                    class="mt-4 text-gray-400 text-sm sm:text-base leading-relaxed max-w-2xl">
                    Explore our collection of mobile accessories, spare parts,
                    and other quality products from Thirasara Max Mobile.
                </p>

            </div>

        </div>

    </section>


    <!-- MAIN CONTENT-->

    <main
        class="flex-1">

        <div
            class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10">


            <!-- Search + Filter -->

            <form
                method="GET"
                action="<?php echo APP_URL; ?>/products.php"
                class="bg-white rounded-2xl border border-gray-200 shadow-sm p-4 sm:p-5 mb-8">

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-3">

                    <!-- Search -->

                    <div class="lg:col-span-5">

                        <label
                            for="search"
                            class="sr-only">
                            Search products
                        </label>

                        <div class="relative">

                            <i
                                data-lucide="search"
                                class="absolute left-3.5 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400">
                            </i>

                            <input
                                type="search"
                                id="search"
                                name="search"
                                value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
                                placeholder="Search products, brands..."
                                autocomplete="off"
                                class="w-full h-11 pl-11 pr-24 rounded-xl border border-gray-200 bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/10 outline-none text-sm transition">

                            <button
                                type="submit"
                                class="absolute right-1.5 top-1/2 -translate-y-1/2 h-8 px-3 rounded-lg bg-brand-red hover:bg-brand-redHover text-white text-xs font-bold transition">

                                Search

                            </button>

                        </div>

                    </div>


                    <!-- Category -->

                    <div class="lg:col-span-2">

                        <label
                            for="category"
                            class="sr-only">
                            Category
                        </label>

                        <select
                            id="category"
                            name="category"
                            class="w-full h-11 px-3 rounded-xl border border-gray-200 bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/10 outline-none text-sm transition">

                            <option value="">
                                All Categories
                            </option>

                            <?php foreach ($categories as $itemCategory): ?>

                                <option
                                    value="<?php echo htmlspecialchars($itemCategory, ENT_QUOTES, 'UTF-8'); ?>"
                                    <?php echo $category === $itemCategory ? 'selected' : ''; ?>>

                                    <?php echo htmlspecialchars($itemCategory); ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- Brand -->

                    <div class="lg:col-span-2">

                        <label
                            for="brand"
                            class="sr-only">
                            Brand
                        </label>

                        <select
                            id="brand"
                            name="brand"
                            class="w-full h-11 px-3 rounded-xl border border-gray-200 bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/10 outline-none text-sm transition">

                            <option value="">
                                All Brands
                            </option>

                            <?php foreach ($brands as $itemBrand): ?>

                                <option
                                    value="<?php echo htmlspecialchars($itemBrand, ENT_QUOTES, 'UTF-8'); ?>"
                                    <?php echo $brand === $itemBrand ? 'selected' : ''; ?>>

                                    <?php echo htmlspecialchars($itemBrand); ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- Sort -->

                    <div class="lg:col-span-2">

                        <label
                            for="sort"
                            class="sr-only">
                            Sort products
                        </label>

                        <select
                            id="sort"
                            name="sort"
                            class="w-full h-11 px-3 rounded-xl border border-gray-200 bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/10 outline-none text-sm transition">

                            <option
                                value="latest"
                                <?php echo $sort === 'latest' ? 'selected' : ''; ?>>
                                Latest
                            </option>

                            <option
                                value="price_low"
                                <?php echo $sort === 'price_low' ? 'selected' : ''; ?>>
                                Price: Low to High
                            </option>

                            <option
                                value="price_high"
                                <?php echo $sort === 'price_high' ? 'selected' : ''; ?>>
                                Price: High to Low
                            </option>

                            <option
                                value="name_az"
                                <?php echo $sort === 'name_az' ? 'selected' : ''; ?>>
                                Name: A-Z
                            </option>

                            <option
                                value="name_za"
                                <?php echo $sort === 'name_za' ? 'selected' : ''; ?>>
                                Name: Z-A
                            </option>

                        </select>

                    </div>

                </div>


                <!-- Result Count / Clear -->

                <div
                    class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mt-4">

                    <div class="text-sm text-gray-500">

                        <?php echo count($products); ?>

                        product<?php echo count($products) === 1 ? '' : 's'; ?>

                        found

                    </div>


                    <?php if ($search !== '' || $category !== '' || $brand !== ''): ?>

                        <a
                            href="<?php echo APP_URL; ?>/products.php"
                            class="inline-flex items-center gap-2 text-sm font-semibold text-brand-red hover:text-brand-redHover transition">

                            <i
                                data-lucide="x"
                                class="w-4 h-4">
                            </i>

                            Clear filters

                        </a>

                    <?php endif; ?>

                </div>

            </form>


            <!-- Active Filters -->

            <?php if ($search !== '' || $category !== '' || $brand !== ''): ?>

                <div
                    class="flex flex-wrap items-center gap-2 mb-6">

                    <span class="text-xs font-semibold text-gray-500">
                        Active filters:
                    </span>


                    <?php if ($search !== ''): ?>

                        <span
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-gray-100 text-gray-700 text-xs font-medium">

                            Search:
                            <?php echo htmlspecialchars($search); ?>

                        </span>

                    <?php endif; ?>


                    <?php if ($category !== ''): ?>

                        <span
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-gray-100 text-gray-700 text-xs font-medium">

                            Category:
                            <?php echo htmlspecialchars($category); ?>

                        </span>

                    <?php endif; ?>


                    <?php if ($brand !== ''): ?>

                        <span
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-gray-100 text-gray-700 text-xs font-medium">

                            Brand:
                            <?php echo htmlspecialchars($brand); ?>

                        </span>

                    <?php endif; ?>

                </div>

            <?php endif; ?>


            <!--PRODUCT GRID -->

            <?php if (!empty($products)): ?>

                <div
                    class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5 sm:gap-6">

                    <?php foreach ($products as $product): ?>

                        <?php

                        $stockStatus = getStockStatus($product);

                        $imageUrl = productImageUrl(
                            $product['image_url'] ?? null
                        );

                        $productName = $product['name'] ?? 'Product';

                        $description = trim(
                            $product['description'] ?? ''
                        );

                        ?>

                        <article
                            class="product-card bg-white rounded-2xl border border-gray-200 overflow-hidden shadow-sm hover:shadow-xl hover:border-gray-300 flex flex-col">


                            <!-- Product Image -->

                            <div
                                class="relative h-56 bg-gray-100 overflow-hidden">

                                <?php if ($imageUrl): ?>

                                    <img
                                        src="<?php echo htmlspecialchars($imageUrl); ?>"
                                        alt="<?php echo htmlspecialchars($productName); ?>"
                                        class="product-image w-full h-full object-cover"
                                        loading="lazy"
                                        onerror="this.style.display='none'; this.nextElementSibling.classList.remove('hidden');">

                                    <div
                                        class="hidden absolute inset-0 flex flex-col items-center justify-center text-gray-400">

                                        <i
                                            data-lucide="image-off"
                                            class="w-10 h-10 mb-2"></i>

                                        <span class="text-xs">
                                            Image unavailable
                                        </span>

                                    </div>

                                <?php else: ?>

                                    <div
                                        class="absolute inset-0 flex flex-col items-center justify-center text-gray-400">

                                        <div
                                            class="w-16 h-16 rounded-2xl bg-white flex items-center justify-center shadow-sm mb-3">

                                            <i
                                                data-lucide="package"
                                                class="w-8 h-8"></i>

                                        </div>

                                        <span class="text-xs font-medium">
                                            No image available
                                        </span>

                                    </div>

                                <?php endif; ?>


                                <!-- Category Badge -->

                                <?php if (!empty($product['category'])): ?>

                                    <span
                                        class="absolute top-3 left-3 px-2.5 py-1 rounded-full bg-white/95 backdrop-blur text-gray-700 text-[11px] font-semibold shadow-sm">
                                        <?php echo htmlspecialchars($product['category']); ?>
                                    </span>

                                <?php endif; ?>


                                <!-- Stock Badge -->

                                <span
                                    class="absolute top-3 right-3 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border text-[11px] font-semibold <?php echo $stockStatus['class']; ?>">

                                    <span
                                        class="w-1.5 h-1.5 rounded-full <?php echo $stockStatus['dot']; ?>"></span>

                                    <?php echo htmlspecialchars($stockStatus['label']); ?>

                                </span>


                                <!-- Repairable Badge -->

                                <?php if (!empty($product['is_repairable'])): ?>

                                    <span
                                        class="absolute bottom-3 left-3 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-brand-dark/90 text-white text-[10px] font-semibold backdrop-blur">

                                        <i
                                            data-lucide="wrench"
                                            class="w-3 h-3"></i>

                                        Repairable

                                    </span>

                                <?php endif; ?>

                            </div>


                            <!-- Product Details -->

                            <div
                                class="p-5 flex flex-col flex-1">


                                <!-- Brand -->

                                <?php if (!empty($product['brand'])): ?>

                                    <p
                                        class="text-[11px] uppercase tracking-wider font-bold text-brand-red mb-1.5">
                                        <?php echo htmlspecialchars($product['brand']); ?>
                                    </p>

                                <?php endif; ?>


                                <!-- Name -->

                                <h2
                                    class="text-base font-bold text-gray-900 line-clamp-2 min-h-[48px]">

                                    <?php echo htmlspecialchars($productName); ?>

                                </h2>


                                <!-- Description -->

                                <p
                                    class="text-sm text-gray-500 line-clamp-2 mt-2 min-h-[40px]">

                                    <?php

                                    echo $description !== ''
                                        ? htmlspecialchars($description)
                                        : 'Quality mobile product from Thirasara Max Mobile.';

                                    ?>

                                </p>


                                <!-- Warranty -->

                                <div
                                    class="flex items-center gap-2 mt-4 text-xs text-gray-500">

                                    <i
                                        data-lucide="shield-check"
                                        class="w-4 h-4 text-brand-red"></i>

                                    <?php

                                    $warrantyMonths = (int) (
                                        $product['warranty_period_months'] ?? 0
                                    );

                                    if ($warrantyMonths > 0) {

                                        echo $warrantyMonths . ' month';

                                        echo $warrantyMonths === 1
                                            ? ''
                                            : 's';

                                        echo ' warranty';
                                    } else {

                                        echo 'Warranty not specified';
                                    }

                                    ?>

                                </div>


                                <!-- Price + Button -->

                                <div
                                    class="mt-auto pt-5">

                                    <div
                                        class="flex items-end justify-between gap-3">

                                        <div>

                                            <p
                                                class="text-xs text-gray-400 mb-1">
                                                Price
                                            </p>

                                            <p
                                                class="text-xl font-extrabold text-gray-900">

                                                Rs.
                                                <?php
                                                echo number_format(
                                                    (float) $product['price'],
                                                    2
                                                );
                                                ?>

                                            </p>

                                        </div>


                                        <!-- Details Button -->

                                        <a
                                            href="<?php echo APP_URL; ?>/product-details.php?id=<?php echo (int) $product['id']; ?>"
                                            class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-gray-100 hover:bg-brand-red hover:text-white text-gray-700 transition"
                                            title="View product">

                                            <i
                                                data-lucide="arrow-up-right"
                                                class="w-5 h-5"></i>

                                        </a>

                                    </div>


                                    <!-- Add to Cart -->

                                    <?php if ($stockStatus['available']): ?>

                                        <form
                                            method="POST"
                                            action="<?php echo APP_URL; ?>/cart.php"
                                            class="mt-4">

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="add">

                                            <input
                                                type="hidden"
                                                name="product_id"
                                                value="<?php echo (int) $product['id']; ?>">

                                            <input
                                                type="hidden"
                                                name="quantity"
                                                value="1">

                                            <button
                                                type="submit"
                                                class="w-full h-11 inline-flex items-center justify-center gap-2 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-sm font-bold transition shadow-sm hover:shadow-md">

                                                <i
                                                    data-lucide="shopping-cart"
                                                    class="w-4 h-4"></i>

                                                Add to Cart

                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <button
                                            type="button"
                                            disabled
                                            class="mt-4 w-full h-11 inline-flex items-center justify-center gap-2 rounded-xl bg-gray-100 text-gray-400 text-sm font-bold cursor-not-allowed">

                                            <i
                                                data-lucide="package-x"
                                                class="w-4 h-4"></i>

                                            Out of Stock

                                        </button>

                                    <?php endif; ?>

                                </div>

                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>


            <?php else: ?>


                <!--EMPTY STATE-->

                <div
                    class="bg-white rounded-2xl border border-gray-200 shadow-sm py-16 px-6 text-center">

                    <div
                        class="w-20 h-20 mx-auto rounded-2xl bg-gray-100 flex items-center justify-center mb-5">

                        <i
                            data-lucide="package-search"
                            class="w-10 h-10 text-gray-400"></i>

                    </div>


                    <h2
                        class="text-xl font-bold text-gray-900">
                        No products found
                    </h2>


                    <p
                        class="mt-2 max-w-md mx-auto text-sm text-gray-500 leading-relaxed">

                        We couldn't find any products matching your current
                        search or filters. Try changing your search criteria.

                    </p>


                    <a
                        href="<?php echo APP_URL; ?>/products.php"
                        class="inline-flex items-center gap-2 mt-6 px-5 py-2.5 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-sm font-bold transition">

                        <i
                            data-lucide="rotate-ccw"
                            class="w-4 h-4"></i>

                        View All Products

                    </a>

                </div>


            <?php endif; ?>


        </div>

    </main>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>


    <!--  JAVASCRIPT-->

    <script>
        lucide.createIcons();


        /* Mobile menu */

        const mobileMenuButton =
            document.getElementById('mobile-menu-btn');

        const mobileMenu =
            document.getElementById('mobile-menu');


        if (mobileMenuButton && mobileMenu) {

            mobileMenuButton.addEventListener('click', function() {

                mobileMenu.classList.toggle('hidden');

            });

        }


        /* Automatically submit category, brand and sort */

        const filterSelects = [
            document.getElementById('category'),
            document.getElementById('brand'),
            document.getElementById('sort')
        ];


        filterSelects.forEach(function(select) {

            if (select) {

                select.addEventListener('change', function() {

                    this.form.submit();

                });

            }

        });
    </script>

</body>

</html>