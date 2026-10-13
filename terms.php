<?php

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = 'Terms & Conditions';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-4xl mx-auto bg-white rounded-3xl border border-gray-200 p-8 sm:p-12 shadow-sm mb-16">
    <div class="border-b border-gray-100 pb-6 mb-8">
        <span class="text-xs font-bold uppercase tracking-wider text-brand-red">Legal &amp; Policy</span>
        <h1 class="text-3xl font-extrabold text-gray-900 mt-2 mb-2">Terms and Conditions</h1>
        <p class="text-xs text-gray-500">Last updated: <?= date('F d, Y') ?></p>
    </div>

    <div class="space-y-6 text-sm text-gray-600 leading-relaxed">
        <div>
            <h2 class="text-base font-bold text-gray-900 mb-2">1. Agreement to Terms</h2>
            <p>
                By accessing or purchasing from Thirasara Max Mobile (website or in-store showroom at Galle - Colombo Rd, Wadduwa), you agree to be bound by these Terms and Conditions.
            </p>
        </div>

        <div>
            <h2 class="text-base font-bold text-gray-900 mb-2">2. Products &amp; Pricing</h2>
            <p>
                All prices displayed are in Sri Lankan Rupees (LKR) and include applicable local taxes unless stated otherwise. We reserve the right to correct any typographical pricing errors or adjust pricing according to distributor fluctuations.
            </p>
        </div>

        <div>
            <h2 class="text-base font-bold text-gray-900 mb-2">3. Device Repairs &amp; Diagnostics</h2>
            <ul class="list-disc pl-5 mt-2 space-y-1">
                <li>Customers are advised to back up all personal device data prior to repair. While utmost care is taken, Thirasara Max Mobile is not liable for data loss occurring during chip-level motherboard or operating system servicing.</li>
                <li>Replaced defective parts become company scrap unless specifically requested by the customer upon handover.</li>
                <li>Devices uncollected after 60 days following notification of completed repair may be subject to storage fees.</li>
            </ul>
        </div>

        <div>
            <h2 class="text-base font-bold text-gray-900 mb-2">4. Digital Warranty Terms</h2>
            <p>
                Warranties cover manufacturing defects and verified technician labor. Warranties are rendered void in instances of physical drop damage, water/liquid intrusion, uncertified tampering, or broken warranty seals.
            </p>
        </div>

        <div>
            <h2 class="text-base font-bold text-gray-900 mb-2">5. Returns and Cancellations</h2>
            <p>
                Retail orders may be cancelled prior to dispatch. For return claims on defective accessories, items must be returned within 7 days in their original packaging with the invoice document.
            </p>
        </div>
    </div>
</div>

<script>
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
