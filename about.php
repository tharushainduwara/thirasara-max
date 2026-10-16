<?php

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = 'About Us';
require_once __DIR__ . '/includes/header.php';
?>

<!-- PAGE HEADER (Matching High-Contrast Style) -->
<section class="bg-gradient-to-r from-gray-900 via-zinc-900 to-gray-900 text-white border-b-4 border-brand-red py-12 rounded-t-3xl overflow-hidden">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="max-w-3xl">
            <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded bg-brand-red text-white text-[10px] font-bold uppercase tracking-wider mb-3">
                Our Story
            </span>
            <h1 class="text-3xl sm:text-4xl font-black uppercase tracking-tight text-white">About Thirasara Max Mobile</h1>
            <p class="text-gray-300 text-xs sm:text-sm mt-3 leading-relaxed">
                Located conveniently along Galle - Colombo Road in Wadduwa, we offer genuine mobile devices, original accessories, expert technical repairs, and digital warranty tracking.
            </p>
            <div class="mt-6 flex flex-wrap gap-3">
                <a href="<?= APP_URL ?>/products.php"
                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-brand-red hover:bg-brand-redHover text-white font-bold text-xs transition shadow-sm">
                    <i data-lucide="shopping-bag" class="w-4 h-4"></i>
                    Browse Products
                </a>
                <a href="<?= APP_URL ?>/contact.php"
                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-white text-gray-900 hover:bg-gray-100 font-bold text-xs transition shadow-sm">
                    <i data-lucide="map-pin" class="w-4 h-4 text-brand-red"></i>
                    Find Store
                </a>
            </div>
        </div>
    </div>
</section>

<!-- STATS STRIP -->
<section class="bg-gray-950 text-gray-300 py-6 border-b border-gray-800 rounded-b-3xl overflow-hidden">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-6 text-center divide-y md:divide-y-0 md:divide-x divide-gray-800">
            <div class="px-4">
                <span class="text-2xl sm:text-3xl font-black text-white">100%</span>
                <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mt-1">Genuine Parts</p>
            </div>
            <div class="px-4 pt-4 md:pt-0">
                <span class="text-2xl sm:text-3xl font-black text-white">10k+</span>
                <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mt-1">Repairs Completed</p>
            </div>
            <div class="px-4 pt-4 md:pt-0">
                <span class="text-2xl sm:text-3xl font-black text-white">Digital</span>
                <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mt-1">Warranty Records</p>
            </div>
            <div class="px-4 pt-4 md:pt-0">
                <span class="text-2xl sm:text-3xl font-black text-brand-red">5+ Yrs</span>
                <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mt-1">Local Trust</p>
            </div>
        </div>
    </div>
</section>

<!-- OUR JOURNEY & REPAIR LAB -->
<section class="py-12 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-10 items-center">

            <!-- Left Info -->
            <div class="lg:col-span-7">
                <span class="text-xs font-bold uppercase tracking-wider text-brand-red">Mission & History</span>
                <h2 class="text-xl sm:text-2xl font-black text-gray-900 mt-1 mb-4 uppercase tracking-tight">Quality Service Built for Wadduwa</h2>
                <div class="space-y-3.5 text-gray-700 text-xs sm:text-sm leading-relaxed">
                    <p>
                        Established along the high-traffic Galle - Colombo highway corridor, <strong class="text-gray-900">Thirasara Max Mobile</strong> was created with a single standard: to provide a straightforward, dependable store where customers receive original components and honest advice.
                    </p>
                    <p>
                        Whether you need high-speed fast chargers, protective glass, or chip-level smartphone troubleshooting, our technical staff handles every item with care and precision.
                    </p>
                </div>

                <div class="mt-6 pt-5 border-t-2 border-gray-100 grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="flex items-center gap-2 text-xs font-bold text-gray-800">
                        <i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-600 flex-shrink-0"></i>
                        <span>Authentic Stock</span>
                    </div>
                    <div class="flex items-center gap-2 text-xs font-bold text-gray-800">
                        <i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-600 flex-shrink-0"></i>
                        <span>Upfront Pricing</span>
                    </div>
                    <div class="flex items-center gap-2 text-xs font-bold text-gray-800">
                        <i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-600 flex-shrink-0"></i>
                        <span>Verified Warranty</span>
                    </div>
                </div>
            </div>

            <!-- Right Visual Features -->
            <div class="lg:col-span-5 bg-gray-50 border-2 border-gray-200 rounded-xl p-5 sm:p-6 space-y-5 shadow-sm">
                <h3 class="text-sm font-black text-gray-900 uppercase tracking-tight border-b-2 border-gray-200 pb-2">Technical Standards</h3>

                <div class="flex items-start gap-3">
                    <div class="w-7 h-7 rounded bg-brand-red text-white flex items-center justify-center flex-shrink-0 text-xs font-extrabold">
                        01
                    </div>
                    <div>
                        <h4 class="text-xs font-bold text-gray-900 uppercase tracking-wider">Diagnostic Equipment</h4>
                        <p class="text-xs text-gray-600 mt-0.5 leading-relaxed">Micro-soldering tools and power diagnostic units for exact fault tracing.</p>
                    </div>
                </div>

                <div class="flex items-start gap-3">
                    <div class="w-7 h-7 rounded bg-blue-600 text-white flex items-center justify-center flex-shrink-0 text-xs font-extrabold">
                        02
                    </div>
                    <div>
                        <h4 class="text-xs font-bold text-gray-900 uppercase tracking-wider">Fast Hardware Servicing</h4>
                        <p class="text-xs text-gray-600 mt-0.5 leading-relaxed">Display panels and battery assemblies replaced with original-spec adhesive seals.</p>
                    </div>
                </div>

                <div class="flex items-start gap-3">
                    <div class="w-7 h-7 rounded bg-emerald-600 text-white flex items-center justify-center flex-shrink-0 text-xs font-extrabold">
                        03
                    </div>
                    <div>
                        <h4 class="text-xs font-bold text-gray-900 uppercase tracking-wider">Live Repair Status</h4>
                        <p class="text-xs text-gray-600 mt-0.5 leading-relaxed">Track your job card's progress anytime using your online job ID.</p>
                    </div>
                </div>
            </div>

        </div>
    </div>
</section>

<!-- SERVICES OVERVIEW -->
<section class="py-12 bg-gray-100 border-t-2 border-gray-200">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <div class="text-center max-w-lg mx-auto mb-8">
            <span class="text-xs font-bold uppercase tracking-wider text-brand-red">Offerings</span>
            <h2 class="text-xl sm:text-2xl font-black text-gray-900 uppercase tracking-tight mt-0.5">What We Provide</h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
            <div class="bg-white border-2 border-gray-200 rounded-xl p-5 shadow-sm flex flex-col justify-between">
                <div>
                    <div class="w-10 h-10 rounded-lg bg-red-100 text-brand-red flex items-center justify-center mb-3">
                        <i data-lucide="headphones" class="w-5 h-5"></i>
                    </div>
                    <h3 class="font-extrabold text-sm text-gray-900 mb-1">Accessories & Audio</h3>
                    <p class="text-xs text-gray-600 leading-relaxed">Fast PD chargers, durable cables, shockproof cases, TWS earphones, and power banks.</p>
                </div>
                <div class="mt-5 pt-3 border-t border-gray-100">
                    <a href="<?= APP_URL ?>/products.php" class="text-xs font-bold text-brand-red hover:underline inline-flex items-center gap-1">
                        View Products &rarr;
                    </a>
                </div>
            </div>

            <div class="bg-white border-2 border-gray-200 rounded-xl p-5 shadow-sm flex flex-col justify-between">
                <div>
                    <div class="w-10 h-10 rounded-lg bg-blue-100 text-blue-700 flex items-center justify-center mb-3">
                        <i data-lucide="smartphone" class="w-5 h-5"></i>
                    </div>
                    <h3 class="font-extrabold text-sm text-gray-900 mb-1">Display & Power Repairs</h3>
                    <p class="text-xs text-gray-600 leading-relaxed">Original-grade screen modules, fresh batteries, charging ports, and speaker replacements.</p>
                </div>
                <div class="mt-5 pt-3 border-t border-gray-100">
                    <a href="<?= APP_URL ?>/repair-booking.php" class="text-xs font-bold text-blue-700 hover:underline inline-flex items-center gap-1">
                        Book Repair &rarr;
                    </a>
                </div>
            </div>

            <div class="bg-white border-2 border-gray-200 rounded-xl p-5 shadow-sm flex flex-col justify-between">
                <div>
                    <div class="w-10 h-10 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center mb-3">
                        <i data-lucide="file-check" class="w-5 h-5"></i>
                    </div>
                    <h3 class="font-extrabold text-sm text-gray-900 mb-1">Digital Warranties</h3>
                    <p class="text-xs text-gray-600 leading-relaxed">Verify coverage periods for purchases and repairs online using serial numbers.</p>
                </div>
                <div class="mt-5 pt-3 border-t border-gray-100">
                    <a href="<?= APP_URL ?>/warranty-check.php" class="text-xs font-bold text-emerald-700 hover:underline inline-flex items-center gap-1">
                        Check Warranty &rarr;
                    </a>
                </div>
            </div>
        </div>

    </div>
</section>

<!-- STORE LOCATION INFO -->
<section class="py-12 bg-white border-t-2 border-gray-200">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="bg-gray-50 border-2 border-gray-200 rounded-xl p-6 sm:p-8 grid grid-cols-1 lg:grid-cols-2 gap-8 items-center">

            <div>
                <span class="text-xs font-bold uppercase tracking-wider text-brand-red">Store Contact</span>
                <h2 class="text-xl sm:text-2xl font-black text-gray-900 uppercase tracking-tight mt-0.5 mb-5">Visit Our Showroom in Wadduwa</h2>

                <div class="space-y-3.5">
                    <div class="flex items-start gap-3">
                        <div class="w-8 h-8 rounded-lg bg-white border border-gray-300 text-brand-red flex items-center justify-center flex-shrink-0 mt-0.5">
                            <i data-lucide="map-pin" class="w-4 h-4"></i>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider block">Address</span>
                            <span class="text-xs font-bold text-gray-800">Galle - Colombo Rd, Wadduwa, Sri Lanka</span>
                        </div>
                    </div>

                    <div class="flex items-start gap-3">
                        <div class="w-8 h-8 rounded-lg bg-white border border-gray-300 text-brand-red flex items-center justify-center flex-shrink-0 mt-0.5">
                            <i data-lucide="phone" class="w-4 h-4"></i>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider block">Hotline</span>
                            <a href="tel:0771234567" class="text-xs font-bold text-gray-800 hover:text-brand-red transition">077 123 4567</a>
                        </div>
                    </div>

                    <div class="flex items-start gap-3">
                        <div class="w-8 h-8 rounded-lg bg-white border border-gray-300 text-brand-red flex items-center justify-center flex-shrink-0 mt-0.5">
                            <i data-lucide="clock" class="w-4 h-4"></i>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider block">Opening Hours</span>
                            <span class="text-xs font-bold text-gray-800 block">Mon &ndash; Sat: 9:00 AM &ndash; 8:00 PM</span>
                            <span class="text-xs font-bold text-gray-800 block">Sun: 9:00 AM &ndash; 5:00 PM</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white border-2 border-gray-200 rounded-xl p-6 text-center flex flex-col items-center justify-center min-h-[200px]">
                <div class="w-12 h-12 rounded-xl bg-gray-100 text-gray-800 flex items-center justify-center mb-2">
                    <i data-lucide="navigation" class="w-6 h-6"></i>
                </div>
                <h3 class="text-sm font-extrabold text-gray-900">Get Store Directions</h3>
                <p class="text-xs text-gray-500 mt-1 mb-4">Located right along Galle - Colombo Road with available parking space.</p>
                <a href="https://maps.google.com/?q=Wadduwa+Galle+Colombo+Road"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-brand-red hover:bg-brand-redHover text-white font-bold text-xs transition shadow-sm">
                    <i data-lucide="external-link" class="w-3.5 h-3.5"></i>
                    Open in Google Maps
                </a>
            </div>

        </div>
    </div>
</section>

<!-- CALL TO ACTION -->
<section class="py-10 bg-gray-950 text-white border-t-2 border-brand-red rounded-3xl overflow-hidden">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-5">
        <div>
            <h2 class="text-lg sm:text-xl font-black uppercase tracking-tight">Need a repair or accessory recommendation?</h2>
            <p class="text-gray-400 text-xs mt-0.5">Get in touch with our Wadduwa shop team today.</p>
        </div>
        <div class="flex items-center gap-3 flex-shrink-0">
            <a href="<?= APP_URL ?>/products.php" class="px-5 py-2.5 rounded-xl bg-brand-red hover:bg-brand-redHover text-white text-xs font-bold transition">
                Shop Products
            </a>
            <a href="<?= APP_URL ?>/contact.php" class="px-5 py-2.5 rounded-xl bg-white text-gray-900 hover:bg-gray-100 text-xs font-bold transition">
                Contact Us
            </a>
        </div>
    </div>
</section>

<script>
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>