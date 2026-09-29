<?php
/** app/Controllers/AdminController.php — 管理员设置 */
class PvAdminController {

    /** 允许保存的设置键（白名单） */
    private static $settingKeys = array(
        'site_title', 'hospital_name',
        'fhir_enabled',
        'pacs_endpoint', 'pacs_api_key',
        'pacs_ae_title', 'pacs_remote_ae', 'pacs_server_host', 'pacs_server_port', 'pacs_timeout',
        'fhir_endpoint', 'fhir_api_key', 'fhir_timeout',
        'viewer_default_ww', 'viewer_default_wl', 'viewer_study_limit',
    );

    /** 统一响应：AJAX 返回 JSON，普通请求写 flash 并回到管理页 */
    private static function reply($msg, $ok = true, $data = null, $tab = 'basic') {
        if (pvw_is_ajax()) {
            pvw_json($ok ? 200 : 400, $msg, $data);
        }
        $_SESSION['pv_flash'] = $msg;
        pvw_redirect(pvw_url('admin', array('tab' => $tab)));
    }

    public static function index() {
        PvAuth::requireAdmin();
        $flash = isset($_SESSION['pv_flash']) ? $_SESSION['pv_flash'] : '';
        unset($_SESSION['pv_flash']);
        $tab = (string)pvw_input('tab', 'basic');
        if (!in_array($tab, array('basic', 'pacs', 'users', 'logs', 'mock', 'storage'), true)) $tab = 'basic';
        pvw_page('admin', array(
            'user'     => PvAuth::user(),
            'flash'    => $flash,
            'tab'      => $tab,
            'site'     => PvSettings::get('site_title', 'PACS 影像浏览器'),
            'settings' => PvSettings::all(),
            'users'    => PvUserRepository::all(),
            'logs'     => PvQueryLogRepository::recent(30),
            'logCount' => PvQueryLogRepository::count(),
            'mockKey'  => PvMockServer::apiKey(),
            'mockUrl'  => PvMockServer::externalEndpoint(),
            'pacsEndpoint' => PvSettings::get('pacs_endpoint', ''),
        ));
    }

    public static function save() {
        PvAuth::requireAdmin();
        pvw_csrf_check();
        $pairs = array();
        foreach (self::$settingKeys as $k) {
            if (isset($_POST[$k])) $pairs[$k] = (string)$_POST[$k];
        }
        if (isset($pairs['viewer_study_limit'])) {
            $pairs['viewer_study_limit'] = (string)max(3, min(10, (int)$pairs['viewer_study_limit']));
        }
        if (isset($pairs['fhir_enabled'])) {
            $pairs['fhir_enabled'] = $pairs['fhir_enabled'] === '1' ? '1' : '0';
        }
        if (isset($pairs['fhir_timeout'])) {
            $pairs['fhir_timeout'] = (string)max(1, min(60, (int)$pairs['fhir_timeout']));
        }
        PvSettings::saveMany($pairs);
        $tab = (string)pvw_input('tab', 'basic');
        self::reply('设置已保存', true, array(
            'site_title'    => PvSettings::get('site_title', ''),
            'hospital_name' => PvSettings::get('hospital_name', ''),
            'icon_version'  => PvIconRenderer::version(),
        ), $tab === 'pacs' ? 'pacs' : 'basic');
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
            self::reply('账号已创建', true, PvUserRepository::all(), 'users');
        } catch (Exception $e) {
            self::reply('创建失败：' . $e->getMessage(), false, null, 'users');
        }
    }

    public static function userUpdate() {
        PvAuth::requireAdmin();
        pvw_csrf_check();
        try {
            PvUserRepository::updateProfile((int)pvw_input('id'), trim((string)pvw_input('display_name')), (string)pvw_input('role'));
            self::reply('账号资料已更新', true, PvUserRepository::all(), 'users');
        } catch (Exception $e) {
            self::reply('更新失败：' . $e->getMessage(), false, null, 'users');
        }
    }

    public static function userStatus() {
        PvAuth::requireAdmin();
        pvw_csrf_check();
        $id = (int)pvw_input('id');
        $status = (int)pvw_input('status');
        if ($id === (int)PvAuth::user()['id'] && $status === 0) {
            self::reply('不能停用当前登录的账号', false, null, 'users');
        }
        try {
            PvUserRepository::setStatus($id, $status);
            self::reply('账号状态已更新', true, PvUserRepository::all(), 'users');
        } catch (Exception $e) {
            self::reply('操作失败：' . $e->getMessage(), false, null, 'users');
        }
    }

    public static function userPassword() {
        PvAuth::requireAdmin();
        pvw_csrf_check();
        try {
            PvUserRepository::setPassword((int)pvw_input('id'), (string)pvw_input('password'));
            self::reply('密码已重置', true, null, 'users');
        } catch (Exception $e) {
            self::reply('重置失败：' . $e->getMessage(), false, null, 'users');
        }
    }

    public static function userDelete() {
        PvAuth::requireAdmin();
        pvw_csrf_check();
        $id = (int)pvw_input('id');
        if ($id === (int)PvAuth::user()['id']) {
            self::reply('不能删除当前登录的账号', false, null, 'users');
        }
        try {
            PvUserRepository::delete($id);
            self::reply('账号已删除', true, PvUserRepository::all(), 'users');
        } catch (Exception $e) {
            self::reply('删除失败：' . $e->getMessage(), false, null, 'users');
        }
    }

    public static function logClear() {
        PvAuth::requireAdmin();
        pvw_csrf_check();
        PvQueryLogRepository::clear();
        self::reply('检索日志已清空', true, null, 'logs');
    }

    /* ---------------- 数据集成：FHIR 连通性 ---------------- */

    /** FHIR R4 连通性测试（数据来源配置的一部分） */
    public static function fhirTest() {
        PvAuth::requireAdmin();
        @set_time_limit(15);
        try {
            pvw_json(200, 'success', PvFhirClient::ping());
        } catch (Exception $e) {
            pvw_json(500, $e->getMessage());
        }
    }

    /* ---------------- 存储情况 ---------------- */

    /** 查询运行时存储占用（数据库 / 上传 / 缓存 / 会话） */
    public static function storage() {
        PvAuth::requireAdmin();
        pvw_json(200, 'success', PvStorageService::stats());
    }

    /** 一键清空上传文件与记录 */
    public static function storageClearUploads() {
        PvAuth::requireAdmin();
        pvw_csrf_check();
        $r = PvStorageService::clearUploads();
        pvw_json(200, '已清空上传文件（' . $r['files'] . ' 个文件 / ' . $r['records'] . ' 条记录）', $r);
    }

    /** 一键清空缓存区 */
    public static function storageClearCache() {
        PvAuth::requireAdmin();
        pvw_csrf_check();
        $r = PvStorageService::clearCache();
        pvw_json(200, '已清空缓存区（' . $r['files'] . ' 个遗留文件）', $r);
    }

    /** 上传自定义站点 / PWA 图标（覆盖代码绘制的默认图标） */
    public static function iconUpload() {
        PvAuth::requireAdmin();
        pvw_csrf_check();
        try {
            if (empty($_FILES['icon']) || !isset($_FILES['icon']['error'])) {
                throw new RuntimeException('未接收到上传文件');
            }
            $f = $_FILES['icon'];
            if ((int)$f['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('上传失败（错误码 ' . (int)$f['error'] . '）');
            }
            PvIconRenderer::saveCustom($f['tmp_name']);
            self::reply('站点图标已更新', true, array('version' => PvIconRenderer::version()), 'basic');
        } catch (Exception $e) {
            self::reply('图标上传失败：' . $e->getMessage(), false, null, 'basic');
        }
    }

    /** 恢复默认（代码绘制）图标 */
    public static function iconReset() {
        PvAuth::requireAdmin();
        pvw_csrf_check();
        PvIconRenderer::clearCustom();
        self::reply('已恢复默认图标', true, array('version' => PvIconRenderer::version()), 'basic');
    }
}
