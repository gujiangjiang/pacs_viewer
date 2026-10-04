/* assets/js/admin.js — 管理设置页交互（PvPages.admin）
 * 负责页签切换、基础设置表单、图标管理、日志清空、外部接口测试等；
 * 账号管理见 admin-users.js，存储情况见 admin-storage.js，模拟服务器见 mock.js。
 */
(function (global) {
    'use strict';

    var curTab = 'basic';

    function goTab(tab) {
        if (global.PvNav) global.PvNav.go('admin', { tab: tab });
        else location.href = (global.PV_BOOT.home || '/') + '?r=admin&tab=' + tab;
    }

    /** 保存后实时刷新顶栏站点名 / 医院名 / 图标（无需整页刷新） */
    function applyChrome(data, iconVersion) {
        data = data || {};
        var site = data.site_title;
        if (site !== undefined && site !== '') {
            var brand = document.querySelector('.pv-brand-name');
            if (brand) brand.textContent = site;
            document.title = document.title.replace(/·\s.*$/, '· ' + site);
        }
        if (data.hospital_name !== undefined) {
            var hosp = document.querySelector('.pv-footer-hosp');
            if (hosp) hosp.textContent = (data.hospital_name && data.hospital_name.trim() !== '') ? data.hospital_name : '默认医院';
        }
        var ver = iconVersion || data.icon_version || data.version;
        if (ver) {
            var preview = document.getElementById('pvIconPreview');
            if (preview) preview.src = preview.src.replace(/v=\d+/, 'v=' + ver) || preview.src;
            Array.prototype.forEach.call(document.querySelectorAll('link[rel="icon"],link[rel="apple-touch-icon"]'), function (l) {
                var href = l.getAttribute('href') || '';
                if (href.indexOf('r=icon') < 0) return;
                if (/v=\d+/.test(href)) href = href.replace(/v=\d+/, 'v=' + ver);
                else href += (href.indexOf('?') < 0 ? '?' : '&') + 'v=' + ver;
                l.setAttribute('href', href);
            });
        }
    }

    global.PvPages = global.PvPages || {};
    global.PvPages.admin = {
        init: function (data) {
            data = data || {};
            if (data.flash) PvUI.toast(data.flash, 'ok');

            var tabs = document.querySelectorAll('.pv-tab');
            var panes = document.querySelectorAll('.pv-tabpane');
            Array.prototype.forEach.call(tabs, function (t) {
                if (t.classList.contains('active')) curTab = t.getAttribute('data-tab');
                t.addEventListener('click', function () {
                    var key = t.getAttribute('data-tab');
                    curTab = key;
                    Array.prototype.forEach.call(tabs, function (x) { x.classList.toggle('active', x === t); });
                    Array.prototype.forEach.call(panes, function (p) { p.classList.toggle('active', p.getAttribute('data-pane') === key); });
                });
            });

            // 设置表单：保存后停留在当前页签（或表单声明的 data-ok-tab），并实时刷新顶栏/图标
            Array.prototype.forEach.call(document.querySelectorAll('form[data-ajax-form]'), function (form) {
                form.__pvOnOk = function (j) {
                    applyChrome(j && j.data);
                    // data-ok-noreload：保存后停留当前子页（如模拟服务器控制 / 数据来源）
                    if (form.hasAttribute('data-ok-noreload')) { if (global.PvMockRefresh) global.PvMockRefresh(); return; }
                    var hid = form.querySelector('[name=tab]');
                    var tab = form.getAttribute('data-ok-tab') || (hid ? hid.value : curTab);
                    goTab(tab);
                };
            });
            PvUI.bindAjaxForms(document);

            // 站点图标：恢复默认
            var iconReset = document.getElementById('pvIconReset');
            if (iconReset) {
                iconReset.addEventListener('click', function () {
                    PvModal.confirm({ title: '恢复默认图标', message: '确认恢复为内置代码绘制的默认图标？', okText: '恢复', danger: true }).then(function (ok) {
                        if (!ok) return;
                        PvUI.post(PvNav.route('admin/icon-reset'), {}).then(function (j) {
                            if (j && j.code === 200) { PvUI.toast(j.msg || '已恢复默认图标', 'ok'); applyChrome({}, j.data && j.data.version); goTab('basic'); }
                            else PvUI.toast((j && j.msg) || '操作失败', 'err');
                        });
                    });
                });
            }

            // 操作日志：保留上限设置（条数 / 天数，均可留空不限制）
            var logSettings = document.getElementById('pvLogSettings');
            if (logSettings) {
                logSettings.addEventListener('click', function () {
                    var body = ''
                        + '<div class="pv-field"><span>上限条数（留空不限制）</span>'
                        + '<input id="pvLogMaxCount" type="number" min="0" step="1" placeholder="如 500"></div>'
                        + '<div class="pv-field"><span>上限天数（留空不限制）</span>'
                        + '<input id="pvLogMaxDays" type="number" min="0" step="1" placeholder="如 3"></div>'
                        + '<p class="pv-hint">任一上限先达到即删除最早的日志；两项均留空表示不限制。</p>';
                    var m = PvModal.open({
                        title: '日志保留设置',
                        body: body,
                        actions: [
                            { label: '取消', cls: 'pv-btn-ghost' },
                            {
                                label: '保存', cls: 'pv-btn-primary', onClick: function () {
                                    var count = ((document.getElementById('pvLogMaxCount') || {}).value || '').trim();
                                    var days = ((document.getElementById('pvLogMaxDays') || {}).value || '').trim();
                                    PvUI.post(PvNav.route('admin/log-settings'), { log_max_count: count, log_max_days: days }).then(function (j) {
                                        if (j && j.code === 200) { PvUI.toast(j.msg || '已保存', 'ok'); m.close(); goTab('logs'); }
                                        else PvUI.toast((j && j.msg) || '保存失败', 'err');
                                    }).catch(function () { PvUI.toast('网络请求失败', 'err'); });
                                    return false;   // 保持打开，由回调决定关闭
                                }
                            }
                        ],
                        onOpen: function () {
                            var c = document.getElementById('pvLogMaxCount');
                            var d = document.getElementById('pvLogMaxDays');
                            if (c) c.value = (data.logMaxCount !== undefined && data.logMaxCount !== '') ? data.logMaxCount : '';
                            if (d) d.value = (data.logMaxDays !== undefined && data.logMaxDays !== '') ? data.logMaxDays : '';
                        }
                    });
                });
            }

            // 操作日志：清空
            var logClear = document.getElementById('pvLogClear');
            if (logClear) {
                logClear.addEventListener('click', function () {
                    PvModal.confirm({ title: '清空操作日志', message: '确认清空全部操作日志？', okText: '清空', danger: true }).then(function (ok) {
                        if (!ok) return;
                        PvUI.post(PvNav.route('admin/log-clear'), {}).then(function (j) {
                            if (j && j.code === 200) { PvUI.toast(j.msg || '已清空', 'ok'); goTab('logs'); }
                            else PvUI.toast((j && j.msg) || '操作失败', 'err');
                        });
                    });
                });
            }

            // 操作日志：滚动加载更多 + 刷新 + 实时增量更新
            (function () {
                var scroll = document.getElementById('pvLogScroll');
                var body = document.getElementById('pvLogBody');
                if (!scroll || !body) return;
                var esc = PvUI.esc;   // 复用通用转义助手
                function rowHtml(l) {
                    var rc = (l.action === 'search') ? String(l.result_count == null ? 0 : l.result_count) : '—';
                    return '<tr data-row="1" data-id="' + (parseInt(l.id, 10) || 0) + '"><td class="pv-dim">' + esc(l.created_at) + '</td>'
                        + '<td>' + esc(l.username) + '</td>'
                        + '<td><span class="pv-badge op-' + esc(l.action) + '">' + esc(l.action_name) + '</span></td>'
                        + '<td><span class="pv-log-detail" title="' + esc(l.detail) + '">' + esc(l.detail) + '</span></td>'
                        + '<td>' + esc(l.keyword) + '</td>'
                        + '<td>' + esc(rc) + '</td>'
                        + '<td class="pv-dim">' + esc(l.ip) + '</td></tr>';
                }
                function logUrl(params) { return PvUI.route('admin/logs', params); }
                function currentTotal() { return parseInt(scroll.getAttribute('data-total'), 10) || 0; }
                function setTotal(n) {
                    scroll.setAttribute('data-total', n);
                    var el = document.getElementById('pvLogCount');
                    if (el) el.textContent = n;
                }
                function maxRowId() {
                    var rows = body.querySelectorAll('tr[data-row][data-id]');
                    var m = 0;
                    Array.prototype.forEach.call(rows, function (r) { var v = parseInt(r.getAttribute('data-id'), 10) || 0; if (v > m) m = v; });
                    return m;
                }
                function dropEmpty() { var e = body.querySelector('tr td[colspan]'); if (e && e.parentNode) e.parentNode.parentNode.removeChild(e.parentNode); }

                // 滚动到底自动加载更多（复用通用 PvInfiniteScroll）
                var loader = null;
                function ensureLoader(offset, hasMore) {
                    if (!global.PvInfiniteScroll) return;
                    if (loader) { loader.destroy(); loader = null; }
                    loader = global.PvInfiniteScroll.paged({
                        container: scroll, list: scroll,
                        offset: offset, hasMore: hasMore, pageSize: 30,
                        sentinelClass: 'pv-more', moreText: '上拉加载更多…', endText: '',
                        request: function (off, limit) { return PvUI.get(logUrl({ offset: off, limit: limit })); },
                        append: function (list) { list.forEach(function (l) { body.insertAdjacentHTML('beforeend', rowHtml(l)); }); },
                        onError: function (msg) { PvUI.toast(msg, 'err'); }
                    });
                }
                var rendered = body.querySelectorAll('tr[data-row]').length;
                if (currentTotal() > rendered) ensureLoader(rendered, true);

                // 刷新：重新拉取第一页并整体替换表格
                function reloadFirstPage(showLoading) {
                    if (showLoading && body) body.innerHTML = '<tr data-row="1"><td colspan="7" class="pv-dim" style="text-align:center">加载中…</td></tr>';
                    return PvUI.get(logUrl({ offset: 0, limit: 30 })).then(function (j) {
                        if (!j || j.code !== 200) { PvUI.toast((j && j.msg) || '加载失败', 'err'); return; }
                        var list = (j.data && j.data.list) || [];
                        var total = j.data && typeof j.data.total === 'number' ? j.data.total : list.length;
                        body.innerHTML = list.length ? list.map(rowHtml).join('') : '<tr data-row="1"><td colspan="7" class="pv-dim" style="text-align:center">暂无记录</td></tr>';
                        setTotal(total);
                        ensureLoader(list.length, list.length < total);
                    }).catch(function () { PvUI.toast('网络请求失败', 'err'); });
                }

                // 实时：仅拉取比当前最新 id 更新的日志，前插且不打断滚动加载
                function poll() {
                    var since = maxRowId();
                    return PvUI.get(logUrl({ since_id: since, limit: 100 })).then(function (j) {
                        if (!j || j.code !== 200) return;
                        var list = (j.data && j.data.list) || [];
                        if (!list.length) { if (j.data && typeof j.data.total === 'number') setTotal(j.data.total); return; }
                        dropEmpty();
                        body.insertAdjacentHTML('afterbegin', list.map(rowHtml).join(''));
                        if (loader) loader.offset += list.length;
                        if (j.data && typeof j.data.total === 'number') setTotal(j.data.total);
                    }).catch(function () {});
                }

                var liveBtn = document.getElementById('pvLogLive');
                var live = PvUI.liveToggle(liveBtn, poll, 5000);   // 复用通用「实时」开关
                var refreshBtn = document.getElementById('pvLogRefresh');
                if (refreshBtn) refreshBtn.addEventListener('click', function () { reloadFirstPage(true); });
                // 切换到其他页签时停止实时轮询，避免无谓请求
                Array.prototype.forEach.call(document.querySelectorAll('.pv-tab'), function (t) {
                    t.addEventListener('click', function () { if (t.getAttribute('data-tab') !== 'logs') live.stop(); });
                });
                global.__pvLogStopLive = function () { live.stop(); };   // 供页面销毁时清理
            })();

            // 外部接口：DICOM / PACS 连通性测试（当前输入）
            PvUI.bindConnTest({
                btn: 'pvTestPacs', out: 'pvTestResult', route: 'api/pacs/test',
                fields: ['pacs_endpoint', 'pacs_api_key', 'pacs_timeout'],
                okText: function (d) { return '接口可用 · ' + (d.name || '') + (d.mode ? ' · 模式 ' + d.mode : '') + (d.institution ? ' · 机构 ' + d.institution : ''); }
            });

            // 外部接口：FHIR R4 连通性测试（当前输入）
            PvUI.bindConnTest({
                btn: 'pvFhirTestMain', out: 'pvFhirResultMain', route: 'api/fhir/test',
                fields: ['fhir_endpoint', 'fhir_api_key', 'fhir_timeout'],
                okText: function (d) { return '连接成功 · ' + ((d.institution || d.name) || 'FHIR'); }
            });

            // 外部接口：左侧分栏切换（DICOM/PACS ↔ FHIR R4）
            PvUI.bindSplit({ navSelector: '.pv-split-item[data-ext]', paneSelector: '[data-ext-pane]', attr: 'ext' });

            // 账号管理面板
            if (global.PvAdminUsers) global.PvAdminUsers.init(goTab);
            // 存储情况面板
            if (global.PvAdminStorage) global.PvAdminStorage.init(curTab, data.cache);
            // 模拟服务器面板（复用 mock.js 的面板逻辑）
            if (global.PvPages && global.PvPages.mock && typeof global.PvPages.mock.init === 'function') {
                try { global.PvPages.mock.init({}); } catch (e) { if (global.console) console.error(e); }
            }
            // 自定义表单控件（下拉 / 日期 / 文件）
            if (global.PvControls) global.PvControls.init(document);
        },
        destroy: function () {
            if (global.__pvLogStopLive) { try { global.__pvLogStopLive(); } catch (e) {} global.__pvLogStopLive = null; }
            if (global.__pvStorageStop) { try { global.__pvStorageStop(); } catch (e) {} global.__pvStorageStop = null; }
            if (global.PvPages && global.PvPages.mock && typeof global.PvPages.mock.destroy === 'function') {
                try { global.PvPages.mock.destroy(); } catch (e) {}
            }
        }
    };
})(window);
