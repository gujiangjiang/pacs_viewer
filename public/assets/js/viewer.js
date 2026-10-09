/* ============================================================
 * assets/js/viewer.js — 影像查看工作区控制器（PvViewer）
 * ============================================================
 * 多检查 / 多窗格布局、激活窗格、序列栏、会话记忆与生命周期装配。
 * 单窗格能力见 modules/pane.js（PvPane）；工具 / 预设见 viewer-actions.js；
 * 键盘见 viewer-shortcuts.js；宿主指令桥见 viewer-host.js；
 * 工作区（打开 / 恢复 / 渲染）见 viewer-workspace.js。
 * ============================================================ */
(function (global) {
    'use strict';

    var clamp = PvRender.clamp;   // 复用通用钳位助手
    var esc = PvUI.esc;           // 复用通用转义助手

    /* ============================================================
     * PvViewer —— 工作区控制器
     * ============================================================ */
    function PvViewer(root, opts) {
        opts = opts || {};
        this.root = root;
        this.route = { uid: opts.uid || '', uids: opts.uids || [], mode: opts.mode || 'append' };
        this.about = opts.about || {};
        this.guest = !!opts.guest;   // 链接访客模式：仅临时阅片，无登录 / 无搜索 / 无关闭
        // 宿主指令桥允许来源：显式配置的 hostOrigin 优先；未配置时信任「嵌入本页的
        // 直接父窗口」与同源来源（见 viewer-host.js 的 _isTrustedHost），兼容独立域的 iframe 宿主。
        this.hostOrigin = opts.hostOrigin || '';
        this.embedded = !!opts.embedded;   // 是否被 iframe 嵌入（前端 self!==top 为最终判定）
        this.studyLimit = this.guest ? 1 : clamp(parseInt(opts.limit, 10) || 5, 3, 10);
        if (root && root.classList) root.classList.toggle('pv-guest', this.guest);
        this.q = function (k) { return root.querySelector('[data-pv="' + k + '"]'); };
        this.panesEl = this.q('panes');
        this.toolbarEl = this.q('toolbar');
        this.ctxEl = this.q('ctxmenu');
        this.filmstripEl = this.q('filmstrip');
        this.seriesListEl = this.q('serieslist');
        this.closeAllEl = this.q('closeall');
        this.splitterEl = this.q('splitter');
        this.sidebar = new PvSidebar(this.seriesListEl);
        this.ws = { studies: [] };
        // 序列操作痕迹（会话内按序列保留）；访客每次进入均为全新状态，不读取/写入 sessionStorage
        this.seriesMap = this.guest ? {} : (global.PvViewerSession ? global.PvViewerSession.loadSeriesMap() : {});
        this.panes = [];
        this.active = 0;
        this.tool = 'wl';
        this.layout = '1';
        this.toolbar = new PvToolbar(this.toolbarEl, {
            onTool: this.setTool.bind(this),
            onPreset: this.setPreset.bind(this),
            onAction: this.doAction.bind(this),
            onLayout: this.setLayoutByKey.bind(this)
        });
        this.applyAccessMode();
        this._bindCloseAll();
        this._bindSplitter();
        this._bindCtxMenu();
        this._disableChromeContext();
        this.restoreSidebarWidth();
        this._bindWindowResize();
        this._bindPersistFlush();
        this._bindKeys();
        this._bindHostCommands();
        this.setLayout('1', true);
        this.boot();
    }
    PvViewer.prototype.activePane = function () { return this.panes[this.active] || null; };
    PvViewer.prototype.study = function (uid) { for (var i = 0; i < this.ws.studies.length; i++) if (this.ws.studies[i].uid === uid) return this.ws.studies[i]; return null; };
    PvViewer.prototype.indexOf = function (uid) { for (var i = 0; i < this.ws.studies.length; i++) if (this.ws.studies[i].uid === uid) return i; return -1; };
    PvViewer.prototype._bindWindowResize = function () {
        var self = this;
        // 同一动画帧内合并多次 resize（复用通用 rAF 节流）
        this._resizeThrottled = PvRender.rafThrottle(function () {
            self.panes.forEach(function (p) { p.resize(); p.render(); });
        });
        this._onWinResize = function () {
            if (!self.isAttached()) return;   // 被 SPA 隐藏期间无需重排重绘
            self._resizeThrottled();
        };
        window.addEventListener('resize', this._onWinResize);
    };
    /** 阅片器根节点当前是否在文档中（SPA 保活时可能被暂时摘下） */
    PvViewer.prototype.isAttached = function () {
        if (!this.root) return false;
        return this.root.isConnected !== undefined ? this.root.isConnected : document.body.contains(this.root);
    };
    /**
     * 被 SPA 切到其他页面时调用：中止后台预取、释放连接，避免拖慢其他页面；
     * 已解码的首帧仍保留在内存，返回时可瞬时重现。
     */
    PvViewer.prototype.onHide = function () {
        var self = this;
        this.panes.forEach(function (p) {
            if (p && p._abortFetches) p._abortFetches();
            if (self.savePaneState) self.savePaneState(p);   // 落盘当前序列的操作痕迹
            if (p && p.shedFrames) p.shedFrames();           // 收缩内存，仅保留当前帧
        });
        if (this.persistNow) this.persistNow();
    };
    /** 重新显示时调用：同步尺寸与重绘，并续接当前序列预取 */
    PvViewer.prototype.onShow = function () {
        this.panes.forEach(function (p) { p.resize(); p.render(); });
        this.renderSidebar();
        var p = this.activePane();
        var ser = p && p.curSeries && p.curSeries();
        if (p && ser && p.prefetch) p.prefetch(ser);
    };
    /** 页面卸载前立即落盘，避免防抖窗口内丢失最后的会话状态 */
    PvViewer.prototype._bindPersistFlush = function () {
        var self = this;
        this._onUnload = function () { if (self._persistTimer) { clearTimeout(self._persistTimer); self._persistTimer = null; self.persistNow(); } };
        window.addEventListener('beforeunload', this._onUnload);
    };

    /* ---------- 布局 ---------- */
    PvViewer.prototype.setLayoutByKey = function (key) { this.setLayout(key, false); };
    PvViewer.prototype.setLayout = function (layout, initial) {
        if (['1', '2h', '2v', '4'].indexOf(layout) < 0) layout = '1';
        this.layout = layout;
        var cols = '1fr', rows = '1fr';
        if (layout === '2h') cols = '1fr 1fr';
        else if (layout === '2v') rows = '1fr 1fr';
        else if (layout === '4') { cols = '1fr 1fr'; rows = '1fr 1fr'; }
        this.panesEl.style.gridTemplateColumns = cols;
        this.panesEl.style.gridTemplateRows = rows;
        this.panesEl.classList.toggle('multi', layout !== '1');
        var need = layout === '1' ? 1 : (layout === '4' ? 4 : 2);
        while (this.panes.length > need) { var p = this.panes.pop(); p.destroy(); }
        while (this.panes.length < need) { this.panes.push(new PvPane(this)); }
        if (this.active >= this.panes.length) this.active = 0;
        this.panes.forEach(function (p, i) { p.el.classList.toggle('active', i === this.active); }, this);
        this.syncLayoutMenu();
        if (!initial) { this.renderSidebar(); this.updatePresetMenu(); this.refreshControlState(); this.persist(); }
    };
    PvViewer.prototype.syncLayoutMenu = function () {
        if (!this.toolbarEl) return;
        Array.prototype.forEach.call(this.toolbarEl.querySelectorAll('[data-pv-layout]'), function (b) {
            b.classList.toggle('active', b.getAttribute('data-pv-layout') === this.layout);
        }, this);
    };
    PvViewer.prototype.setActivePane = function (i) {
        if (i < 0 || i >= this.panes.length) return;
        if (this.active === i) return;
        this.active = i;
        this.panes.forEach(function (p, k) { p.el.classList.toggle('active', k === i); });
        this.renderSidebar();
        this.syncToolbar();
        this.updatePresetMenu();
        this.refreshControlState();
        this.persist();
    };

    /* ---------- 工具栏 ---------- */
    PvViewer.prototype.syncToolbar = function () {
        var p = this.activePane(), st = p ? p.st : { invert: false, flipH: false, flipV: false };
        this.toolbar.sync({ tool: this.tool, invert: st.invert, flipH: st.flipH, flipV: st.flipV });
    };
    PvViewer.prototype.afterPaneLoad = function (p) {
        this.renderSidebar();
        this.syncToolbar();
        this.updatePresetMenu();
        this.refreshControlState();
        this.persist();
    };

    /* ---------- 日志 ---------- */
    PvViewer.prototype.logEvent = function (action, extra) {
        if (this.guest) return;   // 访客不写数据库（操作日志）
        if (!window.PvApi || !PvApi.log) return;
        var p = this.activePane(), d = (p && p.data() && p.data().data) || {}, pt = d.patient || {}, s = d.study || {};
        var info = (pt.name || '') + ' / ' + (s.modality || '') + ' / ' + (s.description || '');
        PvApi.log(action, extra ? (info + ' / ' + extra) : info);
    };

    /* ---------- 关于 / 退出 / 直链 ---------- */
    PvViewer.prototype.showAbout = function () {
        if (!window.PvModal) return;
        var a = this.about || {};
        var html = '<div class="pv-about">'
            + '<img class="pv-about-icon" src="' + esc(a.icon || '') + '" width="96" height="96" alt="软件图标">'
            + '<h3 class="pv-about-name">' + esc(a.name || 'PACS 影像浏览器') + '</h3>'
            + '<div class="pv-about-ver">版本 v' + esc(a.version || '') + '</div>'
            + '<div class="pv-about-lic">授权给　<b>' + esc(a.hospital || '默认医院') + '</b></div>'
            + '</div>';
        window.PvModal.open({ title: '关于', body: html });
    };
    PvViewer.prototype.confirmExit = function () {
        if (this.guest) return;   // 访客不允许关闭（无关闭按钮）
        var self = this;
        var go = function () { self.closeAll(); if (window.PvUI) PvUI.clearSessionState(); if (window.PvNav) window.PvNav.go('search'); };
        if (!this.ws.studies.length) { go(); return; }
        if (window.PvModal) {
            PvModal.confirm({ title: '关闭影像查看', message: '关闭将清空全部已打开的检查与检索记录，并返回患者查询，确认关闭？', okText: '关闭', danger: true })
                .then(function (ok) { if (ok) go(); });
        } else go();
    };
    PvViewer.prototype.copyDirectLink = function () {
        var p = this.activePane();
        var uid = (p && p.st.uid) || this.route.uid;
        if (!uid) { if (p) p.setStatus('没有可复制的检查'); return; }
        var link = window.PvNav ? window.PvNav.route('viewer', { uid: uid }) : '';
        // 补全为绝对地址：route() 只返回站内路径（如 /?r=viewer&uid=...），
        // 直接复制缺少「协议 + 主机 + 部署前缀」的前半段，粘贴到外部无法打开。
        if (link && link.charAt(0) === '/') link = global.location.origin + link;
        // 提示语不附带完整链接，避免窄屏被截断
        var done = function (ok) { if (p) p.setStatus(ok === false ? '复制失败，请手动复制地址' : '已复制阅片直链'); };
        PvUI.copy(link).then(done, function () { done(false); });
    };

    /* ---------- 关闭全部 / 分隔条 / 禁用右击 ---------- */
    PvViewer.prototype._bindCloseAll = function () {
        var self = this, btn = this.closeAllEl; if (!btn || this.guest) return;
        btn.addEventListener('click', function () {
            if (!self.ws.studies.length) return;
            var run = function () { self.closeAll(); };
            if (window.PvModal) PvModal.confirm({ title: '关闭全部', message: '确认清空影像视图中的全部检查序列？', okText: '关闭全部', danger: true }).then(function (ok) { if (ok) run(); });
            else run();
        });
    };
    PvViewer.prototype.applySidebarWidth = function (w) {
        if (!this.filmstripEl) return;
        w = clamp(w, 120, 480);
        this.filmstripEl.style.flexBasis = w + 'px';
        this.filmstripEl.style.width = w + 'px';
        this.sidebarWidth = w;
        // 有 ResizeObserver 时由窗格自行响应尺寸变化并同步重绘，避免此处重复
        // resize/render 与观察器回调叠加导致拖动分栏时右侧影像闪烁。
        if (!window.ResizeObserver) this.panes.forEach(function (p) { p.resize(); p.render(); });
    };
    PvViewer.prototype.restoreSidebarWidth = function () {
        try { var w = parseInt(sessionStorage.getItem('pacs_sidebar_w'), 10); if (w >= 120 && w <= 480) this.applySidebarWidth(w); } catch (e) {}
    };
    PvViewer.prototype._bindSplitter = function () {
        var self = this, sp = this.splitterEl;
        if (!sp || !this.filmstripEl) return;
        var dragging = false, startX = 0, startW = 0;
        // 拖动期间挂到 window：即使指针捕获失败 / 移出分隔条也能持续接收（不改变拖动手感）
        function onMove(e) { if (dragging) self.applySidebarWidth(startW + (e.clientX - startX)); }
        function stop() {
            if (!dragging) return;
            dragging = false; sp.classList.remove('dragging');
            window.removeEventListener('pointermove', onMove);
            window.removeEventListener('pointerup', stop);
            window.removeEventListener('pointercancel', stop);
            try { sessionStorage.setItem('pacs_sidebar_w', String(self.sidebarWidth || 168)); } catch (e) {}
        }
        sp.addEventListener('pointerdown', function (e) {
            dragging = true; startX = e.clientX; startW = self.filmstripEl.getBoundingClientRect().width;
            sp.classList.add('dragging');
            if (sp.setPointerCapture) { try { sp.setPointerCapture(e.pointerId); } catch (err) {} }
            window.addEventListener('pointermove', onMove);
            window.addEventListener('pointerup', stop);
            window.addEventListener('pointercancel', stop);
            e.preventDefault();
        });
        sp.addEventListener('dblclick', function () { self.applySidebarWidth(168); try { sessionStorage.setItem('pacs_sidebar_w', '168'); } catch (e) {} });
    };
    PvViewer.prototype._disableChromeContext = function () {
        var targets = [this.toolbarEl, this.filmstripEl, document.querySelector('.pv-footer')];
        targets.forEach(function (el) {
            if (!el || el.__pvNoCtx) return;
            el.__pvNoCtx = true;
            el.addEventListener('contextmenu', function (e) { e.preventDefault(); });
        });
    };

    PvViewer.prototype.destroy = function () {
        this.closeLightbox();   // 移除可能残留的阅片灯浮层
        if (document.body) document.body.classList.remove('pv-embedded');   // 离开阅片器还原外壳
        if (this._onHostMsg) { window.removeEventListener('message', this._onHostMsg); this._onHostMsg = null; }
        // 保存各窗格当前序列的操作痕迹，供再次进入时恢复
        var self = this;
        this.panes.forEach(function (p) { self.savePaneState(p); });
        this._persistSeriesMap();
        if (this._onWinResize) window.removeEventListener('resize', this._onWinResize);
        if (this._onUnload) window.removeEventListener('beforeunload', this._onUnload);
        if (this._onKey) document.removeEventListener('keydown', this._onKey);
        if (this._resizeThrottled) { this._resizeThrottled.cancel(); this._resizeThrottled = null; }
        if (this._persistTimer) { clearTimeout(this._persistTimer); this._persistTimer = null; }
        if (this.toolbar && this.toolbar.destroy) this.toolbar.destroy();
        if (this._ctxUnbind) { this._ctxUnbind(); this._ctxUnbind = null; }
        if (this._ctxUnbindViewport) { this._ctxUnbindViewport(); this._ctxUnbindViewport = null; }
        if (this.sidebar && this.sidebar._thumbIO) { try { this.sidebar._thumbIO.disconnect(); } catch (e) {} }
        this.panes.slice().forEach(function (p) { p.destroy(); });
        this.panes = [];
    };

    /* ---------- 生命周期 ---------- */
    var instance = null;
    global.PvPages = global.PvPages || {};
    global.PvPages.viewer = {
        init: function (data) {
            data = data || {};
            global.PvIcons = data.icons || global.PvIcons || {};
            var root = document.querySelector('[data-pv="app"]');
            if (!root) return;
            // 是否被 iframe 嵌入：以 `self !== top` 为最终权威（覆盖后端 Sec-Fetch-Dest 初值）
            var embedded = !!data.embedded;
            try { embedded = global.self !== global.top; } catch (e) { embedded = true; }
            if (document.body) document.body.classList.toggle('pv-embedded', embedded);   // 嵌入时隐藏顶栏/页脚
            if (instance) { try { instance.destroy(); } catch (e) {} instance = null; }
            instance = new PvViewer(root, {
                uid: data.uid || '', uids: data.uids || [], mode: data.mode || 'append',
                limit: data.studyLimit || 5, about: data.about || {},
                guest: !!data.guest, embedded: embedded, hostOrigin: data.hostOrigin || ''
            });
        },
        destroy: function () { if (instance) { try { instance.destroy(); } catch (e) {} instance = null; } },
        /** 供 SPA 保活复用：返回当前阅片器实例（未创建时为 null） */
        peek: function () { return instance; }
    };
    global.PvViewer = PvViewer;
})(window);
