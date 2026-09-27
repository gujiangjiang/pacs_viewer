/* assets/js/admin.js — 管理设置页交互（PvPages.admin：页签 + 接口测试） */
(function (global) {
    'use strict';

    global.PvPages = global.PvPages || {};
    global.PvPages.admin = {
        init: function () {
            var tabs = document.querySelectorAll('.pv-tab');
            var panes = document.querySelectorAll('.pv-tabpane');
            Array.prototype.forEach.call(tabs, function (t) {
                t.addEventListener('click', function () {
                    var key = t.getAttribute('data-tab');
                    Array.prototype.forEach.call(tabs, function (x) { x.classList.toggle('active', x === t); });
                    Array.prototype.forEach.call(panes, function (p) { p.classList.toggle('active', p.getAttribute('data-pane') === key); });
                });
            });

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
        },
        destroy: function () {}
    };
})(window);
