/* ============================================================
 * assets/js/modules/decoder.js — DICOM 解码 / 重采样调度
 * ============================================================
 * 优先在 Web Worker 中解码标准 DICOM 并重采样，失败或环境不支持时回退主线程。
 * 对调用方暴露统一接口：PvDecoder.decode(arrayBuffer, size) → Promise<{dec,raw}>。
 * ============================================================ */
(function (global) {
    'use strict';

    var worker = null, seq = 0, pending = {};

    function workerUrl() {
        var base = (global.PV_BOOT && global.PV_BOOT.asset) ? global.PV_BOOT.asset : '';
        var v = (global.PV_BOOT && global.PV_BOOT.version) ? ('?v=' + global.PV_BOOT.version) : '';
        return base + '/js/modules/dicom-worker.js' + v;
    }

    function ensureWorker() {
        if (worker !== null) return worker;
        if (typeof global.Worker === 'undefined') { worker = false; return false; }
        try {
            worker = new global.Worker(workerUrl());
            worker.onmessage = function (e) {
                var d = e.data || {}, p = pending[d.id];
                if (!p) return;
                delete pending[d.id];
                if (d.ok) { p.resolve(d); return; }
                // Worker 解码失败：回退主线程解码（保留了原始缓冲）
                decodeMain(p.buffer, p.size, p.frame).then(p.resolve, p.reject);
            };
            worker.onerror = function () {
                // Worker 异常：标记不可用，未完成请求全部回退主线程
                worker = false;
                Object.keys(pending).forEach(function (k) {
                    var p = pending[k]; delete pending[k];
                    decodeMain(p.buffer, p.size, p.frame).then(p.resolve, p.reject);
                });
            };
        } catch (e) { worker = false; }
        return worker;
    }

    function decodeMain(buffer, size, frame) {
        return new Promise(function (resolve, reject) {
            var dec = global.PvDicom ? global.PvDicom.decode(buffer) : null;
            if (!dec) { reject(new Error('decode')); return; }
            resolve({ ok: true, dec: dec, raw: global.PvRender.resample(dec, size, frame || 0) });
        });
    }

    /** @param {ArrayBuffer} buffer @param {number} size 重采样边长 @param {number} [frame] 多帧内的帧号 */
    function decode(buffer, size, frame) {
        frame = frame || 0;
        var w = ensureWorker();
        if (!w) return decodeMain(buffer, size, frame);
        return new Promise(function (resolve, reject) {
            var id = ++seq;
            // 不转移 buffer：保留原始数据，便于 Worker 异常时回退主线程解码
            pending[id] = { resolve: resolve, reject: reject, buffer: buffer, size: size, frame: frame };
            try {
                w.postMessage({ id: id, buffer: buffer, size: size, frame: frame });
            } catch (e) {
                delete pending[id];
                decodeMain(buffer, size, frame).then(resolve, reject);
            }
        });
    }

    global.PvDecoder = { decode: decode, usingWorker: function () { return ensureWorker() !== false; } };
})(typeof self !== 'undefined' ? self : window);
