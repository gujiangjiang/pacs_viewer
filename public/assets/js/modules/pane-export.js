/* ============================================================
 * assets/js/modules/pane-export.js — 窗格导出（PvPane 扩展）
 * ============================================================
 * 由 pane.js 装配：当前图像 PNG、序列 ZIP、原始 DICOM ZIP 导出。
 * 加载顺序须在 pane.js 之后（扩展 PvPane.prototype）。
 * ============================================================ */
(function (global) {
    'use strict';
    var PvPane = global.PvPane;
    var BASE = PvRender.BASE;

    /* ---------- 导出 ---------- */
    PvPane.prototype.fileBase = function () {
        var d = this.data(); d = d || {}; var data = d.data || {};
        var p = data.patient || {}, s = data.study || {}, ser = this.curSeries() || {};
        return [p.patient_id || 'patient', s.accession_no || s.study_uid || 'study', 'ser' + (ser.series_id || 1)]
            .join('_').replace(/[^\w.-]+/g, '_');
    };
    PvPane.prototype._triggerDownload = function (url, filename) {
        var a = document.createElement('a'); a.href = url; a.download = filename;
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(url); }, 1800);
    };
    PvPane.prototype._paintRaw = function (cx, raw) {
        cx.putImageData(PvRender.window(raw, BASE, this.st.ww, this.st.wl, this.st.invert), 0, 0);
    };
    PvPane.prototype.exportFrameCanvas = function (fi) {
        var self = this, ser = this.curSeries();
        var cv = document.createElement('canvas'); cv.width = BASE; cv.height = BASE;
        var cx = cv.getContext('2d'); cx.fillStyle = '#000'; cx.fillRect(0, 0, BASE, BASE);
        if (!ser) return Promise.resolve(cv);
        if (ser.format === 'dicom') {
            var loc = this._frameLoc(ser, fi), ii = loc.ii, lf = loc.lf;
            var cached = this._frames[this._frameKey(ser, fi)];
            if (cached && cached.status === 'ok') { this._paintRaw(cx, cached.raw); return Promise.resolve(cv); }
            var inst = this._instances[this._instKey(ser, ii)];
            if (inst && inst.status === 'ok') { this._paintRaw(cx, PvRender.resample(inst.dec, BASE, lf)); return Promise.resolve(cv); }
            var durl = ser.images && ser.images[ii];
            if (!durl) return Promise.resolve(cv);
            return fetch(durl, { credentials: 'same-origin' }).then(function (r) { return r.arrayBuffer(); })
                .then(function (buf) { return PvDecoder.decode(buf, BASE, lf); })
                .then(function (res) { self._paintRaw(cx, res.raw); return cv; })
                .catch(function () { return cv; });
        }
        var src = ser.images && ser.images[fi];
        if (!src) return Promise.resolve(cv);
        return new Promise(function (resolve) {
            var im = new Image();
            im.onload = function () {
                var raw = PvRender.imageToRaw(im, BASE, self.raw);
                cx.drawImage(self.windowRaw(raw, self.st.ww, self.st.wl, self.st.invert), 0, 0);
                resolve(cv);
            };
            im.onerror = function () { resolve(cv); };
            im.src = src;
        });
    };
    PvPane.prototype.saveImage = function () {
        if (!this.hasImage()) return;
        var self = this, name = this.fileBase() + '_im' + (this.st.fi + 1) + '.png';
        try {
            this.canvas.toBlob(function (blob) {
                if (!blob) return;
                self._triggerDownload(URL.createObjectURL(blob), name);
                self.setStatus('已保存当前图像：' + name);
                self.viewer.logEvent('download', '当前图像 ' + name);
            }, 'image/png');
        } catch (e) { this.setStatus('当前画面包含跨域内容，无法导出'); }
    };
    PvPane.prototype.saveSeries = function () {
        if (!window.PvZip || !this.hasImage()) { this.setStatus('无可导出序列'); return; }
        var self = this, n = this.frameCount(), base = this.fileBase();
        this.setStatus('正在导出序列（0/' + n + '）…');
        var chain = Promise.resolve(), files = [];
        for (var i = 0; i < n; i++) {
            (function (fi) {
                chain = chain.then(function () {
                    return self.exportFrameCanvas(fi).then(function (cv) {
                        return new Promise(function (resolve) {
                            cv.toBlob(function (blob) {
                                files.push({ name: base + '_im' + (fi + 1) + '.png', data: blob });
                                self.setStatus('正在导出序列（' + (fi + 1) + '/' + n + '）…');
                                resolve();
                            }, 'image/png');
                        });
                    });
                });
            })(i);
        }
        chain.then(function () { self.setStatus('正在打包 ZIP…'); return window.PvZip.create(files); })
            .then(function (zip) {
                self._triggerDownload(URL.createObjectURL(zip), base + '.zip');
                self.setStatus('已导出序列：' + base + '.zip（' + n + ' 帧）');
                self.viewer.logEvent('download', '序列 ZIP ' + base + '（' + n + ' 帧）');
            }).catch(function () { self.setStatus('序列导出失败'); });
    };

    /** 导出当前序列的原始 DICOM 文档（完整 Part-10 字节流，打包为 ZIP） */
    PvPane.prototype.saveDicom = function () {
        var ser = this.curSeries();
        if (!ser || ser.format !== 'dicom' || !ser.images || !ser.images.length) { this.setStatus('当前序列无 DICOM 影像可导出'); return; }
        var self = this, urls = ser.images, n = urls.length, base = this.fileBase();
        var nameFor = function (url, i) {
            var m = /[?&]instance=([^&]+)/.exec(url);
            return base + '_' + (m ? decodeURIComponent(m[1]) : ('im' + (i + 1))) + '.dcm';
        };
        var fetchOne = function (i) {
            return fetch(urls[i], { credentials: 'same-origin' })
                .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.arrayBuffer(); })
                .then(function (buf) { return { name: nameFor(urls[i], i), data: new Uint8Array(buf) }; });
        };
        this.setStatus('正在导出 DICOM（0/' + n + '）…');
        var files = [], chain = Promise.resolve();
        for (var i = 0; i < n; i++) {
            (function (idx) {
                chain = chain.then(function () {
                    return fetchOne(idx).then(function (f) {
                        files.push(f);
                        self.setStatus('正在导出 DICOM（' + (idx + 1) + '/' + n + '）…');
                    });
                });
            })(i);
        }
        chain.then(function () {
            self.setStatus('正在打包 ZIP…');
            return (window.PvZip && files.length) ? window.PvZip.create(files) : null;
        }).then(function (zip) {
            if (zip) {
                self._triggerDownload(URL.createObjectURL(zip), base + '.dcm.zip');
                self.setStatus('已导出 DICOM：' + base + '.dcm.zip（' + n + ' 个实例）');
                self.viewer.logEvent('download', 'DICOM ZIP ' + base + '（' + n + ' 个实例）');
            } else if (files.length) {
                self._triggerDownload(URL.createObjectURL(new Blob([files[0].data], { type: 'application/dicom' })), files[0].name);
                self.setStatus('已导出当前 DICOM：' + files[0].name);
                self.viewer.logEvent('download', 'DICOM ' + files[0].name);
            }
        }).catch(function () { self.setStatus('DICOM 导出失败'); });
    };
})(window);
