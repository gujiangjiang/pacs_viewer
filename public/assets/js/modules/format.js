/* ============================================================
 * assets/js/modules/format.js — 通用格式化助手（PvFmt）
 * ============================================================ */
(function (global) {
    'use strict';

    /** 两位补零 */
    function pad(n) { return (n < 10 ? '0' : '') + n; }

    /** Date → YYYY-MM-DD（本地时区） */
    function date(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

    global.PvFmt = { pad: pad, date: date };
})(window);
