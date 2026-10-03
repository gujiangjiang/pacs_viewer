<?php
/**
 * app/Controllers/PwaController.php — PWA 清单与 Service Worker
 * 这两个资源不需要登录，且随部署路径自适应生成（支持子目录挂载）。
 */
class PvPwaController {

    /** 站点根作用域（以 / 结尾） */
    private static function scope() {
        return PV_URL_SITE === '' ? '/' : PV_URL_SITE . '/';
    }

    /** Web App Manifest（动态生成，保证路径自适应） */
    public static function manifest() {
        $site = PvSettings::get('site_title', 'PACS 影像浏览器');
        $manifest = array(
            'name'             => $site,
            'short_name'       => 'PACS 浏览器',
            'description'      => 'DICOM / PACS 接口联调测试工具',
            'lang'             => 'zh-CN',
            'start_url'        => pvw_url(''),
            'scope'            => self::scope(),
            'display'          => 'standalone',
            'orientation'      => 'any',
            'background_color' => '#0b0f17',
            'theme_color'      => '#0b0f17',
            'icons'            => array(
                array('src' => self::iconUrl(32), 'sizes' => '32x32', 'type' => 'image/png', 'purpose' => 'any'),
                array('src' => self::iconUrl(192), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'),
                array('src' => self::iconUrl(256), 'sizes' => '256x256', 'type' => 'image/png', 'purpose' => 'any'),
                array('src' => self::iconUrl(512), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'),
                array('src' => self::iconUrl(512), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'),
            ),
        );
        if (!headers_sent()) header('Content-Type: application/manifest+json; charset=utf-8');
        echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    /** 站点图标地址（代码绘制；管理员上传后使用自定义图标，带版本号防止缓存） */
    public static function iconUrl($size) {
        return pvw_url('icon', array('size' => (int)$size, 'v' => PvIconRenderer::version()));
    }

    /** 站点图标端点：按请求尺寸输出 PNG（无预置图片，全部由代码绘制） */
    public static function icon() {
        $size = (int)pvw_input('size', 64);
        $png = PvIconRenderer::pngString($size);
        if (!headers_sent()) {
            header('Content-Type: image/png');
            header('Cache-Control: public, max-age=86400');
        }
        echo $png;
        exit;
    }

    /** Service Worker（静态资源缓存优先 + 后台更新；接口实时直连） */
    public static function sw() {
        if (!headers_sent()) {
            header('Content-Type: application/javascript; charset=utf-8');
            header('Service-Worker-Allowed: ' . self::scope());
            header('Cache-Control: no-cache');
        }
        $asset = PV_URL_ASSET;
        $scope = self::scope();
        $home = PV_URL_SITE === '' ? '/' : PV_URL_SITE . '/';
        $cache = 'pacs-viewer-' . PV_VERSION;
        $imgCache = 'pacs-viewer-img-' . PV_VERSION;
        $ver = '?v=' . PV_VERSION;
        $core = array(
            $asset . '/css/base.css' . $ver, $asset . '/css/ui.css' . $ver, $asset . '/css/search.css' . $ver,
            $asset . '/css/viewer.css' . $ver, $asset . '/css/admin.css' . $ver, $asset . '/css/mock.css' . $ver,
            $asset . '/js/api.js' . $ver, $asset . '/js/ui.js' . $ver, $asset . '/js/spa.js' . $ver, $asset . '/js/pwa.js' . $ver,
            $asset . '/js/search.js' . $ver, $asset . '/js/admin.js' . $ver,
            $asset . '/js/admin-users.js' . $ver, $asset . '/js/admin-storage.js' . $ver, $asset . '/js/mock.js' . $ver,
            $asset . '/js/modules/dicom.js' . $ver, $asset . '/js/modules/render.js' . $ver,
            $asset . '/js/modules/osd.js' . $ver, $asset . '/js/modules/sidebar.js' . $ver,
            $asset . '/js/modules/toolbar.js' . $ver, $asset . '/js/modules/measurements.js' . $ver,
            $asset . '/js/modules/zip.js' . $ver, $asset . '/js/modules/decoder.js' . $ver,
            $asset . '/js/modules/dicom-worker.js' . $ver,
            $asset . '/js/modules/scroll.js' . $ver,
            $asset . '/js/modules/pane.js' . $ver, $asset . '/js/viewer.js' . $ver,
            $asset . '/js/modules/viewer-report.js' . $ver,
            $asset . '/js/modules/viewer-ctxmenu.js' . $ver,
            $asset . '/js/modules/viewer-session.js' . $ver,
        );
        ?>
/* Service Worker — PACS 影像浏览器 */
var CACHE = <?php echo json_encode($cache); ?>;
var IMG_CACHE = <?php echo json_encode($imgCache); ?>;
var IMG_MAX = 400;
var ASSET = <?php echo json_encode($asset); ?>;
var SCOPE = <?php echo json_encode($scope); ?>;
var HOME  = <?php echo json_encode($home); ?>;
var CORE = <?php echo json_encode($core, JSON_UNESCAPED_SLASHES); ?>;

/* 影像数据（缩略图 / DICOM 帧）缓存：cache-first + 近似 LRU 容量控制 */
var IMG_IDX = '/__pv_img_idx';
function imgReadIdx(c) {
    return c.match(IMG_IDX).then(function (r) { return r ? r.json() : []; }).catch(function () { return []; });
}
function imgWriteIdx(c, list) {
    return c.put(IMG_IDX, new Response(JSON.stringify(list), { headers: { 'Content-Type': 'application/json' } }));
}
function imgTrim(c, url) {
    imgReadIdx(c).then(function (list) {
        var i = list.indexOf(url);
        if (i >= 0) list.splice(i, 1);
        list.push(url);
        var drop = [];
        while (list.length > IMG_MAX) drop.push(list.shift());
        return Promise.all(drop.map(function (u) { return c.delete(u); })).then(function () { return imgWriteIdx(c, list); });
    }).catch(function () {});
}

self.addEventListener('install', function (e) {
    e.waitUntil(
        caches.open(CACHE).then(function (c) {
            // 逐个缓存，任一失败不影响安装
            return Promise.all(CORE.map(function (u) { return c.add(u).catch(function () {}); }));
        }).then(function () { return self.skipWaiting(); })
    );
});

self.addEventListener('activate', function (e) {
    e.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.map(function (k) {
                if (k !== CACHE && k !== IMG_CACHE && k.indexOf('pacs-viewer-') === 0) return caches.delete(k);
            }));
        }).then(function () { return self.clients.claim(); })
    );
});

self.addEventListener('fetch', function (e) {
    var req = e.request;
    if (req.method !== 'GET') return;                     // 写操作（POST 等）直连
    var url = new URL(req.url);
    if (url.origin !== self.location.origin) return;      // 跨域直连

    var r = url.searchParams.get('r') || '';
    // 接口 / 清单 / SW 本身：实时直连，不缓存
    if (r.indexOf('api') === 0 || r === 'mock' || r === 'manifest' || r === 'sw') return;

    // 影像数据（缩略图 / DICOM 帧 / DICOMweb 代理，内容不可变）：缓存优先，命中即秒开，可离线
    if (r === 'dicom' || r === 'thumb' || r === 'wadoprx') {
        e.respondWith(
            caches.open(IMG_CACHE).then(function (c) {
                return c.match(req).then(function (cached) {
                    if (cached) return cached;
                    return fetch(req).then(function (res) {
                        if (res && res.ok) {
                            var copy = res.clone();
                            c.put(req, copy).then(function () { imgTrim(c, req.url); });
                        }
                        return res;
                    });
                });
            })
        );
        return;
    }

    // 静态资源：缓存优先 + 后台更新（stale-while-revalidate）
    if (url.pathname.indexOf(ASSET + '/') === 0) {
        e.respondWith(
            caches.match(req).then(function (cached) {
                var network = fetch(req).then(function (res) {
                    if (res && res.ok) {
                        var copy = res.clone();   // 必须在返回前同步克隆，避免响应体被消费
                        caches.open(CACHE).then(function (c) { c.put(req, copy); });
                    }
                    return res;
                }).catch(function () { return cached; });
                return cached || network;
            })
        );
        return;
    }

    // 页面导航：网络优先，离线回退缓存
    if (req.mode === 'navigate') {
        e.respondWith(
            fetch(req).then(function (res) {
                if (res && res.ok) {
                    var copy = res.clone();       // 必须在返回前同步克隆
                    caches.open(CACHE).then(function (c) { c.put(req, copy); });
                }
                return res;
            }).catch(function () {
                return caches.match(req).then(function (c) { return c || caches.match(HOME); });
            })
        );
    }
});
        <?php
        exit;
    }
}
