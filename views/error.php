<?php
/** views/error.php — 通用错误页 */
$user = PvAuth::user();
$site = PvSettings::get('site_title', 'PACS 影像浏览器');
$pageTitle = '出错了';
$active = '';
include PV_VIEWS . '/partials/header.php';
?>
<div class="pv-card pv-empty">
    <div class="pv-empty-ico"><?php echo pvw_icon('alert'); ?></div>
    <div class="pv-empty-title"><?php echo pvw_e(isset($message) ? $message : '请求无法处理'); ?></div>
    <a class="pv-btn pv-btn-primary" href="<?php echo pvw_e(pvw_url('search')); ?>">返回检索</a>
</div>
<?php include PV_VIEWS . '/partials/footer.php'; ?>
