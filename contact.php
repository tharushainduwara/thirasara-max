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

<!-- Page Header Banner -->
<div class="mb-10 text-center max-w-2xl mx-auto">
    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-brand-red/10 border border-brand-red/20 text-brand-red text-xs font-bold uppercase tracking-wider mb-3">
        <i data-lucide="message-square" class="w-3.5 h-3.5"></i>
        Get in Touch
    </span>
    <h1 class="text-3xl sm:text-4xl font-extrabold text-gray-900 tracking-tight">
        We're Here to Help
    </h1>
    <p class="text-gray-500 text-sm sm:text-base mt-2 leading-relaxed">
        Have questions about a smartphone, accessory availability, or need a repair quote? Reach out or visit our Wadduwa branch.
    </p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-8 mb-16">
    <!-- Contact Info Cards (Left Side) -->
    <div class="lg:col-span-5 space-y-4">
        <!-- Address Card -->
        <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm flex items-start gap-4">
            <div class="w-12 h-12 rounded-xl bg-red-50 text-brand-red flex items-center justify-center flex-shrink-0">
                <i data-lucide="map-pin" class="w-6 h-6"></i>
            </div>
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400">Our Location</h3>
                <div class="text-base font-bold text-gray-900 mt-0.5">Thirasara Max Mobile</div>
                <p class="text-sm text-gray-600 mt-1 leading-relaxed">
                    Galle - Colombo Rd, Wadduwa,<br>
                    Western Province, Sri Lanka
                </p>
            </div>
        </div>

        <!-- Phone / Hotline Card -->
        <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm flex items-start gap-4">
            <div class="w-12 h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center flex-shrink-0">
                <i data-lucide="phone-call" class="w-6 h-6"></i>
            </div>
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400">Customer Hotline</h3>
                <div class="text-base font-bold text-gray-900 mt-0.5">
                    <a href="tel:0771234567" class="hover:text-brand-red transition">077 123 4567</a>
                </div>
                <p class="text-xs text-gray-500 mt-1">
                    Call or WhatsApp for immediate stock availability &amp; repair queries.
                </p>
            </div>
        </div>

        <!-- Email Card -->
        <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm flex items-start gap-4">
            <div class="w-12 h-12 rounded-xl bg-green-50 text-green-600 flex items-center justify-center flex-shrink-0">
                <i data-lucide="mail" class="w-6 h-6"></i>
            </div>
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400">Email Inquiries</h3>
                <div class="text-base font-bold text-gray-900 mt-0.5">
                    <a href="mailto:info@thirasaramax.lk" class="hover:text-brand-red transition">info@thirasaramax.lk</a>
                </div>
                <div class="text-xs text-gray-500 mt-0.5">
                    Support: <a href="mailto:support@thirasaramax.lk" class="text-gray-700 hover:underline">support@thirasaramax.lk</a>
                </div>
            </div>
        </div>

        <!-- Working Hours Card -->
        <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm flex items-start gap-4">
            <div class="w-12 h-12 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center flex-shrink-0">
                <i data-lucide="clock" class="w-6 h-6"></i>
            </div>
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400">Store Hours</h3>
                <div class="text-sm font-semibold text-gray-800 mt-1">
                    <div class="flex justify-between gap-4">
                        <span>Mon &ndash; Sat:</span>
                        <span class="text-gray-900 font-bold">9:00 AM &ndash; 8:00 PM</span>
                    </div>
                    <div class="flex justify-between gap-4 mt-0.5">
                        <span>Sunday:</span>
                        <span class="text-gray-900 font-bold">9:00 AM &ndash; 5:00 PM</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Contact & Inquiry Form (Right Side) -->
    <div class="lg:col-span-7">
        <div class="bg-white rounded-3xl border border-gray-200 p-8 sm:p-10 shadow-sm">
            <h2 class="text-xl font-bold text-gray-900 mb-2">Send Us a Message</h2>
            <p class="text-xs text-gray-500 mb-6">
                Fill in your details below and our service team will respond via email or phone.
            </p>

            <form action="<?= APP_URL ?>/contact.php" method="POST" class="space-y-5">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label for="name" class="block text-xs font-bold uppercase tracking-wider text-gray-700 mb-1.5">
                            Your Full Name <span class="text-brand-red">*</span>
                        </label>
                        <input type="text"
                               name="name"
                               id="name"
                               required
                               placeholder="e.g. Kasun Perera"
                               value="<?= htmlspecialchars($nameValue) ?>"
                               class="w-full px-4 py-3 rounded-xl border border-gray-300 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-brand-red focus:border-transparent transition">
                    </div>

                    <div>
                        <label for="email" class="block text-xs font-bold uppercase tracking-wider text-gray-700 mb-1.5">
                            Email Address <span class="text-brand-red">*</span>
                        </label>
                        <input type="email"
                               name="email"
                               id="email"
                               required
                               placeholder="e.g. kasun@example.com"
                               value="<?= htmlspecialchars($emailValue) ?>"
                               class="w-full px-4 py-3 rounded-xl border border-gray-300 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-brand-red focus:border-transparent transition">
                    </div>
                </div>

                <div>
                    <label for="subject" class="block text-xs font-bold uppercase tracking-wider text-gray-700 mb-1.5">
                        Subject / Topic <span class="text-brand-red">*</span>
                    </label>
                    <input type="text"
                           name="subject"
                           id="subject"
                           required
                           placeholder="e.g. Inquiry regarding iPhone 13 Screen Replacement"
                           value="<?= htmlspecialchars($subjectValue) ?>"
                           class="w-full px-4 py-3 rounded-xl border border-gray-300 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-brand-red focus:border-transparent transition">
                </div>

                <div>
                    <label for="message" class="block text-xs font-bold uppercase tracking-wider text-gray-700 mb-1.5">
                        Your Message <span class="text-brand-red">*</span>
                    </label>
                    <textarea name="message"
                              id="message"
                              rows="5"
                              required
                              placeholder="Please describe your question, device issue, or product inquiry in detail..."
                              class="w-full px-4 py-3 rounded-xl border border-gray-300 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-brand-red focus:border-transparent transition leading-relaxed"><?= htmlspecialchars($messageValue) ?></textarea>
                </div>

                <div>
                    <button type="submit"
                            class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-8 py-3.5 rounded-xl bg-brand-red hover:bg-brand-redHover text-white font-bold text-sm transition shadow-md shadow-brand-red/20">
                        <i data-lucide="send" class="w-4 h-4"></i>
                        Submit Inquiry
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
