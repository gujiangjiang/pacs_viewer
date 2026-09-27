/* ============================================================
 * assets/js/pwa.js — 注册 Service Worker（PWA 离线 / 可安装）
 * ============================================================ */
(function (global) {
    'use strict';
    if (!('serviceWorker' in navigator)) return;
    var boot = global.PV_BOOT || {};
    if (!boot.sw) return;
    global.addEventListener('load', function () {
        navigator.serviceWorker.register(boot.sw, { scope: boot.scope || './' }).catch(function (e) {
            if (global.console) console.warn('[PWA] Service Worker 注册失败：', e);
        });
    });
})(window);
