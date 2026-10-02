<?php
/** app/Controllers/AdminController.php — 管理员设置 */
class PvAdminController {

    /** 允许保存的设置键（白名单） */
    private static $settingKeys = array(
        'site_title', 'hospital_name',
        'pacs_endpoint', 'pacs_api_key',
        'pacs_ae_title', 'pacs_remote_ae', 'pacs_server_host', 'pacs_server_port', 'pacs_timeout',
        'viewer_default_ww', 'viewer_default_wl', 'viewer_study_limit',
    );

    /** 统一响应：AJAX 返回 JSON，普通请求写 flash 并回到管理页 */
    private static function reply($msg, $ok = true, $data = null, $tab = 'basic') {
        pvw_reply($msg, $ok, $data, pvw_url('admin', array('tab' => $tab)));
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
            'mockUrl'  => PvMockServer::dicomWebEndpoint(),
            'mockViewerUrl' => PvMockServer::viewerUrlTemplate(),
            'mockAeTitle' => PvSettings::get('mock_ae_title', 'PACSVIEWMOCK'),
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
        $tab = (string)pvw_input('tab', 'basic');

        /* 外部接口：保存前必须测试通过 */
        if ($tab === 'pacs') {
            $ep = isset($pairs['pacs_endpoint']) ? trim($pairs['pacs_endpoint']) : trim((string)PvSettings::get('pacs_endpoint', ''));
            if ($ep === '') self::reply('请填写 DICOMweb 接口地址并测试通过后再保存', false, null, 'pacs');
            $key = isset($pairs['pacs_api_key']) ? $pairs['pacs_api_key'] : PvSettings::get('pacs_api_key', '');
            $to = isset($pairs['pacs_timeout']) ? $pairs['pacs_timeout'] : PvSettings::get('pacs_timeout', '5');
            try {
                PvPacsClient::pingWith($ep, $key, $to);
            } catch (Exception $e) {
                self::reply('DICOMweb 接口测试失败，未保存：' . $e->getMessage(), false, null, 'pacs');
            }
        }

        PvSettings::saveMany($pairs);
        self::reply('设置已保存', true, array(
            'site_title'    => PvSettings::get('site_title', ''),
            'hospital_name' => pvw_hospital(),   // 生效后的医院名（覆盖 > 接口 > 项目名 > 默认医院）
            'icon_version'  => PvIconRenderer::version(),
        ), $tab === 'pacs' ? 'pacs' : 'basic');
    }

    /** 使用当前输入（未保存）的 DICOM / PACS 配置测试连通性 */
    public static function pacsTest() {
        PvAuth::requireAdmin();
        pvw_csrf_check();
        $ep = trim((string)pvw_input('pacs_endpoint'));
        if ($ep === '') pvw_json(400, '请填写 PACS 接口地址');
        try {
            $p = PvPacsClient::pingWith($ep, (string)pvw_input('pacs_api_key'), (int)pvw_input('pacs_timeout', 5));
            pvw_json(200, 'success', $p);
        } catch (Exception $e) {
            pvw_json(400, $e->getMessage());
        }
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
        self::reply('操作日志已清空', true, null, 'logs');
    }

    /* ---------------- 数据集成：FHIR 连通性 ---------------- */

    /** FHIR R4 连通性测试（POST 时使用当前输入，GET 时使用已保存配置） */
    public static function fhirTest() {
        PvAuth::requireAdmin();
        @set_time_limit(15);
        $isPost = isset($_SERVER['REQUEST_METHOD']) && strtoupper((string)$_SERVER['REQUEST_METHOD']) === 'POST';
        try {
            if ($isPost) {
                pvw_csrf_check();
                $ep = trim((string)pvw_input('fhir_endpoint'));
                if ($ep === '') pvw_json(400, '请填写 FHIR 接口地址');
                $p = PvFhirClient::pingWith($ep, (string)pvw_input('fhir_api_key'), (int)pvw_input('fhir_timeout', 5));
            } else {
                $p = PvFhirClient::ping();
            }
            pvw_json(200, 'success', $p);
        } catch (Exception $e) {
            pvw_json(400, $e->getMessage());
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
