<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUser = getCurrentUser();
requireAuth(['customer', 'staff', 'admin']);
$uid = (int) $currentUser['id'];
$pdo = Database::getConnection();

// Handle New Inquiry Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_inquiry') {
    $subject = sanitize($_POST['subject'] ?? '');
    $message = sanitize($_POST['message'] ?? '');

    if (empty($subject) || empty($message)) {
        setFlash('error', 'Please fill in both the subject and your question message.');
    } elseif (strlen($message) < 10) {
        setFlash('error', 'Your message must be at least 10 characters long.');
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO inquiries (user_id, name, email, subject, message, status, created_at)
                VALUES (?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $stmt->execute([
                $uid,
                $currentUser['name'] ?? 'Customer',
                $currentUser['email'] ?? '',
                $subject,
                $message
            ]);

            setFlash('success', 'Your inquiry has been submitted! Our support team will review and reply promptly.');
            header('Location: ' . APP_URL . '/customer/inquiries.php');
            exit;
        } catch (Throwable $e) {
            error_log('Customer inquiry submit error: ' . $e->getMessage());
            setFlash('error', 'An error occurred while submitting your inquiry. Please try again.');
        }
    }
}

// Fetch Customer Inquiries
$userEmail = $currentUser['email'] ?? '';
$stmt = $pdo->prepare("
    SELECT * 
    FROM inquiries 
    WHERE user_id = ? OR email = ?
    ORDER BY created_at DESC
");
$stmt->execute([$uid, $userEmail]);
$inquiries = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalInquiries = count($inquiries);
$pendingCount = count(array_filter($inquiries, fn($i) => $i['status'] === 'pending'));
$resolvedCount = count(array_filter($inquiries, fn($i) => $i['status'] === 'resolved'));

$pageTitle = 'My Inquiries & Support';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-10">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Breadcrumbs -->
        <nav class="flex items-center gap-2 text-xs text-gray-500 mb-6">
            <a href="<?php echo APP_URL; ?>/customer/dashboard.php" class="hover:text-gray-900 transition">Dashboard</a>
            <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-gray-400"></i>
            <span class="text-gray-900 font-bold">Customer Inquiries</span>
        </nav>

        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
            <div>
                <div class="flex items-center gap-2.5">
                    <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-red-100 text-brand-red font-bold">
                        <i data-lucide="message-square" class="w-5 h-5"></i>
                    </span>
                    <div>
                        <h1 class="text-2xl sm:text-3xl font-black text-gray-900 uppercase tracking-tight">Support Inquiries</h1>
                        <p class="text-xs text-gray-500 mt-0.5">Track your questions, accessory requests, and repair guidance.</p>
                    </div>
                </div>
            </div>

            <!-- Submit New Inquiry Button -->
            <button type="button" onclick="openInquiryModal()"
                    class="inline-flex items-center gap-2 px-5 py-2.5 bg-brand-red text-white text-xs font-bold uppercase tracking-wider rounded-xl hover:bg-red-700 transition shadow-sm self-start sm:self-auto">
                <i data-lucide="plus-circle" class="w-4 h-4"></i>
                Ask a Question
            </button>
        </div>

        <!-- Metric KPI Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-8">
            <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm flex items-center justify-between">
                <div>
                    <span class="text-[11px] font-bold uppercase tracking-wider text-gray-400">Total Questions</span>
                    <p class="text-2xl font-black text-gray-900 mt-1"><?php echo $totalInquiries; ?></p>
                </div>
                <div class="w-11 h-11 rounded-xl bg-gray-100 text-gray-600 flex items-center justify-center">
                    <i data-lucide="message-circle" class="w-5 h-5"></i>
                </div>
            </div>

            <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm flex items-center justify-between">
                <div>
                    <span class="text-[11px] font-bold uppercase tracking-wider text-gray-400">Awaiting Reply</span>
                    <p class="text-2xl font-black text-amber-600 mt-1"><?php echo $pendingCount; ?></p>
                </div>
                <div class="w-11 h-11 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                    <i data-lucide="clock" class="w-5 h-5"></i>
                </div>
            </div>

            <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm flex items-center justify-between">
                <div>
                    <span class="text-[11px] font-bold uppercase tracking-wider text-gray-400">Answered</span>
                    <p class="text-2xl font-black text-emerald-600 mt-1"><?php echo $resolvedCount; ?></p>
                </div>
                <div class="w-11 h-11 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                    <i data-lucide="check-circle" class="w-5 h-5"></i>
                </div>
            </div>
        </div>

        <!-- Inquiries List -->
        <?php if (empty($inquiries)): ?>
            <div class="bg-white rounded-3xl border border-gray-200 p-12 text-center shadow-sm">
                <div class="w-16 h-16 rounded-2xl bg-red-50 text-brand-red flex items-center justify-center mx-auto mb-4">
                    <i data-lucide="message-square-plus" class="w-8 h-8"></i>
                </div>
                <h3 class="text-base font-extrabold text-gray-900">No Inquiries Submitted Yet</h3>
                <p class="text-xs text-gray-500 max-w-sm mx-auto mt-1 mb-6">
                    Have questions about device compatibility, phone parts, or need a repair quotation? Send us a message and our team will get back to you.
                </p>
                <button type="button" onclick="openInquiryModal()"
                        class="inline-flex items-center gap-2 px-5 py-2.5 bg-gray-900 text-white text-xs font-bold uppercase tracking-wider rounded-xl hover:bg-black transition shadow-sm">
                    <i data-lucide="send" class="w-4 h-4"></i>
                    Send Your First Message
                </button>
            </div>
        <?php else: ?>
            <div class="space-y-4">
                <?php foreach ($inquiries as $inq): ?>
                    <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm transition hover:shadow-md">
                        <!-- Top Meta -->
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-gray-100 pb-3 mb-4">
                            <div class="flex items-center gap-2">
                                <?php if ($inq['status'] === 'resolved'): ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-black bg-emerald-100 text-emerald-700">
                                        <i data-lucide="check-circle" class="w-3 h-3"></i> Answered
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-black bg-amber-100 text-amber-700">
                                        <i data-lucide="clock" class="w-3 h-3"></i> Under Review
                                    </span>
                                <?php endif; ?>
                                <span class="text-xs text-gray-400">
                                    Submitted <?php echo date('M d, Y \a\t h:i A', strtotime($inq['created_at'])); ?>
                                </span>
                            </div>
                        </div>

                        <!-- Inquiry Subject & Body -->
                        <h3 class="text-base font-black text-gray-900 mb-2">
                            <?php echo htmlspecialchars($inq['subject']); ?>
                        </h3>
                        <p class="text-xs text-gray-700 leading-relaxed whitespace-pre-wrap bg-gray-50 rounded-xl p-4 border border-gray-100">
                            <?php echo htmlspecialchars($inq['message']); ?>
                        </p>

                        <!-- Staff / Admin Response -->
                        <?php if (!empty($inq['reply'])): ?>
                            <div class="mt-4 bg-emerald-50/70 border border-emerald-200 rounded-xl p-4">
                                <div class="flex items-center justify-between gap-2 mb-2">
                                    <div class="flex items-center gap-1.5 text-emerald-800 font-bold text-xs">
                                        <i data-lucide="badge-check" class="w-4 h-4 text-emerald-600"></i>
                                        <span>Thirasara Max Support Response</span>
                                    </div>
                                    <span class="text-[10px] text-emerald-600">
                                        <?php echo date('M d, Y', strtotime($inq['updated_at'])); ?>
                                    </span>
                                </div>
                                <p class="text-xs text-emerald-950 whitespace-pre-wrap leading-relaxed font-medium">
                                    <?php echo htmlspecialchars($inq['reply']); ?>
                                </p>
                            </div>
                        <?php else: ?>
                            <div class="mt-4 flex items-center gap-2 text-xs text-amber-700 bg-amber-50/80 rounded-xl px-4 py-3 border border-amber-200">
                                <i data-lucide="info" class="w-4 h-4 flex-shrink-0 text-amber-600"></i>
                                <span>Our technical support team is currently reviewing your query. You will receive an email response shortly.</span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
</div>

<!-- Submit Inquiry Modal -->
<div id="inquiryModal" class="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl relative border border-gray-100 animate-in fade-in duration-200">
        <button type="button" onclick="closeInquiryModal()" class="absolute top-5 right-5 text-gray-400 hover:text-gray-600 transition">
            <i data-lucide="x" class="w-5 h-5"></i>
        </button>

        <div class="flex items-center gap-3 mb-5">
            <div class="w-10 h-10 rounded-xl bg-red-100 text-brand-red flex items-center justify-center font-bold">
                <i data-lucide="message-square-plus" class="w-5 h-5"></i>
            </div>
            <div>
                <h3 class="text-base font-black text-gray-900 uppercase tracking-tight">Ask Our Team</h3>
                <p class="text-xs text-gray-500">We usually respond within a few business hours.</p>
            </div>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="action" value="submit_inquiry">

            <div class="mb-4">
                <label for="subject" class="block text-xs font-bold uppercase tracking-wider text-gray-700 mb-1.5">
                    Subject / Topic *
                </label>
                <input type="text" name="subject" id="subject" required
                       placeholder="e.g., iPhone 13 Screen Replacement Inquiry"
                       class="w-full px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-xs focus:ring-2 focus:ring-brand-red focus:bg-white outline-none transition">
            </div>

            <div class="mb-5">
                <label for="message" class="block text-xs font-bold uppercase tracking-wider text-gray-700 mb-1.5">
                    Your Message / Question *
                </label>
                <textarea name="message" id="message" rows="4" required minlength="10"
                          placeholder="Describe your inquiry in detail. Include model names or specific issues if applicable..."
                          class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-xs focus:ring-2 focus:ring-brand-red focus:bg-white outline-none transition"></textarea>
                <p class="text-[11px] text-gray-400 mt-1">Minimum 10 characters.</p>
            </div>

            <div class="flex items-center justify-end gap-3 pt-2">
                <button type="button" onclick="closeInquiryModal()" class="px-5 py-2.5 text-xs font-bold text-gray-600 hover:text-gray-900 transition">
                    Cancel
                </button>
                <button type="submit" class="px-6 py-2.5 bg-brand-red text-white text-xs font-black uppercase tracking-wider rounded-xl hover:bg-red-700 transition shadow-md inline-flex items-center gap-2">
                    <i data-lucide="send" class="w-3.5 h-3.5"></i>
                    Submit Question
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openInquiryModal() {
    document.getElementById('inquiryModal').classList.remove('hidden');
    if (window.lucide) lucide.createIcons();
}

function closeInquiryModal() {
    document.getElementById('inquiryModal').classList.add('hidden');
}

// Close modal on Escape
window.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeInquiryModal();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
