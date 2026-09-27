<?php
/**
 * ============================================================
 * tools/pacs_viewer/public/index.php — 独立 PACS 浏览器唯一入口
 * ============================================================
 * 独立站点的前端控制器：初始化引导后按 ?r= 路由分发到控制器。
 * 与门诊一体化主系统完全无关，可独立部署（Web 根指向本 public 目录）。
 * ============================================================ */
require dirname(__DIR__) . '/app/bootstrap.php';

$r = isset($_GET['r']) ? trim((string)$_GET['r']) : '';
$isPost = (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'POST');

$routes = array(
    ''                      => array('PvAuthController', null),
    'login'                 => array('PvAuthController', $isPost ? 'login' : 'showLogin'),
    'logout'                => array('PvAuthController', 'logout'),
    'install'               => array('PvInstallController', $isPost ? 'submit' : 'show'),
    'install/submit'        => array('PvInstallController', 'submit'),
    'search'                => array('PvSearchController', 'index'),
    'viewer'                => array('PvViewerController', 'show'),
    'admin'                 => array('PvAdminController', 'index'),
    'admin/save'            => array('PvAdminController', 'save'),
    'admin/user-create'     => array('PvAdminController', 'userCreate'),
    'admin/user-update'     => array('PvAdminController', 'userUpdate'),
    'admin/user-status'     => array('PvAdminController', 'userStatus'),
    'admin/user-password'   => array('PvAdminController', 'userPassword'),
    'admin/user-delete'     => array('PvAdminController', 'userDelete'),
    'admin/log-clear'       => array('PvAdminController', 'logClear'),
    'api/search'            => array('PvApiController', 'search'),
    'api/study'             => array('PvApiController', 'study'),
    'api/ping'              => array('PvApiController', 'ping'),
);

/* 首次运行安装门禁：未完成安装时，除安装向导外一律引导至安装页；
 * 已完成安装后，安装入口不再可用。 */
$pvInstalled = PvSettings::isInstalled();
$isInstallRoute = ($r === 'install' || $r === 'install/submit');
if (!$pvInstalled && !$isInstallRoute) {
    pvw_redirect(pvw_url('install'));
}
if ($pvInstalled && $r === 'install') {
    pvw_redirect(pvw_url(PvAuth::check() ? 'search' : 'login'));
}

if ($r === '') {
    pvw_redirect(pvw_url(PvAuth::check() ? 'search' : 'login'));
}

if (!isset($routes[$r])) {
    http_response_code(404);
    pvw_view('error', array('message' => '页面不存在：' . $r));
    exit;
}

list($class, $method) = $routes[$r];
if ($method === null || !method_exists($class, $method)) {
    http_response_code(405);
    pvw_view('error', array('message' => '请求方式不被支持'));
    exit;
}
call_user_func(array($class, $method));
