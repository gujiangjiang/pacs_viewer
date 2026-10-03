/* ============================================================
 * assets/js/api.js — 外部接口请求封装（模块）
 * 所有患者 / 检查数据统一经此模块从后端 PACS 接口获取。
 * ============================================================ */
(function (global) {
    'use strict';
    var boot = global.PV_BOOT || {};
    var base = boot.api || '?r=api';

    function buildUrl(sub, params) {
        var u = base.replace(/r=api(\b|$)/, 'r=api/' + sub);
        var q = [];
        params = params || {};
        for (var k in params) {
            if (params[k] === undefined || params[k] === null) continue;
            q.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
        }
        if (q.length) u += (u.indexOf('?') < 0 ? '?' : '&') + q.join('&');
        return u;
    }
    function get(sub, params) {
        // 访客阅片：随请求附带签名令牌（无 Cookie / 无会话，与登录态隔离）
        params = params || {};
        var gt = (boot.data && boot.data.guestToken) || '';
        if (gt) params.gtoken = gt;
        // 复用通用请求助手（含 401 跳转登录）
        return PvUI.get(buildUrl(sub, params));
    }

    // 检查数据短时缓存：同一会话内反复返回阅片器时避免重复拉取（60 秒有效）
    var studyCache = {};
    var STUDY_TTL = 60000;

    /**
     * 调阅检查数据。
     * @param {string} uid
     * @param {object} [opts] { fresh:true } 强制绕过缓存（打开新检查时使用）
     */
    function study(uid, opts) {
        opts = opts || {};
        var now = Date.now();
        var c = studyCache[uid];
        if (!opts.fresh && c && (now - c.at) < STUDY_TTL) return Promise.resolve(c.data);
        return get('study', { uid: uid }).then(function (j) {
            if (j && j.code === 200 && j.data) studyCache[uid] = { at: now, data: j };
            return j;
        });
    }

    global.PvApi = {
        search: function (q, opts) {
            var p = { q: q };
            if (opts) {
                if (opts.limit != null) p.limit = opts.limit;
                if (opts.offset != null) p.offset = opts.offset;
                if (opts.gender) p.gender = opts.gender;
                if (opts.modality) p.modality = opts.modality;
                if (opts.date_from) p.date_from = opts.date_from;
                if (opts.date_to) p.date_to = opts.date_to;
                if (opts.sort) { p.sort = opts.sort; p.dir = opts.dir || 'desc'; }
            }
            return get('search', p);
        },
        facets: function () { return get('facets', {}); },
        study:  study,
        /** 调阅影像报告（FHIR DiagnosticReport）；无报告返回 { available:false } */
        report: function (uid, patient, opts) {
            opts = opts || {};
            return get('report', {
                uid: uid, patient: patient || '',
                modality: opts.modality || '', study_date: opts.study_date || '', title: opts.title || ''
            });
        },
        ping:   function () { return get('ping', {}); },
        /** 记录操作日志（读片 / 下载 / 阅读 DICOM），失败静默 */
        log: function (action, detail) {
            var fd = new FormData();
            fd.append('_csrf', boot.csrf || '');
            fd.append('action', action || '');
            fd.append('detail', detail || '');
            return PvUI.post(buildUrl('log', {}), fd).catch(function () { return null; });
        },
        viewerUrl: function (uid) {
            var u = boot.viewer || '?r=viewer';
            return u + (u.indexOf('?') < 0 ? '?' : '&') + 'uid=' + encodeURIComponent(uid);
        }
    };
})(window);
