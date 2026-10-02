/* ============================================================
 * assets/js/spa.js — 站内 AJAX 局部刷新导航（地址栏保持不变）
 * ============================================================
 * · 拦截带 data-nav 的站内链接，替换 #pvMain（地址栏不变）；
 * · 「壳层静态」页面（患者查询 / 影像查看）的片段在**前端缓存**：命中缓存时
 *   切换为纯前端操作，不发任何后端请求、不显示变灰遮罩，因此在高负载下也
 *   绝不卡顿（影像数据仍由各页自身按需走接口获取）；
 * · 首次进入某静态页前，于空闲时后台预取其片段与脚本，使首次点击也即时；
 * · 其他页面（如管理设置）仍走 AJAX 拉取，但仅在超过 120ms 才显示忙碌遮罩，
 *   避免快切换时的闪烁；
 * · 切换页面前调用上一页 PvPages.<page>.destroy() 释放资源（含中止阅片器预取）。
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

    // 壳层静态页：内容不随请求变化（数据由页面自身经接口获取），可长期缓存、零后端切换
    var STATIC_PAGES = { search: 1, viewer: 1 };
    var pageCache = {};   // page -> 片段数据（含 html/css/js/data）

    // 资源去重：按「路径」归一化（忽略 ?v= 版本查询），避免同一文件被重复注入
    function assetKey(url) { return String(url || '').replace(/[?#].*$/, ''); }

    // 记录首页已由服务端加载的资源，避免重复注入
    Array.prototype.forEach.call(document.querySelectorAll('script[src]'), function (s) {
        loadedJs[assetKey(s.getAttribute('src'))] = true;
    });
    Array.prototype.forEach.call(document.querySelectorAll('link[rel="stylesheet"]'), function (l) {
        loadedCss[assetKey(l.getAttribute('href'))] = true;
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
        (list || []).forEach(function (name) {
            var href = (boot.asset || (home.replace(/\/$/, '') + '/assets')) + '/css/' + name;
            var key = assetKey(href);
            if (loadedCss[key]) return;
            loadedCss[key] = true;
            var link = document.createElement('link');
            link.rel = 'stylesheet'; link.href = href;
            document.head.appendChild(link);
        });
    }
    function ensureJs(list) {
        var chain = Promise.resolve();
        (list || []).forEach(function (name) {
            var src = (boot.asset || (home.replace(/\/$/, '') + '/assets')) + '/js/' + name;
            var key = assetKey(src);
            if (loadedJs[key]) return;
            loadedJs[key] = true;
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

    /** 渲染一个页面片段（不发起后端请求） */
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

    function isStatic(page) { return !!STATIC_PAGES[page]; }

    /** 是否携带有效参数（如打开指定检查 uid）——带参数时不吃缓存、须实时取数 */
    function hasParams(params) {
        if (!params) return false;
        for (var k in params) {
            if (params[k] !== undefined && params[k] !== null && params[k] !== '') return true;
        }
        return false;
    }

    /** 静态度缓存副本：清空一次性 flash，避免每次切换重复弹出提示 */
    function cacheCopy(data) {
        var c = {};
        for (var k in data) c[k] = data[k];
        c.flash = '';
        return c;
    }

    /** 空闲时后台预取静态页片段与脚本，使首次点击也即时（不改变当前页面） */
    function prefetchShell(page) {
        if (!isStatic(page) || pageCache[page]) return;
        fetch(route(page), {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (res && res.code === 200 && res.data) {
                pageCache[page] = cacheCopy(res.data);
                ensureCss(res.data.css);
                ensureJs(res.data.js);   // 顺便预载脚本，首次切换无需再等
            }
        }).catch(function () {});
    }
    function warmStaticShells() {
        ['search', 'viewer'].forEach(prefetchShell);
    }
    if (global.requestIdleCallback) {
        global.requestIdleCallback(function () { warmStaticShells(); }, { timeout: 1500 });
    } else {
        setTimeout(warmStaticShells, 300);
    }

    function go(page, params, opts) {
        if (busy) return;
        opts = opts || {};

        var withParams = hasParams(params);

        // 已在当前静态页：重复点击同一标签直接忽略（避免无谓重渲染）
        if (!opts.force && !withParams && page === currentPage && isStatic(page)) return;

        // 释放当前页面资源（含中止阅片器在途预取），避免占用连接
        if (currentPage && currentPage !== page) { teardown(currentPage); currentPage = null; }

        // 静态页命中缓存（且无参数）：纯前端切换，零后端请求、零遮罩、零延迟
        if (!withParams && isStatic(page) && pageCache[page]) { apply(pageCache[page]); return; }

        var url = route(page, params);
        busy = true;
        // 仅在请求超过 120ms 才显示忙碌遮罩，避免快切换闪烁
        var busyTimer = setTimeout(function () { document.body.classList.add('pv-nav-busy'); }, 120);
        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin'
        }).then(function (r) {
            if (r.status === 401 || r.redirected) throw new Error('need-login');
            return r.json();
        }).then(function (res) {
            busy = false; clearTimeout(busyTimer); document.body.classList.remove('pv-nav-busy');
            if (!res || res.code !== 200 || !res.data) { global.location.href = url; return; }
            // 仅缓存「无参数」的静态壳，带参导航（打开指定检查）不得覆盖通用壳
            if (isStatic(page) && !withParams) pageCache[page] = cacheCopy(res.data);
            apply(res.data);
        }).catch(function () {
            busy = false; clearTimeout(busyTimer); document.body.classList.remove('pv-nav-busy');
            global.location.href = url;   // 回退为整页导航，保证可用
        });
    }

    /** 重新加载当前页面（表单提交后刷新局部内容；清缓存以取回最新数据） */
    function refresh() {
        var page = currentPage || boot.page;
        if (!page) return;
        if (pageCache[page]) delete pageCache[page];
        go(page, null, { force: true });
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
