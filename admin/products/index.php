<?php

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth(['admin']);

$currentUser = getCurrentUser();
$pdo         = Database::getConnection();

/* ── Handle DELETE (POST) ───────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['product_id'])) {
    $pid = (int) $_POST['product_id'];

    if ($_POST['action'] === 'delete' && $pid > 0) {
        try {
            /* Soft-delete: mark discontinued instead of hard-deleting */
            $stmt = $pdo->prepare("UPDATE products SET status = 'discontinued' WHERE id = ?");
            $stmt->execute([$pid]);
            setFlash('success', 'Product marked as discontinued.');
        } catch (PDOException $e) {
            error_log('Product delete error: ' . $e->getMessage());
            setFlash('error', 'Could not update product status.');
        }
    }

    /* PRG – redirect to same page (strip any old params) */
    header('Location: ' . APP_URL . '/admin/products/index.php');
    exit;
}

/* ── Input / Filters ────────────────────────────────────────────────── */
$search   = trim($_GET['q']       ?? '');
$status   = trim($_GET['status']  ?? '');
$category = trim($_GET['category'] ?? '');
$page     = max(1, (int) ($_GET['page'] ?? 1));
$perPage  = 15;
$offset   = ($page - 1) * $perPage;

/* Build WHERE clause */
$where  = [];
$params = [];

if ($search !== '') {
    $where[]  = '(p.name LIKE ? OR p.brand LIKE ? OR p.category LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($status !== '' && in_array($status, ['available', 'out_of_stock', 'discontinued'], true)) {
    if ($status === 'available') {
        /* Available = DB status is available AND stock is actually > 0 */
        $where[] = "(p.status = 'available' AND COALESCE(i.stock_quantity, 0) > 0)";
    } elseif ($status === 'out_of_stock') {
        /* Out of stock = stock_quantity is 0 (regardless of p.status) AND not discontinued */
        $where[] = "(COALESCE(i.stock_quantity, 0) = 0 AND p.status != 'discontinued')";
    } elseif ($status === 'discontinued') {
        $where[] = "p.status = 'discontinued'";
    }
}

if ($category !== '') {
    $where[]  = 'p.category = ?';
    $params[] = $category;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ── Fetch products ─────────────────────────────────────────────────── */
try {
    /* Total for pagination */
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) AS cnt
        FROM products p
        LEFT JOIN inventory i ON i.product_id = p.id
        $whereSql
    ");
    $countStmt->execute($params);
    $totalRows = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    $page = min($page, $totalPages);

    /* Product rows joined with inventory */
    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.name,
            p.category,
            p.brand,
            p.price,
            p.status,
            p.is_repairable,
            p.warranty_period_months,
            p.image_url,
            p.created_at,
            COALESCE(i.stock_quantity, 0)       AS stock_quantity,
            COALESCE(i.low_stock_threshold, 5)  AS low_stock_threshold
        FROM products p
        LEFT JOIN inventory i ON i.product_id = p.id
        $whereSql
        ORDER BY p.created_at DESC
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Distinct categories for filter dropdown */
    $categories = $pdo->query("
        SELECT DISTINCT category FROM products ORDER BY category ASC
    ")->fetchAll(PDO::FETCH_COLUMN);

    /* Summary counts — use effective status based on actual inventory */
    $summaryRow = $pdo->query("
        SELECT
            COUNT(*)                                                                                          AS total,
            SUM(CASE WHEN p.status = 'available'    AND COALESCE(i.stock_quantity, 0) > 0 THEN 1 END)        AS available,
            SUM(CASE WHEN COALESCE(i.stock_quantity, 0) = 0 AND p.status != 'discontinued' THEN 1 END)       AS out_of_stock,
            SUM(CASE WHEN p.status = 'discontinued' THEN 1 END)                                              AS discontinued
        FROM products p
        LEFT JOIN inventory i ON i.product_id = p.id
    ")->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Admin products index: ' . $e->getMessage());
    $products   = [];
    $categories = [];
    $totalRows  = 0;
    $totalPages = 1;
    $summaryRow = ['total' => 0, 'available' => 0, 'out_of_stock' => 0, 'discontinued' => 0];
}

/* ── Image URL resolver ─────────────────────────────────────────────── */
/**
 * Resolve a product image_url stored in the DB to a usable <img src>.
 * The DB may store:
 *   - a relative path: "assets/uploads/products/foo.jpg"
 *   - an absolute URL: "https://..."
 * The admin panel lives at /admin/products/, so relative paths would
 * otherwise resolve to the wrong location.
 */
function productThumbUrl(?string $imageUrl): string
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

/* ── Status badge helper ────────────────────────────────────────────── */
function productStatusBadge(string $status): string
{
    return match ($status) {
        'available'    => 'bg-green-100 text-green-700',
        'out_of_stock' => 'bg-amber-100 text-amber-700',
        'discontinued' => 'bg-red-100 text-red-700',
        default        => 'bg-gray-100 text-gray-500',
    };
}

/* ── Build query string helper ──────────────────────────────────────── */
function buildQs(array $overrides = []): string
{
    $base = [
        'q'        => $_GET['q']        ?? '',
        'status'   => $_GET['status']   ?? '',
        'category' => $_GET['category'] ?? '',
        'page'     => $_GET['page']     ?? 1,
    ];
    $merged = array_merge($base, $overrides);
    $merged = array_filter($merged, fn($v) => $v !== '' && $v !== 0 && $v !== '0');
    return $merged ? ('?' . http_build_query($merged)) : '';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products | <?php echo APP_NAME; ?> Admin</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Stylesheet -->
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css">

    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: {
                        brand: {
                            red:      '#e52b34',
                            redHover: '#c4232c',
                            dark:     '#0a0a0c',
                            card:     '#121216',
                            border:   '#1f1f28'
                        }
                    }
                }
            }
        }
    </script>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <script>window.__APP_URL__ = '<?php echo rtrim(APP_URL, '/'); ?>';</script>
    <script src="<?php echo APP_URL; ?>/assets/js/main.js" defer></script>
</head>

<body class="bg-gray-100 text-gray-800 font-sans antialiased">

<div class="flex min-h-screen">

    <!-- SIDEBAR -->
    <?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>

    <!-- MAIN CONTENT -->
    <div class="flex-1 flex flex-col min-w-0">

        <!-- TOP BAR -->
        <header class="bg-white border-b border-gray-200 sticky top-0 z-20 flex items-center gap-4 px-6 h-16 flex-shrink-0">

            <!-- Hamburger (mobile) -->
            <button id="sidebar-open-btn" type="button"
                class="lg:hidden text-gray-500 hover:text-gray-900" aria-label="Open sidebar">
                <i data-lucide="menu" class="w-5 h-5"></i>
            </button>

            <div class="flex-1 min-w-0">
                <h1 class="text-lg font-bold text-gray-900">Products</h1>
                <p class="text-xs text-gray-400 hidden sm:block">Manage your product catalogue</p>
            </div>

            <div class="flex items-center gap-3">
                <a href="<?php echo APP_URL; ?>/admin/products/add.php"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-sm font-semibold transition">
                    <i data-lucide="plus" class="w-4 h-4"></i>
                    <span class="hidden sm:inline">Add Product</span>
                </a>

                <!-- Avatar -->
                <div class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center text-white text-sm font-bold flex-shrink-0">
                    <?php echo strtoupper(substr($currentUser['name'] ?? 'A', 0, 1)); ?>
                </div>
            </div>

        </header>

        <!-- PAGE BODY -->
        <main class="flex-1 p-5 sm:p-7 space-y-5 overflow-auto">

            <!-- Flash messages -->
            <?php displayFlash(); ?>

            <!-- SUMMARY STATS -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">

                <?php
                $summaryTiles = [
                    ['label' => 'Total Products',  'value' => $summaryRow['total'],        'icon' => 'package',        'color' => 'bg-blue-50 text-blue-600',    'filter' => ''],
                    ['label' => 'Available',        'value' => $summaryRow['available'],    'icon' => 'check-circle',   'color' => 'bg-green-50 text-green-600',  'filter' => 'available'],
                    ['label' => 'Out of Stock',     'value' => $summaryRow['out_of_stock'], 'icon' => 'alert-triangle', 'color' => 'bg-amber-50 text-amber-600',  'filter' => 'out_of_stock'],
                    ['label' => 'Discontinued',     'value' => $summaryRow['discontinued'], 'icon' => 'x-circle',       'color' => 'bg-red-50 text-red-600',      'filter' => 'discontinued'],
                ];
                foreach ($summaryTiles as $tile):
                    $isActive = ($status === $tile['filter'] && ($tile['filter'] !== '' || $status === ''));
                    $href     = buildQs(['status' => $tile['filter'], 'page' => 1]);
                ?>
                <a href="<?php echo htmlspecialchars($href ?: '?'); ?>"
                    class="bg-white rounded-2xl border <?php echo $isActive && $tile['filter'] !== '' ? 'border-brand-red ring-1 ring-brand-red/30' : 'border-gray-200'; ?> p-4 shadow-sm flex items-center gap-4 hover:shadow-md transition">
                    <div class="w-10 h-10 rounded-xl <?php echo $tile['color']; ?> flex items-center justify-center flex-shrink-0">
                        <i data-lucide="<?php echo $tile['icon']; ?>" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <p class="text-2xl font-extrabold text-gray-900"><?php echo number_format((int)$tile['value']); ?></p>
                        <p class="text-xs text-gray-500 font-medium"><?php echo $tile['label']; ?></p>
                    </div>
                </a>
                <?php endforeach; ?>

            </div>


            <!-- FILTER BAR -->
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-4">
                <form method="GET" action="" class="flex flex-col sm:flex-row gap-3 items-stretch sm:items-center">

                    <!-- Search -->
                    <div class="relative flex-1 min-w-0">
                        <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none"></i>
                        <input
                            type="text"
                            name="q"
                            value="<?php echo htmlspecialchars($search); ?>"
                            placeholder="Search by name, brand or category…"
                            class="w-full pl-9 pr-4 py-2.5 text-sm border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-red/30 focus:border-brand-red bg-gray-50 placeholder-gray-400">
                    </div>

                    <!-- Category filter -->
                    <select name="category"
                        class="text-sm border border-gray-200 rounded-xl px-3 py-2.5 bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-red/30 focus:border-brand-red min-w-[160px]">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>"
                                <?php echo $category === $cat ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Status filter -->
                    <select name="status"
                        class="text-sm border border-gray-200 rounded-xl px-3 py-2.5 bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-red/30 focus:border-brand-red min-w-[150px]">
                        <option value="">All Statuses</option>
                        <option value="available"    <?php echo $status === 'available'    ? 'selected' : ''; ?>>Available</option>
                        <option value="out_of_stock" <?php echo $status === 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
                        <option value="discontinued" <?php echo $status === 'discontinued' ? 'selected' : ''; ?>>Discontinued</option>
                    </select>

                    <button type="submit"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-sm font-semibold transition flex-shrink-0">
                        <i data-lucide="filter" class="w-4 h-4"></i>
                        Filter
                    </button>

                    <?php if ($search !== '' || $status !== '' || $category !== ''): ?>
                        <a href="<?php echo APP_URL; ?>/admin/products/index.php"
                            class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl border border-gray-200 text-sm text-gray-500 hover:bg-gray-50 transition flex-shrink-0">
                            <i data-lucide="x" class="w-3.5 h-3.5"></i>
                            Clear
                        </a>
                    <?php endif; ?>

                </form>
            </div>


            <!-- PRODUCTS TABLE -->
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">

                <!-- Table header -->
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                    <div>
                        <h3 class="text-base font-bold text-gray-900">All Products</h3>
                        <p class="text-xs text-gray-400 mt-0.5">
                            <?php echo number_format($totalRows); ?> product<?php echo $totalRows !== 1 ? 's' : ''; ?> found
                            <?php if ($search || $status || $category): ?>
                                <span class="text-brand-red font-semibold">· filtered</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <a href="<?php echo APP_URL; ?>/admin/products/add.php"
                        class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-red hover:underline">
                        <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add Product
                    </a>
                </div>

                <?php if (!empty($products)): ?>

                    <!-- Desktop Table -->
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-gray-50 border-b border-gray-100">
                                    <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider w-12">#</th>
                                    <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Product</th>
                                    <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider hidden md:table-cell">Category</th>
                                    <th class="text-right px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Price</th>
                                    <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider hidden sm:table-cell">Stock</th>
                                    <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider hidden lg:table-cell">Warranty</th>
                                    <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <?php foreach ($products as $i => $p): ?>
                                    <?php
                                    $isLowStock = $p['stock_quantity'] > 0 && $p['stock_quantity'] <= $p['low_stock_threshold'];
                                    $isOos      = $p['stock_quantity'] === 0 || $p['stock_quantity'] === '0';
                                    ?>
                                    <tr class="hover:bg-gray-50 transition-colors group">

                                        <!-- Row number -->
                                        <td class="px-5 py-3.5 text-xs text-gray-400 font-mono">
                                            <?php echo $offset + $i + 1; ?>
                                        </td>

                                        <!-- Product info -->
                                        <td class="px-4 py-3.5">
                                            <div class="flex items-center gap-3">
                                                <!-- Thumbnail -->
                                                <?php $thumbSrc = productThumbUrl($p['image_url']); ?>
                                                <?php if ($thumbSrc): ?>
                                                    <img
                                                        src="<?php echo htmlspecialchars($thumbSrc); ?>"
                                                        alt="<?php echo htmlspecialchars($p['name']); ?>"
                                                        class="w-10 h-10 rounded-xl object-cover flex-shrink-0 border border-gray-100"
                                                        onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                                    <div class="w-10 h-10 rounded-xl bg-gray-100 items-center justify-center flex-shrink-0 hidden">
                                                        <i data-lucide="image" class="w-4 h-4 text-gray-400"></i>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="w-10 h-10 rounded-xl bg-gray-100 flex items-center justify-center flex-shrink-0">
                                                        <i data-lucide="image" class="w-4 h-4 text-gray-400"></i>
                                                    </div>
                                                <?php endif; ?>

                                                <div class="min-w-0">
                                                    <a href="<?php echo APP_URL; ?>/admin/products/edit.php?id=<?php echo $p['id']; ?>"
                                                        class="font-semibold text-gray-900 hover:text-brand-red transition text-sm truncate block max-w-[200px]">
                                                        <?php echo htmlspecialchars($p['name']); ?>
                                                    </a>
                                                    <p class="text-[11px] text-gray-400 mt-0.5">
                                                        <?php echo $p['brand'] ? htmlspecialchars($p['brand']) : '—'; ?>
                                                        <?php if ($p['is_repairable']): ?>
                                                            &nbsp;<span class="inline-block px-1.5 py-0.5 bg-purple-100 text-purple-700 rounded-full text-[9px] font-bold">Repairable</span>
                                                        <?php endif; ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Category -->
                                        <td class="px-4 py-3.5 hidden md:table-cell">
                                            <span class="inline-block px-2.5 py-1 rounded-full bg-gray-100 text-gray-600 text-[11px] font-semibold">
                                                <?php echo htmlspecialchars($p['category']); ?>
                                            </span>
                                        </td>

                                        <!-- Price -->
                                        <td class="px-4 py-3.5 text-right font-bold text-gray-900 text-sm">
                                            Rs.<?php echo number_format((float) $p['price'], 2); ?>
                                        </td>

                                        <!-- Stock -->
                                        <td class="px-4 py-3.5 text-center hidden sm:table-cell">
                                            <?php if ($isOos): ?>
                                                <span class="inline-block px-2 py-0.5 rounded-full bg-red-100 text-red-700 text-[11px] font-bold">Out of stock</span>
                                            <?php elseif ($isLowStock): ?>
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[11px] font-bold">
                                                    <i data-lucide="alert-triangle" class="w-3 h-3"></i>
                                                    <?php echo $p['stock_quantity']; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-sm font-semibold text-gray-700"><?php echo number_format((int) $p['stock_quantity']); ?></span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Status -->
                                        <td class="px-4 py-3.5 text-center">
                                            <?php
                                            /* Derive effective status from actual stock */
                                            if ($p['status'] === 'discontinued') {
                                                $effectiveStatus = 'discontinued';
                                            } elseif ((int) $p['stock_quantity'] === 0) {
                                                $effectiveStatus = 'out_of_stock';
                                            } else {
                                                $effectiveStatus = $p['status'];
                                            }
                                            ?>
                                            <span class="inline-block px-2.5 py-0.5 rounded-full text-[11px] font-bold <?php echo productStatusBadge($effectiveStatus); ?>">
                                                <?php echo ucwords(str_replace('_', ' ', $effectiveStatus)); ?>
                                            </span>
                                        </td>

                                        <!-- Warranty -->
                                        <td class="px-4 py-3.5 text-center hidden lg:table-cell text-xs text-gray-500">
                                            <?php echo $p['warranty_period_months']; ?> mo.
                                        </td>

                                        <!-- Actions -->
                                        <td class="px-4 py-3.5">
                                            <div class="flex items-center justify-center gap-1.5">

                                                <!-- Edit -->
                                                <a href="<?php echo APP_URL; ?>/admin/products/edit.php?id=<?php echo $p['id']; ?>"
                                                    title="Edit"
                                                    class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-blue-600 hover:bg-blue-50 transition">
                                                    <i data-lucide="pencil" class="w-4 h-4"></i>
                                                </a>

                                                <!-- View on storefront -->
                                                <a href="<?php echo APP_URL; ?>/product-details.php?id=<?php echo $p['id']; ?>"
                                                    target="_blank"
                                                    title="View on storefront"
                                                    class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-green-600 hover:bg-green-50 transition">
                                                    <i data-lucide="external-link" class="w-4 h-4"></i>
                                                </a>

                                                <!-- Discontinue / Soft-delete -->
                                                <?php if ($p['status'] !== 'discontinued'): ?>
                                                    <form method="POST" action=""
                                                        onsubmit="return confirm('Mark \'<?php echo addslashes(htmlspecialchars($p['name'])); ?>\' as discontinued?');">
                                                        <input type="hidden" name="action"     value="delete">
                                                        <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                                        <button type="submit"
                                                            title="Discontinue product"
                                                            class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-red-600 hover:bg-red-50 transition">
                                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="w-8 h-8"></span>
                                                <?php endif; ?>

                                            </div>
                                        </td>

                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php else: ?>

                    <!-- Empty state -->
                    <div class="flex flex-col items-center justify-center py-20 text-gray-400">
                        <i data-lucide="package" class="w-14 h-14 mb-4 text-gray-200"></i>
                        <p class="text-base font-semibold text-gray-500">No products found</p>
                        <p class="text-sm mt-1">
                            <?php if ($search || $status || $category): ?>
                                Try adjusting your filters or
                                <a href="<?php echo APP_URL; ?>/admin/products/index.php" class="text-brand-red hover:underline font-medium">clear them</a>.
                            <?php else: ?>
                                <a href="<?php echo APP_URL; ?>/admin/products/add.php" class="text-brand-red hover:underline font-medium">Add your first product</a>.
                            <?php endif; ?>
                        </p>
                    </div>

                <?php endif; ?>

                <!-- PAGINATION -->
                <?php if ($totalPages > 1): ?>
                    <div class="flex flex-col sm:flex-row items-center justify-between gap-3 px-6 py-4 border-t border-gray-100 bg-gray-50">

                        <p class="text-xs text-gray-500">
                            Showing
                            <span class="font-semibold text-gray-700"><?php echo number_format($offset + 1); ?></span>
                            –
                            <span class="font-semibold text-gray-700"><?php echo number_format(min($offset + $perPage, $totalRows)); ?></span>
                            of
                            <span class="font-semibold text-gray-700"><?php echo number_format($totalRows); ?></span>
                            products
                        </p>

                        <div class="flex items-center gap-1.5">

                            <!-- Prev -->
                            <?php if ($page > 1): ?>
                                <a href="<?php echo htmlspecialchars(buildQs(['page' => $page - 1])); ?>"
                                    class="w-8 h-8 rounded-lg flex items-center justify-center border border-gray-200 text-gray-500 hover:bg-white hover:border-gray-300 transition text-xs">
                                    <i data-lucide="chevron-left" class="w-4 h-4"></i>
                                </a>
                            <?php else: ?>
                                <span class="w-8 h-8 rounded-lg flex items-center justify-center border border-gray-100 text-gray-300 cursor-not-allowed">
                                    <i data-lucide="chevron-left" class="w-4 h-4"></i>
                                </span>
                            <?php endif; ?>

                            <!-- Page numbers -->
                            <?php
                            $start = max(1, $page - 2);
                            $end   = min($totalPages, $page + 2);
                            if ($start > 1): ?>
                                <a href="<?php echo htmlspecialchars(buildQs(['page' => 1])); ?>"
                                    class="w-8 h-8 rounded-lg flex items-center justify-center border border-gray-200 text-xs text-gray-500 hover:bg-white transition">1</a>
                                <?php if ($start > 2): ?>
                                    <span class="text-gray-400 text-xs px-1">…</span>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php for ($p2 = $start; $p2 <= $end; $p2++): ?>
                                <a href="<?php echo htmlspecialchars(buildQs(['page' => $p2])); ?>"
                                    class="w-8 h-8 rounded-lg flex items-center justify-center border text-xs font-semibold transition
                                        <?php echo $p2 === $page
                                            ? 'bg-brand-red border-brand-red text-white shadow-sm'
                                            : 'border-gray-200 text-gray-600 hover:bg-white hover:border-gray-300'; ?>">
                                    <?php echo $p2; ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($end < $totalPages): ?>
                                <?php if ($end < $totalPages - 1): ?>
                                    <span class="text-gray-400 text-xs px-1">…</span>
                                <?php endif; ?>
                                <a href="<?php echo htmlspecialchars(buildQs(['page' => $totalPages])); ?>"
                                    class="w-8 h-8 rounded-lg flex items-center justify-center border border-gray-200 text-xs text-gray-500 hover:bg-white transition">
                                    <?php echo $totalPages; ?>
                                </a>
                            <?php endif; ?>

                            <!-- Next -->
                            <?php if ($page < $totalPages): ?>
                                <a href="<?php echo htmlspecialchars(buildQs(['page' => $page + 1])); ?>"
                                    class="w-8 h-8 rounded-lg flex items-center justify-center border border-gray-200 text-gray-500 hover:bg-white hover:border-gray-300 transition text-xs">
                                    <i data-lucide="chevron-right" class="w-4 h-4"></i>
                                </a>
                            <?php else: ?>
                                <span class="w-8 h-8 rounded-lg flex items-center justify-center border border-gray-100 text-gray-300 cursor-not-allowed">
                                    <i data-lucide="chevron-right" class="w-4 h-4"></i>
                                </span>
                            <?php endif; ?>

                        </div>
                    </div>
                <?php endif; ?>

            </div><!-- /products table -->


            <!-- Footer -->
            <p class="text-center text-xs text-gray-400 pb-2">
                <?php echo APP_NAME; ?> &mdash; Admin Panel &mdash; v<?php echo APP_VERSION; ?>
            </p>

        </main>

    </div><!-- /main content -->

</div><!-- /flex wrapper -->

<script>lucide.createIcons();</script>

</body>
</html>
