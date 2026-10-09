/* assets/js/admin-logs.js — 日志查询（操作 / 协议 / 系统 / 模拟服务器）
 * 通用「日志面板」初始化：每通道独立 实时 / 刷新 / 设置 / 清空 / 框内滚动分页。
 * 由 admin.js 在管理页初始化时调用 PvPages.logs.init()。
 */
(function (global) {
    'use strict';

    var CH_CN = { operation: '操作日志', protocol: '协议日志', system: '系统日志', mock: '模拟服务器日志' };

    function esc(s) { return PvUI.esc(s == null ? '' : s); }
    function colSpan(ch) { return ch === 'operation' ? 7 : (ch === 'system' ? 2 : 5); }

    function levelBadge(level) {
        if (level === 'error') return '<span class="pv-badge op-error">错误</span>';
        if (level === 'warn') return '<span class="pv-badge op-warn">警告</span>';
        return '<span class="pv-badge">信息</span>';
    }

    /** 单行渲染（按通道列结构不同） */
    function rowHtml(ch, l) {
        if (ch === 'operation') {
            var rc = (l.action === 'search') ? String(l.result_count == null ? 0 : l.result_count) : '—';
            return '<tr data-row="1" data-id="' + (parseInt(l.id, 10) || 0) + '">'
                + '<td class="pv-dim">' + esc(l.created_at) + '</td>'
                + '<td>' + esc(l.username) + '</td>'
                + '<td><span class="pv-badge op-' + esc(l.action) + '">' + esc(l.action_name) + '</span></td>'
                + '<td><span class="pv-log-detail" title="' + esc(l.detail) + '">' + esc(l.detail) + '</span></td>'
                + '<td>' + esc(l.keyword) + '</td><td>' + esc(rc) + '</td>'
                + '<td class="pv-dim">' + esc(l.ip) + '</td></tr>';
        }
        if (ch === 'system') {
            return '<tr data-row="1"><td class="pv-dim">' + esc(l.created_at || '—') + '</td>'
                + '<td><span class="pv-log-detail" title="' + esc(l.text) + '">' + esc(l.text) + '</span></td></tr>';
        }
        // protocol / mock
        var meta = l.meta ? (' title="' + esc(l.meta) + '"') : '';
        return '<tr data-row="1" data-id="' + (parseInt(l.id, 10) || 0) + '">'
            + '<td class="pv-dim">' + esc(l.created_at) + '</td>'
            + '<td>' + levelBadge(l.level) + '</td>'
            + '<td>' + esc(l.action) + '</td>'
            + '<td><span class="pv-log-detail"' + meta + '>' + esc(l.detail) + '</span></td>'
            + '<td class="pv-dim">' + esc(l.ip) + '</td></tr>';
    }

    function initPane(root) {
        if (!root || root.__pvLogBound) return;
        root.__pvLogBound = true;
        var channel = root.getAttribute('data-channel') || 'operation';
        var body = root.querySelector('.pv-log-body');
        var scroll = root.querySelector('.pv-log-scroll');
        var totalEl = root.querySelector('.pv-log-total');
        if (!body || !scroll) return;

        var loader = null;
        var loaded = false;
        var lastTotal = parseInt(scroll.getAttribute('data-total'), 10) || 0;

        function cs() { return colSpan(channel); }
        function setTotal(n) { if (typeof n === 'number') { lastTotal = n; scroll.setAttribute('data-total', n); if (totalEl) totalEl.textContent = n; } }
        function emptyRow(text) { return '<tr data-row="1"><td colspan="' + cs() + '" class="pv-dim" style="text-align:center">' + esc(text) + '</td></tr>'; }
        function dropEmpty() { var t = body.querySelector('td[colspan]'); if (t && t.parentNode && t.parentNode.parentNode) t.parentNode.parentNode.removeChild(t.parentNode); }
        function maxRowId() {
            var rows = body.querySelectorAll('tr[data-row][data-id]'), m = 0;
            Array.prototype.forEach.call(rows, function (r) { var v = parseInt(r.getAttribute('data-id'), 10) || 0; if (v > m) m = v; });
            return m;
        }
        function url(params) { params = params || {}; params.channel = channel; return PvUI.route('admin/logs', params); }

        function ensureLoader(offset, hasMore) {
            if (!global.PvInfiniteScroll) return;
            if (loader) { loader.destroy(); loader = null; }
            loader = global.PvInfiniteScroll.paged({
                container: scroll, list: scroll, offset: offset, hasMore: hasMore, pageSize: 30,
                sentinelClass: 'pv-more', moreText: '加载更多…', endText: '',
                request: function (off, limit) { return PvUI.get(url({ offset: off, limit: limit })); },
                append: function (list) { list.forEach(function (l) { body.insertAdjacentHTML('beforeend', rowHtml(channel, l)); }); },
                onState: function (st) { if (typeof st.total === 'number') setTotal(st.total); },
                onError: function (msg) { PvUI.toast(msg, 'err'); }
            });
        }

        function reload(showLoading) {
            loaded = true;
            if (showLoading) body.innerHTML = emptyRow('加载中…');
            return PvUI.get(url({ offset: 0, limit: 30 })).then(function (j) {
                if (!j || j.code !== 200) { PvUI.toast((j && j.msg) || '加载失败', 'err'); return; }
                var list = (j.data && j.data.list) || [];
                var total = (j.data && typeof j.data.total === 'number') ? j.data.total : list.length;
                body.innerHTML = list.length ? list.map(function (l) { return rowHtml(channel, l); }).join('') : emptyRow('暂无记录');
                setTotal(total);
                ensureLoader(list.length, list.length < total);
            }).catch(function () { PvUI.toast('网络请求失败', 'err'); });
        }

        function poll() {
            var since = maxRowId();
            return PvUI.get(url({ since_id: since, limit: 100 })).then(function (j) {
                if (!j || j.code !== 200) return;
                var list = (j.data && j.data.list) || [];
                if (j.data && typeof j.data.total === 'number') setTotal(j.data.total);
                if (!list.length) return;
                dropEmpty();
                body.insertAdjacentHTML('afterbegin', list.map(function (l) { return rowHtml(channel, l); }).join(''));
                if (loader) loader.offset += list.length;
            }).catch(function () {});
        }

        var liveBtn = root.querySelector('.pv-log-live');
        var live = PvUI.liveToggle(liveBtn, function () {
            if (channel === 'system') reload(false); else poll();
        }, 5000);

        var refreshBtn = root.querySelector('.pv-log-refresh');
        if (refreshBtn) refreshBtn.addEventListener('click', function () { reload(true); });

        var clearBtn = root.querySelector('.pv-log-clear');
        if (clearBtn) clearBtn.addEventListener('click', function () {
            PvModal.confirm({ title: '清空' + CH_CN[channel], message: '确认清空全部' + CH_CN[channel] + '？', okText: '清空', danger: true }).then(function (ok) {
                if (!ok) return;
                PvUI.post(PvNav.route('admin/log-clear'), { channel: channel }).then(function (j) {
                    if (j && j.code === 200) { PvUI.toast(j.msg || '已清空', 'ok'); reload(true); }
                    else PvUI.toast((j && j.msg) || '操作失败', 'err');
                });
            });
        });

        var setBtn = root.querySelector('.pv-log-settings');
        if (setBtn) setBtn.addEventListener('click', function () { openSettings(root, channel, reload); });

        // 对外暴露（惰性加载 / 实时停止 / 已加载标记）
        root.__pvLogReload = reload;
        root.__pvLogStop = function () { live.stop(); };
        root.__pvLogLoaded = function () { return loaded; };

        // 操作日志首屏已服务端渲染：按总数决定是否挂滚动加载
        if (channel === 'operation') {
            loaded = true;
            var rendered = body.querySelectorAll('tr[data-row]').length;
            if (lastTotal > rendered) ensureLoader(rendered, true);
        }
    }

    function openSettings(root, channel, reloadFn) {
        PvUI.get(PvUI.route('admin/log-settings', { channel: channel })).then(function (j) {
            var d = (j && j.data) || {};
            var body = ''
                + '<div class="pv-field"><span>上限条数（留空不限制）</span>'
                + '<input id="pvLogMaxCount" type="number" min="0" step="1" value="' + esc(d.count || '') + '"></div>'
                + '<div class="pv-field"><span>上限天数（留空不限制）</span>'
                + '<input id="pvLogMaxDays" type="number" min="0" step="1" value="' + esc(d.days || '') + '"></div>'
                + '<p class="pv-hint">任一上限先达到即清理最早的日志；两项均留空表示不限制。</p>';
            PvUI.modalSubmit({
                title: CH_CN[channel] + '保留设置',
                body: body,
                okText: '保存',
                okMsg: '已保存',
                errMsg: '保存失败',
                route: 'admin/log-settings',
                getData: function () {
                    return {
                        channel: channel,
                        log_max_count: ((document.getElementById('pvLogMaxCount') || {}).value || '').trim(),
                        log_max_days: ((document.getElementById('pvLogMaxDays') || {}).value || '').trim()
                    };
                },
                onOk: function () { if (typeof reloadFn === 'function') reloadFn(true); }
            });
        });
    }

    global.PvPages = global.PvPages || {};
    global.PvPages.logs = {
        /** 初始化管理页所有日志面板（日志查询左栏 + 模拟服务器日志） */
        init: function () {
            var lgPanes = document.querySelectorAll('.pv-logpane[data-lg-pane]');
            Array.prototype.forEach.call(lgPanes, initPane);
            PvUI.bindSplit({
                navSelector: '.pv-split-item[data-lg]', paneSelector: '[data-lg-pane]', attr: 'lg', initial: 'operation',
                onChange: function (val) {
                    var pane = document.querySelector('.pv-logpane[data-lg-pane="' + val + '"]');
                    if (pane && !pane.__pvLogLoaded()) setTimeout(function () { pane.__pvLogReload(true); }, 0);
                    Array.prototype.forEach.call(lgPanes, function (p) { if (p !== pane && p.__pvLogStop) p.__pvLogStop(); });
                }
            });

            // 模拟服务器日志面板（在「模拟服务器」子 Tab 内）
            var mockPanes = document.querySelectorAll('.pv-logpane[data-mp-pane]');
            Array.prototype.forEach.call(mockPanes, initPane);
            Array.prototype.forEach.call(document.querySelectorAll('.pv-split-item[data-mp]'), function (b) {
                b.addEventListener('click', function () {
                    var v = b.getAttribute('data-mp');
                    var pane = document.querySelector('.pv-logpane[data-mp-pane="logs"]');
                    if (!pane) return;
                    if (v === 'logs') { if (!pane.__pvLogLoaded || !pane.__pvLogLoaded()) setTimeout(function () { pane.__pvLogReload(true); }, 0); }
                    else if (pane.__pvLogStop) pane.__pvLogStop();
                });
            });
        },
        stop: function () {
            Array.prototype.forEach.call(document.querySelectorAll('.pv-logpane'), function (p) { if (p.__pvLogStop) p.__pvLogStop(); });
        }
    };
})(window);
