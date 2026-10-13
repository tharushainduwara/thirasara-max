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

<!-- Hero Section -->
<section class="relative overflow-hidden rounded-3xl bg-brand-dark text-white p-8 sm:p-12 lg:p-16 mb-12 border border-brand-border shadow-xl">
    <!-- Subtle Background Glow -->
    <div class="absolute -top-24 -right-24 w-96 h-96 bg-brand-red/20 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute -bottom-24 -left-24 w-96 h-96 bg-blue-600/10 rounded-full blur-3xl pointer-events-none"></div>

    <div class="relative z-10 max-w-3xl">
        <span class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-brand-red/10 border border-brand-red/20 text-brand-red text-xs font-bold uppercase tracking-wider mb-4">
            <i data-lucide="smartphone" class="w-3.5 h-3.5"></i>
            Wadduwa's Trusted Tech Destination
        </span>
        <h1 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold tracking-tight text-white mb-6 leading-tight">
            Connecting You with Genuine Tech, Reliable Repairs &amp; Quality Care
        </h1>
        <p class="text-gray-300 text-base sm:text-lg leading-relaxed mb-8">
            At <strong class="text-white">Thirasara Max Mobile</strong>, we believe staying connected should never be compromised by low-quality accessories or unreliable repairs. Located conveniently along the Galle - Colombo Road in Wadduwa, we provide genuine smartphones, premium accessories, expert device restorations, and transparent digital warranties.
        </p>

        <div class="flex flex-wrap items-center gap-4">
            <a href="<?= APP_URL ?>/products.php"
               class="inline-flex items-center gap-2 px-6 py-3.5 rounded-xl bg-brand-red hover:bg-brand-redHover text-white font-bold text-sm transition shadow-lg shadow-brand-red/30">
                <i data-lucide="shopping-bag" class="w-4 h-4"></i>
                Shop Accessories &amp; Devices
            </a>
            <a href="<?= APP_URL ?>/contact.php"
               class="inline-flex items-center gap-2 px-6 py-3.5 rounded-xl bg-gray-900/80 hover:bg-gray-800 border border-gray-700 text-gray-200 font-semibold text-sm transition">
                <i data-lucide="map-pin" class="w-4 h-4"></i>
                Visit Our Store
            </a>
        </div>
    </div>
</section>

<!-- Key Stats Counter -->
<section class="grid grid-cols-2 md:grid-cols-4 gap-4 sm:gap-6 mb-16">
    <div class="bg-white rounded-2xl border border-gray-200 p-6 text-center shadow-sm hover:shadow-md transition">
        <div class="w-12 h-12 mx-auto mb-3 rounded-xl bg-red-50 text-brand-red flex items-center justify-center">
            <i data-lucide="shield-check" class="w-6 h-6"></i>
        </div>
        <div class="text-3xl font-black text-gray-900 mb-1">100%</div>
        <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Genuine Products</div>
    </div>

    <div class="bg-white rounded-2xl border border-gray-200 p-6 text-center shadow-sm hover:shadow-md transition">
        <div class="w-12 h-12 mx-auto mb-3 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
            <i data-lucide="wrench" class="w-6 h-6"></i>
        </div>
        <div class="text-3xl font-black text-gray-900 mb-1">10k+</div>
        <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Devices Repaired</div>
    </div>

    <div class="bg-white rounded-2xl border border-gray-200 p-6 text-center shadow-sm hover:shadow-md transition">
        <div class="w-12 h-12 mx-auto mb-3 rounded-xl bg-green-50 text-green-600 flex items-center justify-center">
            <i data-lucide="badge-check" class="w-6 h-6"></i>
        </div>
        <div class="text-3xl font-black text-gray-900 mb-1">Official</div>
        <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Digital Warranty</div>
    </div>

    <div class="bg-white rounded-2xl border border-gray-200 p-6 text-center shadow-sm hover:shadow-md transition">
        <div class="w-12 h-12 mx-auto mb-3 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
            <i data-lucide="users" class="w-6 h-6"></i>
        </div>
        <div class="text-3xl font-black text-gray-900 mb-1">5+ Yrs</div>
        <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Trusted Experience</div>
    </div>
</section>

<!-- Our Story & Mission Section -->
<section class="grid grid-cols-1 lg:grid-cols-2 gap-10 items-center mb-16">
    <div>
        <div class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-brand-red mb-3">
            <i data-lucide="compass" class="w-4 h-4"></i>
            Our Journey &amp; Mission
        </div>
        <h2 class="text-2xl sm:text-3xl font-bold text-gray-900 mb-5 leading-tight">
            Built on Honesty, Technical Expertise, and Customer Loyalty
        </h2>
        <div class="space-y-4 text-gray-600 text-sm sm:text-base leading-relaxed">
            <p>
                Founded to serve mobile users along the busy Galle - Colombo highway corridor, <strong class="text-gray-900">Thirasara Max Mobile</strong> was established with a clear goal: to provide an honest, reliable store where customers never have to second-guess the authenticity of what they buy.
            </p>
            <p>
                From premium fast chargers, protective tempered glass, and high-fidelity audio equipment to intricate chip-level smartphone repairs, our team takes pride in every customer interaction.
            </p>
            <p>
                With modern digital tools such as online repair status tracking and digital warranty validation, we bring city-class technology services directly to the Wadduwa community.
            </p>
        </div>

        <div class="mt-6 flex flex-wrap gap-4 pt-4 border-t border-gray-100">
            <div class="flex items-center gap-2 text-sm text-gray-700 font-semibold">
                <i data-lucide="check-circle" class="w-5 h-5 text-emerald-500 flex-shrink-0"></i>
                Original Parts Only
            </div>
            <div class="flex items-center gap-2 text-sm text-gray-700 font-semibold">
                <i data-lucide="check-circle" class="w-5 h-5 text-emerald-500 flex-shrink-0"></i>
                Transparent Estimates
            </div>
            <div class="flex items-center gap-2 text-sm text-gray-700 font-semibold">
                <i data-lucide="check-circle" class="w-5 h-5 text-emerald-500 flex-shrink-0"></i>
                Certified Technicians
            </div>
        </div>
    </div>

    <!-- Right Feature Visual Card -->
    <div class="bg-gradient-to-br from-brand-dark to-gray-900 rounded-3xl p-8 sm:p-10 text-white border border-brand-border shadow-lg relative overflow-hidden">
        <div class="absolute top-0 right-0 w-64 h-64 bg-brand-red/10 rounded-full blur-3xl pointer-events-none"></div>

        <h3 class="text-xl font-bold text-white mb-6 flex items-center gap-3">
            <span class="w-10 h-10 rounded-xl bg-brand-red/20 text-brand-red flex items-center justify-center flex-shrink-0">
                <i data-lucide="cpu" class="w-5 h-5"></i>
            </span>
            What Sets Our Repair Lab Apart
        </h3>

        <div class="space-y-5">
            <div class="flex items-start gap-4">
                <div class="w-8 h-8 rounded-lg bg-white/10 text-brand-red flex items-center justify-center flex-shrink-0 mt-0.5 font-bold text-xs">
                    01
                </div>
                <div>
                    <h4 class="text-sm font-bold text-white mb-1">State-of-the-Art Diagnostics</h4>
                    <p class="text-xs text-gray-400 leading-relaxed">
                        We use professional micro-soldering gear and diagnostic meters to pinpoint motherboard and power faults accurately.
                    </p>
                </div>
            </div>

            <div class="flex items-start gap-4">
                <div class="w-8 h-8 rounded-lg bg-white/10 text-brand-red flex items-center justify-center flex-shrink-0 mt-0.5 font-bold text-xs">
                    02
                </div>
                <div>
                    <h4 class="text-sm font-bold text-white mb-1">Express Turnaround Times</h4>
                    <p class="text-xs text-gray-400 leading-relaxed">
                        Standard screen replacements and battery swaps are completed rapidly with genuine adhesive sealing and post-repair tests.
                    </p>
                </div>
            </div>

            <div class="flex items-start gap-4">
                <div class="w-8 h-8 rounded-lg bg-white/10 text-brand-red flex items-center justify-center flex-shrink-0 mt-0.5 font-bold text-xs">
                    03
                </div>
                <div>
                    <h4 class="text-sm font-bold text-white mb-1">Real-Time Online Tracking</h4>
                    <p class="text-xs text-gray-400 leading-relaxed">
                        Check your device's repair progress anytime with our live tracking tool using your tracking reference code.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Core Services Grid -->
<section class="mb-16">
    <div class="text-center max-w-2xl mx-auto mb-10">
        <span class="text-xs font-bold uppercase tracking-wider text-brand-red">Comprehensive Services</span>
        <h2 class="text-2xl sm:text-3xl font-bold text-gray-900 mt-2 mb-3">Everything Your Smartphone Needs</h2>
        <p class="text-gray-500 text-sm">From retail accessories to motherboard level restoration, we have you covered.</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Service 1 -->
        <div class="bg-white rounded-2xl border border-gray-200 p-7 shadow-sm hover:border-brand-red/50 hover:shadow-md transition">
            <div class="w-12 h-12 rounded-xl bg-red-50 text-brand-red flex items-center justify-center mb-5">
                <i data-lucide="headphones" class="w-6 h-6"></i>
            </div>
            <h3 class="text-lg font-bold text-gray-900 mb-2">Original Accessories</h3>
            <p class="text-sm text-gray-500 leading-relaxed mb-4">
                Cables, PD fast chargers, car adapters, TWS earbuds, smartwatches, and military-grade shockproof cases from world-class brands.
            </p>
            <a href="<?= APP_URL ?>/products.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-brand-red hover:text-brand-redHover transition">
                Browse Catalog <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
            </a>
        </div>

        <!-- Service 2 -->
        <div class="bg-white rounded-2xl border border-gray-200 p-7 shadow-sm hover:border-brand-red/50 hover:shadow-md transition">
            <div class="w-12 h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center mb-5">
                <i data-lucide="smartphone" class="w-6 h-6"></i>
            </div>
            <h3 class="text-lg font-bold text-gray-900 mb-2">Display &amp; Battery Replacements</h3>
            <p class="text-sm text-gray-500 leading-relaxed mb-4">
                Cracked glass or draining battery? We install original-grade OLED/LCD panels and safety-tested high-capacity batteries.
            </p>
            <a href="<?= APP_URL ?>/contact.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-brand-red hover:text-brand-redHover transition">
                Get an Estimate <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
            </a>
        </div>

        <!-- Service 3 -->
        <div class="bg-white rounded-2xl border border-gray-200 p-7 shadow-sm hover:border-brand-red/50 hover:shadow-md transition">
            <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center mb-5">
                <i data-lucide="file-check" class="w-6 h-6"></i>
            </div>
            <h3 class="text-lg font-bold text-gray-900 mb-2">Digital Warranty Validation</h3>
            <p class="text-sm text-gray-500 leading-relaxed mb-4">
                No more lost paper receipts. Every item and repair backed by our warranty can be verified digitally right on our website.
            </p>
            <a href="<?= APP_URL ?>/warranty-check.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-brand-red hover:text-brand-redHover transition">
                Check Warranty <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
            </a>
        </div>
    </div>
</section>

<!-- Store Location & Contact Card -->
<section class="bg-white rounded-3xl border border-gray-200 p-8 sm:p-12 mb-16 shadow-sm">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-10 items-center">
        <div>
            <span class="text-xs font-bold uppercase tracking-wider text-brand-red">Visit Our Showroom</span>
            <h2 class="text-2xl sm:text-3xl font-bold text-gray-900 mt-2 mb-4">Conveniently Located in Wadduwa</h2>
            <p class="text-gray-600 text-sm leading-relaxed mb-6">
                Stop by our store on Galle - Colombo Road for immediate service, product demos, or direct consultations with our hardware technicians.
            </p>

            <div class="space-y-4">
                <div class="flex items-start gap-3.5">
                    <div class="w-9 h-9 rounded-xl bg-red-50 text-brand-red flex items-center justify-center flex-shrink-0 mt-0.5">
                        <i data-lucide="map-pin" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <div class="text-xs font-bold text-gray-400 uppercase tracking-wider">Physical Address</div>
                        <div class="text-sm font-semibold text-gray-800">Galle - Colombo Rd, Wadduwa, Sri Lanka</div>
                    </div>
                </div>

                <div class="flex items-start gap-3.5">
                    <div class="w-9 h-9 rounded-xl bg-red-50 text-brand-red flex items-center justify-center flex-shrink-0 mt-0.5">
                        <i data-lucide="phone" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <div class="text-xs font-bold text-gray-400 uppercase tracking-wider">Customer Hotline</div>
                        <div class="text-sm font-semibold text-gray-800">
                            <a href="tel:0771234567" class="hover:text-brand-red transition">077 123 4567</a>
                        </div>
                    </div>
                </div>

                <div class="flex items-start gap-3.5">
                    <div class="w-9 h-9 rounded-xl bg-red-50 text-brand-red flex items-center justify-center flex-shrink-0 mt-0.5">
                        <i data-lucide="mail" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <div class="text-xs font-bold text-gray-400 uppercase tracking-wider">Direct Email</div>
                        <div class="text-sm font-semibold text-gray-800">
                            <a href="mailto:info@thirasaramax.lk" class="hover:text-brand-red transition">info@thirasaramax.lk</a>
                        </div>
                    </div>
                </div>

                <div class="flex items-start gap-3.5">
                    <div class="w-9 h-9 rounded-xl bg-red-50 text-brand-red flex items-center justify-center flex-shrink-0 mt-0.5">
                        <i data-lucide="clock" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <div class="text-xs font-bold text-gray-400 uppercase tracking-wider">Business Hours</div>
                        <div class="text-sm font-semibold text-gray-800">
                            Monday &ndash; Saturday: 9:00 AM &ndash; 8:00 PM<br>
                            Sunday: 9:00 AM &ndash; 5:00 PM
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Location Badge / Map visual -->
        <div class="bg-gray-50 rounded-2xl border border-gray-200 p-8 text-center flex flex-col items-center justify-center min-h-[300px]">
            <div class="w-16 h-16 rounded-full bg-brand-red/10 text-brand-red flex items-center justify-center mb-4">
                <i data-lucide="navigation" class="w-8 h-8"></i>
            </div>
            <h3 class="text-lg font-bold text-gray-900 mb-2">Thirasara Max Mobile</h3>
            <p class="text-xs text-gray-500 max-w-sm mb-6 leading-relaxed">
                Direct access on Galle - Colombo Road, Wadduwa. Ample parking space available in front of the store.
            </p>
            <a href="https://maps.google.com/?q=Wadduwa+Galle+Colombo+Road"
               target="_blank"
               rel="noopener noreferrer"
               class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-brand-red hover:bg-brand-redHover text-white font-semibold text-xs transition shadow-sm">
                <i data-lucide="external-link" class="w-4 h-4"></i>
                Open Directions in Google Maps
            </a>
        </div>
    </div>
</section>

<!-- Call to Action Banner -->
<section class="rounded-3xl bg-gradient-to-r from-brand-red to-red-700 text-white p-8 sm:p-12 text-center shadow-xl mb-12">
    <h2 class="text-2xl sm:text-3xl font-black mb-3">Looking for Genuine Mobile Gear?</h2>
    <p class="text-white/80 text-sm sm:text-base max-w-xl mx-auto mb-8 leading-relaxed">
        Browse our verified inventory or drop us a message today. Quality service and transparent warranties guaranteed.
    </p>
    <div class="flex flex-wrap items-center justify-center gap-4">
        <a href="<?= APP_URL ?>/products.php"
           class="inline-flex items-center gap-2 px-7 py-3.5 rounded-xl bg-white text-brand-red font-bold text-sm hover:bg-gray-100 transition shadow-lg">
            <i data-lucide="shopping-bag" class="w-4 h-4"></i>
            Explore Products
        </a>
        <a href="<?= APP_URL ?>/contact.php"
           class="inline-flex items-center gap-2 px-7 py-3.5 rounded-xl bg-black/20 hover:bg-black/30 text-white border border-white/20 font-semibold text-sm transition">
            <i data-lucide="message-square" class="w-4 h-4"></i>
            Send an Inquiry
        </a>
    </div>
</section>

<script>
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
