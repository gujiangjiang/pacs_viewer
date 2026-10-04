<?php
/**
 * ============================================================
 * app/Support/Assets.php — 前端静态资源清单（单一事实来源）
 * ============================================================
 * 页面额外 CSS / JS 与 Service Worker 预缓存清单集中于此，避免在视图、
 * PWA 控制器之间重复维护导致漂移。JS 顺序敏感：基础模块必须先于其
 * 「扩展 / 装配」文件（如 pane.js 先于 pane-*.js，viewer.js 先于 viewer-*.js）。
 *
 * 新增前端模块时：在对应页面的 js 列表中登记，`precache()` 会自动纳入离线缓存。
 * ============================================================ */
class PvAssets {

    /** 公共 CSS（页头固定引入） */
    private static $commonCss = array('base.css', 'ui.css');
    /** 公共 JS（页脚固定引入） */
    private static $commonJs  = array('api.js', 'ui.js', 'modules/controls.js', 'spa.js', 'pwa.js');

    /** 各页面额外资源（css / js 均为相对 assets 的路径，顺序敏感） */
    private static $pages = array(
        'search' => array(
            'css' => array('search.css'),
            'js'  => array('modules/scroll.js', 'search.js'),
        ),
        'viewer' => array(
            'css' => array('viewer.css'),
            'js'  => array(
                'modules/dicom.js', 'modules/render.js', 'modules/osd.js', 'modules/sidebar.js',
                'modules/toolbar.js', 'modules/measurements.js', 'modules/zip.js', 'modules/decoder.js',
                'modules/pane.js', 'modules/pane-annotations.js', 'modules/pane-scrollbar.js',
                'modules/pane-export.js', 'modules/pane-info.js',
                'viewer.js', 'modules/viewer-report.js', 'modules/viewer-ctxmenu.js', 'modules/viewer-session.js',
            ),
        ),
        'admin' => array(
            'css' => array('admin.css', 'mock.css'),
            'js'  => array('modules/scroll.js', 'admin-users.js', 'admin-storage.js', 'admin.js', 'mock.js'),
        ),
    );

    /** 不随页面直出、但需离线缓存的后台 JS（如解码 Worker） */
    private static $extraJs = array('modules/dicom-worker.js');

    public static function commonCss() { return self::$commonCss; }
    public static function commonJs()  { return self::$commonJs; }

    /** 指定页面的额外 CSS（未知页面返回空数组） */
    public static function pageCss($page) { return isset(self::$pages[$page]['css']) ? self::$pages[$page]['css'] : array(); }
    /** 指定页面的额外 JS（未知页面返回空数组） */
    public static function pageJs($page)  { return isset(self::$pages[$page]['js'])  ? self::$pages[$page]['js']  : array(); }

    /**
     * 全部静态资源相对路径（供 Service Worker 预缓存；去重且顺序稳定）。
     * 返回如 ['css/base.css', 'js/api.js', 'js/modules/pane.js', ...]。
     */
    public static function precache() {
        $out = array();
        foreach (self::$commonCss as $c) $out[] = 'css/' . $c;
        foreach (self::$pages as $p) { foreach ($p['css'] as $c) $out[] = 'css/' . $c; }
        foreach (self::$commonJs as $j) $out[] = 'js/' . $j;
        foreach (self::$pages as $p) { foreach ($p['js'] as $j) $out[] = 'js/' . $j; }
        foreach (self::$extraJs as $j) $out[] = 'js/' . $j;
        return array_values(array_unique($out));
    }
}
