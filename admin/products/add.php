<?php

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth(['admin']);

$currentUser = getCurrentUser();
$pdo         = Database::getConnection();

/* ── Existing categories and spec groups for datalists ─────────────── */
try {
    $existingCategories = $pdo
        ->query("SELECT DISTINCT category FROM products ORDER BY category ASC")
        ->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $existingCategories = [];
}

try {
    $existingSpecGroups = $pdo
        ->query("SELECT DISTINCT spec_group FROM product_specifications WHERE spec_group IS NOT NULL AND spec_group != '' ORDER BY spec_group ASC")
        ->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $existingSpecGroups = [];
}
if (empty($existingSpecGroups)) {
    $existingSpecGroups = ['General', 'Display', 'Performance', 'Camera', 'Battery', 'Connectivity', 'Audio', 'Dimensions & Weight'];
}

/* ── POST handler ───────────────────────────────────────────────────── */
$errors = [];
$old    = [];   // repopulate form on validation failure

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* CSRF check */
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form submission. Please try again.';
    } else {

        /* ── Collect & sanitise ─────────────────────────────────── */
        $old = $_POST;

        $name               = trim($_POST['name']               ?? '');
        $category           = trim($_POST['category']           ?? '');
        $brand              = trim($_POST['brand']              ?? '');
        $description        = trim($_POST['description']        ?? '');
        $price              = trim($_POST['price']              ?? '');
        $status             = trim($_POST['status']             ?? 'available');
        $isRepairable       = isset($_POST['is_repairable']) ? 1 : 0;
        $warrantyMonths     = (int) ($_POST['warranty_period_months'] ?? 6);
        $stockQty           = (int) ($_POST['stock_quantity']         ?? 0);
        $lowStockThreshold  = (int) ($_POST['low_stock_threshold']    ?? 5);

        /* ── Technical Specifications (product_specifications table) ─── */
        $specGroups = $_POST['spec_group'] ?? [];
        $specNames  = $_POST['spec_name']  ?? [];
        $specValues = $_POST['spec_value'] ?? [];
        $specsToInsert = [];

        foreach ($specNames as $idx => $sName) {
            $sName  = trim($sName);
            $sVal   = trim($specValues[$idx] ?? '');
            $sGroup = trim($specGroups[$idx] ?? '');
            if ($sGroup === '') {
                $sGroup = 'General';
            }
            if ($sName !== '' && $sVal !== '') {
                $specsToInsert[] = [
                    'group' => $sGroup,
                    'name'  => $sName,
                    'value' => $sVal,
                ];
            }
        }

        /* ── Validate required fields ───────────────────────────── */
        if ($name === '') {
            $errors[] = 'Product name is required.';
        }
        if ($category === '') {
            $errors[] = 'Category is required.';
        }
        if ($price === '' || !is_numeric($price) || (float)$price < 0) {
            $errors[] = 'A valid price is required.';
        }
        if (!in_array($status, ['available', 'out_of_stock', 'discontinued'], true)) {
            $status = 'available';
        }
        if ($warrantyMonths < 0) {
            $warrantyMonths = 0;
        }
        if ($stockQty < 0) {
            $stockQty = 0;
        }
        if ($lowStockThreshold < 1) {
            $lowStockThreshold = 1;
        }

        /* ── Image handling ─────────────────────────────────────── */
        $imageUrl = trim($_POST['image_url_manual'] ?? '');

        if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
            $file     = $_FILES['image_file'];
            $allowed  = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $maxBytes = 3 * 1024 * 1024; // 3 MB

            $finfo    = finfo_open(FILEINFO_MIME_TYPE);
            $mime     = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime, $allowed, true)) {
                $errors[] = 'Image must be JPEG, PNG, WebP or GIF.';
            } elseif ($file['size'] > $maxBytes) {
                $errors[] = 'Image must be smaller than 3 MB.';
            } else {
                $ext      = match ($mime) {
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp',
                    'image/gif'  => 'gif',
                };
                $filename  = 'product_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $uploadDir = __DIR__ . '/../../assets/images/products/';
                $destPath  = $uploadDir . $filename;

                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                if (move_uploaded_file($file['tmp_name'], $destPath)) {
                    $imageUrl = 'assets/images/products/' . $filename;
                } else {
                    $errors[] = 'Failed to save the uploaded image. Check folder permissions.';
                }
            }
        }

        /* ── Insert if no errors ────────────────────────────────── */
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                /* Products row */
                $stmt = $pdo->prepare("
                    INSERT INTO products
                        (name, category, brand, description, price,
                         is_repairable, warranty_period_months, image_url, status)
                    VALUES
                        (:name, :category, :brand, :description, :price,
                         :is_repairable, :warranty_months, :image_url, :status)
                ");
                $stmt->execute([
                    ':name'           => $name,
                    ':category'       => $category,
                    ':brand'          => $brand !== '' ? $brand : null,
                    ':description'    => $description !== '' ? $description : null,
                    ':price'          => (float) $price,
                    ':is_repairable'  => $isRepairable,
                    ':warranty_months'=> $warrantyMonths,
                    ':image_url'      => $imageUrl !== '' ? $imageUrl : null,
                    ':status'         => $status,
                ]);

                $productId = (int) $pdo->lastInsertId();

                /* Inventory row */
                $invStmt = $pdo->prepare("
                    INSERT INTO inventory (product_id, stock_quantity, low_stock_threshold)
                    VALUES (:pid, :qty, :threshold)
                ");
                $invStmt->execute([
                    ':pid'       => $productId,
                    ':qty'       => $stockQty,
                    ':threshold' => $lowStockThreshold,
                ]);

                /* Product specifications rows (product_specifications table) */
                if (!empty($specsToInsert)) {
                    $specStmt = $pdo->prepare("
                        INSERT INTO product_specifications
                            (product_id, spec_group, spec_name, spec_value, sort_order)
                        VALUES
                            (:product_id, :spec_group, :spec_name, :spec_value, :sort_order)
                    ");
                    foreach ($specsToInsert as $order => $specItem) {
                        $specStmt->execute([
                            ':product_id' => $productId,
                            ':spec_group' => $specItem['group'],
                            ':spec_name'  => $specItem['name'],
                            ':spec_value' => $specItem['value'],
                            ':sort_order' => $order,
                        ]);
                    }
                }

                $pdo->commit();

                setFlash('success', "Product \"{$name}\" added successfully.");
                header('Location: ' . APP_URL . '/admin/products/index.php');
                exit;

            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log('Add product error: ' . $e->getMessage());
                $errors[] = 'Database error — could not save the product. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Product | <?php echo APP_NAME; ?> Admin</title>

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

            <button id="sidebar-open-btn" type="button"
                class="lg:hidden text-gray-500 hover:text-gray-900" aria-label="Open sidebar">
                <i data-lucide="menu" class="w-5 h-5"></i>
            </button>

            <!-- Breadcrumb -->
            <div class="flex-1 min-w-0 flex items-center gap-2 text-sm">
                <a href="<?php echo APP_URL; ?>/admin/products/index.php"
                    class="text-gray-400 hover:text-brand-red transition font-medium hidden sm:inline">Products</a>
                <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-gray-300 hidden sm:inline flex-shrink-0"></i>
                <span class="font-bold text-gray-900">Add New Product</span>
            </div>

            <div class="flex items-center gap-3">
                <a href="<?php echo APP_URL; ?>/admin/products/index.php"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-xl border border-gray-200 text-sm font-semibold text-gray-600 hover:bg-gray-50 transition">
                    <i data-lucide="arrow-left" class="w-4 h-4"></i>
                    <span class="hidden sm:inline">Back</span>
                </a>
                <!-- Avatar -->
                <div class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center text-white text-sm font-bold flex-shrink-0">
                    <?php echo strtoupper(substr($currentUser['name'] ?? 'A', 0, 1)); ?>
                </div>
            </div>

        </header>

        <!-- PAGE BODY -->
        <main class="flex-1 p-5 sm:p-7 overflow-auto">

            <!-- Validation errors -->
            <?php if (!empty($errors)): ?>
                <div class="mb-6 bg-rose-50 border border-rose-200 rounded-2xl p-4">
                    <div class="flex items-start gap-3">
                        <i data-lucide="alert-circle" class="w-5 h-5 text-rose-500 flex-shrink-0 mt-0.5"></i>
                        <div>
                            <p class="text-sm font-semibold text-rose-800 mb-1">Please fix the following errors:</p>
                            <ul class="list-disc list-inside space-y-0.5">
                                <?php foreach ($errors as $err): ?>
                                    <li class="text-sm text-rose-700"><?php echo htmlspecialchars($err); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <form
                method="POST"
                action=""
                enctype="multipart/form-data"
                id="add-product-form"
                novalidate>

                <input type="hidden" name="csrf_token" value="<?php echo getCsrfToken(); ?>">

                <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

                    <!-- ── LEFT COLUMN (main fields) ─────────────────── -->
                    <div class="xl:col-span-2 space-y-6">

                        <!-- Basic Info card -->
                        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                            <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-2">
                                <i data-lucide="info" class="w-4 h-4 text-brand-red"></i>
                                <h2 class="text-sm font-bold text-gray-900">Basic Information</h2>
                            </div>
                            <div class="p-6 space-y-5">

                                <!-- Name -->
                                <div>
                                    <label for="name" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                        Product Name <span class="text-brand-red">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="name"
                                        name="name"
                                        value="<?php echo htmlspecialchars($old['name'] ?? ''); ?>"
                                        placeholder="e.g. JBL Flip 6 Portable Speaker"
                                        maxlength="200"
                                        required
                                        class="w-full px-4 py-2.5 text-sm border border-gray-200 rounded-xl bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 outline-none transition placeholder-gray-400">
                                </div>

                                <!-- Category + Brand -->
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                                    <!-- Category -->
                                    <div>
                                        <label for="category" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                            Category <span class="text-brand-red">*</span>
                                        </label>
                                        <input
                                            type="text"
                                            id="category"
                                            name="category"
                                            list="category-list"
                                            value="<?php echo htmlspecialchars($old['category'] ?? ''); ?>"
                                            placeholder="e.g. Speakers"
                                            maxlength="100"
                                            required
                                            class="w-full px-4 py-2.5 text-sm border border-gray-200 rounded-xl bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 outline-none transition placeholder-gray-400">
                                        <datalist id="category-list">
                                            <?php foreach ($existingCategories as $cat): ?>
                                                <option value="<?php echo htmlspecialchars($cat); ?>">
                                            <?php endforeach; ?>
                                        </datalist>
                                        <p class="text-[11px] text-gray-400 mt-1">Choose existing or type a new category.</p>
                                    </div>

                                    <!-- Brand -->
                                    <div>
                                        <label for="brand" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                            Brand
                                        </label>
                                        <input
                                            type="text"
                                            id="brand"
                                            name="brand"
                                            value="<?php echo htmlspecialchars($old['brand'] ?? ''); ?>"
                                            placeholder="e.g. JBL"
                                            maxlength="100"
                                            class="w-full px-4 py-2.5 text-sm border border-gray-200 rounded-xl bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 outline-none transition placeholder-gray-400">
                                    </div>

                                </div>

                                <!-- Description -->
                                <div>
                                    <label for="description" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                        Description
                                    </label>
                                    <textarea
                                        id="description"
                                        name="description"
                                        rows="4"
                                        placeholder="Describe the product — features, specifications, compatibility…"
                                        class="w-full px-4 py-2.5 text-sm border border-gray-200 rounded-xl bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 outline-none transition placeholder-gray-400 resize-none"><?php echo htmlspecialchars($old['description'] ?? ''); ?></textarea>
                                </div>

                            </div>
                        </div>


                        <!-- Technical Specifications card -->
                        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <i data-lucide="cpu" class="w-4 h-4 text-brand-red"></i>
                                    <h2 class="text-sm font-bold text-gray-900">Technical Specifications</h2>
                                </div>
                                <button
                                    type="button"
                                    id="add-spec-btn"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-brand-red hover:bg-brand-redHover text-white text-xs font-semibold transition">
                                    <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                                    Add Row
                                </button>
                            </div>

                            <div class="p-6">

                                <!-- Group suggestions datalist -->
                                <datalist id="spec-group-list">
                                    <?php foreach ($existingSpecGroups as $grp): ?>
                                        <option value="<?php echo htmlspecialchars($grp); ?>">
                                    <?php endforeach; ?>
                                </datalist>

                                <!-- Column headers -->
                                <div class="hidden sm:grid sm:grid-cols-[140px_1fr_1fr_auto] md:grid-cols-[160px_1fr_1fr_auto] gap-2.5 mb-2 px-1">
                                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Group</p>
                                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Spec Name</p>
                                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Value</p>
                                    <p class="w-8"></p>
                                </div>

                                <!-- Spec rows -->
                                <div id="specs-list" class="space-y-3 sm:space-y-2">

                                    <?php
                                    /* Repopulate rows if validation failed */
                                    $oldGroups = $_POST['spec_group'] ?? [];
                                    $oldNames  = $_POST['spec_name']  ?? [];
                                    $oldValues = $_POST['spec_value'] ?? [];
                                    $hasOldSpecs = !empty($oldNames);
                                    ?>

                                    <?php if ($hasOldSpecs): ?>
                                        <?php foreach ($oldNames as $si => $sn): ?>
                                            <?php if (trim($sn) !== '' || trim($oldValues[$si] ?? '') !== ''): ?>
                                            <div class="spec-row grid grid-cols-1 sm:grid-cols-[140px_1fr_1fr_auto] md:grid-cols-[160px_1fr_1fr_auto] gap-2.5 items-center bg-gray-50 sm:bg-transparent p-3 sm:p-0 rounded-xl">
                                                <input
                                                    type="text"
                                                    name="spec_group[]"
                                                    list="spec-group-list"
                                                    value="<?php echo htmlspecialchars($oldGroups[$si] ?? 'General'); ?>"
                                                    placeholder="Group (e.g. Display)"
                                                    class="px-3 py-2 text-sm border border-gray-200 rounded-xl bg-white sm:bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 outline-none transition placeholder-gray-400">
                                                <input
                                                    type="text"
                                                    name="spec_name[]"
                                                    value="<?php echo htmlspecialchars($sn); ?>"
                                                    placeholder="Spec Name (e.g. Screen Size)"
                                                    class="px-3 py-2 text-sm border border-gray-200 rounded-xl bg-white sm:bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 outline-none transition placeholder-gray-400">
                                                <input
                                                    type="text"
                                                    name="spec_value[]"
                                                    value="<?php echo htmlspecialchars($oldValues[$si] ?? ''); ?>"
                                                    placeholder="Value (e.g. 6.7&quot; AMOLED)"
                                                    class="px-3 py-2 text-sm border border-gray-200 rounded-xl bg-white sm:bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 outline-none transition placeholder-gray-400">
                                                <button type="button" onclick="removeSpec(this)"
                                                    class="w-8 h-8 flex items-center justify-center rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 transition flex-shrink-0 self-end sm:self-center">
                                                    <i data-lucide="x" class="w-4 h-4"></i>
                                                </button>
                                            </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <!-- Default starter rows with helpful examples -->
                                        <div class="spec-row grid grid-cols-1 sm:grid-cols-[140px_1fr_1fr_auto] md:grid-cols-[160px_1fr_1fr_auto] gap-2.5 items-center bg-gray-50 sm:bg-transparent p-3 sm:p-0 rounded-xl">
                                            <input type="text" name="spec_group[]" list="spec-group-list" value="Display" placeholder="Group"
                                                class="px-3 py-2 text-sm border border-gray-200 rounded-xl bg-white sm:bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 outline-none transition placeholder-gray-400">
                                            <input type="text" name="spec_name[]" placeholder="e.g. Screen Size"
                                                class="px-3 py-2 text-sm border border-gray-200 rounded-xl bg-white sm:bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 outline-none transition placeholder-gray-400">
                                            <input type="text" name="spec_value[]" placeholder="e.g. 6.7&quot; AMOLED"
                                                class="px-3 py-2 text-sm border border-gray-200 rounded-xl bg-white sm:bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 outline-none transition placeholder-gray-400">
                                            <button type="button" onclick="removeSpec(this)"
                                                class="w-8 h-8 flex items-center justify-center rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 transition flex-shrink-0 self-end sm:self-center">
                                                <i data-lucide="x" class="w-4 h-4"></i>
                                            </button>
                                        </div>
                                        <div class="spec-row grid grid-cols-1 sm:grid-cols-[140px_1fr_1fr_auto] md:grid-cols-[160px_1fr_1fr_auto] gap-2.5 items-center bg-gray-50 sm:bg-transparent p-3 sm:p-0 rounded-xl">
                                            <input type="text" name="spec_group[]" list="spec-group-list" value="Battery" placeholder="Group"
                                                class="px-3 py-2 text-sm border border-gray-200 rounded-xl bg-white sm:bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 outline-none transition placeholder-gray-400">
                                            <input type="text" name="spec_name[]" placeholder="e.g. Capacity"
                                                class="px-3 py-2 text-sm border border-gray-200 rounded-xl bg-white sm:bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 outline-none transition placeholder-gray-400">
                                            <input type="text" name="spec_value[]" placeholder="e.g. 5000 mAh"
                                                class="px-3 py-2 text-sm border border-gray-200 rounded-xl bg-white sm:bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 outline-none transition placeholder-gray-400">
                                            <button type="button" onclick="removeSpec(this)"
                                                class="w-8 h-8 flex items-center justify-center rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 transition flex-shrink-0 self-end sm:self-center">
                                                <i data-lucide="x" class="w-4 h-4"></i>
                                            </button>
                                        </div>
                                        <div class="spec-row grid grid-cols-1 sm:grid-cols-[140px_1fr_1fr_auto] md:grid-cols-[160px_1fr_1fr_auto] gap-2.5 items-center bg-gray-50 sm:bg-transparent p-3 sm:p-0 rounded-xl">
                                            <input type="text" name="spec_group[]" list="spec-group-list" value="Performance" placeholder="Group"
                                                class="px-3 py-2 text-sm border border-gray-200 rounded-xl bg-white sm:bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 outline-none transition placeholder-gray-400">
                                            <input type="text" name="spec_name[]" placeholder="e.g. RAM / Storage"
                                                class="px-3 py-2 text-sm border border-gray-200 rounded-xl bg-white sm:bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 outline-none transition placeholder-gray-400">
                                            <input type="text" name="spec_value[]" placeholder="e.g. 8 GB / 128 GB"
                                                class="px-3 py-2 text-sm border border-gray-200 rounded-xl bg-white sm:bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 outline-none transition placeholder-gray-400">
                                            <button type="button" onclick="removeSpec(this)"
                                                class="w-8 h-8 flex items-center justify-center rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 transition flex-shrink-0 self-end sm:self-center">
                                                <i data-lucide="x" class="w-4 h-4"></i>
                                            </button>
                                        </div>
                                    <?php endif; ?>

                                </div><!-- /specs-list -->

                                <p class="text-[11px] text-gray-400 mt-3">
                                    Organise by group (e.g. Display, Battery, Camera). Empty rows are automatically ignored.
                                </p>

                            </div>
                        </div>


                        <!-- Pricing & Status card -->
                        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                            <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-2">
                                <i data-lucide="tag" class="w-4 h-4 text-brand-red"></i>
                                <h2 class="text-sm font-bold text-gray-900">Pricing &amp; Status</h2>
                            </div>
                            <div class="p-6">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                                    <!-- Price -->
                                    <div>
                                        <label for="price" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                            Price (LKR) <span class="text-brand-red">*</span>
                                        </label>
                                        <div class="relative">
                                            <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-sm font-bold text-gray-400">Rs.</span>
                                            <input
                                                type="number"
                                                id="price"
                                                name="price"
                                                value="<?php echo htmlspecialchars($old['price'] ?? ''); ?>"
                                                placeholder="0.00"
                                                min="0"
                                                step="0.01"
                                                required
                                                class="w-full pl-10 pr-4 py-2.5 text-sm border border-gray-200 rounded-xl bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 outline-none transition placeholder-gray-400">
                                        </div>
                                    </div>

                                    <!-- Status -->
                                    <div>
                                        <label for="status" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                            Listing Status
                                        </label>
                                        <select
                                            id="status"
                                            name="status"
                                            class="w-full px-4 py-2.5 text-sm border border-gray-200 rounded-xl bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 outline-none transition">
                                            <option value="available"    <?php echo ($old['status'] ?? 'available') === 'available'    ? 'selected' : ''; ?>>Available</option>
                                            <option value="out_of_stock" <?php echo ($old['status'] ?? '') === 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
                                            <option value="discontinued" <?php echo ($old['status'] ?? '') === 'discontinued' ? 'selected' : ''; ?>>Discontinued</option>
                                        </select>
                                    </div>

                                    <!-- Warranty -->
                                    <div>
                                        <label for="warranty_period_months" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                            Warranty Period (months)
                                        </label>
                                        <input
                                            type="number"
                                            id="warranty_period_months"
                                            name="warranty_period_months"
                                            value="<?php echo htmlspecialchars($old['warranty_period_months'] ?? '6'); ?>"
                                            min="0"
                                            max="120"
                                            class="w-full px-4 py-2.5 text-sm border border-gray-200 rounded-xl bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 outline-none transition">
                                    </div>

                                    <!-- Repairable -->
                                    <div class="flex items-center">
                                        <div class="bg-gray-50 border border-gray-200 rounded-xl p-4 w-full flex items-start gap-3">
                                            <div class="flex-shrink-0 mt-0.5">
                                                <input
                                                    type="checkbox"
                                                    id="is_repairable"
                                                    name="is_repairable"
                                                    value="1"
                                                    <?php echo !empty($old['is_repairable']) ? 'checked' : ''; ?>
                                                    class="w-4 h-4 rounded border-gray-300 text-brand-red accent-brand-red cursor-pointer">
                                            </div>
                                            <div>
                                                <label for="is_repairable" class="text-sm font-semibold text-gray-700 cursor-pointer">
                                                    Repairable Device
                                                </label>
                                                <p class="text-[11px] text-gray-400 mt-0.5">
                                                    Enable repair booking for this product.
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                </div>
                            </div>
                        </div>


                        <!-- Inventory card -->
                        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                            <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-2">
                                <i data-lucide="warehouse" class="w-4 h-4 text-brand-red"></i>
                                <h2 class="text-sm font-bold text-gray-900">Inventory</h2>
                            </div>
                            <div class="p-6">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                                    <!-- Stock Quantity -->
                                    <div>
                                        <label for="stock_quantity" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                            Opening Stock Quantity
                                        </label>
                                        <input
                                            type="number"
                                            id="stock_quantity"
                                            name="stock_quantity"
                                            value="<?php echo htmlspecialchars($old['stock_quantity'] ?? '0'); ?>"
                                            min="0"
                                            class="w-full px-4 py-2.5 text-sm border border-gray-200 rounded-xl bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 outline-none transition">
                                        <p class="text-[11px] text-gray-400 mt-1">Units currently in stock.</p>
                                    </div>

                                    <!-- Low Stock Threshold -->
                                    <div>
                                        <label for="low_stock_threshold" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                            Low Stock Alert Threshold
                                        </label>
                                        <input
                                            type="number"
                                            id="low_stock_threshold"
                                            name="low_stock_threshold"
                                            value="<?php echo htmlspecialchars($old['low_stock_threshold'] ?? '5'); ?>"
                                            min="1"
                                            class="w-full px-4 py-2.5 text-sm border border-gray-200 rounded-xl bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 outline-none transition">
                                        <p class="text-[11px] text-gray-400 mt-1">Alert fires when stock drops to or below this number.</p>
                                    </div>

                                </div>
                            </div>
                        </div>

                    </div><!-- /left column -->


                    <!-- ── RIGHT COLUMN (image + actions) ─────────────── -->
                    <div class="space-y-6">

                        <!-- Image card -->
                        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                            <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-2">
                                <i data-lucide="image" class="w-4 h-4 text-brand-red"></i>
                                <h2 class="text-sm font-bold text-gray-900">Product Image</h2>
                            </div>
                            <div class="p-6 space-y-5">

                                <!-- Preview -->
                                <div id="image-preview-wrap" class="w-full aspect-square rounded-xl bg-gray-100 border-2 border-dashed border-gray-200 flex items-center justify-center overflow-hidden relative">
                                    <img
                                        id="image-preview"
                                        src=""
                                        alt="Preview"
                                        class="w-full h-full object-cover hidden">
                                    <div id="image-placeholder" class="flex flex-col items-center gap-2 text-gray-400 p-4 text-center">
                                        <i data-lucide="image-plus" class="w-10 h-10 text-gray-300"></i>
                                        <p class="text-xs font-medium">Image preview</p>
                                    </div>
                                </div>

                                <!-- Upload from disk -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Upload Image File</label>
                                    <label for="image_file"
                                        class="flex items-center gap-2 cursor-pointer w-full px-4 py-2.5 rounded-xl border border-dashed border-gray-300 bg-gray-50 hover:bg-gray-100 transition text-sm text-gray-600 font-medium">
                                        <i data-lucide="upload-cloud" class="w-4 h-4 text-gray-400 flex-shrink-0"></i>
                                        <span id="file-label">Choose file…</span>
                                    </label>
                                    <input
                                        type="file"
                                        id="image_file"
                                        name="image_file"
                                        accept="image/jpeg,image/png,image/webp,image/gif"
                                        class="sr-only">
                                    <p class="text-[11px] text-gray-400 mt-1">JPEG, PNG, WebP or GIF · max 3 MB</p>
                                </div>

                                <!-- Divider -->
                                <div class="flex items-center gap-3">
                                    <div class="flex-1 h-px bg-gray-200"></div>
                                    <span class="text-xs text-gray-400 font-medium">or use existing filename</span>
                                    <div class="flex-1 h-px bg-gray-200"></div>
                                </div>

                                <!-- Manual path/URL -->
                                <div>
                                    <label for="image_url_manual" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                        Image Path / URL
                                    </label>
                                    <input
                                        type="text"
                                        id="image_url_manual"
                                        name="image_url_manual"
                                        value="<?php echo htmlspecialchars($old['image_url_manual'] ?? ''); ?>"
                                        placeholder="assets/images/products/filename.jpg"
                                        class="w-full px-4 py-2.5 text-sm border border-gray-200 rounded-xl bg-gray-50 focus:bg-white focus:border-brand-red focus:ring-2 focus:ring-brand-red/20 outline-none transition placeholder-gray-400">
                                    <p class="text-[11px] text-gray-400 mt-1">
                                        Relative path or full URL. Uploading a file above overrides this.
                                    </p>
                                </div>

                            </div>
                        </div>


                        <!-- Action card -->
                        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 space-y-3">

                            <button
                                type="submit"
                                class="w-full inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-sm font-bold transition shadow-sm">
                                <i data-lucide="plus-circle" class="w-4 h-4"></i>
                                Add Product
                            </button>

                            <a href="<?php echo APP_URL; ?>/admin/products/index.php"
                                class="w-full inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl border border-gray-200 text-gray-600 hover:bg-gray-50 text-sm font-semibold transition">
                                <i data-lucide="x" class="w-4 h-4"></i>
                                Cancel
                            </a>

                        </div>


                        <!-- Tips card -->
                        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-5">
                            <div class="flex items-start gap-3">
                                <i data-lucide="lightbulb" class="w-4 h-4 text-amber-600 flex-shrink-0 mt-0.5"></i>
                                <div class="space-y-1.5 text-[12px] text-amber-800">
                                    <p class="font-bold text-amber-900">Tips</p>
                                    <p>• Set <strong>Status</strong> to <em>Available</em> so the product appears in the storefront.</p>
                                    <p>• The <strong>Low Stock Threshold</strong> triggers the sidebar alert badge.</p>
                                    <p>• Check <strong>Repairable</strong> for phones/devices that can be booked for repair.</p>
                                    <p>• Uploaded images are saved to <code class="bg-amber-100 px-1 rounded">assets/images/products/</code>.</p>
                                </div>
                            </div>
                        </div>

                    </div><!-- /right column -->

                </div><!-- /grid -->

            </form>

            <!-- Footer -->
            <p class="text-center text-xs text-gray-400 mt-8 pb-2">
                <?php echo APP_NAME; ?> &mdash; Admin Panel &mdash; v<?php echo APP_VERSION; ?>
            </p>

        </main>

    </div><!-- /main content -->

</div><!-- /flex wrapper -->

<script>lucide.createIcons();</script>

<script>
/* ── Image file picker preview ─────────────────────────────────────── */
(function () {
    const fileInput     = document.getElementById('image_file');
    const manualInput   = document.getElementById('image_url_manual');
    const preview       = document.getElementById('image-preview');
    const placeholder   = document.getElementById('image-placeholder');
    const fileLabel     = document.getElementById('file-label');
    const appUrl        = window.__APP_URL__ || '';

    function showPreview(src) {
        if (!src) {
            preview.classList.add('hidden');
            placeholder.classList.remove('hidden');
            return;
        }
        preview.src = src;
        preview.onload  = () => {
            preview.classList.remove('hidden');
            placeholder.classList.add('hidden');
        };
        preview.onerror = () => {
            preview.classList.add('hidden');
            placeholder.classList.remove('hidden');
        };
    }

    /* File upload → preview via FileReader */
    fileInput.addEventListener('change', function () {
        const file = this.files[0];
        if (!file) return;

        fileLabel.textContent = file.name;

        /* Clear manual URL so the uploaded file takes priority */
        manualInput.value = '';

        const reader = new FileReader();
        reader.onload = e => showPreview(e.target.result);
        reader.readAsDataURL(file);
    });

    /* Manual URL → preview */
    manualInput.addEventListener('input', function () {
        const val = this.value.trim();
        if (!val) { showPreview(''); return; }

        const src = (val.startsWith('http') || val.startsWith('//'))
            ? val
            : appUrl + '/' + val.replace(/^\/+/, '');

        showPreview(src);

        /* Clear file input if user is typing a manual URL */
        fileInput.value = '';
        fileLabel.textContent = 'Choose file…';
    });

    /* Pre-fill preview if old value exists (after validation failure) */
    const existingManual = manualInput.value.trim();
    if (existingManual) {
        const src = (existingManual.startsWith('http') || existingManual.startsWith('//'))
            ? existingManual
            : appUrl + '/' + existingManual.replace(/^\/+/, '');
        showPreview(src);
    }
})();

/* ── Technical Specifications builder ─────────────────────────────── */
function specRowHtml() {
    return `<div class="spec-row grid grid-cols-1 sm:grid-cols-[140px_1fr_1fr_auto] md:grid-cols-[160px_1fr_1fr_auto] gap-2.5 items-center bg-gray-50 sm:bg-transparent p-3 sm:p-0 rounded-xl">
        <input type="text" name="spec_group[]" list="spec-group-list" placeholder="Group (e.g. Display)"
            class="px-3 py-2 text-sm border border-gray-200 rounded-xl bg-white sm:bg-gray-50 focus:bg-white focus:border-red-500 focus:ring-2 focus:ring-red-500/20 outline-none transition placeholder-gray-400">
        <input type="text" name="spec_name[]" placeholder="Spec Name (e.g. Screen Size)"
            class="px-3 py-2 text-sm border border-gray-200 rounded-xl bg-white sm:bg-gray-50 focus:bg-white focus:border-red-500 focus:ring-2 focus:ring-red-500/20 outline-none transition placeholder-gray-400">
        <input type="text" name="spec_value[]" placeholder="Value (e.g. 6.7&quot; AMOLED)"
            class="px-3 py-2 text-sm border border-gray-200 rounded-xl bg-white sm:bg-gray-50 focus:bg-white focus:border-red-500 focus:ring-2 focus:ring-red-500/20 outline-none transition placeholder-gray-400">
        <button type="button" onclick="removeSpec(this)"
            class="w-8 h-8 flex items-center justify-center rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 transition flex-shrink-0 self-end sm:self-center">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
        </button>
    </div>`;
}

document.getElementById('add-spec-btn').addEventListener('click', function () {
    const list = document.getElementById('specs-list');
    const div  = document.createElement('div');
    div.innerHTML = specRowHtml();
    list.appendChild(div.firstElementChild);
    /* Focus the new label input */
    list.lastElementChild.querySelector('input').focus();
});

function removeSpec(btn) {
    const row  = btn.closest('.spec-row');
    const list = document.getElementById('specs-list');
    /* Keep at least one row */
    if (list.querySelectorAll('.spec-row').length > 1) {
        row.remove();
    } else {
        row.querySelectorAll('input').forEach(i => i.value = '');
    }
}
</script>

</body>
</html>
