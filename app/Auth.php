<?php
/**
 * app/Auth.php — 独立登录认证（本工具自带 users 表 + Session）
 */
class PvAuth {

    public static function login($username, $password) {
        $u = PvDatabase::one("SELECT * FROM users WHERE username=? LIMIT 1", array($username));
        if (!$u) return '用户名或密码错误';
        if ((int)$u['status'] !== 1) return '该账号已被停用';
        if (!password_verify((string)$password, (string)$u['password_hash'])) return '用户名或密码错误';
        // 登录成功：重置会话，并清除访客令牌（避免与登录态混淆）
        @session_regenerate_id(true);
        PvGuest::clear();
        $_SESSION['pv_uid'] = (int)$u['id'];
        $_SESSION['pv_user'] = array(
            'id' => (int)$u['id'],
            'username' => $u['username'],
            'display_name' => $u['display_name'],
            'role' => $u['role'],
        );
        $_SESSION['pv_login_at'] = time();
        return true;
    }

    public static function logout() {
        // 清除访客令牌：否则退出后残留的 PV_GUEST 会把后续请求（如登录页）误判为访客阅片
        PvGuest::clear();
        $_SESSION = array();
        if (session_status() === PHP_SESSION_ACTIVE) @session_destroy();
    }

    public static function user() {
        if (empty($_SESSION['pv_uid'])) return null;
        // 实时校验账号有效性（停用即失效）
        $u = PvDatabase::one("SELECT id,username,display_name,role,status,clear_on_open,search_view FROM users WHERE id=?", array((int)$_SESSION['pv_uid']));
        if (!$u || (int)$u['status'] !== 1) { self::logout(); return null; }
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
        if (!self::isAdmin()) pvw_json(403, '需要管理员权限');
    }
}
