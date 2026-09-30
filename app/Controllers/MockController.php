<?php
/** app/Controllers/MockController.php — 内置模拟 PACS 服务器（管理界面 + 对外 API） */
class PvMockController {

    /* ==================== 管理界面 ==================== */

    /** 模拟服务器已并入【管理设置 → 模拟服务器】子 Tab，此处直接跳转 */
    public static function index() {
        PvAuth::requireAdmin();
        pvw_redirect(pvw_url('admin', array('tab' => 'mock')));
    }

    /** 保存模拟服务器设置（FHIR 等外部接口已移至「外部接口」） */
    public static function save() {
        PvAuth::requireAdmin();
        pvw_csrf_check();
        $pairs = array();
        foreach (array('mock_enabled') as $k) {
            if (isset($_POST[$k])) $pairs[$k] = (string)$_POST[$k];
        }
        if (isset($pairs['mock_enabled'])) $pairs['mock_enabled'] = $pairs['mock_enabled'] === '1' ? '1' : '0';
        PvSettings::saveMany($pairs);
        self::reply('模拟服务器设置已保存');
    }

    /** 重新生成对外接口密钥 */
    public static function regenKey() {
        PvAuth::requireAdmin();
        pvw_csrf_check();
        PvSettings::set('mock_api_key', bin2hex(random_bytes(8)));
        self::reply('密钥已重新生成', array('key' => PvMockServer::apiKey()));
    }

    /** 保存解剖部位与切片数量配置 */
    public static function anatomySave() {
        PvAuth::requireAdmin();
        pvw_csrf_check();
        $rows = (isset($_POST['anatomy']) && is_array($_POST['anatomy'])) ? $_POST['anatomy'] : array();
        $entries = array();
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $entries[] = array(
                'modality' => isset($r['modality']) ? (string)$r['modality'] : '',
                'body_key' => isset($r['body_key']) ? (string)$r['body_key'] : '',
                'label'    => isset($r['label']) ? (string)$r['label'] : '',
                'keywords' => isset($r['keywords']) ? (string)$r['keywords'] : '',
                'frames'   => isset($r['frames']) ? (int)$r['frames'] : 0,
                'enabled'  => (isset($r['enabled']) && (string)$r['enabled'] === '1') ? 1 : 0,
            );
        }
        PvMockAnatomyConfig::save($entries);
        self::reply('解剖部位与切片数量已保存');
    }

    /** 恢复默认解剖部位配置 */
    public static function anatomyReset() {
        PvAuth::requireAdmin();
        pvw_csrf_check();
        PvMockAnatomyConfig::reset();
        self::reply('已恢复默认解剖部位配置');
    }

    /** 一键应用：把模拟服务器地址与密钥填入 DICOM/PACS 接口 */
    public static function apply() {
        PvAuth::requireAdmin();
        pvw_csrf_check();
        $url = PvMockServer::dicomWebEndpoint();
        $key = PvMockServer::apiKey();
        PvSettings::saveMany(array('pacs_endpoint' => $url, 'pacs_api_key' => $key));
        self::reply('已将模拟服务器（标准 DICOMweb）填入影像接口', array(
            'endpoint' => $url,
            'key'      => $key,
        ));
    }

    /** 患者预览（已缴费、已登记） */
    public static function patients() {
        PvAuth::requireAdmin();
        @set_time_limit(30);
        $kw = (string)pvw_input('q');
        try {
            $list = PvMockServer::patients($kw);
            pvw_json(200, 'success', array(
                'source'   => PvMockServer::source(),
                'list'     => $list,
                'total'    => count($list),
            ));
        } catch (Exception $e) {
            pvw_json(500, $e->getMessage());
        }
    }

    /* ==================== 标准 DICOM 二进制下发（WADO-URI） ==================== */

    /** 通用阅片器 / 外部 PACS 客户端按标准 DICOM 协议取像（登录或密钥二选一） */
    public static function dicom() {
        if (!PvMockServer::enabled()) { self::textError(403, '内置模拟 PACS 服务器未启用'); }
        if (!PvAuth::check() && !PvMockServer::checkKey((string)pvw_input('key'))) {
            self::textError(403, '模拟服务器密钥校验失败');
        }
        self::emitWado();
    }

    /** 侧栏缩略图（PNG，小图）：登录或密钥二选一，输出 image/png */
    public static function thumb() {
        if (!PvMockServer::enabled()) { self::textError(403, '内置模拟 PACS 服务器未启用'); }
        if (!PvAuth::check() && !PvMockServer::checkKey((string)pvw_input('key'))) {
            self::textError(403, '模拟服务器密钥校验失败');
        }
        try {
            $r = PvMockServer::thumbnail(self::wadoParams());
        } catch (Exception $e) {
            self::textError(404, $e->getMessage());
        }
        if (!headers_sent()) {
            header('Content-Type: ' . $r['content_type']);
            header('Content-Length: ' . strlen($r['binary']));
            header('Cache-Control: private, max-age=86400');
        }
        echo $r['binary'];
        exit;
    }

    /** 输出 DICOM 字节流（不进入 JSON 封装，对通用前端完全透明） */
    private static function emitWado() {
        try {
            $r = PvMockServer::wado(self::wadoParams());
        } catch (Exception $e) {
            self::textError(404, $e->getMessage());
        }
        if (!headers_sent()) {
            header('Content-Type: ' . $r['content_type']);
            header('Content-Length: ' . strlen($r['binary']));
            header('Content-Disposition: inline; filename="' . str_replace('"', '', $r['filename']) . '"');
            header('Cache-Control: private, max-age=86400');
        }
        echo $r['binary'];
        exit;
    }

    /** 汇总 WADO-URI（studyUID/seriesUID/objectUID）与简洁参数（uid/series/instance） */
    private static function wadoParams() {
        return array(
            'studyUID'   => (string)pvw_input('studyUID'),
            'seriesUID'  => (string)pvw_input('seriesUID'),
            'objectUID'  => (string)pvw_input('objectUID'),
            'study_uid'  => (string)pvw_input('study_uid'),
            'series_uid' => (string)pvw_input('series_uid'),
            'object_uid' => (string)pvw_input('object_uid'),
            'uid'        => (string)pvw_input('uid'),
            'series'     => (string)pvw_input('series'),
            'instance'   => (string)pvw_input('instance'),
        );
    }

    private static function textError($code, $msg) {
        if (!headers_sent()) {
            http_response_code((int)$code);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo $msg;
        exit;
    }

    /* ==================== 工具 ==================== */

    private static function reply($msg, $data = null) {
        pvw_reply($msg, true, $data, pvw_url('mockserver'));
    }
}
