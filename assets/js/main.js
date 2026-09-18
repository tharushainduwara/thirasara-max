'use strict';

/* LUCIDE ICONS */
document.addEventListener('DOMContentLoaded', function () {
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
});


/* PUBLIC NAVBAR — MOBILE MENU TOGGLE */
document.addEventListener('DOMContentLoaded', function () {
    var btn  = document.getElementById('mobile-menu-btn');
    var menu = document.getElementById('mobile-menu');

    if (!btn || !menu) return;

    /* Prevent double-binding if header.php already attached a listener */
    if (btn.dataset.menuBound) return;
    btn.dataset.menuBound = '1';

    btn.addEventListener('click', function () {
        var isOpen = !menu.classList.contains('hidden');

        menu.classList.toggle('hidden', isOpen);

        btn.setAttribute('aria-expanded', isOpen ? 'false' : 'true');

        /* Swap icon */
        btn.innerHTML = isOpen
            ? '<i data-lucide="menu"  class="w-6 h-6"></i>'
            : '<i data-lucide="x"    class="w-6 h-6"></i>';

        if (typeof lucide !== 'undefined') lucide.createIcons();
    });
});


/*  ADMIN SIDEBAR — MOBILE SLIDE-IN / OVERLAY */
document.addEventListener('DOMContentLoaded', function () {
    var sidebar  = document.getElementById('admin-sidebar');
    var overlay  = document.getElementById('sidebar-overlay');
    var openBtn  = document.getElementById('sidebar-open-btn');
    var closeBtn = document.getElementById('sidebar-close-btn');

    if (!sidebar) return;               /* Not an admin page */
    if (sidebar.dataset.sidebarBound) return;
    sidebar.dataset.sidebarBound = '1';

    function openSidebar() {
        sidebar.classList.remove('-translate-x-full');
        if (overlay) overlay.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
    }

    function closeSidebar() {
        sidebar.classList.add('-translate-x-full');
        if (overlay) overlay.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }

    function applyResponsive() {
        if (window.innerWidth < 1024) {
            sidebar.classList.add('-translate-x-full', 'fixed', 'inset-y-0', 'left-0');
        } else {
            sidebar.classList.remove('-translate-x-full', 'fixed', 'inset-y-0', 'left-0');
            if (overlay) overlay.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }
    }

    if (openBtn)  openBtn.addEventListener('click', openSidebar);
    if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
    if (overlay)  overlay.addEventListener('click', closeSidebar);

    applyResponsive();
    window.addEventListener('resize', applyResponsive);
});


/* FLASH / ALERT AUTO-DISMISS */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-auto-dismiss]').forEach(function (el) {
        var delay = parseInt(el.dataset.autoDismiss, 10) || 5000;

        setTimeout(function () {
            el.style.transition = 'opacity 0.4s ease';
            el.style.opacity    = '0';
            setTimeout(function () { el.remove(); }, 420);
        }, delay);
    });
});


/* CONFIRM DIALOGS */
document.addEventListener('DOMContentLoaded', function () {
    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-confirm]');
        if (!el) return;

        /* For forms the submit event is more appropriate; skip here */
        if (el.tagName === 'FORM') return;

        if (!window.confirm(el.dataset.confirm)) {
            e.preventDefault();
            e.stopPropagation();
        }
    });

    document.addEventListener('submit', function (e) {
        var form = e.target.closest('form[data-confirm]');
        if (!form) return;

        if (!window.confirm(form.dataset.confirm)) {
            e.preventDefault();
        }
    });
});


/*  PRODUCTS PAGE — FILTER AUTO-SUBMIT & HORIZONTAL SCROLL */
document.addEventListener('DOMContentLoaded', function () {

    /* --- Auto-submit on select change --- */
    ['category', 'brand', 'sort'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el && el.form) {
            el.addEventListener('change', function () { this.form.submit(); });
        }
    });

    /* --- Horizontal category row scroll --- */
    window.scrollRow = function (rowId, direction) {
        var row = document.getElementById(rowId);
        if (!row) return;

        var card = row.querySelector('.product-card');
        var cardWidth = card ? card.offsetWidth + 20 : 300;

        row.scrollBy({ left: direction * cardWidth, behavior: 'smooth' });
    };
});


/*  CART — QUANTITY STEPPER BUTTONS */
document.addEventListener('DOMContentLoaded', function () {
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-qty-btn]');
        if (!btn) return;

        var targetId = btn.dataset.target;
        var input    = targetId ? document.getElementById(targetId) : null;
        if (!input) return;

        var current = parseInt(input.value, 10) || 1;
        var min     = parseInt(input.min, 10)   || 1;
        var max     = parseInt(input.max, 10)   || Infinity;
        var step    = parseInt(input.step, 10)  || 1;

        if (btn.dataset.qtyBtn === 'inc') {
            input.value = Math.min(max, current + step);
        } else {
            input.value = Math.max(min, current - step);
        }

        /* Fire change so any surrounding form listeners pick it up */
        input.dispatchEvent(new Event('change', { bubbles: true }));
    });
});


/*  CART — AJAX ADD-TO-CART */
document.addEventListener('DOMContentLoaded', function () {
    document.addEventListener('submit', function (e) {
        var form = e.target.closest('form[data-ajax-cart]');
        if (!form) return;

        e.preventDefault();

        var btn = form.querySelector('[type="submit"]');
        var originalHTML = btn ? btn.innerHTML : '';

        if (btn) {
            btn.disabled   = true;
            btn.innerHTML  = '<svg class="animate-spin w-4 h-4 mx-auto" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path></svg>';
        }

        var data = new FormData(form);
        var body = {};
        data.forEach(function (v, k) { body[k] = v; });

        /* Resolve the API URL relative to the app root */
        var apiUrl = (window.__APP_URL__ || '') + '/api/cart_actions.php';

        fetch(apiUrl, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(body),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                _updateCartBadge(data.cart_count);
                showToast(data.message || 'Added to cart!', 'success');
            } else {
                showToast(data.error || 'Could not add to cart.', 'error');
            }
        })
        .catch(function () {
            showToast('Network error. Please try again.', 'error');
        })
        .finally(function () {
            if (btn) {
                btn.disabled  = false;
                btn.innerHTML = originalHTML;
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }
        });
    });
});


/* CART COUNT BADGE — LIVE UPDATE */
function _updateCartBadge(count) {
    document.querySelectorAll('[data-cart-count]').forEach(function (el) {
        el.textContent = count;
        el.classList.toggle('hidden', count <= 0);
    });
}


/* FORM UTILITIES — PASSWORD VISIBILITY TOGGLE */
document.addEventListener('DOMContentLoaded', function () {
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-toggle-pw]');
        if (!btn) return;

        var input = document.getElementById(btn.dataset.togglePw);
        if (!input) return;

        var isHidden = input.type === 'password';
        input.type   = isHidden ? 'text' : 'password';

        var icon = btn.querySelector('i[data-lucide]');
        if (icon) {
            icon.setAttribute('data-lucide', isHidden ? 'eye-off' : 'eye');
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
    });
});


/*  FORM UTILITIES — CHARACTER COUNTER */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-char-counter]').forEach(function (input) {
        var counterId = input.dataset.charCounter;
        var counter   = document.getElementById(counterId);
        var max       = parseInt(input.getAttribute('maxlength'), 10);

        if (!counter || !max) return;

        function update() {
            var remaining = max - input.value.length;
            counter.textContent = remaining + ' character' + (remaining === 1 ? '' : 's') + ' remaining';
            counter.classList.toggle('text-red-500', remaining < 20);
        }

        input.addEventListener('input', update);
        update();
    });
});


/*  SMOOTH SCROLL — IN-PAGE ANCHORS */
document.addEventListener('DOMContentLoaded', function () {
    var TOP_BAR_HEIGHT = 72; /* px — adjust if your navbar height changes */

    document.addEventListener('click', function (e) {
        var link = e.target.closest('a[href^="#"]');
        if (!link) return;

        var hash   = link.getAttribute('href');
        if (hash === '#') return;

        var target = document.querySelector(hash);
        if (!target) return;

        e.preventDefault();

        var top = target.getBoundingClientRect().top + window.scrollY - TOP_BAR_HEIGHT;
        window.scrollTo({ top: top, behavior: 'smooth' });

        /* Update URL hash without jumping */
        if (history.pushState) {
            history.pushState(null, null, hash);
        }
    });
});


/* ACTIVE NAV LINK HIGHLIGHTER */
document.addEventListener('DOMContentLoaded', function () {
    var currentPath = window.location.pathname;

    document.querySelectorAll('nav a[href]').forEach(function (link) {
        try {
            var linkPath = new URL(link.href, window.location.origin).pathname;
            if (linkPath === currentPath) {
                link.setAttribute('aria-current', 'page');
            }
        } catch (_) { /* ignore invalid hrefs */ }
    });
});


/* IMAGE LAZY-LOAD FALLBACK */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('img[data-fallback-icon]').forEach(function (img) {
        img.addEventListener('error', function () {
            img.style.display = 'none';
            var sibling = img.nextElementSibling;
            if (sibling) sibling.classList.remove('hidden');
        });
    });
});


/* TOAST NOTIFICATION HELPER */
(function () {
    var TOAST_STYLES = {
        success: 'bg-green-600  text-white',
        error:   'bg-red-600    text-white',
        warning: 'bg-amber-500  text-white',
        info:    'bg-blue-600   text-white',
    };

    var TOAST_ICONS = {
        success: 'check-circle',
        error:   'x-circle',
        warning: 'alert-triangle',
        info:    'info',
    };

    function getContainer() {
        var id  = '__toast-container__';
        var el  = document.getElementById(id);

        if (!el) {
            el            = document.createElement('div');
            el.id         = id;
            el.className  = 'fixed bottom-5 right-5 z-[9999] flex flex-col gap-2 pointer-events-none';
            el.setAttribute('aria-live', 'polite');
            document.body.appendChild(el);
        }

        return el;
    }

    window.showToast = function (message, type, duration) {
        type     = type     || 'info';
        duration = duration || 4000;

        var container = getContainer();
        var toast     = document.createElement('div');
        var colorClass = TOAST_STYLES[type] || TOAST_STYLES.info;
        var iconName   = TOAST_ICONS[type]  || 'info';

        toast.className = [
            'flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg text-sm font-medium',
            'pointer-events-auto min-w-[220px] max-w-sm',
            'transform translate-y-4 opacity-0 transition-all duration-300',
            colorClass,
        ].join(' ');

        toast.innerHTML =
            '<i data-lucide="' + iconName + '" class="w-4 h-4 flex-shrink-0"></i>' +
            '<span class="flex-1">' + _escapeHtml(message) + '</span>' +
            '<button type="button" class="opacity-70 hover:opacity-100 ml-1 flex-shrink-0" aria-label="Close">' +
            '  <i data-lucide="x" class="w-3.5 h-3.5"></i>' +
            '</button>';

        container.appendChild(toast);

        if (typeof lucide !== 'undefined') lucide.createIcons();

        /* Animate in */
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                toast.classList.remove('translate-y-4', 'opacity-0');
            });
        });

        /* Close button */
        toast.querySelector('button').addEventListener('click', function () {
            dismissToast(toast);
        });

        /* Auto-dismiss */
        var timer = setTimeout(function () { dismissToast(toast); }, duration);

        toast.addEventListener('mouseenter', function () { clearTimeout(timer); });
        toast.addEventListener('mouseleave', function () {
            timer = setTimeout(function () { dismissToast(toast); }, 2000);
        });
    };

    function dismissToast(toast) {
        toast.classList.add('opacity-0', 'translate-y-2');
        setTimeout(function () {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
        }, 320);
    }

    function _escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
})();
