/* ============================================================
 * assets/js/modules/viewer-session.js — 阅片器会话记忆（PvViewer 扩展）
 * ============================================================
 * 由 viewer.js 装配：按序列保留操作痕迹、工作区会话防抖落盘与恢复。
 * 加载顺序须在 viewer.js 之后（扩展 PvViewer.prototype）。
 * ============================================================ */
(function (global) {
    'use strict';
    var PvViewer = global.PvViewer;

    /* 序列操作痕迹（缩放/平移/窗值/变换/测量/帧 + 关联布局），会话内按序列保留 */
    var SERIES_LS = 'pacs_series_v1';
    function loadSeriesMap() { try { return JSON.parse(sessionStorage.getItem(SERIES_LS)) || {}; } catch (e) { return {}; } }

    PvViewer.prototype._skey = function (uid, sid) { return uid + '|' + sid; };
    PvViewer.prototype.seriesState = function (uid, sid) {
        if (!uid || sid == null) return null;
        return this.seriesMap[this._skey(uid, sid)] || null;
    };
    PvViewer.prototype.savePaneState = function (pane) {
        var ser = pane && pane.curSeries();
        if (!pane || !pane.st.uid || !ser) return;
        var key = this._skey(pane.st.uid, ser.series_id);
        var rec = this.seriesMap[key] || {};
        rec.view = pane.captureView();
        this.seriesMap[key] = rec;
        this._persistSeriesMap();
    };
    PvViewer.prototype.clearStudySeriesState = function (uid) {
        if (!uid) return;
        var pre = uid + '|';
        for (var k in this.seriesMap) { if (k.indexOf(pre) === 0) delete this.seriesMap[k]; }
        this._persistSeriesMap();
    };
    PvViewer.prototype.clearAllSeriesState = function () {
        this.seriesMap = {};
        this._persistSeriesMap();
    };
    PvViewer.prototype._persistSeriesMap = function () {
        if (this.guest) return;
        try { sessionStorage.setItem(SERIES_LS, JSON.stringify(this.seriesMap)); } catch (e) {}
    };

    /* ---------- 会话记忆 ---------- */
    /** 合并高频写入（翻帧滚动等）到一次 sessionStorage 落盘 */
    PvViewer.prototype.persist = function () {
        var self = this;
        if (this._persistTimer) clearTimeout(this._persistTimer);
        this._persistTimer = setTimeout(function () { self._persistTimer = null; self.persistNow(); }, 150);
    };
    PvViewer.prototype.persistNow = function () {
        if (this.guest) return;   // 访客不落盘会话状态
        try {
            var state = {
                layout: this.layout, active: this.active,
                studies: this.ws.studies.map(function (x) { return { uid: x.uid, collapsed: !!x.collapsed }; }),
                panes: this.panes.map(function (p) { return { uid: p.st.uid, si: p.st.si, fi: p.st.fi }; })
            };
            sessionStorage.setItem('pacs_workspace_v1', JSON.stringify(state));
        } catch (e) {}
    };
    PvViewer.prototype.loadState = function () {
        if (this.guest) return null;   // 访客不做会话恢复
        try { return JSON.parse(sessionStorage.getItem('pacs_workspace_v1')); } catch (e) { return null; }
    };
    PvViewer.prototype.clearState = function () {
        if (this._persistTimer) { clearTimeout(this._persistTimer); this._persistTimer = null; }
        try { sessionStorage.removeItem('pacs_workspace_v1'); } catch (e) {}
    };

    // 供 viewer.js 构造函数读取（构造发生在页面初始化时，此时本模块已加载）
    global.PvViewerSession = { loadSeriesMap: loadSeriesMap };
})(window);
