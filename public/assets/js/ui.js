/* ============================================================
 * assets/js/ui.js — 通用模态框 / 轻提示 / AJAX 表单助手
 * ============================================================
 * 暴露：
 *   PvModal.open(opts)        通用模态框
 *   PvModal.confirm(opts)     确认框（返回 Promise<boolean>）
 *   PvModal.alert(opts)       提示框
 *   PvUI.toast(msg, type)     右上角轻提示
 *   PvUI.post(url, data)      POST 并解析 JSON（自动带 CSRF）
 *   PvUI.bindAjaxForms(root)  将 [data-ajax-form] 表单转为 AJAX 提交
 * ============================================================ */
(function (global) {
    'use strict';
    var boot = global.PV_BOOT || {};

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /* ---------------- 轻提示 ---------------- */
    function toastBox() {
        var box = document.getElementById('pvToastBox');
        if (!box) { box = document.createElement('div'); box.id = 'pvToastBox'; document.body.appendChild(box); }
        return box;
    }
    function toast(msg, type) {
        var el = document.createElement('div');
        el.className = 'pv-toast' + (type ? ' ' + type : '');
        el.textContent = msg;
        toastBox().appendChild(el);
        setTimeout(function () { el.style.opacity = '0'; el.style.transition = 'opacity .2s'; }, 2600);
        setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 2900);
    }

    /* ---------------- 模态框 ---------------- */
    var currentMask = null;

    function close() {
        if (!currentMask) return;
        var mask = currentMask;
        currentMask = null;
        mask.parentNode && mask.parentNode.removeChild(mask);
        document.removeEventListener('keydown', onKey);
    }
    function onKey(e) { if (e.key === 'Escape') close(); }

    function open(opts) {
        opts = opts || {};
        close();
        var mask = document.createElement('div');
        mask.className = 'pv-modal-mask';
        var box = document.createElement('div');
        box.className = 'pv-modal' + (opts.size === 'lg' ? ' pv-modal-lg' : '');

        var head = document.createElement('div');
        head.className = 'pv-modal-head';
        head.innerHTML = '<h3>' + esc(opts.title || '') + '</h3>';
        var x = document.createElement('button');
        x.type = 'button'; x.className = 'pv-modal-x'; x.innerHTML = '&times;';
        x.addEventListener('click', close);
        head.appendChild(x);

        var body = document.createElement('div');
        body.className = 'pv-modal-body';
        if (typeof opts.body === 'string') body.innerHTML = opts.body || '';
        else if (opts.body) body.appendChild(opts.body);

        var foot = document.createElement('div');
        foot.className = 'pv-modal-foot';
        var actions = opts.actions || [{ label: '关闭', cls: 'pv-btn-ghost' }];
        actions.forEach(function (a) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'pv-btn ' + (a.cls || 'pv-btn-primary');
            b.textContent = a.label;
            b.addEventListener('click', function () {
                if (a.onClick) { if (a.onClick(b) === false) return; }
                if (a.close !== false) close();
            });
            foot.appendChild(b);
        });

        box.appendChild(head); box.appendChild(body); box.appendChild(foot);
        mask.appendChild(box);
        if (opts.dismissible !== false) {
            mask.addEventListener('click', function (e) { if (e.target === mask) close(); });
        }
        document.body.appendChild(mask);
        document.addEventListener('keydown', onKey);
        currentMask = mask;
        if (opts.onOpen) opts.onOpen({ mask: mask, body: body, close: close });
        return { close: close, body: body, mask: mask };
    }

    function confirmOpts(opts) {
        if (typeof opts === 'string') opts = { message: opts };
        return new Promise(function (resolve) {
            open({
                title: opts.title || '确认操作',
                body: '<p class="pv-modal-msg">' + esc(opts.message || '') + '</p>',
                actions: [
                    { label: opts.cancelText || '取消', cls: 'pv-btn-ghost', onClick: function () { resolve(false); } },
                    { label: opts.okText || '确定', cls: opts.danger ? 'pv-btn-danger' : 'pv-btn-primary', onClick: function () { resolve(true); } }
                ]
            });
        });
    }
    function alertOpts(opts) {
        if (typeof opts === 'string') opts = { message: opts };
        return new Promise(function (resolve) {
            open({
                title: opts.title || '提示',
                body: '<p class="pv-modal-msg">' + esc(opts.message || '') + '</p>',
                actions: [{ label: '知道了', cls: 'pv-btn-primary', onClick: function () { resolve(true); } }]
            });
        });
    }

    /* ---------------- AJAX ---------------- */
    function post(url, data) {
        var body = data instanceof FormData ? data : new FormData();
        if (!(data instanceof FormData)) {
            data = data || {};
            if (data._csrf === undefined) data._csrf = boot.csrf || '';
            for (var k in data) { if (data[k] !== undefined && data[k] !== null) body.append(k, data[k]); }
        }
        return fetch(url, {
            method: 'POST', body: body,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); });
    }

    function bindAjaxForms(root) {
        var forms = (root || document).querySelectorAll('form[data-ajax-form]');
        Array.prototype.forEach.call(forms, function (form) {
            if (form.__pvBound) return;
            form.__pvBound = true;
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var btn = form.querySelector('[type=submit]');
                if (btn) btn.disabled = true;
                post(form.action, new FormData(form)).then(function (j) {
                    if (btn) btn.disabled = false;
                    if (j && j.code === 200) {
                        toast(j.msg || '操作成功', 'ok');
                        if (typeof form.__pvOnOk === 'function') form.__pvOnOk(j);
                        else if (global.PvNav) global.PvNav.refresh();
                    } else {
                        toast((j && j.msg) || '操作失败', 'err');
                    }
                }).catch(function () {
                    if (btn) btn.disabled = false;
                    toast('网络请求失败', 'err');
                });
            });
        });
    }

    global.PvModal = { open: open, close: close, confirm: confirmOpts, alert: alertOpts };
    global.PvUI = { toast: toast, post: post, bindAjaxForms: bindAjaxForms, esc: esc };
})(window);
