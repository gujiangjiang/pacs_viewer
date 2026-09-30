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

            // 检索日志：清空
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

            function formVal(name) {
                var el = document.querySelector('[name="' + name + '"]');
                return el ? el.value : '';
            }

            // 外部接口：DICOM / PACS 连通性测试（当前输入）
            var btn = document.getElementById('pvTestPacs');
            var out = document.getElementById('pvTestResult');
            if (btn && out) {
                btn.addEventListener('click', function () {
                    btn.disabled = true;
                    out.className = 'pv-test-result';
                    out.textContent = '测试中…';
                    PvUI.post(PvNav.route('api/pacs/test'), {
                        pacs_endpoint: formVal('pacs_endpoint'),
                        pacs_api_key: formVal('pacs_api_key'),
                        pacs_timeout: formVal('pacs_timeout')
                    }).then(function (j) {
                        btn.disabled = false;
                        if (j && j.code === 200) {
                            out.className = 'pv-test-result ok';
                            var d = j.data || {};
                            out.textContent = '✓ 接口可用 · ' + (d.name || '') + (d.version ? ' v' + d.version : '') + (d.mode ? ' · 模式 ' + d.mode : '') + (d.studies != null ? ' · 检查数 ' + d.studies : '');
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

            // 外部接口：FHIR R4 连通性测试（当前输入）
            var fhirBtn = document.getElementById('pvFhirTestMain');
            var fhirOut = document.getElementById('pvFhirResultMain');
            if (fhirBtn && fhirOut) {
                fhirBtn.addEventListener('click', function () {
                    fhirBtn.disabled = true; fhirOut.className = 'pv-test-result'; fhirOut.textContent = '测试中…';
                    PvUI.post(PvNav.route('api/fhir/test'), {
                        fhir_endpoint: formVal('fhir_endpoint'),
                        fhir_api_key: formVal('fhir_api_key'),
                        fhir_timeout: formVal('fhir_timeout')
                    }).then(function (j) {
                        fhirBtn.disabled = false;
                        if (j && j.code === 200) { fhirOut.className = 'pv-test-result ok'; fhirOut.textContent = '✓ 连接成功 · ' + ((j.data && j.data.name) || 'FHIR'); }
                        else { fhirOut.className = 'pv-test-result err'; fhirOut.textContent = '✗ ' + ((j && j.msg) || '连接失败'); }
                    }).catch(function () { fhirBtn.disabled = false; fhirOut.className = 'pv-test-result err'; fhirOut.textContent = '✗ 网络请求失败'; });
                });
            }

            // 外部接口：左侧分栏切换（DICOM/PACS ↔ FHIR R4）
            var extItems = document.querySelectorAll('.pv-split-item[data-ext]');
            Array.prototype.forEach.call(extItems, function (item) {
                item.addEventListener('click', function () {
                    var key = item.getAttribute('data-ext');
                    Array.prototype.forEach.call(extItems, function (b) { b.classList.toggle('active', b === item); });
                    Array.prototype.forEach.call(document.querySelectorAll('[data-ext-pane]'), function (p) {
                        p.classList.toggle('pv-hidden', p.getAttribute('data-ext-pane') !== key);
                    });
                });
            });

            // 账号管理面板
            if (global.PvAdminUsers) global.PvAdminUsers.init(goTab);
            // 存储情况面板
            if (global.PvAdminStorage) global.PvAdminStorage.init(curTab);
            // 模拟服务器面板（复用 mock.js 的面板逻辑）
            if (global.PvPages && global.PvPages.mock && typeof global.PvPages.mock.init === 'function') {
                try { global.PvPages.mock.init({}); } catch (e) { if (global.console) console.error(e); }
            }
        },
        destroy: function () {}
    };
})(window);
