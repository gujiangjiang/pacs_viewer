/* ============================================================
 * assets/js/viewer.js — 影像查看（分栏工作区）
 * 结构：
 *   PvPane  —— 单个窗格（画布 / OSD / 滚动条 / 测量 / 导出 / DICOM 详情）
 *   PvViewer—— 工作区控制器（多检查、多窗格布局、激活窗格、工具栏、序列栏）
 * 工具栏操作作用于「激活窗格」；测量在任意窗格内进行且不越界。
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
    var PANE_ACTS = { 'rotate-cw': 1, 'rotate-ccw': 1, 'flip-h': 1, 'flip-v': 1, 'invert': 1, 'clear': 1, 'fit': 1, 'oneone': 1, 'prev': 1, 'next': 1 };

    function clamp(v, a, b) { return v < a ? a : (v > b ? b : v); }
    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    /** 图标渲染：命中统一 SVG 图标库则用 SVG，否则按文本/emoji（如预设窗）显示 */
    function iconHtml(name) {
        if (global.PvIcons && global.PvIcons[name]) {
            return '<span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + global.PvIcons[name] + '</svg></span>';
        }
        return '<span class="ic">' + name + '</span>';
    }

    /* ============================================================
     * PvPane —— 单个窗格
     * ============================================================ */
    function PvPane(viewer) {
        this.viewer = viewer;
        this.el = document.createElement('div');
        this.el.className = 'pv-pane';
        this.el.innerHTML =
            '<div class="pv-canvas-wrap"><canvas></canvas></div>'
            + '<div class="pv-vw-title"></div><div class="pv-vw-status"></div><div class="pv-vw-hu"></div>'
            + '<div class="pv-vw-scroll" hidden><div class="pv-vw-scroll-track"><div class="pv-vw-scroll-thumb"></div></div><div class="pv-vw-scroll-bubble"></div></div>';
        viewer.panesEl.appendChild(this.el);
        this.canvas = this.el.querySelector('canvas');
        this.ctx = this.canvas.getContext('2d');
        this.stage = this.el.querySelector('.pv-canvas-wrap');
        this.titleEl = this.el.querySelector('.pv-vw-title');
        this.statusEl = this.el.querySelector('.pv-vw-status');
        this.huEl = this.el.querySelector('.pv-vw-hu');
        this.scrollEl = this.el.querySelector('.pv-vw-scroll');
        this.scrollTrack = this.el.querySelector('.pv-vw-scroll-track');
        this.scrollThumb = this.el.querySelector('.pv-vw-scroll-thumb');
        this.scrollBubble = this.el.querySelector('.pv-vw-scroll-bubble');
        this.dpr = window.devicePixelRatio || 1;

        this.work = document.createElement('canvas'); this.work.width = BASE; this.work.height = BASE;
        this.wctx = this.work.getContext('2d');
        this.raw = document.createElement('canvas'); this.raw.width = BASE; this.raw.height = BASE;

        this.cache = {}; this.cacheKeys = []; this.imgCache = {};
        this._frames = {};
        this.st = {
            uid: '', si: 0, fi: 0, ww: 400, wl: 40, isHU: true,
            zoom: 1, panX: 0, panY: 0, rot: 0, flipH: false, flipV: false, invert: false,
            annos: [], draft: null, drag: null
        };
        this._bind();
        this._bindScrollbar();
        this.resize();
        var self = this;
        if (window.ResizeObserver) { this._ro = new ResizeObserver(function () { self.resize(); self.render(); }); this._ro.observe(this.stage); }
    }

    PvPane.prototype.destroy = function () {
        try { if (this._ro) this._ro.disconnect(); } catch (e) {}
        if (this._upH) window.removeEventListener('pointerup', this._upH);
        if (this._sb && this._sb.hideTimer) clearTimeout(this._sb.hideTimer);
        if (this.el.parentNode) this.el.parentNode.removeChild(this.el);
    };

    /* ---------- 数据 ---------- */
    PvPane.prototype.hasImage = function () { return !!(this.curSeries()); };
    PvPane.prototype.data = function () { return this.viewer.study(this.st.uid); };
    PvPane.prototype.seriesList = function () { var d = this.data(); return d ? d.series : []; };
    PvPane.prototype.curSeries = function () { var d = this.data(); return d && d.series[this.st.si] ? d.series[this.st.si] : null; };
    PvPane.prototype.frameIsHU = function () { var s = this.curSeries(); return !!(s && s.is_hu); };
    PvPane.prototype.frameCount = function () { var s = this.curSeries(); return s ? Math.max(1, s.slice_count || (s.images ? s.images.length : 1)) : 0; };

    /** 显示像素物理间距（mm/显示像素）：按原图列数折算到 BASE 显示尺寸 */
    PvPane.prototype.effectivePixelSpacing = function () {
        var s = this.curSeries(); if (!s) return 0.7;
        var ps = parseFloat(s.pixel_spacing) || 0.7;
        var cols = parseInt(s.columns, 10) || BASE;
        return ps * (cols / BASE);
    };

    PvPane.prototype.setStatus = function (m) { if (this.statusEl) this.statusEl.textContent = m || ''; };
    PvPane.prototype.updateTitle = function () {
        if (!this.titleEl) return;
        var d = this.data(), s = (d && d.data && d.data.study) || {};
        this.titleEl.textContent = this.hasImage()
            ? ((s.modality || '') + ' · ' + (s.description || '') + (s.accession_no ? ' · ' + s.accession_no : ''))
            : '';
    };

    PvPane.prototype.resize = function () {
        var box = this.stage.getBoundingClientRect();
        var w = Math.max(2, Math.floor(box.width)), h = Math.max(2, Math.floor(box.height));
        this.cssW = w; this.cssH = h;
        this.canvas.width = Math.floor(w * this.dpr); this.canvas.height = Math.floor(h * this.dpr);
        this.canvas.style.width = w + 'px'; this.canvas.style.height = h + 'px';
        this.updateScrollbar();
    };

    /* ---------- 标准 DICOM 帧获取与缓存 ---------- */
    PvPane.prototype._frameKey = function (series, fi) { return this.st.uid + '|' + series.series_id + '|' + fi; };

    /** 取指定帧（异步拉取标准 DICOM 并解码）；返回 {status:'ok'|'loading'} 或 null */
    PvPane.prototype.getFrame = function (series, fi) {
        var key = this._frameKey(series, fi);
        var c = this._frames[key];
        if (c) return c;
        var url = series.images && series.images[fi];
        if (!url) return null;
        this._frames[key] = { status: 'loading' };
        var self = this;
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.arrayBuffer(); })
            .then(function (buf) {
                var dec = window.PvDicom ? PvDicom.decode(buf) : null;
                if (!dec) { delete self._frames[key]; self.setStatus('DICOM 解码失败'); self.render(); return; }
                self._frames[key] = { status: 'ok', dec: dec, raw: PvRender.resample(dec, BASE) };
                self._trimFrames();
                self.render();
            })
            .catch(function () { delete self._frames[key]; self.setStatus('影像加载失败'); self.render(); });
        return null;
    };
    PvPane.prototype.currentFrame = function () {
        var s = this.curSeries(); if (!s || s.format !== 'dicom') return null;
        var f = this.getFrame(s, this.st.fi);
        return (f && f.status === 'ok') ? f : null;
    };

    /** 帧缓存上限控制（优先保留正在显示的帧） */
    PvPane.prototype._trimFrames = function () {
        var keys = Object.keys(this._frames);
        if (keys.length <= 240) return;
        var removed = 0;
        for (var i = 0; i < keys.length && removed < keys.length - 200; i++) {
            var f = this._frames[keys[i]];
            if (f && f.status === 'ok') { delete this._frames[keys[i]]; removed++; }
        }
    };

    /** 后台预取并解码整条序列（并发上限 4），使滚动翻帧基本即时 */
    PvPane.prototype.prefetch = function (series) {
        if (!series || series.format !== 'dicom' || !series.images || !series.images.length) return;
        var self = this, n = series.images.length, MAX = 6, cursor = 0;
        var order = [], seen = {}, cur = this.st.fi;
        var push = function (k) { if (k >= 0 && k < n && !seen[k]) { seen[k] = 1; order.push(k); } };
        push(cur);
        for (var d = 1; d < n; d++) { push(cur + d); push(cur - d); }
        function next() {
            if (cursor >= order.length) return;
            var idx = order[cursor++];
            var key = self._frameKey(series, idx);
            if (self._frames[key]) { next(); return; }
            self._frames[key] = { status: 'loading' };
            fetch(series.images[idx], { credentials: 'same-origin' })
                .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.arrayBuffer(); })
                .then(function (buf) {
                    var dec = window.PvDicom ? PvDicom.decode(buf) : null;
                    if (dec) self._frames[key] = { status: 'ok', dec: dec, raw: PvRender.resample(dec, BASE) };
                    else delete self._frames[key];
                    self._trimFrames();
                    if (self.curSeries() === series && self.st.fi === idx) self.render();
                })
                .catch(function () { delete self._frames[key]; })
                .then(function () { next(); });
        }
        for (var k = 0; k < MAX; k++) next();
    };

    PvPane.prototype.getRealImage = function (src, cb) {
        if (this.imgCache[src]) { if (this.imgCache[src].complete) cb(this.imgCache[src]); return; }
        var im = new Image(), self = this;
        im.onload = function () { self.render(); cb(im); };
        im.src = src; this.imgCache[src] = im;
    };
    PvPane.prototype.currentSource = function () {
        var s = this.curSeries(); if (!s) return null;
        if (s.format === 'dicom') {
            var f = this.getFrame(s, this.st.fi);
            if (f && f.status === 'ok') return { kind: 'raw', raw: f.raw, dec: f.dec };
            return { kind: 'loading' };
        }
        var src = s.images && s.images[this.st.fi];
        if (!src) return null;
        var im = this.imgCache[src];
        if (!im) { this.getRealImage(src, function () {}); return { kind: 'loading' }; }
        if (!im.complete) return { kind: 'loading' };
        return { kind: 'image', img: im };
    };
    /** 显示值场 → 窗宽窗位画布 */
    PvPane.prototype.windowRaw = function (raw, ww, wl, invert) {
        var img = PvRender.window(raw, BASE, ww, wl, invert);
        if (this.work.width !== BASE || this.work.height !== BASE) { this.work.width = BASE; this.work.height = BASE; }
        this.wctx.putImageData(img, 0, 0);
        return this.work;
    };

    PvPane.prototype.render = function () {
        var ctx = this.ctx, st = this.st;
        if (!ctx) return;
        ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
        ctx.fillStyle = '#000'; ctx.fillRect(0, 0, this.cssW, this.cssH);
        var src = this.currentSource(), winCanvas = null;
        if (src && src.kind === 'raw') {
            winCanvas = this.windowRaw(src.raw, st.ww, st.wl, st.invert);
        } else if (src && src.kind === 'image') {
            var im = src.img, rc = this.raw.getContext('2d');
            rc.setTransform(1, 0, 0, 1, 0, 0); rc.fillStyle = '#000'; rc.fillRect(0, 0, BASE, BASE);
            var sc = Math.min(BASE / im.width, BASE / im.height), dw = im.width * sc, dh = im.height * sc;
            rc.drawImage(im, (BASE - dw) / 2, (BASE - dh) / 2, dw, dh);
            var data = rc.getImageData(0, 0, BASE, BASE);
            var raw = new Float32Array(BASE * BASE);
            for (var i = 0, n = BASE * BASE; i < n; i++) {
                var j = i * 4;
                raw[i] = data.data[j] * .299 + data.data[j + 1] * .587 + data.data[j + 2] * .114;
            }
            winCanvas = this.windowRaw(raw, st.ww, st.wl, st.invert);
        } else if (src && src.kind === 'loading') {
            this.placeholder('正在解码图像…');
        } else {
            var isActive = this === this.viewer.activePane();
            var emptyWs = !this.viewer.ws.studies.length;
            this.placeholder((emptyWs && isActive)
                ? '请在「研究检索」中选择检查\n或点击顶部「影像查看」查看已打开的检查'
                : '空视图\n从左侧序列载入', emptyWs && isActive);
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
        if (this.data() && this.curSeries()) {
            this.drawAnnotations();
            PvOsd.draw(ctx, {
                cssW: this.cssW, cssH: this.cssH, dpr: this.dpr, data: this.data().data,
                series: this.curSeries(), fi: st.fi, count: this.frameCount(),
                ww: st.ww, wl: st.wl, zoom: st.zoom, rot: st.rot, flipH: st.flipH, flipV: st.flipV
            });
        }
    };
    PvPane.prototype.placeholder = function (t, big) {
        if (!t) return;
        var ctx = this.ctx;
        ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
        ctx.fillStyle = big ? '#475569' : '#64748b';
        ctx.font = (big ? '15px' : '14px') + ' sans-serif';
        ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        var lines = String(t).split('\n'), lh = big ? 26 : 20;
        var y0 = this.cssH / 2 - (lines.length - 1) * lh / 2;
        for (var i = 0; i < lines.length; i++) ctx.fillText(lines[i], this.cssW / 2, y0 + i * lh);
    };

    PvPane.prototype._trig = function () { var r = this.st.rot * Math.PI / 180; return { c: Math.cos(r), s: Math.sin(r) }; };
    PvPane.prototype.imgToScreen = function (x, y) {
        var t = this._trig(), fh = this.st.flipH ? -1 : 1, fv = this.st.flipV ? -1 : 1;
        var x1 = (x - BASE / 2) * this.st.zoom * fh, y1 = (y - BASE / 2) * this.st.zoom * fv;
        return { x: this.cssW / 2 + this.st.panX + x1 * t.c - y1 * t.s, y: this.cssH / 2 + this.st.panY + x1 * t.s + y1 * t.c };
    };
    PvPane.prototype.screenToImg = function (sx, sy) {
        var t = this._trig(), fh = this.st.flipH ? -1 : 1, fv = this.st.flipV ? -1 : 1;
        var dx = sx - (this.cssW / 2 + this.st.panX), dy = sy - (this.cssH / 2 + this.st.panY);
        var x1 = dx * t.c + dy * t.s, y1 = -dx * t.s + dy * t.c;
        return { x: x1 / (this.st.zoom * fh) + BASE / 2, y: y1 / (this.st.zoom * fv) + BASE / 2 };
    };

    /* ---------- 标注 ---------- */
    PvPane.prototype.drawAnnotations = function () {
        var st = this.st, ctx = this.ctx;
        ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
        var all = st.annos.slice();
        if (st.draft) { var dd = this._draftView(); if (dd) all.push(dd); }
        for (var i = 0; i < all.length; i++) this._drawAnno(ctx, all[i]);
    };
    PvPane.prototype._draftView = function () {
        var d = this.st.draft; if (!d) return null;
        var pts = d.fixed.slice();
        if (d.hover && (d.type === 'length' || d.type === 'angle')) pts.push(d.hover);
        return { type: d.type, pts: pts, color: '#facc15' };
    };
    PvPane.prototype._line = function (ctx, a, b, color) {
        var A = this.imgToScreen(a.x, a.y), B = this.imgToScreen(b.x, b.y);
        ctx.save(); ctx.strokeStyle = color; ctx.lineWidth = 1.4; ctx.setLineDash([5, 4]);
        ctx.beginPath(); ctx.moveTo(A.x, A.y); ctx.lineTo(B.x, B.y); ctx.stroke(); ctx.setLineDash([]);
        ctx.fillStyle = color;
        [A, B].forEach(function (P) { ctx.beginPath(); ctx.arc(P.x, P.y, 3, 0, Math.PI * 2); ctx.fill(); });
        ctx.restore();
    };
    PvPane.prototype._label = function (ctx, x, y, txt) {
        ctx.save(); ctx.font = '12px monospace'; ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
        ctx.shadowColor = 'rgba(0,0,0,.9)'; ctx.shadowBlur = 3;
        var w = ctx.measureText(txt).width + 8;
        ctx.fillStyle = 'rgba(16,185,129,.88)'; ctx.fillRect(x, y - 9, w, 18);
        ctx.shadowBlur = 0; ctx.fillStyle = '#04140d'; ctx.fillText(txt, x + 4, y); ctx.restore();
    };
    PvPane.prototype._drawAnno = function (ctx, a) {
        var color = a.color || '#10b981', ps = this.effectivePixelSpacing();
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
    PvPane.prototype._rel = function (e) { var r = this.canvas.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; };
    PvPane.prototype._bind = function () {
        var self = this, cv = this.canvas;
        cv.addEventListener('contextmenu', function (e) { e.preventDefault(); });
        cv.addEventListener('wheel', function (e) { self.onWheel(e); }, { passive: false });
        cv.addEventListener('pointerdown', function (e) { self.viewer.setActivePane(self.viewer.panes.indexOf(self)); self.onDown(e); });
        cv.addEventListener('pointermove', function (e) { self.onMove(e); self._updateHU(e); });
        cv.addEventListener('pointerleave', function () { self._setHU(''); });
        window.addEventListener('pointerup', this._upH = function (e) { self.onUp(e); });
    };
    PvPane.prototype.onDown = function (e) {
        var pt = this._rel(e), st = this.st;
        try { this.canvas.setPointerCapture(e.pointerId); } catch (err) {}
        if (e.button === 1) { st.drag = { mode: 'pan', x: pt.x, y: pt.y, px: st.panX, py: st.panY }; return; }
        if (e.button === 2) { st.drag = { mode: 'wl', x: pt.x, y: pt.y, ww: st.ww, wl: st.wl, btn: 2, cx: e.clientX, cy: e.clientY, moved: false }; return; }
        if (!this.hasImage()) return;
        var p = this.screenToImg(pt.x, pt.y), t = this.viewer.tool;
        if (t === 'wl') st.drag = { mode: 'wl', x: pt.x, y: pt.y, ww: st.ww, wl: st.wl };
        else if (t === 'pan') st.drag = { mode: 'pan', x: pt.x, y: pt.y, px: st.panX, py: st.panY };
        else if (t === 'zoom') st.drag = { mode: 'zoom', y: pt.y, z: st.zoom };
        else if (t === 'length' || t === 'angle') this._addPoint(t, p);
        else if (t === 'rect' || t === 'ellipse') st.draft = { type: t, fixed: [p, p] };
        this.render();
    };
    PvPane.prototype.onMove = function (e) {
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
    PvPane.prototype.onUp = function () {
        var st = this.st;
        if (st.drag) {
            if (st.drag.btn === 2 && !st.drag.moved) this.viewer.openCtxMenu(st.drag.cx, st.drag.cy);
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
    PvPane.prototype._zoomTo = function (z, sx, sy) {
        z = clamp(z, 0.12, 16);
        var b = this.screenToImg(sx, sy); this.st.zoom = z;
        var m = this.imgToScreen(b.x, b.y);
        this.st.panX += sx - m.x; this.st.panY += sy - m.y;
    };
    PvPane.prototype.onWheel = function (e) {
        if (!this.hasImage()) return;
        e.preventDefault();
        this.viewer.setActivePane(this.viewer.panes.indexOf(this));
        var st = this.st, pt = this._rel(e);
        if (this.viewer.tool === 'zoom' || e.ctrlKey || e.metaKey) { this._zoomTo(st.zoom * (e.deltaY < 0 ? 1.12 : 1 / 1.12), pt.x, pt.y); this.render(); return; }
        var n = this.frameCount();
        if (n > 1) {
            var dy = e.deltaY;
            if (e.deltaMode === 1) dy *= 16; else if (e.deltaMode === 2) dy *= 100;
            this._wheelAcc = (this._wheelAcc || 0) + dy;
            var step = 0, TH = 100;
            while (Math.abs(this._wheelAcc) >= TH) { step += this._wheelAcc > 0 ? 1 : -1; this._wheelAcc -= this._wheelAcc > 0 ? TH : -TH; }
            if (step !== 0) this.setFrame(st.fi + step);
            return;
        }
        this._zoomTo(st.zoom * (e.deltaY < 0 ? 1.12 : 1 / 1.12), pt.x, pt.y);
        this.render();
    };
    PvPane.prototype._addPoint = function (type, p) {
        var st = this.st;
        if (!st.draft || st.draft.type !== type) st.draft = { type: type, fixed: [], hover: null };
        st.draft.fixed.push(p); st.draft.hover = null;
        if (st.draft.fixed.length >= (type === 'length' ? 2 : 3)) {
            st.annos.push({ type: type, pts: st.draft.fixed.slice(), color: '#10b981' });
            st.draft = null;
        }
        this.render();
    };
    PvPane.prototype._roiStats = function (pts, type) {
        var src = this.currentSource(); if (!src || src.kind !== 'raw') return null;
        var raw = src.raw, size = BASE;
        var x0 = clamp(Math.round(Math.min(pts[0].x, pts[1].x)), 0, size - 1);
        var x1 = clamp(Math.round(Math.max(pts[0].x, pts[1].x)), 0, size - 1);
        var y0 = clamp(Math.round(Math.min(pts[0].y, pts[1].y)), 0, size - 1);
        var y1 = clamp(Math.round(Math.max(pts[0].y, pts[1].y)), 0, size - 1);
        var cx = (x0 + x1) / 2, cy = (y0 + y1) / 2, rx = Math.max(1, (x1 - x0) / 2), ry = Math.max(1, (y1 - y0) / 2);
        var sum = 0, n = 0;
        for (var y = y0; y <= y1; y++) {
            for (var x = x0; x <= x1; x++) {
                if (type === 'ellipse') { var dx = (x - cx) / rx, dy = (y - cy) / ry; if (dx * dx + dy * dy > 1) continue; }
                sum += raw[y * size + x]; n++;
            }
        }
        if (!n) return null;
        var ps = this.effectivePixelSpacing();
        return { area: n * ps * ps, mean: sum / n, hu: this.frameIsHU(), count: n };
    };
    PvPane.prototype.clearAnnos = function () { this.st.annos = []; this.st.draft = null; this.render(); };

    /* ---------- 帧 / 序列 ---------- */
    PvPane.prototype.applyDefaults = function () {
        var s = (this.data() && this.data().data && this.data().data.study) || {};
        var ser = this.curSeries();
        if (ser && ser.window_width) {
            this.st.ww = parseFloat(ser.window_width) || 400;
            this.st.wl = parseFloat(ser.window_center) || 40;
        } else if (ser && ser.is_hu) {
            var m = (s.modality || '').toUpperCase();
            var pre = (m === 'DR' || m === 'MG') ? PRESETS.full : PRESETS.soft;
            this.st.ww = pre.ww; this.st.wl = pre.wl;
        } else { this.st.ww = 256; this.st.wl = 128; }
        this.st.isHU = this.frameIsHU();
    };
    PvPane.prototype.fit = function () {
        this.st.zoom = clamp(Math.min(this.cssW / BASE, this.cssH / BASE) * 0.92, 0.05, 16);
        this.st.panX = 0; this.st.panY = 0; this.render();
    };
    PvPane.prototype.setSeries = function (uid, si) {
        var d = this.viewer.study(uid);
        if (!d || !d.series[si]) return;
        this.st.uid = uid; this.st.si = si; this.st.fi = 0;
        this._wheelAcc = 0; this.st.annos = []; this.st.draft = null; this._frames = {};
        this.applyDefaults(); this.fit();
        this.updateTitle(); this.updateScrollbar();
        this.viewer.afterPaneLoad(this);
        this.prefetch(d.series[si]);
    };
    PvPane.prototype.setFrame = function (i) {
        var n = this.frameCount();
        if (n <= 1) return;
        i = clamp(i, 0, n - 1);
        this.st.fi = i;
        this.render(); this.updateScrollbar();
        this.setStatus('切片 ' + (i + 1) + ' / ' + n);
        this.viewer.persist();
    };

    /* ---------- 帧滚动条 ---------- */
    PvPane.prototype.updateScrollbar = function () {
        if (!this.scrollEl || !this.scrollTrack || !this.scrollThumb) return;
        var n = this.frameCount();
        if (n <= 1 || !this.hasImage()) { this.scrollEl.hidden = true; this.scrollEl.classList.remove('show-bubble'); return; }
        this.scrollEl.hidden = false;
        var trackH = this.scrollTrack.clientHeight || this.scrollEl.clientHeight || 1;
        var thumbH = Math.max(28, Math.round(trackH / n));
        if (thumbH > trackH) thumbH = trackH;
        var maxTop = Math.max(0, trackH - thumbH);
        this.scrollThumb.style.height = thumbH + 'px';
        this.scrollThumb.style.top = Math.round(maxTop * (this.st.fi / (n - 1))) + 'px';
    };
    PvPane.prototype._bindScrollbar = function () {
        var self = this, track = this.scrollTrack, thumb = this.scrollThumb;
        if (!track) return;
        this._sb = { dragging: false, startY: 0, startTop: 0, hideTimer: null };
        function bubble(n, fi) {
            if (!self.scrollBubble) return;
            self.scrollBubble.textContent = (fi + 1) + ' / ' + n;
            self.scrollBubble.style.top = (thumb.offsetTop + thumb.offsetHeight / 2) + 'px';
            self.scrollEl.classList.add('show-bubble');
        }
        function scheduleHide() { clearTimeout(self._sb.hideTimer); self._sb.hideTimer = setTimeout(function () { self.scrollEl.classList.remove('show-bubble'); }, 900); }
        function setFromY(clientY, fromThumb) {
            var n = self.frameCount(); if (n <= 1) return;
            var rect = track.getBoundingClientRect(), trackH = rect.height, thumbH = thumb.offsetHeight;
            var maxTop = Math.max(0, trackH - thumbH), top;
            if (fromThumb) top = Math.max(0, Math.min(maxTop, self._sb.startTop + (clientY - self._sb.startY)));
            else top = Math.max(0, Math.min(maxTop, clientY - rect.top - thumbH / 2));
            var fi = Math.round((maxTop > 0 ? top / maxTop : 0) * (n - 1));
            self.setFrame(fi); bubble(n, fi); scheduleHide();
        }
        function onMove(e) { if (!self._sb.dragging) return; e.preventDefault(); setFromY(e.clientY, true); }
        function onUp() {
            if (!self._sb.dragging) return;
            self._sb.dragging = false; self.scrollEl.classList.remove('dragging');
            document.removeEventListener('pointermove', onMove); document.removeEventListener('pointerup', onUp);
        }
        track.addEventListener('pointerdown', function (e) {
            e.preventDefault();
            self.viewer.setActivePane(self.viewer.panes.indexOf(self));
            if (e.target === thumb) {
                self._sb.dragging = true; self._sb.startY = e.clientY; self._sb.startTop = thumb.offsetTop;
                self.scrollEl.classList.add('dragging');
                document.addEventListener('pointermove', onMove); document.addEventListener('pointerup', onUp);
            } else setFromY(e.clientY, false);
        });
        track.addEventListener('mouseleave', function () { if (!self._sb.dragging) self.scrollEl.classList.remove('show-bubble'); });
    };

    /* ---------- CT 值 ---------- */
    PvPane.prototype._setHU = function (text) {
        if (!this.huEl) return;
        if (text) { this.huEl.textContent = text; this.huEl.style.display = 'block'; }
        else { this.huEl.style.display = 'none'; }
    };
    PvPane.prototype._updateHU = function (e) {
        if (!this.huEl) return;
        var ser = this.curSeries();
        if (!ser) { this._setHU(''); return; }
        var pt = this._rel(e), p = this.screenToImg(pt.x, pt.y);
        var x = Math.floor(p.x), y = Math.floor(p.y);
        if (x < 0 || y < 0 || x >= BASE || y >= BASE) { this._setHU(''); return; }
        if (ser.format === 'dicom') {
            var f = this.currentFrame();
            if (!f) { this._setHU(''); return; }
            var dec = f.dec;
            var sx = Math.floor(x * dec.columns / BASE), sy = Math.floor(y * dec.rows / BASE);
            if (sx < 0 || sy < 0 || sx >= dec.columns || sy >= dec.rows) { this._setHU(''); return; }
            var stored = dec.pixels[sy * dec.columns + sx];
            var val = stored * dec.rescaleSlope + dec.rescaleIntercept;
            this._setHU((ser.is_hu ? 'HU ' : '灰度 ') + Math.round(val) + '　(' + x + ', ' + y + ')');
        } else {
            try { var d = this.raw.getContext('2d').getImageData(x, y, 1, 1).data; this._setHU('灰度 ' + d[0] + '　(' + x + ', ' + y + ')'); }
            catch (err) { this._setHU('(' + x + ', ' + y + ')'); }
        }
    };

    /* ---------- 导出 ---------- */
    PvPane.prototype.fileBase = function () {
        var d = this.data(); d = d || {}; var data = d.data || {};
        var p = data.patient || {}, s = data.study || {}, ser = this.curSeries() || {};
        return [p.patient_id || 'patient', s.accession_no || s.study_uid || 'study', 'ser' + (ser.series_id || 1)]
            .join('_').replace(/[^\w.-]+/g, '_');
    };
    PvPane.prototype._triggerDownload = function (url, filename) {
        var a = document.createElement('a'); a.href = url; a.download = filename;
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(url); }, 1800);
    };
    PvPane.prototype._paintRaw = function (cx, raw) {
        cx.putImageData(PvRender.window(raw, BASE, this.st.ww, this.st.wl, this.st.invert), 0, 0);
    };
    PvPane.prototype.exportFrameCanvas = function (fi) {
        var self = this, ser = this.curSeries();
        var cv = document.createElement('canvas'); cv.width = BASE; cv.height = BASE;
        var cx = cv.getContext('2d'); cx.fillStyle = '#000'; cx.fillRect(0, 0, BASE, BASE);
        if (!ser) return Promise.resolve(cv);
        if (ser.format === 'dicom') {
            var cached = this._frames[this._frameKey(ser, fi)];
            if (cached && cached.status === 'ok') { this._paintRaw(cx, cached.raw); return Promise.resolve(cv); }
            var durl = ser.images && ser.images[fi];
            if (!durl) return Promise.resolve(cv);
            return fetch(durl, { credentials: 'same-origin' }).then(function (r) { return r.arrayBuffer(); })
                .then(function (buf) {
                    var dec = window.PvDicom ? PvDicom.decode(buf) : null;
                    if (dec) self._paintRaw(cx, PvRender.resample(dec, BASE));
                    return cv;
                }).catch(function () { return cv; });
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
                var raw = new Float32Array(BASE * BASE);
                for (var i = 0, n = BASE * BASE; i < n; i++) { var j = i * 4; raw[i] = data.data[j] * .299 + data.data[j + 1] * .587 + data.data[j + 2] * .114; }
                cx.drawImage(self.windowRaw(raw, self.st.ww, self.st.wl, self.st.invert), 0, 0);
                resolve(cv);
            };
            im.onerror = function () { resolve(cv); };
            im.src = src;
        });
    };
    PvPane.prototype.saveImage = function () {
        if (!this.hasImage()) return;
        var self = this, name = this.fileBase() + '_im' + (this.st.fi + 1) + '.png';
        try {
            this.canvas.toBlob(function (blob) {
                if (!blob) return;
                self._triggerDownload(URL.createObjectURL(blob), name);
                self.setStatus('已保存当前图像：' + name);
                self.viewer.logEvent('download', '当前图像 ' + name);
            }, 'image/png');
        } catch (e) { this.setStatus('当前画面包含跨域内容，无法导出'); }
    };
    PvPane.prototype.saveSeries = function () {
        if (!window.PvZip || !this.hasImage()) { this.setStatus('无可导出序列'); return; }
        var self = this, n = this.frameCount(), base = this.fileBase();
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
        chain.then(function () { self.setStatus('正在打包 ZIP…'); return window.PvZip.create(files); })
            .then(function (zip) {
                self._triggerDownload(URL.createObjectURL(zip), base + '.zip');
                self.setStatus('已导出序列：' + base + '.zip（' + n + ' 帧）');
                self.viewer.logEvent('download', '序列 ZIP ' + base + '（' + n + ' 帧）');
            }).catch(function () { self.setStatus('序列导出失败'); });
    };

    /* ---------- DICOM 详情 ---------- */
    PvPane.prototype.showDicomInfo = function () {
        if (!window.PvModal || !this.hasImage()) return;
        var d = (this.data() || {}).data || {}, p = d.patient || {}, s = d.study || {}, ser = this.curSeries() || {};
        var meta = d.meta || {}, isHU = this.frameIsHU(), count = this.frameCount();
        var seriesUid = /^\d[\d.]*$/.test(s.study_uid || '') ? (s.study_uid + '.' + (ser.series_id || 1))
            : ('1.2.826.0.1.3680043.8.498.' + (ser.seed || ser.series_id || '1'));
        var section = function (title, rows) {
            var h = '<div class="pv-dicom-sec"><h4>' + esc(title) + '</h4><table class="pv-dicom-table">';
            rows.forEach(function (r) { if (r[1] === undefined || r[1] === null || r[1] === '') return; h += '<tr><th>' + esc(r[0]) + '</th><td>' + esc(r[1]) + '</td></tr>'; });
            return h + '</table></div>';
        };
        var seriesList = (d.series || []).map(function (x) { return 'Ser ' + x.series_id + ' · ' + (x.description || '') + '（' + (x.slice_count || 0) + ' 帧）'; }).join('；');
        var html = '<div class="pv-dicom">' +
            section('患者信息 (Patient)', [['PatientName（姓名）', p.name], ['PatientID（患者号）', p.patient_id], ['PatientBirthDate（出生日期）', p.birth_date], ['PatientSex（性别）', p.gender], ['Age（年龄）', p.age], ['OutpatientNo（门诊号）', p.outpatient_no]]) +
            section('检查信息 (Study)', [['StudyInstanceUID', s.study_uid], ['AccessionNumber（检查号）', s.accession_no], ['StudyDate（检查时间）', s.study_date], ['Modality（模态）', s.modality], ['StudyDescription（检查项目）', s.description], ['InstitutionName（机构）', s.institution], ['StationName（设备）', s.station_name], ['ReferringDept（申请科室）', s.apply_dept], ['ReferringPhysician（申请医生）', s.apply_doctor], ['NumberOfSeries（序列数）', (d.series || []).length]]) +
            section('序列信息 (Series)', [['SeriesNumber（序列号）', ser.series_id], ['SeriesInstanceUID', seriesUid], ['SeriesDescription（序列描述）', ser.description], ['ImageOrientation（方位）', ser.orientation], ['NumberOfFrames（帧数）', count], ['SliceThickness（层厚）', ser.slice_thickness != null ? ser.slice_thickness : s.slice_thickness], ['PixelSpacing（像素间距）', ser.pixel_spacing], ['SeriesList（本检查序列）', seriesList]]) +
            section('当前图像 (Instance)', [['InstanceNumber（帧号）', (this.st.fi + 1) + ' / ' + count], ['Rows × Columns（矩阵）', (ser.rows || 512) + ' × ' + (ser.columns || 512)], ['BitsAllocated（位深）', ser.bits_allocated || 16], ['PhotometricInterpretation', 'MONOCHROME2'], ['RescaleIntercept / Slope', (ser.rescale_intercept != null ? ser.rescale_intercept : '0') + ' / ' + (ser.rescale_slope != null ? ser.rescale_slope : '1')], ['WindowWidth / WindowCenter', Math.round(this.st.ww) + ' / ' + Math.round(this.st.wl)], ['PixelRepresentation（是否 HU）', isHU ? '有符号（HU）' : '无符号'], ['Zoom / Rotation', Math.round(this.st.zoom * 100) + '% / ' + (((this.st.rot % 360) + 360) % 360) + '°'], ['Flip（镜像）', (this.st.flipH ? 'H' : '') + (this.st.flipV ? 'V' : '') || 'N'], ['Annotations（标注数）', this.st.annos.length]]) +
            section('数据来源', [['Source（来源）', meta.source], ['Mode（接口模式）', meta.mode], ['Format（影像格式）', ser.format === 'dicom' ? '标准 DICOM（WADO-URI）' : '图像文件']]) + '</div>';
        window.PvModal.open({ title: 'DICOM 详情 · ' + (s.accession_no || s.study_uid || ''), size: 'lg', body: html });
        this.viewer.logEvent('dicom');
    };

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
        PvApi.study(uid).then(function (j) {
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
        var restored = [], chain = Promise.resolve();
        saved.studies.forEach(function (item) {
            chain = chain.then(function () {
                return PvApi.study(item.uid).then(function (j) {
                    if (j && j.code === 200 && j.data) restored.push({ uid: item.uid, data: j.data, series: j.data.series || [], collapsed: !!item.collapsed });
                }).catch(function () {});
            });
        });
        chain.then(function () {
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
    };
    PvViewer.prototype.showEmpty = function () {
        this.ws.studies = [];
        this.panes.forEach(function (p) { p.st.uid = ''; p.st.si = 0; p.st.fi = 0; p.st.annos = []; p.updateTitle(); p.updateScrollbar(); p.render(); });
        this.renderSidebar();
        this.syncToolbar();
        this.refreshControlState();
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
    PvViewer.prototype.persist = function () {
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
    PvViewer.prototype.clearState = function () { try { sessionStorage.removeItem('pacs_workspace_v1'); } catch (e) {} };

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
        var done = function () { if (p) p.setStatus('已复制阅片直链：' + link); };
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(link).then(done, done);
        else { var ta = document.createElement('textarea'); ta.value = link; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch (e) {} document.body.removeChild(ta); done(); }
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
    global.PvPane = PvPane;
})(window);
