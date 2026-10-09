/* ============================================================
 * assets/js/modules/popover.js — 通用弹层定位与关闭助手（PvPopover）
 * ============================================================
 * 收敛各弹层（工具栏菜单 / 自定义下拉 / 日期选择 / 右键菜单 / 管理端下拉）中
 * 重复的「fixed 定位 + 外部点击关闭 + 视口变化关闭」样板：
 *   PvPopover.positionFixed(panel, anchor, opts)  定位（两种既有算法，逐像素等价）
 *   PvPopover.onViewport(fn)                      监听 resize / 滚动（返回解绑函数）
 *   PvPopover.onOutside(isInside, onClose, opts)  外部点击 / 按下关闭（返回解绑函数）
 * 仅提供工具方法，不接管各弹层的开关状态，调用方行为保持不变。
 * ============================================================ */
(function (global) {
    'use strict';

    /**
     * fixed 定位弹层。
     * @param {Element} panel  弹层元素（需已可见，宽高可测）
     * @param {Element} anchor 触发元素
     * @param {object} [opts]
     *   mode        'menu'（工具栏 / 右键类：水平单向钳位 + 垂直翻转）
     *               'select'（下拉 / 日期：水平越界右缘回退 + 垂直越界优先翻上，结果取整）
     *   gap         与触发元素的间距（默认 6）
     *   margin      视口边缘留白（默认 8）
     *   matchWidth  select 模式下是否对齐触发元素宽度（默认 false）
     */
    function positionFixed(panel, anchor, opts) {
        opts = opts || {};
        var gap = opts.gap != null ? opts.gap : 6;
        var margin = opts.margin != null ? opts.margin : 8;
        var r = anchor.getBoundingClientRect();
        if (opts.mode === 'menu') {
            var pw = panel.offsetWidth, ph = panel.offsetHeight;
            var left = Math.max(margin, Math.min(r.left, window.innerWidth - pw - margin));
            var top = r.bottom + gap;
            if (top + ph > window.innerHeight - margin) top = Math.max(margin, r.top - ph - gap);
            panel.style.left = left + 'px';
            panel.style.top = top + 'px';
            return;
        }
        if (opts.matchWidth) panel.style.width = Math.round(r.width) + 'px';
        var w = panel.offsetWidth, h = panel.offsetHeight;
        var vw = document.documentElement.clientWidth, vh = document.documentElement.clientHeight;
        var l = r.left, t = r.bottom + gap;
        if (l + w > vw - margin) l = Math.max(margin, vw - margin - w);
        if (t + h > vh - margin) { var a = r.top - gap - h; t = a >= margin ? a : Math.max(margin, vh - margin - h); }
        panel.style.left = Math.round(l) + 'px';
        panel.style.top = Math.round(t) + 'px';
    }

    /** 监听视口变化（resize + 捕获滚动），返回解绑函数 */
    function onViewport(fn) {
        window.addEventListener('resize', fn);
        window.addEventListener('scroll', fn, true);
        return function () {
            window.removeEventListener('resize', fn);
            window.removeEventListener('scroll', fn, true);
        };
    }

    /**
     * 外部事件（默认 pointerdown 捕获）关闭弹层；可选 Escape 关闭。
     * @param {Function} isInside 判定事件目标是否属于弹层
     * @param {Function} onClose
     * @param {object} [opts] { type, capture, key }
     * @return {Function} 解绑函数
     */
    function onOutside(isInside, onClose, opts) {
        opts = opts || {};
        var type = opts.type || 'pointerdown';
        var capture = opts.capture !== false;
        var handler = function (e) { if (!isInside(e.target)) onClose(); };
        document.addEventListener(type, handler, capture);
        var onKey = null;
        if (opts.key) {
            onKey = function (e) { if (e.key === 'Escape') onClose(); };
            document.addEventListener('keydown', onKey, true);
        }
        return function () {
            document.removeEventListener(type, handler, capture);
            if (onKey) document.removeEventListener('keydown', onKey, true);
        };
    }

    global.PvPopover = { positionFixed: positionFixed, onViewport: onViewport, onOutside: onOutside };
})(window);
