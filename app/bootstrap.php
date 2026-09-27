<?php
/**
 * ============================================================
 * tools/pacs_viewer/app/bootstrap.php — 独立 PACS 浏览器引导
 * ============================================================
 * 本工具是完全独立的 PHP 网站，代码与数据均限制在 tools/pacs_viewer/
 * 目录内，不与门诊一体化主系统共享任何文件、数据库或配置。
 *
 * 目录：
 *   public/    Web 根（唯一入口 index.php + assets 静态资源）
 *   app/       后端代码（引导 / 数据库 / 认证 / PACS 接口 / 控制器）
 *   views/     页面模板
 *   data/      自身 SQLite 数据库与会话文件（运行时生成）
 * ============================================================ */

define('PV_ROOT', dirname(__DIR__));                 // tools/pacs_viewer
define('PV_APP', PV_ROOT . '/app');
define('PV_VIEWS', PV_ROOT . '/views');
define('PV_DATA', PV_ROOT . '/data');
define('PV_PUBLIC', PV_ROOT . '/public');
define('PV_VERSION', '0.1.0');

date_default_timezone_set('Asia/Shanghai');
if (!is_dir(PV_DATA)) @mkdir(PV_DATA, 0775, true);
$pvSessDir = PV_DATA . '/session';
if (!is_dir($pvSessDir)) @mkdir($pvSessDir, 0775, true);
if (is_dir($pvSessDir) && is_writable($pvSessDir)) {
    session_save_path($pvSessDir);
}
session_name('PACSVIEWSID');
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

/* ---------- 计算部署路径（兼容「独立站点根」与「挂在项目 tools 下」两种方式） ----------
 *  ① root = tools/pacs_viewer/public    → 入口 /index.php，站点根 /
 *  ② root = 项目根（含 tools/）          → 入口 /tools/pacs_viewer/index.php
 *  ③ root = tools/pacs_viewer            → 入口 /index.php
 * 资源始终位于 public/assets 下，入口链接始终指向可执行脚本所在目录。 */
$pvScript  = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME']) : '/index.php';
$pvScriptDir = rtrim(str_replace('\\', '/', dirname($pvScript)), '/');
if (substr($pvScriptDir, -7) === '/public') {
    $pvPublicUrl = $pvScriptDir;                 // 直接以 public 为根
    $pvEntry = $pvScript;
} elseif ($pvScriptDir !== '' && $pvScriptDir !== '.') {
    $pvPublicUrl = $pvScriptDir . '/public';     // 根入口（index.php 在上级）
    $pvEntry = $pvScript;
} else {
    $pvPublicUrl = '';
    $pvEntry = '/index.php';
}
$pvSiteDir = rtrim(str_replace('\\', '/', dirname($pvEntry)), '/');
if ($pvSiteDir === '.' || $pvSiteDir === '\\') $pvSiteDir = '';

define('PV_URL_PUBLIC', $pvPublicUrl);           // public 目录 URL 前缀
define('PV_URL_ASSET', $pvPublicUrl . '/assets'); // 静态资源 URL 前缀
define('PV_URL_SITE', $pvSiteDir);               // 站点目录 URL 前缀（用于页面/接口链接）
define('PV_ENTRY', $pvEntry);                    // 入口脚本 URL

/* ---------- 载入核心类 ---------- */
require_once PV_APP . '/Database.php';
require_once PV_APP . '/Settings.php';
require_once PV_APP . '/Auth.php';
require_once PV_APP . '/Pacs/DemoPacs.php';
require_once PV_APP . '/Pacs/PacsClient.php';
require_once PV_APP . '/Services/StudyService.php';
require_once PV_APP . '/Repositories/UserRepository.php';
require_once PV_APP . '/Repositories/QueryLogRepository.php';
require_once PV_APP . '/Controllers/AuthController.php';
require_once PV_APP . '/Controllers/InstallController.php';
require_once PV_APP . '/Controllers/SearchController.php';
require_once PV_APP . '/Controllers/ViewerController.php';
require_once PV_APP . '/Controllers/AdminController.php';
require_once PV_APP . '/Controllers/ApiController.php';

PvDatabase::init();   // 首次访问自动建库 / 建表 / 播种管理员

/* ============================================================
 * 通用助手（统一 pvw_ 前缀，避免与宿主环境冲突）
 * ============================================================ */

/** HTML 转义 */
function pvw_e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** 站点内部链接（?r=路由） */
function pvw_url($r = '', array $params = array()) {
    $q = $r !== '' ? array('r' => $r) : array();
    foreach ($params as $k => $v) { if ($v !== null && $v !== '') $q[$k] = $v; }
    $base = PV_URL_SITE === '' ? '/' : PV_URL_SITE . '/';
    return $base . ($q ? '?' . http_build_query($q) : '');
}

/** 静态资源链接 */
function pvw_asset($path) { return PV_URL_ASSET . '/' . ltrim($path, '/'); }

/** 302 跳转 */
function pvw_redirect($url) { header('Location: ' . $url); exit; }

/** 统一 JSON 输出 */
function pvw_json($code, $msg = '', $data = null) {
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('code' => (int)$code, 'msg' => (string)$msg, 'data' => $data), JSON_UNESCAPED_UNICODE);
    exit;
}

/** 取请求参数（GET/POST） */
function pvw_input($key, $default = '') {
    if (isset($_POST[$key])) return is_string($_POST[$key]) ? trim($_POST[$key]) : $_POST[$key];
    if (isset($_GET[$key]))  return is_string($_GET[$key]) ? trim($_GET[$key]) : $_GET[$key];
    return $default;
}

/** 生成/取 CSRF 令牌 */
function pvw_csrf() {
    if (empty($_SESSION['pv_csrf'])) $_SESSION['pv_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['pv_csrf'];
}
function pvw_csrf_check() {
    $t = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
    if ($t === '' || empty($_SESSION['pv_csrf']) || !hash_equals($_SESSION['pv_csrf'], $t)) {
        pvw_json(403, '安全校验失败，请刷新页面重试');
    }
}

/** 渲染视图 */
function pvw_view($name, array $data = array()) {
    $file = PV_VIEWS . '/' . str_replace('..', '', $name) . '.php';
    if (!is_file($file)) { http_response_code(500); echo 'view not found: ' . pvw_e($name); exit; }
    extract($data, EXTR_SKIP);
    require $file;
}
