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

    /** 内联小图标（用于连接测试结果等文本内图标，替代 emoji / 符号） */
    var TR_ICONS = {
        check: '<polyline points="20 6 9 17 4 12"/>',
        cross: '<line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/>'
    };
    function trIcon(name) {
        return '<svg class="pv-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (TR_ICONS[name] || '') + '</svg>';
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
    var currentCancel = null;   // confirm / alert 的「取消」回调：非按钮方式关闭时调用，避免 Promise 悬挂

    function close() {
        if (!currentMask) return;
        var mask = currentMask;
        var cancel = currentCancel;
        currentMask = null;
        currentCancel = null;
        mask.parentNode && mask.parentNode.removeChild(mask);
        document.removeEventListener('keydown', onKey);
        if (typeof cancel === 'function') { try { cancel(); } catch (e) {} }
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
        // 页脚左侧注释（如免责声明），按钮仍靠右对齐
        if (opts.footNote) {
            var note = document.createElement('span');
            note.className = 'pv-modal-note';
            note.textContent = opts.footNote;
            foot.appendChild(note);
        }
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
            // Esc / 点遮罩 / 关闭按钮等非按钮关闭：按「取消」处理
            currentCancel = function () { resolve(false); };
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
            currentCancel = function () { resolve(true); };
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

    /**
     * 通用左右分栏导航：点击左栏项切换高亮与右栏面板显隐。
     * @param {object} o
     *   navSelector  左栏项选择器（项带 data-<attr>）
     *   paneSelector 右栏面板选择器（面板带 data-<attr>-pane）
     *   attr         属性名（如 'mp' / 'src' / 'ext'）
     *   initial      初始选中值（缺省取首个项）
     *   onChange     function(value) 切换回调（可选）
     * @return {{select: function(string):void}|null}
     */
    function bindSplit(o) {
        o = o || {};
        var items = document.querySelectorAll(o.navSelector || '');
        var panes = document.querySelectorAll(o.paneSelector || '');
        if (!items.length) return null;
        var navAttr = 'data-' + o.attr, paneAttr = 'data-' + o.attr + '-pane';
        function select(val) {
            Array.prototype.forEach.call(items, function (b) { b.classList.toggle('active', b.getAttribute(navAttr) === val); });
            Array.prototype.forEach.call(panes, function (p) { p.classList.toggle('pv-hidden', p.getAttribute(paneAttr) !== val); });
            if (typeof o.onChange === 'function') o.onChange(val);
        }
        Array.prototype.forEach.call(items, function (b) {
            b.addEventListener('click', function () { select(b.getAttribute(navAttr)); });
        });
        var init = (o.initial !== undefined && o.initial !== null && o.initial !== '') ? o.initial : items[0].getAttribute(navAttr);
        select(init);
        return { select: select };
    }

    /**
     * POST 后按 {code} 统一提示与回调，集中处理按钮禁用与异常。
     * @param {string} route 路由（经 PvNav.route 构造）
     * @param {object|FormData} data
     * @param {object} [opts] { btn, okMsg, errMsg, silent, onOk(j), onError(j) }
     */
    function postThen(route, data, opts) {
        opts = opts || {};
        var url = global.PvNav ? global.PvNav.route(route) : route;
        if (opts.btn) opts.btn.disabled = true;
        return post(url, data).then(function (j) {
            if (opts.btn) opts.btn.disabled = false;
            if (j && j.code === 200) {
                if (!opts.silent) toast(j.msg || opts.okMsg || '操作成功', 'ok');
                if (typeof opts.onOk === 'function') opts.onOk(j);
            } else {
                if (!opts.silent) toast((j && j.msg) || opts.errMsg || '操作失败', 'err');
                if (typeof opts.onError === 'function') opts.onError(j);
            }
            return j;
        }).catch(function () {
            if (opts.btn) opts.btn.disabled = false;
            if (!opts.silent) toast('网络请求失败', 'err');
            if (typeof opts.onError === 'function') opts.onError(null);
            return null;
        });
    }

    /**
     * 绑定「测试连接」按钮：读取表单字段 POST，渲染成功 / 失败结果。
     * @param {object} o
     *   btn / out   按钮与结果元素（或元素 id）
     *   route       测试接口路由
     *   fields      参与提交的字段名数组
     *   okText      function(data) → 成功文案（前置对勾图标）；缺省「连接成功」
     */
    function bindConnTest(o) {
        o = o || {};
        var btn = typeof o.btn === 'string' ? document.getElementById(o.btn) : o.btn;
        var out = typeof o.out === 'string' ? document.getElementById(o.out) : o.out;
        if (!btn || !out) return;
        btn.addEventListener('click', function () {
            btn.disabled = true;
            out.className = 'pv-test-result'; out.textContent = '测试中…';
            var data = {};
            (o.fields || []).forEach(function (name) {
                var el = document.querySelector('[name="' + name + '"]');
                data[name] = el ? el.value : '';
            });
            post(global.PvNav ? global.PvNav.route(o.route) : o.route, data).then(function (j) {
                btn.disabled = false;
                if (j && j.code === 200) {
                    out.className = 'pv-test-result ok';
                    var d = j.data || {};
                    out.innerHTML = trIcon('check') + ' ' + esc(typeof o.okText === 'function' ? o.okText(d) : '连接成功');
                } else {
                    out.className = 'pv-test-result err';
                    out.innerHTML = trIcon('cross') + ' ' + esc((j && j.msg) || '测试失败');
                }
            }).catch(function () {
                btn.disabled = false;
                out.className = 'pv-test-result err';
                out.innerHTML = trIcon('cross') + ' ' + esc('网络请求失败');
            });
        });
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

    /* ---------------- 站内路由 / 实时轮询 / 模态提交 ---------------- */

    /** 站内路由 URL（优先复用 PvNav.route；不可用时按 PV_BOOT.home 兜底） */
    function route(r, params) {
        if (global.PvNav && global.PvNav.route) return global.PvNav.route(r, params);
        var home = boot.home || '/';
        var url = home + (home.indexOf('?') < 0 ? '?' : '&') + 'r=' + encodeURIComponent(r);
        params = params || {};
        for (var k in params) {
            if (params[k] === undefined || params[k] === null || params[k] === '') continue;
            url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
        }
        return url;
    }

    /**
     * 「实时」轮询开关：点击按钮在「运行 / 停止」间切换，运行中加入 active 类。
     * @param {Element}  btn 触发按钮
     * @param {Function} tick 每次轮询执行的函数
     * @param {number}   [ms] 轮询间隔（毫秒，默认 5000）
     * @return {{start:Function, stop:Function, isOn:Function}}
     */
    function liveToggle(btn, tick, ms) {
        var timer = null;
        function isOn() { return !!timer; }
        function stop() {
            if (timer) { clearInterval(timer); timer = null; }
            if (btn) btn.classList.remove('active');
        }
        function run() { try { tick(); } catch (e) {} }
        function start() {
            stop();
            if (btn) btn.classList.add('active');
            run();
            timer = setInterval(run, ms || 5000);
        }
        if (btn) btn.addEventListener('click', function () { if (isOn()) stop(); else start(); });
        return { start: start, stop: stop, isOn: isOn };
    }

    /**
     * 模态框内异步提交：保存时 POST，成功后关闭并按需回调。
     * @param {object} o { title, body, okText, danger, route, getData(), onOk(j), onError(j), onOpen }
     *   getData 返回 false 表示校验未通过、保持打开。
     * @return {object} 与 PvModal.open 相同的句柄
     */
    function modalSubmit(o) {
        o = o || {};
        return open({
            title: o.title || '编辑',
            body: o.body || '',
            size: o.size || '',
            actions: [
                { label: o.cancelText || '取消', cls: 'pv-btn-ghost' },
                {
                    label: o.okText || '保存', cls: o.danger ? 'pv-btn-danger' : 'pv-btn-primary', close: false,
                    onClick: function () {
                        var data = typeof o.getData === 'function' ? o.getData() : (o.getData || {});
                        if (data === false) return false;
                        post(route(o.route), data).then(function (j) {
                            if (j && j.code === 200) {
                                toast(j.msg || o.okMsg || '操作成功', 'ok');
                                close();
                                if (typeof o.onOk === 'function') o.onOk(j);
                            } else {
                                toast((j && j.msg) || o.errMsg || '操作失败', 'err');
                                if (typeof o.onError === 'function') o.onError(j);
                            }
                        }).catch(function () {
                            toast('网络请求失败', 'err');
                            if (typeof o.onError === 'function') o.onError(null);
                        });
                        return false;   // 由回调决定关闭
                    }
                }
            ],
            onOpen: o.onOpen
        });
    }

    /**
     * 清空会话内与本账号相关的临时状态（退出登录 / 切换账号时调用）。
     * 检索关键词与结果、已打开工作区、序列操作痕迹均存于 sessionStorage，
     * 同一标签页内不随登录态自动失效，必须显式清理，避免换账号后残留上一账号数据。
     */
    function clearSessionState() {
        try {
            var keys = [
                'pacs_search_v1', 'pacs_search_v2', 'pacs_search_v3',   // 检索（含历史版本）
                'pacs_workspace_v1',                                    // 已打开检查工作区
                'pacs_series_v1',                                       // 序列操作痕迹
                'pacs_sidebar_w'                                        // 序列栏宽度
            ];
            for (var i = 0; i < keys.length; i++) sessionStorage.removeItem(keys[i]);
        } catch (e) {}
    }

    global.PvModal = { open: open, close: close, confirm: confirmOpts, alert: alertOpts };
    global.PvUI = {
        toast: toast, get: get, post: post, upload: upload, copy: copy,
        bindAjaxForms: bindAjaxForms, esc: esc,
        bindSplit: bindSplit, postThen: postThen, bindConnTest: bindConnTest,
        route: route, liveToggle: liveToggle, modalSubmit: modalSubmit,
        clearSessionState: clearSessionState
    };
})(window);
