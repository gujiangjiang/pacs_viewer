/* ============================================================
 * assets/js/modules/viewer-host.js — 宿主页指令桥（PvViewer 扩展）
 * ============================================================
 * 由 viewer.js 装配：被 PACS 外呼系统 iframe 嵌入时，宿主通过
 * window.postMessage({type:'pv-command', command|tool|preset|value}) 远程驱动工具栏。
 * 加载顺序须在 viewer.js 之后（扩展 PvViewer.prototype）。
 * ============================================================ */
(function (global) {
    'use strict';
    var PvViewer = global.PvViewer;

    /* ---------- 宿主页指令桥（被 PACS 外呼系统 iframe 嵌入时，工具栏可远程驱动） ----------
     * 宿主通过 window.postMessage({type:'pv-command', command|tool|preset|value}) 发送指令，
     * 支持：tool（wl/zoom/pan/length/angle/rect/ellipse）、preset、fit、reset、rotate、flip、invert。 */
    PvViewer.prototype._bindHostCommands = function () {
        var self = this;
        this._onHostMsg = function (e) {
            if (!self._isTrustedHost(e)) return;   // 仅接受可信来源
            var d = e && e.data;
            if (!d || typeof d !== 'object' || d.type !== 'pv-command') return;
            try { self.applyHostCommand(d); } catch (err) { /* 忽略非法指令 */ }
        };
        window.addEventListener('message', this._onHostMsg);
    };
    /**
     * 指令来源校验：
     *   ① 配置了 hostOrigin → 严格匹配该来源；
     *   ② 未配置时，信任嵌入本页的直接父窗口（iframe 宿主，跨域亦放行）；
     *   ③ 其余仅接受同源消息。
     * 如此既能让独立域的宿主工作站驱动工具栏，又不会被任意第三方页面远程操控。
     */
    PvViewer.prototype._isTrustedHost = function (e) {
        if (!e) return false;
        if (this.hostOrigin) return e.origin === this.hostOrigin;
        if (global.parent && global.parent !== global && e.source === global.parent) return true;
        return e.origin === global.location.origin;
    };
    /** 宿主指令中可直接作为“工具名”的命令（兼容 {command:'zoom'} 一类简写） */
    var HOST_TOOLS = { wl: 1, zoom: 1, pan: 1, length: 1, angle: 1, rect: 1, ellipse: 1 };
    PvViewer.prototype.applyHostCommand = function (d) {
        var p = this.activePane();
        if (!p) return;
        var cmd = d.command || d.action || '';
        // 工具：支持 {tool:'zoom'}、{command:'tool',value:'zoom'} 与 {command:'zoom'} 三种写法
        var tool = d.tool || (cmd === 'tool' ? d.value : '') || (HOST_TOOLS[cmd] ? cmd : '');
        if (tool) { this.setTool(tool); return; }
        if (cmd === 'preset') { this.setPreset(d.preset || d.value || ''); return; }
        if (!p.hasImage()) return;
        if (cmd === 'fit') { p.fit(); return; }
        if (cmd === 'oneone') { this._paneAction(p, 'oneone'); return; }
        if (cmd === 'reset') {
            // 等价双击左侧序列缩略图：重置为该视图默认状态（重载 + 默认窗 + 适应窗口）
            var gi = this.indexOf(p.st.uid), si = p.st.si;
            if (gi >= 0) this.setSeriesOnActive(gi, si, true);
            p.setStatus('已重置为该视图默认状态'); return;
        }
        if (cmd === 'rotate') { this._paneAction(p, 'rotate-cw'); return; }
        if (cmd === 'flip') { this._paneAction(p, 'flip-h'); return; }
        if (cmd === 'invert') { this._paneAction(p, 'invert'); return; }
    };
})(window);
