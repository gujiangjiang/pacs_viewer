/* assets/js/mock.js — 模拟服务器页交互（PvPages.mock） */
(function (global) {
    'use strict';

    function getJson(route, params) {
        var url = global.PvNav ? global.PvNav.route(route, params) : (global.PV_BOOT.home || '/') + '?r=' + route;
        return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); });
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
                var v = input.value;
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(v).then(function () { PvUI.toast('已复制', 'ok'); });
                } else {
                    input.select(); try { document.execCommand('copy'); } catch (e) {}
                    PvUI.toast('已复制', 'ok');
                }
            });
        });
    }

    global.PvPages = global.PvPages || {};
    global.PvPages.mock = {
        init: function (data) {
            data = data || {};
            PvUI.bindAjaxForms(document);
            bindCopy();
            if (data.flash) PvUI.toast(data.flash, 'ok');

            var source = document.getElementById('pvMockSource');
            var fhirBox = document.getElementById('pvFhirBox');
            var fhirKeep = document.getElementById('pvFhirSaveBuiltin');
            if (source && fhirBox) {
                source.addEventListener('change', function () {
                    var on = source.value === 'fhir';
                    fhirBox.classList.toggle('pv-hidden', !on);
                    if (fhirKeep) fhirKeep.classList.toggle('pv-hidden', on);
                });
            }

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
                            PvUI.toast('已应用：' + (j.data ? j.data.endpoint : ''), 'ok');
                            PvUI.toast('可回到「研究检索」开始使用模拟数据', 'ok');
                        } else PvUI.toast((j && j.msg) || '应用失败', 'err');
                    }).catch(function () { apply.disabled = false; PvUI.toast('网络请求失败', 'err'); });
                });
            }

            var test = document.getElementById('pvFhirTest');
            var out = document.getElementById('pvFhirResult');
            if (test && out) {
                test.addEventListener('click', function () {
                    test.disabled = true; out.className = 'pv-test-result'; out.textContent = '测试中…';
                    getJson('api/mock/fhir-test').then(function (j) {
                        test.disabled = false;
                        if (j && j.code === 200) {
                            out.className = 'pv-test-result ok';
                            out.textContent = '✓ 连接成功 · ' + ((j.data && j.data.name) || 'FHIR');
                        } else {
                            out.className = 'pv-test-result err';
                            out.textContent = '✗ ' + ((j && j.msg) || '连接失败');
                        }
                    }).catch(function () { test.disabled = false; out.className = 'pv-test-result err'; out.textContent = '✗ 网络请求失败'; });
                });
            }

            var sbtn = document.getElementById('pvMockSearch');
            var skw = document.getElementById('pvMockKeyword');
            if (sbtn && skw) {
                var run = function () {
                    sbtn.disabled = true; sbtn.textContent = '检索中…';
                    getJson('api/mock/patients', { q: skw.value }).then(function (j) {
                        sbtn.disabled = false; sbtn.textContent = '检索患者';
                        if (j && j.code === 200) renderPatients(j.data.list || []);
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
