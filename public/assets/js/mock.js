/* assets/js/mock.js — 模拟服务器页交互（PvPages.mock） */
(function (global) {
    'use strict';

    function getJson(route, params) {
        var url = global.PvNav ? global.PvNav.route(route, params) : (global.PV_BOOT.home || '/') + '?r=' + route;
        return PvUI.get(url);
    }

    /** 更新患者数据来源标签（builtin 内置仿真 / fhir 门诊 FHIR） */
    function updateSource(src) {
        var el = document.getElementById('pvMockSourceLabel');
        if (!el) return;
        var map = { builtin: '内置仿真数据', fhir: '门诊 FHIR' };
        el.textContent = '来源：' + (map[src] || src || '内置仿真数据');
    }

    function renderPatients(list) {
        var box = document.getElementById('pvMockPatients');
        var empty = document.getElementById('pvMockEmpty');
        var meta = document.getElementById('pvMockResultMeta');
        if (!box) return;
        box.innerHTML = '';
        if (!list || !list.length) {
            empty.style.display = '';
            meta.style.display = 'none';
            return;
        }
        empty.style.display = 'none';
        meta.style.display = '';
        meta.textContent = '共 ' + list.length + ' 位患者（仅显示已缴费、已登记的检查）';
        list.forEach(function (p) {
            var el = document.createElement('div');
            el.className = 'pv-mock-patient';
            var exams = (p.exams || []).map(function (e) {
                return '<span title="' + PvUI.esc(e.accession_no || '') + '">' + PvUI.esc(e.modality) + ' · ' + PvUI.esc(e.description) + '</span>';
            }).join('');
            el.innerHTML =
                '<div class="pv-mock-patient-head"><b>' + PvUI.esc(p.name) + '</b>' +
                '<span class="pv-dim">' + PvUI.esc(p.gender) + ' / ' + PvUI.esc(p.age || '—') + '</span></div>' +
                '<div class="pv-mock-patient-meta">患者号：' + PvUI.esc(p.patient_id) +
                '　门诊号：' + PvUI.esc(p.outpatient_no || '—') + '<br>检查数：' + (p.exams ? p.exams.length : 0) + '</div>' +
                '<div class="pv-mock-exam">' + exams + '</div>';
            box.appendChild(el);
        });
    }

    function bindCopy() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-copy]'), function (btn) {
            btn.addEventListener('click', function () {
                var input = document.querySelector(btn.getAttribute('data-copy'));
                if (!input) return;
                PvUI.copy(input.value).then(function (ok) {
                    PvUI.toast(ok ? '已复制' : '复制失败，请手动选择复制', ok ? 'ok' : 'err');
                });
            });
        });
    }

    function bindAnatomy() {
        var rows = document.getElementById('pvAnatomyRows');
        var tpl = document.getElementById('pvAnatomyTpl');
        var add = document.getElementById('pvAnatomyAdd');
        var reset = document.getElementById('pvAnatomyReset');
        var seq = Date.now();

        function bindRemove() {
            Array.prototype.forEach.call(document.querySelectorAll('[data-anatomy-remove]'), function (b) {
                b.onclick = function () {
                    var tr = b.closest ? b.closest('tr') : null;
                    if (tr && tr.parentNode) tr.parentNode.removeChild(tr);
                };
            });
        }
        if (add && rows && tpl) {
            add.addEventListener('click', function () {
                var frag = tpl.content.cloneNode(true);
                var idx = 'n' + (seq++);
                Array.prototype.forEach.call(frag.querySelectorAll('[data-f]'), function (inp) {
                    inp.name = 'anatomy[' + idx + '][' + inp.getAttribute('data-f') + ']';
                });
                rows.appendChild(frag);
                bindRemove();
            });
        }
        bindRemove();
        if (reset) {
            reset.addEventListener('click', function () {
                PvUI.post(PvNav.route('api/mock/anatomy-reset'), {}).then(function (j) {
                    if (j && j.code === 200) { PvUI.toast(j.msg || '已恢复默认', 'ok'); setTimeout(function () { location.reload(); }, 600); }
                    else PvUI.toast((j && j.msg) || '操作失败', 'err');
                });
            });
        }
    }

    global.PvPages = global.PvPages || {};
    global.PvPages.mock = {
        init: function (data) {
            data = data || {};
            PvUI.bindAjaxForms(document);
            bindCopy();
            bindAnatomy();
            if (data.flash) PvUI.toast(data.flash, 'ok');

            var regen = document.getElementById('pvRegenKey');
            if (regen) {
                regen.addEventListener('click', function () {
                    PvUI.post(PvNav.route('api/mock/key'), {}).then(function (j) {
                        if (j && j.code === 200) {
                            var inp = document.getElementById('pvMockKey');
                            if (inp && j.data) inp.value = j.data.key;
                            PvUI.toast(j.msg || '密钥已更新，请记得重新应用', 'ok');
                        } else PvUI.toast((j && j.msg) || '操作失败', 'err');
                    });
                });
            }

            var apply = document.getElementById('pvApplyMock');
            if (apply) {
                apply.addEventListener('click', function () {
                    apply.disabled = true;
                    PvUI.post(PvNav.route('api/mock/apply'), {}).then(function (j) {
                        apply.disabled = false;
                        if (j && j.code === 200) {
                            var d = j.data || {};
                            // 即时回显到「外部接口」表单（同页另一子 Tab）
                            var setVal = function (name, val) {
                                var el = document.querySelector('[name="' + name + '"]');
                                if (el && val != null) el.value = val;
                            };
                            setVal('pacs_endpoint', d.endpoint);
                            setVal('pacs_api_key', d.key);
                            PvUI.toast('已应用：' + (d.endpoint || ''), 'ok');
                            PvUI.toast('已填入 DICOMweb 接口，可回到「研究检索」使用模拟数据', 'ok');
                        } else PvUI.toast((j && j.msg) || '应用失败', 'err');
                    }).catch(function () { apply.disabled = false; PvUI.toast('网络请求失败', 'err'); });
                });
            }

            var sbtn = document.getElementById('pvMockSearch');
            var skw = document.getElementById('pvMockKeyword');
            if (sbtn && skw) {
                var run = function () {
                    sbtn.disabled = true; sbtn.textContent = '检索中…';
                    getJson('api/mock/patients', { q: skw.value }).then(function (j) {
                        sbtn.disabled = false; sbtn.textContent = '检索患者';
                        if (j && j.code === 200) { renderPatients(j.data.list || []); updateSource(j.data.source); }
                        else { renderPatients([]); PvUI.toast((j && j.msg) || '检索失败', 'err'); }
                    }).catch(function () {
                        sbtn.disabled = false; sbtn.textContent = '检索患者';
                        PvUI.toast('网络请求失败', 'err');
                    });
                };
                sbtn.addEventListener('click', run);
                skw.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); run(); } });
            }
        },
        destroy: function () {}
    };
})(window);
