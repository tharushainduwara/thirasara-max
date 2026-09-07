<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$currentUser = getCurrentUser();
$cartCount = isset($_SESSION['cart']) ? array_sum(array_column($_SESSION['cart'], 'quantity')) : 0;
$currentScript = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) . ' | ' . APP_NAME : APP_NAME . ' - ' . APP_TAGLINE; ?></title>

    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Tailwind CSS CDN -->
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
                        },
                        primary: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                            800: '#1e40af',
                            900: '#1e3a8a',
                        }
                    }
                }
            }
        }
    </script>
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
</head>

<body class="bg-gray-50 text-gray-800 flex flex-col min-h-screen font-sans antialiased">

    <!-- Top Modern Dark Navbar -->
    <header class="bg-brand-dark border-b border-brand-border sticky top-0 z-50 shadow-md">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-20">

                <!-- Brand Logo -->
                <div class="flex items-center">
                    <a> <img src="<?php echo APP_URL; ?>/assets/images/Logo.png" alt="Thirasara Max Mobile" class="h-14 w-auto border-brand-border rounded-full object-contain" onerror="this.onerror=null; this.src='<?php echo APP_URL; ?>/assets/images/Logo.jpg';">
                    </a>
                </div>

                <!-- Desktop Navigation Menu (Tailored for Thirasara Max) -->
                <nav class="hidden lg:flex items-center space-x-7">
                    <a href="<?php echo APP_URL; ?>/index.php" class="text-sm font-medium transition-colors <?php echo $currentScript === 'index.php' ? 'text-white font-semibold' : 'text-gray-300 hover:text-white'; ?>">
                        Home
                    </a>
                    <a href="<?php echo APP_URL; ?>/products.php" class="text-sm font-medium transition-colors <?php echo $currentScript === 'products.php' ? 'text-white font-semibold' : 'text-gray-300 hover:text-white'; ?>">
                        Products & Accessories
                    </a>
                    <a href="<?php echo APP_URL; ?>/repair-booking.php" class="text-sm font-medium transition-colors <?php echo $currentScript === 'repair-booking.php' ? 'text-white font-semibold' : 'text-gray-300 hover:text-white'; ?>">
                        Book Repair
                    </a>
                    <a href="<?php echo APP_URL; ?>/repair-track.php" class="text-sm font-medium transition-colors <?php echo $currentScript === 'repair-track.php' ? 'text-white font-semibold' : 'text-gray-300 hover:text-white'; ?>">
                        Track Repair
                    </a>
                    <a href="<?php echo APP_URL; ?>/warranty-check.php" class="text-sm font-medium transition-colors <?php echo $currentScript === 'warranty-check.php' ? 'text-white font-semibold' : 'text-gray-300 hover:text-white'; ?>">
                        Warranty Check
                    </a>
                    <a href="<?php echo APP_URL; ?>/inquiry.php" class="text-sm font-medium transition-colors <?php echo $currentScript === 'inquiry.php' ? 'text-white font-semibold' : 'text-gray-300 hover:text-white'; ?>">
                        Inquiries
                    </a>
                </nav>

                <!-- Right Action Icons & Auth Controls -->
                <div class="flex items-center space-x-4">

                    <!-- Shopping Cart Icon with Live Count -->
                    <a href="<?php echo APP_URL; ?>/cart.php" class="relative p-2 text-gray-300 hover:text-white transition-colors" title="Shopping Cart">
                        <i data-lucide="shopping-bag" class="w-5 h-5"></i>
                        <?php if ($cartCount > 0): ?>
                            <span class="absolute 1 top-0.5 right-0.5 inline-flex items-center justify-center px-1.5 py-0.5 text-[10px] font-bold leading-none text-white bg-brand-red rounded-full ring-2 ring-brand-dark">
                                <?php echo $cartCount; ?>
                            </span>
                        <?php endif; ?>
                    </a>

                    <!-- User Portal / Dashboard or Login/Register -->
                    <div class="hidden sm:flex items-center space-x-3 border-l border-gray-800 pl-4">
                        <?php if ($currentUser): ?>
                            <!-- Logged In User Pill -->
                            <a href="<?php echo APP_URL . '/' . $currentUser['role'] . '/dashboard.php'; ?>" class="flex items-center space-x-2 bg-gray-900 hover:bg-gray-800 border border-gray-800 text-gray-200 px-3.5 py-2 rounded-xl text-xs font-semibold transition-colors">
                                <i data-lucide="user" class="w-4 h-4 text-brand-red"></i>
                                <span><?php echo htmlspecialchars($currentUser['name']); ?></span>
                                <span class="text-[10px] bg-brand-red/20 text-brand-red px-1.5 py-0.5 rounded uppercase font-bold"><?php echo $currentUser['role']; ?></span>
                            </a>
                            <a href="<?php echo APP_URL; ?>/logout.php" class="text-gray-400 hover:text-brand-red p-2 transition-colors" title="Sign Out">
                                <i data-lucide="log-out" class="w-4 h-4"></i>
                            </a>
                        <?php else: ?>
                            <!-- Guest Controls -->
                            <a href="<?php echo APP_URL; ?>/login.php" class="text-gray-300 hover:text-white font-medium text-xs px-3 py-2 transition-colors">
                                Sign In
                            </a>

                            <a href="<?php echo APP_URL; ?>/register.php" class="text-gray-300 hover:text-white font-medium text-xs px-3 py-2 transition-colors">
                                Register
                            </a>
                        <?php endif; ?>
                    </div>

                    <!-- Mobile Menu Hamburger Button -->
                    <button id="mobile-menu-btn" type="button" class="lg:hidden text-gray-300 hover:text-white focus:outline-none p-2 rounded-xl transition-colors hover:bg-gray-900">
                        <i data-lucide="menu" class="w-6 h-6"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Mobile Navigation Menu Drawer -->
        <div id="mobile-menu" class="hidden lg:hidden bg-[#0d0d11] border-t border-brand-border shadow-2xl">
            <div class="px-4 pt-3 pb-6 space-y-1 text-sm">
                <a href="<?php echo APP_URL; ?>/index.php" class="flex items-center space-x-3 px-3 py-3 rounded-xl text-gray-300 hover:text-white hover:bg-gray-900 transition">
                    <i data-lucide="home" class="w-4 h-4 text-gray-400"></i>
                    <span>Home</span>
                </a>
                <a href="<?php echo APP_URL; ?>/products.php" class="flex items-center space-x-3 px-3 py-3 rounded-xl text-gray-300 hover:text-white hover:bg-gray-900 transition">
                    <i data-lucide="smartphone" class="w-4 h-4 text-gray-400"></i>
                    <span>Products & Accessories</span>
                </a>
                <a href="<?php echo APP_URL; ?>/repair-booking.php" class="flex items-center space-x-3 px-3 py-3 rounded-xl text-gray-300 hover:text-white hover:bg-gray-900 transition">
                    <i data-lucide="wrench" class="w-4 h-4 text-gray-400"></i>
                    <span>Book Repair</span>
                </a>
                <a href="<?php echo APP_URL; ?>/repair-track.php" class="flex items-center space-x-3 px-3 py-3 rounded-xl text-gray-300 hover:text-white hover:bg-gray-900 transition">
                    <i data-lucide="activity" class="w-4 h-4 text-gray-400"></i>
                    <span>Track Repair</span>
                </a>
                <a href="<?php echo APP_URL; ?>/warranty-check.php" class="flex items-center space-x-3 px-3 py-3 rounded-xl text-gray-300 hover:text-white hover:bg-gray-900 transition">
                    <i data-lucide="shield-check" class="w-4 h-4 text-gray-400"></i>
                    <span>Warranty Check & Claim</span>
                </a>
                <a href="<?php echo APP_URL; ?>/inquiry.php" class="flex items-center space-x-3 px-3 py-3 rounded-xl text-gray-300 hover:text-white hover:bg-gray-900 transition">
                    <i data-lucide="help-circle" class="w-4 h-4 text-gray-400"></i>
                    <span>Inquiries & Support</span>
                </a>
                <a href="<?php echo APP_URL; ?>/cart.php" class="flex items-center justify-between px-3 py-3 rounded-xl text-gray-300 hover:text-white hover:bg-gray-900 transition">
                    <div class="flex items-center space-x-3">
                        <i data-lucide="shopping-bag" class="w-4 h-4 text-gray-400"></i>
                        <span>Shopping Cart</span>
                    </div>
                    <?php if ($cartCount > 0): ?>
                        <span class="bg-brand-red text-white text-xs font-bold px-2 py-0.5 rounded-full"><?php echo $cartCount; ?></span>
                    <?php endif; ?>
                </a>

                <div class="border-t border-gray-800 my-3 pt-3">
                    <?php if ($currentUser): ?>
                        <div class="p-3 bg-gray-900 rounded-xl mb-3 flex items-center justify-between">
                            <div>
                                <div class="text-sm font-bold text-white"><?php echo htmlspecialchars($currentUser['name']); ?></div>
                                <div class="text-xs text-brand-red font-semibold uppercase tracking-wider"><?php echo $currentUser['role']; ?> Role</div>
                            </div>
                            <a href="<?php echo APP_URL; ?>/logout.php" class="text-xs text-gray-400 hover:text-brand-red font-bold">Logout</a>
                        </div>
                        <a href="<?php echo APP_URL . '/' . $currentUser['role'] . '/dashboard.php'; ?>" class="block w-full text-center bg-brand-red hover:bg-brand-redHover text-white font-bold py-3 rounded-xl uppercase tracking-wider text-xs transition">
                            Go to Dashboard
                        </a>
                    <?php else: ?>
                        <div class="grid grid-cols-2 gap-2">
                            <a href="<?php echo APP_URL; ?>/login.php" class="block text-center bg-gray-900 hover:bg-gray-800 text-white font-bold py-3 rounded-xl text-xs transition border border-gray-800">
                                Sign In
                            </a>
                            <a href="<?php echo APP_URL; ?>/register.php" class="block text-center bg-brand-red hover:bg-brand-redHover text-white font-bold py-3 rounded-xl text-xs uppercase tracking-wider transition">
                                Register
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="flex-grow">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <?php displayFlash(); ?>

            <script>
                // Initialize Lucide Icons
                lucide.createIcons();

                // Mobile Menu Toggle
                const mobileBtn = document.getElementById('mobile-menu-btn');
                const mobileMenu = document.getElementById('mobile-menu');

                if (mobileBtn && mobileMenu) {
                    mobileBtn.addEventListener('click', () => {
                        mobileMenu.classList.toggle('hidden');
                    });
                }
            </script>