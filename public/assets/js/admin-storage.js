/* assets/js/admin-storage.js — 管理页「存储情况」面板（PvAdminStorage）
 * 由 admin.js 装配；查询运行时存储占用并提供上传 / 缓存一键清空。
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

    function route(r) {
        return global.PvNav ? global.PvNav.route(r) : ((global.PV_BOOT.home || '/') + '?r=' + r);
    }

    global.PvAdminStorage = {
        /** @param {string} curTab 当前激活页签（storage 时自动加载） */
        init: function (curTab) {
            var box = document.getElementById('pvStorageBox');
            if (!box) return;

            function render(d) {
                d = d || {};
                var rows = [
                    ['数据库', (d.db || {}).bytes, (d.db || {}).path || ''],
                    ['上传文件', (d.uploads || {}).bytes, ((d.uploads || {}).files || 0) + ' 个文件'],
                    ['内存缓存', (d.cache || {}).bytes, '后端：' + ((d.cache || {}).backend || '—') + ' · ' + ((d.cache || {}).count || 0) + ' 条目' + ((d.cache || {}).max_bytes ? ' · 上限 ' + human(d.cache.max_bytes) : '')],
                    ['会话文件', (d.session || {}).bytes, ((d.session || {}).files || 0) + ' 个文件']
                ];
                if (d.cache && d.cache.disk_bytes > 0) rows.push(['磁盘缓存', d.cache.disk_bytes, (d.cache.disk_files || 0) + ' 个影像文件（跨进程持久复用）']);
                var html = '<table class="pv-table pv-storage-table"><thead><tr><th>项目</th><th>占用</th><th>说明</th></tr></thead><tbody>';
                rows.forEach(function (r) {
                    html += '<tr><td>' + PvUI.esc(r[0]) + '</td><td class="pv-storage-size">' + human(r[1]) + '</td><td class="pv-dim">' + PvUI.esc(r[2] || '') + '</td></tr>';
                });
                box.innerHTML = html + '</tbody></table>';
            }
            function load(showLoading) {
                if (showLoading) box.innerHTML = '<div class="pv-dim">加载中…</div>';
                PvUI.get(route('api/storage'))
                    .then(function (j) { if (j && j.code === 200) render(j.data); else box.innerHTML = '<div class="pv-dim">加载失败</div>'; })
                    .catch(function () { box.innerHTML = '<div class="pv-dim">网络请求失败</div>'; });
            }

            var refresh = document.getElementById('pvStorageRefresh');
            if (refresh) refresh.addEventListener('click', function () { load(true); });

            var cu = document.getElementById('pvStorageClearUploads');
            if (cu) cu.addEventListener('click', function () {
                PvModal.confirm({ title: '清空上传文件', message: '确认删除全部上传文件及其记录？此操作不可恢复。', okText: '清空', danger: true }).then(function (ok) {
                    if (!ok) return;
                    PvUI.postThen('api/storage/clear-uploads', {}, { okMsg: '操作结束', errMsg: '操作结束', onOk: function () { load(false); }, onError: function () { load(false); } });
                });
            });
            var cc = document.getElementById('pvStorageClearCache');
            if (cc) cc.addEventListener('click', function () {
                PvModal.confirm({ title: '清空缓存区', message: '确认清空影像内存缓存？清空后再次打开影像会重新生成。', okText: '清空', danger: true }).then(function (ok) {
                    if (!ok) return;
                    PvUI.postThen('api/storage/clear-cache', {}, { okMsg: '操作结束', errMsg: '操作结束', onOk: function () { load(false); }, onError: function () { load(false); } });
                });
            });

            Array.prototype.forEach.call(document.querySelectorAll('.pv-tab'), function (t) {
                if (t.getAttribute('data-tab') === 'storage') t.addEventListener('click', function () { load(false); });
            });
            if (curTab === 'storage') load(true);
        }
    };
})(window);
