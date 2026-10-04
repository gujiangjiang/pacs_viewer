/* assets/js/mock.js — 模拟服务器页交互（PvPages.mock） */
(function (global) {
    'use strict';

    function getJson(route, params) { return PvUI.get(PvUI.route(route, params)); }   // 复用通用路由/请求助手

    /** 更新患者数据来源标签（builtin 内置仿真 / fhir 门诊 FHIR） */
    function updateSource(src) {
        var el = document.getElementById('pvMockSourceLabel');
        if (!el) return;
        var map = { builtin: '内置仿真数据', fhir: '门诊 FHIR' };
        el.textContent = '来源：' + (map[src] || src || '内置仿真数据');
    }

    /* ---------- 患者预览：固定框内滚动 + 动态分页加载 ---------- */
    var MOCK_PAGE = 20;
    var mockLoader = null;
    var mockLoaded = 0, mockTotal = 0, mockHasMore = false, mockQuery = '';

    /** 单个患者卡片 */
    function patientCard(p) {
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
        return el;
    }

    function appendPatients(list) {
        var grid = document.getElementById('pvMockPatients');
        if (!grid) return;
        (list || []).forEach(function (p) { grid.appendChild(patientCard(p)); });
    }

    /** 结果统计 + 空白占位显隐（并同步框高） */
    function updatePatientsMeta() {
        var meta = document.getElementById('pvMockResultMeta');
        var empty = document.getElementById('pvMockEmpty');
        if (empty) empty.style.display = mockTotal > 0 ? 'none' : '';
        if (meta) {
            if (mockTotal > 0) {
                meta.style.display = '';
                meta.textContent = '共 ' + mockTotal + ' 位患者（仅显示已缴费、已登记的检查，已加载 ' + mockLoaded + (mockHasMore ? '，向下滚动加载更多' : '') + '）';
            } else {
                meta.style.display = 'none';
                var t = empty && empty.querySelector('.pv-empty-title');
                if (t) t.textContent = mockQuery ? '未找到匹配的患者' : '点击「检索患者」查看模拟服务器中的患者与检查';
            }
        }
    }

    /** 拉取一页患者 */
    function fetchPatients(offset) {
        return getJson('api/mock/patients', { q: mockQuery, limit: MOCK_PAGE, offset: offset }).then(function (j) {
            if (!j || j.code !== 200) throw new Error((j && j.msg) || '检索失败');
            return j.data || {};
        });
    }

    /** 重置预览（销毁加载器 / 清空列表）；keepKeyword=false 时同时清空关键词 */
    function resetMockPatients(keepKeyword) {
        if (mockLoader) { mockLoader.destroy(); mockLoader = null; }
        mockLoaded = 0; mockTotal = 0; mockHasMore = false;
        var grid = document.getElementById('pvMockPatients'); if (grid) grid.innerHTML = '';
        var meta = document.getElementById('pvMockResultMeta'); if (meta) { meta.textContent = ''; meta.style.display = 'none'; }
        var empty = document.getElementById('pvMockEmpty'); if (empty) empty.style.display = '';
        if (!keepKeyword) {
            mockQuery = '';
            var kw = document.getElementById('pvMockKeyword'); if (kw) kw.value = '';
        }
    }
    function clearMockPatients() { resetMockPatients(false); }

    /** 建立「滚动到底」加载器（结果列表在框内滚动） */
    function createPatientsLoader() {
        if (mockLoader) { mockLoader.destroy(); mockLoader = null; }
        if (!global.PvInfiniteScroll) return;
        mockLoader = global.PvInfiniteScroll.create({
            container: document.getElementById('pvMockScroll'),
            list: document.getElementById('pvMockScroll'),
            offset: mockLoaded, hasMore: mockHasMore, pageSize: MOCK_PAGE,
            sentinelClass: 'pv-more', moreText: '加载更多患者…', endText: '',
            load: function (off) {
                return fetchPatients(off).then(function (d) {
                    return { list: d.list || [], has_more: !!d.has_more, total: d.total };
                });
            },
            append: function (list) { appendPatients(list); },
            onState: function (ld) {
                mockLoaded = ld.offset;
                mockHasMore = !!ld.hasMore;
                if (typeof ld.total === 'number') mockTotal = ld.total;
                updatePatientsMeta();
            }
        });
    }

    /** 执行检索（重置到第一页） */
    function runPatientSearch(btn) {
        var kw = document.getElementById('pvMockKeyword');
        mockQuery = kw ? kw.value : '';
        resetMockPatients(true);
        if (btn) { btn.disabled = true; btn.textContent = '检索中…'; }
        fetchPatients(0).then(function (d) {
            if (btn) { btn.disabled = false; btn.textContent = '检索患者'; }
            mockTotal = typeof d.total === 'number' ? d.total : (d.list || []).length;
            mockHasMore = !!d.has_more;
            appendPatients(d.list || []);
            mockLoaded = (d.list || []).length;
            updateSource(d.source);
            if (d.fhir_error) PvUI.toast('FHIR 获取失败：' + d.fhir_error, 'err');
            updatePatientsMeta();
            createPatientsLoader();
        }).catch(function (e) {
            if (btn) { btn.disabled = false; btn.textContent = '检索患者'; }
            mockTotal = 0; mockHasMore = false;
            updatePatientsMeta();
            PvUI.toast(e && e.message ? e.message : '检索失败', 'err');
        });
    }

    function bindCopy() {
        // data-copy 可挂在按钮上（复制其指向元素），或直接挂在输入框上（点击自身即复制）
        Array.prototype.forEach.call(document.querySelectorAll('[data-copy]'), function (el) {
            el.addEventListener('click', function () {
                var src = (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA')
                    ? el : document.querySelector(el.getAttribute('data-copy'));
                if (!src) return;
                PvUI.copy(src.value).then(function (ok) {
                    PvUI.toast(ok ? '已复制' : '复制失败，请手动选择复制', ok ? 'ok' : 'err');
                });
            });
        });
    }

    /** 启停联动：禁用时隐藏参数区 */
    function syncMockParams() {
        var en = document.getElementById('pvMockEnabled');
        var box = document.getElementById('pvMockParams');
        if (box) box.classList.toggle('pv-hidden', !(en && en.checked));
    }
    /** 刷新「服务器状态」指标（启停 / 来源 / 部位与切片） */
    function refreshMockStatus() {
        var en = document.getElementById('pvMockEnabled');
        var on = !!(en && en.checked);
        var badge = document.getElementById('pvStatBadge');
        if (badge) { badge.textContent = on ? '运行中' : '已停用'; badge.className = 'pv-badge ' + (on ? 'ok' : 'off'); }
        var st = document.getElementById('pvStatEnabled'); if (st) st.textContent = on ? '运行中' : '已停用';
        var srcEl = document.getElementById('pvStatSource');
        var src = document.querySelector('[name="mock_patient_source"]');
        if (srcEl) srcEl.textContent = (src && src.value === 'fhir') ? 'FHIR 接口获取' : '内置模拟数据';
        var rows = document.querySelectorAll('#pvAnatomyRows tr');
        var list = [], count = 0;
        Array.prototype.forEach.call(rows, function (tr) {
            var cb = tr.querySelector('input[type=checkbox]');
            if (!cb || !cb.checked) return;
            var val = function (f) { var el = tr.querySelector('[name$="[' + f + ']"]'); return el ? el.value : ''; };
            count++;
            list.push((val('modality') ? val('modality') + '·' : '') + (val('label') || val('body_key')) + '（' + (val('frames') || 0) + ' 帧）');
        });
        var c = document.getElementById('pvStatPartCount'); if (c) c.textContent = count;
        var p = document.getElementById('pvStatParts'); if (p) p.textContent = list.length ? list.join('、') : '未启用任何部位';
    }
    global.PvMockRefresh = function () { syncMockParams(); refreshMockStatus(); };

    /** 模拟服务器左右两栏：左侧导航切换右侧面板（复用通用分栏助手） */
    function bindMpane() {
        PvUI.bindSplit({
            navSelector: '.pv-split-item[data-mp]', paneSelector: '[data-mp-pane]', attr: 'mp', initial: 'status',
            // 离开「患者查询」子页即清空预览（框体高度由 CSS flex 锚定页脚，无需计算）
            onChange: function (val) { if (val !== 'patients') clearMockPatients(); }
        });
    }

    /** 患者数据来源（内置模拟 / FHIR）左右分栏切换，并写入隐藏字段 */
    function bindSource() {
        var hidden = document.querySelector('[name="mock_patient_source"]');
        if (!document.querySelectorAll('.pv-split-item[data-src]').length) return;
        PvUI.bindSplit({
            navSelector: '.pv-split-item[data-src]', paneSelector: '[data-src-pane]', attr: 'src',
            initial: hidden && hidden.value ? hidden.value : 'builtin',
            onChange: function (src) { if (hidden) hidden.value = src; }
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
            bindMpane();
            bindSource();
            bindAnatomy();
            // 切换到其他页签（离开模拟服务器）时清空患者预览
            Array.prototype.forEach.call(document.querySelectorAll('.pv-tab'), function (t) {
                t.addEventListener('click', function () { if (t.getAttribute('data-tab') !== 'mock') clearMockPatients(); });
            });
            var pvEn = document.getElementById('pvMockEnabled');
            if (pvEn) pvEn.addEventListener('change', function () { syncMockParams(); refreshMockStatus(); });
            syncMockParams();
            refreshMockStatus();
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
                            PvUI.toast('已填入 DICOMweb 接口，可回到「患者查询」使用模拟数据', 'ok');
                        } else PvUI.toast((j && j.msg) || '应用失败', 'err');
                    }).catch(function () { apply.disabled = false; PvUI.toast('网络请求失败', 'err'); });
                });
            }

            var sbtn = document.getElementById('pvMockSearch');
            var skw = document.getElementById('pvMockKeyword');
            if (sbtn && skw) {
                var run = function () { runPatientSearch(sbtn); };
                sbtn.addEventListener('click', run);
                skw.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); run(); } });
            }
        },
        destroy: function () { clearMockPatients(); }
    };
})(window);
