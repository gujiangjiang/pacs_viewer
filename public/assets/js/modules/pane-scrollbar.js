/* ============================================================
 * assets/js/modules/pane-scrollbar.js — 窗格帧滚动条（PvPane 扩展）
 * ============================================================
 * 由 pane.js 装配：多帧序列右侧帧滚动条的绘制与交互。
 * 加载顺序须在 pane.js 之后（扩展 PvPane.prototype）。
 * ============================================================ */
(function (global) {
    'use strict';
    var PvPane = global.PvPane;

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
})(window);
