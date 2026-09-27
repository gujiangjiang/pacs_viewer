/* ============================================================
 * assets/js/modules/toolbar.js — 工具栏模块
 * 绑定工具 / 预设窗 / 动作按钮，并向控制器暴露同步状态的方法。
 * ============================================================ */
(function (global) {
    'use strict';
    function PvToolbar(el, handlers) {
        this.el = el; this.h = handlers || {};
        var self = this;
        if (!el) return;
        el.addEventListener('click', function (e) {
            // 子 Tab 切换（窗宽窗位 / 浏览 / 测量 / 变换 / 工具）
            var tab = e.target.closest ? e.target.closest('[data-pv-tab]') : null;
            if (tab && el.contains(tab)) {
                var key = tab.getAttribute('data-pv-tab');
                Array.prototype.forEach.call(el.querySelectorAll('[data-pv-tab]'), function (x) { x.classList.toggle('active', x === tab); });
                Array.prototype.forEach.call(el.querySelectorAll('[data-pv-pane]'), function (p) { p.classList.toggle('active', p.getAttribute('data-pv-pane') === key); });
                return;
            }
            var node = e.target;
            while (node && node !== el && !node.getAttribute('data-pv-tool') && !node.getAttribute('data-pv-preset') && !node.getAttribute('data-pv-act')) {
                node = node.parentNode;
            }
            if (!node || node === el) return;
            var tool = node.getAttribute('data-pv-tool');
            var preset = node.getAttribute('data-pv-preset');
            var act = node.getAttribute('data-pv-act');
            if (tool && self.h.onTool) self.h.onTool(tool);
            else if (preset && self.h.onPreset) self.h.onPreset(preset);
            else if (act && self.h.onAction) self.h.onAction(act);
        });
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
    global.PvToolbar = PvToolbar;
})(window);
