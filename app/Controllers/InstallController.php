<?php
/**
 * app/Controllers/InstallController.php — 多步安装向导（环境→数据库→初始化→站点→管理员→完成）
 *
 * 步骤与事件：
 *   install          显示向导（阶段断点续填）
 *   install/status   状态（环境检测 / 阶段 / 默认值 / 时区表）
 *   install/database 数据库文件路径（探测通过 → 写 config.db + time.storage=utc）
 *   install/migrate  数据库初始化（建表 + 播种，幂等）
 *   install/site     站点信息（站点名/医院名/全站时区 → config.db timezone）
 *   install/admin    创建安装管理员
 *   install/finish   置 installed=1 + 自动登录
 */
class PvInstallController {

    /** 当前向导阶段（已安装返回 done） */
    private static function stage() {
        if (PvSettings::isInstalled()) return 'done';
        if (!PvConfigDb::exists()) return 'env';
        $s = (string)PvConfigDb::get('setup.stage', '');
        return in_array($s, array('migrate', 'site', 'admin', 'done'), true) ? $s : 'migrate';
    }

    /** 未安装守卫（已安装一律拒绝向导写操作） */
    private static function openGuard() {
        if (PvSettings::isInstalled()) pvw_json(403, '系统已安装，向导已关闭');
    }

    /** 阶段守卫：防跳步 */
    private static function stageGuard(array $allow) {
        $s = self::stage();
        if (!in_array($s, $allow, true)) {
            pvw_json(409, '初始化步骤顺序不正确（当前：' . $s . '）');
        }
    }

    /** 显示安装向导（多步；阶段由前端经 install/status 驱动） */
    public static function show() {
        if (PvSettings::isInstalled()) pvw_redirect(pvw_url(PvAuth::check() ? '' : 'login'));
        $error = isset($_SESSION['pv_install_error']) ? $_SESSION['pv_install_error'] : '';
        unset($_SESSION['pv_install_error']);
        pvw_view('auth/install', array(
            'error'   => $error,
            'site'    => PvSettings::get('site_title', 'PACS 影像浏览器'),
            'csrf'    => pvw_csrf(),
            'default' => array(
                'site_title'    => PvSettings::get('site_title', 'PACS 影像浏览器'),
                'hospital_name' => PvSettings::get('hospital_name', ''),
            ),
        ));
    }

    /** 向导状态：环境检测 + 阶段 + 默认值 + 时区表 */
    public static function status() {
        self::openGuard();
        $env = array(
            'php'  => array('ok' => version_compare(PHP_VERSION, '7.0.0', '>='), 'version' => PHP_VERSION, 'required' => 'PHP 7.0+'),
            'pdo'  => array('ok' => extension_loaded('pdo_sqlite'), 'drivers' => extension_loaded('pdo_sqlite') ? array('sqlite') : array()),
            'data' => array('ok' => is_dir(PV_DATA) ? is_writable(PV_DATA) : @mkdir(PV_DATA, 0775, true), 'path' => PV_DATA),
        );
        $db = array('configured' => PvConfigDb::exists() && (string)PvConfigDb::get('db.type', '') !== '', 'ok' => null, 'message' => '');
        if ($db['configured']) {
            try {
                $pdo = new PDO('sqlite:' . PvConfigDb::dbPath(), null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
                $pdo->exec('CREATE TABLE IF NOT EXISTS _pv_probe (id INTEGER PRIMARY KEY)');
                $pdo->exec('DROP TABLE _pv_probe');
                $db['ok'] = true;
                $db['message'] = 'SQLite 连接正常';
            } catch (Exception $e) {
                $db['ok'] = false;
                $db['message'] = '连接失败：' . $e->getMessage();
            }
        }
        pvw_json(200, 'success', array(
            'installed'       => false,
            'stage'           => self::stage(),
            'env'             => $env,
            'db'              => $db,
            'site'            => array(
                'site_title'    => PvSettings::get('site_title', 'PACS 影像浏览器'),
                'hospital_name' => PvSettings::get('hospital_name', ''),
                'timezone'      => PvConfigDb::timezone('Asia/Shanghai'),
            ),
            'default_db_path' => PV_DATA . '/pacs_viewer.db',
            'timezones'       => DateTimeZone::listIdentifiers(),
        ));
    }

    /** ② 数据库配置：探测通过 → 写 config.db（数据库路径 + UTC 存储契约） */
    public static function database() {
        self::openGuard();
        pvw_csrf_check();
        self::stageGuard(array('env', 'migrate'));
        $path = trim((string)pvw_input('db_path', ''));
        if ($path === '') $path = PV_DATA . '/pacs_viewer.db';
        if (strpos($path, '/') !== 0) $path = PV_DATA . '/' . $path;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) pvw_json(422, '目录不可创建：' . $dir);
        if (!is_writable($dir)) pvw_json(422, '目录不可写：' . $dir);
        try {
            $pdo = new PDO('sqlite:' . $path, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('CREATE TABLE IF NOT EXISTS _pv_probe (id INTEGER PRIMARY KEY)');
            $pdo->exec('DROP TABLE _pv_probe');
        } catch (Exception $e) {
            pvw_json(422, '数据库连接失败：' . $e->getMessage());
        }
        $ok = PvConfigDb::setMany(array(
            'db.type' => 'sqlite', 'db.path' => $path,
            'time.storage' => 'utc', 'setup.stage' => 'migrate',
        ));
        if (!$ok) pvw_json(500, '配置写入失败，请检查 data/ 目录权限');
        pvw_json(200, '数据库配置已保存', array('stage' => 'migrate', 'db_path' => $path));
    }

    /** ③ 数据库初始化：建表 + 播种（幂等；使用 config.db 指定的数据文件） */
    public static function migrate() {
        self::openGuard();
        pvw_csrf_check();
        self::stageGuard(array('migrate', 'site'));
        try {
            PvDatabase::init();
            $n = (int)PvDatabase::val("SELECT COUNT(*) FROM settings");
        } catch (Exception $e) {
            pvw_json(500, '数据库初始化失败：' . $e->getMessage());
        }
        PvConfigDb::set('setup.stage', 'site');
        pvw_json(200, '数据库已就绪', array('stage' => 'site', 'settings' => $n));
    }

    /** ④ 站点信息：站点名/医院名（业务库）+ 全站时区（config.db） */
    public static function site() {
        self::openGuard();
        pvw_csrf_check();
        self::stageGuard(array('site', 'admin'));
        $siteTitle = trim((string)pvw_input('site_title', ''));
        if ($siteTitle === '') $siteTitle = 'PACS 影像浏览器';
        $hosp = trim((string)pvw_input('hospital_name', ''));
        $tz = trim((string)pvw_input('timezone', ''));
        try {
            new DateTimeZone($tz);
        } catch (Exception $e) {
            pvw_json(422, '请选择有效的时区');
        }
        PvSettings::saveMany(array('site_title' => $siteTitle, 'hospital_name' => $hosp));
        PvConfigDb::setMany(array('timezone' => $tz, 'setup.stage' => 'admin'));
        date_default_timezone_set($tz);   // 当前请求即时生效
        pvw_json(200, '站点信息已保存', array('stage' => 'admin', 'timezone' => $tz));
    }

    /** ⑤ 创建安装管理员 */
    public static function admin() {
        self::openGuard();
        pvw_csrf_check();
        self::stageGuard(array('admin', 'done'));
        $username = trim((string)pvw_input('username'));
        $display  = trim((string)pvw_input('display_name'));
        $password = (string)pvw_input('password');
        $confirm  = (string)pvw_input('password_confirm');
        if ($username === '' || $password === '') pvw_json(422, '管理员用户名与密码不能为空');
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{1,31}$/', $username)) {
            pvw_json(422, '用户名须以字母开头，仅含字母 / 数字 / 下划线（2-32 位）');
        }
        if (mb_strlen($password) < 6) pvw_json(422, '密码长度至少 6 位');
        if ($password !== $confirm) pvw_json(422, '两次输入的密码不一致');
        $hasOwner = (int)PvDatabase::val("SELECT COUNT(*) FROM users WHERE is_owner=1");
        if ($hasOwner > 0) pvw_json(409, '安装管理员已创建');
        try {
            PvUserRepository::createOwner($username, $password, $display !== '' ? $display : $username);
        } catch (Exception $e) {
            pvw_json(500, '创建管理员失败：' . $e->getMessage());
        }
        PvConfigDb::set('setup.stage', 'done');
        pvw_json(200, '管理员创建成功', array('stage' => 'done'));
    }

    /** ⑥ 完成：置 installed + 确保模拟服务器密钥 + 自动登录 */
    public static function finish() {
        self::openGuard();
        pvw_csrf_check();
        self::stageGuard(array('done'));
        PvSettings::saveMany(array('installed' => '1'));
        if (trim((string)PvSettings::get('mock_api_key', '')) === '') {
            PvSettings::set('mock_api_key', bin2hex(random_bytes(8)));
        }
        PvConfigDb::remove('setup.stage');   // 完成标记由业务库 settings.installed 持有
        $u = trim((string)pvw_input('username', ''));
        $p = (string)pvw_input('password', '');
        if ($u !== '' && $p !== '') {
            PvAuth::login($u, $p);   // 自动登录（失败不影响安装完成）
        }
        pvw_json(200, '系统初始化完成', array('installed' => true));
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
