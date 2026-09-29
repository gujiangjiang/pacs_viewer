/* ============================================================
 * assets/js/modules/dicom.js — 标准 DICOM 解码模块（通用，无模拟特化）
 * 解析 DICOM Part 10 文件（Explicit / Implicit VR Little Endian），
 * 提取像素流与标准窗宽窗位 / Rescale 参数，供 Canvas 渲染。
 * ============================================================ */
(function (global) {
    'use strict';

    var TS_IMPLICIT = '1.2.840.10008.1.2';
    var TS_EXPLICIT = '1.2.840.10008.1.2.1';

    function Decoder(buffer) {
        this.bytes = new Uint8Array(buffer);
        this.pos = 0;
        this.explicit = true;
    }
    Decoder.prototype.u16 = function (p) { return this.bytes[p] | (this.bytes[p + 1] << 8); };
    Decoder.prototype.u32 = function (p) {
        return (this.bytes[p] | (this.bytes[p + 1] << 8) | (this.bytes[p + 2] << 16) | (this.bytes[p + 3] << 24)) >>> 0;
    };
    Decoder.prototype.ascii = function (p, n) {
        var s = '';
        for (var i = 0; i < n; i++) { var c = this.bytes[p + i]; if (c === 0) break; s += String.fromCharCode(c); }
        return s.replace(/[ \u0000]+$/, '');
    };
    Decoder.prototype.next = function () {
        if (this.pos + 8 > this.bytes.length) return null;
        var group = this.u16(this.pos), element = this.u16(this.pos + 2);
        var vr = '', len, vpos;
        var b4 = this.bytes[this.pos + 4], b5 = this.bytes[this.pos + 5];
        var looksVr = (b4 >= 65 && b4 <= 90) && (b5 >= 65 && b5 <= 90);
        if (this.explicit && looksVr) {
            vr = String.fromCharCode(b4, b5);
            if (vr === 'OB' || vr === 'OW' || vr === 'OF' || vr === 'SQ' || vr === 'UT' || vr === 'UN') {
                len = this.u32(this.pos + 8); vpos = this.pos + 12;
            } else { len = this.u16(this.pos + 6); vpos = this.pos + 8; }
        } else {
            len = this.u32(this.pos + 4); vpos = this.pos + 8;
        }
        return { group: group, element: element, vr: vr, len: len, vpos: vpos };
    };

    function parseDS(s) {
        if (!s) return null;
        var first = s.split('\\')[0];
        var v = parseFloat(first);
        return isFinite(v) ? v : null;
    }

    /**
     * 解码 DICOM Part 10 文件。
     * @param {ArrayBuffer} buffer
     * @return {object|null} { rows, columns, bitsAllocated, bitsStored,
     *   pixelRepresentation, windowCenter, windowWidth, rescaleIntercept,
     *   rescaleSlope, pixels }
     */
    function decode(buffer) {
        var d = new Decoder(buffer);
        var b = d.bytes;
        if (b.length < 132 || !(b[128] === 68 && b[129] === 73 && b[130] === 67 && b[131] === 77)) return null;

        d.pos = 132;
        d.explicit = true;
        var transferSyntax = TS_EXPLICIT;

        // File Meta Information（显式 VR，遇到非 0002 组即数据集开始）
        while (true) {
            if (d.pos + 8 > b.length) return null;
            var group = d.u16(d.pos);
            if (group !== 0x0002) break;
            var el = d.next();
            if (!el) return null;
            if (el.group === 0x0002 && el.element === 0x0010) transferSyntax = d.ascii(el.vpos, el.len);
            d.pos = el.vpos + el.len;
        }

        d.explicit = (transferSyntax.indexOf('1.2.840.10008.1.2.1') === 0) ||
                     (transferSyntax.indexOf('1.2.840.10008.1.2.2') === 0);
        if (transferSyntax === TS_IMPLICIT) d.explicit = false;

        var meta = { rows: 0, columns: 0, bitsAllocated: 16, bitsStored: 16, pixelRepresentation: 0, rescaleIntercept: 0, rescaleSlope: 1 };
        var wc = null, ww = null, pixelInfo = null;

        while (d.pos + 8 <= b.length) {
            var r = d.next();
            if (!r) break;
            if (r.len === 0xFFFFFFFF) break;                    // 封装/未定义长度（压缩）暂不支持
            var g = r.group, e = r.element, vpos = r.vpos, len = r.len;
            if (g === 0x0028 && e === 0x0010) meta.rows = d.u16(vpos);
            else if (g === 0x0028 && e === 0x0011) meta.columns = d.u16(vpos);
            else if (g === 0x0028 && e === 0x0100) meta.bitsAllocated = d.u16(vpos);
            else if (g === 0x0028 && e === 0x0101) meta.bitsStored = d.u16(vpos);
            else if (g === 0x0028 && e === 0x0103) meta.pixelRepresentation = d.u16(vpos);
            else if (g === 0x0028 && e === 0x1050) wc = parseDS(d.ascii(vpos, len));
            else if (g === 0x0028 && e === 0x1051) ww = parseDS(d.ascii(vpos, len));
            else if (g === 0x0028 && e === 0x1052) { var ri = parseDS(d.ascii(vpos, len)); if (ri !== null) meta.rescaleIntercept = ri; }
            else if (g === 0x0028 && e === 0x1053) { var rs = parseDS(d.ascii(vpos, len)); if (rs !== null) meta.rescaleSlope = rs; }
            else if (g === 0x7FE0 && e === 0x0010) { pixelInfo = { vpos: vpos, len: len }; }
            d.pos = vpos + len;
        }

        if (!pixelInfo || !meta.rows || !meta.columns) return null;

        var count = meta.rows * meta.columns;
        var pixels;
        if (meta.bitsAllocated === 16) {
            var signed = meta.pixelRepresentation === 1;
            pixels = signed ? new Int16Array(count) : new Uint16Array(count);
            var off = pixelInfo.vpos;
            for (var i = 0; i < count; i++) {
                var v = b[off + i * 2] | (b[off + i * 2 + 1] << 8);
                if (signed && (v & 0x8000)) v -= 0x10000;
                pixels[i] = v;
            }
        } else if (meta.bitsAllocated === 8) {
            pixels = new Uint8Array(count);
            for (var k = 0; k < count; k++) pixels[k] = b[pixelInfo.vpos + k];
        } else {
            return null;
        }

        meta.windowCenter = wc;
        meta.windowWidth = ww;
        meta.pixels = pixels;
        return meta;
    }

    global.PvDicom = { decode: decode };
})(window);
