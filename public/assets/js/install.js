/**
 * assets/js/install.js — 多步安装向导驱动
 * 流程：环境检测 → 数据库 → 初始化 → 站点（含时区）→ 管理员 → 完成（自动登录进入）
 * 阶段由后端 config.db setup.stage 断点续填；全部请求携带 CSRF。
 */
(function () {
    'use strict';

    var CFG = window.PV_INSTALL || { base: '/', csrf: '' };
    var state = { status: null, adminUser: '', adminPass: '' };
    var COMMON_TZ = ['Asia/Shanghai', 'Asia/Urumqi', 'Asia/Hong_Kong', 'Asia/Taipei',
        'Asia/Tokyo', 'Asia/Singapore', 'America/New_York', 'Europe/London', 'UTC'];

    function el(id) { return document.getElementById(id); }

    function toast(msg, kind) {
        if (window.PvUI && PvUI.toast) PvUI.toast(msg, kind || 'ok');
    }

    /** POST（application/x-www-form-urlencoded，_csrf 内置；自动带 CSRF 与提示） */
    function post(action, data) {
        var body = new URLSearchParams();
        body.set('_csrf', CFG.csrf);
        Object.keys(data || {}).forEach(function (k) { body.set(k, data[k]); });
        return fetch(CFG.base + '?r=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (r) { return r.json(); }).then(function (j) {
            if (!j || j.code !== 200) throw new Error((j && j.msg) || '请求失败');
            return j.data || {};
        }).catch(function (e) {
            toast(e.message || '请求失败', 'err');
            throw e;
        });
    }

    /** 显示指定步骤面板并更新步骤条 */
    function show(step) {
        var panes = document.querySelectorAll('.pv-wiz-pane');
        for (var i = 0; i < panes.length; i++) {
            panes[i].style.display = (panes[i].getAttribute('data-pane') === step) ? 'block' : 'none';
        }
        var chips = document.querySelectorAll('#wiz-steps .pv-wiz-step');
        var reached = false;
        for (var j = 0; j < chips.length; j++) {
            var s = chips[j].getAttribute('data-step');
            chips[j].className = 'pv-wiz-step';
            if (s === step) { chips[j].className += ' active'; reached = true; }
            else if (!reached) { chips[j].className += ' done'; }
        }
    }

    /** 环境检测列表（状态点 + 文案，无图形字符） */
    function renderEnv(env) {
        var items = [
            { ok: env.php.ok, text: 'PHP 版本：' + env.php.version + '（需要 ' + env.php.required + '）' },
            { ok: env.pdo.ok, text: 'PDO SQLite 驱动：' + (env.pdo.drivers.length ? env.pdo.drivers.join(' / ') : '未检测到') },
            { ok: env.data.ok, text: '数据目录可写：' + env.data.path }
        ];
        var html = '', pass = true, ul = el('wiz-env');
        for (var i = 0; i < items.length; i++) {
            var li = document.createElement('li');
            var dot = document.createElement('i');
            dot.className = 'pv-wiz-dot ' + (items[i].ok ? 'ok' : 'bad');
            var span = document.createElement('span');
            span.textContent = items[i].text;
            li.appendChild(dot);
            li.appendChild(span);
            ul.appendChild(li);
            if (!items[i].ok) pass = false;
        }
        el('wiz-env-next').disabled = !pass;
    }

    /** 时区下拉：常用置顶 + 全量 */
    function renderTimezones(list, selected) {
        var sel = el('wiz-site-tz');
        var html = '<optgroup label="常用">';
        COMMON_TZ.forEach(function (t) { html += '<option value="' + t + '">' + t + '</option>'; });
        html += '</optgroup><optgroup label="全部时区">';
        (list || []).forEach(function (t) { html += '<option value="' + t + '">' + t + '</option>'; });
        html += '</optgroup>';
        sel.innerHTML = html;
        sel.value = selected || 'Asia/Shanghai';
        if (!sel.value) sel.value = 'Asia/Shanghai';
    }

    /** 初始化：拉取状态并按阶段续填 */
    function init() {
        fetch(CFG.base + '?r=install/status').then(function (r) { return r.json(); }).then(function (j) {
            if (!j || j.code !== 200) throw new Error((j && j.msg) || '无法获取初始化状态');
            var d = j.data;
            state.status = d;
            renderEnv(d.env);
            renderTimezones(d.timezones, d.site.timezone);
            el('wiz-db-path').value = d.default_db_path || '';
            if (d.site.site_title) el('wiz-site-title').value = d.site.site_title;
            if (d.site.hospital_name) el('wiz-site-hosp').value = d.site.hospital_name;
            var stageMap = { env: 'env', migrate: 'migrate', site: 'site', admin: 'admin', done: 'finish' };
            show(stageMap[d.stage] || 'env');
            if (d.stage === 'migrate') {
                el('wiz-migrate-hint').textContent = '数据库配置已保存，点击开始创建数据表。';
            }
        }).catch(function (e) { toast(e.message, 'err'); });
    }

    /* ---------- 步骤事件 ---------- */
    el('wiz-env-next').addEventListener('click', function () { show('database'); });

    el('wiz-db-submit').addEventListener('click', function () {
        post('install/database', { db_path: el('wiz-db-path').value.trim() }).then(function () {
            toast('数据库连接正常');
            show('migrate');
        }).catch(function () {});
    });

    el('wiz-migrate-submit').addEventListener('click', function () {
        post('install/migrate', {}).then(function () {
            el('wiz-migrate-hint').textContent = '数据库已就绪。';
            el('wiz-migrate-submit').style.display = 'none';
            el('wiz-migrate-next').style.display = 'block';
        }).catch(function () {});
    });
    el('wiz-migrate-next').addEventListener('click', function () { show('site'); });

    el('wiz-site-submit').addEventListener('click', function () {
        post('install/site', {
            site_title: el('wiz-site-title').value.trim(),
            hospital_name: el('wiz-site-hosp').value.trim(),
            timezone: el('wiz-site-tz').value
        }).then(function () {
            toast('站点信息已保存');
            show('admin');
        }).catch(function () {});
    });

    el('wiz-admin-submit').addEventListener('click', function () {
        var user = el('wiz-admin-user').value.trim();
        var pass = el('wiz-admin-pass').value;
        var pass2 = el('wiz-admin-pass2').value;
        if (!user || !pass) { toast('请填写用户名与密码', 'err'); return; }
        if (pass !== pass2) { toast('两次密码不一致', 'err'); return; }
        post('install/admin', {
            username: user,
            display_name: el('wiz-admin-name').value.trim(),
            password: pass,
            password_confirm: pass2
        }).then(function () {
            state.adminUser = user;
            state.adminPass = pass;
            toast('管理员创建成功');
            show('finish');
        }).catch(function () {});
    });

    el('wiz-finish-submit').addEventListener('click', function () {
        post('install/finish', { username: state.adminUser, password: state.adminPass }).then(function () {
            toast('初始化完成，正在进入系统');
            window.location.href = CFG.base;
        }).catch(function () {});
    });

    init();
})();
