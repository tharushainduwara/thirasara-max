<?php

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Product Details';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = Database::getConnection();

$productId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$productId || $productId < 1) {
    header('Location: ' . APP_URL . '/products.php');
    exit;
}

/* Load product*/
$product = null;

try {
    $stmt = $pdo->prepare("
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
        LEFT JOIN inventory i ON i.product_id = p.id
        WHERE p.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Product details error: ' . $e->getMessage());
}

if (!$product || $product['status'] === 'discontinued') {
    http_response_code(404);
    $pageTitle = 'Product Not Found';
}

$specifications = [];
$images = [];
$relatedProducts = [];

if ($product) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, spec_group, spec_name, spec_value, sort_order
            FROM product_specifications
            WHERE product_id = :product_id
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute([':product_id' => $productId]);
        $specifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Product specification error: ' . $e->getMessage());
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id, image_url, alt_text, sort_order, is_primary
            FROM product_images
            WHERE product_id = :product_id
            ORDER BY is_primary DESC, sort_order ASC, id ASC
        ");
        $stmt->execute([':product_id' => $productId]);
        $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Product images error: ' . $e->getMessage());
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                p.id, p.name, p.brand, p.category, p.price, p.image_url,
                COALESCE(i.stock_quantity, 0) AS stock_quantity
            FROM products p
            LEFT JOIN inventory i ON i.product_id = p.id
            WHERE p.id <> :id
              AND p.category = :category
              AND p.status = 'available'
            ORDER BY p.created_at DESC
            LIMIT 4
        ");
        $stmt->execute([
            ':id' => $productId,
            ':category' => $product['category']
        ]);
        $relatedProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Related products error: ' . $e->getMessage());
    }
}

function detailImageUrl(?string $url): string
{
    if (!$url) {
        return '';
    }

    if (
        str_starts_with($url, 'http://') ||
        str_starts_with($url, 'https://') ||
        str_starts_with($url, '//')
    ) {
        return $url;
    }

    return APP_URL . '/' . ltrim($url, '/');
}

function detailStock(array $product): array
{
    $stock = (int)($product['stock_quantity'] ?? 0);
    $threshold = (int)($product['low_stock_threshold'] ?? 5);

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

$currentUser = function_exists('getCurrentUser') ? getCurrentUser() : null;
$stock = $product ? detailStock($product) : null;

$gallery = [];

if ($product && !empty($product['image_url'])) {
    $gallery[] = [
        'url' => detailImageUrl($product['image_url']),
        'alt' => $product['name'],
        'primary' => true
    ];
}

foreach ($images as $image) {
    $url = detailImageUrl($image['image_url'] ?? '');
    if ($url === '') {
        continue;
    }

    $exists = false;
    foreach ($gallery as $existing) {
        if ($existing['url'] === $url) {
            $exists = true;
            break;
        }
    }

    if (!$exists) {
        $gallery[] = [
            'url' => $url,
            'alt' => $image['alt_text'] ?: $product['name'],
            'primary' => (bool)$image['is_primary']
        ];
    }
}

$groupedSpecs = [];
foreach ($specifications as $spec) {
    $group = trim($spec['spec_group'] ?: 'Specifications');
    $groupedSpecs[$group][] = $spec;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?php echo htmlspecialchars($pageTitle); ?>
        |
        <?php echo htmlspecialchars(APP_NAME); ?>
    </title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">

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

    <script src="https://unpkg.com/lucide@latest"></script>

    <style>
        html {
            scroll-behavior: smooth;
        }

        .gallery-thumb.active {
            border-color: #e52b34;
            box-shadow: 0 0 0 2px rgba(229, 43, 52, .12);
        }

        .gallery-main {
            transition: opacity .18s ease, transform .25s ease;
        }

        .spec-row:last-child {
            border-bottom: 0;
        }
    </style>
</head>

<body class="bg-gray-50 text-gray-800 flex flex-col min-h-screen font-sans antialiased">

<?php require_once __DIR__ . '/includes/header.php'; ?>

<?php if (!$product): ?>

    <main class="flex-1">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
            <div class="bg-white border border-gray-200 rounded-3xl p-10 text-center shadow-sm">
                <div class="w-20 h-20 mx-auto rounded-2xl bg-gray-100 flex items-center justify-center">
                    <i data-lucide="package-x" class="w-10 h-10 text-gray-400"></i>
                </div>

                <h1 class="mt-6 text-2xl font-extrabold text-gray-900">
                    Product not found
                </h1>

                <p class="mt-2 text-sm text-gray-500">
                    The product you are looking for is unavailable or no longer exists.
                </p>

                <a
                    href="<?php echo APP_URL; ?>/products.php"
                    class="inline-flex items-center gap-2 mt-6 px-5 py-3 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-sm font-bold transition">
                    <i data-lucide="arrow-left" class="w-4 h-4"></i>
                    Back to Products
                </a>
            </div>
        </div>
    </main>

<?php else: ?>

    <main class="flex-1">

        <!-- Breadcrumb -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-6">
            <nav class="flex flex-wrap items-center gap-2 text-sm text-gray-500">
                <a
                    href="<?php echo APP_URL; ?>/products.php"
                    class="hover:text-brand-red transition">
                    Products
                </a>

                <i data-lucide="chevron-right" class="w-4 h-4"></i>

                <span><?php echo htmlspecialchars($product['category']); ?></span>

                <i data-lucide="chevron-right" class="w-4 h-4"></i>

                <span class="text-gray-900 font-medium">
                    <?php echo htmlspecialchars($product['name']); ?>
                </span>
            </nav>
        </div>

        <!-- Product Hero -->
        <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8">

            <div class="bg-white rounded-3xl border border-gray-200 shadow-sm overflow-hidden">

                <div class="grid grid-cols-1 lg:grid-cols-2">

                    <!-- Gallery -->
                    <div class="p-5 sm:p-8 bg-gray-50 border-b lg:border-b-0 lg:border-r border-gray-200">

                        <div class="relative aspect-square rounded-2xl bg-white border border-gray-200 overflow-hidden">

                            <?php if (!empty($gallery)): ?>

                                <img
                                    id="mainProductImage"
                                    src="<?php echo htmlspecialchars($gallery[0]['url']); ?>"
                                    alt="<?php echo htmlspecialchars($gallery[0]['alt']); ?>"
                                    class="gallery-main w-full h-full object-contain p-6 sm:p-10"
                                    onerror="this.style.display='none'; document.getElementById('imageFallback').classList.remove('hidden');">

                                <div
                                    id="imageFallback"
                                    class="hidden absolute inset-0 flex flex-col items-center justify-center text-gray-400">
                                    <i data-lucide="image-off" class="w-12 h-12 mb-3"></i>
                                    <span class="text-sm">Image unavailable</span>
                                </div>

                            <?php else: ?>

                                <div class="absolute inset-0 flex flex-col items-center justify-center text-gray-400">
                                    <div class="w-20 h-20 rounded-2xl bg-gray-100 flex items-center justify-center">
                                        <i data-lucide="package" class="w-10 h-10"></i>
                                    </div>
                                    <span class="text-sm mt-3">No product image</span>
                                </div>

                            <?php endif; ?>

                            <span
                                class="absolute top-4 left-4 px-3 py-1.5 rounded-full bg-white/95 border border-gray-200 text-xs font-bold text-gray-700 shadow-sm">
                                <?php echo htmlspecialchars($product['category']); ?>
                            </span>
                        </div>

                        <?php if (count($gallery) > 1): ?>

                            <div class="grid grid-cols-5 gap-3 mt-4">

                                <?php foreach ($gallery as $index => $image): ?>

                                    <button
                                        type="button"
                                        class="gallery-thumb <?php echo $index === 0 ? 'active' : ''; ?> aspect-square rounded-xl bg-white border-2 border-gray-200 overflow-hidden transition hover:border-brand-red"
                                        data-image="<?php echo htmlspecialchars($image['url']); ?>"
                                        data-alt="<?php echo htmlspecialchars($image['alt']); ?>">

                                        <img
                                            src="<?php echo htmlspecialchars($image['url']); ?>"
                                            alt="<?php echo htmlspecialchars($image['alt']); ?>"
                                            class="w-full h-full object-contain p-1"
                                            loading="lazy">

                                    </button>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>

                    </div>

                    <!-- Main Information -->
                    <div class="p-6 sm:p-8 lg:p-10 flex flex-col">

                        <?php if (!empty($product['brand'])): ?>
                            <p class="text-xs uppercase tracking-[.18em] font-extrabold text-brand-red">
                                <?php echo htmlspecialchars($product['brand']); ?>
                            </p>
                        <?php endif; ?>

                        <h1 class="mt-2 text-3xl sm:text-4xl font-extrabold tracking-tight text-gray-900">
                            <?php echo htmlspecialchars($product['name']); ?>
                        </h1>

                        <div class="flex flex-wrap items-center gap-2 mt-5">

                            <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full border text-xs font-bold <?php echo $stock['class']; ?>">
                                <span class="w-1.5 h-1.5 rounded-full <?php echo $stock['dot']; ?>"></span>
                                <?php echo htmlspecialchars($stock['label']); ?>
                            </span>

                            <?php if (!empty($product['is_repairable'])): ?>
                                <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-gray-900 text-white text-xs font-bold">
                                    <i data-lucide="wrench" class="w-3.5 h-3.5"></i>
                                    Repairable
                                </span>
                            <?php endif; ?>

                        </div>

                        <div class="mt-7">
                            <p class="text-xs uppercase tracking-wider font-semibold text-gray-400">
                                Price
                            </p>

                            <p class="mt-1 text-3xl font-extrabold text-gray-900">
                                Rs.
                                <?php echo number_format((float)$product['price'], 2); ?>
                            </p>
                        </div>

                        <div class="mt-7 pt-7 border-t border-gray-100">

                            <h2 class="text-sm font-extrabold text-gray-900">
                                About this product
                            </h2>

                            <p class="mt-3 text-sm sm:text-base leading-7 text-gray-600">
                                <?php
                                echo !empty($product['description'])
                                    ? nl2br(htmlspecialchars($product['description']))
                                    : 'Product information will be updated soon.';
                                ?>
                            </p>

                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-7">

                            <div class="rounded-2xl bg-gray-50 border border-gray-200 p-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-xl bg-white border border-gray-200 flex items-center justify-center">
                                        <i data-lucide="shield-check" class="w-5 h-5 text-brand-red"></i>
                                    </div>

                                    <div>
                                        <p class="text-xs text-gray-400">Warranty</p>
                                        <p class="text-sm font-bold text-gray-900">
                                            <?php
                                            $months = (int)$product['warranty_period_months'];
                                            echo $months > 0
                                                ? $months . ' month' . ($months === 1 ? '' : 's')
                                                : 'Not specified';
                                            ?>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-2xl bg-gray-50 border border-gray-200 p-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-xl bg-white border border-gray-200 flex items-center justify-center">
                                        <i data-lucide="package-check" class="w-5 h-5 text-brand-red"></i>
                                    </div>

                                    <div>
                                        <p class="text-xs text-gray-400">Availability</p>
                                        <p class="text-sm font-bold text-gray-900">
                                            <?php echo htmlspecialchars($stock['label']); ?>
                                        </p>
                                    </div>
                                </div>
                            </div>

                        </div>

                        <?php if ($stock['available']): ?>

                            <form
                                method="POST"
                                action="<?php echo APP_URL; ?>/cart.php"
                                class="mt-8">

                                <input type="hidden" name="action" value="add">
                                <input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>">
                                <input type="hidden" name="quantity" value="1">

                                <button
                                    type="submit"
                                    class="w-full h-13 py-3.5 inline-flex items-center justify-center gap-2 rounded-xl bg-brand-red hover:bg-brand-redHover text-white font-bold transition shadow-sm hover:shadow-lg">

                                    <i data-lucide="shopping-cart" class="w-5 h-5"></i>
                                    Add to Cart

                                </button>

                            </form>

                        <?php else: ?>

                            <button
                                type="button"
                                disabled
                                class="mt-8 w-full h-13 py-3.5 inline-flex items-center justify-center gap-2 rounded-xl bg-gray-100 text-gray-400 font-bold cursor-not-allowed">

                                <i data-lucide="package-x" class="w-5 h-5"></i>
                                Currently Out of Stock

                            </button>

                        <?php endif; ?>

                        <a
                            href="<?php echo APP_URL; ?>/products.php"
                            class="mt-3 w-full h-12 inline-flex items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 font-semibold transition">

                            <i data-lucide="arrow-left" class="w-4 h-4"></i>
                            Continue Shopping

                        </a>

                    </div>

                </div>
            </div>
        </section>

        <!-- Specifications -->
        <?php if (!empty($groupedSpecs)): ?>

            <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-10">

                <div class="bg-white rounded-3xl border border-gray-200 shadow-sm overflow-hidden">

                    <div class="px-6 sm:px-8 py-6 border-b border-gray-200">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-red-50 flex items-center justify-center">
                                <i data-lucide="list-checks" class="w-5 h-5 text-brand-red"></i>
                            </div>

                            <div>
                                <h2 class="text-xl font-extrabold text-gray-900">
                                    Technical Specifications
                                </h2>
                                <p class="text-sm text-gray-500 mt-1">
                                    Product specifications and features.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="p-6 sm:p-8">

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">

                            <?php foreach ($groupedSpecs as $groupName => $groupSpecs): ?>

                                <div class="rounded-2xl border border-gray-200 overflow-hidden">

                                    <div class="px-5 py-4 bg-gray-50 border-b border-gray-200">
                                        <h3 class="font-extrabold text-gray-900">
                                            <?php echo htmlspecialchars($groupName); ?>
                                        </h3>
                                    </div>

                                    <div>

                                        <?php foreach ($groupSpecs as $spec): ?>

                                            <div class="spec-row grid grid-cols-5 gap-4 px-5 py-4 border-b border-gray-100">

                                                <div class="col-span-2 text-sm font-semibold text-gray-500">
                                                    <?php echo htmlspecialchars($spec['spec_name']); ?>
                                                </div>

                                                <div class="col-span-3 text-sm font-medium text-gray-900 break-words">
                                                    <?php echo nl2br(htmlspecialchars($spec['spec_value'])); ?>
                                                </div>

                                            </div>

                                        <?php endforeach; ?>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        </div>

                    </div>
                </div>
            </section>

        <?php endif; ?>

        <!-- Service / Warranty -->
        <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-10">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">

                <div class="bg-white border border-gray-200 rounded-2xl p-5">
                    <i data-lucide="shield-check" class="w-6 h-6 text-brand-red"></i>
                    <h3 class="mt-4 font-extrabold text-gray-900">Warranty Support</h3>
                    <p class="mt-2 text-sm leading-6 text-gray-500">
                        Warranty information is provided according to the product record.
                    </p>
                </div>

                <div class="bg-white border border-gray-200 rounded-2xl p-5">
                    <i data-lucide="wrench" class="w-6 h-6 text-brand-red"></i>
                    <h3 class="mt-4 font-extrabold text-gray-900">Repair Support</h3>
                    <p class="mt-2 text-sm leading-6 text-gray-500">
                        <?php echo !empty($product['is_repairable'])
                            ? 'This product is marked as repairable in our system.'
                            : 'Repair availability depends on the product and service requirements.'; ?>
                    </p>
                </div>

                <div class="bg-white border border-gray-200 rounded-2xl p-5">
                    <i data-lucide="headphones" class="w-6 h-6 text-brand-red"></i>
                    <h3 class="mt-4 font-extrabold text-gray-900">Customer Support</h3>
                    <p class="mt-2 text-sm leading-6 text-gray-500">
                        Contact Thirasara Max Mobile for product, warranty and repair assistance.
                    </p>
                </div>

            </div>

        </section>

        <!-- Related Products -->
        <?php if (!empty($relatedProducts)): ?>

            <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-14">

                <div class="flex items-end justify-between gap-4 mb-5">
                    <div>
                        <p class="text-xs uppercase tracking-wider font-extrabold text-brand-red">
                            You may also like
                        </p>

                        <h2 class="mt-1 text-2xl font-extrabold text-gray-900">
                            Related Products
                        </h2>
                    </div>

                    <a
                        href="<?php echo APP_URL; ?>/products.php?category=<?php echo urlencode($product['category']); ?>"
                        class="hidden sm:inline-flex items-center gap-2 text-sm font-bold text-brand-red hover:text-brand-redHover">
                        View all
                        <i data-lucide="arrow-right" class="w-4 h-4"></i>
                    </a>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">

                    <?php foreach ($relatedProducts as $related): ?>

                        <a
                            href="<?php echo APP_URL; ?>/product-details.php?id=<?php echo (int)$related['id']; ?>"
                            class="bg-white rounded-2xl border border-gray-200 overflow-hidden shadow-sm hover:shadow-lg hover:-translate-y-1 transition">

                            <div class="h-48 bg-gray-100">

                                <?php if (!empty($related['image_url'])): ?>

                                    <img
                                        src="<?php echo htmlspecialchars(detailImageUrl($related['image_url'])); ?>"
                                        alt="<?php echo htmlspecialchars($related['name']); ?>"
                                        class="w-full h-full object-contain p-5"
                                        loading="lazy">

                                <?php else: ?>

                                    <div class="w-full h-full flex items-center justify-center text-gray-400">
                                        <i data-lucide="package" class="w-10 h-10"></i>
                                    </div>

                                <?php endif; ?>

                            </div>

                            <div class="p-4">

                                <?php if (!empty($related['brand'])): ?>
                                    <p class="text-[10px] uppercase tracking-wider font-bold text-brand-red">
                                        <?php echo htmlspecialchars($related['brand']); ?>
                                    </p>
                                <?php endif; ?>

                                <h3 class="mt-1 font-bold text-gray-900 line-clamp-2">
                                    <?php echo htmlspecialchars($related['name']); ?>
                                </h3>

                                <p class="mt-3 text-lg font-extrabold text-gray-900">
                                    Rs. <?php echo number_format((float)$related['price'], 2); ?>
                                </p>

                            </div>

                        </a>

                    <?php endforeach; ?>

                </div>
            </section>

        <?php endif; ?>

    </main>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
    lucide.createIcons();

    const mainImage = document.getElementById('mainProductImage');
    const thumbs = document.querySelectorAll('.gallery-thumb');

    thumbs.forEach(function (thumb) {
        thumb.addEventListener('click', function () {
            if (!mainImage) return;

            const image = this.dataset.image;
            const alt = this.dataset.alt || '';

            mainImage.style.opacity = '0.35';

            setTimeout(function () {
                mainImage.src = image;
                mainImage.alt = alt;
                mainImage.style.opacity = '1';
            }, 100);

            thumbs.forEach(function (item) {
                item.classList.remove('active');
            });

            this.classList.add('active');
        });
    });
</script>

</body>
</html>
