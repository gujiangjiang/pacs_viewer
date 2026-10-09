/* ============================================================
 * assets/js/modules/viewer-workspace.js — 阅片器工作区与序列栏（PvViewer 扩展）
 * ============================================================
 * 由 viewer.js 装配：检查的打开 / 恢复 / 渲染、序列栏、关闭与清空、激活窗格载入序列。
 * 加载顺序须在 viewer.js 之后（扩展 PvViewer.prototype）。
 * ============================================================ */
(function (global) {
    'use strict';
    var PvViewer = global.PvViewer;

    PvViewer.prototype.openStudy = function (uid, mode, noCull) {
        var self = this;
        var idx = this.indexOf(uid);
        var p = this.activePane();
        if (idx >= 0) {
            this.ws.studies[idx].collapsed = false;
            if (p) p.setSeries(uid, 0);
            this.renderSidebar();
            this.sidebar.scrollToSeries(uid, 0);   // 自动滚动定位到该检查序列
            if (p) p.setStatus('该检查已在影像视图中打开，已定位');
            return Promise.resolve();
        }
        // 打开新检查：恢复默认单视图
        if (this.layout !== '1') this.setLayout('1', false);
        var p0 = this.activePane(); if (p0) p0.setStatus('正在加载影像数据…');
        return PvApi.study(uid, { fresh: true }).then(function (j) {
            if (!j || j.code !== 200 || !j.data) { if (p0) p0.setStatus((j && j.msg) || '数据加载失败'); return; }
            if (mode === 'replace') { self.ws.studies = []; self.clearAllSeriesState(); self.panes.forEach(function (pp) { pp.st.uid = ''; pp.st.si = 0; pp.st.fi = 0; }); }
            self.ws.studies.push({ uid: uid, data: j.data, series: j.data.series || [], collapsed: false });
            // noCull：按申请单（A2）一次打开该单全部 Study 时不按上限裁剪
            if (!noCull) {
                while (self.ws.studies.length > self.studyLimit) {
                    var ev = self.ws.studies.shift();
                    self.clearStudySeriesState(ev.uid);
                    // 窗格若引用被挤占的检查则清空引用（渲染空态，避免残留失效 uid）
                    self.panes.forEach(function (pp) { if (pp.st.uid === ev.uid) { pp.st.uid = ''; pp.st.si = 0; pp.st.fi = 0; } });
                }
            }
            self.ws.studies.forEach(function (x, k) { x.collapsed = (k !== self.ws.studies.length - 1); });
            var a = self.activePane(); if (a) a.setSeries(uid, 0);
            self.panes.forEach(function (pp) { if (pp !== a) { pp.updateTitle(); pp.updateScrollbar(); pp.render(); } });
            self.renderSidebar();
            self.sidebar.scrollToSeries(uid, 0);   // 新打开的检查自动滚动到序列栏顶部
            self.logEvent('read');
            if (a) a.setStatus('');
        }).catch(function () { if (p0) p0.setStatus('网络请求失败'); });
    };
    PvViewer.prototype.boot = function () {
        var self = this;
        var saved = this.loadState();
        // 立即给出加载反馈，避免恢复期间看似空白卡住
        var ap = this.activePane(); if (ap) ap.setStatus('正在恢复影像视图…');
        if (this.route.uid) {
            this.restoreStudies(saved, function () { self.openStudy(self.route.uid, self.route.mode); });
            return;
        }
        if (this.route.uids && this.route.uids.length) {
            // A2：按申请单一并打开该单全部 Study（siblings 不受 study_limit 裁剪）
            this.restoreStudies(saved, function () {
                var list = self.route.uids.slice();
                var seq = function (i) {
                    if (i >= list.length) { self.refreshControlState(); return; }
                    Promise.resolve(self.openStudy(list[i], i === 0 ? 'replace' : 'append', true)).then(function () { seq(i + 1); });
                };
                seq(0);
            });
            return;
        }
        this.restoreStudies(saved, function () {
            if (!self.ws.studies.length) { self.showEmpty(); return; }
            // 窗格已由 restoreStudies→renderPanes 按痕迹渲染，勿再 renderAll（会重置缩放窗值）
            self.renderSidebar(); self.refreshControlState();
            var p = self.activePane(); if (p) p.setStatus('');
        });
    };
    PvViewer.prototype.restoreStudies = function (saved, cb) {
        var self = this;
        if (!saved || !saved.studies || !saved.studies.length) { if (cb) cb(); return; }
        if (saved.layout) this.setLayout(saved.layout, true);
        // 并行拉取各检查数据（Promise.all 保持原顺序），缩短恢复时间
        var tasks = saved.studies.map(function (item) {
            return PvApi.study(item.uid).then(function (j) {
                if (j && j.code === 200 && j.data) {
                    return { uid: item.uid, data: j.data, series: j.data.series || [], collapsed: !!item.collapsed };
                }
                return null;
            }).catch(function () { return null; });
        });
        Promise.all(tasks).then(function (list) {
            var restored = list.filter(function (x) { return x; });
            self.ws.studies = restored;
            // 恢复窗格内容
            if (saved.panes) {
                saved.panes.forEach(function (ps, i) {
                    var pane = self.panes[i]; if (!pane) return;
                    if (ps && ps.uid && self.study(ps.uid)) {
                        pane.st.uid = ps.uid; pane.st.si = ps.si || 0; pane.st.fi = ps.fi || 0;
                    }
                });
            }
            if (typeof saved.active === 'number' && saved.active >= 0 && saved.active < self.panes.length) self.active = saved.active;
            self.renderPanes();
            if (cb) cb();
        });
    };
    /** 重绘所有窗格但保留各序列既有操作痕迹（用于恢复 / 关闭检查等，不重置缩放窗值） */
    PvViewer.prototype.renderPanes = function () {
        var self = this;
        this.panes.forEach(function (p, i) {
            p.el.classList.toggle('active', i === self.active);
            if (p.st.uid && self.study(p.st.uid)) {
                var ser = p.curSeries();
                var stt = ser ? self.seriesState(p.st.uid, ser.series_id) : null;
                if (stt && stt.view) { p.st.annos = []; p.applyView(stt.view); }
                else { p.applyDefaults(); p.fit(); }
                p.updateTitle(); p.updateScrollbar(); p.render();
                if (ser) p.prefetch(ser);
            } else {
                p.updateTitle(); p.updateScrollbar(); p.render();
            }
        });
    };
    PvViewer.prototype.renderSidebar = function () {
        var self = this, p = this.activePane();
        this.sidebar.renderStudies(this.ws.studies, { uid: p ? p.st.uid : '', si: p ? p.st.si : 0 }, {
            // 序列栏点击会 stopPropagation，需主动收起工具/预设下拉菜单，避免菜单残留
            onSeries: function (gi, si) { if (self.toolbar) self.toolbar.closeMenus(); self.setSeriesOnActive(gi, si, false); },
            onSeriesReset: function (gi, si) { if (self.toolbar) self.toolbar.closeMenus(); self.setSeriesOnActive(gi, si, true); },
            onToggle: function (gi) {
                if (self.toolbar) self.toolbar.closeMenus();
                // 竖屏底部横排序列栏不支持折叠：忽略切换，保持全部展开
                if (global.matchMedia && global.matchMedia('(orientation: portrait)').matches) return;
                var st = self.ws.studies[gi]; if (!st) return; st.collapsed = !st.collapsed; self.renderSidebar(); self.persist();
            },
            onClose: function (gi) { if (self.toolbar) self.toolbar.closeMenus(); self.removeStudy(gi); }
        });
    };
    PvViewer.prototype.removeStudy = function (gi) {
        if (this.guest) return;   // 访客不允许关闭检查
        if (gi < 0 || gi >= this.ws.studies.length) return;
        var removed = this.ws.studies.splice(gi, 1)[0];
        this.clearStudySeriesState(removed.uid);   // 关闭检查：丢弃其序列操作痕迹
        // 窗格中若引用了被移除的检查则清空该窗格
        this.panes.forEach(function (p) { if (p.st.uid === removed.uid) { p.st.uid = ''; p.st.si = 0; p.st.fi = 0; } });
        if (!this.ws.studies.length) { this.showEmpty(); return; }
        this.renderPanes();
        this.renderSidebar();
        this.syncToolbar();
        this.refreshControlState();
        this.persist();
    };
    PvViewer.prototype.showEmpty = function () {
        this.ws.studies = [];
        if (this.layout !== '1') this.setLayout('1', false);   // 影像清空后恢复单视图
        this.panes.forEach(function (p) { p.st.uid = ''; p.st.si = 0; p.st.fi = 0; p.st.annos = []; p.updateTitle(); p.updateScrollbar(); p.render(); });
        this.renderSidebar();
        this.syncToolbar();
        this.refreshControlState();
        this.clearState();
        var p = this.activePane(); if (p) p.setStatus('');
    };
    PvViewer.prototype.closeAll = function () {
        this.ws.studies = [];
        this.clearAllSeriesState();
        if (this.layout !== '1') this.setLayout('1', false);   // 影像清空后恢复单视图
        this.panes.forEach(function (p) { p.st.uid = ''; p.st.si = 0; p.st.fi = 0; p.st.annos = []; p.st.draft = null; p.updateTitle(); p.updateScrollbar(); p.render(); });
        this.clearState();
        this.renderSidebar();
        this.syncToolbar();
        this.refreshControlState();
        var p = this.activePane(); if (p) p.setStatus('');
    };

    /**
     * 在「激活窗格」载入序列（不改动布局：多视图下点序列即载入到当前激活分栏，便于对比）。
     * fresh=true 时重置该序列的操作痕迹（双击）。
     */
    PvViewer.prototype.setSeriesOnActive = function (gi, si, fresh) {
        var st = this.ws.studies[gi], ser = st && st.series[si];
        if (!ser) return;
        var ap = this.activePane(); if (!ap) return;
        if (fresh) {
            delete this.seriesMap[this._skey(st.uid, ser.series_id)];
            this._persistSeriesMap();
            ap.setSeries(st.uid, si, { fresh: true });
            return;
        }
        ap.setSeries(st.uid, si, {});
    };
})(window);
