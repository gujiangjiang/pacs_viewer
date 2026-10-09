/* ============================================================
 * assets/js/modules/viewer-actions.js — 阅片器工具 / 预设 / 动作（PvViewer 扩展）
 * ============================================================
 * 由 viewer.js 装配：工具切换、窗预设、动作分派（含窗格变换与缩放）、
 * 序列栏显隐、访问模式与控件状态、阅片灯。
 * 加载顺序须在 viewer.js 之后（扩展 PvViewer.prototype）。
 * ============================================================ */
(function (global) {
    'use strict';
    var PvViewer = global.PvViewer;
    var PRESETS = global.PvPresets;
    var clamp = PvRender.clamp;   // 复用通用钳位助手

    var PANE_ACTS = { 'rotate-cw': 1, 'rotate-ccw': 1, 'flip-h': 1, 'flip-v': 1, 'invert': 1, 'clear': 1, 'fit': 1, 'oneone': 1, 'prev': 1, 'next': 1, 'zoom-in': 1, 'zoom-out': 1 };

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
})(window);
