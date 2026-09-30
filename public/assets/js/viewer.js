/* ============================================================
 * assets/js/viewer.js — 影像查看工作区控制器（PvViewer）
 * ============================================================
 * 多检查 / 多窗格布局、激活窗格、工具栏、序列栏、右键菜单、会话记忆。
 * 单窗格能力见 modules/pane.js（PvPane）。
 * ============================================================ */
(function (global) {
    'use strict';

    var PRESETS = global.PvPresets;
    var PANE_ACTS = { 'rotate-cw': 1, 'rotate-ccw': 1, 'flip-h': 1, 'flip-v': 1, 'invert': 1, 'clear': 1, 'fit': 1, 'oneone': 1, 'prev': 1, 'next': 1 };

    var clamp = PvRender.clamp;   // 复用通用钳位助手
    var esc = PvUI.esc;           // 复用通用转义助手
    /** 图标渲染：命中统一 SVG 图标库则用 SVG，否则按文本/emoji（如预设窗）显示 */
    function iconHtml(name) {
        if (global.PvIcons && global.PvIcons[name]) {
            return '<span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + global.PvIcons[name] + '</svg></span>';
        }
        return '<span class="ic">' + name + '</span>';
    }

    /* ============================================================
     * PvViewer —— 工作区控制器
     * ============================================================ */
    function PvViewer(root, opts) {
        opts = opts || {};
        this.root = root;
        this.route = { uid: opts.uid || '', mode: opts.mode || 'append' };
        this.about = opts.about || {};
        this.direct = opts.direct || '';
        this.studyLimit = clamp(parseInt(opts.limit, 10) || 5, 3, 10);
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
        this._bindCloseAll();
        this._bindSplitter();
        this._bindCtxMenu();
        this._disableChromeContext();
        this.restoreSidebarWidth();
        this._bindWindowResize();
        this._bindPersistFlush();
        this.setLayout('1', true);
        this.boot();
    }
    PvViewer.prototype.activePane = function () { return this.panes[this.active] || null; };
    PvViewer.prototype.study = function (uid) { for (var i = 0; i < this.ws.studies.length; i++) if (this.ws.studies[i].uid === uid) return this.ws.studies[i]; return null; };
    PvViewer.prototype.indexOf = function (uid) { for (var i = 0; i < this.ws.studies.length; i++) if (this.ws.studies[i].uid === uid) return i; return -1; };
    PvViewer.prototype._bindWindowResize = function () {
        var self = this;
        this._onWinResize = function () { self.panes.forEach(function (p) { p.resize(); p.render(); }); };
        window.addEventListener('resize', this._onWinResize);
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
        if (!initial) { this.renderSidebar(); this.refreshControlState(); this.persist(); }
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
        this.refreshControlState();
        this.persist();
    };

    /* ---------- 工具栏 ---------- */
    PvViewer.prototype.syncToolbar = function () {
        var p = this.activePane(), st = p ? p.st : { invert: false, flipH: false, flipV: false };
        this.toolbar.sync({ tool: this.tool, invert: st.invert, flipH: st.flipH, flipV: st.flipV });
    };
    PvViewer.prototype.setTool = function (t) {
        this.tool = t;
        this.panes.forEach(function (p) { p.canvas.style.cursor = (t === 'pan') ? 'grab' : 'crosshair'; });
        this.syncToolbar();
        var hints = { wl: '窗宽窗位：左右拖动改 WW，上下拖动改 WL', zoom: '缩放：拖动或滚轮（以指针为中心）', pan: '平移：拖动移动画布', length: '测距：依次点击两点', angle: '测角：依次点击三点', rect: '矩形 ROI：拖拽框选', ellipse: '椭圆 ROI：拖拽框选' };
        var p = this.activePane(); if (p) p.setStatus(hints[t] || '');
    };
    PvViewer.prototype.setPreset = function (k) {
        var p = this.activePane(); if (!p || !p.hasImage()) return;
        var pr = PRESETS[k]; if (!pr) return;
        p.st.ww = pr.ww; p.st.wl = pr.wl; p.render();
        p.setStatus(pr.label + ' · WW ' + pr.ww + ' / WL ' + pr.wl);
        Array.prototype.forEach.call(this.toolbarEl.querySelectorAll('[data-pv-preset]'), function (el) { el.classList.toggle('active', el.getAttribute('data-pv-preset') === k); });
    };
    PvViewer.prototype.doAction = function (a) {
        if (PANE_ACTS[a]) { var p = this.activePane(); if (!p) return; this._paneAction(p, a); return; }
        if (a === 'toggle-sidebar') { this.toggleSidebar(); return; }
        if (a === 'copy-link') { this.copyDirectLink(); return; }
        if (a === 'about') { this.showAbout(); return; }
        if (a === 'back') { this.confirmExit(); return; }
        if (a === 'dicom-info') { var p2 = this.activePane(); if (p2) p2.showDicomInfo(); return; }
        if (a === 'save-image') { var p3 = this.activePane(); if (p3) p3.saveImage(); return; }
        if (a === 'save-series') { var p4 = this.activePane(); if (p4) p4.saveSeries(); return; }
    };
    PvViewer.prototype._paneAction = function (p, a) {
        var st = p.st;
        if (a === 'rotate-cw') st.rot = (st.rot + 90) % 360;
        else if (a === 'rotate-ccw') st.rot = (st.rot - 90 + 360) % 360;
        else if (a === 'flip-h') st.flipH = !st.flipH;
        else if (a === 'flip-v') st.flipV = !st.flipV;
        else if (a === 'invert') st.invert = !st.invert;
        else if (a === 'clear') { p.clearAnnos(); this.syncToolbar(); return; }
        else if (a === 'fit') { p.fit(); this.syncToolbar(); return; }
        else if (a === 'oneone') { st.zoom = 1; st.panX = 0; st.panY = 0; }
        else if (a === 'prev') { p.setFrame(st.fi - 1); this.syncToolbar(); return; }
        else if (a === 'next') { p.setFrame(st.fi + 1); this.syncToolbar(); return; }
        this.syncToolbar(); p.render();
    };
    PvViewer.prototype.toggleSidebar = function () {
        if (!this.filmstripEl) return;
        this.filmstripEl.classList.toggle('is-hidden');
        var visible = !this.filmstripEl.classList.contains('is-hidden');
        var p = this.activePane(); if (p) p.setStatus(visible ? '序列栏已显示' : '序列栏已隐藏');
        this.panes.forEach(function (p) { p.resize(); p.render(); });
    };
    PvViewer.prototype.refreshControlState = function () {
        var bar = this.toolbarEl; if (!bar) return;
        var p = this.activePane();
        var hasImage = !!(p && p.hasImage());
        var multi = hasImage && p.frameCount() > 1;
        var keepActs = { 'toggle-sidebar': 1, 'about': 1, 'back': 1 };
        Array.prototype.forEach.call(bar.querySelectorAll('[data-pv-tool]'), function (b) { b.disabled = !hasImage; });
        Array.prototype.forEach.call(bar.querySelectorAll('[data-pv-menu]'), function (b) { b.disabled = (!hasImage && b.getAttribute('data-pv-menu') !== 'layout'); });
        Array.prototype.forEach.call(bar.querySelectorAll('[data-pv-act]'), function (b) {
            var a = b.getAttribute('data-pv-act');
            if (keepActs[a]) { b.disabled = false; return; }
            if (a === 'prev' || a === 'next') { b.disabled = !multi; return; }
            b.disabled = !hasImage;
        });
        if (!hasImage) Array.prototype.forEach.call(bar.querySelectorAll('[data-pv-tool]'), function (b) { b.classList.remove('active'); });
    };
    PvViewer.prototype.afterPaneLoad = function (p) {
        this.renderSidebar();
        this.syncToolbar();
        this.refreshControlState();
        this.persist();
    };

    /* ---------- 工作区 ---------- */
    PvViewer.prototype.openStudy = function (uid, mode) {
        var self = this;
        var idx = this.indexOf(uid);
        var p = this.activePane();
        if (idx >= 0) {
            this.ws.studies[idx].collapsed = false;
            if (p) p.setSeries(uid, 0);
            this.renderSidebar();
            if (p) p.setStatus('该检查已在影像视图中打开，已定位');
            return;
        }
        var p0 = this.activePane(); if (p0) p0.setStatus('正在加载影像数据…');
        PvApi.study(uid, { fresh: true }).then(function (j) {
            if (!j || j.code !== 200 || !j.data) { if (p0) p0.setStatus((j && j.msg) || '数据加载失败'); return; }
            if (mode === 'replace') { self.ws.studies = []; self.panes.forEach(function (pp) { pp.st.uid = ''; pp.st.si = 0; pp.st.fi = 0; }); }
            self.ws.studies.push({ uid: uid, data: j.data, series: j.data.series || [], collapsed: false });
            while (self.ws.studies.length > self.studyLimit) self.ws.studies.shift();
            self.ws.studies.forEach(function (x, k) { x.collapsed = (k !== self.ws.studies.length - 1); });
            var a = self.activePane(); if (a) a.setSeries(uid, 0);
            self.panes.forEach(function (pp) { if (pp !== a) { pp.updateTitle(); pp.updateScrollbar(); pp.render(); } });
            self.logEvent('read');
            if (a) a.setStatus('');
        }).catch(function () { if (p0) p0.setStatus('网络请求失败'); });
    };
    PvViewer.prototype.boot = function () {
        var self = this;
        var saved = this.loadState();
        if (this.route.uid) {
            this.restoreStudies(saved, function () { self.openStudy(self.route.uid, self.route.mode); });
            return;
        }
        this.restoreStudies(saved, function () {
            if (!self.ws.studies.length) { self.showEmpty(); return; }
            self.renderAll(); self.renderSidebar(); self.refreshControlState();
        });
    };
    PvViewer.prototype.restoreStudies = function (saved, cb) {
        var self = this;
        if (!saved || !saved.studies || !saved.studies.length) { if (cb) cb(); return; }
        if (saved.layout) this.setLayout(saved.layout, true);
        // 并行拉取各检查数据（Promise.all 保持原顺序），缩短恢复时间
        var tasks = saved.studies.map(function (item) {
            return PvApi.study(item.uid).then(function (j) {
                if (j && j.code === 200 && j.data) {
                    return { uid: item.uid, data: j.data, series: j.data.series || [], collapsed: !!item.collapsed };
                }
                return null;
            }).catch(function () { return null; });
        });
        Promise.all(tasks).then(function (list) {
            var restored = list.filter(function (x) { return x; });
            self.ws.studies = restored;
            // 恢复窗格内容
            if (saved.panes) {
                saved.panes.forEach(function (ps, i) {
                    var pane = self.panes[i]; if (!pane) return;
                    if (ps && ps.uid && self.study(ps.uid)) {
                        pane.st.uid = ps.uid; pane.st.si = ps.si || 0; pane.st.fi = ps.fi || 0;
                        pane.applyDefaults();
                    }
                });
            }
            if (typeof saved.active === 'number' && saved.active >= 0 && saved.active < self.panes.length) self.active = saved.active;
            self.panes.forEach(function (p, i) {
                p.el.classList.toggle('active', i === self.active);
                if (p.st.uid && self.study(p.st.uid)) { p.applyDefaults(); p.fit(); p.updateTitle(); p.updateScrollbar(); p.render(); var rs = p.curSeries(); if (rs) p.prefetch(rs); }
                else { p.updateTitle(); p.updateScrollbar(); p.render(); }
            });
            if (cb) cb();
        });
    };
    PvViewer.prototype.renderAll = function () {
        this.panes.forEach(function (p, i) { p.el.classList.toggle('active', i === this.active); p.applyDefaults(); p.fit(); p.updateTitle(); p.render(); }, this);
        this.renderSidebar();
        this.syncToolbar();
        this.refreshControlState();
    };
    PvViewer.prototype.renderSidebar = function () {
        var self = this, p = this.activePane();
        this.sidebar.renderStudies(this.ws.studies, { uid: p ? p.st.uid : '', si: p ? p.st.si : 0 }, {
            onSeries: function (gi, si) { var st = self.ws.studies[gi]; if (!st) return; var ap = self.activePane(); if (ap) ap.setSeries(st.uid, si); },
            onToggle: function (gi) { var st = self.ws.studies[gi]; if (!st) return; st.collapsed = !st.collapsed; self.renderSidebar(); self.persist(); },
            onClose: function (gi) { self.removeStudy(gi); }
        });
    };
    PvViewer.prototype.removeStudy = function (gi) {
        if (gi < 0 || gi >= this.ws.studies.length) return;
        var removed = this.ws.studies.splice(gi, 1)[0];
        // 窗格中若引用了被移除的检查则清空该窗格
        this.panes.forEach(function (p) { if (p.st.uid === removed.uid) { p.st.uid = ''; p.st.si = 0; p.st.fi = 0; } });
        if (!this.ws.studies.length) { this.showEmpty(); return; }
        this.renderAll();
        this.persist();
    };
    PvViewer.prototype.showEmpty = function () {
        this.ws.studies = [];
        this.panes.forEach(function (p) { p.st.uid = ''; p.st.si = 0; p.st.fi = 0; p.st.annos = []; p.updateTitle(); p.updateScrollbar(); p.render(); });
        this.renderSidebar();
        this.syncToolbar();
        this.refreshControlState();
        this.clearState();
        var p = this.activePane(); if (p) p.setStatus('');
    };
    PvViewer.prototype.closeAll = function () {
        this.ws.studies = [];
        this.panes.forEach(function (p) { p.st.uid = ''; p.st.si = 0; p.st.fi = 0; p.st.annos = []; p.st.draft = null; p.updateTitle(); p.updateScrollbar(); p.render(); });
        this.clearState();
        this.renderSidebar();
        this.syncToolbar();
        this.refreshControlState();
        var p = this.activePane(); if (p) p.setStatus('');
    };

    /* ---------- 会话记忆 ---------- */
    /** 合并高频写入（翻帧滚动等）到一次 sessionStorage 落盘 */
    PvViewer.prototype.persist = function () {
        var self = this;
        if (this._persistTimer) clearTimeout(this._persistTimer);
        this._persistTimer = setTimeout(function () { self._persistTimer = null; self.persistNow(); }, 150);
    };
    PvViewer.prototype.persistNow = function () {
        try {
            var state = {
                layout: this.layout, active: this.active,
                studies: this.ws.studies.map(function (x) { return { uid: x.uid, collapsed: !!x.collapsed }; }),
                panes: this.panes.map(function (p) { return { uid: p.st.uid, si: p.st.si, fi: p.st.fi }; })
            };
            sessionStorage.setItem('pacs_workspace_v1', JSON.stringify(state));
        } catch (e) {}
    };
    PvViewer.prototype.loadState = function () { try { return JSON.parse(sessionStorage.getItem('pacs_workspace_v1')); } catch (e) { return null; } };
    PvViewer.prototype.clearState = function () {
        if (this._persistTimer) { clearTimeout(this._persistTimer); this._persistTimer = null; }
        try { sessionStorage.removeItem('pacs_workspace_v1'); } catch (e) {}
    };

    /* ---------- 日志 ---------- */
    PvViewer.prototype.logEvent = function (action, extra) {
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
        var self = this;
        var go = function () { self.closeAll(); try { sessionStorage.removeItem('pacs_search_v1'); } catch (e) {} if (window.PvNav) window.PvNav.go('search'); };
        if (!this.ws.studies.length) { go(); return; }
        if (window.PvModal) {
            PvModal.confirm({ title: '关闭影像查看', message: '关闭将清空全部已打开的检查与检索记录，并返回研究检索，确认关闭？', okText: '关闭', danger: true })
                .then(function (ok) { if (ok) go(); });
        } else go();
    };
    PvViewer.prototype.copyDirectLink = function () {
        var p = this.activePane();
        var uid = (p && p.st.uid) || this.route.uid;
        if (!uid) { if (p) p.setStatus('没有可复制的检查'); return; }
        var link = window.PvNav ? window.PvNav.route('viewer', { uid: uid }) : '';
        var done = function (ok) { if (p) p.setStatus((ok === false ? '复制失败，请手动复制：' : '已复制阅片直链：') + link); };
        PvUI.copy(link).then(done, function () { done(false); });
    };

    /* ---------- 右键菜单（作用于激活窗格） ---------- */
    PvViewer.prototype._ctxItem = function (o) {
        if (o.sep) return '<div class="pv-ctx-sep"></div>';
        var arrow = o.sub ? '<span class="arrow">▶</span>' : '';
        var sub = o.sub ? '<div class="pv-ctx-sub">' + o.sub.map(this._ctxItem.bind(this)).join('') + '</div>' : '';
        var attrs = '';
        if (o.tool) attrs += ' data-tool="' + o.tool + '"';
        if (o.act) attrs += ' data-act="' + o.act + '"';
        if (o.preset) attrs += ' data-preset="' + o.preset + '"';
        var icon = o.icon ? iconHtml(o.icon) : '';
        return '<div class="pv-ctx-item"' + attrs + '>' + icon + '<span class="lb">' + esc(o.label) + '</span>' + arrow + sub + '</div>';
    };
    PvViewer.prototype.openCtxMenu = function (cx, cy) {
        var el = this.ctxEl; if (!el) return;
        var p = this.activePane();
        var hasImage = !!(p && p.hasImage());
        var items;
        if (!hasImage) {
            items = [{ label: '关于', icon: 'about', act: 'about' }];
        } else {
            items = [
                { label: '预设窗', icon: 'preset', sub: [{ label: '软组织窗 (400/40)', preset: 'soft', icon: '🟫' }, { label: '肺窗 (1500/-600)', preset: 'lung', icon: '🫁' }, { label: '骨窗 (2000/350)', preset: 'bone', icon: '🦴' }, { label: '默认窗 (2500/250)', preset: 'full', icon: '🖼' }] },
                { label: '缩放', icon: 'zoom', tool: 'zoom' }, { label: '平移', icon: 'pan', tool: 'pan' }, { label: '使用窗口', icon: 'wl', tool: 'wl' },
                { label: '原图 1:1', icon: 'oneone', act: 'oneone' }, { sep: true },
                { label: '测量', icon: 'measure', sub: [{ label: '测距（mm）', tool: 'length', icon: 'length' }, { label: '测角（°）', tool: 'angle', icon: 'angle' }, { label: '矩形 ROI', tool: 'rect', icon: 'rect' }, { label: '椭圆 ROI', tool: 'ellipse', icon: 'ellipse' }, { sep: true }, { label: '清除标注', act: 'clear', icon: 'clear' }] },
                { label: '变换', icon: 'transform', sub: [{ label: '逆时针 90°', act: 'rotate-ccw', icon: 'rotate-ccw' }, { label: '顺时针 90°', act: 'rotate-cw', icon: 'rotate-cw' }, { label: '水平镜像', act: 'flip-h', icon: 'flip-h' }, { label: '垂直镜像', act: 'flip-v', icon: 'flip-v' }, { label: '正负片反色', act: 'invert', icon: 'invert' }] },
                { sep: true }, { label: '关于', icon: 'about', act: 'about' }
            ];
        }
        el.innerHTML = items.map(this._ctxItem.bind(this)).join('');
        el.classList.add('open');
        var w = el.offsetWidth, h = el.offsetHeight;
        el.style.left = Math.max(8, Math.min(cx, window.innerWidth - w - 8)) + 'px';
        el.style.top = Math.max(8, Math.min(cy, window.innerHeight - h - 8)) + 'px';
    };
    PvViewer.prototype.closeCtxMenu = function () { if (this.ctxEl) this.ctxEl.classList.remove('open'); };
    PvViewer.prototype._bindCtxMenu = function () {
        var self = this, el = this.ctxEl; if (!el) return;
        el.addEventListener('click', function (e) {
            var it = e.target.closest ? e.target.closest('.pv-ctx-item') : null;
            if (!it) return;
            if (it.querySelector('.pv-ctx-sub')) return;
            var tool = it.getAttribute('data-tool'), act = it.getAttribute('data-act'), preset = it.getAttribute('data-preset');
            if (tool) self.setTool(tool);
            else if (act) self.doAction(act);
            else if (preset) self.setPreset(preset);
            self.closeCtxMenu();
        });
        this._ctxDoc = function (ev) { if (el.classList.contains('open') && !el.contains(ev.target)) self.closeCtxMenu(); };
        this._ctxViewport = function () { self.closeCtxMenu(); };
        document.addEventListener('pointerdown', this._ctxDoc, true);
        window.addEventListener('resize', this._ctxViewport);
        window.addEventListener('scroll', this._ctxViewport, true);
    };

    /* ---------- 关闭全部 / 分隔条 / 禁用右击 ---------- */
    PvViewer.prototype._bindCloseAll = function () {
        var self = this, btn = this.closeAllEl; if (!btn) return;
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
        this.panes.forEach(function (p) { p.resize(); p.render(); });
    };
    PvViewer.prototype.restoreSidebarWidth = function () {
        try { var w = parseInt(sessionStorage.getItem('pacs_sidebar_w'), 10); if (w >= 120 && w <= 480) this.applySidebarWidth(w); } catch (e) {}
    };
    PvViewer.prototype._bindSplitter = function () {
        var self = this, sp = this.splitterEl;
        if (!sp || !this.filmstripEl) return;
        var dragging = false, startX = 0, startW = 0;
        sp.addEventListener('pointerdown', function (e) {
            dragging = true; startX = e.clientX; startW = self.filmstripEl.getBoundingClientRect().width;
            sp.classList.add('dragging');
            if (sp.setPointerCapture) { try { sp.setPointerCapture(e.pointerId); } catch (err) {} }
            e.preventDefault();
        });
        sp.addEventListener('pointermove', function (e) { if (dragging) self.applySidebarWidth(startW + (e.clientX - startX)); });
        function stop() { if (!dragging) return; dragging = false; sp.classList.remove('dragging'); try { sessionStorage.setItem('pacs_sidebar_w', String(self.sidebarWidth || 168)); } catch (e) {} }
        sp.addEventListener('pointerup', stop);
        sp.addEventListener('pointercancel', stop);
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
        if (this._onWinResize) window.removeEventListener('resize', this._onWinResize);
        if (this._onUnload) window.removeEventListener('beforeunload', this._onUnload);
        if (this._persistTimer) { clearTimeout(this._persistTimer); this._persistTimer = null; }
        if (this.toolbar && this.toolbar.destroy) this.toolbar.destroy();
        if (this._ctxDoc) document.removeEventListener('pointerdown', this._ctxDoc, true);
        if (this._ctxViewport) { window.removeEventListener('resize', this._ctxViewport); window.removeEventListener('scroll', this._ctxViewport, true); }
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
            if (instance) { try { instance.destroy(); } catch (e) {} instance = null; }
            instance = new PvViewer(root, {
                uid: data.uid || '', mode: data.mode || 'append', direct: data.direct || '',
                limit: data.studyLimit || 5, about: data.about || {}
            });
        },
        destroy: function () { if (instance) { try { instance.destroy(); } catch (e) {} instance = null; } }
    };
    global.PvViewer = PvViewer;
})(window);
