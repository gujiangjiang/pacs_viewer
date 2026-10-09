/* ============================================================
 * assets/js/search.js — 患者查询页交互（左右分栏 + 条件筛选 + 三种视图）
 * 视图：纯列表（表格，默认）/ 紧凑列表 / 卡片；均支持向下滚动加载更多。
 * 交互：单击选中，双击打开影像。
 * ============================================================ */
(function (global) {
    'use strict';

    var esc = PvUI.esc;

    var input, btn, box, empty, meta, onDocKey, clearChk, loader = null;
    var sideFilters = { gender: '', modality: '', dateMode: 'all' };
    var viewMode = 'table';
    var sortState = { key: 'study_date', dir: 'desc' };
    var selectedKey = null;

    var PAGE = 30;
    var allItems = [], total = 0, hasMore = false, query = '';

    // 触摸端无可靠 dblclick：用双触（pointerup）兜底；并防抖避免双击/双触重复打开
    var lastTapAt = 0, lastTapKey = '';
    var lastOpenAt = 0, lastOpenKey = '';
    function isDoubleTap(e, key) {
        if (!e || (e.pointerType && e.pointerType !== 'touch')) return false;   // 仅触摸端接管
        var now = Date.now();
        if (now - lastTapAt < 320 && lastTapKey === key) { lastTapAt = 0; lastTapKey = ''; return true; }
        lastTapAt = now; lastTapKey = key; return false;
    }

    // 列顺序：重要信息靠前
    var COLUMNS = [
        { key: 'name', label: '姓名' },
        { key: 'gender', label: '性别' },
        { key: 'age', label: '年龄', sort: 'birth_date' },
        { key: 'modality', label: '类型' },
        { key: 'description', label: '检查项目' },
        { key: 'accession_no', label: '检查号' },
        { key: 'study_date', label: '检查时间' },
        { key: 'patient_id', label: '患者号' },
        { key: 'outpatient_no', label: '门诊号' },
        { key: 'station_name', label: '设备' },
        { key: 'status_name', label: '状态', nosort: true }
    ];

    /* ---------- 日期工具（格式化复用 PvFmt） ---------- */
    function today() { var d = new Date(); return new Date(d.getFullYear(), d.getMonth(), d.getDate()); }
    function addDays(d, n) { var x = new Date(d.getTime()); x.setDate(x.getDate() + n); return x; }
    function computeRange(mode) {
        if (mode === 'all') return { from: '', to: '' };
        var t = today();
        if (mode === 'today') return { from: PvFmt.date(t), to: PvFmt.date(t) };
        if (mode === '3d') return { from: PvFmt.date(addDays(t, -2)), to: PvFmt.date(t) };
        if (mode === 'week') return { from: PvFmt.date(addDays(t, -6)), to: PvFmt.date(t) };
        if (mode === 'year') return { from: PvFmt.date(addDays(t, -364)), to: PvFmt.date(t) };
        return null;
    }

    /* ---------- 打开 / 选中 ---------- */
    function openItem(s) {
        if (s.has_images === false) { PvUI.toast('该检查仅有登记信息，暂无影像数据', 'err'); return; }
        var key = s.study_uid || s.accession_no;
        var now = Date.now();
        if (key === lastOpenKey && now - lastOpenAt < 700) return;   // 双击/双触只打开一次
        lastOpenAt = now; lastOpenKey = key;
        var mode = (clearChk && clearChk.checked) ? 'replace' : 'append';
        global.PvNav.go('viewer', { uid: key, mode: mode });
    }
    function selectItem(s, el) {
        selectedKey = s.study_uid || s.accession_no;
        Array.prototype.forEach.call(box.querySelectorAll('.selected'), function (x) { x.classList.remove('selected'); });
        if (el) el.classList.add('selected');
    }

    /* ---------- 卡片 / 紧凑列表项 ---------- */
    function makeItem(s) {
        var hasImg = s.has_images !== false;
        var el = document.createElement('div');
        el.className = 'pv-study' + (hasImg ? '' : ' is-noimg') + ((s.study_uid || s.accession_no) === selectedKey ? ' selected' : '');
        el.innerHTML =
            '<div class="pv-study-head">' +
            '<div class="pv-study-id">' +
            '<span class="pv-study-name">' + esc(s.name) + '</span>' +
            '<span class="pv-study-sex">' + esc(s.gender) + ' / ' + esc(s.age) + '</span>' +
            (s.fhir ? '<span class="pv-src-tag" title="患者信息来自 FHIR R4 补充">FHIR</span>' : '') +
            '</div>' +
            '<span class="pv-study-status' + (hasImg ? '' : ' is-noimg') + '">' + esc(s.status_name || '已完成') + '</span>' +
            '</div>' +
            '<div class="pv-study-desc"><span>' + esc(s.description || '影像检查') + '</span>' +
            '<span class="pv-mod">' + esc(s.modality) + '</span></div>' +
            '<div class="pv-study-rows">' +
            '<div><b>患者号：</b>' + esc(s.patient_id) + '　<b>门诊号：</b>' + esc(s.outpatient_no || '—') + '</div>' +
            '<div><b>检查号：</b>' + esc(s.accession_no || '—') + '</div>' +
            '<div><b>检查时间：</b>' + esc(s.study_date || '—') + '　<b>设备：</b>' + esc(s.station_name || '—') + '</div>' +
            '</div>';
        el.addEventListener('click', function () { selectItem(s, el); });
        el.addEventListener('dblclick', function () { openItem(s); });
        el.addEventListener('pointerup', function (e) {   // 触摸端双触兜底
            if (isDoubleTap(e, s.study_uid || s.accession_no)) openItem(s);
        });
        return el;
    }

    /* ---------- 纯列表（表格） ---------- */
    function attr(key) { return key.replace(/[^a-z0-9_]/gi, ''); }
    function cellHtml(s, c) {
        if (c.key === 'status_name') return '<span class="pv-study-status' + (s.has_images === false ? ' is-noimg' : '') + '">' + esc(s.status_name || '已完成') + '</span>';
        var v = s[c.key];
        if (c.key === 'summary' || v === undefined || v === null || v === '') v = '—';
        return esc(v);
    }
    function buildTable(list) {
        var tbl = document.createElement('table');
        tbl.className = 'pv-table pv-result-table';
        var h = '<thead><tr>';
        COLUMNS.forEach(function (c) {
            var sk = c.sort || c.key;
            var active = !c.nosort && sortState.key === sk;
            var arrow = active ? '<span class="pv-sort-arrow ' + (sortState.dir === 'asc' ? 'up' : 'down') + '"></span>' : '';
            h += '<th class="' + (c.nosort ? '' : 'sortable' + (active ? ' active' : '')) + '"' +
                (c.nosort ? '' : ' data-key="' + attr(sk) + '"') + '>' + esc(c.label) + arrow + '</th>';
        });
        h += '</tr></thead>';
        var b = '<tbody>';
        list.forEach(function (s, i) {
            b += '<tr data-i="' + i + '"' + ((s.study_uid || s.accession_no) === selectedKey ? ' class="selected"' : '') + '>';
            COLUMNS.forEach(function (c) { b += '<td>' + cellHtml(s, c) + '</td>'; });
            b += '</tr>';
        });
        b += '</tbody>';
        tbl.innerHTML = h + b;
        tbl.addEventListener('click', function (e) {
            var tr = e.target.closest ? e.target.closest('tbody tr') : null;
            if (!tr) return;
            var i = parseInt(tr.getAttribute('data-i'), 10);
            if (allItems[i]) selectItem(allItems[i], tr);
        });
        tbl.addEventListener('dblclick', function (e) {
            var tr = e.target.closest ? e.target.closest('tbody tr') : null;
            if (!tr) return;
            var i = parseInt(tr.getAttribute('data-i'), 10);
            if (allItems[i]) openItem(allItems[i]);
        });
        tbl.addEventListener('pointerup', function (e) {   // 触摸端双触兜底
            var tr = e.target.closest ? e.target.closest('tbody tr') : null;
            if (!tr) return;
            var i = parseInt(tr.getAttribute('data-i'), 10);
            var s = allItems[i];
            if (s && isDoubleTap(e, s.study_uid || s.accession_no)) openItem(s);
        });
        tbl.addEventListener('click', function (e) {
            var th = e.target.closest ? e.target.closest('th.sortable') : null;
            if (!th) return;
            var key = th.getAttribute('data-key');
            if (!key) return;
            if (sortState.key === key) sortState.dir = (sortState.dir === 'asc' ? 'desc' : 'asc');
            else { sortState.key = key; sortState.dir = 'desc'; }
            doSearch();
        });
        // 宽表格在独立容器内横向滚动，避免撑宽页面；纵向仍随页面滚动
        var wrap = document.createElement('div');
        wrap.className = 'pv-results-table-wrap';
        wrap.appendChild(tbl);
        return wrap;
    }

    function setMeta() {
        if (!meta) return;
        meta.style.display = '';
        meta.textContent = (total > 0 ? '已加载 ' + allItems.length + ' / 共 ' + total + ' 条检查记录' : '已加载 ' + allItems.length + ' 条检查记录')
            + (hasMore ? '，向下滚动加载更多' : '');
    }

    /** 首次加载 / 重新检索后，重置通用滚动加载器（患者列表） */
    function resetLoader() {
        if (loader) loader.destroy();
        if (!global.PvInfiniteScroll) { loader = null; return; }
        loader = global.PvInfiniteScroll.paged({
            container: box, list: box,
            offset: allItems.length, hasMore: hasMore, pageSize: PAGE,
            sentinelClass: 'pv-more', moreText: '加载更多…', endText: '',
            request: function (offset, size) {
                var opts = { limit: size, offset: offset };
                var f = currentFilters();
                for (var k in f) if (f[k]) opts[k] = f[k];
                return PvApi.search(query, opts);
            },
            append: function (list) {
                list.forEach(function (s) { allItems.push(s); });
                renderAll();
            },
            onState: function (st) {
                hasMore = st.hasMore;
                if (typeof st.total === 'number') total = st.total;
                setMeta();
                saveState();
            },
            onError: function (msg) { PvUI.toast(msg, 'err'); }
        });
    }

    function renderAll() {
        box.className = 'pv-results ' + (viewMode === 'table' ? 'pv-results-table' : (viewMode === 'card' ? 'pv-results-card' : 'pv-results-list'));
        box.innerHTML = '';
        if (!allItems.length) {
            box.style.display = 'none';
            if (empty) {
                empty.style.display = '';
                empty.querySelector('.pv-empty-title').textContent = '未找到匹配的检查记录';
            }
            meta.textContent = '未找到匹配的检查记录';
            meta.style.display = '';
            return;
        }
        box.style.display = '';
        if (empty) empty.style.display = 'none';
        if (viewMode === 'table') {
            box.appendChild(buildTable(allItems));
        } else {
            allItems.forEach(function (s) { box.appendChild(makeItem(s)); });
        }
        setMeta();
        if (loader) loader.reattach();   // 重建列表 DOM 后重新挂载哨兵
    }

    function currentFilters() {
        return {
            gender: sideFilters.gender || '',
            modality: sideFilters.modality || '',
            date_from: document.getElementById('pvDateFrom') ? document.getElementById('pvDateFrom').value : '',
            date_to: document.getElementById('pvDateTo') ? document.getElementById('pvDateTo').value : '',
            sort: sortState.key || '',
            dir: sortState.dir || 'desc'
        };
    }

    function saveState() {
        try {
            sessionStorage.setItem('pacs_search_v3', JSON.stringify({
                kw: query || '', list: allItems, total: total, has_more: hasMore,
                gender: sideFilters.gender, modality: sideFilters.modality, dateMode: sideFilters.dateMode,
                from: document.getElementById('pvDateFrom') ? document.getElementById('pvDateFrom').value : '',
                to: document.getElementById('pvDateTo') ? document.getElementById('pvDateTo').value : '',
                view: viewMode, sort: sortState
            }));
        } catch (e) {}
    }

    /* ---------- 筛选器 ---------- */
    function setActiveChip(container, attrName, val) {
        if (!container) return;
        Array.prototype.forEach.call(container.querySelectorAll('.pv-chip'), function (c) {
            c.classList.toggle('active', (c.getAttribute(attrName) || '') === val);
        });
    }

    function applyDateMode(mode) {
        sideFilters.dateMode = mode;
        var fromEl = document.getElementById('pvDateFrom'), toEl = document.getElementById('pvDateTo');
        setActiveChip(document.getElementById('pvDateChips'), 'data-range', mode);
        var r = computeRange(mode);
        if (r === null) {
            if (fromEl) fromEl.disabled = false;
            if (toEl) toEl.disabled = false;
        } else if (mode === 'all') {
            if (fromEl) { fromEl.value = ''; fromEl.disabled = true; }
            if (toEl) { toEl.value = ''; toEl.disabled = true; }
        } else {
            if (fromEl) { fromEl.value = r.from; fromEl.disabled = true; }
            if (toEl) { toEl.value = r.to; toEl.disabled = true; }
        }
        if (global.PvControls) { global.PvControls.sync(fromEl); global.PvControls.sync(toEl); }
        validateDates();
    }

    function validateDates() {
        var errEl = document.getElementById('pvDateErr');
        var fromEl = document.getElementById('pvDateFrom'), toEl = document.getElementById('pvDateTo');
        if (!fromEl || !toEl) return true;
        if (fromEl.value && toEl.value && fromEl.value > toEl.value) {
            if (errEl) { errEl.textContent = '起始日期不能晚于截止日期'; errEl.style.display = ''; }
            if (btn) btn.disabled = true;
            return false;
        }
        if (errEl) errEl.style.display = 'none';
        if (btn && !btn.classList.contains('is-loading')) btn.disabled = false;
        return true;
    }

    function bindFilters() {
        var gEl = document.getElementById('pvGenderChips');
        if (gEl) gEl.addEventListener('click', function (e) {
            var c = e.target.closest ? e.target.closest('.pv-chip') : null;
            if (!c) return;
            sideFilters.gender = c.getAttribute('data-gender') || '';
            setActiveChip(gEl, 'data-gender', sideFilters.gender);
            doSearch();
        });
        var dEl = document.getElementById('pvDateChips');
        if (dEl) dEl.addEventListener('click', function (e) {
            var c = e.target.closest ? e.target.closest('.pv-chip') : null;
            if (!c) return;
            applyDateMode(c.getAttribute('data-range') || 'all');
            doSearch();
        });
        ['pvDateFrom', 'pvDateTo'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.addEventListener('change', function () { if (validateDates()) doSearch(); });
        });
        var mEl = document.getElementById('pvModality');
        if (mEl) mEl.addEventListener('change', function () { sideFilters.modality = mEl.value; doSearch(); });
    }

    function loadFacets() {
        var mEl = document.getElementById('pvModality');
        if (!mEl || !PvApi.facets) return;
        PvApi.facets().then(function (j) {
            if (!j || j.code !== 200) return;
            var list = (j.data && j.data.modalities) || [];
            var html = '<option value="">全部</option>';
            list.forEach(function (m) { html += '<option value="' + esc(m) + '">' + esc(m) + '</option>'; });
            mEl.innerHTML = html;
            if (sideFilters.modality) mEl.value = sideFilters.modality;
            if (global.PvControls) global.PvControls.sync(mEl);
        }).catch(function () {});
    }

    function bindViewToggle() {
        var wrap = document.getElementById('pvViewToggle');
        if (!wrap) return;
        Array.prototype.forEach.call(wrap.querySelectorAll('.pv-toggle-btn'), function (b) {
            b.classList.toggle('active', b.getAttribute('data-view') === viewMode);
            b.addEventListener('click', function () {
                viewMode = b.getAttribute('data-view') || 'table';
                Array.prototype.forEach.call(wrap.querySelectorAll('.pv-toggle-btn'), function (x) {
                    x.classList.toggle('active', x === b);
                });
                renderAll();
                saveState();
                // 视图偏好随用户保存到数据库（下次自动启用）
                if (global.PvNav) PvUI.post(global.PvNav.route('api/pref'), { search_view: viewMode });
            });
        });
    }

    /* ---------- 检索 ---------- */
    function doSearch() {
        if (!validateDates()) return;
        btn.disabled = true;
        btn.classList.add('is-loading');
        box.style.display = '';
        if (empty) empty.style.display = 'none';
        query = input.value;
        allItems = []; total = 0; hasMore = false;
        if (loader) { loader.destroy(); loader = null; }
        meta.textContent = '';
        var opts = { limit: PAGE, offset: 0 };
        var f = currentFilters();
        for (var k in f) if (f[k]) opts[k] = f[k];
        PvApi.search(query, opts).then(function (j) {
            btn.disabled = false; btn.classList.remove('is-loading');
            if (!j || j.code !== 200) {
                box.innerHTML = '';
                box.style.display = 'none';
                var em = (j && j.msg) || '检索失败';
                if (empty) { empty.style.display = ''; empty.querySelector('.pv-empty-title').textContent = em; }
                meta.textContent = em;
                meta.style.display = '';
                PvUI.toast(em, 'err');
                return;
            }
            var d = j.data || {};
            allItems = d.list || [];
            total = (typeof d.total === 'number') ? d.total : allItems.length;
            hasMore = !!d.has_more;
            renderAll();
            resetLoader();
            saveState();
            var srcEl = document.getElementById('pvFooterSource');
            if (srcEl && d.source) {
                srcEl.textContent = d.source.label || '';
                srcEl.className = 'pv-footer-src ' + (d.source.state !== 'unset' ? 'is-ok' : 'is-off');
            }
            if (d.fhir_error) PvUI.toast('FHIR 补充失败：' + d.fhir_error, 'err');
        }).catch(function () {
            btn.disabled = false; btn.classList.remove('is-loading');
            box.innerHTML = '';
            box.style.display = 'none';
            if (empty) { empty.style.display = ''; empty.querySelector('.pv-empty-title').textContent = '网络请求失败'; }
            meta.textContent = '网络请求失败';
            meta.style.display = '';
            PvUI.toast('网络请求失败', 'err');
        });
    }

    global.PvPages = global.PvPages || {};
    global.PvPages.search = {
        init: function (data) {
            data = data || {};
            input = document.getElementById('pvKeyword');
            btn = document.getElementById('pvSearchBtn');
            box = document.getElementById('pvResults');
            empty = document.getElementById('pvEmpty');
            meta = document.getElementById('pvResultMeta');
            if (!input || !btn || !box) return;
            if (data.flash) PvUI.toast(data.flash, 'ok');

            clearChk = document.getElementById('pvClearOnOpen');
            if (clearChk) {
                var initial = (typeof global.PvClearOnOpen === 'boolean') ? global.PvClearOnOpen : !!data.clearOnOpen;
                clearChk.checked = initial;
                global.PvClearOnOpen = initial;
                clearChk.addEventListener('change', function () {
                    global.PvClearOnOpen = clearChk.checked;
                    PvUI.post(global.PvNav.route('api/pref'), { clear_on_open: clearChk.checked ? '1' : '0' });
                });
            }

            bindFilters();
            loadFacets();

            // 视图偏好以数据库（用户级）为准，默认纯列表
            viewMode = (data.searchView === 'card' || data.searchView === 'list') ? data.searchView : 'table';
            var saved = null;
            try { saved = JSON.parse(sessionStorage.getItem('pacs_search_v3')); } catch (e) {}
            if (saved) {
                if (saved.sort && saved.sort.key) sortState = saved.sort;
                sideFilters.gender = saved.gender || '';
                sideFilters.modality = saved.modality || '';
                sideFilters.dateMode = saved.dateMode || 'all';
                setActiveChip(document.getElementById('pvGenderChips'), 'data-gender', sideFilters.gender);
                applyDateMode(sideFilters.dateMode);
                if (sideFilters.dateMode === 'custom') {
                    var fe = document.getElementById('pvDateFrom'), te = document.getElementById('pvDateTo');
                    if (fe && typeof saved.from === 'string') fe.value = saved.from;
                    if (te && typeof saved.to === 'string') te.value = saved.to;
                }
                if (saved.kw) input.value = saved.kw;
            }
            bindViewToggle();

            onDocKey = function (e) {
                if (e.key === 'Enter' && document.activeElement === input) { e.preventDefault(); doSearch(); }
            };
            input.addEventListener('keydown', onDocKey);
            btn.addEventListener('click', doSearch);

            if (saved && typeof saved.kw === 'string') {
                query = saved.kw;
                allItems = saved.list || [];
                total = (typeof saved.total === 'number') ? saved.total : allItems.length;
                hasMore = !!saved.has_more;
                renderAll();
                resetLoader();
            } else {
                renderAll();
                doSearch();
            }
            if (global.PvControls) global.PvControls.init(document);   // 增强下拉 / 日期控件
            input.focus();
        },
        destroy: function () {
            if (input && onDocKey) input.removeEventListener('keydown', onDocKey);
            if (loader) { loader.destroy(); loader = null; }
            allItems = []; total = 0; hasMore = false;
            input = btn = box = empty = meta = onDocKey = null;
        }
    };
})(window);
