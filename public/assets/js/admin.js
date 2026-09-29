/* assets/js/admin.js — 管理设置页交互（PvPages.admin） */
(function (global) {
    'use strict';

    var curTab = 'basic';

    function goTab(tab) {
        if (global.PvNav) global.PvNav.go('admin', { tab: tab });
        else location.href = (global.PV_BOOT.home || '/') + '?r=admin&tab=' + tab;
    }

    function userModal(opts) {
        var isEdit = opts.mode === 'edit';
        var body = '' +
            '<label class="pv-field"><span>用户名</span>' +
            '<input type="text" id="puUsername" value="' + PvUI.esc(opts.username || '') + '"' + (isEdit ? ' readonly' : ' placeholder="以字母开头，2-32 位"') + '></label>' +
            '<label class="pv-field"><span>显示名称</span>' +
            '<input type="text" id="puDisplay" value="' + PvUI.esc(opts.display || '') + '" placeholder="可选"></label>' +
            (isEdit ? '' : '<label class="pv-field"><span>初始密码</span><input type="password" id="puPassword" placeholder="至少 6 位"></label>') +
            '<label class="pv-field"><span>角色</span><select id="puRole"' + (opts.owner ? ' disabled' : '') + '>' +
            '<option value="user"' + (opts.role === 'user' ? ' selected' : '') + '>普通用户</option>' +
            '<option value="admin"' + (opts.role === 'admin' ? ' selected' : '') + '>管理员</option>' +
            '</select></label>' +
            (opts.owner ? '<p class="pv-hint">安装管理员必须保持管理员角色。</p>' : '');

        PvModal.open({
            title: isEdit ? ('编辑账号 · ' + PvUI.esc(opts.username)) : '新增账号',
            body: body,
            actions: [
                { label: '取消', cls: 'pv-btn-ghost' },
                {
                    label: isEdit ? '保存' : '创建', cls: 'pv-btn-primary', close: false,
                    onClick: function (btn) {
                        var uname = (document.getElementById('puUsername') || {}).value || '';
                        var display = (document.getElementById('puDisplay') || {}).value || '';
                        var role = (document.getElementById('puRole') || {}).value || 'user';
                        var data;
                        if (isEdit) {
                            data = { id: opts.id, display_name: display, role: role };
                            postThen('admin/user-update', data, btn, '账号资料已更新');
                        } else {
                            var pw = (document.getElementById('puPassword') || {}).value || '';
                            if (!uname.trim()) { PvUI.toast('请输入用户名', 'err'); return false; }
                            if (pw.length < 6) { PvUI.toast('密码至少 6 位', 'err'); return false; }
                            data = { username: uname.trim(), display_name: display, password: pw, role: role };
                            postThen('admin/user-create', data, btn, '账号已创建');
                        }
                    }
                }
            ]
        });
    }

    function passwordModal(u) {
        PvModal.open({
            title: '重置密码 · ' + PvUI.esc(u.username),
            body: '<label class="pv-field"><span>新密码</span><input type="password" id="ppPassword" placeholder="至少 6 位"></label>' +
                  '<label class="pv-field"><span>确认新密码</span><input type="password" id="ppConfirm" placeholder="再次输入"></label>',
            actions: [
                { label: '取消', cls: 'pv-btn-ghost' },
                {
                    label: '重置', cls: 'pv-btn-primary', close: false,
                    onClick: function (btn) {
                        var p1 = (document.getElementById('ppPassword') || {}).value || '';
                        var p2 = (document.getElementById('ppConfirm') || {}).value || '';
                        if (p1.length < 6) { PvUI.toast('密码至少 6 位', 'err'); return false; }
                        if (p1 !== p2) { PvUI.toast('两次输入的密码不一致', 'err'); return false; }
                        postThen('admin/user-password', { id: u.id, password: p1 }, btn, '密码已重置');
                    }
                }
            ]
        });
    }

    function postThen(route, data, btn, okMsg) {
        if (btn) btn.disabled = true;
        PvUI.post(PvNav.route(route), data).then(function (j) {
            if (btn) btn.disabled = false;
            if (j && j.code === 200) {
                PvModal.close();
                PvUI.toast(j.msg || okMsg || '操作成功', 'ok');
                goTab('users');
            } else {
                PvUI.toast((j && j.msg) || '操作失败', 'err');
            }
        }).catch(function () {
            if (btn) btn.disabled = false;
            PvUI.toast('网络请求失败', 'err');
        });
    }

    function onUserAction(e) {
        var btn = e.target.closest ? e.target.closest('[data-act]') : null;
        if (!btn) return;
        var tr = btn.closest('tr[data-user]');
        if (!tr) return;
        var u = {
            id: tr.getAttribute('data-id'),
            username: tr.getAttribute('data-username'),
            display: tr.getAttribute('data-display'),
            role: tr.getAttribute('data-role'),
            status: parseInt(tr.getAttribute('data-status'), 10) || 0,
            owner: tr.getAttribute('data-owner') === '1'
        };
        var act = btn.getAttribute('data-act');
        if (act === 'edit') { userModal({ mode: 'edit', id: u.id, username: u.username, display: u.display, role: u.role, owner: u.owner }); return; }
        if (act === 'password') { passwordModal(u); return; }
        if (act === 'status') {
            var to = u.status === 1 ? 0 : 1;
            postThen('admin/user-status', { id: u.id, status: to }, btn, to ? '账号已启用' : '账号已停用');
            return;
        }
        if (act === 'delete') {
            PvModal.confirm({
                title: '删除账号',
                message: '确认删除账号「' + u.username + '」？此操作不可恢复。',
                okText: '删除', danger: true
            }).then(function (ok) {
                if (!ok) return;
                postThen('admin/user-delete', { id: u.id }, null, '账号已删除');
            });
        }
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

    function human(b) {
        b = +b || 0;
        if (b < 1024) return b + ' B';
        if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
        if (b < 1073741824) return (b / 1048576).toFixed(2) + ' MB';
        return (b / 1073741824).toFixed(2) + ' GB';
    }
    function storageRoute(r) {
        return global.PvNav ? global.PvNav.route(r) : ((global.PV_BOOT.home || '/') + '?r=' + r);
    }
    function initStorage() {
        var box = document.getElementById('pvStorageBox');
        if (!box) return;
        var refresh = document.getElementById('pvStorageRefresh');
        function render(d) {
            d = d || {};
            var rows = [
                ['数据库', (d.db || {}).bytes, (d.db || {}).path || ''],
                ['上传文件', (d.uploads || {}).bytes, ((d.uploads || {}).files || 0) + ' 个文件'],
                ['缓存区', (d.cache || {}).bytes, '后端：' + ((d.cache || {}).backend || '—') + ' · ' + ((d.cache || {}).count || 0) + ' 条目' + ((d.cache || {}).max_bytes ? ' · 上限 ' + human(d.cache.max_bytes) : '')],
                ['会话文件', (d.session || {}).bytes, ((d.session || {}).files || 0) + ' 个文件']
            ];
            if (d.legacy && d.legacy.bytes > 0) rows.push(['遗留磁盘缓存', d.legacy.bytes, (d.legacy.files || 0) + ' 个文件（可清空）']);
            var html = '<table class="pv-table pv-storage-table"><thead><tr><th>项目</th><th>占用</th><th>说明</th></tr></thead><tbody>';
            rows.forEach(function (r) {
                html += '<tr><td>' + PvUI.esc(r[0]) + '</td><td class="pv-storage-size">' + human(r[1]) + '</td><td class="pv-dim">' + PvUI.esc(r[2] || '') + '</td></tr>';
            });
            box.innerHTML = html + '</tbody></table>';
        }
        function load(showLoading) {
            if (showLoading) box.innerHTML = '<div class="pv-dim">加载中…</div>';
            fetch(storageRoute('api/storage'), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) { if (j && j.code === 200) render(j.data); else box.innerHTML = '<div class="pv-dim">加载失败</div>'; })
                .catch(function () { box.innerHTML = '<div class="pv-dim">网络请求失败</div>'; });
        }
        if (refresh) refresh.addEventListener('click', function () { load(true); });
        var cu = document.getElementById('pvStorageClearUploads');
        if (cu) cu.addEventListener('click', function () {
            PvModal.confirm({ title: '清空上传文件', message: '确认删除全部上传文件及其记录？此操作不可恢复。', okText: '清空', danger: true }).then(function (ok) {
                if (!ok) return;
                PvUI.post(storageRoute('api/storage/clear-uploads'), {}).then(function (j) { PvUI.toast((j && j.msg) || '操作结束', j && j.code === 200 ? 'ok' : 'err'); load(false); });
            });
        });
        var cc = document.getElementById('pvStorageClearCache');
        if (cc) cc.addEventListener('click', function () {
            PvModal.confirm({ title: '清空缓存区', message: '确认清空影像内存缓存？清空后再次打开影像会重新生成。', okText: '清空', danger: true }).then(function (ok) {
                if (!ok) return;
                PvUI.post(storageRoute('api/storage/clear-cache'), {}).then(function (j) { PvUI.toast((j && j.msg) || '操作结束', j && j.code === 200 ? 'ok' : 'err'); load(false); });
            });
        });
        Array.prototype.forEach.call(document.querySelectorAll('.pv-tab'), function (t) {
            if (t.getAttribute('data-tab') === 'storage') t.addEventListener('click', function () { load(false); });
        });
        if (curTab === 'storage') load(true);
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

            // 设置表单：保存后停留在当前页签，并实时刷新顶栏/图标
            Array.prototype.forEach.call(document.querySelectorAll('form[data-ajax-form]'), function (form) {
                form.__pvOnOk = function (j) {
                    applyChrome(j && j.data);
                    goTab(form.querySelector('[name=tab]') ? form.querySelector('[name=tab]').value : 'basic');
                };
            });
            PvUI.bindAjaxForms(document);

            var addBtn = document.getElementById('pvAddUser');
            if (addBtn) addBtn.addEventListener('click', function () { userModal({ mode: 'add', role: 'user' }); });

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

            var table = document.getElementById('pvUserTable');
            if (table) table.addEventListener('click', onUserAction);

            var logClear = document.getElementById('pvLogClear');
            if (logClear) {
                logClear.addEventListener('click', function () {
                    PvModal.confirm({ title: '清空检索日志', message: '确认清空全部检索日志？', okText: '清空', danger: true }).then(function (ok) {
                        if (!ok) return;
                        PvUI.post(PvNav.route('admin/log-clear'), {}).then(function (j) {
                            if (j && j.code === 200) { PvUI.toast(j.msg || '已清空', 'ok'); goTab('logs'); }
                            else PvUI.toast((j && j.msg) || '操作失败', 'err');
                        });
                    });
                });
            }

            var btn = document.getElementById('pvTestPacs');
            var out = document.getElementById('pvTestResult');
            if (btn && out) {
                btn.addEventListener('click', function () {
                    btn.disabled = true;
                    out.className = 'pv-test-result';
                    out.textContent = '测试中…';
                    PvApi.ping().then(function (j) {
                        btn.disabled = false;
                        if (j && j.code === 200) {
                            out.className = 'pv-test-result ok';
                            var d = j.data || {};
                            out.textContent = '✓ 接口可用 · ' + (d.name || '') + ' v' + (d.version || '') + (d.mode ? ' · 模式 ' + d.mode : '') + (d.studies != null ? ' · 检查数 ' + d.studies : '');
                        } else {
                            out.className = 'pv-test-result err';
                            out.textContent = '✗ ' + ((j && j.msg) || '测试失败');
                        }
                    }).catch(function () {
                        btn.disabled = false;
                        out.className = 'pv-test-result err';
                        out.textContent = '✗ 网络请求失败';
                    });
                });
            }

            // 模拟服务器子 Tab（复用 mock.js 的面板逻辑）
            if (global.PvPages && global.PvPages.mock && typeof global.PvPages.mock.init === 'function') {
                try { global.PvPages.mock.init({}); } catch (e) { if (global.console) console.error(e); }
            }

            // 存储情况页签
            try { initStorage(); } catch (e2) { if (global.console) console.error(e2); }

            // 数据来源：检索来源切换 + FHIR 连接测试
            var ps = document.getElementById('pvPatientSource');
            var fhirBox = document.getElementById('pvFhirDataSourceBox');
            if (ps && fhirBox) ps.addEventListener('change', function () { fhirBox.classList.toggle('pv-hidden', ps.value !== 'fhir'); });
            var fhirBtn = document.getElementById('pvFhirTestMain');
            var fhirOut = document.getElementById('pvFhirResultMain');
            if (fhirBtn && fhirOut) {
                fhirBtn.addEventListener('click', function () {
                    fhirBtn.disabled = true; fhirOut.className = 'pv-test-result'; fhirOut.textContent = '测试中…';
                    fetch(storageRoute('api/fhir/test'), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (j) {
                            fhirBtn.disabled = false;
                            if (j && j.code === 200) { fhirOut.className = 'pv-test-result ok'; fhirOut.textContent = '✓ 连接成功 · ' + ((j.data && j.data.name) || 'FHIR'); }
                            else { fhirOut.className = 'pv-test-result err'; fhirOut.textContent = '✗ ' + ((j && j.msg) || '连接失败'); }
                        })
                        .catch(function () { fhirBtn.disabled = false; fhirOut.className = 'pv-test-result err'; fhirOut.textContent = '✗ 网络请求失败'; });
                });
            }
        },
        destroy: function () {}
    };
})(window);
