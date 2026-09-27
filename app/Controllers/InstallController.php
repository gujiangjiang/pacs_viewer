<?php
/** app/Controllers/InstallController.php — 首次运行安装向导（创建首个管理员） */
class PvInstallController {

    /** 显示安装向导 */
    public static function show() {
        if (PvSettings::isInstalled()) pvw_redirect(pvw_url(PvAuth::check() ? '' : 'login'));
        $error = isset($_SESSION['pv_install_error']) ? $_SESSION['pv_install_error'] : '';
        unset($_SESSION['pv_install_error']);
        pvw_view('auth/install', array(
            'error'   => $error,
            'site'    => PvSettings::get('site_title', 'PACS 影像浏览器'),
            'host'    => PvSettings::get('hospital_name', ''),
            'default' => array(
                'site_title'    => PvSettings::get('site_title', 'PACS 影像浏览器'),
                'hospital_name' => PvSettings::get('hospital_name', ''),
            ),
        ));
    }

    /** 提交安装：校验 → 建首个管理员 → 生成模拟服务器密钥 → 标记已安装 → 自动登录 */
    public static function submit() {
        if (PvSettings::isInstalled()) pvw_redirect(pvw_url('login'));
        pvw_csrf_check();

        $site = trim((string)pvw_input('site_title'));
        $hosp = trim((string)pvw_input('hospital_name'));
        $username = trim((string)pvw_input('username'));
        $display  = trim((string)pvw_input('display_name'));
        $password = (string)pvw_input('password');
        $confirm  = (string)pvw_input('password_confirm');

        $_SESSION['pv_install_error'] = '';
        if ($site === '') $site = 'PACS 影像浏览器';
        if ($username === '' || $password === '') {
            return self::fail('管理员用户名与密码不能为空');
        }
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{1,31}$/', $username)) {
            return self::fail('用户名须以字母开头，仅含字母 / 数字 / 下划线（2-32 位）');
        }
        if (mb_strlen($password) < 6) return self::fail('密码长度至少 6 位');
        if ($password !== $confirm) return self::fail('两次输入的密码不一致');

        try {
            PvUserRepository::createOwner($username, $password, $display !== '' ? $display : $username);
        } catch (Exception $e) {
            return self::fail('创建管理员失败：' . $e->getMessage());
        }

        // 写入站点信息并标记安装完成；同时确保模拟服务器密钥存在
        PvSettings::saveMany(array(
            'site_title'    => $site,
            'hospital_name' => $hosp,
            'installed'     => '1',
        ));
        if (trim((string)PvSettings::get('mock_api_key', '')) === '') {
            PvSettings::set('mock_api_key', bin2hex(random_bytes(8)));
        }

        // 安装完成后直接以该管理员身份登录
        PvAuth::login($username, $password);
        if (!empty($_SESSION['pv_flash'])) unset($_SESSION['pv_flash']);
        $_SESSION['pv_flash'] = '安装完成，欢迎使用！';
        pvw_redirect(pvw_url(''));
    }

    private static function fail($msg) {
        $_SESSION['pv_install_error'] = $msg;
        pvw_redirect(pvw_url('install'));
    }
}
