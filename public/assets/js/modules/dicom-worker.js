/* ============================================================
 * assets/js/modules/dicom-worker.js — 标准 DICOM 解码 / 重采样 Worker
 * ============================================================
 * 在后台线程完成 DICOM 解析与重采样，避免大序列滚动时阻塞主线程。
 * 复用通用模块 dicom.js（解码）与 render.js（重采样），不带任何模拟特化，
 * 因此对「标准 PACS 数据」同样适用。
 * ============================================================ */
'use strict';

var QS = self.location.search || '';   // 继承主线程的版本号，确保与页面同一份资源缓存
importScripts('dicom.js' + QS, 'render.js' + QS);

self.onmessage = function (e) {
    var d = e.data || {};
    var out = { id: d.id };
    try {
        var dec = self.PvDicom ? self.PvDicom.decode(d.buffer) : null;
        if (!dec) { out.ok = false; out.error = 'decode'; self.postMessage(out); return; }
        var raw = self.PvRender.resample(dec, d.size);
        out.ok = true;
        out.dec = dec;
        out.raw = raw;
        // 转移缓冲区，零拷贝回传
        self.postMessage(out, [raw.buffer, dec.pixels.buffer]);
    } catch (err) {
        out.ok = false;
        out.error = String((err && err.message) || err);
        self.postMessage(out);
    }
};
