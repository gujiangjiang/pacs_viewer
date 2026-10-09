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
        // 菜单使用 fixed 定位，避免被工具栏的 overflow 裁剪（定位算法见 PvPopover）
        function positionMenu(wrap, btn) {
            var panel = wrap.querySelector('.pv-menu');
            if (!panel) return;
            PvPopover.positionFixed(panel, btn, { mode: 'menu' });
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

        // 点击工具栏之外的区域收起菜单（复用通用外部点击助手）
        this._unbindDocClick = PvPopover.onOutside(function (t) { return el.contains(t); }, closeMenus, { type: 'click', capture: false });
        // 视口变化时收起，避免 fixed 菜单错位（复用通用视口监听助手）
        this._unbindViewport = PvPopover.onViewport(function () { closeMenus(); });
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
        if (this._unbindDocClick) { this._unbindDocClick(); this._unbindDocClick = null; }
        if (this._unbindViewport) { this._unbindViewport(); this._unbindViewport = null; }
    };
    global.PvToolbar = PvToolbar;
})(window);
