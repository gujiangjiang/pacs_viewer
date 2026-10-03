/* ============================================================
 * assets/js/modules/viewer-ctxmenu.js — 阅片器右键菜单（PvViewer 扩展）
 * ============================================================
 * 由 viewer.js 装配：作用于激活窗格的图像右键菜单。
 * 加载顺序须在 viewer.js 之后（扩展 PvViewer.prototype）。
 * ============================================================ */
(function (global) {
    'use strict';
    var PvViewer = global.PvViewer;
    var esc = PvUI.esc;

    /** 图标渲染：命中统一 SVG 图标库则用 SVG，否则按文本/emoji（如预设窗）显示 */
    function iconHtml(name) {
        if (global.PvIcons && global.PvIcons[name]) {
            return '<span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + global.PvIcons[name] + '</svg></span>';
        }
        return '<span class="ic">' + name + '</span>';
    }

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
})(window);
