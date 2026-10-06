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
            'logLimits' => array(
                'operation' => self::logLimits('operation'),
                'protocol'  => self::logLimits('protocol'),
                'system'    => self::logLimits('system'),
                'mock'      => self::logLimits('mock'),
            ),
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

    /* ---------------- 日志查询（操作 / 协议 / 系统 / 模拟服务器） ---------------- */

    /** 日志通道白名单（管理端「日志查询」左栏 + 模拟服务器日志共用） */
    public static function logChannels() {
        return array('operation', 'protocol', 'system', 'mock');
    }

    /** 规范化日志通道（非法回退 operation） */
    private static function logChannel() {
        $c = strtolower(trim((string)pvw_input('channel', 'operation')));
        return in_array($c, self::logChannels(), true) ? $c : 'operation';
    }

    /** 指定通道的保留上限（条数 / 天数，0 表示不限制） */
    private static function logLimits($channel) {
        if ($channel === 'operation') {
            return array('count' => PvQueryLogRepository::maxCount(), 'days' => PvQueryLogRepository::maxDays());
        }
        if ($channel === 'system') {
            return PvSystemLogService::limits();
        }
        return PvActivityLogRepository::limits($channel);
    }

    public static function logClear() {
        PvAuth::requireAdmin();
        pvw_csrf_check();
        $channel = self::logChannel();
        if ($channel === 'operation') {
            PvQueryLogRepository::clear();
        } elseif ($channel === 'system') {
            PvSystemLogService::clear();
        } else {
            PvActivityLogRepository::clear($channel);
        }
        self::reply('日志已清空', true, array('channel' => $channel), 'logs');
    }

    /** 保存指定通道日志保留上限（POST 保存 / GET 返回当前值供设置框回填） */
    public static function logSettings() {
        PvAuth::requireAdmin();
        $channel = self::logChannel();
        $isPost = isset($_SERVER['REQUEST_METHOD']) && strtoupper((string)$_SERVER['REQUEST_METHOD']) === 'POST';
        if (!$isPost) {
            pvw_json(200, 'success', array('channel' => $channel) + self::logLimits($channel));
        }
        pvw_csrf_check();
        $count = pvw_input('log_max_count');
        $days = pvw_input('log_max_days');
        if ($channel === 'operation') {
            PvSettings::set('log_max_count', PvNumber::positiveInt($count));
            PvSettings::set('log_max_days', PvNumber::positiveInt($days));
            PvQueryLogRepository::enforceLimits();
        } elseif ($channel === 'system') {
            PvSystemLogService::saveLimits($count, $days);
        } else {
            PvActivityLogRepository::saveLimits($channel, $count, $days);
        }
        self::reply('日志保留设置已保存', true, array('channel' => $channel) + self::logLimits($channel), 'logs');
    }

    /** 指定通道日志分页读取（管理端滚动加载 / 实时增量） */
    public static function logs() {
        PvAuth::requireAdmin();
        $channel = self::logChannel();
        $offset = max(0, (int)pvw_input('offset', 0));
        $limit = max(1, min(200, (int)pvw_input('limit', 30)));
        $sinceId = max(0, (int)pvw_input('since_id', 0));

        if ($channel === 'system') {
            $lines = PvSystemLogService::lines($limit);
            $list = array();
            foreach ($lines as $i => $ln) {
                $list[] = array('id' => $i + 1, 'created_at' => (string)$ln['time'], 'text' => (string)$ln['text']);
            }
            pvw_json(200, 'success', array(
                'channel' => $channel,
                'list' => $list,
                'total' => max(count($list), PvSystemLogService::approxCount()),
                'has_more' => count($list) >= $limit,
            ));
        }

        if ($channel === 'operation') {
            $rows = $sinceId > 0 ? PvQueryLogRepository::since($sinceId, $limit) : PvQueryLogRepository::page($offset, $limit);
            $list = array();
            foreach ($rows as $l) {
                $act = isset($l['action']) ? (string)$l['action'] : 'search';
                $list[] = array(
                    'id' => (int)$l['id'],
                    'created_at' => (string)$l['created_at'],
                    'username' => (string)$l['username'],
                    'action' => $act,
                    'action_name' => PvQueryLogRepository::actionName($act),
                    'detail' => isset($l['detail']) ? (string)$l['detail'] : '',
                    'keyword' => (string)$l['keyword'],
                    'result_count' => ($act === 'search') ? (int)$l['result_count'] : null,
                    'ip' => (string)$l['ip'],
                );
            }
            $total = PvQueryLogRepository::count();
        } else {
            $rows = $sinceId > 0 ? PvActivityLogRepository::since($channel, $sinceId, $limit) : PvActivityLogRepository::page($channel, $offset, $limit);
            $list = array();
            foreach ($rows as $l) {
                $list[] = array(
                    'id' => (int)$l['id'],
                    'created_at' => (string)$l['created_at'],
                    'level' => (string)$l['level'],
                    'action' => (string)$l['action'],
                    'detail' => (string)$l['detail'],
                    'meta' => (string)$l['meta'],
                    'ip' => (string)$l['ip'],
                );
            }
            $total = PvActivityLogRepository::count($channel);
        }
        pvw_json(200, 'success', array(
            'channel' => $channel,
            'list' => $list,
            'total' => $total,
            'has_more' => ($sinceId <= 0) && (($offset + count($list)) < $total),
        ));
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

    /**
     * 保存缓存设置：APCu / 磁盘开关、各自容量上限、共享日期上限。
     * 容量与日期均为可选项，留空即不限制；保存后立即按新规则清理。
     */
    public static function storageSettings() {
        PvAuth::requireAdmin();
        // GET：返回当前已保存的缓存设置，供设置模态框每次打开时回填最新值
        $isPost = isset($_SERVER['REQUEST_METHOD']) && strtoupper((string)$_SERVER['REQUEST_METHOD']) === 'POST';
        if (!$isPost) pvw_json(200, 'success', self::cacheSettings());
        pvw_csrf_check();
        PvSettings::set('cache_apcu_enabled', ((string)pvw_input('cache_apcu_enabled') === '1') ? '1' : '0');
        PvSettings::set('cache_disk_enabled', ((string)pvw_input('cache_disk_enabled') === '1') ? '1' : '0');
        PvSettings::set('cache_max_bytes', PvNumber::mbToBytes(pvw_input('cache_max_mb')));
        PvSettings::set('cache_disk_max_bytes', PvNumber::mbToBytes(pvw_input('cache_disk_max_mb')));
        PvSettings::set('cache_max_days', PvNumber::positiveInt(pvw_input('cache_max_days')));
        PvMockCache::enforceLimits();   // 保存后立即执行开关与上限
        self::reply('缓存设置已保存', true, PvMockCache::stats(), 'storage');
    }

    /** 当前缓存设置（供 GET 回填；容量以 MB 展示，留空表示不限制） */
    private static function cacheSettings() {
        $mb = function ($bytes) { $bytes = (int)$bytes; return $bytes > 0 ? round($bytes / 1048576, 2) : ''; };
        $days = (int)PvSettings::get('cache_max_days', '');
        return array(
            'apcuEnabled' => (string)PvSettings::get('cache_apcu_enabled', '1') === '1',
            'diskEnabled' => (string)PvSettings::get('cache_disk_enabled', '1') === '1',
            'maxMb'       => $mb(PvSettings::get('cache_max_bytes', '')),
            'diskMaxMb'   => $mb(PvSettings::get('cache_disk_max_bytes', '')),
            'maxDays'     => $days > 0 ? (string)$days : '',
        );
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
