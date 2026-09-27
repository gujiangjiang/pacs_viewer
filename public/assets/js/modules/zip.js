/* ============================================================
 * assets/js/modules/zip.js — 极简 ZIP 打包（store 无压缩，零依赖）
 * 用途：把多帧 PNG 打包为一个 zip 下载（保存序列）。
 * 用法：PvZip.create([{ name: 'a.png', data: Blob|Uint8Array }, ...]) → Promise<Blob>
 * ============================================================ */
(function (global) {
    'use strict';

    var crcTable = (function () {
        var t = new Uint32Array(256);
        for (var n = 0; n < 256; n++) {
            var c = n;
            for (var k = 0; k < 8; k++) c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
            t[n] = c >>> 0;
        }
        return t;
    })();
    function crc32(u8) {
        var c = 0xFFFFFFFF;
        for (var i = 0; i < u8.length; i++) c = crcTable[(c ^ u8[i]) & 0xFF] ^ (c >>> 8);
        return (c ^ 0xFFFFFFFF) >>> 0;
    }
    function toU8(d) {
        if (d instanceof Uint8Array) return Promise.resolve(d);
        if (global.Blob && d instanceof Blob) return d.arrayBuffer().then(function (b) { return new Uint8Array(b); });
        return Promise.resolve(new Uint8Array(d));
    }
    function dosTime(d) { return (d.getHours() << 11) | (d.getMinutes() << 5) | Math.floor(d.getSeconds() / 2); }
    function dosDate(d) { return ((d.getFullYear() - 1980) << 9) | ((d.getMonth() + 1) << 5) | d.getDate(); }

    function localHeader(name, size, crc, time, date) {
        var b = new Uint8Array(30 + name.length), dv = new DataView(b.buffer);
        dv.setUint32(0, 0x04034b50, true);
        dv.setUint16(4, 20, true);
        dv.setUint16(6, 0x0800, true);      // UTF-8 文件名
        dv.setUint16(8, 0, true);           // 存储（无压缩）
        dv.setUint16(10, time, true);
        dv.setUint16(12, date, true);
        dv.setUint32(14, crc, true);
        dv.setUint32(18, size, true);
        dv.setUint32(22, size, true);
        dv.setUint16(26, name.length, true);
        dv.setUint16(28, 0, true);
        b.set(name, 30);
        return b;
    }
    function centralHeader(name, size, crc, time, date, offset) {
        var b = new Uint8Array(46 + name.length), dv = new DataView(b.buffer);
        dv.setUint32(0, 0x02014b50, true);
        dv.setUint16(4, 20, true);
        dv.setUint16(6, 20, true);
        dv.setUint16(8, 0x0800, true);
        dv.setUint16(10, 0, true);
        dv.setUint16(12, time, true);
        dv.setUint16(14, date, true);
        dv.setUint32(16, crc, true);
        dv.setUint32(20, size, true);
        dv.setUint32(24, size, true);
        dv.setUint16(28, name.length, true);
        dv.setUint16(30, 0, true);
        dv.setUint16(32, 0, true);
        dv.setUint16(34, 0, true);
        dv.setUint16(36, 0, true);
        dv.setUint32(38, 0, true);
        dv.setUint32(42, offset, true);
        b.set(name, 46);
        return b;
    }
    function eocd(count, cdSize, cdOffset) {
        var b = new Uint8Array(22), dv = new DataView(b.buffer);
        dv.setUint32(0, 0x06054b50, true);
        dv.setUint16(4, 0, true);
        dv.setUint16(6, 0, true);
        dv.setUint16(8, count, true);
        dv.setUint16(10, count, true);
        dv.setUint32(12, cdSize, true);
        dv.setUint32(16, cdOffset, true);
        dv.setUint16(20, 0, true);
        return b;
    }
    function concat(list) {
        var len = 0, i;
        for (i = 0; i < list.length; i++) len += list[i].length;
        var out = new Uint8Array(len), pos = 0;
        for (i = 0; i < list.length; i++) { out.set(list[i], pos); pos += list[i].length; }
        return out;
    }

    function create(files) {
        return Promise.all(files.map(function (f) {
            return toU8(f.data).then(function (u) { return { name: f.name, data: u }; });
        })).then(function (list) {
            var now = new Date(), time = dosTime(now), date = dosDate(now);
            var chunks = [], central = [], offset = 0;
            list.forEach(function (f) {
                var name = new TextEncoder().encode(f.name);
                var crc = crc32(f.data);
                var lh = localHeader(name, f.data.length, crc, time, date);
                chunks.push(lh, f.data);
                central.push(centralHeader(name, f.data.length, crc, time, date, offset));
                offset += lh.length + f.data.length;
            });
            var cd = concat(central);
            chunks.push(cd, eocd(list.length, cd.length, offset));
            return new Blob(chunks, { type: 'application/zip' });
        });
    }

    global.PvZip = { create: create };
})(window);
