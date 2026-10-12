/* ============================================================
 * modules/visit.js — 就诊标识显示助手（门诊号 / 住院号 / 就诊号）
 * ============================================================
 * 显示约定（与门诊一体化系统一致）：
 *  - 门诊 / 急诊患者 → 「门诊号」（值为就诊流水号 outpatient_no）
 *  - 住院患者       → 「住院号」（值为住院号 inpatient_no，来源于
 *    FHIR AN 标识 urn:his:identifier:admission / DICOM AdmissionID 0038,0010）
 *  - 类型未知但有就诊流水号 → 回退「门诊号」；两者皆无 → 中性「就诊号」
 * ============================================================ */
var PvVisit = (function () {
    function isInpatient(o) {
        o = o || {};
        return o.visit_type === 'inpatient' || (o.inpatient_no ? true : false);
    }
    function label(o) {
        o = o || {};
        if (isInpatient(o) && o.inpatient_no) return '住院号';
        return o.outpatient_no ? '门诊号' : '就诊号';
    }
    function no(o) {
        o = o || {};
        if (isInpatient(o) && o.inpatient_no) return o.inpatient_no;
        return o.outpatient_no || '';
    }
    function osd(o) {   /* 图像水印英文标签 */
        o = o || {};
        return (isInpatient(o) && o.inpatient_no) ? 'Adm No: ' : 'Visit No: ';
    }
    return { isInpatient: isInpatient, label: label, no: no, osd: osd };
})();
