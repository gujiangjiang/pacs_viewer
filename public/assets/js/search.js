/* ============================================================
 * assets/js/search.js — 检索页交互（PvPages.search）
 * ============================================================ */
(function (global) {
    'use strict';

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    var input, btn, box, empty, meta, onDocKey, clearChk;

    function render(list) {
        box.innerHTML = '';
        if (!list || !list.length) {
            empty.style.display = '';
            empty.querySelector('.pv-empty-title').textContent = '未找到匹配的检查记录';
            meta.style.display = 'none';
            return;
        }
        empty.style.display = 'none';
        meta.style.display = '';
        meta.textContent = '共找到 ' + list.length + ' 条已完成检查记录，点击卡片调阅影像';
        list.forEach(function (s) {
            var el = document.createElement('div');
            el.className = 'pv-study';
            el.innerHTML =
                '<div class="pv-study-head"><span class="pv-study-name">' + esc(s.name) + '</span>' +
                '<span class="pv-study-sex">' + esc(s.gender) + ' / ' + esc(s.age) + '</span>' +
                '<span class="pv-mod">' + esc(s.modality) + '</span></div>' +
                '<div class="pv-study-desc">' + esc(s.description || '影像检查') + '</div>' +
                '<div class="pv-study-rows">' +
                '<div><b>患者号：</b>' + esc(s.patient_id) + '　<b>门诊号：</b>' + esc(s.outpatient_no || '—') + '</div>' +
                '<div><b>检查号：</b>' + esc(s.accession_no || '—') + '</div>' +
                '<div><b>检查时间：</b>' + esc(s.study_date || '—') + '　<b>设备：</b>' + esc(s.station_name || '—') + '</div>' +
                '</div>' +
                '<div class="pv-study-foot"><span class="pv-study-status">' + esc(s.status_name || '已完成') + '</span>' +
                '<span class="pv-study-open">打开影像 →</span></div>';
            el.addEventListener('click', function () {
                var mode = (clearChk && clearChk.checked) ? 'replace' : 'append';
                global.PvNav.go('viewer', { uid: s.study_uid || s.accession_no, mode: mode });
            });
            box.appendChild(el);
        });
    }

    function doSearch() {
        btn.disabled = true;
        btn.textContent = '检索中…';
        empty.style.display = 'none';
        PvApi.search(input.value).then(function (j) {
            btn.disabled = false; btn.textContent = '检索';
            if (!j || j.code !== 200) {
                box.innerHTML = '';
                empty.style.display = '';
                var em = (j && j.msg) || '检索失败';
                empty.querySelector('.pv-empty-title').textContent = em;
                PvUI.toast(em, 'err');
                return;
            }
            var d = j.data || {};
            render(d.list || []);
            var modeEl = document.getElementById('pvMode');
            if (modeEl) {
                modeEl.textContent = d.remote ? '远程 PACS 接口' : '未配置 PACS 接口';
                modeEl.className = d.remote ? 'is-remote' : 'is-demo';
            }
        }).catch(function () {
            btn.disabled = false; btn.textContent = '检索';
            empty.style.display = '';
            empty.querySelector('.pv-empty-title').textContent = '网络请求失败';
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
                try { clearChk.checked = localStorage.getItem('pacs_clear_on_open') === '1'; } catch (e) {}
                clearChk.addEventListener('change', function () {
                    try { localStorage.setItem('pacs_clear_on_open', clearChk.checked ? '1' : '0'); } catch (e) {}
                });
            }
            btn.addEventListener('click', doSearch);
            onDocKey = function (e) {
                if (e.key === 'Enter' && document.activeElement === input) { e.preventDefault(); doSearch(); }
            };
            input.addEventListener('keydown', onDocKey);
            input.focus();
        },
        destroy: function () {
            if (input && onDocKey) input.removeEventListener('keydown', onDocKey);
            input = btn = box = empty = meta = onDocKey = null;
        }
    };
})(window);
