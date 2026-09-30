/* assets/js/admin-users.js — 管理页「账号管理」面板（PvAdminUsers）
 * 由 admin.js 装配；创建 / 编辑 / 重置密码 / 启停 / 删除账号。
 */
(function (global) {
    'use strict';

    var goTabFn = null;   // 由 init 注入的页签跳转（保存后回到账号列表）

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
                if (goTabFn) goTabFn('users');
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

    global.PvAdminUsers = {
        /** @param {Function} goTab 页签跳转（保存后回到账号列表） */
        init: function (goTab) {
            goTabFn = goTab || null;
            var addBtn = document.getElementById('pvAddUser');
            if (addBtn) addBtn.addEventListener('click', function () { userModal({ mode: 'add', role: 'user' }); });
            var table = document.getElementById('pvUserTable');
            if (table) table.addEventListener('click', onUserAction);
        }
    };
})(window);
