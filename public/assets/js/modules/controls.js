/* ============================================================
 * assets/js/modules/controls.js — 自定义表单控件（下拉 / 日期 / 文件）
 * ============================================================
 * 用自定义 DOM 替换系统默认外观（原生下拉列表 / 日历弹层无法用 CSS 定制），
 * 同时**保留原生元素**作为数据源：表单提交、`element.value` 读取、`change`
 * 事件均不受影响，因此不改变既有业务逻辑。
 *
 * 用法：
 *   PvControls.init(root)     // 扫描并增强 root 内的 select / date / file
 *   PvControls.sync(el)       // 外部程序化修改 value / disabled 后同步显示
 * 自动：脚本加载后对 document 执行一次 init（SPA 由各页 init 再调用）。
 * ============================================================ */
(function (global) {
    'use strict';

    var CHEV = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';
    var CAL  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="3" x2="8" y2="7"/><line x1="16" y1="3" x2="16" y2="7"/></svg>';
    var NAVL = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 6 9 12 15 18"/></svg>';
    var NAVR = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>';

    function txt(s) { return String(s == null ? '' : s); }
    function make(tag, cls) { var e = document.createElement(tag); if (cls) e.className = cls; return e; }
    function fire(el, type) {
        var e;
        try { e = new Event(type, { bubbles: true }); }
        catch (err) { e = document.createEvent('Event'); e.initEvent(type, true, false); }
        el.dispatchEvent(e);
    }
    function isVisible(el) { return !!(el && el.offsetParent !== null); }

    /* ---------------- 下拉选择 ---------------- */
    function enhanceSelect(sel) {
        if (sel.__pvSelect) return;
        sel.__pvSelect = true;
        sel.classList.add('pv-native-hidden');

        var wrap = make('div', 'pv-select');
        var btn = make('button', 'pv-select-btn'); btn.type = 'button';
        var label = make('span', 'pv-select-label');
        var caret = make('span', 'pv-select-caret'); caret.innerHTML = CHEV;
        btn.appendChild(label); btn.appendChild(caret);
        var menu = make('div', 'pv-select-menu');   // 打开时挂到 body，避免被祖先 overflow 裁剪
        wrap.appendChild(btn);
        sel.parentNode.insertBefore(wrap, sel.nextSibling);

        function build() {
            menu.innerHTML = '';
            Array.prototype.forEach.call(sel.options, function (o) {
                var it = make('button', 'pv-select-opt' + (o.selected ? ' selected' : ''));
                it.type = 'button';
                it.textContent = txt(o.textContent);
                it.setAttribute('data-value', o.value);
                if (o.disabled) it.disabled = true;
                it.addEventListener('click', function () { pick(o.value); });
                menu.appendChild(it);
            });
            var cur = sel.options[sel.selectedIndex];
            label.textContent = cur ? txt(cur.textContent) : '';
            var dis = !!sel.disabled;
            wrap.classList.toggle('is-disabled', dis);
            btn.disabled = dis;
        }
        function position() {
            if (!menu.classList.contains('open')) return;
            PvPopover.positionFixed(menu, btn, { mode: 'select', matchWidth: true });
        }
        var unbindOutside = null, unbindViewport = null;
        function open() {
            if (sel.disabled || menu.classList.contains('open')) return;
            build();
            document.body.appendChild(menu);
            wrap.classList.add('open');
            menu.classList.add('open');
            position();
            unbindOutside = PvPopover.onOutside(function (t) { return wrap.contains(t) || menu.contains(t); }, close, { type: 'pointerdown', capture: true });
            document.addEventListener('keydown', onKey, true);
            unbindViewport = PvPopover.onViewport(position);
            var cur = menu.querySelector('.pv-select-opt.selected');
            if (cur && menu.scrollHeight > menu.clientHeight) menu.scrollTop = Math.max(0, cur.offsetTop - menu.clientHeight / 2);
        }
        function close() {
            wrap.classList.remove('open');
            menu.classList.remove('open');
            if (menu.parentNode) menu.parentNode.removeChild(menu);
            if (unbindOutside) { unbindOutside(); unbindOutside = null; }
            document.removeEventListener('keydown', onKey, true);
            if (unbindViewport) { unbindViewport(); unbindViewport = null; }
        }
        function pick(v) {
            sel.value = v;
            fire(sel, 'input');
            fire(sel, 'change');
            build();
            close();
            btn.focus();
        }
        function onKey(e) {
            if (e.key === 'Escape') { close(); return; }
            if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp' && e.key !== 'Enter' && e.key !== ' ') return;
            var opts = menu.querySelectorAll('.pv-select-opt:not([disabled])');
            if (!wrap.classList.contains('open')) { e.preventDefault(); open(); return; }
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); close(); return; }
            e.preventDefault();
            var list = Array.prototype.slice.call(opts);
            if (!list.length) return;
            var idx = -1;
            for (var i = 0; i < list.length; i++) if (list[i].getAttribute('data-value') === sel.value) { idx = i; break; }
            idx = e.key === 'ArrowDown' ? Math.min(list.length - 1, idx + 1) : Math.max(0, idx - 1);
            pick(list[idx].getAttribute('data-value'));
        }
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (wrap.classList.contains('open')) close(); else open();
        });

        if (global.MutationObserver) {
            sel.__pvSelectMO = new global.MutationObserver(build);
            sel.__pvSelectMO.observe(sel, { childList: true, subtree: true, attributes: true, attributeFilter: ['selected', 'disabled'] });
        }
        sel.__pvSelectSync = build;
        build();
    }

    /* ---------------- 日期选择 ---------------- */
    function enhanceDate(input) {
        if (input.__pvDate) return;
        input.__pvDate = true;
        input.classList.add('pv-native-hidden');

        var wrap = make('div', 'pv-date');
        var field = make('button', 'pv-date-field'); field.type = 'button';
        var text = make('span', 'pv-date-text');
        var ico = make('span', 'pv-date-ico'); ico.innerHTML = CAL;
        field.appendChild(text); field.appendChild(ico);
        var pop = make('div', 'pv-date-pop');   // 打开时挂到 body，避免被祖先 overflow 裁剪
        wrap.appendChild(field);
        input.parentNode.insertBefore(wrap, input.nextSibling);

        var view = new Date();
        function parse() { var v = txt(input.value); if (!v) return null; var d = new Date(v + 'T00:00:00'); return isNaN(d.getTime()) ? null : d; }
        function fmt(d) { var m = d.getMonth() + 1, dd = d.getDate(); return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (dd < 10 ? '0' : '') + dd; }
        function sync() {
            text.textContent = txt(input.value) || '选择日期';
            text.classList.toggle('is-placeholder', !input.value);
            field.disabled = !!input.disabled;
            wrap.classList.toggle('is-disabled', !!input.disabled);
            var sel = parse(); if (sel) view = new Date(sel.getFullYear(), sel.getMonth(), 1);
        }
        function render() {
            var sel = parse();
            var y = view.getFullYear(), m = view.getMonth();
            var start = new Date(y, m, 1).getDay();
            var days = new Date(y, m + 1, 0).getDate();
            var h = '<div class="pv-cal-head"><button type="button" class="pv-cal-nav" data-nav="-1">' + NAVL + '</button>'
                + '<span class="pv-cal-title">' + y + ' 年 ' + (m + 1) + ' 月</span>'
                + '<button type="button" class="pv-cal-nav" data-nav="1">' + NAVR + '</button></div><div class="pv-cal-grid">';
            ['日', '一', '二', '三', '四', '五', '六'].forEach(function (w) { h += '<span class="pv-cal-w">' + w + '</span>'; });
            for (var i = 0; i < start; i++) h += '<span class="pv-cal-empty"></span>';
            for (var d = 1; d <= days; d++) {
                var cur = sel && sel.getFullYear() === y && sel.getMonth() === m && sel.getDate() === d;
                h += '<button type="button" class="pv-cal-day' + (cur ? ' cur' : '') + '" data-d="' + d + '">' + d + '</button>';
            }
            pop.innerHTML = h + '</div>';
            Array.prototype.forEach.call(pop.querySelectorAll('[data-nav]'), function (b) {
                b.addEventListener('click', function () { view.setMonth(view.getMonth() + parseInt(b.getAttribute('data-nav'), 10)); render(); });
            });
            Array.prototype.forEach.call(pop.querySelectorAll('.pv-cal-day'), function (b) {
                b.addEventListener('click', function () {
                    input.value = fmt(new Date(y, m, parseInt(b.getAttribute('data-d'), 10)));
                    fire(input, 'input'); fire(input, 'change');
                    sync(); close();
                });
            });
        }
        function position() {
            if (!pop.classList.contains('open')) return;
            PvPopover.positionFixed(pop, field, { mode: 'select' });
        }
        var unbindOutside = null, unbindViewport = null;
        function open() {
            if (input.disabled || pop.classList.contains('open')) return;
            sync(); render();
            document.body.appendChild(pop);
            wrap.classList.add('open');
            pop.classList.add('open');
            position();
            unbindOutside = PvPopover.onOutside(function (t) { return wrap.contains(t) || pop.contains(t); }, close, { type: 'pointerdown', capture: true });
            unbindViewport = PvPopover.onViewport(position);
        }
        function close() {
            wrap.classList.remove('open');
            pop.classList.remove('open');
            if (pop.parentNode) pop.parentNode.removeChild(pop);
            if (unbindOutside) { unbindOutside(); unbindOutside = null; }
            if (unbindViewport) { unbindViewport(); unbindViewport = null; }
        }
        field.addEventListener('click', function (e) {
            e.stopPropagation();
            if (wrap.classList.contains('open')) close(); else open();
        });
        input.__pvDateSync = sync;
        sync();
    }

    /* ---------------- 文件选择 ---------------- */
    function enhanceFile(input) {
        if (input.__pvFile) return;
        input.__pvFile = true;
        input.classList.add('pv-native-hidden');

        var wrap = make('div', 'pv-file');
        var btn = make('button', 'pv-btn pv-btn-outline pv-btn-sm', null); btn.type = 'button'; btn.textContent = '选择文件';
        var name = make('span', 'pv-file-name'); name.textContent = '未选择文件';
        wrap.appendChild(btn); wrap.appendChild(name);
        input.parentNode.insertBefore(wrap, input.nextSibling);

        btn.addEventListener('click', function () { input.click(); });
        input.addEventListener('change', function () {
            name.textContent = (input.files && input.files.length) ? input.files[0].name : '未选择文件';
        });
    }

    /* ---------------- 入口 ---------------- */
    function init(root) {
        root = root || document;
        if (!root.querySelectorAll) return;
        Array.prototype.forEach.call(root.querySelectorAll('select.pv-input, .pv-field select, .pv-modal .pv-field select'), enhanceSelect);
        Array.prototype.forEach.call(root.querySelectorAll('input[type="date"]'), enhanceDate);
        Array.prototype.forEach.call(root.querySelectorAll('input[type="file"]'), enhanceFile);
    }

    function sync(el) {
        if (!el) return;
        if (el.__pvSelectSync) el.__pvSelectSync();
        if (el.__pvDateSync) el.__pvDateSync();
    }

    global.PvControls = { init: init, sync: sync };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); });
    } else {
        init(document);
    }
})(window);
