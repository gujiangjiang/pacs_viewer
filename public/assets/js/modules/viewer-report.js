/* ============================================================
 * assets/js/modules/viewer-report.js — 影像报告查看（PvViewer 扩展）
 * ============================================================
 * 由 viewer.js 装配：查看 FHIR DiagnosticReport 并渲染报告文档。
 * 加载顺序须在 viewer.js 之后（扩展 PvViewer.prototype）。
 * ============================================================ */
(function (global) {
    'use strict';
    var PvViewer = global.PvViewer;
    var esc = PvUI.esc;

    PvViewer.prototype.showReport = function () {
        if (!window.PvModal) return;
        var p = this.activePane();
        var d = (p && p.data() && p.data().data) || null;
        if (!d || !d.study) { if (window.PvUI) PvUI.toast('请先打开一个检查', 'err'); return; }
        var pInfo = d.patient || {}, s = d.study || {};
        var uid = s.study_uid || '';
        var self = this;
        var m = window.PvModal.open({
            title: '影像检查报告',
            size: 'lg',
            body: '<div class="pv-report"><div class="pv-report-loading">正在加载报告…</div></div>',
            actions: [{ label: '关闭', cls: 'pv-btn-ghost' }],
            onOpen: function (h) { self._loadReport(h.body, pInfo, s, uid); }
        });
        self.logEvent('report');
        return m;
    };

    PvViewer.prototype._loadReport = function (bodyEl, pInfo, s, uid) {
        var self = this;
        var done = function (rep) { bodyEl.innerHTML = self.reportHtml(pInfo, s, rep || { available: false }); };
        if (window.PvApi && PvApi.report) {
            PvApi.report(uid, pInfo.patient_id || '', {
                modality: s.modality || '', study_date: s.study_date || '', title: s.description || ''
            }).then(function (j) {
                done((j && j.code === 200 && j.data) ? j.data : null);
            }).catch(function () { done(null); });
        } else { done(null); }
    };

    PvViewer.prototype.reportHtml = function (p, s, rep) {
        var item = function (label, val) {
            val = (val === undefined || val === null) ? '' : String(val).trim();
            if (val === '') return '';
            return '<div class="pv-report-item"><span>' + esc(label) + '</span><b>' + esc(val) + '</b></div>';
        };
        var meta = item('患者姓名', p.name) + item('性别', p.gender) + item('年龄', p.age)
            + item('患者号', p.patient_id) + item('门诊号', p.outpatient_no)
            + item('检查号', s.accession_no) + item('检查项目', s.description)
            + item('检查时间', s.study_date);

        var statusText = rep.available ? (rep.status_text || '') : '';
        var st = rep.status || '';
        var stCls = (st === 'final' || st === 'amended' || st === 'corrected') ? 'ok'
            : ((st === 'cancelled') ? 'off' : 'warn');
        var statusBadge = statusText !== ''
            ? '<span class="pv-report-status ' + stCls + '">' + esc(statusText) + '</span>' : '';
        var reportNo = (rep.report_no || '').trim() !== '' ? '<span class="pv-report-no">报告号 ' + esc(rep.report_no) + '</span>' : '';
        var corner = (reportNo !== '' || statusBadge !== '')
            ? '<div class="pv-report-corner">' + statusBadge + reportNo + '</div>' : '';

        var sec = function (title, val) {
            val = (val === undefined || val === null) ? '' : String(val).trim();
            if (val === '') return '';
            return '<div class="pv-report-sec"><h4>' + esc(title) + '</h4><div class="pv-report-text">' + esc(val).replace(/\n/g, '<br>') + '</div></div>';
        };
        var body = rep.available
            ? (sec('检查所见', rep.findings) + sec('检查诊断', rep.conclusion) + sec('临床诊断', rep.clinical_diagnosis))
            : '';
        if (body === '') {
            body = '<div class="pv-report-empty">'
                + '<div class="pv-report-empty-t">' + (rep.available ? '报告正文尚未填写' : '该检查暂无影像报告') + '</div>'
                + '<div class="pv-report-empty-s">' + (rep.available ? '报告可能仍在书写中，请稍后重试。' : '报告由门诊/ RIS 系统出具后，可在此查看；亦可联系检查科室。') + '</div>'
                + '</div>';
        }
        var footItems = item('开单医生', rep.apply_doctor) + item('开单科室', rep.apply_dept)
            + item('报告医生', rep.report_doctor) + item('报告时间', rep.issued);
        var foot = footItems !== '' ? '<div class="pv-report-foot">' + footItems + '</div>' : '';
        // PDF 链接仅允许 http(s)：避免远端 FHIR 下发 javascript: 等协议造成点击执行
        var pdfUrl = ('pdf_url' in rep && rep.pdf_url) ? String(rep.pdf_url) : '';
        var pdfBtn = /^https?:\/\//i.test(pdfUrl)
            ? '<a class="pv-btn pv-btn-ghost pv-btn-sm" href="' + esc(pdfUrl) + '" target="_blank" rel="noopener">查看 PDF 报告</a>' : '';

        return '<div class="pv-report"><div class="pv-report-doc">'
            + '<div class="pv-report-title">'
            + '<div class="pv-report-hosp">' + esc(this.about && (this.about.report_hospital || this.about.hospital) ? (this.about.report_hospital || this.about.hospital) : '') + '</div>'
            + '<h2>影像检查报告</h2>'
            + corner + '</div>'
            + '<div class="pv-report-meta">' + meta + '</div>'
            + '<div class="pv-report-body">' + body + '</div>'
            + foot
            + (pdfBtn ? '<div class="pv-report-pdf">' + pdfBtn + '</div>' : '')
            + '</div></div>';
    };
})(window);
