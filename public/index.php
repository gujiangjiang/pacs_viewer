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
    'admin/icon-upload'     => array('PvAdminController', 'iconUpload'),
    'admin/icon-reset'      => array('PvAdminController', 'iconReset'),
    'mockserver'            => array('PvMockController', 'index'),
    'mock'                  => array('PvMockController', 'api'),
    'api/mock/save'         => array('PvMockController', 'save'),
    'api/mock/key'          => array('PvMockController', 'regenKey'),
    'api/mock/apply'        => array('PvMockController', 'apply'),
    'api/mock/patients'     => array('PvMockController', 'patients'),
    'api/mock/fhir-test'    => array('PvMockController', 'fhirTest'),
    'api/search'            => array('PvApiController', 'search'),
    'api/study'             => array('PvApiController', 'study'),
    'api/ping'              => array('PvApiController', 'ping'),
    'manifest'              => array('PvPwaController', 'manifest'),
    'sw'                    => array('PvPwaController', 'sw'),
    'icon'                  => array('PvPwaController', 'icon'),
    'upload'                => array('PvUploadController', 'upload'),
    'upload/delete'         => array('PvUploadController', 'delete'),
    'file'                  => array('PvUploadController', 'file'),
);

/* 首次运行安装门禁：未完成安装时，除安装向导外一律引导至安装页；
 * 已完成安装后，安装入口不再可用。 */
$pvInstalled = PvSettings::isInstalled();
$isInstallRoute = ($r === 'install' || $r === 'install/submit');
$pvPublicAsset = ($r === 'mock' || $r === 'manifest' || $r === 'sw' || $r === 'icon');
if (!$pvInstalled && !$isInstallRoute && !$pvPublicAsset) {
    pvw_redirect(pvw_url('install'));
}
if ($pvInstalled && $r === 'install') {
    pvw_redirect(pvw_url(PvAuth::check() ? '' : 'login'));
}

/* 站点根路径直接渲染检索页（避免 / → ?r=search 的重定向导致地址栏闪烁）；
 * 未登录则引导至登录。 */
if ($r === '') {
    if (!PvAuth::check()) pvw_redirect(pvw_url('login'));
    PvSearchController::index();
    exit;
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
