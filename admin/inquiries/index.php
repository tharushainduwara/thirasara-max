<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/mailer.php';

requireAuth(['admin']);

$pdo = Database::getConnection();
$currentUser = getCurrentUser();

// Handle Actions (Reply, Delete, Toggle Status)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');
    $inquiryId = (int) ($_POST['inquiry_id'] ?? 0);

    if ($action === 'reply' && $inquiryId > 0) {
        $replyText = trim($_POST['reply_message'] ?? '');

        if (empty($replyText)) {
            setFlash('error', 'Reply message cannot be empty.');
        } else {
            try {
                // Fetch inquiry
                $stmt = $pdo->prepare("SELECT * FROM inquiries WHERE id = ?");
                $stmt->execute([$inquiryId]);
                $inquiry = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($inquiry) {
                    $updateStmt = $pdo->prepare("
                        UPDATE inquiries 
                        SET reply = ?, status = 'resolved', updated_at = NOW() 
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$replyText, $inquiryId]);

                    // Send email notification
                    $emailSent = false;
                    if (!empty($inquiry['email'])) {
                        $emailSent = sendInquiryReplyEmail(
                            $inquiry['email'],
                            $inquiry['name'],
                            $inquiry['subject'],
                            $inquiry['message'],
                            $replyText
                        );
                    }

                    // Create in-app notification if customer is registered
                    if (!empty($inquiry['user_id'])) {
                        $notifStmt = $pdo->prepare("
                            INSERT INTO notifications (user_id, title, message, type, is_read, email_sent, created_at)
                            VALUES (?, ?, ?, 'general', 0, ?, NOW())
                        ");
                        $notifTitle = "Reply to inquiry: " . mb_strimwidth($inquiry['subject'], 0, 40, '...');
                        $notifMsg = "We have replied to your inquiry: \"" . mb_strimwidth($replyText, 0, 120, '...') . "\"";
                        $notifStmt->execute([
                            $inquiry['user_id'],
                            $notifTitle,
                            $notifMsg,
                            $emailSent ? 1 : 0
                        ]);
                    }

                    setFlash('success', 'Reply submitted and customer notified successfully' . ($emailSent ? ' via email.' : '.'));
                } else {
                    setFlash('error', 'Inquiry not found.');
                }
            } catch (Throwable $e) {
                error_log('Admin inquiry reply error: ' . $e->getMessage());
                setFlash('error', 'An error occurred while saving your reply.');
            }
        }
        header('Location: ' . APP_URL . '/admin/inquiries/index.php');
        exit;
    }

    if ($action === 'delete' && $inquiryId > 0) {
        try {
            $stmt = $pdo->prepare("DELETE FROM inquiries WHERE id = ?");
            $stmt->execute([$inquiryId]);
            setFlash('success', 'Inquiry deleted successfully.');
        } catch (Throwable $e) {
            error_log('Admin inquiry delete error: ' . $e->getMessage());
            setFlash('error', 'Failed to delete inquiry.');
        }
        header('Location: ' . APP_URL . '/admin/inquiries/index.php');
        exit;
    }

    if ($action === 'toggle_status' && $inquiryId > 0) {
        try {
            $stmt = $pdo->prepare("SELECT status FROM inquiries WHERE id = ?");
            $stmt->execute([$inquiryId]);
            $currentStatus = $stmt->fetchColumn();

            $newStatus = ($currentStatus === 'resolved') ? 'pending' : 'resolved';
            $updateStmt = $pdo->prepare("UPDATE inquiries SET status = ?, updated_at = NOW() WHERE id = ?");
            $updateStmt->execute([$newStatus, $inquiryId]);

            setFlash('success', 'Inquiry marked as ' . ucfirst($newStatus) . '.');
        } catch (Throwable $e) {
            error_log('Admin inquiry toggle status error: ' . $e->getMessage());
            setFlash('error', 'Failed to update status.');
        }
        header('Location: ' . APP_URL . '/admin/inquiries/index.php');
        exit;
    }
}

// Filters & Search
$statusFilter = sanitize($_GET['status'] ?? 'all');
$search = trim($_GET['q'] ?? '');

$where = [];
$params = [];

if ($statusFilter === 'pending' || $statusFilter === 'resolved') {
    $where[] = "i.status = ?";
    $params[] = $statusFilter;
}

if (!empty($search)) {
    $where[] = "(i.name LIKE ? OR i.email LIKE ? OR i.subject LIKE ? OR i.message LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Stats
$stats = [
    'total'    => (int) $pdo->query("SELECT COUNT(*) FROM inquiries")->fetchColumn(),
    'pending'  => (int) $pdo->query("SELECT COUNT(*) FROM inquiries WHERE status = 'pending'")->fetchColumn(),
    'resolved' => (int) $pdo->query("SELECT COUNT(*) FROM inquiries WHERE status = 'resolved'")->fetchColumn()
];

// Query Inquiries
$sql = "
    SELECT 
        i.*,
        u.role AS user_role,
        u.phone AS user_phone
    FROM inquiries i
    LEFT JOIN users u ON i.user_id = u.id
    {$whereClause}
    ORDER BY i.created_at DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$inquiries = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Customer Inquiries';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> | <?php echo APP_NAME; ?> Admin</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif']
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
        }
    </script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="<?php echo APP_URL; ?>/assets/js/main.js" defer></script>
</head>

<body class="bg-gray-100 text-gray-800 font-sans antialiased">
    <div class="flex min-h-screen">
        <!-- SIDEBAR -->
        <?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>

        <div class="flex-1 flex flex-col min-w-0">
            <!-- TOP BAR -->
            <header class="bg-white border-b border-gray-200 sticky top-0 z-20 flex items-center gap-4 px-6 h-16 flex-shrink-0">
                <button id="sidebar-open-btn" type="button" class="lg:hidden text-gray-500 hover:text-gray-900" aria-label="Open sidebar">
                    <i data-lucide="menu" class="w-5 h-5"></i>
                </button>
                <div class="flex-1 min-w-0 flex items-center gap-2 text-sm">
                    <span class="text-gray-400 font-medium hidden sm:inline">Admin</span>
                    <i data-lucide="chevron-right" class="w-3.5 h-3.5 text-gray-300 hidden sm:inline flex-shrink-0"></i>
                    <span class="font-bold text-gray-900">Inquiries</span>
                </div>
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-brand-red flex items-center justify-center text-white text-sm font-bold flex-shrink-0">
                        <?php echo strtoupper(substr($currentUser['name'] ?? 'A', 0, 1)); ?>
                    </div>
                </div>
            </header>

            <!-- MAIN CONTENT -->
            <main class="flex-1 p-5 sm:p-7 overflow-auto">
        <!-- Page Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
            <div>
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-red-100 text-brand-red font-bold">
                        <i data-lucide="message-square" class="w-5 h-5"></i>
                    </span>
                    <h1 class="text-2xl font-black text-gray-900 uppercase tracking-tight">Customer Inquiries</h1>
                </div>
                <p class="text-xs text-gray-500 mt-1">Review pre-sales questions, technical support messages, and provide replies.</p>
            </div>
            
            <a href="<?php echo APP_URL; ?>/contact.php" target="_blank" class="inline-flex items-center gap-2 px-4 py-2.5 bg-gray-900 text-white rounded-xl text-xs font-bold hover:bg-black transition shadow-sm self-start sm:self-auto">
                <i data-lucide="external-link" class="w-4 h-4"></i>
                View Public Contact Page
            </a>
        </div>

        <!-- Metric KPI Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-8">
            <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm flex items-center justify-between">
                <div>
                    <span class="text-[11px] font-bold uppercase tracking-wider text-gray-400">Total Inquiries</span>
                    <p class="text-2xl font-black text-gray-900 mt-1"><?php echo number_format($stats['total']); ?></p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                    <i data-lucide="inbox" class="w-6 h-6"></i>
                </div>
            </div>

            <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm flex items-center justify-between">
                <div>
                    <span class="text-[11px] font-bold uppercase tracking-wider text-gray-400">Awaiting Response</span>
                    <p class="text-2xl font-black text-amber-600 mt-1"><?php echo number_format($stats['pending']); ?></p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                    <i data-lucide="clock" class="w-6 h-6"></i>
                </div>
            </div>

            <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm flex items-center justify-between">
                <div>
                    <span class="text-[11px] font-bold uppercase tracking-wider text-gray-400">Resolved</span>
                    <p class="text-2xl font-black text-emerald-600 mt-1"><?php echo number_format($stats['resolved']); ?></p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                    <i data-lucide="check-circle-2" class="w-6 h-6"></i>
                </div>
            </div>
        </div>

        <!-- Filter & Search Toolbar -->
        <div class="bg-white rounded-2xl border border-gray-200 p-4 mb-6 shadow-sm flex flex-col md:flex-row items-center justify-between gap-4">
            <!-- Tabs -->
            <div class="flex items-center gap-1 bg-gray-100 p-1 rounded-xl w-full md:w-auto">
                <a href="<?php echo APP_URL; ?>/admin/inquiries/index.php?status=all<?php echo !empty($search) ? '&q=' . urlencode($search) : ''; ?>"
                   class="px-3.5 py-1.5 rounded-lg text-xs font-bold transition <?php echo $statusFilter === 'all' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-900'; ?>">
                    All (<?php echo $stats['total']; ?>)
                </a>
                <a href="<?php echo APP_URL; ?>/admin/inquiries/index.php?status=pending<?php echo !empty($search) ? '&q=' . urlencode($search) : ''; ?>"
                   class="px-3.5 py-1.5 rounded-lg text-xs font-bold transition <?php echo $statusFilter === 'pending' ? 'bg-white text-amber-600 shadow-sm' : 'text-gray-500 hover:text-gray-900'; ?>">
                    Pending (<?php echo $stats['pending']; ?>)
                </a>
                <a href="<?php echo APP_URL; ?>/admin/inquiries/index.php?status=resolved<?php echo !empty($search) ? '&q=' . urlencode($search) : ''; ?>"
                   class="px-3.5 py-1.5 rounded-lg text-xs font-bold transition <?php echo $statusFilter === 'resolved' ? 'bg-white text-emerald-600 shadow-sm' : 'text-gray-500 hover:text-gray-900'; ?>">
                    Resolved (<?php echo $stats['resolved']; ?>)
                </a>
            </div>

            <!-- Search Form -->
            <form method="GET" action="<?php echo APP_URL; ?>/admin/inquiries/index.php" class="w-full md:w-80 flex items-center gap-2">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                <div class="relative w-full">
                    <i data-lucide="search" class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search sender, subject..."
                           class="w-full pl-9 pr-3 py-2 bg-gray-50 border border-gray-200 rounded-xl text-xs focus:ring-2 focus:ring-brand-red focus:bg-white outline-none transition">
                </div>
                <?php if (!empty($search)): ?>
                    <a href="<?php echo APP_URL; ?>/admin/inquiries/index.php?status=<?php echo htmlspecialchars($statusFilter); ?>"
                       class="px-2.5 py-2 text-gray-400 hover:text-gray-600 transition" title="Clear search">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Inquiries Table -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <?php if (empty($inquiries)): ?>
                <div class="p-12 text-center">
                    <div class="w-16 h-16 rounded-2xl bg-gray-100 text-gray-400 flex items-center justify-center mx-auto mb-3">
                        <i data-lucide="message-square-off" class="w-8 h-8"></i>
                    </div>
                    <h3 class="text-sm font-bold text-gray-800">No inquiries found</h3>
                    <p class="text-xs text-gray-400 mt-1">There are no customer inquiries matching your filter criteria.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="bg-gray-50 border-b border-gray-200 text-gray-400 font-bold uppercase tracking-wider text-[10px]">
                                <th class="py-3.5 px-4">Sender</th>
                                <th class="py-3.5 px-4">Subject & Message</th>
                                <th class="py-3.5 px-4">Status</th>
                                <th class="py-3.5 px-4">Date Submitted</th>
                                <th class="py-3.5 px-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($inquiries as $inq): ?>
                                <tr class="hover:bg-gray-50/80 transition">
                                    <td class="py-4 px-4">
                                        <div class="flex items-center gap-3">
                                            <div class="w-9 h-9 rounded-full bg-red-100 text-brand-red font-bold flex items-center justify-center text-xs flex-shrink-0">
                                                <?php echo strtoupper(substr($inq['name'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <p class="font-bold text-gray-900"><?php echo htmlspecialchars($inq['name']); ?></p>
                                                <p class="text-gray-500 text-[11px]"><?php echo htmlspecialchars($inq['email']); ?></p>
                                                <?php if (!empty($inq['user_id'])): ?>
                                                    <span class="inline-flex items-center gap-1 text-[9px] font-bold text-blue-600 bg-blue-50 px-1.5 py-0.5 rounded mt-0.5">
                                                        <i data-lucide="user-check" class="w-2.5 h-2.5"></i> Registered Client
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1 text-[9px] font-bold text-gray-500 bg-gray-100 px-1.5 py-0.5 rounded mt-0.5">
                                                        <i data-lucide="globe" class="w-2.5 h-2.5"></i> Guest Visitor
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="py-4 px-4 max-w-xs md:max-w-md">
                                        <div class="font-bold text-gray-900 text-xs mb-1">
                                            <?php echo htmlspecialchars($inq['subject']); ?>
                                        </div>
                                        <p class="text-gray-500 text-[11px] line-clamp-2 leading-relaxed">
                                            <?php echo htmlspecialchars($inq['message']); ?>
                                        </p>
                                        <?php if (!empty($inq['reply'])): ?>
                                            <div class="mt-2 text-[10px] text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-lg p-2 line-clamp-1">
                                                <strong>Replied:</strong> <?php echo htmlspecialchars($inq['reply']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td class="py-4 px-4 whitespace-nowrap">
                                        <?php if ($inq['status'] === 'resolved'): ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-black bg-emerald-100 text-emerald-700">
                                                <i data-lucide="check" class="w-3 h-3"></i> Resolved
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-black bg-amber-100 text-amber-700">
                                                <i data-lucide="clock" class="w-3 h-3"></i> Pending
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="py-4 px-4 whitespace-nowrap text-gray-500 text-[11px]">
                                        <div><?php echo date('M d, Y', strtotime($inq['created_at'])); ?></div>
                                        <div class="text-[10px] text-gray-400"><?php echo date('h:i A', strtotime($inq['created_at'])); ?></div>
                                    </td>

                                    <td class="py-4 px-4 text-right whitespace-nowrap">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <!-- View / Reply Button -->
                                            <button type="button" 
                                                    onclick="openReplyModal(<?php echo htmlspecialchars(json_encode($inq), ENT_QUOTES, 'UTF-8'); ?>)"
                                                    class="inline-flex items-center gap-1 px-3 py-1.5 bg-gray-900 text-white rounded-lg text-[11px] font-bold hover:bg-black transition shadow-sm">
                                                <i data-lucide="reply" class="w-3.5 h-3.5"></i>
                                                <?php echo empty($inq['reply']) ? 'Reply' : 'View / Edit'; ?>
                                            </button>

                                            <!-- Toggle Status Form -->
                                            <form method="POST" action="" class="inline" onsubmit="return confirm('Toggle status for this inquiry?');">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="inquiry_id" value="<?php echo $inq['id']; ?>">
                                                <button type="submit" 
                                                        class="p-1.5 rounded-lg border border-gray-200 text-gray-500 hover:text-gray-900 hover:bg-gray-100 transition"
                                                        title="<?php echo $inq['status'] === 'resolved' ? 'Mark as Pending' : 'Mark as Resolved'; ?>">
                                                    <i data-lucide="<?php echo $inq['status'] === 'resolved' ? 'rotate-ccw' : 'check'; ?>" class="w-3.5 h-3.5"></i>
                                                </button>
                                            </form>

                                            <!-- Delete Form -->
                                            <form method="POST" action="" class="inline" onsubmit="return confirm('Are you sure you want to permanently delete this inquiry?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="inquiry_id" value="<?php echo $inq['id']; ?>">
                                                <button type="submit" 
                                                        class="p-1.5 rounded-lg border border-gray-200 text-red-500 hover:bg-red-50 transition" 
                                                        title="Delete Inquiry">
                                                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
            </main>
        </div>
    </div>

    <!-- Reply Modal -->
    <div id="replyModal" class="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-xl w-full p-6 sm:p-8 shadow-2xl relative border border-gray-100 animate-in fade-in duration-200">
            <button type="button" onclick="closeReplyModal()" class="absolute top-5 right-5 text-gray-400 hover:text-gray-600 transition">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>

            <div class="flex items-center gap-3 mb-5">
                <div class="w-10 h-10 rounded-xl bg-red-100 text-brand-red flex items-center justify-center font-bold">
                    <i data-lucide="message-square" class="w-5 h-5"></i>
                </div>
                <div>
                    <h3 class="text-base font-black text-gray-900 uppercase tracking-tight">Customer Inquiry</h3>
                    <p id="modalSenderInfo" class="text-xs text-gray-500">Reviewing inquiry message</p>
                </div>
            </div>

            <!-- Inquiry Details -->
            <div class="bg-gray-50 rounded-2xl p-4 border border-gray-200 mb-5 space-y-2">
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Subject:</span>
                    <p id="modalSubject" class="text-xs font-bold text-gray-900 mt-0.5"></p>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Customer Message:</span>
                    <p id="modalMessage" class="text-xs text-gray-700 mt-0.5 whitespace-pre-wrap leading-relaxed max-h-40 overflow-y-auto scrollbar-thin"></p>
                </div>
            </div>

            <!-- Reply Form -->
            <form method="POST" action="">
                <input type="hidden" name="action" value="reply">
                <input type="hidden" name="inquiry_id" id="modalInquiryId">

                <div class="mb-5">
                    <label for="reply_message" class="block text-xs font-bold uppercase tracking-wider text-gray-700 mb-1.5">
                        Official Support Reply:
                    </label>
                    <textarea name="reply_message" id="modalReplyText" rows="4" required
                              placeholder="Type your response here. This will be sent directly to the customer's email and saved in their portal..."
                              class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl text-xs focus:ring-2 focus:ring-brand-red focus:border-brand-red outline-none transition"></textarea>
                    <p class="text-[11px] text-gray-400 mt-1 flex items-center gap-1">
                        <i data-lucide="mail" class="w-3 h-3 text-gray-400"></i>
                        An email with this reply will automatically be dispatched to the customer's email.
                    </p>
                </div>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <button type="button" onclick="closeReplyModal()" class="px-5 py-2.5 text-xs font-bold text-gray-600 hover:text-gray-900 transition">
                        Cancel
                    </button>
                    <button type="submit" class="px-6 py-2.5 bg-brand-red text-white text-xs font-black uppercase tracking-wider rounded-xl hover:bg-red-700 transition shadow-md inline-flex items-center gap-2">
                        <i data-lucide="send" class="w-3.5 h-3.5"></i>
                        Send Response
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openReplyModal(inquiry) {
        document.getElementById('modalInquiryId').value = inquiry.id;
        document.getElementById('modalSenderInfo').textContent = inquiry.name + ' (' + inquiry.email + ')';
        document.getElementById('modalSubject').textContent = inquiry.subject;
        document.getElementById('modalMessage').textContent = inquiry.message;
        document.getElementById('modalReplyText').value = inquiry.reply || '';
        
        document.getElementById('replyModal').classList.remove('hidden');
        if (window.lucide) lucide.createIcons();
    }

    function closeReplyModal() {
        document.getElementById('replyModal').classList.add('hidden');
    }

    window.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeReplyModal();
    });

    if (typeof lucide !== 'undefined') lucide.createIcons();
    </script>
</body>
</html>
