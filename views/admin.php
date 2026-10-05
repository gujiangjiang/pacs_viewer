<?php
/** views/admin.php — 管理员设置（片段） */
$page = 'admin';
$pageTitle = '管理设置';
$active = 'admin';
$bodyClass = 'pv-page-admin';
$extraCss = PvAssets::pageCss('admin');
$extraJs = PvAssets::pageJs('admin');
$s = $settings;
$v = function ($k, $d = '') use ($s) { return isset($s[$k]) ? $s[$k] : $d; };
$curTab = isset($tab) ? $tab : 'basic';
$tabCls = function ($t) use ($curTab) { return $curTab === $t ? ' active' : ''; };
$pageData = array(
    'flash' => isset($flash) ? $flash : '',
    // 日志保留上限（可选项，空串表示不限制）
    'logMaxCount' => $v('log_max_count', ''),
    'logMaxDays'  => $v('log_max_days', ''),
    // 缓存设置（容量以 MB 展示；空串表示不限制）
    'cache' => array(
        'apcuEnabled'    => $v('cache_apcu_enabled', '1') !== '0',
        'diskEnabled'    => $v('cache_disk_enabled', '1') !== '0',
        'maxMb'          => ($v('cache_max_bytes', '') !== '') ? round((float)$v('cache_max_bytes', '') / 1048576, 2) : '',
        'diskMaxMb'      => ($v('cache_disk_max_bytes', '') !== '') ? round((float)$v('cache_disk_max_bytes', '') / 1048576, 2) : '',
        'maxDays'        => $v('cache_max_days', ''),
    ),
);
?>
<div class="pv-tabs">
    <button type="button" class="pv-tab<?php echo $tabCls('basic'); ?>" data-tab="basic"><span class="ic"><?php echo pvw_icon('sliders'); ?></span><span class="lbl">基础设置</span></button>
    <button type="button" class="pv-tab<?php echo $tabCls('pacs'); ?>" data-tab="pacs"><span class="ic"><?php echo pvw_icon('server'); ?></span><span class="lbl">外部接口</span></button>
    <button type="button" class="pv-tab<?php echo $tabCls('users'); ?>" data-tab="users"><span class="ic"><?php echo pvw_icon('users'); ?></span><span class="lbl">账号管理</span></button>
    <button type="button" class="pv-tab<?php echo $tabCls('logs'); ?>" data-tab="logs"><span class="ic"><?php echo pvw_icon('view-list'); ?></span><span class="lbl">操作日志</span></button>
    <button type="button" class="pv-tab<?php echo $tabCls('mock'); ?>" data-tab="mock"><span class="ic"><?php echo pvw_icon('sample'); ?></span><span class="lbl">模拟服务器</span></button>
    <button type="button" class="pv-tab<?php echo $tabCls('storage'); ?>" data-tab="storage"><span class="ic"><?php echo pvw_icon('database'); ?></span><span class="lbl">存储情况</span></button>
</div>


<?php include PV_VIEWS . '/partials/admin_basic.php'; ?>

<?php include PV_VIEWS . '/partials/admin_pacs.php'; ?>

<?php include PV_VIEWS . '/partials/admin_users.php'; ?>

<?php include PV_VIEWS . '/partials/admin_logs.php'; ?>

<!-- 模拟服务器 -->
<section class="pv-tabpane<?php echo $tabCls('mock'); ?>" data-pane="mock">
    <?php
    $anatomy = PvMockAnatomyConfig::entries();
    include PV_VIEWS . '/partials/mock_pane.php';
    ?>
</section>

<?php include PV_VIEWS . '/partials/admin_storage.php'; ?>
