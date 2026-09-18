<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUser = getCurrentUser();

$cartCount = 0;
if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $cartItem) {
        $cartCount += isset($cartItem['quantity']) ? (int) $cartItem['quantity'] : 0;
    }
}

$currentScript = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?= isset($pageTitle)
            ? htmlspecialchars($pageTitle) . ' | ' . APP_NAME
            : APP_NAME . ' - ' . APP_TAGLINE ?>
    </title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Custom Stylesheet -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">

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
        };
    </script>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <!-- Global App State -->
    <script>
        window.__APP_URL__ = '<?= rtrim(APP_URL, '/') ?>';
    </script>
    <script src="<?= APP_URL ?>/assets/js/main.js" defer></script>
</head>

<body class="bg-gray-50 text-gray-800 flex flex-col min-h-screen font-sans antialiased">

    <!-- NAVBAR -->
    <header class="bg-brand-dark border-b border-brand-border sticky top-0 z-50 shadow-md">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-20">

                <!-- Logo -->
                <a href="<?= APP_URL ?>/index.php" class="flex items-center">
                    <img src="<?= APP_URL ?>/assets/images/Logo.png"
                        alt="Thirasara Max Mobile"
                        class="h-14 w-auto rounded-full object-contain"
                        onerror="this.onerror=null; this.src='<?= APP_URL ?>/assets/images/Logo.jpg';">
                </a>

                <!-- Desktop Navigation -->
                <nav class="hidden lg:flex items-center space-x-7">
                    <a href="<?= APP_URL ?>/index.php"
                        class="text-sm font-medium transition-colors <?= $currentScript === 'index.php' ? 'text-white font-semibold' : 'text-gray-300 hover:text-white' ?>">
                        Home
                    </a>

                    <a href="<?= APP_URL ?>/products.php"
                        class="text-sm font-medium transition-colors <?= $currentScript === 'products.php' ? 'text-white font-semibold' : 'text-gray-300 hover:text-white' ?>">
                        Products & Accessories
                    </a>

                    <a href="<?= APP_URL ?>/contact.php"
                        class="text-sm font-medium transition-colors <?= $currentScript === 'contact.php' ? 'text-white font-semibold' : 'text-gray-300 hover:text-white' ?>">
                        Contact Us
                    </a>

                    <a href="<?= APP_URL ?>/about.php"
                        class="text-sm font-medium transition-colors <?= $currentScript === 'about.php' ? 'text-white font-semibold' : 'text-gray-300 hover:text-white' ?>">
                        About Us
                    </a>

                    <a href="<?= APP_URL ?>/repair-track.php"
                        class="text-sm font-medium transition-colors <?= $currentScript === 'repair-track.php' ? 'text-white font-semibold' : 'text-gray-300 hover:text-white' ?>">
                        Track Repair
                    </a>
                </nav>

                <!-- Right Side Actions -->
                <div class="flex items-center space-x-3 sm:space-x-4">

                    <!-- Shopping Cart Icon -->
                    <a href="<?= APP_URL ?>/cart.php" class="relative p-2 text-gray-300 hover:text-white transition-colors" title="Shopping Cart">
                        <i data-lucide="shopping-bag" class="w-5 h-5"></i>
                        <?php if ($cartCount > 0): ?>
                            <span class="absolute -top-0.5 -right-0.5 min-w-4 h-4 px-1 bg-brand-red text-white text-[10px] font-bold rounded-full flex items-center justify-center ring-2 ring-brand-dark">
                                <?= $cartCount ?>
                            </span>
                        <?php endif; ?>
                    </a>

                    <!-- User Account / Auth Actions -->
                    <?php if ($currentUser): ?>
                        <a href="<?= APP_URL . '/' . htmlspecialchars($currentUser['role']) ?>/dashboard.php"
                            class="hidden sm:flex items-center gap-2 text-gray-300 hover:text-white transition">
                            <div class="w-8 h-8 rounded-full bg-brand-red flex items-center justify-center text-white text-xs font-bold">
                                <?= strtoupper(substr($currentUser['name'] ?? 'U', 0, 1)) ?>
                            </div>
                            <span class="text-sm font-medium">
                                <?= htmlspecialchars($currentUser['name']) ?>
                            </span>
                        </a>

                        <a href="<?= APP_URL ?>/logout.php"
                            class="hidden sm:inline-flex p-2 text-gray-400 hover:text-brand-red transition"
                            title="Sign Out">
                            <i data-lucide="log-out" class="w-4 h-4"></i>
                        </a>
                    <?php else: ?>
                        <a href="<?= APP_URL ?>/login.php"
                            class="hidden sm:inline-flex items-center gap-2 px-4 py-2 bg-brand-red hover:bg-brand-redHover text-white text-sm font-semibold rounded-lg transition">
                            <i data-lucide="log-in" class="w-4 h-4"></i>
                            Login
                        </a>
                    <?php endif; ?>

                    <!-- Mobile Menu Toggle Button -->
                    <button type="button"
                        id="mobile-menu-btn"
                        class="lg:hidden p-2 text-gray-300 hover:text-white"
                        aria-label="Open menu">
                        <i data-lucide="menu" class="w-6 h-6"></i>
                    </button>
                </div>
            </div>

            <!-- Mobile Navigation Drawer -->
            <div id="mobile-menu" class="hidden lg:hidden pb-5">
                <nav class="flex flex-col space-y-1">
                    <a href="<?= APP_URL ?>/index.php"
                        class="flex items-center gap-3 px-3 py-3 rounded-lg text-sm <?= $currentScript === 'index.php' ? 'text-white bg-white/5' : 'text-gray-300 hover:text-white hover:bg-white/5' ?>">
                        <i data-lucide="home" class="w-4 h-4"></i>
                        Home
                    </a>

                    <a href="<?= APP_URL ?>/products.php"
                        class="flex items-center gap-3 px-3 py-3 rounded-lg text-sm <?= $currentScript === 'products.php' ? 'text-white bg-white/5' : 'text-gray-300 hover:text-white hover:bg-white/5' ?>">
                        <i data-lucide="smartphone" class="w-4 h-4"></i>
                        Products & Accessories
                    </a>

                    <a href="<?= APP_URL ?>/contact.php"
                        class="flex items-center gap-3 px-3 py-3 rounded-lg text-sm <?= $currentScript === 'contact.php' ? 'text-white bg-white/5' : 'text-gray-300 hover:text-white hover:bg-white/5' ?>">
                        <i data-lucide="wrench" class="w-4 h-4"></i>
                        Contact Us
                    </a>

                    <a href="<?= APP_URL ?>/about.php"
                        class="flex items-center gap-3 px-3 py-3 rounded-lg text-sm <?= $currentScript === 'about.php' ? 'text-white bg-white/5' : 'text-gray-300 hover:text-white hover:bg-white/5' ?>">
                        <i data-lucide="activity" class="w-4 h-4"></i>
                        About Us
                    </a>

                    <a href="<?= APP_URL ?>/repair-track.php"
                        class="flex items-center gap-3 px-3 py-3 rounded-lg text-sm <?= $currentScript === 'repair-track.php' ? 'text-white bg-white/5' : 'text-gray-300 hover:text-white hover:bg-white/5' ?>">
                        <i data-lucide="shield-check" class="w-4 h-4"></i>
                        Track Repair
                    </a>

                    <!-- Mobile User Section -->
                    <?php if ($currentUser): ?>
                        <div class="border-t border-gray-800 mt-3 pt-4">
                            <div class="flex items-center justify-between px-3 py-3 mb-2">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center text-white text-sm font-bold">
                                        <?= strtoupper(substr($currentUser['name'] ?? 'U', 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="text-sm font-semibold text-white">
                                            <?= htmlspecialchars($currentUser['name']) ?>
                                        </div>
                                        <div class="text-[10px] uppercase tracking-wider text-brand-red font-bold">
                                            <?= htmlspecialchars($currentUser['role']) ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <a href="<?= APP_URL . '/' . htmlspecialchars($currentUser['role']) ?>/dashboard.php"
                                class="flex items-center justify-center gap-2 w-full px-4 py-3 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-xs font-bold transition">
                                <i data-lucide="layout-dashboard" class="w-4 h-4"></i>
                                Dashboard
                            </a>

                            <a href="<?= APP_URL ?>/logout.php"
                                class="flex items-center justify-center gap-2 w-full px-4 py-3 mt-2 rounded-xl bg-gray-900 hover:bg-gray-800 border border-gray-800 text-gray-300 text-xs font-semibold transition">
                                <i data-lucide="log-out" class="w-4 h-4"></i>
                                Logout
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="border-t border-gray-800 mt-3 pt-4 grid grid-cols-2 gap-2">
                            <a href="<?= APP_URL ?>/login.php"
                                class="flex items-center justify-center gap-2 py-3 rounded-xl bg-gray-900 border border-gray-800 text-white text-xs font-semibold">
                                <i data-lucide="log-in" class="w-4 h-4"></i>
                                Login
                            </a>

                            <a href="<?= APP_URL ?>/register.php"
                                class="flex items-center justify-center gap-2 py-3 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-xs font-bold">
                                <i data-lucide="user-plus" class="w-4 h-4"></i>
                                Register
                            </a>
                        </div>
                    <?php endif; ?>
                </nav>
            </div>
        </div>
    </header>

    <!-- Main Content Wrapper -->
    <main class="flex-grow">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <?php displayFlash(); ?>