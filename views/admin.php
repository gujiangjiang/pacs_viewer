<?php
/** views/admin.php — 管理员设置（片段） */
$page = 'admin';
$pageTitle = '管理设置';
$active = 'admin';
$bodyClass = 'pv-page-admin';
$extraCss = array('admin.css', 'mock.css');
$extraJs = array('admin-users.js', 'admin-storage.js', 'admin.js', 'mock.js');
$s = $settings;
$v = function ($k, $d = '') use ($s) { return isset($s[$k]) ? $s[$k] : $d; };
$curTab = isset($tab) ? $tab : 'basic';
$tabCls = function ($t) use ($curTab) { return $curTab === $t ? ' active' : ''; };
$pageData = array('flash' => isset($flash) ? $flash : '');
?>
<div class="pv-tabs">
    <button type="button" class="pv-tab<?php echo $tabCls('basic'); ?>" data-tab="basic">基础设置</button>
    <button type="button" class="pv-tab<?php echo $tabCls('pacs'); ?>" data-tab="pacs">外部接口</button>
    <button type="button" class="pv-tab<?php echo $tabCls('users'); ?>" data-tab="users">账号管理</button>
    <button type="button" class="pv-tab<?php echo $tabCls('logs'); ?>" data-tab="logs">检索日志</button>
    <button type="button" class="pv-tab<?php echo $tabCls('mock'); ?>" data-tab="mock">模拟服务器</button>
    <button type="button" class="pv-tab<?php echo $tabCls('storage'); ?>" data-tab="storage">存储情况</button>
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
