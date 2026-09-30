/* ============================================================
 * assets/js/spa.js — 站内 AJAX 局部刷新导航（地址栏保持不变）
 * ============================================================
 * · 拦截带 data-nav 的站内链接，通过 fetch 拉取 JSON 片段并替换 #pvMain；
 * · 地址栏始终不变（不做 history 跳转），外部/直接链接仍可整页进入；
 * · 按页加载所需 CSS / JS，并调用 PvPages.<page>.init(data) 初始化；
 * · 切换页面前调用上一页 PvPages.<page>.destroy() 释放资源。
 * ============================================================ */
(function (global) {
    'use strict';
    var boot = global.PV_BOOT || {};
    var home = boot.home || '/';
    var main = document.getElementById('pvMain');
    var loadedCss = {};
    var loadedJs = {};
    var currentPage = boot.page || null;
    var busy = false;

    // 记录首页已由服务端加载的资源，避免重复注入
    Array.prototype.forEach.call(document.querySelectorAll('script[src]'), function (s) {
        loadedJs[s.getAttribute('src')] = true;
    });
    Array.prototype.forEach.call(document.querySelectorAll('link[rel="stylesheet"]'), function (l) {
        loadedCss[l.getAttribute('href')] = true;
    });

    function route(page, params) {
        var url = home + (home.indexOf('?') < 0 ? '?' : '') + 'r=' + encodeURIComponent(page);
        params = params || {};
        for (var k in params) {
            if (params[k] === undefined || params[k] === null || params[k] === '') continue;
            url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
        }
        return url;
    }

    function ensureCss(list) {
        (list || []).forEach(function (name, i) {
            var href = (boot.asset || (home.replace(/\/$/, '') + '/assets')) + '/css/' + name;
            if (loadedCss[href]) return;
            var link = document.createElement('link');
            link.rel = 'stylesheet'; link.href = href;
            document.head.appendChild(link);
            loadedCss[href] = true;
        });
    }
    function ensureJs(list) {
        var chain = Promise.resolve();
        (list || []).forEach(function (name) {
            var src = (boot.asset || (home.replace(/\/$/, '') + '/assets')) + '/js/' + name;
            if (loadedJs[src]) return;
            loadedJs[src] = true;
            chain = chain.then(function () {
                return new Promise(function (resolve) {
                    var s = document.createElement('script');
                    s.src = src;
                    s.onload = resolve;
                    s.onerror = function () { resolve(); };
                    document.body.appendChild(s);
                });
            });
        });
        return chain;
    }
    function setActiveNav(active) {
        var links = document.querySelectorAll('#pvNav a[data-nav]');
        Array.prototype.forEach.call(links, function (a) {
            a.classList.toggle('active', a.getAttribute('data-nav') === active);
        });
    }

    function initPage(res) {
        var page = res.page;
        if (page && global.PvPages && global.PvPages[page] && typeof global.PvPages[page].init === 'function') {
            try { global.PvPages[page].init(res.data || {}); } catch (e) { if (global.console) console.error(e); }
        }
    }

    /** 销毁指定页面（释放其资源，如阅片器仍在后台预取的帧请求） */
    function teardown(page) {
        if (page && global.PvPages && global.PvPages[page] && typeof global.PvPages[page].destroy === 'function') {
            try { global.PvPages[page].destroy(); } catch (e) { if (global.console) console.error(e); }
        }
    }

    function apply(res) {
        if (!main) return;
        ensureCss(res.css);
        main.innerHTML = res.html;
        if (res.title) document.title = res.title;
        document.body.className = res.bodyClass || '';
        setActiveNav(res.active);
        currentPage = res.page;
        var done = function () { initPage(res); };
        Promise.resolve(ensureJs(res.js)).then(done).catch(done);
    }

    function go(page, params, opts) {
        if (busy) return;
        opts = opts || {};
        var url = route(page, params);
        busy = true;
        document.body.classList.add('pv-nav-busy');
        // 先释放当前页面资源：阅片器可能在后台预取整条序列，占用浏览器连接会拖慢
        // 本次导航请求（表现为点标签后页面变暗数秒才切换）。提前销毁并中止其在途请求。
        if (currentPage && currentPage !== page) { teardown(currentPage); currentPage = null; }
        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin'
        }).then(function (r) {
            if (r.status === 401 || r.redirected) throw new Error('need-login');
            return r.json();
        }).then(function (res) {
            busy = false; document.body.classList.remove('pv-nav-busy');
            if (!res || res.code !== 200 || !res.data) { global.location.href = url; return; }
            apply(res.data);
        }).catch(function () {
            busy = false; document.body.classList.remove('pv-nav-busy');
            global.location.href = url;   // 回退为整页导航，保证可用
        });
    }

    /** 重新加载当前页面（表单提交后刷新局部内容） */
    function refresh() {
        var page = currentPage || boot.page;
        if (page) go(page);
    }

    // 事件委托：拦截站内 data-nav 链接点击
    document.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('a[data-nav]') : null;
        if (!a) return;
        if (a.hasAttribute('data-no-nav')) return;
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button) return;
        var page = a.getAttribute('data-nav');
        if (!page) return;
        e.preventDefault();
        go(page);
    });

    // 干净地址栏：常规页（非阅片直链）加载后清除 ?r=...，仅保留站点根路径
    if (boot.page && boot.page !== 'viewer') {
        try { global.history.replaceState(null, '', home); } catch (e) {}
    }

    global.PvNav = { go: go, refresh: refresh, route: route, current: function () { return currentPage; } };
})(window);
