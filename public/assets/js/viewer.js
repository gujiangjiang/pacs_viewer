/* ============================================================
 * assets/js/viewer.js — 影像查看工作区控制器（PvViewer）
 * ============================================================
 * 多检查 / 多窗格布局、激活窗格、工具栏、序列栏、右键菜单、会话记忆。
 * 单窗格能力见 modules/pane.js（PvPane）。
 * ============================================================ */
(function (global) {
    'use strict';

    var PRESETS = global.PvPresets;
    var PANE_ACTS = { 'rotate-cw': 1, 'rotate-ccw': 1, 'flip-h': 1, 'flip-v': 1, 'invert': 1, 'clear': 1, 'fit': 1, 'oneone': 1, 'prev': 1, 'next': 1, 'zoom-in': 1, 'zoom-out': 1 };

    /* 键盘快捷键映射（不在界面展示，避免臃肿；按 ? 查看说明） */
    var KEY_TOOLS = { w: 'wl', z: 'zoom', p: 'pan', l: 'length', a: 'angle', r: 'rect', e: 'ellipse' };
    var KEY_ACTS = { f: 'fit', i: 'invert', h: 'flip-h', v: 'flip-v', c: 'clear', d: 'dicom-info', s: 'save-image' };
    var KEY_LAYOUT = { '1': '1', '2': '2h', '3': '2v', '4': '4' };

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
        // 直接父窗口」与同源来源（见 _isTrustedHost），兼容独立域的 iframe 宿主。
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
        var raf = window.requestAnimationFrame || function (fn) { return setTimeout(fn, 16); };
        this._onWinResize = function () {
            if (!self.isAttached()) return;   // 被 SPA 隐藏期间无需重排重绘
            if (self._resizeRaf) return;
            self._resizeRaf = raf(function () { self._resizeRaf = null; self.panes.forEach(function (p) { p.resize(); p.render(); }); });
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

    /* ---------- 宿主页指令桥（被 PACS 外呼系统 iframe 嵌入时，工具栏可远程驱动） ----------
     * 宿主通过 window.postMessage({type:'pv-command', command|tool|preset|value}) 发送指令，
     * 支持：tool（wl/zoom/pan/length/angle/rect/ellipse）、preset、fit、reset、rotate、flip、invert。 */
    PvViewer.prototype._bindHostCommands = function () {
        var self = this;
        this._onHostMsg = function (e) {
            if (!self._isTrustedHost(e)) return;   // 仅接受可信来源
            var d = e && e.data;
            if (!d || typeof d !== 'object' || d.type !== 'pv-command') return;
            try { self.applyHostCommand(d); } catch (err) { /* 忽略非法指令 */ }
        };
        window.addEventListener('message', this._onHostMsg);
    };
    /**
     * 指令来源校验：
     *   ① 配置了 hostOrigin → 严格匹配该来源；
     *   ② 未配置时，信任嵌入本页的直接父窗口（iframe 宿主，跨域亦放行）；
     *   ③ 其余仅接受同源消息。
     * 如此既能让独立域的宿主工作站驱动工具栏，又不会被任意第三方页面远程操控。
     */
    PvViewer.prototype._isTrustedHost = function (e) {
        if (!e) return false;
        if (this.hostOrigin) return e.origin === this.hostOrigin;
        if (global.parent && global.parent !== global && e.source === global.parent) return true;
        return e.origin === global.location.origin;
    };
    /** 宿主指令中可直接作为“工具名”的命令（兼容 {command:'zoom'} 一类简写） */
    var HOST_TOOLS = { wl: 1, zoom: 1, pan: 1, length: 1, angle: 1, rect: 1, ellipse: 1 };
    PvViewer.prototype.applyHostCommand = function (d) {
        var p = this.activePane();
        if (!p) return;
        var cmd = d.command || d.action || '';
        // 工具：支持 {tool:'zoom'}、{command:'tool',value:'zoom'} 与 {command:'zoom'} 三种写法
        var tool = d.tool || (cmd === 'tool' ? d.value : '') || (HOST_TOOLS[cmd] ? cmd : '');
        if (tool) { this.setTool(tool); return; }
        if (cmd === 'preset') { this.setPreset(d.preset || d.value || ''); return; }
        if (!p.hasImage()) return;
        if (cmd === 'fit') { p.fit(); return; }
        if (cmd === 'oneone') { this._paneAction(p, 'oneone'); return; }
        if (cmd === 'reset') {
            // 等价双击左侧序列缩略图：重置为该视图默认状态（重载 + 默认窗 + 适应窗口）
            var gi = this.indexOf(p.st.uid), si = p.st.si;
            if (gi >= 0) this.setSeriesOnActive(gi, si, true);
            p.setStatus('已重置为该视图默认状态'); return;
        }
        if (cmd === 'rotate') { this._paneAction(p, 'rotate-cw'); return; }
        if (cmd === 'flip') { this._paneAction(p, 'flip-h'); return; }
        if (cmd === 'invert') { this._paneAction(p, 'invert'); return; }
    };

    /** 键盘快捷键绑定（阅片器专属；输入框内不拦截） */
    PvViewer.prototype._bindKeys = function () {
        var self = this;
        this._onKey = function (e) {
            if (!self.isAttached()) return;   // 非激活页（被 SPA 隐藏）不响应快捷键
            if (e.metaKey || e.ctrlKey || e.altKey) return;
            var t = e.target;
            if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) return;
            var k = e.key;
            if (k === '?') { e.preventDefault(); self.showShortcuts(); return; }
            if (k === 'Escape') { self.closeCtxMenu(); if (window.PvModal) window.PvModal.close(); return; }
            if (k === 'ArrowLeft' || k === 'ArrowUp') { e.preventDefault(); self.doAction('prev'); return; }
            if (k === 'ArrowRight' || k === 'ArrowDown') { e.preventDefault(); self.doAction('next'); return; }
            if (k === '=' || k === '+') { e.preventDefault(); self.doAction('zoom-in'); return; }
            if (k === '-' || k === '_') { e.preventDefault(); self.doAction('zoom-out'); return; }
            var lower = k.length === 1 ? k.toLowerCase() : k;
            if (KEY_TOOLS[lower]) { e.preventDefault(); self.setTool(KEY_TOOLS[lower]); return; }
            if (KEY_ACTS[lower]) { e.preventDefault(); self.doAction(KEY_ACTS[lower]); return; }
            if (KEY_LAYOUT[k]) { e.preventDefault(); self.setLayoutByKey(KEY_LAYOUT[k]); return; }
        };
        document.addEventListener('keydown', this._onKey);
    };

    /** 键盘快捷键说明（仅模态框展示，界面不占位） */
    PvViewer.prototype.showShortcuts = function () {
        if (!window.PvModal) return;
        var rows = [
            ['?', '显示 / 关闭本快捷键说明'],
            ['← → / ↑ ↓', '上一帧 / 下一帧'],
            ['W / Z / P', '窗宽窗位 / 缩放 / 平移'],
            ['L / A', '测距 / 测角'],
            ['R / E', '矩形 ROI / 椭圆 ROI'],
            ['F', '适应窗口'],
            ['= / -', '放大 / 缩小'],
            ['1 / 2 / 3 / 4', '单 / 左右双 / 上下双 / 四视图'],
            ['I / H / V', '反色 / 水平镜像 / 垂直镜像'],
            ['C', '清除标注'],
            ['D / S', 'DICOM 详情 / 保存当前图像'],
            ['Esc', '关闭右键菜单 / 模态框']
        ];
        var html = '<div class="pv-keys"><table class="pv-keys-table"><tbody>';
        rows.forEach(function (r) {
            var keys = r[0].split(/\s*\/\s*/).map(function (k) { return '<kbd>' + esc(k) + '</kbd>'; }).join('');
            html += '<tr><th>' + keys + '</th><td>' + esc(r[1]) + '</td></tr>';
        });
        html += '</tbody></table></div>';
        window.PvModal.open({ title: '键盘快捷键', size: 'lg', body: html });
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
    PvViewer.prototype.setTool = function (t) {
        this.tool = t;
        this.panes.forEach(function (p) { p.canvas.style.cursor = (t === 'pan') ? 'grab' : 'crosshair'; });
        this.syncToolbar();
        var hints = { wl: '窗宽窗位：左右拖动改 WW，上下拖动改 WL', zoom: '缩放：拖动或滚轮（以指针为中心）', pan: '平移：拖动移动画布', length: '测距：依次点击两点', angle: '测角：依次点击三点', rect: '矩形 ROI：拖拽框选', ellipse: '椭圆 ROI：拖拽框选' };
        var p = this.activePane(); if (p) p.setStatus(hints[t] || '');
    };
    PvViewer.prototype.setPreset = function (k) {
        var p = this.activePane(); if (!p || !p.hasImage()) return;
        // 「默认窗」是每个序列各自的默认状态：等价于双击左侧序列缩略图重置（重载 + 默认窗 + 适应窗口）
        if (k === 'full') {
            var gi = this.indexOf(p.st.uid), si = p.st.si;
            if (gi >= 0) this.setSeriesOnActive(gi, si, true);
            var w = p.defaultWindow();
            p.setStatus('默认窗 · WW ' + Math.round(w.ww) + ' / WL ' + Math.round(w.wl));
            this.updatePresetMenu();
            return;
        }
        var pr = PRESETS[k]; if (!pr) return;
        p.st.ww = pr.ww; p.st.wl = pr.wl; p._wwTouched = true; p.render();
        p.setStatus(pr.label + ' · WW ' + pr.ww + ' / WL ' + pr.wl);
        Array.prototype.forEach.call(this.toolbarEl.querySelectorAll('[data-pv-preset]'), function (el) { el.classList.toggle('active', el.getAttribute('data-pv-preset') === k); });
    };
    /** 动态刷新「默认窗」预设文案为当前序列的默认窗值 */
    PvViewer.prototype.updatePresetMenu = function () {
        if (!this.toolbarEl) return;
        var lab = this.toolbarEl.querySelector('[data-preset-label="full"]');
        if (!lab) return;
        var p = this.activePane();
        if (p && p.hasImage() && p.defaultWindow) {
            var w = p.defaultWindow();
            lab.textContent = '默认窗 (' + Math.round(w.ww) + ' / ' + Math.round(w.wl) + ')';
        } else {
            lab.textContent = '默认窗';
        }
    };
    PvViewer.prototype.doAction = function (a) {
        if (PANE_ACTS[a]) { var p = this.activePane(); if (!p) return; this._paneAction(p, a); return; }
        if (a === 'toggle-sidebar') { this.toggleSidebar(); return; }
        if (a === 'copy-link') { this.copyDirectLink(); return; }
        if (a === 'about') { this.showAbout(); return; }
        if (a === 'back') { if (!this.guest) this.confirmExit(); return; }
        if (a === 'report') { this.showReport(); return; }
        if (a === 'dicom-info') { var p2 = this.activePane(); if (p2) p2.showDicomInfo(); return; }
        if (a === 'save-image') { var p3 = this.activePane(); if (p3) p3.saveImage(); return; }
        if (a === 'save-series') { var p4 = this.activePane(); if (p4) p4.saveSeries(); return; }
        if (a === 'save-dicom') { var p5 = this.activePane(); if (p5) p5.saveDicom(); return; }
        if (a === 'lightbox') { this.toggleLightbox(); return; }
        if (a === 'shortcuts') { this.showShortcuts(); return; }
    };

    /* ---------- 阅片灯（全屏纯白背光，点击任意区域退出） ---------- */
    PvViewer.prototype.toggleLightbox = function () {
        if (this._lightboxEl) { this.closeLightbox(); return; }
        var self = this;
        var el = document.createElement('div');
        el.className = 'pv-lightbox';
        el.setAttribute('role', 'button');
        el.setAttribute('title', '阅片灯模式：点击任意区域退出');
        el.addEventListener('click', function () { self.closeLightbox(); });
        document.body.appendChild(el);
        this._lightboxEl = el;
        this._onLightboxKey = function (e) { if (e.key === 'Escape') { e.preventDefault(); self.closeLightbox(); } };
        document.addEventListener('keydown', this._onLightboxKey, true);
    };
    PvViewer.prototype.closeLightbox = function () {
        if (this._onLightboxKey) { document.removeEventListener('keydown', this._onLightboxKey, true); this._onLightboxKey = null; }
        if (this._lightboxEl && this._lightboxEl.parentNode) this._lightboxEl.parentNode.removeChild(this._lightboxEl);
        this._lightboxEl = null;
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
        else if (a === 'oneone') { st.zoom = 1; st.panX = 0; st.panY = 0; p._fitPending = false; }
        else if (a === 'zoom-in') { st.zoom = clamp(st.zoom * 1.2, 0.12, 16); p._fitPending = false; }
        else if (a === 'zoom-out') { st.zoom = clamp(st.zoom / 1.2, 0.12, 16); p._fitPending = false; }
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
    /**
     * 访问模式差异化：普通访客直链隐藏「复制阅片直链」、保留「阅片灯」；
     * 嵌入模式两者都隐藏；登录用户（非嵌入）不变。
     */
    PvViewer.prototype.applyAccessMode = function () {
        var bar = this.toolbarEl;
        if (!bar) return;
        var hide = function (act, on) {
            var b = bar.querySelector('[data-pv-act="' + act + '"]');
            if (b) b.hidden = !!on;   // 前端最终判定，覆盖服务端初值
        };
        hide('copy-link', this.embedded || this.guest);
        hide('lightbox', this.embedded);
    };
    PvViewer.prototype.refreshControlState = function () {
        var bar = this.toolbarEl; if (!bar) return;
        var p = this.activePane();
        var hasImage = !!(p && p.hasImage());
        var multi = hasImage && p.frameCount() > 1;
        var hasStudies = this.ws.studies.length > 0;
        var keepActs = { 'toggle-sidebar': 1, 'about': 1, 'back': 1 };
        Array.prototype.forEach.call(bar.querySelectorAll('[data-pv-tool]'), function (b) { b.disabled = !hasImage; });
        Array.prototype.forEach.call(bar.querySelectorAll('[data-pv-menu]'), function (b) {
            // 布局：无已打开检查时禁用（空分栏无意义）；其余菜单：无影像时禁用
            b.disabled = (b.getAttribute('data-pv-menu') === 'layout') ? !hasStudies : !hasImage;
        });
        Array.prototype.forEach.call(bar.querySelectorAll('[data-pv-act]'), function (b) {
            var a = b.getAttribute('data-pv-act');
            if (keepActs[a]) { b.disabled = false; return; }
            if (a === 'report') { b.disabled = !(p && p.data()); return; }   // 有检查即可查看报告（含暂无报告占位）
            if (a === 'prev' || a === 'next') { b.disabled = !multi; return; }
            b.disabled = !hasImage;
        });
        if (!hasImage) Array.prototype.forEach.call(bar.querySelectorAll('[data-pv-tool]'), function (b) { b.classList.remove('active'); });
        if (this.closeAllEl) this.closeAllEl.hidden = !hasStudies;   // 空序列栏时隐藏「关闭全部」
    };
    PvViewer.prototype.afterPaneLoad = function (p) {
        this.renderSidebar();
        this.syncToolbar();
        this.updatePresetMenu();
        this.refreshControlState();
        this.persist();
    };

    /* ---------- 工作区 ---------- */
    PvViewer.prototype.openStudy = function (uid, mode, noCull) {
        var self = this;
        var idx = this.indexOf(uid);
        var p = this.activePane();
        if (idx >= 0) {
            this.ws.studies[idx].collapsed = false;
            if (p) p.setSeries(uid, 0);
            this.renderSidebar();
            this.sidebar.scrollToSeries(uid, 0);   // 自动滚动定位到该检查序列
            if (p) p.setStatus('该检查已在影像视图中打开，已定位');
            return Promise.resolve();
        }
        // 打开新检查：恢复默认单视图
        if (this.layout !== '1') this.setLayout('1', false);
        var p0 = this.activePane(); if (p0) p0.setStatus('正在加载影像数据…');
        return PvApi.study(uid, { fresh: true }).then(function (j) {
            if (!j || j.code !== 200 || !j.data) { if (p0) p0.setStatus((j && j.msg) || '数据加载失败'); return; }
            if (mode === 'replace') { self.ws.studies = []; self.clearAllSeriesState(); self.panes.forEach(function (pp) { pp.st.uid = ''; pp.st.si = 0; pp.st.fi = 0; }); }
            self.ws.studies.push({ uid: uid, data: j.data, series: j.data.series || [], collapsed: false });
            // noCull：按申请单（A2）一次打开该单全部 Study 时不按上限裁剪
            if (!noCull) { while (self.ws.studies.length > self.studyLimit) { var ev = self.ws.studies.shift(); self.clearStudySeriesState(ev.uid); } }
            self.ws.studies.forEach(function (x, k) { x.collapsed = (k !== self.ws.studies.length - 1); });
            var a = self.activePane(); if (a) a.setSeries(uid, 0);
            self.panes.forEach(function (pp) { if (pp !== a) { pp.updateTitle(); pp.updateScrollbar(); pp.render(); } });
            self.renderSidebar();
            self.sidebar.scrollToSeries(uid, 0);   // 新打开的检查自动滚动到序列栏顶部
            self.logEvent('read');
            if (a) a.setStatus('');
        }).catch(function () { if (p0) p0.setStatus('网络请求失败'); });
    };
    PvViewer.prototype.boot = function () {
        var self = this;
        var saved = this.loadState();
        // 立即给出加载反馈，避免恢复期间看似空白卡住
        var ap = this.activePane(); if (ap) ap.setStatus('正在恢复影像视图…');
        if (this.route.uid) {
            this.restoreStudies(saved, function () { self.openStudy(self.route.uid, self.route.mode); });
            return;
        }
        if (this.route.uids && this.route.uids.length) {
            // A2：按申请单一并打开该单全部 Study（siblings 不受 study_limit 裁剪）
            this.restoreStudies(saved, function () {
                var list = self.route.uids.slice();
                var seq = function (i) {
                    if (i >= list.length) { self.refreshControlState(); return; }
                    Promise.resolve(self.openStudy(list[i], i === 0 ? 'replace' : 'append', true)).then(function () { seq(i + 1); });
                };
                seq(0);
            });
            return;
        }
        this.restoreStudies(saved, function () {
            if (!self.ws.studies.length) { self.showEmpty(); return; }
            // 窗格已由 restoreStudies→renderPanes 按痕迹渲染，勿再 renderAll（会重置缩放窗值）
            self.renderSidebar(); self.refreshControlState();
            var p = self.activePane(); if (p) p.setStatus('');
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
                    }
                });
            }
            if (typeof saved.active === 'number' && saved.active >= 0 && saved.active < self.panes.length) self.active = saved.active;
            self.renderPanes();
            if (cb) cb();
        });
    };
    /** 重绘所有窗格但保留各序列既有操作痕迹（用于恢复 / 关闭检查等，不重置缩放窗值） */
    PvViewer.prototype.renderPanes = function () {
        var self = this;
        this.panes.forEach(function (p, i) {
            p.el.classList.toggle('active', i === self.active);
            if (p.st.uid && self.study(p.st.uid)) {
                var ser = p.curSeries();
                var stt = ser ? self.seriesState(p.st.uid, ser.series_id) : null;
                if (stt && stt.view) { p.st.annos = []; p.applyView(stt.view); }
                else { p.applyDefaults(); p.fit(); }
                p.updateTitle(); p.updateScrollbar(); p.render();
                if (ser) p.prefetch(ser);
            } else {
                p.updateTitle(); p.updateScrollbar(); p.render();
            }
        });
    };
    PvViewer.prototype.renderSidebar = function () {
        var self = this, p = this.activePane();
        this.sidebar.renderStudies(this.ws.studies, { uid: p ? p.st.uid : '', si: p ? p.st.si : 0 }, {
            // 序列栏点击会 stopPropagation，需主动收起工具/预设下拉菜单，避免菜单残留
            onSeries: function (gi, si) { if (self.toolbar) self.toolbar.closeMenus(); self.setSeriesOnActive(gi, si, false); },
            onSeriesReset: function (gi, si) { if (self.toolbar) self.toolbar.closeMenus(); self.setSeriesOnActive(gi, si, true); },
            onToggle: function (gi) {
                if (self.toolbar) self.toolbar.closeMenus();
                // 竖屏底部横排序列栏不支持折叠：忽略切换，保持全部展开
                if (global.matchMedia && global.matchMedia('(orientation: portrait)').matches) return;
                var st = self.ws.studies[gi]; if (!st) return; st.collapsed = !st.collapsed; self.renderSidebar(); self.persist();
            },
            onClose: function (gi) { if (self.toolbar) self.toolbar.closeMenus(); self.removeStudy(gi); }
        });
    };
    PvViewer.prototype.removeStudy = function (gi) {
        if (this.guest) return;   // 访客不允许关闭检查
        if (gi < 0 || gi >= this.ws.studies.length) return;
        var removed = this.ws.studies.splice(gi, 1)[0];
        this.clearStudySeriesState(removed.uid);   // 关闭检查：丢弃其序列操作痕迹
        // 窗格中若引用了被移除的检查则清空该窗格
        this.panes.forEach(function (p) { if (p.st.uid === removed.uid) { p.st.uid = ''; p.st.si = 0; p.st.fi = 0; } });
        if (!this.ws.studies.length) { this.showEmpty(); return; }
        this.renderPanes();
        this.renderSidebar();
        this.syncToolbar();
        this.refreshControlState();
        this.persist();
    };
    PvViewer.prototype.showEmpty = function () {
        this.ws.studies = [];
        if (this.layout !== '1') this.setLayout('1', false);   // 影像清空后恢复单视图
        this.panes.forEach(function (p) { p.st.uid = ''; p.st.si = 0; p.st.fi = 0; p.st.annos = []; p.updateTitle(); p.updateScrollbar(); p.render(); });
        this.renderSidebar();
        this.syncToolbar();
        this.refreshControlState();
        this.clearState();
        var p = this.activePane(); if (p) p.setStatus('');
    };
    PvViewer.prototype.closeAll = function () {
        this.ws.studies = [];
        this.clearAllSeriesState();
        if (this.layout !== '1') this.setLayout('1', false);   // 影像清空后恢复单视图
        this.panes.forEach(function (p) { p.st.uid = ''; p.st.si = 0; p.st.fi = 0; p.st.annos = []; p.st.draft = null; p.updateTitle(); p.updateScrollbar(); p.render(); });
        this.clearState();
        this.renderSidebar();
        this.syncToolbar();
        this.refreshControlState();
        var p = this.activePane(); if (p) p.setStatus('');
    };

    /**
     * 在「激活窗格」载入序列（不改动布局：多视图下点序列即载入到当前激活分栏，便于对比）。
     * fresh=true 时重置该序列的操作痕迹（双击）。
     */
    PvViewer.prototype.setSeriesOnActive = function (gi, si, fresh) {
        var st = this.ws.studies[gi], ser = st && st.series[si];
        if (!ser) return;
        var ap = this.activePane(); if (!ap) return;
        if (fresh) {
            delete this.seriesMap[this._skey(st.uid, ser.series_id)];
            this._persistSeriesMap();
            ap.setSeries(st.uid, si, { fresh: true });
            return;
        }
        ap.setSeries(st.uid, si, {});
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
        if (this._resizeRaf) { try { (window.cancelAnimationFrame || clearTimeout)(this._resizeRaf); } catch (e) {} this._resizeRaf = null; }
        if (this._persistTimer) { clearTimeout(this._persistTimer); this._persistTimer = null; }
        if (this.toolbar && this.toolbar.destroy) this.toolbar.destroy();
        if (this._ctxDoc) document.removeEventListener('pointerdown', this._ctxDoc, true);
        if (this._ctxViewport) { window.removeEventListener('resize', this._ctxViewport); window.removeEventListener('scroll', this._ctxViewport, true); }
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
