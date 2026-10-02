/* ============================================================
 * assets/js/search.js — 检索页交互（PvPages.search，左右分栏 + 条件筛选）
 * ============================================================ */
(function (global) {
    'use strict';

    var esc = PvUI.esc;

    var input, btn, box, empty, meta, onDocKey, clearChk;
    var sideFilters = { gender: '', modality: '', dateMode: 'all' };
    var viewMode = 'list';

    var PAGE = 30;
    var allItems = [], total = 0, hasMore = false, loading = false, query = '', sentinelEl = null, moreIO = null;

    /* ---------- 日期工具 ---------- */
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function fmtDate(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function today() { var d = new Date(); return new Date(d.getFullYear(), d.getMonth(), d.getDate()); }
    function addDays(d, n) { var x = new Date(d.getTime()); x.setDate(x.getDate() + n); return x; }
    function computeRange(mode) {
        if (mode === 'all') return { from: '', to: '' };
        var t = today();
        if (mode === 'today') return { from: fmtDate(t), to: fmtDate(t) };
        if (mode === '3d') return { from: fmtDate(addDays(t, -2)), to: fmtDate(t) };
        if (mode === 'week') return { from: fmtDate(addDays(t, -6)), to: fmtDate(t) };
        if (mode === 'year') return { from: fmtDate(addDays(t, -364)), to: fmtDate(t) };
        return null;   // custom
    }

    /* ---------- 列表项 ---------- */
    function makeItem(s) {
        var hasImg = s.has_images !== false;
        var el = document.createElement('div');
        el.className = 'pv-study' + (hasImg ? '' : ' is-noimg');
        el.innerHTML =
            '<div class="pv-study-head"><span class="pv-study-name">' + esc(s.name) + '</span>' +
            '<span class="pv-study-sex">' + esc(s.gender) + ' / ' + esc(s.age) + '</span>' +
            (s.fhir ? '<span class="pv-src-tag" title="患者信息来自 FHIR R4 补充">FHIR</span>' : '') +
            '<span class="pv-mod">' + esc(s.modality) + '</span></div>' +
            '<div class="pv-study-desc">' + esc(s.description || '影像检查') + '</div>' +
            '<div class="pv-study-rows">' +
            '<div><b>患者号：</b>' + esc(s.patient_id) + '　<b>门诊号：</b>' + esc(s.outpatient_no || '—') + '</div>' +
            '<div><b>检查号：</b>' + esc(s.accession_no || '—') + '</div>' +
            '<div><b>检查时间：</b>' + esc(s.study_date || '—') + '　<b>设备：</b>' + esc(s.station_name || '—') + '</div>' +
            '</div>' +
            '<div class="pv-study-foot"><span class="pv-study-status">' + esc(s.status_name || '已完成') + '</span>' +
            '<span class="pv-study-open">' + (hasImg ? '打开影像 →' : '暂无影像') + '</span></div>';
        el.addEventListener('click', function () {
            if (!hasImg) { PvUI.toast('该检查仅有登记信息，暂无影像数据', 'err'); return; }
            var mode = (clearChk && clearChk.checked) ? 'replace' : 'append';
            global.PvNav.go('viewer', { uid: s.study_uid || s.accession_no, mode: mode });
        });
        return el;
    }

    function setMeta() {
        if (!meta) return;
        meta.style.display = '';
        var fhirOnly = 0;
        allItems.forEach(function (s) { if (s.has_images === false) fhirOnly++; });
        meta.textContent = (total > 0 ? '已加载 ' + allItems.length + ' / 共 ' + total + ' 条检查记录' : '已加载 ' + allItems.length + ' 条检查记录')
            + (fhirOnly ? '（含 ' + fhirOnly + ' 条仅登记·暂无影像）' : '')
            + (hasMore ? '，向下滚动加载更多' : '');
    }

    function clearMore() {
        if (moreIO) { moreIO.disconnect(); moreIO = null; }
        if (sentinelEl && sentinelEl.parentNode) sentinelEl.parentNode.removeChild(sentinelEl);
        sentinelEl = null;
    }

    function placeSentinel() {
        if (!hasMore) { clearMore(); return; }
        if (!sentinelEl) { sentinelEl = document.createElement('div'); sentinelEl.className = 'pv-more'; }
        sentinelEl.textContent = '加载更多…';
        box.appendChild(sentinelEl);
        if (global.IntersectionObserver) {
            if (!moreIO) {
                moreIO = new global.IntersectionObserver(function (en) {
                    if (en[0] && en[0].isIntersecting) loadMore();
                }, { rootMargin: '400px' });
            }
            moreIO.observe(sentinelEl);
        }
    }

    function modifyResultsClass() {
        box.className = 'pv-results ' + (viewMode === 'card' ? 'pv-results-card' : 'pv-results-list');
    }

    function paint() {
        clearMore();
        modifyResultsClass();
        box.innerHTML = '';
        if (!allItems.length) {
            empty.style.display = '';
            empty.querySelector('.pv-empty-title').textContent = '未找到匹配的检查记录';
            meta.style.display = 'none';
            return;
        }
        empty.style.display = 'none';
        allItems.forEach(function (s) { box.appendChild(makeItem(s)); });
        setMeta();
        placeSentinel();
    }

    function currentFilters() {
        return {
            gender: sideFilters.gender || '',
            modality: sideFilters.modality || '',
            date_from: input && document.getElementById('pvDateFrom') ? document.getElementById('pvDateFrom').value : '',
            date_to: input && document.getElementById('pvDateTo') ? document.getElementById('pvDateTo').value : ''
        };
    }

    function loadMore() {
        if (loading || !hasMore) return;
        loading = true;
        if (sentinelEl) sentinelEl.textContent = '加载中…';
        var opts = { limit: PAGE, offset: allItems.length };
        var f = currentFilters();
        for (var k in f) if (f[k]) opts[k] = f[k];
        PvApi.search(query, opts).then(function (j) {
            loading = false;
            if (!j || j.code !== 200) { PvUI.toast((j && j.msg) || '加载失败', 'err'); if (sentinelEl) sentinelEl.textContent = '加载更多…'; return; }
            var d = j.data || {}, list = d.list || [];
            total = (typeof d.total === 'number') ? d.total : (allItems.length + list.length);
            hasMore = !!d.has_more;
            list.forEach(function (s) { allItems.push(s); box.appendChild(makeItem(s)); });
            setMeta();
            placeSentinel();
            saveState();
        }).catch(function () {
            loading = false;
            PvUI.toast('网络请求失败', 'err');
            if (sentinelEl) sentinelEl.textContent = '加载更多…';
        });
    }

    function saveState() {
        try {
            sessionStorage.setItem('pacs_search_v2', JSON.stringify({
                kw: query || '', list: allItems, total: total, has_more: hasMore,
                gender: sideFilters.gender, modality: sideFilters.modality, dateMode: sideFilters.dateMode,
                from: document.getElementById('pvDateFrom') ? document.getElementById('pvDateFrom').value : '',
                to: document.getElementById('pvDateTo') ? document.getElementById('pvDateTo').value : '',
                view: viewMode
            }));
        } catch (e) {}
    }

    /* ---------- 筛选器 ---------- */
    function setActiveChip(container, attr, val) {
        if (!container) return;
        Array.prototype.forEach.call(container.querySelectorAll('.pv-chip'), function (c) {
            c.classList.toggle('active', (c.getAttribute(attr) || '') === val);
        });
    }

    function applyDateMode(mode) {
        sideFilters.dateMode = mode;
        var fromEl = document.getElementById('pvDateFrom'), toEl = document.getElementById('pvDateTo');
        setActiveChip(document.getElementById('pvDateChips'), 'data-range', mode);
        var r = computeRange(mode);
        if (r === null) {          // custom：解锁，保留当前值
            if (fromEl) fromEl.disabled = false;
            if (toEl) toEl.disabled = false;
        } else if (mode === 'all') {
            if (fromEl) { fromEl.value = ''; fromEl.disabled = true; }
            if (toEl) { toEl.value = ''; toEl.disabled = true; }
        } else {
            if (fromEl) { fromEl.value = r.from; fromEl.disabled = true; }
            if (toEl) { toEl.value = r.to; toEl.disabled = true; }
        }
        validateDates();
    }

    function validateDates() {
        var errEl = document.getElementById('pvDateErr');
        var fromEl = document.getElementById('pvDateFrom'), toEl = document.getElementById('pvDateTo');
        if (!fromEl || !toEl) return true;
        // 无论锁定/解锁，始终保证 from <= to（自定义时若反向则报错拦截）
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
        }).catch(function () {});
    }

    function bindViewToggle() {
        var wrap = document.getElementById('pvViewToggle');
        if (!wrap) return;
        Array.prototype.forEach.call(wrap.querySelectorAll('.pv-toggle-btn'), function (b) {
            b.addEventListener('click', function () {
                viewMode = b.getAttribute('data-view') === 'card' ? 'card' : 'list';
                Array.prototype.forEach.call(wrap.querySelectorAll('.pv-toggle-btn'), function (x) {
                    x.classList.toggle('active', x === b);
                });
                paint();
                saveState();
            });
        });
        // 初始化按钮状态
        Array.prototype.forEach.call(wrap.querySelectorAll('.pv-toggle-btn'), function (x) {
            x.classList.toggle('active', x.getAttribute('data-view') === viewMode);
        });
    }

    /* ---------- 检索 ---------- */
    function doSearch() {
        if (!validateDates()) return;
        btn.disabled = true;
        btn.classList.add('is-loading');
        empty.style.display = 'none';
        query = input.value;
        allItems = []; total = 0; hasMore = false;
        var opts = { limit: PAGE, offset: 0 };
        var f = currentFilters();
        for (var k in f) if (f[k]) opts[k] = f[k];
        PvApi.search(query, opts).then(function (j) {
            btn.disabled = false; btn.classList.remove('is-loading');
            if (!j || j.code !== 200) {
                box.innerHTML = ''; clearMore();
                empty.style.display = '';
                var em = (j && j.msg) || '检索失败';
                empty.querySelector('.pv-empty-title').textContent = em;
                meta.style.display = 'none';
                PvUI.toast(em, 'err');
                return;
            }
            var d = j.data || {};
            allItems = d.list || [];
            total = (typeof d.total === 'number') ? d.total : allItems.length;
            hasMore = !!d.has_more;
            paint();
            saveState();
            var modeEl = document.getElementById('pvMode');
            if (modeEl && d.source) {
                modeEl.textContent = d.source.label || '';
                modeEl.className = d.source.state !== 'unset' ? 'is-remote' : 'is-demo';
            }
            if (d.fhir_error) PvUI.toast('FHIR 补充失败：' + d.fhir_error, 'err');
        }).catch(function () {
            btn.disabled = false; btn.classList.remove('is-loading');
            box.innerHTML = ''; clearMore();
            empty.style.display = '';
            empty.querySelector('.pv-empty-title').textContent = '网络请求失败';
            meta.style.display = 'none';
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

            // 恢复本会话状态
            var saved = null;
            try { saved = JSON.parse(sessionStorage.getItem('pacs_search_v2')); } catch (e) {}
            if (saved) {
                viewMode = saved.view === 'card' ? 'card' : 'list';
                sideFilters.gender = saved.gender || '';
                sideFilters.modality = saved.modality || '';
                sideFilters.dateMode = saved.dateMode || 'all';
                setActiveChip(document.getElementById('pvGenderChips'), 'data-gender', sideFilters.gender);
                // 恢复日期：先按 mode 计算，custom 时用保存值
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
                paint();
            } else {
                paint();
                doSearch();   // 首次进入即列出（按检查时间倒序）
            }
            input.focus();
        },
        destroy: function () {
            if (input && onDocKey) input.removeEventListener('keydown', onDocKey);
            clearMore();
            allItems = []; total = 0; hasMore = false; loading = false;
            input = btn = box = empty = meta = onDocKey = null;
        }
    };
})(window);
