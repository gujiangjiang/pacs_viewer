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
        PvAuth::logout();
        pvw_redirect(pvw_url('login'));
    }
}
