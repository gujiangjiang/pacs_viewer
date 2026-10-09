<?php
/**
 * app/Auth.php — 独立登录认证（本工具自带 users 表 + Session）
 */
class PvAuth {

    /** 请求级用户缓存：避免一次请求内多路径（requireLogin/isAdmin/控制器）重复查询数据库 */
    private static $userCache = null;
    private static $userResolved = false;

    /** 失效用户缓存（登录 / 登出后调用） */
    private static function forgetUser() { self::$userCache = null; self::$userResolved = false; }

    public static function login($username, $password) {
        $u = PvDatabase::one("SELECT * FROM users WHERE username=? LIMIT 1", array($username));
        if (!$u) return '用户名或密码错误';
        if ((int)$u['status'] !== 1) return '该账号已被停用';
        if (!password_verify((string)$password, (string)$u['password_hash'])) return '用户名或密码错误';
        // 登录成功：重置会话
        @session_regenerate_id(true);
        $_SESSION['pv_uid'] = (int)$u['id'];
        $_SESSION['pv_user'] = array(
            'id' => (int)$u['id'],
            'username' => $u['username'],
            'display_name' => $u['display_name'],
            'role' => $u['role'],
        );
        $_SESSION['pv_login_at'] = time();
        self::forgetUser();
        return true;
    }

    public static function logout() {
        $_SESSION = array();
        if (session_status() === PHP_SESSION_ACTIVE) @session_destroy();
        self::forgetUser();
    }

    public static function user() {
        if (self::$userResolved) return self::$userCache;
        self::$userResolved = true;
        if (empty($_SESSION['pv_uid'])) { self::$userCache = null; return null; }
        // 实时校验账号有效性（停用即失效）
        $u = PvDatabase::one("SELECT id,username,display_name,role,status,clear_on_open,search_view FROM users WHERE id=?", array((int)$_SESSION['pv_uid']));
        if (!$u || (int)$u['status'] !== 1) { self::logout(); return null; }
        self::$userCache = $u;
        return $u;
    }

    public static function check() { return self::user() !== null; }

    public static function isAdmin() {
        $u = self::user();
        return $u && $u['role'] === 'admin';
    }

    public static function requireLogin() {
        if (!self::check()) pvw_redirect(pvw_url('login'));
    }
    /** JSON 接口专用：未登录返回 401 JSON（避免 fetch 拿到登录页 HTML） */
    public static function requireLoginJson() {
        if (!self::check()) pvw_json(401, '登录会话已失效，请重新登录', array('need_login' => true));
    }
    public static function requireAdmin() {
        self::requireLogin();
        if (self::isAdmin()) return;
        if (pvw_is_ajax()) pvw_json(403, '需要管理员权限');   // AJAX / SPA 片段：JSON
        http_response_code(403);                              // 页面直访：可读错误页
        pvw_view('error', array('message' => '需要管理员权限'));
        exit;
    }
}
