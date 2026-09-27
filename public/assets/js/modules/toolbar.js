/* ============================================================
 * assets/js/modules/toolbar.js — 工具栏模块
 * 大图标按钮 + 下拉菜单；绑定工具 / 预设 / 动作，并暴露状态同步方法。
 * 菜单：点击按钮开关，点击其他区域或选择任一项后自动收起。
 * ============================================================ */
(function (global) {
    'use strict';
    function PvToolbar(el, handlers) {
        this.el = el; this.h = handlers || {};
        var self = this;
        if (!el) return;

        function closeMenus(except) {
            Array.prototype.forEach.call(el.querySelectorAll('.pv-menu-wrap.open'), function (w) {
                if (w !== except) w.classList.remove('open');
            });
        }
        function toggleMenu(btn) {
            var wrap = btn.closest ? btn.closest('.pv-menu-wrap') : null;
            if (!wrap) return;
            var wasOpen = wrap.classList.contains('open');
            closeMenus();
            if (!wasOpen) {
                wrap.classList.add('open');
                positionMenu(wrap, btn);
            }
        }
        // 菜单使用 fixed 定位，避免被工具栏的 overflow 裁剪
        function positionMenu(wrap, btn) {
            var panel = wrap.querySelector('.pv-menu');
            if (!panel) return;
            var r = btn.getBoundingClientRect();
            var pw = panel.offsetWidth, ph = panel.offsetHeight;
            var left = Math.max(8, Math.min(r.left, window.innerWidth - pw - 8));
            var top = r.bottom + 6;
            if (top + ph > window.innerHeight - 8) top = Math.max(8, r.top - ph - 6);
            panel.style.left = left + 'px';
            panel.style.top = top + 'px';
        }
        this.closeMenus = function () { closeMenus(); };

        el.addEventListener('click', function (e) {
            // 下拉菜单按钮
            var menuBtn = e.target.closest ? e.target.closest('[data-pv-menu]') : null;
            if (menuBtn && el.contains(menuBtn)) { e.stopPropagation(); toggleMenu(menuBtn); return; }

            // 工具 / 预设 / 动作 / 布局
            var node = e.target;
            while (node && node !== el && !node.getAttribute('data-pv-tool') && !node.getAttribute('data-pv-preset') && !node.getAttribute('data-pv-act') && !node.getAttribute('data-pv-layout')) {
                node = node.parentNode;
            }
            if (!node || node === el) { closeMenus(); return; }
            var tool = node.getAttribute('data-pv-tool');
            var preset = node.getAttribute('data-pv-preset');
            var act = node.getAttribute('data-pv-act');
            var layout = node.getAttribute('data-pv-layout');
            if (layout && self.h.onLayout) self.h.onLayout(layout);
            else if (tool && self.h.onTool) self.h.onTool(tool);
            else if (preset && self.h.onPreset) self.h.onPreset(preset);
            else if (act && self.h.onAction) self.h.onAction(act);
            closeMenus();   // 选择任意项后收起菜单
        });

        // 点击工具栏之外的区域收起菜单
        this._docClick = function (ev) { if (!el.contains(ev.target)) closeMenus(); };
        document.addEventListener('click', this._docClick);
        // 视口变化时收起，避免 fixed 菜单错位
        this._onViewport = function () { closeMenus(); };
        window.addEventListener('resize', this._onViewport);
        window.addEventListener('scroll', this._onViewport, true);
    }
    PvToolbar.prototype.sync = function (state) {
        if (!this.el) return;
        Array.prototype.forEach.call(this.el.querySelectorAll('[data-pv-tool]'), function (el) {
            el.classList.toggle('active', el.getAttribute('data-pv-tool') === state.tool);
        });
        Array.prototype.forEach.call(this.el.querySelectorAll('[data-pv-act]'), function (el) {
            var a = el.getAttribute('data-pv-act');
            if (a === 'invert') el.classList.toggle('active', !!state.invert);
            else if (a === 'flip-h') el.classList.toggle('active', !!state.flipH);
            else if (a === 'flip-v') el.classList.toggle('active', !!state.flipV);
        });
    };
    PvToolbar.prototype.destroy = function () {
        if (this._docClick) document.removeEventListener('click', this._docClick);
        if (this._onViewport) {
            window.removeEventListener('resize', this._onViewport);
            window.removeEventListener('scroll', this._onViewport, true);
        }
    };
    global.PvToolbar = PvToolbar;
})(window);
