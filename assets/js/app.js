/* =====================================================================
   SIMOP Dapur Fizzul — interaksi UI (tanpa dependensi selain Chart.js)
   ===================================================================== */
(function () {
    'use strict';

    /* --------- Sidebar (mobile) --------- */
    function initNav() {
        var burger = document.querySelector('.burger');
        if (burger) {
            burger.addEventListener('click', function (e) {
                e.preventDefault();
                document.body.classList.toggle('nav-open');
            });
        }
        document.addEventListener('click', function (e) {
            if (document.body.classList.contains('nav-open') &&
                !e.target.closest('.sidebar') && !e.target.closest('.burger')) {
                document.body.classList.remove('nav-open');
            }
        });
    }

    /* --------- Modal --------- */
    window.openModal = function (id) {
        var m = document.getElementById(id);
        if (!m) return;
        m.classList.add('open');
        document.body.style.overflow = 'hidden';
        var first = m.querySelector('input:not([type=hidden]):not([readonly]), select, textarea');
        if (first) setTimeout(function () { first.focus(); }, 60);
    };
    window.closeModal = function (id) {
        var m = document.getElementById(id);
        if (!m) return;
        m.classList.remove('open');
        document.body.style.overflow = '';
    };
    function initModal() {
        document.addEventListener('click', function (e) {
            var opener = e.target.closest('[data-modal]');
            if (opener) {
                e.preventDefault();
                var mid = opener.getAttribute('data-modal');
                var m = document.getElementById(mid);
                if (m && opener.hasAttribute('data-reset')) {
                    var f = m.querySelector('form');
                    if (f) f.reset();
                    m.querySelectorAll('input[type=hidden]').forEach(function (h) { if (h.name === 'id') h.value = ''; });
                }
                openModal(mid);
                return;
            }
            var delBtn = e.target.closest('[data-del]');
            if (delBtn) {
                e.preventDefault();
                var box = document.getElementById('m-del');
                if (box) {
                    var set = function (sel, val) { var el = box.querySelector(sel); if (el) el.value = val; };
                    set('#del-id', delBtn.getAttribute('data-id') || '');
                    set('#del-action', delBtn.getAttribute('data-action') || '');
                    var nm = box.querySelector('#del-name'); if (nm) nm.textContent = delBtn.getAttribute('data-name') || '';
                    var st = box.querySelector('#del-stock'); if (st) st.textContent = delBtn.getAttribute('data-stock') || '-';
                    var cf = box.querySelector('#del-confirm'); if (cf) cf.checked = false;
                }
                openModal('m-del');
                return;
            }

            var filler = e.target.closest('[data-fill]');
            if (filler) {
                e.preventDefault();
                var target = document.getElementById(filler.getAttribute('data-fill'));
                if (!target) return;
                var data = {};
                try { data = JSON.parse(filler.getAttribute('data-json') || '{}'); } catch (err) { data = {}; }
                Object.keys(data).forEach(function (k) {
                    var el = target.querySelector('[name="' + k + '"]');
                    if (!el) return;
                    var v = data[k];
                    if (el.type === 'checkbox') el.checked = parseInt(v, 10) === 1 || v === true || v === '1';
                    else el.value = (v === null || v === undefined) ? '' : v;
                });
                var t = target.querySelector('[data-title]');
                if (t && filler.getAttribute('data-title-text')) t.textContent = filler.getAttribute('data-title-text');
                openModal(filler.getAttribute('data-fill'));
                return;
            }
            var closer = e.target.closest('[data-close]');
            if (closer) {
                e.preventDefault();
                closeModal(closer.closest('.modal-backdrop').id);
                return;
            }
            if (e.target.classList.contains('modal-backdrop')) {
                closeModal(e.target.id);
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                var open = document.querySelector('.modal-backdrop.open');
                if (open) closeModal(open.id);
            }
        });
    }

    /* --------- Konfirmasi --------- */
    function initConfirm() {
        document.addEventListener('submit', function (e) {
            var f = e.target;
            var msg = f.getAttribute('data-confirm');
            if (msg && !window.confirm(msg)) {
                e.preventDefault();
            }
        });
    }

    /* --------- Toast --------- */
    function initToast() {
        var stack = document.querySelector('.toast-stack');
        if (!stack) return;
        Array.prototype.forEach.call(stack.querySelectorAll('.toast'), function (t) {
            var close = document.createElement('button');
            close.className = 'x-btn';
            close.style.float = 'right';
            close.innerHTML = '&times;';
            close.onclick = function () { t.remove(); };
            t.appendChild(close);
            setTimeout(function () {
                t.style.transition = 'opacity .3s, transform .3s';
                t.style.opacity = '0';
                t.style.transform = 'translateX(10px)';
                setTimeout(function () { t.remove(); }, 320);
            }, 7000);
        });
    }

    /* --------- Quick filter tabel --------- */
    function initTableFilter() {
        document.querySelectorAll('[data-filter]').forEach(function (input) {
            var table = document.querySelector(input.getAttribute('data-filter'));
            if (!table) return;
            input.addEventListener('input', function () {
                var q = input.value.toLowerCase().trim();
                var shown = 0;
                table.querySelectorAll('tbody tr').forEach(function (tr) {
                    if (tr.classList.contains('total-row')) return;
                    var ok = q === '' || tr.innerText.toLowerCase().indexOf(q) !== -1;
                    tr.style.display = ok ? '' : 'none';
                    if (ok) shown++;
                });
                var meta = document.querySelector('[data-filter-count="' + input.getAttribute('data-filter') + '"]');
                if (meta) meta.textContent = shown + ' baris ditampilkan';
            });
        });
    }

    /* --------- Baris item dinamis (penjualan & pembelian) --------- */
    function fmt(n, dec) {
        return (isFinite(n) ? n : 0).toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: dec === undefined ? 0 : dec });
    }
    window.addItemRow = function (kind, data) {
        var tpl = document.getElementById(kind + '-row-template');
        var host = document.getElementById(kind + '-rows');
        if (!tpl || !host) return;
        var node = tpl.content.firstElementChild.cloneNode(true);
        host.appendChild(node);
        if (data) {
            Object.keys(data).forEach(function (k) {
                var el = node.querySelector('[name="' + k + '[]"]');
                if (el) el.value = data[k];
            });
        }
        recalc(kind);
    };
    window.removeItemRow = function (btn) {
        var row = btn.closest('.item-row');
        var host = row.parentElement;
        row.remove();
        if (!host.children.length) {
            addItemRow(host.id.replace('-rows', ''));
        }
        recalc(host.id.replace('-rows', ''));
    };
    function recalc(kind) {
        var host = document.getElementById(kind + '-rows');
        if (!host) return;
        var total = 0;
        Array.prototype.forEach.call(host.querySelectorAll('.item-row'), function (row) {
            var qty = parseFloat((row.querySelector('[name="qty[]"]') || {}).value || 0) || 0;
            var priceEl = row.querySelector('[name="unit_cost[]"]') || row.querySelector('[name="unit_price[]"]');
            var price = priceEl && priceEl.value !== '' ? parseFloat(String(priceEl.value).replace(/\./g, '').replace(',', '.')) : null;
            if (price === null || isNaN(price)) {
                var sel = row.querySelector('select[name="menu_id[]"]');
                if (sel && sel.selectedOptions[0]) price = parseFloat(sel.selectedOptions[0].getAttribute('data-price') || 0);
                if (priceEl && !isNaN(price) && price) priceEl.value = price;
            }
            price = isNaN(price) ? 0 : price;
            var line = qty * price;
            var out = row.querySelector('.row-total');
            if (out) out.textContent = 'Rp ' + fmt(line);
            total += line;
        });
        var t = document.getElementById(kind + '-total');
        if (t) {
            var disc = parseFloat(((document.getElementById('discount') || {}).value || 0) + '') || 0;
            t.textContent = 'Rp ' + fmt(total - (kind === 'sale' ? disc : 0));
        }
    }
    function initItemRows() {
        ['sale', 'purchase'].forEach(function (kind) {
            var host = document.getElementById(kind + '-rows');
            if (!host) return;
            if (!host.children.length) addItemRow(kind);
            host.addEventListener('input', function () { recalc(kind); });
            host.addEventListener('change', function (e) {
                var sel = e.target;
                if (sel.matches('select[name="menu_id[]"]')) {
                    var priceEl = host.querySelector('.item-row [name="unit_price[]"]');
                    if (priceEl) priceEl.value = sel.selectedOptions[0].getAttribute('data-price') || '';
                }
                if (sel.matches('select[name="ingredient_id[]"]')) {
                    var unitEl = sel.closest('.item-row').querySelector('[name="unit[]"]');
                    if (unitEl) unitEl.value = sel.selectedOptions[0].getAttribute('data-unit') || '';
                }
                recalc(kind);
            });
            var d = document.getElementById('discount');
            if (d) d.addEventListener('input', function () { recalc(kind); });
        });
    }
    window.recalcItems = recalc;

    /* --------- Chart.js --------- */
    function initCharts() {
        if (typeof Chart === 'undefined') return;
        Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
        Chart.defaults.font.size = 11.5;
        Chart.defaults.color = '#635F82';
        Chart.defaults.plugins.legend.labels.boxWidth = 12;
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        Chart.defaults.maintainAspectRatio = false;
        var palette = ['#4F46E5', '#8B5CF6', '#0EA5E9', '#0E9F6E', '#D97706', '#DC2626', '#7C3AED', '#0891B2', '#65A30D', '#DB2777'];

        document.querySelectorAll('script[type="application/json"][data-chart]').forEach(function (s) {
            var canvas = document.getElementById(s.getAttribute('data-chart'));
            if (!canvas) return;
            var cfg;
            try { cfg = JSON.parse(s.textContent); } catch (e) { return; }
            var type = cfg.type || 'line';
            var ds = (cfg.datasets || []).map(function (d, i) {
                var base = {
                    label: d.label || 'Data',
                    data: d.data || [],
                    borderColor: d.borderColor || palette[i % palette.length],
                    backgroundColor: d.backgroundColor || (type === 'line' ? 'rgba(79,70,229,.12)'
                        : type === 'bar' ? palette[i % palette.length] : palette.map(function (c) { return c + 'cc'; })),
                    borderWidth: d.borderWidth || 2,
                    tension: 0.35,
                    pointRadius: type === 'line' ? 2.5 : 0,
                    pointHoverRadius: 4
                };
                if (type === 'bar') { base.borderRadius = 6; base.borderColor = 'transparent'; base.maxBarThickness = 46; }
                if (type === 'line') base.fill = d.fill === true;
                if (d.yAxisID) base.yAxisID = d.yAxisID;
                return base;
            });
            new Chart(canvas.getContext('2d'), {
                type: type,
                data: { labels: cfg.labels || [], datasets: ds },
                options: {
                    responsive: true,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: cfg.legend !== false && ds.length > 1, position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    var v = ctx.parsed.y !== undefined && ctx.parsed.y !== null ? ctx.parsed.y : ctx.parsed;
                                    var prefix = cfg.prefix || '';
                                    var suffix = cfg.suffix || '';
                                    if (typeof v === 'number') {
                                        if (cfg.format === 'rp') return ctx.dataset.label + ': Rp ' + fmt(v);
                                        return ctx.dataset.label + ': ' + prefix + fmt(v, 1) + suffix;
                                    }
                                    return ctx.dataset.label + ': ' + v;
                                }
                            }
                        }
                    },
                    scales: (type === 'doughnut' || type === 'pie') ? {} : {
                        x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 12 } },
                        y: {
                            beginAtZero: true, grid: { color: 'rgba(31,27,58,.07)' },
                            ticks: {
                                callback: function (v) {
                                    if (cfg.format === 'rp') {
                                        return v >= 1000000 ? (v / 1000000) + ' jt' : v >= 1000 ? (v / 1000) + ' rb' : v;
                                    }
                                    return v;
                                }
                            }
                        }
                    }
                }
            });
        });
    }

    /* --------- Tab tanpa reload (anchor) --------- */
    function initFormats() {
        document.querySelectorAll('input[data-num], input[data-currency]').forEach(function (el) {
            el.addEventListener('blur', function () {
                var v = String(el.value).replace(/[^0-9,.\-]/g, '');
                if (v === '') return;
                var n = parseFloat(v.replace(/\./g, '').replace(',', '.'));
                if (isNaN(n)) return;
                el.value = el.hasAttribute('data-currency') ? fmt(n) : String(n).replace('.', ',');
            });
            el.addEventListener('focus', function () {
                el.value = String(el.value).replace(/\./g, '').replace(',', '.');
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initNav();
        initModal();
        initConfirm();
        initToast();
        initTableFilter();
        initItemRows();
        initFormats();
        initCharts();
    });
})();
