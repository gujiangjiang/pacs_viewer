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
define('PV_VERSION', '1.7.3');

date_default_timezone_set('Asia/Shanghai');
if (!is_dir(PV_DATA)) @mkdir(PV_DATA, 0775, true);
$pvSessDir = PV_DATA . '/session';
if (!is_dir($pvSessDir)) @mkdir($pvSessDir, 0775, true);
if (is_dir($pvSessDir) && is_writable($pvSessDir)) {
    session_save_path($pvSessDir);
}
session_name('PACSVIEWSID');
/* 会话 Cookie 安全属性（必须在 session_start 之前固定）：
 * HttpOnly 防脚本窃取；SameSite=Lax 防跨站请求携带；HTTPS 环境附加 Secure。
 * 非 HTTPS 的本地部署自动降级为 Secure=off，不改变可用性。 */
$pvHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params(array(
        'lifetime' => 0, 'path' => '/', 'secure' => $pvHttps,
        'httponly' => true, 'samesite' => 'Lax',
    ));
} else {
    session_set_cookie_params(0, '/', '', $pvHttps, true);   // PHP < 7.3：无 samesite 参数
}
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
require_once PV_APP . '/Support/icons.php';
require_once PV_APP . '/Support/Cache.php';
require_once PV_APP . '/Support/Http.php';
require_once PV_APP . '/Support/Dicom.php';
require_once PV_APP . '/Support/DicomTags.php';
require_once PV_APP . '/Support/Number.php';
require_once PV_APP . '/Support/LogLimits.php';
require_once PV_APP . '/Support/Assets.php';
require_once PV_APP . '/Settings.php';
require_once PV_APP . '/Auth.php';
require_once PV_APP . '/Guest.php';
require_once PV_APP . '/Pacs/DemoPacs.php';
require_once PV_APP . '/Pacs/PacsClient.php';
require_once PV_APP . '/Pacs/FhirClient.php';
require_once PV_APP . '/Pacs/DicomWebClient.php';
require_once PV_APP . '/Pacs/MockServer.php';
require_once PV_APP . '/Pacs/AcquisitionStore.php';
require_once PV_APP . '/Pacs/Mock/Contracts/SliceGeneratorInterface.php';
require_once PV_APP . '/Pacs/Mock/Contracts/VolumeGeneratorInterface.php';
require_once PV_APP . '/Pacs/Mock/Utils/ProceduralNoise.php';
require_once PV_APP . '/Pacs/Mock/Utils/GeometryHelper.php';
require_once PV_APP . '/Pacs/Mock/Utils/DicomTagBuilder.php';
require_once PV_APP . '/Pacs/Mock/Utils/MockCache.php';
require_once PV_APP . '/Pacs/Mock/Generators/AbstractGenerator.php';
require_once PV_APP . '/Pacs/Mock/MockAnatomyConfig.php';
require_once PV_APP . '/Pacs/Mock/Generators/CT/HeadCT.php';
require_once PV_APP . '/Pacs/Mock/Generators/CT/ChestCT.php';
require_once PV_APP . '/Pacs/Mock/Generators/CT/LumbarCT.php';
require_once PV_APP . '/Pacs/Mock/Generators/CT/AbdomenCT.php';
require_once PV_APP . '/Pacs/Mock/Generators/MR/AbstractMR.php';
require_once PV_APP . '/Pacs/Mock/Generators/MR/HeadMR.php';
require_once PV_APP . '/Pacs/Mock/Generators/MR/LumbarMR.php';
require_once PV_APP . '/Pacs/Mock/Generators/MR/KneeMR.php';
require_once PV_APP . '/Pacs/Mock/Generators/DR/ChestDR.php';
require_once PV_APP . '/Pacs/Mock/Generators/DR/LimbDR.php';
require_once PV_APP . '/Pacs/Mock/Generators/US/AbstractUS.php';
require_once PV_APP . '/Pacs/Mock/Generators/US/AbdomenUS.php';
require_once PV_APP . '/Pacs/Mock/Generators/US/CardiacUS.php';
require_once PV_APP . '/Pacs/Mock/Generators/Generic/GenericPlaceholder.php';
require_once PV_APP . '/Pacs/Mock/MockDispatcher.php';
require_once PV_APP . '/Services/StudyService.php';
require_once PV_APP . '/Services/IconRenderer.php';
require_once PV_APP . '/Services/UploadStore.php';
require_once PV_APP . '/Services/StorageService.php';
require_once PV_APP . '/Services/SystemLogService.php';
require_once PV_APP . '/Repositories/UserRepository.php';
require_once PV_APP . '/Repositories/QueryLogRepository.php';
require_once PV_APP . '/Repositories/ActivityLogRepository.php';
require_once PV_APP . '/Controllers/AuthController.php';
require_once PV_APP . '/Controllers/InstallController.php';
require_once PV_APP . '/Controllers/SearchController.php';
require_once PV_APP . '/Controllers/ViewerController.php';
require_once PV_APP . '/Controllers/AdminController.php';
require_once PV_APP . '/Controllers/MockController.php';
require_once PV_APP . '/Controllers/PwaController.php';
require_once PV_APP . '/Controllers/UploadController.php';
require_once PV_APP . '/Controllers/ApiController.php';
require_once PV_APP . '/Controllers/DicomWebController.php';
require_once PV_APP . '/Controllers/WadoProxyController.php';

PvDatabase::init();   // 首次访问自动建库 / 建表 / 播种管理员

/* ============================================================
 * 通用助手（统一 pvw_ 前缀，避免与宿主环境冲突）
 * ============================================================ */

/** 接口返回的机构名称（DICOMweb InstitutionName / FHIR Organization，自动记录） */
function pvw_hospital_api() {
    // 内置模拟数据来源：机构名取「模拟数据机构名称」，避免沿用既往 FHIR 机构名
    if (PvMockServer::enabled() && PvMockServer::source() !== 'fhir') {
        $ep = (string)PvSettings::get('pacs_endpoint', '');
        if ($ep === '' || PvMockServer::isSelfEndpoint($ep)) {
            return PvMockServer::builtinInstitution();
        }
    }
    return trim((string)PvSettings::get('pacs_hospital_name', ''));
}

/** 管理员配置的医院名称（覆盖值，可为空） */
function pvw_hospital_override() {
    return trim((string)PvSettings::get('hospital_name', ''));
}

/** 全局展示用医院名称：管理员覆盖 > 接口返回 > 项目名称 > 默认医院 */
function pvw_hospital() {
    $h = pvw_hospital_override();
    if ($h !== '') return $h;
    $a = pvw_hospital_api();
    if ($a !== '') return $a;
    $s = trim((string)PvSettings::get('site_title', ''));
    return $s !== '' ? $s : '默认医院';
}

/** 接口来源医院名称（DICOM 详情 / 影像报告专用）：接口返回 > 项目名称 > 默认医院（不受管理员覆盖影响） */
function pvw_hospital_source() {
    $a = pvw_hospital_api();
    if ($a !== '') return $a;
    $s = trim((string)PvSettings::get('site_title', ''));
    return $s !== '' ? $s : '默认医院';
}

/** HTML 转义 */
function pvw_e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** 站点内部链接（?r=路由） */
function pvw_url($r = '', array $params = array()) {
    $q = $r !== '' ? array('r' => $r) : array();
    foreach ($params as $k => $v) { if ($v !== null && $v !== '') $q[$k] = $v; }
    $base = PV_URL_SITE === '' ? '/' : PV_URL_SITE . '/';
    return $base . ($q ? '?' . http_build_query($q) : '');
}

/** 静态资源链接（附版本号，随 PV_VERSION 变更自动失效，避免旧脚本被缓存） */
function pvw_asset($path) { return PV_URL_ASSET . '/' . ltrim($path, '/') . '?v=' . PV_VERSION; }

/** 上传文件访问地址（经 ?r=file 鉴权下发） */
function pvw_file_url($token, $download = false, array $params = array()) {
    $q = array_merge(array('t' => (string)$token), $download ? array('download' => 1) : array(), $params);
    return pvw_url('file', $q);
}


/** 是否为站内 AJAX 局部刷新请求 */
function pvw_is_ajax() {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') return true;
    return isset($_GET['pv_ajax']) && (string)$_GET['pv_ajax'] === '1';
}

/**
 * 是否被 iframe 嵌入（服务端初值，用于渲染前的差异化 / 日志）。
 * 现代浏览器优先用 Sec-Fetch-Dest：顶层导航=document，iframe 导航=iframe；
 * 旧浏览器回退 Referer 主机是否与自身不同（缺失或同源视为非嵌入）。
 * 注意：前端 `window.self !== window.top` 为最终权威，会纠正此初值。
 */
function pvw_is_embedded() {
    $dest = isset($_SERVER['HTTP_SEC_FETCH_DEST']) ? strtolower((string)$_SERVER['HTTP_SEC_FETCH_DEST']) : '';
    if ($dest === 'iframe') return true;
    if ($dest === 'document') return false;
    $ref = isset($_SERVER['HTTP_REFERER']) ? (string)parse_url((string)$_SERVER['HTTP_REFERER'], PHP_URL_HOST) : '';
    if ($ref === '') return false;
    $own = isset($_SERVER['HTTP_HOST']) ? preg_replace('/:\d+$/', '', (string)$_SERVER['HTTP_HOST']) : '';
    return $own === '' || strcasecmp($ref, $own) !== 0;
}

/** 任意站点路径的绝对地址（保持路径原样，如 DICOMweb 的 /dicom-web） */
function pvw_abs_path($path) {
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '' ? (string)$_SERVER['HTTP_HOST'] : 'localhost';
    return $scheme . '://' . $host . $path;
}

/** 站点内部链接的绝对地址（用于模拟服务器对外地址等） */
function pvw_abs_url($r = '', array $params = array()) {
    return pvw_abs_path(pvw_url($r, $params));
}

/** 302 跳转 */
function pvw_redirect($url) { header('Location: ' . $url); exit; }

/** 统一 JSON 输出 */
function pvw_json($code, $msg = '', $data = null) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        if ((int)$code >= 400) http_response_code((int)$code);   // 错误码同步 HTTP 状态
    }
    echo json_encode(array('code' => (int)$code, 'msg' => (string)$msg, 'data' => $data), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 统一响应：AJAX 返回 JSON，普通请求写入 flash 后跳转。
 * @param string      $msg      提示信息
 * @param bool        $ok       成功 / 失败
 * @param mixed       $data     附带数据（AJAX 时返回）
 * @param string|null $redirect 普通请求的跳转地址（默认站点根）
 */
function pvw_reply($msg, $ok = true, $data = null, $redirect = null) {
    if (pvw_is_ajax()) {
        pvw_json($ok ? 200 : 400, $msg, $data);
    }
    $_SESSION['pv_flash'] = $msg;
    pvw_redirect($redirect !== null ? $redirect : pvw_url(''));
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
        // AJAX 请求返回 JSON；普通表单（登录 / 安装等）返回可读错误页，避免用户看到裸 JSON
        if (pvw_is_ajax()) pvw_json(403, '安全校验失败，请刷新页面重试');
        http_response_code(403);
        pvw_view('error', array('message' => '安全校验失败，请刷新页面后重试'));
        exit;
    }
}

/** 渲染视图 */
function pvw_view($name, array $data = array()) {
    $file = pvw_view_file($name);
    extract($data, EXTR_SKIP);
    require $file;
}

/** 解析视图文件路径 */
function pvw_view_file($name) {
    $file = PV_VIEWS . '/' . str_replace('..', '', (string)$name) . '.php';
    if (!is_file($file)) { http_response_code(500); echo 'view not found: ' . pvw_e($name); exit; }
    return $file;
}

/**
 * 渲染「受外壳包裹」的页面（患者查询 / 阅片 / 管理 / 模拟服务器）。
 * - 普通请求：输出完整 HTML（页头 + 主区 + 页脚）。
 * - AJAX 请求：输出 JSON 片段（html + css + js + data），由前端 spa.js 局部替换，
 *   地址栏保持不变。
 *
 * 视图文件只需输出主体内容，并按需设置：$page / $pageTitle / $active /
 * $bodyClass / $extraCss / $extraJs / $pageData。
 */
function pvw_page($name, array $data = array()) {
    $file = pvw_view_file($name);
    extract($data, EXTR_SKIP);
    // 布局元数据默认值（视图内可覆盖）
    $page = isset($page) ? $page : '';
    if (!isset($pageTitle)) $pageTitle = '';
    $active = isset($active) ? $active : '';
    $bodyClass = isset($bodyClass) ? $bodyClass : '';
    $extraCss = array(); $extraJs = array(); $pageData = array();
    ob_start();
    require $file;
    $body = ob_get_clean();
    $site = isset($site) ? $site : PvSettings::get('site_title', 'PACS 影像浏览器');

    if (pvw_is_ajax()) {
        pvw_json(200, 'success', array(
            'page'      => $page,
            'title'     => ($pageTitle !== '' ? $pageTitle . ' · ' : '') . $site,
            'active'    => $active,
            'bodyClass' => $bodyClass,
            'html'      => $body,
            'css'       => array_values((array)$extraCss),
            'js'        => array_values((array)$extraJs),
            'data'      => $pageData,
        ));
    }

    include pvw_view_file('partials/header');
    echo $body;
    include pvw_view_file('partials/footer');
}
