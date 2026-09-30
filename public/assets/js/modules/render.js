/* ============================================================
 * assets/js/modules/render.js — 标准 DICOM 像素窗宽窗位渲染模块
 * 前端只依据标准 DICOM 数据集（像素流 + Rescale/Window Tag）完成渲染，
 * 不包含任何针对模拟数据的特化逻辑。
 * ============================================================ */
(function (global) {
    'use strict';

    var BASE = 512;                       // 画布逻辑尺寸

    function clamp(v, a, b) { return v < a ? a : (v > b ? b : v); }

    /**
     * 将解码后的 DICOM 像素重采样为 size×size 的显示值场。
     * 值 = 像素 * RescaleSlope + RescaleIntercept（CT 即 HU，MR/DR/US 为信号 / 灰度）。
     * @param {object} dec  PvDicom.decode 的返回值
     * @param {number} size 目标尺寸
     * @return {Float32Array}
     */
    function resample(dec, size) {
        var sw = dec.columns, sh = dec.rows, px = dec.pixels;
        var slope = dec.rescaleSlope, intercept = dec.rescaleIntercept;
        var out = new Float32Array(size * size);
        var identity = (slope === 1 && intercept === 0);
        for (var y = 0; y < size; y++) {
            var sy = (sh === size) ? y : Math.min(sh - 1, (y * sh / size) | 0);
            var srow = sy * sw;
            var orow = y * size;
            for (var x = 0; x < size; x++) {
                var sx = (sw === size) ? x : Math.min(sw - 1, (x * sw / size) | 0);
                var v = px[srow + sx];
                out[orow + x] = identity ? v : (v * slope + intercept);
            }
        }
        return out;
    }

    /**
     * 窗宽窗位映射：raw(显示值场) → ImageData(size×size)。
     * @param {Float32Array} raw
     * @param {number} size
     * @param {number} ww 窗宽、wl 窗位（与 raw 同单位）
     * @param {boolean} invert
     * @return {ImageData}
     */
    function window(raw, size, ww, wl, invert) {
        ww = Math.max(1, ww);
        var lo = wl - ww / 2, k = 255 / ww;
        var img = new ImageData(size, size), d = img.data;
        for (var i = 0, n = size * size; i < n; i++) {
            var o = (raw[i] - lo) * k;
            o = o < 0 ? 0 : (o > 255 ? 255 : o);
            if (invert) o = 255 - o;
            var j = i * 4;
            d[j] = d[j + 1] = d[j + 2] = o; d[j + 3] = 255;
        }
        return img;
    }

    /** 便捷：解码对象直接窗口化为指定尺寸 ImageData */
    function decodeToImage(dec, size, ww, wl, invert) {
        return window(resample(dec, size), size, ww, wl, invert);
    }

    global.PvRender = {
        BASE: BASE,
        resample: resample, window: window, decodeToImage: decodeToImage, clamp: clamp
    };
})(window);
