<?php
/**
 * views/partials/header.php — 公共页头外壳（品牌 + 导航）
 * 变量：$site（站点名）、$user（当前用户，可空）、$active（当前导航）、
 *       $pageTitle、$bodyClass、$extraCss、$page、$pageData
 */
$pvActive = isset($active) ? $active : '';
$pvUser = isset($user) ? $user : null;
$pvTitle = isset($pageTitle) && $pageTitle !== '' ? $pageTitle . ' · ' . $site : $site;
$pvHosp = pvw_hospital();
$pvPage = isset($page) ? $page : '';
$pvPageData = isset($pageData) ? $pageData : array();
$pvGuest = !empty($guest);   // 链接访客阅片：无登录、仅影像查看入口
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo pvw_e($pvTitle); ?></title>
<meta name="theme-color" content="#0b0f17">
<meta name="color-scheme" content="dark">
<meta name="application-name" content="<?php echo pvw_e($site); ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?php echo pvw_e($site); ?>">
<meta name="mobile-web-app-capable" content="yes">
<link rel="manifest" href="<?php echo pvw_e(pvw_url('manifest')); ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?php echo pvw_e(PvPwaController::iconUrl(32)); ?>">
<link rel="icon" type="image/png" sizes="16x16" href="<?php echo pvw_e(PvPwaController::iconUrl(16)); ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?php echo pvw_e(PvPwaController::iconUrl(180)); ?>">
<link rel="stylesheet" href="<?php echo pvw_asset('css/base.css'); ?>">
<link rel="stylesheet" href="<?php echo pvw_asset('css/ui.css'); ?>">
<?php if (!empty($extraCss)) { foreach ((array)$extraCss as $c) { ?>
<link rel="stylesheet" href="<?php echo pvw_asset('css/' . $c); ?>">
<?php } } ?>
<script>window.PV_BOOT = <?php echo json_encode(array(
    'home'     => pvw_url(''),
    'asset'    => PV_URL_ASSET,
    'api'      => pvw_url('api'),
    'viewer'   => pvw_url('viewer'),
    'manifest' => pvw_url('manifest'),
    'sw'       => pvw_url('sw'),
    'scope'    => PV_URL_SITE === '' ? '/' : PV_URL_SITE . '/',
    'site'     => $site,
    'version'  => PV_VERSION,
    'page'     => $pvPage,
    'data'     => $pvPageData,
    'csrf'     => pvw_csrf(),
    'roles'    => array('admin' => ($pvUser && $pvUser['role'] === 'admin')),
    'guest'    => $pvGuest,
), JSON_UNESCAPED_UNICODE); ?>;</script>
</head>
<body class="<?php echo pvw_e(isset($bodyClass) ? $bodyClass : ''); ?>">
<header class="pv-topbar<?php echo $pvGuest ? ' pv-topbar-guest' : ''; ?>">
    <a class="pv-brand pv-brand-refresh<?php echo $pvGuest ? ' pv-brand-guest' : ''; ?>" id="pvBrand"
       href="<?php echo pvw_e(pvw_url('')); ?>" title="点击软刷新当前页面">
        <img class="pv-logo" src="<?php echo pvw_e(PvPwaController::iconUrl(96)); ?>" width="40" height="40" alt="" draggable="false">
        <span class="pv-brand-name"><?php echo pvw_e($site); ?></span>
    </a>
    <nav class="pv-nav" id="pvNav">
        <?php if ($pvGuest) { ?>
        <a class="active" href="<?php echo pvw_e(pvw_url('viewer')); ?>" data-nav="viewer">影像查看</a>
        <?php } else { ?>
        <a class="<?php echo $pvActive === 'search' ? 'active' : ''; ?>" href="<?php echo pvw_e(pvw_url('search')); ?>" data-nav="search">患者查询</a>
        <a class="<?php echo $pvActive === 'viewer' ? 'active' : ''; ?>" href="<?php echo pvw_e(pvw_url('viewer')); ?>" data-nav="viewer">影像查看</a>
        <?php if ($pvUser && $pvUser['role'] === 'admin') { ?>
        <a class="<?php echo $pvActive === 'admin' ? 'active' : ''; ?>" href="<?php echo pvw_e(pvw_url('admin')); ?>" data-nav="admin">管理设置</a>
        <?php } ?>
        <?php } ?>
    </nav>
    <div class="pv-user">
        <?php if ($pvUser && !$pvGuest) { ?>
        <span class="pv-user-name"><?php echo pvw_e($pvUser['display_name'] !== '' ? $pvUser['display_name'] : $pvUser['username']); ?><?php echo $pvUser['role'] === 'admin' ? ' · 管理员' : ''; ?></span>
        <a class="pv-btn pv-btn-ghost pv-btn-sm" href="<?php echo pvw_e(pvw_url('logout')); ?>" data-no-nav="1"
           onclick="try{sessionStorage.removeItem('pacs_workspace_v1');sessionStorage.removeItem('pacs_search_v1');sessionStorage.removeItem('pacs_sidebar_w')}catch(e){}">退出</a>
        <?php } ?>
    </div>
</header>
<main id="pvMain" class="pv-main">
