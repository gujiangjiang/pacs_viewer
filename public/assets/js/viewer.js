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

    function PvViewer(root, uid, direct) {
        this.root = root;
        this.uid = uid;
        this.direct = direct || '';
        this.q = function (k) { return root.querySelector('[data-pv="' + k + '"]'); };
        this.canvas = this.q('canvas');
        this.ctx = this.canvas.getContext('2d');
        this.stage = this.q('canvas').parentNode;
        this.statusEl = this.q('status');
        this.titleEl = this.q('title');
        this.filmstripEl = this.q('filmstrip');
        this.pcName = this.q('pc-name'); this.pcSub = this.q('pc-sub'); this.pcBody = this.q('pc-body');
        this.dpr = window.devicePixelRatio || 1;

        this.work = document.createElement('canvas'); this.work.width = BASE; this.work.height = BASE;
        this.wctx = this.work.getContext('2d');
        this.raw = document.createElement('canvas'); this.raw.width = BASE; this.raw.height = BASE;

        this.cache = {}; this.cacheKeys = []; this.imgCache = {};
        this.sidebar = new PvSidebar(this.q('serieslist'));
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
        this._bind();
        this._bindScrollbar();
        this.resize();
        var self = this;
        if (window.ResizeObserver) { this._ro = new ResizeObserver(function () { self.resize(); self.render(); }); this._ro.observe(this.stage); }
        else window.addEventListener('resize', this._onWinResize = function () { self.resize(); self.render(); });
        this.render();
        this.load();
    }

    PvViewer.prototype.setStatus = function (m) { if (this.statusEl) this.statusEl.textContent = m || ''; };

    /** 释放资源（SPA 切换页面时调用） */
    PvViewer.prototype.destroy = function () {
        try { if (this._ro) this._ro.disconnect(); } catch (e) {}
        if (this._upH) window.removeEventListener('pointerup', this._upH);
        if (this._onWinResize) window.removeEventListener('resize', this._onWinResize);
        if (this.toolbar && this.toolbar.destroy) this.toolbar.destroy();
        if (this._sb && this._sb.hideTimer) clearTimeout(this._sb.hideTimer);
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

    PvViewer.prototype.load = function () {
        var self = this;
        this.setStatus('正在加载影像数据…');
        PvApi.study(this.uid).then(function (j) {
            if (!j || j.code !== 200 || !j.data) { self.setStatus((j && j.msg) || '数据加载失败'); return; }
            self.setData(j.data);
            self.setStatus('');   // 加载完成后清除加载提示
        }).catch(function () { self.setStatus('网络请求失败'); });
    };

    PvViewer.prototype.setData = function (data) {
        this.st.data = data;
        this.st.series = (data.series && data.series.length) ? data.series : [];
        this.st.si = 0; this.st.fi = 0;
        if (this.titleEl) {
            var s = data.study || {};
            this.titleEl.textContent = (s.modality || '') + ' · ' + (s.description || '') + (s.accession_no ? ' · ' + s.accession_no : '');
        }
        var self = this;
        this.sidebar.render(this.st.series, 0, function (i) { self.setSeries(i); });
        this.applyDefaults();
        this.fit();
        this.updateHud();
        this.toolbar.sync(this.st);
        this.updateScrollbar();
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
            this.placeholder('暂无影像');
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
        this.drawAnnotations();
        PvOsd.draw(ctx, {
            cssW: this.cssW, cssH: this.cssH, dpr: this.dpr, data: this.st.data,
            series: this.curSeries(), fi: st.fi, count: this.frameCount(),
            ww: st.ww, wl: st.wl, zoom: st.zoom, rot: st.rot, flipH: st.flipH, flipV: st.flipV,
            gutter: (this.scrollEl && !this.scrollEl.hidden) ? 22 : 0
        });
    };
    PvViewer.prototype.placeholder = function (t) {
        var ctx = this.ctx;
        ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
        ctx.fillStyle = '#64748b'; ctx.font = '14px sans-serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        ctx.fillText(t, this.cssW / 2, this.cssH / 2);
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
        cv.addEventListener('pointermove', function (e) { self.onMove(e); });
        window.addEventListener('pointerup', this._upH = function (e) { self.onUp(e); });
    };
    PvViewer.prototype.onDown = function (e) {
        var pt = this._rel(e), st = this.st;
        try { this.canvas.setPointerCapture(e.pointerId); } catch (err) {}
        if (e.button === 1) { st.drag = { mode: 'pan', x: pt.x, y: pt.y, px: st.panX, py: st.panY }; return; }
        if (e.button === 2) { st.drag = { mode: 'wl', x: pt.x, y: pt.y, ww: st.ww, wl: st.wl }; return; }
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
        if (st.drag) { st.drag = null; return; }
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
        else if (a === 'back') { if (window.PvNav) window.PvNav.go('search'); return; }
        this.toolbar.sync(st); this.render();
    };

    /** 复制本检查的阅片直链（地址栏固定时的对外分享 / 外部系统调用入口） */
    PvViewer.prototype.copyDirectLink = function () {
        var link = this.direct || (window.PvNav ? window.PvNav.route('viewer', { uid: this.uid }) : '');
        if (!link) return;
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
        this.sidebar.setActive(i);
        this.applyDefaults(); this.fit();
        this.setStatus('序列 ' + (i + 1) + '：' + (this.curSeries().description || ''));
        this.toolbar.sync(this.st); this.render(); this.updateHud(); this.updateScrollbar();
    };
    PvViewer.prototype.setFrame = function (i) {
        var n = this.frameCount();
        i = clamp(i, 0, n - 1);
        this.st.fi = i; this.render();
        this.setStatus('切片 ' + (i + 1) + ' / ' + n);
        this.updateScrollbar();
    };
    PvViewer.prototype.fit = function () {
        this.st.zoom = clamp(Math.min(this.cssW / BASE, this.cssH / BASE) * 0.92, 0.05, 16);
        this.st.panX = 0; this.st.panY = 0;
        this.render();
    };
    PvViewer.prototype.updateHud = function () {
        var data = this.st.data || {}, p = data.patient || {}, s = data.study || {}, ser = this.curSeries() || {};
        if (this.pcName) this.pcName.textContent = p.name || '—';
        if (this.pcSub) this.pcSub.textContent = (p.gender || '') + (p.age ? '　/　' + p.age : '');
        if (this.pcBody) {
            var rows = [
                ['患者号', p.patient_id], ['门诊号', p.outpatient_no],
                ['检查号', s.accession_no], ['检查项目', s.description],
                ['检查时间', s.study_date], ['设备', s.station_name],
                ['机构', s.institution],
                ['序列', ser.series_id ? ('Ser ' + ser.series_id + ' · ' + (ser.description || '')) : '']
            ];
            var html = '';
            rows.forEach(function (r) { if (r[1]) html += '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd>'; });
            this.pcBody.innerHTML = html;
        }
    };

    /* ---------- 启动（页面生命周期由 spa.js / 页脚统一调度） ---------- */
    var instance = null;
    global.PvPages = global.PvPages || {};
    global.PvPages.viewer = {
        init: function (data) {
            data = data || {};
            var root = document.querySelector('[data-pv="app"]');
            if (!root || !data.uid) return;
            if (instance) { try { instance.destroy(); } catch (e) {} instance = null; }
            instance = new PvViewer(root, data.uid, data.direct);
        },
        destroy: function () {
            if (instance) { try { instance.destroy(); } catch (e) {} instance = null; }
        }
    };
    global.PvViewer = PvViewer;
})(window);
