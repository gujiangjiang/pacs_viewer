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

    // 阅片器保活：切到其他页面时不销毁，仅把根节点摘下暂存；返回时原样挂回并复用
    // 已解码帧缓存，从而瞬时恢复，避免重新取像 / 重新解码造成的整屏空白卡顿。
    var keptViewer = null;         // 暂存的 [data-pv="app"] 根节点
    var keptInstance = null;       // 对应的 PvViewer 实例
    var viewerMeta = null;         // 阅片壳层元数据（title/bodyClass/active/css）
    // 首屏即阅片页（如直链）：记录初始壳层元数据，供返回时恢复
    if (boot.page === 'viewer') {
        viewerMeta = { title: document.title, bodyClass: document.body.className, active: 'viewer', css: null };
    }

    // 资源去重：按「路径」归一化（忽略 ?v= 版本查询），避免同一文件被重复注入
    function assetKey(url) { return String(url || '').replace(/[?#].*$/, ''); }

    // 记录首页已由服务端加载的资源，避免重复注入
    Array.prototype.forEach.call(document.querySelectorAll('script[src]'), function (s) {
        loadedJs[assetKey(s.getAttribute('src'))] = true;
    });
    Array.prototype.forEach.call(document.querySelectorAll('link[rel="stylesheet"]'), function (l) {
        loadedCss[assetKey(l.getAttribute('href'))] = true;
    });

    /** 站内路由 URL：复用通用构造（PvUI.buildUrl），保证编码口径一致 */
    function route(page, params) {
        return PvUI.buildUrl(home, page, params);
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
    // 动态脚本去重：按 key 复用同一个加载 Promise，避免「已在加载中」被误判为「已加载」
    // 而让 initPage 早于脚本执行（表现为页面骨架出来了但没有初始化）。
    var jsPromises = {};
    function ensureJs(list) {
        var chain = Promise.resolve();
        (list || []).forEach(function (name) {
            var src = (boot.asset || (home.replace(/\/$/, '') + '/assets')) + '/js/' + name;
            var key = assetKey(src);
            if (loadedJs[key]) return;   // 首屏已由服务端引入
            chain = chain.then(function () {
                if (jsPromises[key]) return jsPromises[key];
                jsPromises[key] = new Promise(function (resolve) {
                    var s = document.createElement('script');
                    s.src = src;
                    s.onload = function () { loadedJs[key] = true; resolve(); };
                    s.onerror = function () { resolve(); };   // 失败也放行，避免永久等待
                    document.body.appendChild(s);
                });
                return jsPromises[key];
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

    function initPage(res, tries) {
        var page = res.page;
        if (page && global.PvPages && global.PvPages[page] && typeof global.PvPages[page].init === 'function') {
            try { global.PvPages[page].init(res.data || {}); } catch (e) { if (global.console) console.error(e); }
            return;
        }
        // 兜底：脚本尚未执行完成时延迟重试；若期间已切走则放弃
        tries = tries || 0;
        if (page && tries < 20 && currentPage === page) {
            setTimeout(function () { if (currentPage === page) initPage(res, tries + 1); }, 50);
        }
    }

    /** 销毁指定页面（释放其资源，如阅片器仍在后台预取的帧请求） */
    function teardown(page) {
        if (page && global.PvPages && global.PvPages[page] && typeof global.PvPages[page].destroy === 'function') {
            try { global.PvPages[page].destroy(); } catch (e) { if (global.console) console.error(e); }
        }
    }

    /** 取当前阅片器实例（保活复用） */
    function viewerPeek() {
        return (global.PvPages && global.PvPages.viewer && global.PvPages.viewer.peek) ? global.PvPages.viewer.peek() : null;
    }
    /** 离开影像查看：摘下根节点暂存，中止后台预取，但不销毁（保留已解码帧） */
    function detachViewer() {
        if (keptViewer) return;
        var node = main ? main.querySelector('[data-pv="app"]') : null;
        if (!node) return;
        keptViewer = node;
        keptInstance = viewerPeek();
        if (keptInstance && keptInstance.onHide) { try { keptInstance.onHide(); } catch (e) {} }
        if (node.parentNode) node.parentNode.removeChild(node);
    }
    /** 返回影像查看：挂回暂存节点并复用实例；无暂存则返回 false（走正常加载） */
    function reattachViewer() {
        if (!keptViewer || !main) return false;
        if (viewerMeta) {
            ensureCss(viewerMeta.css);
            if (viewerMeta.title) document.title = viewerMeta.title;
            document.body.className = viewerMeta.bodyClass || '';
            setActiveNav(viewerMeta.active);
        }
        main.innerHTML = '';
        main.appendChild(keptViewer);
        currentPage = 'viewer';
        var inst = keptInstance || viewerPeek();
        if (inst && inst.onShow) { try { inst.onShow(); } catch (e) {} }
        return true;
    }

    /** 渲染一个页面片段（不发起后端请求） */
    function apply(res) {
        if (!main) return;
        ensureCss(res.css);
        if (res.page === 'viewer') {
            viewerMeta = { title: res.title, bodyClass: res.bodyClass, active: res.active, css: res.css };
            if (reattachViewer()) return;   // 命中保活实例：直接用，不再重建
        }
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
    /**
     * 后台预热「已打开检查」的数据：整页刷新后，阅片器实例需重新拉取各检查，
     * 空闲时先把这些数据取回并落入 PvApi 的会话内缓存，点击「影像查看」即可秒开，
     * 避免强制刷新后短暂整屏空白。
     */
    function warmWorkspace() {
        if (!global.PvApi || !global.PvApi.study) return;
        var st;
        try { st = JSON.parse(global.sessionStorage.getItem('pacs_workspace_v1') || 'null'); } catch (e) { return; }
        if (!st || !st.studies || !st.studies.length) return;
        st.studies.forEach(function (s) {
            var uid = s && s.uid;
            if (!uid) return;
            try { global.PvApi.study(uid).catch(function () {}); } catch (e) {}
        });
    }
    function warmStaticShells() {
        ['search', 'viewer'].forEach(prefetchShell);
        warmWorkspace();
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

        // 已在当前静态页：重复点击同一标签直接忽略（避免无谓重渲染）；
        // 但影像查看带参（打开指定检查）时仍需续接打开，不能忽略。
        if (!opts.force && !withParams && page === currentPage && isStatic(page)) return;

        // 释放当前页面资源；影像查看改为「保活」：仅摘下暂存，不销毁，返回时瞬时恢复
        if (currentPage && currentPage !== page) {
            if (currentPage === 'viewer') detachViewer();
            else teardown(currentPage);
            currentPage = null;
        }

        // 影像查看：已在当前页且带参 → 直接续接打开（无需重建整页）
        if (page === 'viewer' && currentPage === 'viewer' && withParams) {
            var inst0 = viewerPeek();
            if (inst0 && inst0.openStudy) { inst0.openStudy(params.uid, params.mode || 'append'); return; }
        }

        // 影像查看：命中保活实例 → 原样挂回，零后端请求、零重建、保留已解码影像
        if (page === 'viewer' && keptViewer) {
            if (reattachViewer()) {
                var inst1 = keptInstance || viewerPeek();
                if (withParams && inst1 && inst1.openStudy) inst1.openStudy(params.uid, params.mode || 'append');
                return;
            }
        }

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

    /* ---------- 左上角品牌：整体可点击，软刷新当前页面 ---------- */
    function softRefresh() {
        // 访客阅片直链：SPA 刷新会丢失 uid，改用整页刷新以保留直链参数
        if (boot.guest) { global.location.reload(); return; }
        if (global.PvNav && global.PvNav.refresh) { global.PvNav.refresh(); return; }
        global.location.reload();
    }
    (function bindBrand() {
        var brand = document.getElementById('pvBrand');
        if (!brand) return;
        brand.addEventListener('click', function (e) { e.preventDefault(); softRefresh(); });
        brand.addEventListener('contextmenu', function (e) { e.preventDefault(); });   // 禁止右击
        brand.addEventListener('dragstart', function (e) { e.preventDefault(); });     // 禁止拖拽
    })();

    /* 顶部导航（患者查询 / 影像查看 / 管理设置）等同按钮：仅可点击，禁止拖拽与右击 */
    (function bindNav() {
        var nav = document.getElementById('pvNav');
        if (!nav) return;
        var hit = function (e) { return e.target && e.target.closest ? e.target.closest('a[data-nav]') : null; };
        nav.addEventListener('dragstart', function (e) { if (hit(e)) e.preventDefault(); });
        nav.addEventListener('contextmenu', function (e) { if (hit(e)) e.preventDefault(); });
    })();
})(window);
