/* ============================================================
 * assets/js/modules/sidebar.js — 左侧序列栏模块（多检查工作区）
 * 按检查分组渲染：每个检查一个可折叠标题 + 其序列缩略图；
 * 支持同时展开多个检查、单独关闭某个检查。
 *
 * 缩略图策略：DICOM 首帧解码一次后缓存于内存并持久化到浏览器
 * localStorage，重建列表时同步绘制，避免异步解码造成的闪烁。
 * ============================================================ */
(function (global) {
    'use strict';
    var THUMB = 128;

    var THUMB_CACHE = {};                 // key -> ImageData（内存）
    var LS_PREFIX = 'pvthumb:';
    var LS_INDEX = 'pvthumb:__idx';
    var LS_MAX = 120;                     // 最多持久化 120 张缩略图

    function hashStr(s) {
        var h = 5381;
        for (var i = 0; i < s.length; i++) h = ((h << 5) + h + s.charCodeAt(i)) | 0;
        return (h >>> 0).toString(36);
    }
    function lsGet(hk) {
        try { return localStorage.getItem(LS_PREFIX + hk) || ''; } catch (e) { return ''; }
    }
    function lsSet(hk, val) {
        try {
            localStorage.setItem(LS_PREFIX + hk, val);
            var idx = [];
            try { idx = JSON.parse(localStorage.getItem(LS_INDEX) || '[]'); } catch (e) { idx = []; }
            if (idx.indexOf(hk) < 0) idx.push(hk);
            while (idx.length > LS_MAX) {
                var old = idx.shift();
                try { localStorage.removeItem(LS_PREFIX + old); } catch (e) {}
            }
            localStorage.setItem(LS_INDEX, JSON.stringify(idx));
        } catch (e) { /* 配额不足等：忽略，退化为内存缓存 */ }
    }
    /** ImageData → 灰度字节 base64（体积约为 RGBA 的 1/4） */
    function encodeGray(img) {
        var n = img.width * img.height, bytes = new Uint8Array(n);
        for (var i = 0; i < n; i++) bytes[i] = img.data[i * 4];
        var s = '', CH = 0x8000;
        for (var j = 0; j < n; j += CH) s += String.fromCharCode.apply(null, bytes.subarray(j, Math.min(j + CH, n)));
        return btoa(s);
    }
    function decodeGray(b64, w, h) {
        var bin;
        try { bin = atob(b64); } catch (e) { return null; }
        if (bin.length < w * h) return null;
        var img = new ImageData(w, h), d = img.data;
        for (var i = 0; i < w * h; i++) { var g = bin.charCodeAt(i); var j = i * 4; d[j] = d[j + 1] = d[j + 2] = g; d[j + 3] = 255; }
        return img;
    }

    function PvSidebar(el) {
        this.el = el;
        this.studies = [];
        this.active = 0;
        this.h = {};
        this._sig = '';
    }
    /** HTML 转义（复用通用助手，避免重复实现） */
    PvSidebar.prototype.esc = function (s) { return PvUI.esc(s); };

    /** 结构签名：检查列表 / 折叠状态 / 序列构成 未变时，仅更新激活高亮，不重建 DOM */
    function structureSig(studies) {
        return JSON.stringify((studies || []).map(function (st) {
            return [st.uid, !!st.collapsed, (st.series || []).map(function (se) { return String(se.series_id); })];
        }));
    }

    /** 渲染多个检查分组；activeRef = { uid, si } 标识当前激活窗格加载的检查/序列 */
    PvSidebar.prototype.renderStudies = function (studies, activeRef, handlers) {
        this.studies = studies || []; this.activeRef = activeRef || {}; this.h = handlers || {};
        var self = this;
        if (!this.el) return;
        if (!this.studies.length) { this.el.innerHTML = '<div class="pv-film-empty">暂无已打开的检查</div>'; this._sig = ''; return; }

        var sig = structureSig(this.studies);
        if (this._sig === sig && this.el.querySelector('.pv-sg')) { this._applyActive(); return; }
        this._sig = sig;

        var html = '';
        this.studies.forEach(function (st, gi) {
            var d = st.data || {}, p = d.patient || {}, s = d.study || {};
            var active = (st.uid === self.activeRef.uid);
            html += '<div class="pv-sg' + (active ? ' active' : '') + (st.collapsed ? ' collapsed' : '') + '">'
                + '<div class="pv-sg-head" data-toggle="' + gi + '">'
                +   '<div class="pv-sg-title"><span class="pv-sg-name">' + self.esc(p.name || '—') + '</span>'
                +     '<span class="pv-sg-sub">' + self.esc((p.gender || '') + (p.age ? '　' + p.age : '')) + '</span></div>'
                +   '<span class="pv-sg-mod">' + self.esc(s.modality || '') + '</span>'
                +   '<button type="button" class="pv-sg-close" data-close="' + gi + '" title="关闭该检查">×</button>'
                + '</div>'
                + '<div class="pv-sg-series">';
            (st.series || []).forEach(function (se, si) {
                var tkey = self.thumbKey(se);
                html += '<div class="pv-thumb' + (active && si === (self.activeRef.si || 0) ? ' active' : '') + '" data-g="' + gi + '" data-s="' + si + '" data-tkey="' + self.esc(tkey) + '">'
                    + '<canvas class="pv-thumb-cv" width="' + THUMB + '" height="' + THUMB + '"></canvas>'
                    + '<div class="pv-thumb-meta"><span class="pv-thumb-id">Ser ' + self.esc(se.series_id) + '</span>'
                    + '<span class="pv-thumb-n">' + (se.slice_count || (se.images ? se.images.length : 1)) + ' 帧</span></div>'
                    + '<div class="pv-thumb-desc" title="' + self.esc(se.description || '') + '">' + self.esc(se.description || '') + '</div></div>';
            });
            html += '</div></div>';
        });
        this.el.innerHTML = html;

        Array.prototype.forEach.call(this.el.querySelectorAll('.pv-thumb'), function (th) {
            th.addEventListener('click', function (e) {
                e.stopPropagation();
                var gi = parseInt(th.getAttribute('data-g'), 10), si = parseInt(th.getAttribute('data-s'), 10);
                if (self.h.onSeries) self.h.onSeries(gi, si);
            });
        });
        Array.prototype.forEach.call(this.el.querySelectorAll('.pv-sg-head'), function (h) {
            h.addEventListener('click', function (e) {
                if (e.target.closest && e.target.closest('[data-close]')) return;
                if (self.h.onToggle) self.h.onToggle(parseInt(h.getAttribute('data-toggle'), 10));
            });
        });
        Array.prototype.forEach.call(this.el.querySelectorAll('[data-close]'), function (b) {
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                if (self.h.onClose) self.h.onClose(parseInt(b.getAttribute('data-close'), 10));
            });
        });

        // 缩略图懒加载：仅绘制进入视口的序列，减少首屏解码与请求
        var thumbs = this.el.querySelectorAll('.pv-thumb');
        if (global.IntersectionObserver) {
            if (this._thumbIO) this._thumbIO.disconnect();
            this._thumbIO = new global.IntersectionObserver(function (entries) {
                entries.forEach(function (en) {
                    if (!en.isIntersecting) return;
                    var th = en.target;
                    var gi = parseInt(th.getAttribute('data-g'), 10), si = parseInt(th.getAttribute('data-s'), 10);
                    var st = self.studies[gi], se = st && st.series[si];
                    var cv = th.querySelector('canvas');
                    if (se && cv) self.drawThumb(cv, se);
                    self._thumbIO.unobserve(th);
                });
            }, { root: this.el, rootMargin: '240px' });
            Array.prototype.forEach.call(thumbs, function (th) { self._thumbIO.observe(th); });
        } else {
            this.studies.forEach(function (st, gi) {
                (st.series || []).forEach(function (se, si) {
                    var cv = self.el.querySelector('.pv-thumb[data-g="' + gi + '"][data-s="' + si + '"] canvas');
                    if (cv) self.drawThumb(cv, se);
                });
            });
        }
    };

    /** 仅更新激活高亮（不重建 DOM，缩略图保持显示） */
    PvSidebar.prototype._applyActive = function () {
        var ref = this.activeRef || {}, self = this;
        Array.prototype.forEach.call(this.el.querySelectorAll('.pv-sg'), function (sg, gi) {
            var st = self.studies[gi];
            sg.classList.toggle('active', !!(st && ref.uid && st.uid === ref.uid));
        });
        Array.prototype.forEach.call(this.el.querySelectorAll('.pv-thumb'), function (th) {
            var g = parseInt(th.getAttribute('data-g'), 10), s = parseInt(th.getAttribute('data-s'), 10);
            var st = self.studies[g];
            th.classList.toggle('active', !!(st && ref.uid && st.uid === ref.uid && s === (ref.si || 0)));
        });
    };

    PvSidebar.prototype.thumbKey = function (series) {
        var url = (series.images && series.images[0]) || '';
        return url + '|' + (series.window_width || '') + '|' + (series.window_center || '');
    };
    PvSidebar.prototype._paintThumb = function (cv, img) {
        var ctx = cv.getContext('2d');
        ctx.fillStyle = '#000'; ctx.fillRect(0, 0, cv.width, cv.height);
        ctx.putImageData(img, 0, 0);
    };
    PvSidebar.prototype._repaintThumbs = function (key) {
        var img = THUMB_CACHE[key];
        if (!img) return;
        Array.prototype.forEach.call(document.querySelectorAll('.pv-thumb[data-tkey]'), function (th) {
            if (th.getAttribute('data-tkey') !== key) return;
            var cv = th.querySelector('canvas');
            if (cv) { var ctx = cv.getContext('2d'); ctx.fillStyle = '#000'; ctx.fillRect(0, 0, cv.width, cv.height); ctx.putImageData(img, 0, 0); }
        });
    };
    PvSidebar.prototype.drawThumb = function (cv, series) {
        var ctx = cv.getContext('2d');
        ctx.fillStyle = '#000'; ctx.fillRect(0, 0, cv.width, cv.height);
        if (series.format === 'dicom') {
            var key = this.thumbKey(series), hk = hashStr(key);
            if (THUMB_CACHE[key]) { this._paintThumb(cv, THUMB_CACHE[key]); return; }
            var stored = lsGet(hk);
            if (stored) {
                var img0 = decodeGray(stored, THUMB, THUMB);
                if (img0) { THUMB_CACHE[key] = img0; this._paintThumb(cv, img0); return; }
            }
            this._pending = this._pending || {};
            if (this._pending[key]) return;
            this._pending[key] = 1;
            var self = this;
            // 回退：下载首个实例并解码其首帧
            var decodeFirst = function () {
                var dsrc = series.images && series.images[0];
                if (!dsrc) { delete self._pending[key]; return; }
                fetch(dsrc, { credentials: 'same-origin' }).then(function (r) { return r.arrayBuffer(); }).then(function (buf) {
                    var dec = window.PvDicom ? PvDicom.decode(buf) : null;
                    if (dec) {
                        var ww = parseFloat(series.window_width) || 256, wl = parseFloat(series.window_center) || 128;
                        var img = PvRender.decodeToImage(dec, THUMB, ww, wl, false);
                        THUMB_CACHE[key] = img;
                        lsSet(hk, encodeGray(img));
                        self._repaintThumbs(key);
                    }
                    delete self._pending[key];
                }).catch(function () { delete self._pending[key]; });
            };
            // 优先使用服务端缩略图/渲染图端点（小图，快且省流量）；失败时回退解码首帧
            if (series.thumbnail) {
                var im = new Image();
                im.onload = function () {
                    var c2 = cv.getContext('2d');
                    c2.fillStyle = '#000'; c2.fillRect(0, 0, cv.width, cv.height);
                    var sc = Math.min(cv.width / im.width, cv.height / im.height);
                    var dw = im.width * sc, dh = im.height * sc;
                    c2.drawImage(im, (cv.width - dw) / 2, (cv.height - dh) / 2, dw, dh);
                    try {
                        var id = c2.getImageData(0, 0, cv.width, cv.height);
                        THUMB_CACHE[key] = id; lsSet(hk, encodeGray(id));
                    } catch (e) {}
                    delete self._pending[key];
                };
                im.onerror = function () { decodeFirst(); };
                im.src = series.thumbnail;
                return;
            }
            decodeFirst();
            return;
        }
        var src = series.images && series.images[0];
        if (!src) return;
        var im = new Image();
        im.onload = function () {
            ctx.fillStyle = '#000'; ctx.fillRect(0, 0, cv.width, cv.height);
            var sc = Math.min(cv.width / im.width, cv.height / im.height);
            var dw = im.width * sc, dh = im.height * sc;
            ctx.drawImage(im, (cv.width - dw) / 2, (cv.height - dh) / 2, dw, dh);
        };
        im.src = src;
    };

    global.PvSidebar = PvSidebar;
})(window);
