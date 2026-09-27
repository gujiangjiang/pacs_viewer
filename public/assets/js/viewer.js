/* ============================================================
 * assets/js/viewer.js — 阅片器主控制器
 * 装配 render / osd / sidebar / toolbar / measurements 模块，
 * 负责画布管线、坐标变换、窗宽窗位、交互与状态调度。
 * ============================================================ */
(function (global) {
    'use strict';

    var BASE = PvRender.BASE, HU_MIN = PvRender.HU_MIN, HU_MAX = PvRender.HU_MAX;
    var PRESETS = {
        soft: { ww: 400, wl: 40, label: '软组织窗' },
        lung: { ww: 1500, wl: -600, label: '肺窗' },
        bone: { ww: 2000, wl: 350, label: '骨窗' },
        full: { ww: 2500, wl: 250, label: '默认窗' }
    };
    function clamp(v, a, b) { return v < a ? a : (v > b ? b : v); }
    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function PvViewer(root, opts) {
        opts = opts || {};
        this.root = root;
        this.route = { uid: opts.uid || '', mode: opts.mode || 'append' };
        this.direct = opts.direct || '';
        this.about = opts.about || {};
        this.studyLimit = parseInt(opts.limit, 10) || 5;
        if (this.studyLimit < 3) this.studyLimit = 3;
        if (this.studyLimit > 10) this.studyLimit = 10;
        this.q = function (k) { return root.querySelector('[data-pv="' + k + '"]'); };
        this.canvas = this.q('canvas');
        this.ctx = this.canvas.getContext('2d');
        this.stage = this.q('canvas').parentNode;
        this.statusEl = this.q('status');
        this.titleEl = this.q('title');
        this.huEl = this.q('hu');
        this.ctxEl = this.q('ctxmenu');
        this.filmstripEl = this.q('filmstrip');
        this.seriesListEl = this.q('serieslist');
        this.closeAllEl = this.q('closeall');
        this.splitterEl = this.q('splitter');
        this.dpr = window.devicePixelRatio || 1;

        this.work = document.createElement('canvas'); this.work.width = BASE; this.work.height = BASE;
        this.wctx = this.work.getContext('2d');
        this.raw = document.createElement('canvas'); this.raw.width = BASE; this.raw.height = BASE;

        this.cache = {}; this.cacheKeys = []; this.imgCache = {};
        this.sidebar = new PvSidebar(this.seriesListEl);
        this.scrollEl = this.q('vscroll'); this.scrollTrack = this.q('vscroll-track');
        this.scrollThumb = this.q('vscroll-thumb'); this.scrollBubble = this.q('vscroll-bubble');
        this.toolbar = new PvToolbar(this.q('toolbar'), {
            onTool: this.setTool.bind(this),
            onPreset: this.setPreset.bind(this),
            onAction: this.doAction.bind(this)
        });
        this.st = {
            data: null, series: [], si: 0, fi: 0,
            ww: 400, wl: 40, isHU: true,
            zoom: 1, panX: 0, panY: 0, rot: 0, flipH: false, flipV: false, invert: false,
            tool: 'wl', annos: [], draft: null, drag: null
        };
        this.ws = { studies: [], active: -1 };
        this._bind();
        this._bindScrollbar();
        this._bindCloseAll();
        this._bindSplitter();
        this._bindCtxMenu();
        this._disableChromeContext();
        this.restoreSidebarWidth();
        this.resize();
        var self = this;
        if (window.ResizeObserver) { this._ro = new ResizeObserver(function () { self.resize(); self.render(); }); this._ro.observe(this.stage); }
        else window.addEventListener('resize', this._onWinResize = function () { self.resize(); self.render(); });
        this.render();
        this.refreshControlState();
        this.boot();
    }

    /** 根据是否有影像 / 是否多帧，刷新工具栏与菜单按钮可用状态 */
    PvViewer.prototype.refreshControlState = function () {
        var bar = this.q('toolbar'); if (!bar) return;
        var hasImage = !!(this.st.series && this.st.series.length);
        var multi = hasImage && this.frameCount() > 1;
        var keepActs = { 'toggle-sidebar': 1, 'about': 1, 'back': 1 };
        Array.prototype.forEach.call(bar.querySelectorAll('[data-pv-tool],[data-pv-menu]'), function (b) { b.disabled = !hasImage; });
        Array.prototype.forEach.call(bar.querySelectorAll('[data-pv-act]'), function (b) {
            var a = b.getAttribute('data-pv-act');
            if (keepActs[a]) { b.disabled = false; return; }
            if (a === 'prev' || a === 'next') { b.disabled = !multi; return; }
            b.disabled = !hasImage;
        });
        // 无影像时取消工具按钮的选中态（避免灰置却显示被选中）
        if (!hasImage) {
            Array.prototype.forEach.call(bar.querySelectorAll('[data-pv-tool]'), function (b) { b.classList.remove('active'); });
        }
    };

    PvViewer.prototype.setStatus = function (m) { if (this.statusEl) this.statusEl.textContent = m || ''; };

    /** 释放资源（SPA 切换页面时调用） */
    PvViewer.prototype.destroy = function () {
        try { if (this._ro) this._ro.disconnect(); } catch (e) {}
        if (this._upH) window.removeEventListener('pointerup', this._upH);
        if (this._onWinResize) window.removeEventListener('resize', this._onWinResize);
        if (this.toolbar && this.toolbar.destroy) this.toolbar.destroy();
        if (this._sb && this._sb.hideTimer) clearTimeout(this._sb.hideTimer);
        if (this._ctxDoc) document.removeEventListener('pointerdown', this._ctxDoc, true);
        if (this._ctxViewport) {
            window.removeEventListener('resize', this._ctxViewport);
            window.removeEventListener('scroll', this._ctxViewport, true);
        }
        this.st.drag = null; this.st.draft = null;
    };

    /** 右侧帧滚动条：多帧时显示，单帧隐藏；拖动/点击实现连续滚动 */
    PvViewer.prototype.updateScrollbar = function () {
        if (!this.scrollEl || !this.scrollTrack || !this.scrollThumb) return;
        var n = this.frameCount();
        if (n <= 1) { this.scrollEl.hidden = true; this.scrollEl.classList.remove('show-bubble'); return; }
        this.scrollEl.hidden = false;
        var trackH = this.scrollTrack.clientHeight || this.scrollEl.clientHeight || 1;
        var thumbH = Math.max(28, Math.round(trackH / n));
        if (thumbH > trackH) thumbH = trackH;
        var maxTop = Math.max(0, trackH - thumbH);
        var ratio = this.st.fi / (n - 1);
        this.scrollThumb.style.height = thumbH + 'px';
        this.scrollThumb.style.top = Math.round(maxTop * ratio) + 'px';
    };
    PvViewer.prototype._bindScrollbar = function () {
        var self = this, track = this.scrollTrack, thumb = this.scrollThumb;
        if (!track) return;
        this._sb = { dragging: false, startY: 0, startTop: 0, hideTimer: null };
        function bubble(n, fi) {
            if (!self.scrollBubble) return;
            self.scrollBubble.textContent = (fi + 1) + ' / ' + n;
            self.scrollBubble.style.top = (thumb.offsetTop + thumb.offsetHeight / 2) + 'px';
            self.scrollEl.classList.add('show-bubble');
        }
        function scheduleHide() {
            clearTimeout(self._sb.hideTimer);
            self._sb.hideTimer = setTimeout(function () { self.scrollEl.classList.remove('show-bubble'); }, 900);
        }
        function setFromY(clientY, fromThumb) {
            var n = self.frameCount(); if (n <= 1) return;
            var rect = track.getBoundingClientRect();
            var trackH = rect.height;
            var thumbH = thumb.offsetHeight;
            var maxTop = Math.max(0, trackH - thumbH);
            var top;
            if (fromThumb) top = Math.max(0, Math.min(maxTop, self._sb.startTop + (clientY - self._sb.startY)));
            else top = Math.max(0, Math.min(maxTop, clientY - rect.top - thumbH / 2));
            var ratio = maxTop > 0 ? top / maxTop : 0;
            var fi = Math.round(ratio * (n - 1));
            self.setFrame(fi);
            bubble(n, fi); scheduleHide();
        }
        function onMove(e) { if (!self._sb.dragging) return; e.preventDefault(); setFromY(e.clientY, true); }
        function onUp() {
            if (!self._sb.dragging) return;
            self._sb.dragging = false;
            self.scrollEl.classList.remove('dragging');
            document.removeEventListener('pointermove', onMove);
            document.removeEventListener('pointerup', onUp);
        }
        track.addEventListener('pointerdown', function (e) {
            e.preventDefault();
            if (e.target === thumb) {
                self._sb.dragging = true; self._sb.startY = e.clientY; self._sb.startTop = thumb.offsetTop;
                self.scrollEl.classList.add('dragging');
                document.addEventListener('pointermove', onMove);
                document.addEventListener('pointerup', onUp);
            } else {
                setFromY(e.clientY, false);
            }
        });
        track.addEventListener('mouseleave', function () { if (!self._sb.dragging) self.scrollEl.classList.remove('show-bubble'); });
    };

    /** 底部「关闭全部」按钮 */
    PvViewer.prototype._bindCloseAll = function () {
        var self = this, btn = this.closeAllEl;
        if (!btn) return;
        btn.addEventListener('click', function () {
            if (!self.ws.studies.length) return;
            var run = function () { self.closeAll(); };
            if (window.PvModal) {
                PvModal.confirm({ title: '关闭全部', message: '确认清空影像视图中的全部检查序列？', okText: '关闭全部', danger: true }).then(function (ok) { if (ok) run(); });
            } else run();
        });
    };
    PvViewer.prototype.closeAll = function () {
        this.ws.studies = []; this.ws.active = -1;
        this.st.data = null; this.st.series = []; this.st.si = 0; this.st.fi = 0;
        this.clearState();
        this.renderSidebar();
        this.updateTitle();
        this.updateScrollbar();
        this.refreshControlState();
        this.render();
        this.setStatus('');
    };

    /** 禁用工具栏 / 序列栏 / 底栏的右击，避免误操作（视图画布仍保留右键菜单） */
    PvViewer.prototype._disableChromeContext = function () {
        var targets = [this.q('toolbar'), this.filmstripEl, document.querySelector('.pv-footer')];
        targets.forEach(function (el) {
            if (!el || el.__pvNoCtx) return;
            el.__pvNoCtx = true;
            el.addEventListener('contextmenu', function (e) { e.preventDefault(); });
        });
    };

    /** 序列栏宽度调节（仅本次登录有效，存入 sessionStorage） */
    PvViewer.prototype.applySidebarWidth = function (w) {
        if (!this.filmstripEl) return;
        w = Math.max(120, Math.min(480, Math.round(w)));
        this.filmstripEl.style.flexBasis = w + 'px';
        this.filmstripEl.style.width = w + 'px';
        this.sidebarWidth = w;
        this.resize();
    };
    PvViewer.prototype.restoreSidebarWidth = function () {
        try {
            var w = parseInt(sessionStorage.getItem('pacs_sidebar_w'), 10);
            if (w >= 120 && w <= 480) this.applySidebarWidth(w);
        } catch (e) {}
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
        sp.addEventListener('pointermove', function (e) {
            if (!dragging) return;
            self.applySidebarWidth(startW + (e.clientX - startX));
        });
        function stop() {
            if (!dragging) return;
            dragging = false; sp.classList.remove('dragging');
            try { sessionStorage.setItem('pacs_sidebar_w', String(self.sidebarWidth || 168)); } catch (e) {}
        }
        sp.addEventListener('pointerup', stop);
        sp.addEventListener('pointercancel', stop);
        sp.addEventListener('dblclick', function () { self.applySidebarWidth(168); try { sessionStorage.setItem('pacs_sidebar_w', '168'); } catch (e) {} });
    };

    /* ---------- 影像视图（多检查工作区） ---------- */
    PvViewer.prototype.indexOf = function (uid) {
        for (var i = 0; i < this.ws.studies.length; i++) if (this.ws.studies[i].uid === uid) return i;
        return -1;
    };
    PvViewer.prototype.activeStudy = function () { return this.ws.studies[this.ws.active] || null; };

    /** 启动：URL 指定检查则打开；追加模式下先恢复本会话已打开的影像再追加 */
    PvViewer.prototype.boot = function () {
        var self = this;
        if (this.route.uid) {
            if (this.route.mode === 'replace') { this.openStudy(this.route.uid, 'replace'); return; }
            this.restore(function () { self.openStudy(self.route.uid, 'append'); });
            return;
        }
        this.restore(function () {
            if (!self.ws.studies.length) { self.showEmpty(); return; }
            self.activate(self.pickRestoreActive(), false);
            self.setStatus('');
        });
    };
    PvViewer.prototype.pickRestoreActive = function () {
        var saved = this.loadState();
        if (saved && typeof saved.active === 'number' && saved.active >= 0 && saved.active < this.ws.studies.length) return saved.active;
        return this.ws.studies.length - 1;
    };
    /** 恢复本会话已打开检查（不重放读片日志） */
    PvViewer.prototype.restore = function (cb) {
        var self = this, saved = this.loadState();
        if (!saved || !saved.studies || !saved.studies.length) { if (cb) cb(); return; }
        this.setStatus('正在恢复影像视图…');
        var restored = [], chain = Promise.resolve();
        saved.studies.forEach(function (item) {
            chain = chain.then(function () {
                return PvApi.study(item.uid).then(function (j) {
                    if (j && j.code === 200 && j.data) {
                        restored.push({
                            uid: item.uid, data: j.data, series: j.data.series || [],
                            si: item.si || 0, fi: item.fi || 0, collapsed: !!item.collapsed
                        });
                    }
                }).catch(function () {});
            });
        });
        chain.then(function () { self.ws.studies = restored; if (cb) cb(); });
    };

    /** 打开检查：已存在则定位展开；replace 清空后打开，append 追加并收起其他 */
    PvViewer.prototype.openStudy = function (uid, mode) {
        var self = this;
        var idx = this.indexOf(uid);
        if (idx >= 0) { this.activate(idx, true); this.setStatus('该检查已在影像视图中打开，已定位'); return; }
        this.setStatus('正在加载影像数据…');
        PvApi.study(uid).then(function (j) {
            if (!j || j.code !== 200 || !j.data) { self.setStatus((j && j.msg) || '数据加载失败'); return; }
            if (mode === 'replace') { self.ws.studies = []; self.ws.active = -1; }
            self.ws.studies.push({ uid: uid, data: j.data, series: j.data.series || [], si: 0, fi: 0, collapsed: false });
            while (self.ws.studies.length > self.studyLimit) self.ws.studies.shift();
            self.ws.studies.forEach(function (x, k) { x.collapsed = (k !== self.ws.studies.length - 1); });
            self.activate(self.ws.studies.length - 1, false);
            self.logEvent('read');
            self.setStatus('');
        }).catch(function () { self.setStatus('网络请求失败'); });
    };

    /** 激活某个检查（expand=true 时展开其序列） */
    PvViewer.prototype.activate = function (i, expand) {
        if (i < 0 || i >= this.ws.studies.length) return;
        this.ws.active = i;
        var s = this.ws.studies[i];
        if (expand) s.collapsed = false;
        this.st.data = s.data;
        this.st.series = s.series || [];
        this.st.si = (s.si >= 0 && s.si < this.st.series.length) ? s.si : 0;
        this.st.fi = s.fi || 0;
        this._wheelAcc = 0;
        this.st.annos = []; this.st.draft = null;
        this.renderSidebar();
        this.updateTitle();
        this.applyDefaults();
        this.fit();
        this.toolbar.sync(this.st);
        this.updateScrollbar();
        this.refreshControlState();
        this.persist();
    };

    PvViewer.prototype.renderSidebar = function () {
        var self = this;
        this.sidebar.renderStudies(this.ws.studies, this.ws.active, {
            onSeries: function (gi, si) { if (gi !== self.ws.active) { self.activate(gi, true); } self.setSeries(si); },
            onToggle: function (gi) { var s = self.ws.studies[gi]; if (!s) return; s.collapsed = !s.collapsed; self.renderSidebar(); self.persist(); },
            onClose: function (gi) { self.removeStudy(gi); }
        });
    };
    PvViewer.prototype.removeStudy = function (gi) {
        if (gi < 0 || gi >= this.ws.studies.length) return;
        var wasActive = this.ws.active;
        this.ws.studies.splice(gi, 1);
        if (!this.ws.studies.length) { this.closeAll(); return; }
        var act;
        if (gi < wasActive) act = wasActive - 1;
        else if (gi === wasActive) act = Math.min(gi, this.ws.studies.length - 1);
        else act = wasActive;
        this.ws.active = -1;
        this.activate(act, false);
    };

    PvViewer.prototype.showEmpty = function () {
        this.ws.studies = []; this.ws.active = -1;
        this.st.data = null; this.st.series = []; this.st.si = 0; this.st.fi = 0;
        this.renderSidebar();
        this.updateTitle();
        this.updateScrollbar();
        this.refreshControlState();
        this.render();
        this.setStatus('');
    };

    PvViewer.prototype.updateTitle = function () {
        if (!this.titleEl) return;
        var s = (this.st.data && this.st.data.study) || {};
        this.titleEl.textContent = this.st.series.length
            ? ((s.modality || '') + ' · ' + (s.description || '') + (s.accession_no ? ' · ' + s.accession_no : ''))
            : '';
    };

    /* ---------- 会话记忆 ---------- */
    PvViewer.prototype.persist = function () {
        try {
            var state = {
                active: this.ws.active,
                studies: this.ws.studies.map(function (x) { return { uid: x.uid, si: x.si, fi: x.fi, collapsed: !!x.collapsed }; })
            };
            sessionStorage.setItem('pacs_workspace_v1', JSON.stringify(state));
        } catch (e) {}
    };
    PvViewer.prototype.loadState = function () {
        try { return JSON.parse(sessionStorage.getItem('pacs_workspace_v1')); } catch (e) { return null; }
    };
    PvViewer.prototype.clearState = function () {
        try { sessionStorage.removeItem('pacs_workspace_v1'); } catch (e) {}
    };

    PvViewer.prototype.logEvent = function (action, extra) {
        if (!window.PvApi || !PvApi.log) return;
        var d = this.st.data || {}, p = d.patient || {}, s = d.study || {};
        var info = (p.name || '') + ' / ' + (s.modality || '') + ' / ' + (s.description || '');
        PvApi.log(action, extra ? (info + ' / ' + extra) : info);
    };

    /* ---------- 鼠标处 CT 值 ---------- */
    PvViewer.prototype._setHU = function (text) {
        if (!this.huEl) return;
        if (text) { this.huEl.textContent = text; this.huEl.style.display = 'block'; }
        else { this.huEl.style.display = 'none'; }
    };
    PvViewer.prototype._updateHU = function (e) {
        if (!this.huEl) return;
        var ser = this.curSeries();
        if (!ser) { this._setHU(''); return; }
        var pt = this._rel(e), p = this.screenToImg(pt.x, pt.y);
        var x = Math.floor(p.x), y = Math.floor(p.y);
        if (x < 0 || y < 0 || x >= BASE || y >= BASE) { this._setHU(''); return; }
        if (ser.is_mock) {
            var img = this.getMock(ser, this.st.fi);
            var b = img.data[(y * BASE + x) * 4];
            var hu = Math.round(b / 255 * (PvRender.HU_MAX - PvRender.HU_MIN) + PvRender.HU_MIN);
            this._setHU('HU ' + hu + '　(' + x + ', ' + y + ')');
        } else {
            try {
                var d = this.raw.getContext('2d').getImageData(x, y, 1, 1).data;
                this._setHU('灰度 ' + d[0] + '　(' + x + ', ' + y + ')');
            } catch (err) { this._setHU('(' + x + ', ' + y + ')'); }
        }
    };

    /* ---------- 右键快捷菜单 ---------- */
    PvViewer.prototype._ctxItem = function (o) {
        if (o.sep) return '<div class="pv-ctx-sep"></div>';
        var arrow = o.sub ? '<span class="arrow">▶</span>' : '';
        var sub = o.sub ? '<div class="pv-ctx-sub">' + o.sub.map(this._ctxItem.bind(this)).join('') + '</div>' : '';
        var attrs = '';
        if (o.tool) attrs += ' data-tool="' + o.tool + '"';
        if (o.act) attrs += ' data-act="' + o.act + '"';
        if (o.preset) attrs += ' data-preset="' + o.preset + '"';
        var icon = o.icon ? '<span class="ic">' + o.icon + '</span>' : '';
        return '<div class="pv-ctx-item"' + attrs + '>' + icon + '<span class="lb">' + esc(o.label) + '</span>' + arrow + sub + '</div>';
    };
    PvViewer.prototype.openCtxMenu = function (cx, cy) {
        var el = this.ctxEl; if (!el) return;
        var items = [
            { label: '预设窗', icon: '🎚', sub: [
                { label: '软组织窗 (400/40)', preset: 'soft', icon: '🟫' },
                { label: '肺窗 (1500/-600)', preset: 'lung', icon: '🫁' },
                { label: '骨窗 (2000/350)', preset: 'bone', icon: '🦴' },
                { label: '默认窗 (2500/250)', preset: 'full', icon: '🖼' }
            ] },
            { label: '缩放', icon: '🔍', tool: 'zoom' },
            { label: '平移', icon: '✥', tool: 'pan' },
            { label: '使用窗口', icon: '◐', tool: 'wl' },
            { label: '原图 1:1', icon: '🖼', act: 'oneone' },
            { sep: true },
            { label: '测量', icon: '📏', sub: [
                { label: '测距（mm）', tool: 'length', icon: '📏' },
                { label: '测角（°）', tool: 'angle', icon: '📐' },
                { label: '矩形 ROI', tool: 'rect', icon: '▭' },
                { label: '椭圆 ROI', tool: 'ellipse', icon: '⬭' },
                { sep: true },
                { label: '清除标注', act: 'clear', icon: '🧹' }
            ] },
            { label: '变换', icon: '🔄', sub: [
                { label: '逆时针 90°', act: 'rotate-ccw', icon: '↺' },
                { label: '顺时针 90°', act: 'rotate-cw', icon: '↻' },
                { label: '水平镜像', act: 'flip-h', icon: '⇋' },
                { label: '垂直镜像', act: 'flip-v', icon: '⇅' },
                { label: '正负片反色', act: 'invert', icon: '◑' }
            ] },
            { sep: true },
            { label: '关于', icon: 'ℹ️', act: 'about' }
        ];
        // 无影像时仅保留「关于」
        if (!this.st.series || !this.st.series.length) {
            items = [{ label: '关于', icon: 'ℹ️', act: 'about' }];
        }
        el.innerHTML = items.map(this._ctxItem.bind(this)).join('');
        el.classList.add('open');
        var w = el.offsetWidth, h = el.offsetHeight;
        var left = Math.max(8, Math.min(cx, window.innerWidth - w - 8));
        var top = Math.max(8, Math.min(cy, window.innerHeight - h - 8));
        el.style.left = left + 'px';
        el.style.top = top + 'px';
    };
    PvViewer.prototype.closeCtxMenu = function () { if (this.ctxEl) this.ctxEl.classList.remove('open'); };
    PvViewer.prototype._bindCtxMenu = function () {
        var self = this, el = this.ctxEl; if (!el) return;
        el.addEventListener('click', function (e) {
            var it = e.target.closest ? e.target.closest('.pv-ctx-item') : null;
            if (!it) return;
            if (it.querySelector('.pv-ctx-sub')) return;   // 含子菜单，交给 hover 展开
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

    PvViewer.prototype.applyDefaults = function () {
        var s = this.st.data && this.st.data.study ? this.st.data.study : {};
        if (this.frameIsHU()) {
            if (s.default_ww && s.default_wl) { this.st.ww = parseInt(s.default_ww, 10) || 400; this.st.wl = parseInt(s.default_wl, 10) || 40; }
            else { var m = (s.modality || '').toUpperCase(); var pre = (m === 'DR' || m === 'MG') ? PRESETS.full : PRESETS.soft; this.st.ww = pre.ww; this.st.wl = pre.wl; }
        } else { this.st.ww = 256; this.st.wl = 128; }
        this.st.isHU = this.frameIsHU();
    };

    PvViewer.prototype.curSeries = function () { return this.st.series[this.st.si] || null; };
    PvViewer.prototype.frameIsHU = function () { var s = this.curSeries(); return !s || s.is_mock; };
    PvViewer.prototype.frameCount = function () { var s = this.curSeries(); return s ? Math.max(1, s.slice_count || (s.images ? s.images.length : 1)) : 0; };

    PvViewer.prototype.resize = function () {
        var box = this.stage.getBoundingClientRect();
        var w = Math.max(2, Math.floor(box.width)), h = Math.max(2, Math.floor(box.height));
        this.cssW = w; this.cssH = h;
        this.canvas.width = Math.floor(w * this.dpr); this.canvas.height = Math.floor(h * this.dpr);
        this.canvas.style.width = w + 'px'; this.canvas.style.height = h + 'px';
        this.updateScrollbar();
    };

    PvViewer.prototype.getMock = function (series, frame) {
        var key = series.seed + '#' + frame;
        if (this.cache[key]) return this.cache[key];
        var idx = clamp(frame, 0, Math.max(0, (series.slice_count || 1) - 1));
        var img = PvRender.makeSlice(series.orientation || 'AXIAL', series.seed, idx, series.slice_count || 1);
        this.cache[key] = img; this.cacheKeys.push(key);
        if (this.cacheKeys.length > 80) delete this.cache[this.cacheKeys.shift()];
        return img;
    };
    PvViewer.prototype.getRealImage = function (src, cb) {
        if (this.imgCache[src]) { if (this.imgCache[src].complete) cb(this.imgCache[src]); return; }
        var im = new Image(), self = this;
        im.onload = function () { self.render(); cb(im); };
        im.src = src; this.imgCache[src] = im;
    };
    PvViewer.prototype.currentSource = function () {
        var s = this.curSeries(); if (!s) return null;
        if (s.is_mock) return { kind: 'data', img: this.getMock(s, this.st.fi) };
        var src = s.images && s.images[this.st.fi];
        if (!src) return null;
        var im = this.imgCache[src];
        if (!im) { this.getRealImage(src, function () {}); return { kind: 'loading' }; }
        if (!im.complete) return { kind: 'loading' };
        return { kind: 'image', img: im };
    };

    PvViewer.prototype.windowed = function (imgData, isHU, ww, wl, invert) {
        ww = Math.max(1, ww);
        var lo = wl - ww / 2, k = 255 / ww, lut = new Uint8Array(256);
        for (var v = 0; v < 256; v++) {
            var val = isHU ? (v / 255 * (HU_MAX - HU_MIN) + HU_MIN) : v;
            var o = (val - lo) * k; o = o < 0 ? 0 : (o > 255 ? 255 : o);
            if (invert) o = 255 - o; lut[v] = o;
        }
        var w = imgData.width, h = imgData.height;
        if (!this._out || this._out.width !== w || this._out.height !== h) { this.work.width = w; this.work.height = h; this._out = this.wctx.createImageData(w, h); }
        var sd = imgData.data, od = this._out.data;
        for (var i = 0, n = w * h; i < n; i++) { var j = i * 4, g = lut[sd[j]]; od[j] = od[j + 1] = od[j + 2] = g; od[j + 3] = 255; }
        this.wctx.putImageData(this._out, 0, 0);
        return this.work;
    };

    PvViewer.prototype.render = function () {
        var ctx = this.ctx, st = this.st;
        if (!ctx) return;
        ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
        ctx.fillStyle = '#000'; ctx.fillRect(0, 0, this.cssW, this.cssH);
        var src = this.currentSource(), winCanvas = null;
        if (src && src.kind === 'data') {
            winCanvas = this.windowed(src.img, st.isHU, st.ww, st.wl, st.invert);
        } else if (src && src.kind === 'image') {
            var im = src.img, rc = this.raw.getContext('2d');
            rc.setTransform(1, 0, 0, 1, 0, 0); rc.fillStyle = '#000'; rc.fillRect(0, 0, BASE, BASE);
            var sc = Math.min(BASE / im.width, BASE / im.height), dw = im.width * sc, dh = im.height * sc;
            rc.drawImage(im, (BASE - dw) / 2, (BASE - dh) / 2, dw, dh);
            var data = rc.getImageData(0, 0, BASE, BASE);
            for (var i = 0; i < data.data.length; i += 4) {
                var l = (data.data[i] * .299 + data.data[i + 1] * .587 + data.data[i + 2] * .114) | 0;
                data.data[i] = data.data[i + 1] = data.data[i + 2] = l;
            }
            winCanvas = this.windowed(data, false, st.ww, st.wl, st.invert);
        } else if (src && src.kind === 'loading') {
            this.placeholder('正在解码图像…');
        } else {
            this.placeholder(this.st.series.length ? '暂无影像' : '请在「研究检索」中选择检查\n或点击顶部「影像」查看已打开的检查', true);
        }
        if (winCanvas) {
            ctx.save();
            ctx.translate(this.cssW / 2 + st.panX, this.cssH / 2 + st.panY);
            ctx.rotate(st.rot * Math.PI / 180);
            ctx.scale(st.zoom * (st.flipH ? -1 : 1), st.zoom * (st.flipV ? -1 : 1));
            ctx.imageSmoothingEnabled = st.zoom < 1;
            ctx.drawImage(winCanvas, -BASE / 2, -BASE / 2);
            ctx.restore();
        }
        if (this.st.data && this.st.series.length) {
            this.drawAnnotations();
            PvOsd.draw(ctx, {
                cssW: this.cssW, cssH: this.cssH, dpr: this.dpr, data: this.st.data,
                series: this.curSeries(), fi: st.fi, count: this.frameCount(),
                ww: st.ww, wl: st.wl, zoom: st.zoom, rot: st.rot, flipH: st.flipH, flipV: st.flipV
            });
        }
    };
    PvViewer.prototype.placeholder = function (t, big) {
        var ctx = this.ctx;
        ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
        ctx.fillStyle = big ? '#475569' : '#64748b';
        ctx.font = (big ? '15px' : '14px') + ' sans-serif';
        ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        var lines = String(t).split('\n');
        var lh = big ? 26 : 20;
        var y0 = this.cssH / 2 - (lines.length - 1) * lh / 2;
        for (var i = 0; i < lines.length; i++) ctx.fillText(lines[i], this.cssW / 2, y0 + i * lh);
    };

    PvViewer.prototype._trig = function () { var r = this.st.rot * Math.PI / 180; return { c: Math.cos(r), s: Math.sin(r) }; };
    PvViewer.prototype.imgToScreen = function (x, y) {
        var t = this._trig(), fh = this.st.flipH ? -1 : 1, fv = this.st.flipV ? -1 : 1;
        var x1 = (x - BASE / 2) * this.st.zoom * fh, y1 = (y - BASE / 2) * this.st.zoom * fv;
        return { x: this.cssW / 2 + this.st.panX + x1 * t.c - y1 * t.s, y: this.cssH / 2 + this.st.panY + x1 * t.s + y1 * t.c };
    };
    PvViewer.prototype.screenToImg = function (sx, sy) {
        var t = this._trig(), fh = this.st.flipH ? -1 : 1, fv = this.st.flipV ? -1 : 1;
        var dx = sx - (this.cssW / 2 + this.st.panX), dy = sy - (this.cssH / 2 + this.st.panY);
        var x1 = dx * t.c + dy * t.s, y1 = -dx * t.s + dy * t.c;
        return { x: x1 / (this.st.zoom * fh) + BASE / 2, y: y1 / (this.st.zoom * fv) + BASE / 2 };
    };

    /* ---------- 标注 ---------- */
    PvViewer.prototype.drawAnnotations = function () {
        var st = this.st, ctx = this.ctx;
        ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
        var all = st.annos.slice();
        if (st.draft) { var dd = this._draftView(); if (dd) all.push(dd); }
        for (var i = 0; i < all.length; i++) this._drawAnno(ctx, all[i]);
    };
    PvViewer.prototype._draftView = function () {
        var d = this.st.draft; if (!d) return null;
        var pts = d.fixed.slice();
        if (d.hover && (d.type === 'length' || d.type === 'angle')) pts.push(d.hover);
        return { type: d.type, pts: pts, color: '#facc15' };
    };
    PvViewer.prototype._line = function (ctx, a, b, color) {
        var A = this.imgToScreen(a.x, a.y), B = this.imgToScreen(b.x, b.y);
        ctx.save(); ctx.strokeStyle = color; ctx.lineWidth = 1.4; ctx.setLineDash([5, 4]);
        ctx.beginPath(); ctx.moveTo(A.x, A.y); ctx.lineTo(B.x, B.y); ctx.stroke(); ctx.setLineDash([]);
        ctx.fillStyle = color;
        [A, B].forEach(function (P) { ctx.beginPath(); ctx.arc(P.x, P.y, 3, 0, Math.PI * 2); ctx.fill(); });
        ctx.restore();
    };
    PvViewer.prototype._label = function (ctx, x, y, txt) {
        ctx.save(); ctx.font = '12px monospace'; ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
        ctx.shadowColor = 'rgba(0,0,0,.9)'; ctx.shadowBlur = 3;
        var w = ctx.measureText(txt).width + 8;
        ctx.fillStyle = 'rgba(16,185,129,.88)'; ctx.fillRect(x, y - 9, w, 18);
        ctx.shadowBlur = 0; ctx.fillStyle = '#04140d'; ctx.fillText(txt, x + 4, y); ctx.restore();
    };
    PvViewer.prototype._drawAnno = function (ctx, a) {
        var color = a.color || '#10b981', ps = (this.curSeries() && this.curSeries().pixel_spacing) || 0.7;
        if (a.type === 'length' && a.pts.length >= 2) {
            this._line(ctx, a.pts[0], a.pts[1], color);
            var mm = PvMeasure.distMM(a.pts[0], a.pts[1], ps), px = Math.round(PvMeasure.distPx(a.pts[0], a.pts[1]));
            var mid = this.imgToScreen((a.pts[0].x + a.pts[1].x) / 2, (a.pts[0].y + a.pts[1].y) / 2);
            this._label(ctx, mid.x + 6, mid.y, mm.toFixed(1) + ' mm (' + px + ' px)');
        } else if (a.type === 'angle' && a.pts.length >= 3) {
            this._line(ctx, a.pts[0], a.pts[1], color); this._line(ctx, a.pts[1], a.pts[2], color);
            var V = this.imgToScreen(a.pts[1].x, a.pts[1].y);
            this._label(ctx, V.x + 8, V.y - 12, PvMeasure.angleDeg(a.pts[0], a.pts[1], a.pts[2]).toFixed(1) + '\u00b0');
        } else if ((a.type === 'rect' || a.type === 'ellipse') && a.pts.length >= 2) {
            var A = this.imgToScreen(a.pts[0].x, a.pts[0].y), B = this.imgToScreen(a.pts[1].x, a.pts[1].y);
            var x = Math.min(A.x, B.x), y = Math.min(A.y, B.y), w = Math.abs(B.x - A.x), h = Math.abs(B.y - A.y);
            ctx.save(); ctx.strokeStyle = color; ctx.lineWidth = 1.4; ctx.setLineDash([5, 4]); ctx.beginPath();
            if (a.type === 'rect') ctx.rect(x, y, w, h); else ctx.ellipse((A.x + B.x) / 2, (A.y + B.y) / 2, w / 2, h / 2, 0, 0, Math.PI * 2);
            ctx.stroke(); ctx.setLineDash([]);
            if (a.stats) {
                var lines = ['A: ' + a.stats.area.toFixed(1) + ' mm\u00b2', 'Mean: ' + a.stats.mean.toFixed(1) + (a.stats.hu ? ' HU' : '')];
                var lx = x, ly = y + h + 14;
                ctx.font = '11px monospace'; ctx.textAlign = 'left'; ctx.textBaseline = 'middle'; ctx.shadowColor = 'rgba(0,0,0,.9)'; ctx.shadowBlur = 3;
                lines.forEach(function (t, k) {
                    ctx.fillStyle = 'rgba(16,185,129,.9)'; ctx.fillRect(lx, ly + k * 16 - 8, ctx.measureText(t).width + 8, 16);
                    ctx.shadowBlur = 0; ctx.fillStyle = '#04140d'; ctx.fillText(t, lx + 4, ly + k * 16); ctx.shadowBlur = 3;
                });
            }
            ctx.restore();
        } else if (a.pts) {
            a.pts.forEach(function (p) { var P = this.imgToScreen(p.x, p.y); ctx.save(); ctx.fillStyle = color; ctx.beginPath(); ctx.arc(P.x, P.y, 3, 0, Math.PI * 2); ctx.fill(); ctx.restore(); }, this);
        }
    };

    /* ---------- 交互 ---------- */
    PvViewer.prototype._rel = function (e) { var r = this.canvas.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; };
    PvViewer.prototype._bind = function () {
        var self = this, cv = this.canvas;
        cv.addEventListener('contextmenu', function (e) { e.preventDefault(); });
        cv.addEventListener('wheel', function (e) { self.onWheel(e); }, { passive: false });
        cv.addEventListener('pointerdown', function (e) { self.onDown(e); });
        cv.addEventListener('pointermove', function (e) { self.onMove(e); self._updateHU(e); });
        cv.addEventListener('pointerleave', function () { self._setHU(''); });
        window.addEventListener('pointerup', this._upH = function (e) { self.onUp(e); });
    };
    PvViewer.prototype.onDown = function (e) {
        var pt = this._rel(e), st = this.st;
        try { this.canvas.setPointerCapture(e.pointerId); } catch (err) {}
        if (e.button === 1) { st.drag = { mode: 'pan', x: pt.x, y: pt.y, px: st.panX, py: st.panY }; return; }
        if (e.button === 2) { st.drag = { mode: 'wl', x: pt.x, y: pt.y, ww: st.ww, wl: st.wl, btn: 2, cx: e.clientX, cy: e.clientY, moved: false }; return; }
        var p = this.screenToImg(pt.x, pt.y), t = st.tool;
        if (t === 'wl') st.drag = { mode: 'wl', x: pt.x, y: pt.y, ww: st.ww, wl: st.wl };
        else if (t === 'pan') st.drag = { mode: 'pan', x: pt.x, y: pt.y, px: st.panX, py: st.panY };
        else if (t === 'zoom') st.drag = { mode: 'zoom', y: pt.y, z: st.zoom };
        else if (t === 'length' || t === 'angle') this._addPoint(t, p);
        else if (t === 'rect' || t === 'ellipse') st.draft = { type: t, fixed: [p, p] };
        this.render();
    };
    PvViewer.prototype.onMove = function (e) {
        var st = this.st, pt = this._rel(e);
        if (st.drag) {
            if (st.drag.btn === 2 && (Math.abs(pt.x - st.drag.x) + Math.abs(pt.y - st.drag.y)) > 4) st.drag.moved = true;
            if (st.drag.mode === 'pan') { st.panX = st.drag.px + (pt.x - st.drag.x); st.panY = st.drag.py + (pt.y - st.drag.y); }
            else if (st.drag.mode === 'wl') { var sc = st.isHU ? 4 : 2; st.ww = clamp(st.drag.ww + (pt.x - st.drag.x) * sc, 1, 6000); st.wl = clamp(st.drag.wl - (pt.y - st.drag.y) * sc, -1200, 3000); }
            else if (st.drag.mode === 'zoom') { this._zoomTo(st.drag.z * Math.exp((st.drag.y - pt.y) / 180), pt.x, pt.y); }
            this.render(); return;
        }
        if (st.draft) {
            var p = this.screenToImg(pt.x, pt.y);
            if (st.draft.type === 'rect' || st.draft.type === 'ellipse') st.draft.fixed[1] = p; else st.draft.hover = p;
            this.render();
        }
    };
    PvViewer.prototype.onUp = function () {
        var st = this.st;
        if (st.drag) {
            if (st.drag.btn === 2 && !st.drag.moved) this.openCtxMenu(st.drag.cx, st.drag.cy);
            st.drag = null; return;
        }
        if (st.draft && (st.draft.type === 'rect' || st.draft.type === 'ellipse')) {
            var d = st.draft;
            if (Math.abs(d.fixed[0].x - d.fixed[1].x) > 2 && Math.abs(d.fixed[0].y - d.fixed[1].y) > 2) {
                st.annos.push({ type: d.type, pts: d.fixed.slice(), stats: this._roiStats(d.fixed, d.type), color: '#10b981' });
            }
            st.draft = null; this.render();
        }
    };
    PvViewer.prototype._zoomTo = function (z, sx, sy) {
        z = clamp(z, 0.12, 16);
        var b = this.screenToImg(sx, sy); this.st.zoom = z;
        var m = this.imgToScreen(b.x, b.y);
        this.st.panX += sx - m.x; this.st.panY += sy - m.y;
    };
    PvViewer.prototype.onWheel = function (e) {
        e.preventDefault();
        var st = this.st, pt = this._rel(e);
        if (st.tool === 'zoom' || e.ctrlKey || e.metaKey) { this._zoomTo(st.zoom * (e.deltaY < 0 ? 1.12 : 1 / 1.12), pt.x, pt.y); this.render(); return; }
        var n = this.frameCount();
        if (n > 1) {
            // 归一化滚轮增量并按阈值累积，避免高精度滚轮 / 触控板「一滚到底」
            var dy = e.deltaY;
            if (e.deltaMode === 1) dy *= 16;         // 以「行」为单位
            else if (e.deltaMode === 2) dy *= 100;   // 以「页」为单位
            this._wheelAcc = (this._wheelAcc || 0) + dy;
            var step = 0, TH = 100;
            while (Math.abs(this._wheelAcc) >= TH) {
                step += this._wheelAcc > 0 ? 1 : -1;
                this._wheelAcc -= this._wheelAcc > 0 ? TH : -TH;
            }
            if (step !== 0) this.setFrame(st.fi + step);
            return;
        }
        this._zoomTo(st.zoom * (e.deltaY < 0 ? 1.12 : 1 / 1.12), pt.x, pt.y);
        this.render();
    };
    PvViewer.prototype._addPoint = function (type, p) {
        var st = this.st;
        if (!st.draft || st.draft.type !== type) st.draft = { type: type, fixed: [], hover: null };
        st.draft.fixed.push(p); st.draft.hover = null;
        if (st.draft.fixed.length >= (type === 'length' ? 2 : 3)) {
            st.annos.push({ type: type, pts: st.draft.fixed.slice(), color: '#10b981' });
            st.draft = null;
        }
        this.render();
    };
    PvViewer.prototype._roiStats = function (pts, type) {
        var src = this.currentSource(); if (!src) return null;
        var data;
        if (src.kind === 'data') data = src.img;
        else if (src.kind === 'image') data = this.raw.getContext('2d').getImageData(0, 0, BASE, BASE);
        else return null;
        var ps = (this.curSeries() && this.curSeries().pixel_spacing) || 0.7;
        return PvMeasure.roiStats(data, pts, type, this.frameIsHU(), ps);
    };

    /* ---------- 工具 / 公共操作 ---------- */
    PvViewer.prototype.setTool = function (t) {
        this.st.tool = t; this.st.draft = null;
        this.canvas.style.cursor = (t === 'pan') ? 'grab' : 'crosshair';
        this.toolbar.sync(this.st);
        this.setStatus(({ wl: '窗宽窗位：左右拖动改 WW，上下拖动改 WL', zoom: '缩放：拖动或滚轮（以指针为中心）', pan: '平移：拖动移动画布', length: '测距：依次点击两点', angle: '测角：依次点击三点', rect: '矩形 ROI：拖拽框选', ellipse: '椭圆 ROI：拖拽框选' })[t] || '');
        this.render();
    };
    PvViewer.prototype.setPreset = function (k) {
        var p = PRESETS[k]; if (!p) return;
        this.st.ww = p.ww; this.st.wl = p.wl;
        Array.prototype.forEach.call(this.q('toolbar') ? this.q('toolbar').querySelectorAll('[data-pv-preset]') : [], function (el) { el.classList.toggle('active', el.getAttribute('data-pv-preset') === k); });
        this.setStatus(p.label + ' · WW ' + p.ww + ' / WL ' + p.wl);
        this.render();
    };
    PvViewer.prototype.doAction = function (a) {
        var st = this.st;
        if (a === 'rotate-cw') st.rot = (st.rot + 90) % 360;
        else if (a === 'rotate-ccw') st.rot = (st.rot - 90 + 360) % 360;
        else if (a === 'flip-h') st.flipH = !st.flipH;
        else if (a === 'flip-v') st.flipV = !st.flipV;
        else if (a === 'invert') st.invert = !st.invert;
        else if (a === 'clear') { st.annos = []; st.draft = null; }
        else if (a === 'fit') { this.fit(); return; }
        else if (a === 'oneone') { st.zoom = 1; st.panX = 0; st.panY = 0; }
        else if (a === 'prev') { this.setFrame(st.fi - 1); return; }
        else if (a === 'next') { this.setFrame(st.fi + 1); return; }
        else if (a === 'toggle-sidebar') {
            if (this.filmstripEl) {
                this.filmstripEl.classList.toggle('is-hidden');
                var visible = !this.filmstripEl.classList.contains('is-hidden');
                this.setStatus(visible ? '序列栏已显示' : '序列栏已隐藏');
            }
            this.toolbar.sync(st); this.resize(); this.render(); return;
        }
        else if (a === 'copy-link') { this.copyDirectLink(); return; }
        else if (a === 'about') { this.showAbout(); return; }
        else if (a === 'dicom-info') { this.showDicomInfo(); return; }
        else if (a === 'save-image') { this.saveImage(); return; }
        else if (a === 'save-series') { this.saveSeries(); return; }
        else if (a === 'back') { this.confirmExit(); return; }
        this.toolbar.sync(st); this.render();
    };

    /* ---------- 导出 / 保存 ---------- */
    PvViewer.prototype.fileBase = function () {
        var d = this.st.data || {}, p = d.patient || {}, s = d.study || {}, ser = this.curSeries() || {};
        return [p.patient_id || 'patient', s.accession_no || s.study_uid || 'study', 'ser' + (ser.series_id || 1)]
            .join('_').replace(/[^\w.-]+/g, '_');
    };
    PvViewer.prototype._triggerDownload = function (url, filename) {
        var a = document.createElement('a'); a.href = url; a.download = filename;
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(url); }, 1800);
    };
    /** 将某一帧渲染为独立画布（含当前窗宽窗位 / 反色；不叠加标注与 OSD） */
    PvViewer.prototype.exportFrameCanvas = function (fi) {
        var self = this, ser = this.curSeries();
        var cv = document.createElement('canvas'); cv.width = BASE; cv.height = BASE;
        var cx = cv.getContext('2d'); cx.fillStyle = '#000'; cx.fillRect(0, 0, BASE, BASE);
        if (!ser) return Promise.resolve(cv);
        if (ser.is_mock) {
            var img = this.getMock(ser, fi);
            cx.drawImage(this.windowed(img, this.st.isHU, this.st.ww, this.st.wl, this.st.invert), 0, 0);
            return Promise.resolve(cv);
        }
        var src = ser.images && ser.images[fi];
        if (!src) return Promise.resolve(cv);
        return new Promise(function (resolve) {
            var im = new Image();
            im.onload = function () {
                var rc = self.raw.getContext('2d');
                rc.setTransform(1, 0, 0, 1, 0, 0); rc.fillStyle = '#000'; rc.fillRect(0, 0, BASE, BASE);
                var sc = Math.min(BASE / im.width, BASE / im.height), dw = im.width * sc, dh = im.height * sc;
                rc.drawImage(im, (BASE - dw) / 2, (BASE - dh) / 2, dw, dh);
                var data = rc.getImageData(0, 0, BASE, BASE);
                for (var i = 0; i < data.data.length; i += 4) {
                    var l = (data.data[i] * .299 + data.data[i + 1] * .587 + data.data[i + 2] * .114) | 0;
                    data.data[i] = data.data[i + 1] = data.data[i + 2] = l;
                }
                cx.drawImage(self.windowed(data, false, self.st.ww, self.st.wl, self.st.invert), 0, 0);
                resolve(cv);
            };
            im.onerror = function () { resolve(cv); };
            im.src = src;
        });
    };
    /** 保存当前画面（含标注 / OSD 的截图） */
    PvViewer.prototype.saveImage = function () {
        var self = this, name = this.fileBase() + '_im' + (this.st.fi + 1) + '.png';
        try {
            this.canvas.toBlob(function (blob) {
                if (!blob) return;
                self._triggerDownload(URL.createObjectURL(blob), name);
                self.setStatus('已保存当前图像：' + name);
                self.logEvent('download', '当前图像 ' + name);
            }, 'image/png');
        } catch (e) { this.setStatus('当前画面包含跨域内容，无法导出'); }
    };
    /** 保存整个序列为 ZIP（每帧一张 PNG） */
    PvViewer.prototype.saveSeries = function () {
        if (!window.PvZip) { this.setStatus('导出组件未就绪'); return; }
        var self = this, n = this.frameCount(), base = this.fileBase();
        if (n <= 0) return;
        this.setStatus('正在导出序列（0/' + n + '）…');
        var chain = Promise.resolve(), files = [];
        for (var i = 0; i < n; i++) {
            (function (fi) {
                chain = chain.then(function () {
                    return self.exportFrameCanvas(fi).then(function (cv) {
                        return new Promise(function (resolve) {
                            cv.toBlob(function (blob) {
                                files.push({ name: base + '_im' + (fi + 1) + '.png', data: blob });
                                self.setStatus('正在导出序列（' + (fi + 1) + '/' + n + '）…');
                                resolve();
                            }, 'image/png');
                        });
                    });
                });
            })(i);
        }
        chain.then(function () {
            self.setStatus('正在打包 ZIP…');
            return window.PvZip.create(files);
        }).then(function (zip) {
            self._triggerDownload(URL.createObjectURL(zip), base + '.zip');
            self.setStatus('已导出序列：' + base + '.zip（' + n + ' 帧）');
            self.logEvent('download', '序列 ZIP ' + base + '（' + n + ' 帧）');
        }).catch(function () { self.setStatus('序列导出失败'); });
    };

    /** 退出阅片：确认后关闭全部检查、清空搜索状态并返回研究检索 */
    PvViewer.prototype.confirmExit = function () {
        var self = this;
        var go = function () {
            self.closeAll();
            try { sessionStorage.removeItem('pacs_search_v1'); } catch (e) {}
            if (window.PvNav) window.PvNav.go('search');
        };
        if (window.PvModal) {
            PvModal.confirm({
                title: '退出阅片',
                message: '退出将关闭全部已打开的检查，并清空检索记录，确认退出？',
                okText: '退出', danger: true
            }).then(function (ok) { if (ok) go(); });
        } else go();
    };

    /* ---------- 关于 ---------- */
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

    /* ---------- DICOM 详情 ---------- */
    PvViewer.prototype.showDicomInfo = function () {
        if (!window.PvModal) return;
        var d = this.st.data || {}, p = d.patient || {}, s = d.study || {}, ser = this.curSeries() || {};
        var meta = d.meta || {};
        var isHU = this.frameIsHU();
        var count = this.frameCount();
        var seriesUid = /^\d[\d.]*$/.test(s.study_uid || '')
            ? (s.study_uid + '.' + (ser.series_id || 1))
            : ('1.2.826.0.1.3680043.8.498.' + (ser.seed || ser.series_id || '1'));
        var section = function (title, rows) {
            var h = '<div class="pv-dicom-sec"><h4>' + esc(title) + '</h4><table class="pv-dicom-table">';
            rows.forEach(function (r) {
                if (r[1] === undefined || r[1] === null || r[1] === '') return;
                h += '<tr><th>' + esc(r[0]) + '</th><td>' + esc(r[1]) + '</td></tr>';
            });
            return h + '</table></div>';
        };
        var seriesList = (d.series || []).map(function (x) {
            return 'Ser ' + x.series_id + ' · ' + (x.description || '') + '（' + (x.slice_count || 0) + ' 帧）';
        }).join('；');
        var html = '<div class="pv-dicom">' +
            section('患者信息 (Patient)', [
                ['PatientName（姓名）', p.name],
                ['PatientID（患者号）', p.patient_id],
                ['PatientBirthDate（出生日期）', p.birth_date],
                ['PatientSex（性别）', p.gender],
                ['Age（年龄）', p.age],
                ['OutpatientNo（门诊号）', p.outpatient_no]
            ]) +
            section('检查信息 (Study)', [
                ['StudyInstanceUID', s.study_uid],
                ['AccessionNumber（检查号）', s.accession_no],
                ['StudyDate（检查时间）', s.study_date],
                ['Modality（模态）', s.modality],
                ['StudyDescription（检查项目）', s.description],
                ['InstitutionName（机构）', s.institution],
                ['StationName（设备）', s.station_name],
                ['ReferringDept（申请科室）', s.apply_dept],
                ['ReferringPhysician（申请医生）', s.apply_doctor],
                ['NumberOfSeries（序列数）', (d.series || []).length]
            ]) +
            section('序列信息 (Series)', [
                ['SeriesNumber（序列号）', ser.series_id],
                ['SeriesInstanceUID', seriesUid],
                ['SeriesDescription（序列描述）', ser.description],
                ['ImageOrientation（方位）', ser.orientation],
                ['NumberOfFrames（帧数）', count],
                ['SliceThickness（层厚）', ser.slice_thickness != null ? ser.slice_thickness : s.slice_thickness],
                ['PixelSpacing（像素间距）', ser.pixel_spacing],
                ['SeriesList（本检查序列）', seriesList]
            ]) +
            section('当前图像 (Instance)', [
                ['InstanceNumber（帧号）', (this.st.fi + 1) + ' / ' + count],
                ['Rows × Columns（矩阵）', '512 × 512'],
                ['BitsAllocated（位深）', 16],
                ['PhotometricInterpretation', 'MONOCHROME2'],
                ['RescaleIntercept / Slope', '0 / 1'],
                ['WindowWidth / WindowCenter', Math.round(this.st.ww) + ' / ' + Math.round(this.st.wl)],
                ['PixelRepresentation（是否 HU）', isHU ? '有符号（HU）' : '无符号'],
                ['Zoom / Rotation', Math.round(this.st.zoom * 100) + '% / ' + (((this.st.rot % 360) + 360) % 360) + '°'],
                ['Flip（镜像）', (this.st.flipH ? 'H' : '') + (this.st.flipV ? 'V' : '') || 'N'],
                ['Annotations（标注数）', this.st.annos.length]
            ]) +
            section('数据来源', [
                ['Source（来源）', meta.source],
                ['Mode（接口模式）', meta.mode],
                ['IsMock（是否仿真影像）', ser.is_mock ? '是（前端算法生成）' : '否（真实图像）']
            ]) + '</div>';
        window.PvModal.open({ title: 'DICOM 详情 · ' + (s.accession_no || s.study_uid || ''), size: 'lg', body: html });
        this.logEvent('dicom');
    };

    /** 复制当前检查的阅片直链（地址栏固定时的对外分享 / 外部系统调用入口） */
    PvViewer.prototype.copyDirectLink = function () {
        var uid = this.route.uid || (this.activeStudy() ? this.activeStudy().uid : '');
        var link = this.direct || (window.PvNav ? window.PvNav.route('viewer', { uid: uid }) : '');
        if (!link || !uid) { this.setStatus('没有可复制的检查'); return; }
        var done = function () { this.setStatus('已复制阅片直链：' + link); }.bind(this);
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(link).then(done, done);
        } else {
            var ta = document.createElement('textarea'); ta.value = link; document.body.appendChild(ta);
            ta.select(); try { document.execCommand('copy'); } catch (e) {} document.body.removeChild(ta); done();
        }
    };
    PvViewer.prototype.setSeries = function (i) {
        if (i < 0 || i >= this.st.series.length) return;
        this.st.si = i; this.st.fi = 0;
        this._wheelAcc = 0;
        var s = this.activeStudy(); if (s) { s.si = i; s.fi = 0; }
        this.renderSidebar();
        this.applyDefaults(); this.fit();
        this.setStatus('序列 ' + (i + 1) + '：' + (this.curSeries().description || ''));
        this.toolbar.sync(this.st); this.render(); this.updateScrollbar();
        this.refreshControlState();
        this.persist();
    };
    PvViewer.prototype.setFrame = function (i) {
        var n = this.frameCount();
        i = clamp(i, 0, n - 1);
        this.st.fi = i;
        var s = this.activeStudy(); if (s) s.fi = i;
        this.render();
        this.setStatus('切片 ' + (i + 1) + ' / ' + n);
        this.updateScrollbar();
        this.persist();
    };
    PvViewer.prototype.fit = function () {
        this.st.zoom = clamp(Math.min(this.cssW / BASE, this.cssH / BASE) * 0.92, 0.05, 16);
        this.st.panX = 0; this.st.panY = 0;
        this.render();
    };

    /* ---------- 启动（页面生命周期由 spa.js / 页脚统一调度） ---------- */
    var instance = null;
    global.PvPages = global.PvPages || {};
    global.PvPages.viewer = {
        init: function (data) {
            data = data || {};
            var root = document.querySelector('[data-pv="app"]');
            if (!root) return;
            if (instance) { try { instance.destroy(); } catch (e) {} instance = null; }
            instance = new PvViewer(root, {
                uid: data.uid || '',
                mode: data.mode || 'append',
                direct: data.direct || '',
                limit: data.studyLimit || 5,
                about: data.about || {}
            });
        },
        destroy: function () {
            if (instance) { try { instance.destroy(); } catch (e) {} instance = null; }
        }
    };
    global.PvViewer = PvViewer;
})(window);
