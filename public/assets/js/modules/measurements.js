/* ============================================================
 * assets/js/modules/measurements.js — 测量与 ROI 计算模块（纯函数）
 * ============================================================ */
(function (global) {
    'use strict';
    var clamp = PvRender.clamp;   // 复用通用钳位助手
    function distPx(a, b) { return Math.hypot(a.x - b.x, a.y - b.y); }
    function distMM(a, b, ps) { return distPx(a, b) * (ps || 0.7); }
    function angleDeg(a, b, c) {
        var v1 = { x: a.x - b.x, y: a.y - b.y }, v2 = { x: c.x - b.x, y: c.y - b.y };
        var d = (v1.x * v2.x + v1.y * v2.y) / ((Math.hypot(v1.x, v1.y) * Math.hypot(v2.x, v2.y)) || 1);
        return Math.acos(clamp(d, -1, 1)) * 180 / Math.PI;
    }
    global.PvMeasure = { distPx: distPx, distMM: distMM, angleDeg: angleDeg };
})(window);
