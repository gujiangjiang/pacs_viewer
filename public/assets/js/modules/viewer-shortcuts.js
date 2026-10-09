/* ============================================================
 * assets/js/modules/viewer-shortcuts.js — 阅片器键盘快捷键（PvViewer 扩展）
 * ============================================================
 * 由 viewer.js 装配：快捷键绑定与说明模态框。
 * 加载顺序须在 viewer.js 之后（扩展 PvViewer.prototype）。
 * ============================================================ */
(function (global) {
    'use strict';
    var PvViewer = global.PvViewer;
    var esc = PvUI.esc;   // 复用通用转义助手

    /* 键盘快捷键映射（不在界面展示，避免臃肿；按 ? 查看说明） */
    var KEY_TOOLS = { w: 'wl', z: 'zoom', p: 'pan', l: 'length', a: 'angle', r: 'rect', e: 'ellipse' };
    var KEY_ACTS = { f: 'fit', i: 'invert', h: 'flip-h', v: 'flip-v', c: 'clear', d: 'dicom-info', s: 'save-image' };
    var KEY_LAYOUT = { '1': '1', '2': '2h', '3': '2v', '4': '4' };

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
})(window);
