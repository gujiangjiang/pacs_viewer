/* ============================================================
 * assets/js/modules/pane-info.js — 窗格 DICOM 详情（PvPane 扩展）
 * ============================================================
 * 由 pane.js 装配：以模态框展示当前检查 / 序列 / 图像的 DICOM 标签详情。
 * 加载顺序须在 pane.js 之后（扩展 PvPane.prototype）。
 * ============================================================ */
(function (global) {
    'use strict';
    var PvPane = global.PvPane;
    var esc = PvUI.esc;

    /* ---------- DICOM 详情 ---------- */
    PvPane.prototype.showDicomInfo = function () {
        if (!window.PvModal || !this.hasImage()) return;
        var d = (this.data() || {}).data || {}, p = d.patient || {}, s = d.study || {}, ser = this.curSeries() || {};
        var meta = d.meta || {}, isHU = this.frameIsHU(), count = this.frameCount();
        var seriesUid = /^\d[\d.]*$/.test(s.study_uid || '') ? (s.study_uid + '.' + (ser.series_id || 1))
            : ('1.2.826.0.1.3680043.8.498.' + (ser.seed || ser.series_id || '1'));
        var section = function (title, rows) {
            var h = '<div class="pv-dicom-sec"><h4>' + esc(title) + '</h4><table class="pv-dicom-table">';
            rows.forEach(function (r) { if (r[1] === undefined || r[1] === null || r[1] === '') return; h += '<tr><th>' + esc(r[0]) + '</th><td>' + esc(r[1]) + '</td></tr>'; });
            return h + '</table></div>';
        };
        var seriesList = (d.series || []).map(function (x) { return 'Ser ' + x.series_id + ' · ' + (x.description || '') + '（' + (x.slice_count || 0) + ' 帧）'; }).join('；');
        var html = '<div class="pv-dicom">' +
            section('患者信息 (Patient)', [['PatientName（姓名）', p.name], ['PatientID（患者号）', p.patient_id], ['PatientBirthDate（出生日期）', p.birth_date], ['PatientSex（性别）', p.gender], ['Age（年龄）', p.age], ['OutpatientNo（门诊号）', p.outpatient_no]]) +
            section('检查信息 (Study)', [['StudyInstanceUID', s.study_uid], ['AccessionNumber（检查号）', s.accession_no], ['StudyDate（检查时间）', s.study_date], ['Modality（模态）', s.modality], ['StudyDescription（检查项目）', s.description], ['InstitutionName（机构）', s.institution], ['StationName（设备）', s.station_name], ['ReferringDept（申请科室）', s.apply_dept], ['ReferringPhysician（申请医生）', s.apply_doctor], ['NumberOfSeries（序列数）', (d.series || []).length]]) +
            section('序列信息 (Series)', [['SeriesNumber（序列号）', ser.series_id], ['SeriesInstanceUID', seriesUid], ['SeriesDescription（序列描述）', ser.description], ['ImageOrientation（方位）', ser.orientation], ['NumberOfFrames（帧数）', count], ['SliceThickness（层厚）', ser.slice_thickness != null ? ser.slice_thickness : s.slice_thickness], ['PixelSpacing（像素间距）', ser.pixel_spacing], ['SeriesList（本检查序列）', seriesList]]) +
            section('当前图像 (Instance)', [['InstanceNumber（帧号）', (this.st.fi + 1) + ' / ' + count], ['Rows × Columns（矩阵）', (ser.rows || 512) + ' × ' + (ser.columns || 512)], ['BitsAllocated（位深）', ser.bits_allocated || 16], ['PhotometricInterpretation', 'MONOCHROME2'], ['RescaleIntercept / Slope', (ser.rescale_intercept != null ? ser.rescale_intercept : '0') + ' / ' + (ser.rescale_slope != null ? ser.rescale_slope : '1')], ['WindowWidth / WindowCenter', Math.round(this.st.ww) + ' / ' + Math.round(this.st.wl)], ['PixelRepresentation（是否 HU）', isHU ? '有符号（HU）' : '无符号'], ['Zoom / Rotation', Math.round(this.st.zoom * 100) + '% / ' + (((this.st.rot % 360) + 360) % 360) + '°'], ['Flip（镜像）', (this.st.flipH ? 'H' : '') + (this.st.flipV ? 'V' : '') || 'N'], ['Annotations（标注数）', this.st.annos.length]]) +
            section('数据来源', [['Source（来源）', meta.source], ['Mode（接口模式）', meta.mode], ['Format（影像格式）', ser.format === 'dicom' ? '标准 DICOM（WADO-URI）' : '图像文件']]) + '</div>';
        window.PvModal.open({ title: 'DICOM 详情 · ' + (s.accession_no || s.study_uid || ''), size: 'lg', body: html });
        this.viewer.logEvent('dicom');
    };
})(window);
