<?php
/** views/viewer.php — 阅片器页面（片段） */
$page = 'viewer';
$pageTitle = '影像查看';
$active = 'viewer';
$bodyClass = 'pv-page-viewer';
$extraCss = PvAssets::pageCss('viewer');
$extraJs = PvAssets::pageJs('viewer');
$isGuest = !empty($guest);   // 链接访客阅片模式
// 是否被 iframe 嵌入：后端 Sec-Fetch-Dest 初值，前端 self!==top 会再纠正（最终权威）
$isEmbedded = !empty($embedded);
// 工具项显隐：普通访客直链隐藏「复制阅片直链」；嵌入模式再隐藏「阅片灯」
$hideCopyLink = $isGuest || $isEmbedded;
$hideLightbox = $isEmbedded;
$pageData = array(
    'uid'        => $uid,
    'uids'       => isset($uids) ? $uids : array(),
    'mode'       => $mode,
    'guest'      => $isGuest,
    'embedded'   => $isEmbedded,
    'guestToken' => isset($guestToken) ? (string)$guestToken : '',
    // 宿主来源：由嵌入方通过 ?host=<origin> 显式下发（如门诊一体化内嵌转发），
    // 用于宿主 postMessage 指令桥的严格来源校验；未提供时回退「信任直接父窗口」。
    'hostOrigin' => isset($_GET['host']) ? trim((string)$_GET['host']) : '',
    'studyLimit' => $isGuest ? 1 : (int)PvSettings::get('viewer_study_limit', '5'),
    'isAdmin'    => $isGuest ? false : PvAuth::isAdmin(),
    'about'      => array(
        'name'     => PvSettings::get('site_title', 'PACS 影像浏览器'),
        'version'  => PV_VERSION,
        'hospital' => pvw_hospital(),
        'report_hospital' => pvw_hospital_source(),   // 报告/DICOM 详情用接口来源医院名
        'icon'     => PvPwaController::iconUrl(96),
    ),
    'icons'      => pvw_icons(),
);
?>
<div id="pvViewer" class="pv-app<?php echo $isGuest ? ' pv-guest' : ''; ?>" data-pv="app">
    <div class="pv-vw-toolbar" data-pv="toolbar">
        <button type="button" class="pv-bigbtn" data-pv-act="toggle-sidebar" title="显示 / 隐藏左侧序列栏">
            <span class="pv-bi"><?php echo pvw_icon('sidebar'); ?></span><span class="pv-bl">序列栏</span></button>
        <button type="button" class="pv-bigbtn" data-pv-tool="wl" title="窗宽窗位工具：选中后在图像上左右拖动改窗宽(WW)，上下拖动改窗位(WL)；亦可随时用右键拖拽调节">
            <span class="pv-bi"><?php echo pvw_icon('wl'); ?></span><span class="pv-bl">窗宽窗位</span></button>
        <div class="pv-menu-wrap">
            <button type="button" class="pv-bigbtn" data-pv-menu="preset" title="常用窗宽窗位预设">
                <span class="pv-bi"><?php echo pvw_icon('preset'); ?></span><span class="pv-bl">预设窗 <span class="pv-caret"><?php echo pvw_icon('chevron-down'); ?></span></span></button>
            <div class="pv-menu" data-pv-menu-panel="preset">
                <button type="button" data-pv-preset="soft"><span class="ic"><?php echo pvw_icon('win-soft'); ?></span> 软组织窗 (400 / 40)</button>
                <button type="button" data-pv-preset="lung"><span class="ic"><?php echo pvw_icon('win-lung'); ?></span> 肺窗 (1500 / -600)</button>
                <button type="button" data-pv-preset="bone"><span class="ic"><?php echo pvw_icon('win-bone'); ?></span> 骨窗 (2000 / 350)</button>
                <button type="button" data-pv-preset="full"><span class="ic"><?php echo pvw_icon('win-full'); ?></span> <span data-preset-label="full">默认窗</span></button>
            </div>
        </div>
        <span class="pv-tsep"></span>
        <button type="button" class="pv-bigbtn" data-pv-tool="zoom" title="缩放：拖动或滚轮以指针为中心缩放">
            <span class="pv-bi"><?php echo pvw_icon('zoom'); ?></span><span class="pv-bl">缩放</span></button>
        <button type="button" class="pv-bigbtn" data-pv-tool="pan" title="平移：拖动移动画布">
            <span class="pv-bi"><?php echo pvw_icon('pan'); ?></span><span class="pv-bl">平移</span></button>
        <button type="button" class="pv-bigbtn" data-pv-act="prev" title="上一帧">
            <span class="pv-bi"><?php echo pvw_icon('prev'); ?></span><span class="pv-bl">上一帧</span></button>
        <button type="button" class="pv-bigbtn" data-pv-act="next" title="下一帧 / 滚轮连续翻帧">
            <span class="pv-bi"><?php echo pvw_icon('next'); ?></span><span class="pv-bl">下一帧</span></button>
        <div class="pv-menu-wrap">
            <button type="button" class="pv-bigbtn" data-pv-menu="layout" title="视图分栏布局">
                <span class="pv-bi"><?php echo pvw_icon('layout'); ?></span><span class="pv-bl">布局 <span class="pv-caret"><?php echo pvw_icon('chevron-down'); ?></span></span></button>
            <div class="pv-menu" data-pv-menu-panel="layout">
                <button type="button" data-pv-layout="1"><span class="ic"><?php echo pvw_icon('layout-1'); ?></span> 单视图</button>
                <button type="button" data-pv-layout="2h"><span class="ic"><?php echo pvw_icon('layout-2h'); ?></span> 左右双视图</button>
                <button type="button" data-pv-layout="2v"><span class="ic"><?php echo pvw_icon('layout-2v'); ?></span> 上下双视图</button>
                <button type="button" data-pv-layout="4"><span class="ic"><?php echo pvw_icon('layout-4'); ?></span> 四视图</button>
            </div>
        </div>
        <button type="button" class="pv-bigbtn" data-pv-act="fit" title="图像适应窗口">
            <span class="pv-bi"><?php echo pvw_icon('fit'); ?></span><span class="pv-bl">适应窗口</span></button>
        <button type="button" class="pv-bigbtn" data-pv-act="oneone" title="1:1 原始比例">
            <span class="pv-bi"><?php echo pvw_icon('oneone'); ?></span><span class="pv-bl">原图</span></button>
        <span class="pv-tsep"></span>
        <div class="pv-menu-wrap">
            <button type="button" class="pv-bigbtn" data-pv-menu="measure" title="测量工具">
                <span class="pv-bi"><?php echo pvw_icon('measure'); ?></span><span class="pv-bl">测量 <span class="pv-caret"><?php echo pvw_icon('chevron-down'); ?></span></span></button>
            <div class="pv-menu" data-pv-menu-panel="measure">
                <button type="button" data-pv-tool="length"><span class="ic"><?php echo pvw_icon('length'); ?></span> 测距（mm）</button>
                <button type="button" data-pv-tool="angle"><span class="ic"><?php echo pvw_icon('angle'); ?></span> 测角（°）</button>
                <button type="button" data-pv-tool="rect"><span class="ic"><?php echo pvw_icon('rect'); ?></span> 矩形 ROI</button>
                <button type="button" data-pv-tool="ellipse"><span class="ic"><?php echo pvw_icon('ellipse'); ?></span> 椭圆 ROI</button>
                <div class="pv-menu-sep"></div>
                <button type="button" data-pv-act="clear"><span class="ic"><?php echo pvw_icon('clear'); ?></span> 清除标注</button>
            </div>
        </div>
        <div class="pv-menu-wrap">
            <button type="button" class="pv-bigbtn" data-pv-menu="transform" title="图像变换">
                <span class="pv-bi"><?php echo pvw_icon('transform'); ?></span><span class="pv-bl">变换 <span class="pv-caret"><?php echo pvw_icon('chevron-down'); ?></span></span></button>
            <div class="pv-menu" data-pv-menu-panel="transform">
                <button type="button" data-pv-act="rotate-ccw"><span class="ic"><?php echo pvw_icon('rotate-ccw'); ?></span> 逆时针 90°</button>
                <button type="button" data-pv-act="rotate-cw"><span class="ic"><?php echo pvw_icon('rotate-cw'); ?></span> 顺时针 90°</button>
                <button type="button" data-pv-act="flip-h"><span class="ic"><?php echo pvw_icon('flip-h'); ?></span> 水平镜像</button>
                <button type="button" data-pv-act="flip-v"><span class="ic"><?php echo pvw_icon('flip-v'); ?></span> 垂直镜像</button>
                <button type="button" data-pv-act="invert"><span class="ic"><?php echo pvw_icon('invert'); ?></span> 正负片反色</button>
            </div>
        </div>
        <div class="pv-menu-wrap">
            <button type="button" class="pv-bigbtn" data-pv-menu="tools" title="更多工具">
                <span class="pv-bi"><?php echo pvw_icon('tools'); ?></span><span class="pv-bl">工具 <span class="pv-caret"><?php echo pvw_icon('chevron-down'); ?></span></span></button>
            <div class="pv-menu" data-pv-menu-panel="tools">
                <button type="button" data-pv-act="report"><span class="ic"><?php echo pvw_icon('report'); ?></span> 查看影像报告</button>
                <button type="button" data-pv-act="dicom-info"><span class="ic"><?php echo pvw_icon('dicom'); ?></span> DICOM 详情</button>
                <button type="button" data-pv-act="copy-link"<?php echo $hideCopyLink ? ' hidden' : ''; ?>><span class="ic"><?php echo pvw_icon('link'); ?></span> 复制阅片直链</button>
                <button type="button" data-pv-act="save-image"><span class="ic"><?php echo pvw_icon('save-image'); ?></span> 保存当前图像</button>
                <button type="button" data-pv-act="save-series"><span class="ic"><?php echo pvw_icon('save-series'); ?></span> 保存序列（ZIP）</button>
                <button type="button" data-pv-act="save-dicom"><span class="ic"><?php echo pvw_icon('dicom'); ?></span> 导出 DICOM（原始文件）</button>
                <div class="pv-menu-sep"></div>
                <button type="button" data-pv-act="lightbox"<?php echo $hideLightbox ? ' hidden' : ''; ?>><span class="ic"><?php echo pvw_icon('lightbox'); ?></span> 阅片灯</button>
                <div class="pv-menu-sep"></div>
                <button type="button" data-pv-act="shortcuts"><span class="ic"><?php echo pvw_icon('keyboard'); ?></span> 键盘快捷键</button>
            </div>
        </div>
        <button type="button" class="pv-bigbtn" data-pv-act="about" title="关于本软件">
            <span class="pv-bi"><?php echo pvw_icon('about'); ?></span><span class="pv-bl">关于</span></button>
        <span class="pv-spacer"></span>
        <?php if (!$isGuest) { ?>
        <button type="button" class="pv-bigbtn pv-bigbtn-exit" data-pv-act="back" title="关闭阅片，返回患者查询">
            <span class="pv-bi"><?php echo pvw_icon('close'); ?></span><span class="pv-bl">关闭</span></button>
        <?php } ?>
    </div>
    <div class="pv-vw-body">
        <aside class="pv-filmstrip" data-pv="filmstrip">
            <div class="pv-serieslist" data-pv="serieslist"></div>
            <?php if (!$isGuest) { ?>
            <button type="button" class="pv-closeall" data-pv="closeall" title="清空影像视图中全部检查序列">关闭全部</button>
            <?php } ?>
        </aside>
        <div class="pv-splitter" data-pv="splitter" title="拖动调节序列栏宽度"></div>
        <main class="pv-panes" data-pv="panes"></main>
    </div>
    <div class="pv-ctxmenu" data-pv="ctxmenu"></div>
</div>
