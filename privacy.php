<?php

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = 'Privacy Policy';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-4xl mx-auto bg-white rounded-3xl border border-gray-200 p-8 sm:p-12 shadow-sm mb-16">
    <div class="border-b border-gray-100 pb-6 mb-8">
        <span class="text-xs font-bold uppercase tracking-wider text-brand-red">Legal &amp; Privacy</span>
        <h1 class="text-3xl font-extrabold text-gray-900 mt-2 mb-2">Privacy Policy</h1>
        <p class="text-xs text-gray-500">Last updated: <?= date('F d, Y') ?></p>
    </div>

    <div class="space-y-6 text-sm text-gray-600 leading-relaxed">
        <div>
            <h2 class="text-base font-bold text-gray-900 mb-2">1. Overview</h2>
            <p>
                Thirasara Max Mobile ("we", "our", or "us"), located along Galle - Colombo Rd, Wadduwa, is committed to safeguarding your privacy. This policy explains how we collect, store, and utilize your personal information when you visit our website, purchase mobile goods, or book repair services.
            </p>
        </div>

        <div>
            <h2 class="text-base font-bold text-gray-900 mb-2">2. Information We Collect</h2>
            <p>When you interact with our platform, we may collect:</p>
            <ul class="list-disc pl-5 mt-2 space-y-1">
                <li>Personal identifiers: Full name, email address, contact phone number, and physical delivery address.</li>
                <li>Device details: Smartphone brand, model, and IMEI/serial numbers provided for repair or warranty registration.</li>
                <li>Transaction records: Order numbers, items purchased, payment method (no sensitive card details are stored directly on our servers).</li>
            </ul>
        </div>

        <div>
            <h2 class="text-base font-bold text-gray-900 mb-2">3. How We Use Your Information</h2>
            <p>Your details are used exclusively to:</p>
            <ul class="list-disc pl-5 mt-2 space-y-1">
                <li>Process and deliver your retail orders.</li>
                <li>Notify you of repair updates and delivery milestones via email or phone.</li>
                <li>Verify and maintain your digital warranty claims.</li>
                <li>Respond to customer service and technical inquiries.</li>
            </ul>
        </div>

        <div>
            <h2 class="text-base font-bold text-gray-900 mb-2">4. Data Security</h2>
            <p>
                We employ industry-standard encryption, password hashing, and secure server environments to ensure your data is safeguarded against unauthorized access, alteration, or disclosure.
            </p>
        </div>

        <div>
            <h2 class="text-base font-bold text-gray-900 mb-2">5. Contact Us Regarding Privacy</h2>
            <p>
                If you have questions about your personal data or wish to request data updates, please contact us at <a href="mailto:info@thirasaramax.lk" class="text-brand-red font-semibold hover:underline">info@thirasaramax.lk</a> or visit our store in Wadduwa.
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
