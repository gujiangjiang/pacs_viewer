/* ============================================================
 * assets/js/modules/pane.js — 单个影像窗格（PvPane）
 * ============================================================
 * 画布渲染 / 交互 / 帧缓存与预取 / 帧滚动条 / 测量标注 / CT 值 / DICOM 详情。
 * 由 viewer.js 装配；对外暴露 PvPane。
 * ============================================================ */
(function (global) {
    'use strict';

    var BASE = PvRender.BASE;
    var clamp = PvRender.clamp;   // 复用通用钳位助手
    var esc = PvUI.esc;           // 复用通用转义助手
    var PRESETS = {
        soft: { ww: 400, wl: 40, label: '软组织窗' },
        lung: { ww: 1500, wl: -600, label: '肺窗' },
        bone: { ww: 2000, wl: 350, label: '骨窗' },
        full: { ww: 2500, wl: 250, label: '默认窗' }
    };
    global.PvPresets = PRESETS;

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

        this.imgCache = {};
        this._frames = {};         // 显示帧缓存（global frame index → {dec,raw}）
        this._instances = {};      // 解码实例缓存（instance index → {status,dec}，支持多帧复用）
        this._ctrls = [];          // 在途请求的 AbortController（离开/换序列时中止）
        this._prefetchSeq = 0;     // 预取代次，用于中止后停止预取循环
        this._dir = 1;             // 最近滚动方向（+1 向后 / -1 向前），用于预取偏置
        this.st = {
            uid: '', si: 0, fi: 0, ww: 400, wl: 40, isHU: true,
            zoom: 1, panX: 0, panY: 0, rot: 0, flipH: false, flipV: false, invert: false,
            annos: [], draft: null, drag: null
        };
        this._bind();
        this._bindScrollbar();
        this.resize();
        var self = this;
        if (window.ResizeObserver) { this._ro = new ResizeObserver(function () { self.resize(); self._scheduleRender(); }); this._ro.observe(this.stage); }
    }

    PvPane.prototype.destroy = function () {
        this._abortFetches();      // 中止后台预取，释放浏览器连接
        if (this._raf) { try { (window.cancelAnimationFrame || clearTimeout)(this._raf); } catch (e) {} this._raf = null; }
        try { if (this._ro) this._ro.disconnect(); } catch (e) {}
        if (this._upH) window.removeEventListener('pointerup', this._upH);
        if (this._sb && this._sb.hideTimer) clearTimeout(this._sb.hideTimer);
        if (this.el.parentNode) this.el.parentNode.removeChild(this.el);
    };

    /** 可中止的取字节请求：登记 AbortController，便于离开时立即释放连接 */
    PvPane.prototype._fetchBuffer = function (url) {
        var self = this;
        var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        if (ctrl) this._ctrls.push(ctrl);
        var opts = { credentials: 'same-origin' };
        if (ctrl) opts.signal = ctrl.signal;
        var cleanup = function () { if (ctrl) { var i = self._ctrls.indexOf(ctrl); if (i >= 0) self._ctrls.splice(i, 1); } };
        return fetch(url, opts).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.arrayBuffer();
        }).then(function (buf) { cleanup(); return buf; }, function (err) { cleanup(); throw err; });
    };
    /** 中止当前窗格全部在途请求（切换序列 / 销毁时调用） */
    PvPane.prototype._abortFetches = function () {
        this._prefetchSeq++;
        var list = this._ctrls; this._ctrls = [];
        for (var i = 0; i < list.length; i++) { try { list[i].abort(); } catch (e) {} }
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

    /* ---------- 标准 DICOM 帧获取与缓存（支持单帧与原生多帧实例） ---------- */
    PvPane.prototype._frameKey = function (series, fi) { return this.st.uid + '|' + series.series_id + '|' + fi; };
    PvPane.prototype._instKey = function (series, ii) { return this.st.uid + '|' + series.series_id + '|i' + ii; };
    /** 每实例帧数（缺省 1；>1 表示原生多帧 DICOM 实例） */
    PvPane.prototype._fpi = function (series) { return series ? Math.max(1, parseInt(series.frames_per_instance, 10) || 1) : 1; };

    /** 全局帧号 → {实例序号 ii, 实例内帧号 lf}（支持各实例帧数不同的多帧序列） */
    PvPane.prototype._frameLoc = function (series, fi) {
        var insts = series && series.instances;
        if (insts && insts.length) {
            var acc = 0;
            for (var i = 0; i < insts.length; i++) {
                var c = insts[i] | 0;
                if (fi < acc + c) return { ii: i, lf: fi - acc };
                acc += c;
            }
            var last = insts.length - 1;
            return { ii: last, lf: Math.max(0, (insts[last] | 0) - 1) };
        }
        var fpi = this._fpi(series);
        return { ii: Math.floor(fi / fpi), lf: fi % fpi };
    };

    /** 取指定帧（异步拉取标准 DICOM 并解码；多帧实例仅解码一次后按帧复用） */
    PvPane.prototype.getFrame = function (series, fi) {
        var fkey = this._frameKey(series, fi);
        var c = this._frames[fkey];
        if (c) return c;
        var loc = this._frameLoc(series, fi);
        var ii = loc.ii, lf = loc.lf;
        var ikey = this._instKey(series, ii);
        var inst = this._instances[ikey];
        if (inst && inst.status === 'ok') {
            // 已解码实例：按帧惰性生成显示值场
            this._frames[fkey] = { status: 'ok', dec: inst.dec, raw: PvRender.resample(inst.dec, BASE, lf) };
            return this._frames[fkey];
        }
        if (inst && inst.status === 'loading') return null;
        var url = series.images && series.images[ii];
        if (!url) return null;
        this._instances[ikey] = { status: 'loading' };
        var self = this;
        this._fetchBuffer(url)
            .then(function (buf) { return PvDecoder.decode(buf, BASE, lf); })
            .then(function (res) {
                self._instances[ikey] = { status: 'ok', dec: res.dec };
                self._frames[fkey] = { status: 'ok', dec: res.dec, raw: res.raw };
                self._trimFrames(); self._trimInstances();
                self.render();
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') return;   // 主动中止：不报错
                delete self._instances[ikey]; delete self._frames[fkey];
                self.setStatus('影像加载失败'); self.render();
            });
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

    /** 解码实例缓存上限控制（多帧实例较大，限制保留实例数） */
    PvPane.prototype._trimInstances = function () {
        var keys = Object.keys(this._instances);
        if (keys.length <= 24) return;
        var removed = 0;
        for (var i = 0; i < keys.length && removed < keys.length - 16; i++) {
            var inst = this._instances[keys[i]];
            if (inst && inst.status === 'ok') { delete this._instances[keys[i]]; removed++; }
        }
    };

    /**
     * 后台预取并解码整条序列，使滚动翻帧基本即时。
     * 并发刻意保持较低（2）：避免占满浏览器每主机连接数，导致切换页面 / 标签时
     * 的站点请求被排队而出现明显卡顿；离开窗格或换序列时会被 _abortFetches 中止。
     */
    PvPane.prototype.prefetch = function (series) {
        if (!series || series.format !== 'dicom' || !series.images || !series.images.length) return;
        var self = this, n = this.frameCount(), MAX = 2, cursor = 0;
        var seq = this._prefetchSeq, pendingInst = {};
        var order = [], seen = {}, cur = this.st.fi, dir = this._dir || 1;
        var push = function (k) { if (k >= 0 && k < n && !seen[k]) { seen[k] = 1; order.push(k); } };
        push(cur);
        /* 按最近滚动方向优先预取前方帧（更跟手），再补后方 */
        for (var d = 1; d < n; d++) push(cur + d * dir);
        for (var d = 1; d < n; d++) push(cur - d * dir);
        /* 大型序列仅预取离当前帧最近的有限窗口（order 已按距离由近到远排序），
         * 其余按需加载，降低内存与网络占用。 */
        if (order.length > 60) order = order.slice(0, 60);
        function next() {
            if (seq !== self._prefetchSeq || cursor >= order.length) return;
            var idx = order[cursor++];
            var loc = self._frameLoc(series, idx), ii = loc.ii, lf = loc.lf;
            var ikey = self._instKey(series, ii), fkey = self._frameKey(series, idx);
            // 同一实例只解码一次：已缓存或已在途则跳过
            if (self._frames[fkey] || self._instances[ikey] || pendingInst[ii]) { next(); return; }
            var url = series.images[ii];
            if (!url) { next(); return; }
            pendingInst[ii] = 1;
            self._instances[ikey] = { status: 'loading' };
            self._fetchBuffer(url)
                .then(function (buf) { return PvDecoder.decode(buf, BASE, lf); })
                .then(function (res) {
                    self._instances[ikey] = { status: 'ok', dec: res.dec };
                    self._frames[fkey] = { status: 'ok', dec: res.dec, raw: res.raw };
                    self._trimFrames(); self._trimInstances();
                    if (self.curSeries() === series && self.st.fi === idx) self.render();
                })
                .catch(function (err) {
                    delete self._instances[ikey];
                    if (!(err && err.name === 'AbortError')) delete self._frames[fkey];
                })
                .then(function () { next(); });
        }
        /* 稍作延迟再启动预取：给首帧渲染与即时交互让路；若其间切换序列/离开，
         * _abortFetches 会递增 seq，使本次预取循环自动失效。 */
        setTimeout(function () { for (var k = 0; k < MAX; k++) next(); }, 200);
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
    /** 显示值场 → 窗宽窗位画布（复用 ImageData 缓冲，减少分配与 GC） */
    PvPane.prototype.windowRaw = function (raw, ww, wl, invert) {
        if (!this._winImg || this._winImg.width !== BASE) this._winImg = new ImageData(BASE, BASE);
        var img = PvRender.window(raw, BASE, ww, wl, invert, this._winImg);
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
    /** 合并同一动画帧内的多次渲染请求（拖动 / 滚轮 / 缩放等高频场景） */
    PvPane.prototype._scheduleRender = function () {
        if (this._rafPending) return;
        var self = this;
        this._rafPending = true;
        var raf = window.requestAnimationFrame || function (fn) { return setTimeout(fn, 16); };
        this._raf = raf(function () { self._rafPending = false; self._raf = null; self.render(); });
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
            this._scheduleRender(); return;
        }
        if (st.draft) {
            var p = this.screenToImg(pt.x, pt.y);
            if (st.draft.type === 'rect' || st.draft.type === 'ellipse') st.draft.fixed[1] = p; else st.draft.hover = p;
            this._scheduleRender();
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
        if (this.viewer.tool === 'zoom' || e.ctrlKey || e.metaKey) { this._zoomTo(st.zoom * (e.deltaY < 0 ? 1.12 : 1 / 1.12), pt.x, pt.y); this._scheduleRender(); return; }
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
        this._scheduleRender();
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
    /** 采集当前序列的视图状态（缩放/平移/窗值/变换/测量/帧） */
    PvPane.prototype.captureView = function () {
        var s = this.st;
        return {
            ww: s.ww, wl: s.wl, zoom: s.zoom, panX: s.panX, panY: s.panY,
            rot: s.rot, flipH: s.flipH, flipV: s.flipV, invert: s.invert,
            fi: s.fi, annos: (s.annos || []).slice()
        };
    };
    /** 应用视图状态（保留原缩放平移，不重新 fit） */
    PvPane.prototype.applyView = function (v) {
        if (!v) return;
        var s = this.st;
        s.ww = v.ww; s.wl = v.wl; s.zoom = v.zoom; s.panX = v.panX; s.panY = v.panY;
        s.rot = v.rot; s.flipH = !!v.flipH; s.flipV = !!v.flipV; s.invert = !!v.invert;
        s.fi = clamp(parseInt(v.fi, 10) || 0, 0, Math.max(0, this.frameCount() - 1));
        s.annos = (v.annos || []).slice();
        s.draft = null;
        s.isHU = this.frameIsHU();
    };

    /**
     * 载入序列。
     * @param {object} [opts] { fresh:true } 重置该序列的操作痕迹（双击）；否则恢复既往状态。
     */
    PvPane.prototype.setSeries = function (uid, si, opts) {
        var d = this.viewer.study(uid);
        if (!d || !d.series[si]) return;
        opts = opts || {};
        // 切走前保存当前序列的操作痕迹
        if (this.st.uid && this.curSeries()) this.viewer.savePaneState(this);
        this._abortFetches();      // 中止上一条序列仍在进行的预取
        this.st.uid = uid; this.st.si = si; this.st.fi = 0;
        this._wheelAcc = 0; this.st.draft = null; this._frames = {}; this._instances = {}; this._dir = 1;
        var ser = d.series[si];
        var saved = opts.fresh ? null : this.viewer.seriesState(uid, ser.series_id);
        if (saved && saved.view) {
            this.st.annos = [];
            this.applyView(saved.view);
        } else {
            this.st.annos = [];
            this.applyDefaults(); this.fit();
        }
        this.updateTitle(); this.updateScrollbar();
        this.viewer.afterPaneLoad(this);
        this.prefetch(ser);
    };
    PvPane.prototype.setFrame = function (i) {
        var n = this.frameCount();
        if (n <= 1) return;
        i = clamp(i, 0, n - 1);
        if (i > this.st.fi) this._dir = 1; else if (i < this.st.fi) this._dir = -1;
        this.st.fi = i;
        this._scheduleRender(); this.updateScrollbar();
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
            var base = this._frameLoc(ser, this.st.fi).lf * dec.rows * dec.columns;   // 多帧偏移
            var stored = dec.pixels[base + sy * dec.columns + sx];
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
            var loc = this._frameLoc(ser, fi), ii = loc.ii, lf = loc.lf;
            var cached = this._frames[this._frameKey(ser, fi)];
            if (cached && cached.status === 'ok') { this._paintRaw(cx, cached.raw); return Promise.resolve(cv); }
            var inst = this._instances[this._instKey(ser, ii)];
            if (inst && inst.status === 'ok') { this._paintRaw(cx, PvRender.resample(inst.dec, BASE, lf)); return Promise.resolve(cv); }
            var durl = ser.images && ser.images[ii];
            if (!durl) return Promise.resolve(cv);
            return fetch(durl, { credentials: 'same-origin' }).then(function (r) { return r.arrayBuffer(); })
                .then(function (buf) { return PvDecoder.decode(buf, BASE, lf); })
                .then(function (res) { self._paintRaw(cx, res.raw); return cv; })
                .catch(function () { return cv; });
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

    global.PvPane = PvPane;
})(window);
