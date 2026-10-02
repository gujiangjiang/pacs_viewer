/* ============================================================
 * assets/js/modules/osd.js — 四角医学水印 OSD 模块
 * 不随平移 / 缩放位移，始终叠印在画布四角。
 * ============================================================ */
(function (global) {
    'use strict';
    function draw(ctx, o) {
        var d = o.data || {};
        var s = d.study || {}, p = d.patient || {}, ser = o.series || {};
        ctx.setTransform(o.dpr, 0, 0, o.dpr, 0, 0);
        ctx.save();
        ctx.font = '12px "SFMono-Regular", Consolas, "Courier New", monospace';
        ctx.textBaseline = 'top';
        ctx.shadowColor = 'rgba(0,0,0,0.9)';
        ctx.shadowBlur = 4;
        ctx.fillStyle = '#ffffff';

        var thick = parseFloat(ser.slice_thickness || s.slice_thickness || 0) || 0;
        var loc = (o.fi - (o.count - 1) / 2) * thick;
        var zdeg = ((o.rot % 360) + 360) % 360;
        var flip = (o.flipH ? 'H' : '') + (o.flipV ? 'V' : '') || 'N';
        var TL = [(s.hospital_display || s.institution || ''), (s.modality || '') + '  ' + (s.station_name || ''), s.description || '',
                  'Ser: ' + (ser.series_id || '') + ' - ' + (ser.description || '')];
        var TR = [p.name || '', (p.gender || '') + ' / ' + (p.age || ''), 'PID: ' + (p.patient_id || ''), 'OPD No: ' + (p.outpatient_no || '')];
        var BL = ['WW: ' + Math.round(o.ww) + ' WL: ' + Math.round(o.wl), 'Im: ' + (o.fi + 1) + '/' + o.count,
                  'Thick: ' + thick.toFixed(1) + 'mm Loc: ' + loc.toFixed(1) + 'mm', 'Zoom: ' + Math.round(o.zoom * 100) + '%'];
        var BR = ['Acc: ' + (s.accession_no || ''), 'Date: ' + (s.study_date || ''),
                  'Rot: ' + zdeg + '\u00b0 Flip: ' + flip, 'Slice: ' + (ser.orientation || '')];

        var pad = 10, lh = 15;
        ctx.textAlign = 'left';
        TL.forEach(function (t, i) { if (t) ctx.fillText(' ' + t, pad, pad + i * lh); });
        BL.forEach(function (t, i) { if (t) ctx.fillText(' ' + t, pad, o.cssH - pad - (BL.length - i) * lh); });
        ctx.textAlign = 'right';
        TR.forEach(function (t, i) { if (t) ctx.fillText(t + ' ', o.cssW - pad, pad + i * lh); });
        BR.forEach(function (t, i) { if (t) ctx.fillText(t + ' ', o.cssW - pad, o.cssH - pad - (BR.length - i) * lh); });
        ctx.restore();
    }
    global.PvOsd = { draw: draw };
})(window);
