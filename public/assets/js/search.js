/* ============================================================
 * assets/js/search.js — 检索页交互（PvPages.search）
 * ============================================================ */
(function (global) {
    'use strict';

    var esc = PvUI.esc;   // 复用通用转义助手，避免重复实现

    var input, btn, box, empty, meta, onDocKey, clearChk;

    /* 服务端分页 + 视口触发续载（流式），配合 content-visibility 虚拟化 */
    var PAGE = 30;
    var allItems = [], total = 0, hasMore = false, loading = false, query = '', sentinelEl = null, moreIO = null;

    function makeCard(s) {
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
            + (hasMore ? '，向下滚动加载更多' : '，点击卡片调阅影像');
    }

    function clearMore() {
        if (moreIO) { moreIO.disconnect(); moreIO = null; }
        if (sentinelEl && sentinelEl.parentNode) sentinelEl.parentNode.removeChild(sentinelEl);
        sentinelEl = null;
    }

    function placeSentinel() {
        if (!hasMore) { clearMore(); return; }
        if (!sentinelEl) {
            sentinelEl = document.createElement('div');
            sentinelEl.className = 'pv-more';
        }
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

    function paint() {
        clearMore();
        box.innerHTML = '';
        if (!allItems.length) {
            empty.style.display = '';
            empty.querySelector('.pv-empty-title').textContent = '未找到匹配的检查记录';
            meta.style.display = 'none';
            return;
        }
        empty.style.display = 'none';
        allItems.forEach(function (s) { box.appendChild(makeCard(s)); });
        setMeta();
        placeSentinel();
    }

    /** 加载下一页（流式追加） */
    function loadMore() {
        if (loading || !hasMore) return;
        loading = true;
        if (sentinelEl) sentinelEl.textContent = '加载中…';
        PvApi.search(query, { limit: PAGE, offset: allItems.length }).then(function (j) {
            loading = false;
            if (!j || j.code !== 200) { PvUI.toast((j && j.msg) || '加载失败', 'err'); if (sentinelEl) sentinelEl.textContent = '加载更多…'; return; }
            var d = j.data || {}, list = d.list || [];
            total = (typeof d.total === 'number') ? d.total : (allItems.length + list.length);
            hasMore = !!d.has_more;
            list.forEach(function (s) { allItems.push(s); box.appendChild(makeCard(s)); });
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
        try { sessionStorage.setItem('pacs_search_v1', JSON.stringify({ kw: query || '', list: allItems, total: total, has_more: hasMore })); } catch (e) {}
    }
    function loadState() {
        try { return JSON.parse(sessionStorage.getItem('pacs_search_v1')); } catch (e) { return null; }
    }

    function doSearch() {
        btn.disabled = true;
        btn.classList.add('is-loading');
        empty.style.display = 'none';
        query = input.value;
        allItems = []; total = 0; hasMore = false;
        PvApi.search(query, { limit: PAGE, offset: 0 }).then(function (j) {
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
                // 优先使用本会话内用户的最新选择：静态页片段会被前端缓存，若直接用
                // 缓存里的旧值会导致「取消勾选后又自动勾上」。
                var initial = (typeof global.PvClearOnOpen === 'boolean') ? global.PvClearOnOpen : !!data.clearOnOpen;
                clearChk.checked = initial;
                global.PvClearOnOpen = initial;
                clearChk.addEventListener('change', function () {
                    global.PvClearOnOpen = clearChk.checked;   // 立即记忆，跨标签切换保持
                    PvUI.post(global.PvNav.route('api/pref'), { clear_on_open: clearChk.checked ? '1' : '0' });
                });
            }
            btn.addEventListener('click', doSearch);
            onDocKey = function (e) {
                if (e.key === 'Enter' && document.activeElement === input) { e.preventDefault(); doSearch(); }
            };
            input.addEventListener('keydown', onDocKey);
            // 恢复本会话上次的检索结果（含已加载分页）
            var saved = loadState();
            if (saved && typeof saved.kw === 'string') {
                input.value = saved.kw;
                query = saved.kw;
                allItems = saved.list || [];
                total = (typeof saved.total === 'number') ? saved.total : allItems.length;
                hasMore = !!saved.has_more;
                paint();
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
