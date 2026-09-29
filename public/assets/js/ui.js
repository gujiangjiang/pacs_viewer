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
        if (opts.draggable !== false) makeDraggable(box, head);
        if (opts.onOpen) opts.onOpen({ mask: mask, body: body, close: close });
        return { close: close, body: body, mask: mask };
    }

    /** 按住标题栏拖动模态框 */
    function makeDraggable(modal, head) {
        var dragging = false, sx = 0, sy = 0, sl = 0, st = 0;
        head.style.cursor = 'move';
        head.addEventListener('pointerdown', function (e) {
            if (e.target.closest && e.target.closest('.pv-modal-x')) return;
            var r = modal.getBoundingClientRect();
            modal.style.position = 'fixed';
            modal.style.margin = '0';
            modal.style.left = r.left + 'px';
            modal.style.top = r.top + 'px';
            modal.style.maxHeight = Math.min(r.height, window.innerHeight - 16) + 'px';
            sx = e.clientX; sy = e.clientY; sl = r.left; st = r.top; dragging = true;
            if (head.setPointerCapture) { try { head.setPointerCapture(e.pointerId); } catch (err) {} }
            e.preventDefault();
        });
        head.addEventListener('pointermove', function (e) {
            if (!dragging) return;
            var l = sl + (e.clientX - sx), t = st + (e.clientY - sy);
            l = Math.max(4, Math.min(l, window.innerWidth - modal.offsetWidth - 4));
            t = Math.max(4, Math.min(t, window.innerHeight - 40));
            modal.style.left = l + 'px'; modal.style.top = t + 'px';
        });
        function stop() { dragging = false; }
        head.addEventListener('pointerup', stop);
        head.addEventListener('pointercancel', stop);
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
    /** 统一 JSON 请求：同源 + AJAX 头；401 跳转登录；返回解析后的 JSON */
    function requestJson(url, opts) {
        opts = opts || {};
        return fetch(url, {
            method: opts.method || 'GET',
            body: opts.body || undefined,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin'
        }).then(function (r) {
            if (r.status === 401) {
                var home = boot.home || '/';
                location.href = home + (home.indexOf('?') < 0 ? '?' : '&') + 'r=login';
                throw new Error('未登录');
            }
            return r.json();
        });
    }
    /** GET 并解析 JSON（url 需为完整地址，可用 PvNav.route 构造） */
    function get(url) { return requestJson(url, {}); }
    function post(url, data) {
        var body = data instanceof FormData ? data : new FormData();
        if (!(data instanceof FormData)) {
            data = data || {};
            if (data._csrf === undefined) data._csrf = boot.csrf || '';
            for (var k in data) { if (data[k] !== undefined && data[k] !== null) body.append(k, data[k]); }
        }
        return requestJson(url, { method: 'POST', body: body });
    }

    /** 通用文件上传：PvUI.upload(route, file, fields) → Promise<{code,msg,data}> */
    function upload(route, file, fields) {
        var body = new FormData();
        if (file) body.append('file', file);
        fields = fields || {};
        if (fields._csrf === undefined) fields._csrf = boot.csrf || '';
        for (var k in fields) { if (fields[k] !== undefined && fields[k] !== null) body.append(k, fields[k]); }
        var url = route.indexOf('r=') >= 0 ? route : ((global.PvNav ? global.PvNav.route(route) : route));
        return requestJson(url, { method: 'POST', body: body });
    }

    /** 复制文本到剪贴板（优先 Clipboard API，回退 execCommand）；返回 Promise<boolean> */
    function copy(text) {
        text = String(text == null ? '' : text);
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return false; });
        }
        try {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            var ok = document.execCommand('copy');
            document.body.removeChild(ta);
            return Promise.resolve(!!ok);
        } catch (e) { return Promise.resolve(false); }
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
    global.PvUI = { toast: toast, get: get, post: post, upload: upload, copy: copy, bindAjaxForms: bindAjaxForms, esc: esc };
})(window);
