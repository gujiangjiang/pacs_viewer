<?php
/** views/viewer.php — 阅片器页面（片段） */
$page = 'viewer';
$pageTitle = '影像阅片';
$active = 'search';
$bodyClass = 'pv-page-viewer';
$extraCss = array('viewer.css');
$extraJs = array('modules/render.js', 'modules/osd.js', 'modules/sidebar.js', 'modules/toolbar.js', 'modules/measurements.js', 'viewer.js');
$pageData = array(
    'uid'     => $uid,
    'isAdmin' => PvAuth::isAdmin(),
    'direct'  => pvw_url('viewer', array('uid' => $uid)),
);
?>
<div id="pvViewer" class="pv-app" data-pv="app">
    <div class="pv-vw-toolbar" data-pv="toolbar">
        <button type="button" class="pv-tbtn" data-pv-tool="wl">◐ 窗宽窗位</button>
        <button type="button" class="pv-tbtn" data-pv-tool="zoom">🔍 缩放</button>
        <button type="button" class="pv-tbtn" data-pv-tool="pan">✥ 平移</button>
        <span class="pv-tsep"></span>
        <button type="button" class="pv-tbtn" data-pv-tool="length">📏 测距</button>
        <button type="button" class="pv-tbtn" data-pv-tool="angle">📐 测角</button>
        <button type="button" class="pv-tbtn" data-pv-tool="rect">▭ 矩形 ROI</button>
        <button type="button" class="pv-tbtn" data-pv-tool="ellipse">⬭ 椭圆 ROI</button>
        <span class="pv-tsep"></span>
        <details class="pv-dd">
            <summary>🎚 预设窗</summary>
            <div class="pv-dd-menu">
                <button type="button" data-pv-preset="soft">软组织窗 (400 / 40)</button>
                <button type="button" data-pv-preset="lung">肺窗 (1500 / -600)</button>
                <button type="button" data-pv-preset="bone">骨窗 (2000 / 350)</button>
                <button type="button" data-pv-preset="full">默认窗 (2500 / 250)</button>
            </div>
        </details>
        <span class="pv-tsep"></span>
        <button type="button" class="pv-tbtn" data-pv-act="rotate-ccw">↺ 左旋</button>
        <button type="button" class="pv-tbtn" data-pv-act="rotate-cw">↻ 右旋</button>
        <button type="button" class="pv-tbtn" data-pv-act="flip-h">⇋ 镜像H</button>
        <button type="button" class="pv-tbtn" data-pv-act="flip-v">⇅ 镜像V</button>
        <button type="button" class="pv-tbtn" data-pv-act="invert">◑ 反色</button>
        <span class="pv-tsep"></span>
        <button type="button" class="pv-tbtn" data-pv-act="prev">◀ 上一帧</button>
        <button type="button" class="pv-tbtn" data-pv-act="next">下一帧 ▶</button>
        <button type="button" class="pv-tbtn" data-pv-act="clear">🧹 清屏</button>
        <span class="pv-tsep"></span>
        <button type="button" class="pv-tbtn" data-pv-act="fit">⤢ 适应窗口</button>
        <button type="button" class="pv-tbtn" data-pv-act="oneone">1:1 原图</button>
        <button type="button" class="pv-tbtn pv-tbtn-accent" data-pv-act="toggle-sidebar" title="显示 / 隐藏左侧序列栏">⇤ 序列栏</button>
        <button type="button" class="pv-tbtn" data-pv-act="copy-link" title="复制本检查的阅片直链（可分享或供外部系统调用）">🔗 复制直链</button>
        <button type="button" class="pv-tbtn" data-pv-act="back">← 返回检索</button>
    </div>
    <div class="pv-vw-body">
        <aside class="pv-filmstrip" data-pv="filmstrip"></aside>
        <main class="pv-stage">
            <div class="pv-canvas-wrap"><canvas data-pv="canvas"></canvas></div>
            <div class="pv-vw-title" data-pv="title"></div>
            <div class="pv-vw-status" data-pv="status"></div>
            <div class="pv-vw-hud" data-pv="topinfo"></div>
        </main>
    </div>
</div>
