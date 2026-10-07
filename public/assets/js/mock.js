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
                meta.textContent = '共 ' + mockTotal + ' 位患者（全部患者，含已摄片检查；已加载 ' + mockLoaded + (mockHasMore ? '，向下滚动加载更多' : '') + '）';
            } else {
                meta.style.display = 'none';
                var t = empty && empty.querySelector('.pv-empty-title');
                if (t) t.textContent = mockQuery ? '未找到匹配的患者' : '点击「检索患者」查看模拟服务器中的患者与检查';
            }
        }
    }

    /** 请求一页患者（返回站点标准接口结构，供高层分页封装归一化） */
    function requestPatients(offset) {
        return getJson('api/mock/patients', { q: mockQuery, limit: MOCK_PAGE, offset: offset });
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
        mockLoader = global.PvInfiniteScroll.paged({
            container: document.getElementById('pvMockScroll'),
            list: document.getElementById('pvMockScroll'),
            offset: mockLoaded, hasMore: mockHasMore, pageSize: MOCK_PAGE,
            sentinelClass: 'pv-more', moreText: '加载更多患者…', endText: '',
            request: function (off) { return requestPatients(off); },
            append: function (list) { appendPatients(list); },
            onState: function (st) {
                mockLoaded = st.offset;
                mockHasMore = st.hasMore;
                if (typeof st.total === 'number') mockTotal = st.total;
                updatePatientsMeta();
            },
            onError: function (msg) { PvUI.toast(msg, 'err'); }
        });
    }

    /** 执行检索（重置到第一页） */
    function runPatientSearch(btn) {
        var kw = document.getElementById('pvMockKeyword');
        mockQuery = kw ? kw.value : '';
        resetMockPatients(true);
        if (btn) { btn.disabled = true; btn.textContent = '检索中…'; }
        requestPatients(0).then(function (j) {
            if (btn) { btn.disabled = false; btn.textContent = '检索患者'; }
            if (!j || j.code !== 200) {
                mockTotal = 0; mockHasMore = false;
                updatePatientsMeta();
                PvUI.toast((j && j.msg) || '检索失败', 'err');
                return;
            }
            var d = j.data || {};
            mockTotal = typeof d.total === 'number' ? d.total : (d.list || []).length;
            mockHasMore = !!d.has_more;
            appendPatients(d.list || []);
            mockLoaded = (d.list || []).length;
            updateSource(d.source);
            if (d.fhir_error) PvUI.toast('FHIR 获取失败：' + d.fhir_error, 'err');
            updatePatientsMeta();
            createPatientsLoader();
        }).catch(function () {
            if (btn) { btn.disabled = false; btn.textContent = '检索患者'; }
            mockTotal = 0; mockHasMore = false;
            updatePatientsMeta();
            PvUI.toast('网络请求失败', 'err');
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
            // 离开「患者查询」子页即清空预览；进入「摄片登记」自动加载工作列表
            onChange: function (val) {
                if (val !== 'patients') clearMockPatients();
                if (val === 'worklist') loadWorklist();
            }
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
                if (global.PvControls) global.PvControls.init(rows);   // 增强新增行的部位下拉
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

    /* ---------- 摄片列表：待登记 / 待摄片 / 已完成（子页签 + 日期筛选） ---------- */
    var wlAll = [], wlTab = 'pending', wlDate = '';
    function wlStatusText(w) {
        if (w.business_status) return w.business_status;
        var m = { requested: '待登记', accepted: '已登记待摄片', 'in-progress': '摄片中', completed: '已摄片', cancelled: '已取消' };
        return m[w.status] || w.status || '';
    }
    function wlGroup(w) {
        if (w.status === 'requested') return 'pending';
        if (w.status === 'completed') return 'done';
        return 'toshoot';   // accepted / in-progress
    }
    function wlRowDate(w) { var d = (w.registered_at || w.authored_at || ''); return d ? String(d).slice(0, 10) : ''; }
    function wlCounts() {
        var c = { pending: 0, toshoot: 0, done: 0 };
        wlAll.forEach(function (w) {
            if (wlDate && wlRowDate(w) !== wlDate) return;
            var g = wlGroup(w); if (c[g] !== undefined) c[g]++;
        });
        return c;
    }
    function renderWorklist() {
        var body = document.getElementById('pvWorklistBody');
        if (!body) return;
        var list = wlAll.filter(function (w) {
            if (wlGroup(w) !== wlTab) return false;
            if (wlDate && wlRowDate(w) !== wlDate) return false;
            return true;
        });
        var c = wlCounts();
        ['pending', 'toshoot', 'done'].forEach(function (k) {
            var el = document.querySelector('.pv-wl-cnt[data-cnt="' + k + '"]');
            if (el) el.textContent = c[k];
        });
        if (!list.length) {
            body.innerHTML = '<tr data-row="1"><td colspan="9" class="pv-dim" style="text-align:center">无记录</td></tr>';
            return;
        }
        body.innerHTML = list.map(function (w) {
            var reg = (w.status === 'requested')
                ? '<button type="button" class="pv-btn pv-btn-outline pv-btn-sm" data-wl-reg="1">登记</button> ' : '';
            var acq = (w.status === 'completed')
                ? ''
                : '<button type="button" class="pv-btn pv-btn-primary pv-btn-sm" data-wl-acq="1">摄片</button>';
            return '<tr data-row="1" data-wl=\'' + PvUI.esc(JSON.stringify(w)).replace(/'/g, '&#39;') + '\'>'
                + '<td>' + PvUI.esc(w.name) + '</td><td>' + PvUI.esc(w.gender) + '</td><td>' + PvUI.esc(w.age || '—') + '</td>'
                + '<td>' + PvUI.esc(w.modality) + '</td><td>' + PvUI.esc(w.item_name) + '</td><td>' + PvUI.esc(w.accession_no) + '</td>'
                + '<td class="pv-dim">' + PvUI.esc(w.registered_at || w.authored_at || '—') + '</td><td>' + PvUI.esc(wlStatusText(w)) + '</td>'
                + '<td>' + reg + acq + '</td></tr>';
        }).join('');
    }
    function loadWorklist(btn) {
        var body = document.getElementById('pvWorklistBody');
        var hint = document.getElementById('pvWorklistHint');
        if (body) body.innerHTML = '<tr data-row="1"><td colspan="9" class="pv-dim" style="text-align:center">加载中…</td></tr>';
        if (hint) { hint.style.display = 'none'; hint.textContent = ''; }
        if (btn) btn.disabled = true;
        return getJson('api/mock/worklist').then(function (j) {
            if (btn) btn.disabled = false;
            if (!j || j.code !== 200) { PvUI.toast((j && j.msg) || '加载失败', 'err'); wlAll = []; renderWorklist(); return; }
            var d = j.data || {};
            wlAll = d.list || [];
            if (d.hint && hint) { hint.style.display = ''; hint.textContent = d.hint; }
            renderWorklist();
        }).catch(function () { if (btn) btn.disabled = false; PvUI.toast('网络请求失败', 'err'); });
    }
    function bindWorklist() {
        var body = document.getElementById('pvWorklistBody');
        var refresh = document.getElementById('pvWorklistRefresh');
        var dateEl = document.getElementById('pvWorklistDate');
        var dateClear = document.getElementById('pvWorklistDateClear');
        if (refresh) refresh.addEventListener('click', function () { loadWorklist(refresh); });
        if (dateEl) dateEl.addEventListener('change', function () { wlDate = dateEl.value || ''; renderWorklist(); });
        if (dateClear) dateClear.addEventListener('click', function () { if (dateEl) dateEl.value = ''; wlDate = ''; renderWorklist(); });
        Array.prototype.forEach.call(document.querySelectorAll('#pvWorklistTabs [data-wl-tab]'), function (t) {
            t.addEventListener('click', function () {
                wlTab = t.getAttribute('data-wl-tab');
                Array.prototype.forEach.call(document.querySelectorAll('#pvWorklistTabs [data-wl-tab]'), function (x) { x.classList.toggle('active', x === t); });
                renderWorklist();
            });
        });
        if (!body) return;
        body.addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('[data-wl-reg],[data-wl-acq]') : null;
            if (!b) return;
            var tr = b.closest('tr');
            var w = {};
            try { w = JSON.parse(tr.getAttribute('data-wl') || '{}'); } catch (x) { w = {}; }
            if (b.hasAttribute('data-wl-reg')) {
                doRegister(w, b);
            } else {
                doAcquire(w, b);
            }
        });
    }
    function doRegister(w, btn) {
        btn.disabled = true;
        PvUI.post(PvNav.route('api/mock/register'), { task_id: w.task_id }).then(function (j) {
            btn.disabled = false;
            if (j && j.code === 200) { PvUI.toast(j.msg || '已登记', 'ok'); loadWorklist(); }
            else PvUI.toast((j && j.msg) || '登记失败', 'err');
        }).catch(function () { btn.disabled = false; PvUI.toast('网络请求失败', 'err'); });
    }
    function doAcquire(w, btn) {
        btn.disabled = true;
        PvUI.post(PvNav.route('api/mock/acquire'), {
            task_id: w.task_id, accession_no: w.accession_no, patient_id: w.patient_id,
            name: w.name, gender: w.gender, birth_date: w.birth_date, outpatient_no: w.outpatient_no,
            modality: w.modality, item_name: w.item_name
        }).then(function (j) {
            btn.disabled = false;
            if (j && j.code === 200) {
                var uid = (j.data && j.data.study_uid) || '';
                PvUI.toast('摄片完成' + (uid ? '（Study ' + uid + '）' : ''), 'ok');
                if (j.data && j.data.write_error) PvUI.toast('门诊状态回写提示：' + j.data.write_error, 'err');
                loadWorklist();
            } else PvUI.toast((j && j.msg) || '摄片失败', 'err');
        }).catch(function () { btn.disabled = false; PvUI.toast('网络请求失败', 'err'); });
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
            bindWorklist();
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
