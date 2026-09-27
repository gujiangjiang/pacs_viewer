/* ============================================================
 * assets/js/api.js — 外部接口请求封装（模块）
 * 所有患者 / 检查数据统一经此模块从后端 PACS 接口获取。
 * ============================================================ */
(function (global) {
    'use strict';
    var boot = global.PV_BOOT || {};
    var base = boot.api || '?r=api';

    function buildUrl(sub, params) {
        var u = base.replace(/r=api(\b|$)/, 'r=api/' + sub);
        var q = [];
        params = params || {};
        for (var k in params) {
            if (params[k] === undefined || params[k] === null) continue;
            q.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
        }
        if (q.length) u += (u.indexOf('?') < 0 ? '?' : '&') + q.join('&');
        return u;
    }
    function get(sub, params) {
        return fetch(buildUrl(sub, params), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) {
                if (r.status === 401) {
                    var home = boot.home || '/';
                    location.href = home + (home.indexOf('?') < 0 ? '?' : '') + 'r=login';
                    throw new Error('未登录');
                }
                return r.json();
            });
    }

    global.PvApi = {
        search: function (q) { return get('search', { q: q }); },
        study:  function (uid) { return get('study', { uid: uid }); },
        ping:   function () { return get('ping', {}); },
        viewerUrl: function (uid) {
            var u = boot.viewer || '?r=viewer';
            return u + (u.indexOf('?') < 0 ? '?' : '&') + 'uid=' + encodeURIComponent(uid);
        }
    };
})(window);
