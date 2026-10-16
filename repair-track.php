<?php

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/repair_helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$currentUser = getCurrentUser();
$pageTitle   = 'Track Your Repair';
$inputCls    = 'mt-1.5 w-full px-3 py-2.5 sm:px-4 sm:py-3 rounded-lg border border-gray-300 bg-gray-50 text-xs sm:text-sm text-gray-900 placeholder-gray-400 focus:bg-white focus:outline-none focus:border-brand-red transition';
$labelCls    = 'block text-[10px] font-bold uppercase tracking-wider text-gray-700';

require_once __DIR__ . '/includes/header.php';
?>

<!-- PAGE HERO (High-Contrast Style) -->
<section class="bg-gradient-to-r from-gray-900 via-zinc-900 to-gray-900 text-white border-b-4 border-brand-red py-12 mb-10 overflow-hidden rounded-3xl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center max-w-2xl">
        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded bg-brand-red text-white text-[10px] font-bold uppercase tracking-wider mb-3 shadow-sm">
            <span class="w-1.5 h-1.5 bg-white rounded-full animate-pulse"></span> Live Status
        </span>
        <h1 class="text-3xl sm:text-4xl font-black uppercase tracking-tight text-white">
            Track Your <span class="text-brand-red">Repair</span>
        </h1>
        <p class="text-gray-300 text-xs sm:text-sm mt-2 leading-relaxed">
            Enter your repair code and the phone number or email you booked with.
        </p>
    </div>
</section>

<section class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 mb-16">

    <!-- LOOKUP FORM -->
    <div class="bg-white border-2 border-gray-200 rounded-xl p-6 sm:p-8 shadow-sm">
        <form id="trackForm" novalidate class="space-y-4">
            <input type="hidden" name="csrf" value="<?php echo rp_e(rp_csrf_token()); ?>">

            <div>
                <label class="<?php echo $labelCls; ?>">Repair Code</label>
                <input name="ref" required maxlength="9" autocomplete="off" placeholder="RP-XXXXXX"
                    class="<?php echo $inputCls; ?> uppercase tracking-widest font-extrabold">
            </div>
            <div>
                <label class="<?php echo $labelCls; ?>">Phone or Email</label>
                <input name="contact" required maxlength="100" autocomplete="off" placeholder="0771234567 or you@example.com"
                    class="<?php echo $inputCls; ?>">
            </div>

            <button type="submit" id="trackBtn"
                class="w-full inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-brand-red hover:bg-brand-redHover text-white font-bold text-xs sm:text-sm transition shadow-sm disabled:opacity-60">
                <i data-lucide="search" class="w-4 h-4"></i> Check Status
            </button>
        </form>
    </div>

    <!-- RESULT -->
    <div id="result" class="mt-6" aria-live="polite"></div>

    <p class="mt-6 text-center text-xs text-gray-500">
        Don't have a code yet?
        <a href="<?php echo APP_URL; ?>/repair-booking.php" class="font-bold text-brand-red hover:underline">Book a repair</a>
    </p>
</section>

<script>
    (function() {
        const LABELS = {
            pending: 'Received',
            assigned: 'Technician Assigned',
            in_progress: 'Repair In Progress',
            completed: 'Completed',
            cancelled: 'Cancelled'
        };
        const STEPS = [{
                key: 'pending',
                label: 'Received',
                icon: 'clipboard-list'
            },
            {
                key: 'assigned',
                label: 'Assigned',
                icon: 'user-check'
            },
            {
                key: 'in_progress',
                label: 'In Progress',
                icon: 'wrench'
            },
            {
                key: 'completed',
                label: 'Done',
                icon: 'check-circle-2'
            }
        ];
        const BADGE = {
            pending: 'bg-amber-500 text-white',
            assigned: 'bg-blue-600 text-white',
            in_progress: 'bg-purple-600 text-white',
            completed: 'bg-emerald-600 text-white',
            cancelled: 'bg-red-500 text-white'
        };

        const form = document.getElementById('trackForm');
        const btn = document.getElementById('trackBtn');
        const box = document.getElementById('result');
        let hasResult = false;

        const esc = s => {
            const d = document.createElement('div');
            d.textContent = s ?? '';
            return d.innerHTML;
        };
        const money = n => 'Rs. ' + Number(n).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
        const icons = () => {
            if (window.lucide) lucide.createIcons();
        };

        function row(icon, label, value) {
            return '<div class="flex items-start gap-3 py-3 border-b border-gray-100 last:border-0">' +
                '<i data-lucide="' + icon + '" class="w-4 h-4 text-gray-400 mt-0.5"></i>' +
                '<div class="flex-1 flex justify-between gap-4 text-xs sm:text-sm"><span class="text-gray-500 font-medium">' + label + '</span>' +
                '<span class="font-bold text-gray-900 text-right">' + value + '</span></div></div>';
        }

        function stepper(status) {
            const idx = STEPS.findIndex(s => s.key === status);
            return '<div class="flex items-start mt-6">' + STEPS.map((s, i) => {
                const done = i <= idx,
                    current = i === idx;
                const circle = done ? 'bg-brand-red text-white' : 'bg-gray-100 text-gray-400';
                const ring = current ? ' ring-4 ring-brand-red/20' : '';
                const line = i < STEPS.length - 1 ?
                    '<div class="flex-1 h-0.5 mt-4 ' + (i < idx ? 'bg-brand-red' : 'bg-gray-200') + '"></div>' : '';
                return '<div class="flex flex-col items-center w-14 sm:w-20">' +
                    '<div class="w-8 h-8 sm:w-10 sm:h-10 rounded-xl flex items-center justify-center ' + circle + ring + '"><i data-lucide="' + s.icon + '" class="w-4 h-4 sm:w-5 sm:h-5"></i></div>' +
                    '<span class="mt-2 text-[10px] sm:text-xs font-bold text-center ' + (done ? 'text-gray-900' : 'text-gray-400') + '">' + s.label + '</span></div>' + line;
            }).join('') + '</div>';
        }

        function showError(msg) {
            hasResult = false;
            box.innerHTML = '<div class="flex gap-3 p-4 rounded-xl bg-red-50 border-2 border-red-200 text-red-700 text-xs sm:text-sm font-semibold">' +
                '<i data-lucide="alert-circle" class="w-5 h-5 flex-shrink-0"></i><span>' + esc(msg) + '</span></div>';
            icons();
        }

        function render(d) {
            hasResult = true;
            let costRow = '';
            if (d.status === 'completed' && d.final_cost > 0) costRow = row('banknote', 'Final cost', esc(money(d.final_cost)));
            else if (d.estimated_cost > 0) costRow = row('banknote', 'Estimated cost', esc(money(d.estimated_cost)));

            box.innerHTML =
                '<div class="bg-white border-2 border-gray-200 rounded-xl shadow-sm p-6 sm:p-8">' +
                '<div class="flex flex-wrap items-center justify-between gap-3 pb-4 border-b-2 border-gray-100">' +
                '<div><p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Repair Code</p>' +
                '<p class="text-lg sm:text-xl font-black tracking-widest text-brand-red">' + esc(d.repair_code) + '</p></div>' +
                '<span class="inline-flex items-center px-3 py-1 rounded text-xs font-extrabold uppercase ' + (BADGE[d.status] || BADGE.pending) + '">' + esc(LABELS[d.status] || d.status) + '</span>' +
                '</div>' +
                (d.status === 'cancelled' ?
                    '<div class="mt-6 p-4 rounded-xl bg-red-50 border-2 border-red-200 text-xs sm:text-sm text-red-700 font-semibold">This repair was cancelled. Contact us if you think this is a mistake.</div>' :
                    stepper(d.status)) +
                '<div class="mt-6">' +
                row('smartphone', 'Device', esc(d.device_model)) +
                (d.warranty ? row('shield-check', 'Warranty', '<span class="text-emerald-600 font-bold">Covered</span>') : '') +
                costRow +
                (d.preferred_date ? row('calendar', 'Preferred date', esc(d.preferred_date)) : '') +
                (d.completed_at ? row('check-circle-2', 'Completed', esc(d.completed_at)) : '') +
                row('clock', 'Last updated', esc(d.updated_at)) +
                '</div>' +
                '<p class="mt-5 text-[10px] font-medium text-gray-400 text-center uppercase tracking-wider">This page refreshes automatically every 30 seconds.</p>' +
                '</div>';
            icons();
        }

        async function check(showSpinner) {
            if (showSpinner) {
                btn.disabled = true;
                btn.lastChild.textContent = ' Checking…';
            }
            try {
                const res = await fetch('api/get_repair_status.php', {
                    method: 'POST',
                    body: new FormData(form)
                });
                const data = await res.json();
                res.ok ? render(data) : showError(data.error || 'Something went wrong.');
            } catch (e) {
                showError('Network error. Please try again.');
            } finally {
                btn.disabled = false;
                btn.lastChild.textContent = ' Check Status';
            }
        }

        form.addEventListener('submit', e => {
            e.preventDefault();
            check(true);
        });
        setInterval(() => {
            if (hasResult && !document.hidden) check(false);
        }, 30000);
    })();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>