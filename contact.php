<?php

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUser = getCurrentUser();
$pdo = Database::getConnection();

$nameValue = $currentUser['name'] ?? '';
$emailValue = $currentUser['email'] ?? '';
$subjectValue = '';
$messageValue = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = sanitize($_POST['name'] ?? '');
    $email   = sanitize($_POST['email'] ?? '');
    $subject = sanitize($_POST['subject'] ?? '');
    $message = sanitize($_POST['message'] ?? '');

    $nameValue    = $name;
    $emailValue   = $email;
    $subjectValue = $subject;
    $messageValue = $message;

    if (empty($name) || empty($email) || empty($subject) || empty($message)) {
        setFlash('error', 'Please fill in all fields before submitting.');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        setFlash('error', 'Please provide a valid email address.');
    } elseif (strlen($message) < 10) {
        setFlash('error', 'Your inquiry message must be at least 10 characters long.');
    } else {
        try {
            $userId = $currentUser ? (int)$currentUser['id'] : null;

            $stmt = $pdo->prepare("
                INSERT INTO inquiries (user_id, name, email, subject, message, status, created_at)
                VALUES (?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $stmt->execute([$userId, $name, $email, $subject, $message]);

            setFlash('success', 'Thank you! Your message has been sent successfully. Our team will contact you shortly.');

            // Clear form values after successful submission
            $subjectValue = '';
            $messageValue = '';
            if (!$currentUser) {
                $nameValue = '';
                $emailValue = '';
            }
        } catch (PDOException $e) {
            error_log('Contact/Inquiry submission error: ' . $e->getMessage());
            setFlash('error', 'Sorry, an error occurred while saving your message. Please try again.');
        }
    }
}

$pageTitle = 'Contact Us & Inquiries';
require_once __DIR__ . '/includes/header.php';
?>

<!-- PAGE HEADER (High-Contrast Style) -->
<section class="bg-gradient-to-r from-gray-900 via-zinc-900 to-gray-900 text-white border-b-4 border-brand-red py-12 mb-10 rounded-3xl overflow-hidden">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center max-w-2xl">
        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded bg-brand-red text-white text-[10px] font-bold uppercase tracking-wider mb-3 shadow-sm">
            <i data-lucide="message-square" class="w-3.5 h-3.5"></i>
            Get in Touch
        </span>
        <h1 class="text-3xl sm:text-4xl font-black uppercase tracking-tight text-white">
            We're Here to Help
        </h1>
        <p class="text-gray-300 text-xs sm:text-sm mt-2 leading-relaxed">
            Have questions about a smartphone, accessory availability, or need a repair quote? Reach out or visit our Wadduwa branch.
        </p>
    </div>
</section>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 mb-16">

        <!-- Contact Info Cards (Left Side) -->
        <div class="lg:col-span-5 space-y-4">
            <!-- Address Card -->
            <div class="bg-white rounded-xl border-2 border-gray-200 p-5 shadow-sm flex items-start gap-4">
                <div class="w-10 h-10 rounded-lg bg-red-100 text-brand-red flex items-center justify-center flex-shrink-0">
                    <i data-lucide="map-pin" class="w-5 h-5"></i>
                </div>
                <div>
                    <h3 class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Our Location</h3>
                    <div class="text-sm font-extrabold text-gray-900 mt-0.5">Thirasara Max Mobile</div>
                    <p class="text-xs text-gray-600 mt-1 leading-relaxed">
                        Galle - Colombo Rd, Wadduwa,<br>
                        Western Province, Sri Lanka
                    </p>
                </div>
            </div>

            <!-- Phone / Hotline Card -->
            <div class="bg-white rounded-xl border-2 border-gray-200 p-5 shadow-sm flex items-start gap-4">
                <div class="w-10 h-10 rounded-lg bg-blue-100 text-blue-700 flex items-center justify-center flex-shrink-0">
                    <i data-lucide="phone-call" class="w-5 h-5"></i>
                </div>
                <div>
                    <h3 class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Customer Hotline</h3>
                    <div class="text-sm font-extrabold text-gray-900 mt-0.5">
                        <a href="tel:0771234567" class="hover:text-brand-red transition">077 123 4567</a>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">
                        Call or WhatsApp for immediate stock availability &amp; repair queries.
                    </p>
                </div>
            </div>

            <!-- Email Card -->
            <div class="bg-white rounded-xl border-2 border-gray-200 p-5 shadow-sm flex items-start gap-4">
                <div class="w-10 h-10 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center flex-shrink-0">
                    <i data-lucide="mail" class="w-5 h-5"></i>
                </div>
                <div>
                    <h3 class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Email Inquiries</h3>
                    <div class="text-sm font-extrabold text-gray-900 mt-0.5">
                        <a href="mailto:info@thirasaramax.lk" class="hover:text-brand-red transition">info@thirasaramax.lk</a>
                    </div>
                    <div class="text-xs text-gray-500 mt-0.5">
                        Support: <a href="mailto:support@thirasaramax.lk" class="text-gray-700 font-semibold hover:underline">support@thirasaramax.lk</a>
                    </div>
                </div>
            </div>

            <!-- Working Hours Card -->
            <div class="bg-white rounded-xl border-2 border-gray-200 p-5 shadow-sm flex items-start gap-4">
                <div class="w-10 h-10 rounded-lg bg-amber-100 text-amber-700 flex items-center justify-center flex-shrink-0">
                    <i data-lucide="clock" class="w-5 h-5"></i>
                </div>
                <div>
                    <h3 class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Store Hours</h3>
                    <div class="text-xs font-semibold text-gray-800 mt-1 space-y-1">
                        <div class="flex justify-between gap-6">
                            <span>Mon &ndash; Sat:</span>
                            <span class="text-gray-900 font-extrabold">9:00 AM &ndash; 8:00 PM</span>
                        </div>
                        <div class="flex justify-between gap-6">
                            <span>Sunday:</span>
                            <span class="text-gray-900 font-extrabold">9:00 AM &ndash; 5:00 PM</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Contact & Inquiry Form (Right Side) -->
        <div class="lg:col-span-7">
            <div class="bg-white rounded-xl border-2 border-gray-200 p-6 sm:p-8 shadow-sm">
                <h2 class="text-lg font-black uppercase tracking-tight text-gray-900 mb-1">Send Us a Message</h2>
                <p class="text-xs text-gray-500 mb-5">
                    Fill in your details below and our service team will respond via email or phone.
                </p>

                <form action="<?= APP_URL ?>/contact.php" method="POST" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="name" class="block text-[10px] font-bold uppercase tracking-wider text-gray-700 mb-1">
                                Your Full Name <span class="text-brand-red">*</span>
                            </label>
                            <input type="text"
                                name="name"
                                id="name"
                                required
                                placeholder="e.g. Kasun Perera"
                                value="<?= htmlspecialchars($nameValue) ?>"
                                class="w-full px-3 py-2.5 rounded-lg border border-gray-300 text-xs sm:text-sm text-gray-900 bg-gray-50 focus:bg-white focus:outline-none focus:border-brand-red transition">
                        </div>

                        <div>
                            <label for="email" class="block text-[10px] font-bold uppercase tracking-wider text-gray-700 mb-1">
                                Email Address <span class="text-brand-red">*</span>
                            </label>
                            <input type="email"
                                name="email"
                                id="email"
                                required
                                placeholder="e.g. kasun@example.com"
                                value="<?= htmlspecialchars($emailValue) ?>"
                                class="w-full px-3 py-2.5 rounded-lg border border-gray-300 text-xs sm:text-sm text-gray-900 bg-gray-50 focus:bg-white focus:outline-none focus:border-brand-red transition">
                        </div>
                    </div>

                    <div>
                        <label for="subject" class="block text-[10px] font-bold uppercase tracking-wider text-gray-700 mb-1">
                            Subject / Topic <span class="text-brand-red">*</span>
                        </label>
                        <input type="text"
                            name="subject"
                            id="subject"
                            required
                            placeholder="e.g. Inquiry regarding iPhone 13 Screen Replacement"
                            value="<?= htmlspecialchars($subjectValue) ?>"
                            class="w-full px-3 py-2.5 rounded-lg border border-gray-300 text-xs sm:text-sm text-gray-900 bg-gray-50 focus:bg-white focus:outline-none focus:border-brand-red transition">
                    </div>

                    <div>
                        <label for="message" class="block text-[10px] font-bold uppercase tracking-wider text-gray-700 mb-1">
                            Your Message <span class="text-brand-red">*</span>
                        </label>
                        <textarea name="message"
                            id="message"
                            rows="5"
                            required
                            placeholder="Please describe your question, device issue, or product inquiry in detail..."
                            class="w-full px-3 py-2.5 rounded-lg border border-gray-300 text-xs sm:text-sm text-gray-900 bg-gray-50 focus:bg-white focus:outline-none focus:border-brand-red transition leading-relaxed"><?= htmlspecialchars($messageValue) ?></textarea>
                    </div>

                    <div>
                        <button type="submit"
                            class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-brand-red hover:bg-brand-redHover text-white font-bold text-xs sm:text-sm transition shadow-sm">
                            <i data-lucide="send" class="w-4 h-4"></i>
                            Submit Inquiry
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>

<script>
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>