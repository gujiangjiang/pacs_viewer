<?php
/** app/Controllers/AuthController.php — 登录 / 登出 */
class PvAuthController {

    public static function showLogin() {
        if (PvAuth::check()) pvw_redirect(pvw_url(''));
        $error = isset($_SESSION['pv_login_error']) ? $_SESSION['pv_login_error'] : '';
        unset($_SESSION['pv_login_error']);
        pvw_view('auth/login', array('error' => $error, 'site' => PvSettings::get('site_title', 'PACS 影像浏览器')));
    }

    public static function login() {
        pvw_csrf_check();
        $username = (string)pvw_input('username');
        $password = (string)pvw_input('password');
        $res = PvAuth::login($username, $password);
        if ($res === true) {
            $_SESSION['pv_flash'] = '登录成功，欢迎使用';
            pvw_redirect(pvw_url(''));
        }
        $_SESSION['pv_login_error'] = $res;
        pvw_redirect(pvw_url('login'));
    }

    public static function logout() {
        // 登出属状态变更操作：校验随页头退出链接下发的 CSRF 令牌（GET 场景）
        $t = isset($_GET['_csrf']) ? (string)$_GET['_csrf'] : '';
        if ($t === '' || empty($_SESSION['pv_csrf']) || !hash_equals($_SESSION['pv_csrf'], $t)) {
            http_response_code(403);
            pvw_view('error', array('message' => '安全校验失败，请刷新页面后重试'));
            exit;
        }
        PvAuth::logout();
        pvw_redirect(pvw_url('login'));
    }
}
