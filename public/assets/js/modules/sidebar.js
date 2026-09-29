/* ============================================================
 * assets/js/modules/sidebar.js — 左侧序列栏模块（多检查工作区）
 * 按检查分组渲染：每个检查一个可折叠标题 + 其序列缩略图；
 * 支持同时展开多个检查、单独关闭某个检查。
 * ============================================================ */
(function (global) {
    'use strict';
    var THUMB = 128;

    function PvSidebar(el) {
        this.el = el;
        this.studies = [];
        this.active = 0;
        this.h = {};
        this._offscreen = document.createElement('canvas');
        this._offscreen.width = PvRender.BASE; this._offscreen.height = PvRender.BASE;
    }
    PvSidebar.prototype.esc = function (s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    };

    /** 渲染多个检查分组；activeRef = { uid, si } 标识当前激活窗格加载的检查/序列 */
    PvSidebar.prototype.renderStudies = function (studies, activeRef, handlers) {
        this.studies = studies || []; this.activeRef = activeRef || {}; this.h = handlers || {};
        var self = this;
        if (!this.el) return;
        if (!this.studies.length) { this.el.innerHTML = '<div class="pv-film-empty">暂无已打开的检查</div>'; return; }

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
                html += '<div class="pv-thumb' + (active && si === (self.activeRef.si || 0) ? ' active' : '') + '" data-g="' + gi + '" data-s="' + si + '">'
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

        this.studies.forEach(function (st, gi) {
            (st.series || []).forEach(function (se, si) {
                var cv = self.el.querySelector('.pv-thumb[data-g="' + gi + '"][data-s="' + si + '"] canvas');
                if (cv) self.drawThumb(cv, se);
            });
        });
    };

    PvSidebar.prototype.drawThumb = function (cv, series) {
        var ctx = cv.getContext('2d');
        ctx.fillStyle = '#000'; ctx.fillRect(0, 0, cv.width, cv.height);
        if (series.format === 'dicom') {
            var dsrc = series.images && series.images[0];
            if (!dsrc) return;
            var self = this;
            fetch(dsrc, { credentials: 'same-origin' }).then(function (r) { return r.arrayBuffer(); }).then(function (buf) {
                var dec = window.PvDicom ? PvDicom.decode(buf) : null;
                if (!dec) return;
                var ww = parseFloat(series.window_width) || 256, wl = parseFloat(series.window_center) || 128;
                var img = PvRender.decodeToImage(dec, cv.width, ww, wl, false);
                var tmp = self._thumbCanvas || (self._thumbCanvas = document.createElement('canvas'));
                tmp.width = cv.width; tmp.height = cv.height;
                tmp.getContext('2d').putImageData(img, 0, 0);
                ctx.fillStyle = '#000'; ctx.fillRect(0, 0, cv.width, cv.height);
                ctx.drawImage(tmp, 0, 0);
            }).catch(function () {});
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
