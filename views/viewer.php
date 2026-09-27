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
        <button type="button" class="pv-bigbtn" data-pv-act="toggle-sidebar" title="显示 / 隐藏左侧序列栏">
            <span class="pv-bi">☰</span><span class="pv-bl">序列栏</span></button>
        <button type="button" class="pv-bigbtn" data-pv-tool="wl" title="窗宽窗位工具：选中后在图像上左右拖动改窗宽(WW)，上下拖动改窗位(WL)；亦可随时用右键拖拽调节">
            <span class="pv-bi">◐</span><span class="pv-bl">窗宽窗位</span></button>
        <div class="pv-menu-wrap">
            <button type="button" class="pv-bigbtn" data-pv-menu="preset" title="常用窗宽窗位预设">
                <span class="pv-bi">🎚</span><span class="pv-bl">预设窗 ▾</span></button>
            <div class="pv-menu" data-pv-menu-panel="preset">
                <button type="button" data-pv-preset="soft">软组织窗 (400 / 40)</button>
                <button type="button" data-pv-preset="lung">肺窗 (1500 / -600)</button>
                <button type="button" data-pv-preset="bone">骨窗 (2000 / 350)</button>
                <button type="button" data-pv-preset="full">默认窗 (2500 / 250)</button>
            </div>
        </div>
        <span class="pv-tsep"></span>
        <button type="button" class="pv-bigbtn" data-pv-tool="zoom" title="缩放：拖动或滚轮以指针为中心缩放">
            <span class="pv-bi">🔍</span><span class="pv-bl">缩放</span></button>
        <button type="button" class="pv-bigbtn" data-pv-tool="pan" title="平移：拖动移动画布">
            <span class="pv-bi">✥</span><span class="pv-bl">平移</span></button>
        <button type="button" class="pv-bigbtn" data-pv-act="prev" title="上一帧">
            <span class="pv-bi">⏮</span><span class="pv-bl">上一帧</span></button>
        <button type="button" class="pv-bigbtn" data-pv-act="next" title="下一帧 / 滚轮连续翻帧">
            <span class="pv-bi">⏭</span><span class="pv-bl">下一帧</span></button>
        <button type="button" class="pv-bigbtn" data-pv-act="fit" title="图像适应窗口">
            <span class="pv-bi">⛶</span><span class="pv-bl">适应窗口</span></button>
        <button type="button" class="pv-bigbtn" data-pv-act="oneone" title="1:1 原始比例">
            <span class="pv-bi">1:1</span><span class="pv-bl">原图</span></button>
        <span class="pv-tsep"></span>
        <div class="pv-menu-wrap">
            <button type="button" class="pv-bigbtn" data-pv-menu="measure" title="测量工具">
                <span class="pv-bi">📏</span><span class="pv-bl">测量 ▾</span></button>
            <div class="pv-menu" data-pv-menu-panel="measure">
                <button type="button" data-pv-tool="length">📏 测距（mm）</button>
                <button type="button" data-pv-tool="angle">📐 测角（°）</button>
                <button type="button" data-pv-tool="rect">▭ 矩形 ROI</button>
                <button type="button" data-pv-tool="ellipse">⬭ 椭圆 ROI</button>
            </div>
        </div>
        <div class="pv-menu-wrap">
            <button type="button" class="pv-bigbtn" data-pv-menu="transform" title="图像变换">
                <span class="pv-bi">🔄</span><span class="pv-bl">变换 ▾</span></button>
            <div class="pv-menu" data-pv-menu-panel="transform">
                <button type="button" data-pv-act="rotate-ccw">↺ 逆时针 90°</button>
                <button type="button" data-pv-act="rotate-cw">↻ 顺时针 90°</button>
                <button type="button" data-pv-act="flip-h">⇋ 水平镜像</button>
                <button type="button" data-pv-act="flip-v">⇅ 垂直镜像</button>
                <button type="button" data-pv-act="invert">◑ 正负片反色</button>
            </div>
        </div>
        <div class="pv-menu-wrap">
            <button type="button" class="pv-bigbtn" data-pv-menu="tools" title="更多工具">
                <span class="pv-bi">🧰</span><span class="pv-bl">工具 ▾</span></button>
            <div class="pv-menu" data-pv-menu-panel="tools">
                <button type="button" data-pv-act="copy-link">🔗 复制阅片直链</button>
                <button type="button" data-pv-act="save-image">💾 保存当前图像</button>
                <button type="button" data-pv-act="save-series">🗂 保存序列（ZIP）</button>
                <div class="pv-menu-sep"></div>
                <button type="button" data-pv-act="clear">🧹 清除标注</button>
            </div>
        </div>
        <span class="pv-spacer"></span>
        <button type="button" class="pv-bigbtn pv-bigbtn-exit" data-pv-act="back" title="退出阅片，返回检索">
            <span class="pv-bi">⏻</span><span class="pv-bl">退出</span></button>
    </div>
    <div class="pv-vw-body">
        <aside class="pv-filmstrip" data-pv="filmstrip">
            <details class="pv-patientcard" data-pv="patientcard" open>
                <summary>
                    <span class="pv-pc-name" data-pv="pc-name">—</span>
                    <span class="pv-pc-sub" data-pv="pc-sub"></span>
                </summary>
                <dl class="pv-pc-body" data-pv="pc-body"></dl>
            </details>
            <div class="pv-serieslist" data-pv="serieslist"></div>
        </aside>
        <main class="pv-stage">
            <div class="pv-canvas-wrap"><canvas data-pv="canvas"></canvas></div>
            <div class="pv-vw-title" data-pv="title"></div>
            <div class="pv-vw-status" data-pv="status"></div>
            <div class="pv-vw-scroll" data-pv="vscroll" hidden>
                <div class="pv-vw-scroll-track" data-pv="vscroll-track">
                    <div class="pv-vw-scroll-thumb" data-pv="vscroll-thumb"></div>
                </div>
                <div class="pv-vw-scroll-bubble" data-pv="vscroll-bubble"></div>
            </div>
        </main>
    </div>
</div>
