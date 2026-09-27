<?php
/** app/Controllers/AdminController.php — 管理员设置 */
class PvAdminController {

    /** 允许保存的设置键（白名单） */
    private static $settingKeys = array(
        'site_title', 'hospital_name',
        'pacs_query_mode', 'pacs_endpoint', 'pacs_api_key',
        'pacs_ae_title', 'pacs_remote_ae', 'pacs_server_host', 'pacs_server_port', 'pacs_timeout',
        'viewer_default_ww', 'viewer_default_wl',
    );

    public static function index() {
        PvAuth::requireAdmin();
        $flash = isset($_SESSION['pv_flash']) ? $_SESSION['pv_flash'] : '';
        unset($_SESSION['pv_flash']);
        pvw_view('admin', array(
            'user'     => PvAuth::user(),
            'flash'    => $flash,
            'site'     => PvSettings::get('site_title', '模拟 PACS 影像浏览器'),
            'settings' => PvSettings::all(),
            'users'    => PvUserRepository::all(),
            'logs'     => PvQueryLogRepository::recent(30),
            'logCount' => PvQueryLogRepository::count(),
        ));
    }

    public static function save() {
        PvAuth::requireAdmin();
        pvw_csrf_check();
        $pairs = array();
        foreach (self::$settingKeys as $k) {
            if (isset($_POST[$k])) $pairs[$k] = (string)$_POST[$k];
        }
        if (isset($pairs['pacs_query_mode']) && $pairs['pacs_query_mode'] !== 'Remote') $pairs['pacs_query_mode'] = 'Demo';
        PvSettings::saveMany($pairs);
        $_SESSION['pv_flash'] = '设置已保存';
        pvw_redirect(pvw_url('admin'));
    }

    public static function userCreate() {
        PvAuth::requireAdmin();
        pvw_csrf_check();
        try {
            PvUserRepository::create(
                trim((string)pvw_input('username')),
                (string)pvw_input('password'),
                trim((string)pvw_input('display_name')),
                (string)pvw_input('role')
            );
            $_SESSION['pv_flash'] = '账号已创建';
        } catch (Exception $e) {
            $_SESSION['pv_flash'] = '创建失败：' . $e->getMessage();
        }
        pvw_redirect(pvw_url('admin'));
    }

    public static function userUpdate() {
        PvAuth::requireAdmin();
        pvw_csrf_check();
        try {
            PvUserRepository::updateProfile((int)pvw_input('id'), trim((string)pvw_input('display_name')), (string)pvw_input('role'));
            $_SESSION['pv_flash'] = '账号资料已更新';
        } catch (Exception $e) {
            $_SESSION['pv_flash'] = '更新失败：' . $e->getMessage();
        }
        pvw_redirect(pvw_url('admin'));
    }

    public static function userStatus() {
        PvAuth::requireAdmin();
        pvw_csrf_check();
        $id = (int)pvw_input('id');
        $status = (int)pvw_input('status');
        if ($id === (int)PvAuth::user()['id'] && $status === 0) {
            $_SESSION['pv_flash'] = '不能停用当前登录的账号';
        } else {
            try {
                PvUserRepository::setStatus($id, $status);
                $_SESSION['pv_flash'] = '账号状态已更新';
            } catch (Exception $e) {
                $_SESSION['pv_flash'] = '操作失败：' . $e->getMessage();
            }
        }
        pvw_redirect(pvw_url('admin'));
    }

    public static function userDelete() {
        PvAuth::requireAdmin();
        pvw_csrf_check();
        $id = (int)pvw_input('id');
        if ($id === (int)PvAuth::user()['id']) {
            $_SESSION['pv_flash'] = '不能删除当前登录的账号';
            pvw_redirect(pvw_url('admin'));
        }
        try {
            PvUserRepository::delete($id);
            $_SESSION['pv_flash'] = '账号已删除';
        } catch (Exception $e) {
            $_SESSION['pv_flash'] = '删除失败：' . $e->getMessage();
        }
        pvw_redirect(pvw_url('admin'));
    }

    public static function userPassword() {
        PvAuth::requireAdmin();
        pvw_csrf_check();
        try {
            PvUserRepository::setPassword((int)pvw_input('id'), (string)pvw_input('password'));
            $_SESSION['pv_flash'] = '密码已重置';
        } catch (Exception $e) {
            $_SESSION['pv_flash'] = '重置失败：' . $e->getMessage();
        }
        pvw_redirect(pvw_url('admin'));
    }

    public static function logClear() {
        PvAuth::requireAdmin();
        pvw_csrf_check();
        PvQueryLogRepository::clear();
        $_SESSION['pv_flash'] = '检索日志已清空';
        pvw_redirect(pvw_url('admin'));
    }
}
