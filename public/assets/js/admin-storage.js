/* assets/js/admin-storage.js — 管理页「存储情况」面板（PvAdminStorage）
 * 由 admin.js 装配；查询运行时存储占用，提供刷新 / 实时、合并清空与缓存设置。
 */
(function (global) {
    'use strict';

    function human(b) {
        b = +b || 0;
        if (b < 1024) return b + ' B';
        if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
        if (b < 1073741824) return (b / 1048576).toFixed(2) + ' MB';
        return (b / 1073741824).toFixed(2) + ' GB';
    }

    function route(r) { return PvUI.route(r); }   // 复用通用路由助手

    global.PvAdminStorage = {
        /**
         * @param {string} curTab 当前激活页签（storage 时自动加载）
         * @param {object} [cache] 缓存设置兜底值（打开设置模态框时优先取服务端最新值）
         */
        init: function (curTab, cache) {
            var box = document.getElementById('pvStorageBox');
            if (!box) return;
            cache = cache || {};
            var unbindDd = null;

            function render(d) {
                d = d || {};
                var c = d.cache || {};
                var limitTxt = function (bytes) { return bytes ? ' · 上限 ' + human(bytes) : ' · 不限容量'; };
                var dayTxt = (c.ttl_seconds ? ' · 保留 ' + Math.round(c.ttl_seconds / 86400) + ' 天' : ' · 不限日期');
                var rows = [
                    ['数据库', (d.db || {}).bytes, (d.db || {}).path || ''],
                    ['上传文件', (d.uploads || {}).bytes, ((d.uploads || {}).files || 0) + ' 个文件'],
                    ['内存缓存', c.bytes, (c.enabled ? '后端：' + (c.backend || '—') + ' · ' + (c.count || 0) + ' 条目' : '已关闭 / 不可用（' + (c.backend || '—') + '）') + limitTxt(c.max_bytes) + dayTxt],
                    ['磁盘缓存', c.disk_bytes || 0, (c.disk_enabled ? (c.disk_files || 0) + ' 个影像文件（跨进程持久复用）' : '已关闭') + limitTxt(c.disk_max_bytes || 0) + dayTxt],
                    ['会话文件', (d.session || {}).bytes, ((d.session || {}).files || 0) + ' 个文件']
                ];
                var html = '<table class="pv-table pv-storage-table"><thead><tr><th>项目</th><th>占用</th><th>说明</th></tr></thead><tbody>';
                rows.forEach(function (r) {
                    html += '<tr><td>' + PvUI.esc(r[0]) + '</td><td class="pv-storage-size">' + human(r[1]) + '</td><td class="pv-dim">' + PvUI.esc(r[2] || '') + '</td></tr>';
                });
                box.innerHTML = html + '</tbody></table>';
            }
            function load(showLoading) {
                if (showLoading) box.innerHTML = '<div class="pv-dim">加载中…</div>';
                return PvUI.get(route('api/storage'))
                    .then(function (j) { if (j && j.code === 200) render(j.data); else box.innerHTML = '<div class="pv-dim">加载失败</div>'; })
                    .catch(function () { box.innerHTML = '<div class="pv-dim">网络请求失败</div>'; });
            }

            var refresh = document.getElementById('pvStorageRefresh');
            if (refresh) refresh.addEventListener('click', function () { load(true); });

            // 实时：定时刷新存储占用（复用通用「实时」开关）
            var liveBtn = document.getElementById('pvStorageLive');
            var live = PvUI.liveToggle(liveBtn, function () { load(false); }, 5000);

            // 合并「清空」下拉：清空上传文件 / 清空缓存区
            var dd = document.getElementById('pvStorageClear');
            function confirmClear(title, msg, r) {
                PvModal.confirm({ title: title, message: msg, okText: '清空', danger: true }).then(function (ok) {
                    if (!ok) return;
                    PvUI.postThen(r, {}, { okMsg: '操作结束', errMsg: '操作结束', onOk: function () { load(false); }, onError: function () { load(false); } });
                });
            }
            if (dd) {
                var ddBtn = dd.querySelector('button');
                if (ddBtn) ddBtn.addEventListener('click', function (e) { e.stopPropagation(); dd.classList.toggle('open'); });
                Array.prototype.forEach.call(dd.querySelectorAll('[data-clear]'), function (b) {
                    b.addEventListener('click', function () {
                        var kind = b.getAttribute('data-clear');
                        dd.classList.remove('open');
                        if (kind === 'uploads') confirmClear('清空上传文件', '确认删除全部上传文件及其记录？此操作不可恢复。', 'api/storage/clear-uploads');
                        else confirmClear('清空缓存区', '确认清空影像内存缓存？清空后再次打开影像会重新生成。', 'api/storage/clear-cache');
                    });
                });
                unbindDd = PvPopover.onOutside(
                    function (t) { return dd.contains(t); },
                    function () { dd.classList.remove('open'); },
                    { type: 'click', capture: false }
                );
            }

            // 缓存设置：每次打开前拉取服务端最新值回填，避免显示过期内容
            var cs = document.getElementById('pvStorageSettings');
            function openCacheModal(v) {
                v = v || {};
                var val = function (x) { return (x === undefined || x === null) ? '' : x; };
                var body = ''
                    + '<div class="pv-field"><label class="pv-check"><input type="checkbox" id="pvCacheApcu"> 启用 APCu 内存缓存</label></div>'
                    + '<div class="pv-field"><span>内存缓存上限（MB，留空不限制）</span>'
                    + '<input id="pvCacheMaxMb" type="number" min="0" step="1" placeholder="如 64"></div>'
                    + '<div class="pv-field"><label class="pv-check"><input type="checkbox" id="pvCacheDisk"> 启用硬盘缓存</label></div>'
                    + '<div class="pv-field"><span>硬盘缓存上限（MB，留空不限制）</span>'
                    + '<input id="pvCacheDiskMaxMb" type="number" min="0" step="1" placeholder="如 512"></div>'
                    + '<div class="pv-field"><span>缓存日期上限（天，留空不限制；内存与硬盘共用）</span>'
                    + '<input id="pvCacheMaxDays" type="number" min="0" step="1" placeholder="如 3"></div>'
                    + '<p class="pv-hint">超过上限时自动删除最早生成的缓存；容量与日期谁先达到即执行谁，留空表示该维度不限制。</p>';
                var m = PvModal.open({
                    title: '缓存设置',
                    body: body,
                    actions: [
                        { label: '取消', cls: 'pv-btn-ghost' },
                        {
                            label: '保存', cls: 'pv-btn-primary', onClick: function () {
                                var data = {
                                    cache_apcu_enabled: document.getElementById('pvCacheApcu').checked ? '1' : '0',
                                    cache_disk_enabled: document.getElementById('pvCacheDisk').checked ? '1' : '0',
                                    cache_max_mb: (document.getElementById('pvCacheMaxMb').value || '').trim(),
                                    cache_disk_max_mb: (document.getElementById('pvCacheDiskMaxMb').value || '').trim(),
                                    cache_max_days: (document.getElementById('pvCacheMaxDays').value || '').trim()
                                };
                                PvUI.post(route('api/storage/settings'), data).then(function (j) {
                                    if (j && j.code === 200) { PvUI.toast(j.msg || '已保存', 'ok'); m.close(); load(false); }
                                    else PvUI.toast((j && j.msg) || '保存失败', 'err');
                                }).catch(function () { PvUI.toast('网络请求失败', 'err'); });
                                return false;   // 保持打开，由回调决定关闭
                            }
                        }
                    ],
                    onOpen: function () {
                        var apcu = document.getElementById('pvCacheApcu');
                        var disk = document.getElementById('pvCacheDisk');
                        var maxMb = document.getElementById('pvCacheMaxMb');
                        var diskMb = document.getElementById('pvCacheDiskMaxMb');
                        var days = document.getElementById('pvCacheMaxDays');
                        if (apcu) apcu.checked = v.apcuEnabled !== false;
                        if (disk) disk.checked = v.diskEnabled !== false;
                        if (maxMb) maxMb.value = val(v.maxMb);
                        if (diskMb) diskMb.value = val(v.diskMaxMb);
                        if (days) days.value = val(v.maxDays);
                    }
                });
            }
            if (cs) cs.addEventListener('click', function () {
                PvUI.get(route('api/storage/settings')).then(function (j) {
                    openCacheModal((j && j.code === 200 && j.data) ? j.data : cache);
                }).catch(function () { openCacheModal(cache); });
            });

            Array.prototype.forEach.call(document.querySelectorAll('.pv-tab'), function (t) {
                if (t.getAttribute('data-tab') === 'storage') t.addEventListener('click', function () { load(false); });
            });
            if (curTab === 'storage') load(true);

            // 供页面销毁时清理定时器与文档监听
            global.__pvStorageStop = function () {
                live.stop();
                if (unbindDd) { unbindDd(); unbindDd = null; }
            };
        }
    };
})(window);
