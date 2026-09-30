<?php
/** app/Controllers/ApiController.php — 前端 JSON 接口（全部数据来自 PACS 接口） */
class PvApiController {

    /** 数据来源指纹：来源配置变化时缓存自然失效 */
    private static function sourceFingerprint() {
        return md5(
            PvPacsClient::mode() . '|' . PvSettings::get('pacs_endpoint', '') . '|'
            . (PvStudyService::isFhirEnabled() ? '1' : '0') . '|' . (PvMockServer::enabled() ? '1' : '0')
        );
    }

    /** 检索检查列表（响应短时缓存，减少高并发下的重复检索） */
    public static function search() {
        PvAuth::requireLoginJson();
        $kw = (string)pvw_input('q');
        $ck = 'search:' . self::sourceFingerprint() . ':' . $kw;
        $list = PvCache::get($ck);
        if ($list === null) {
            try {
                $list = PvStudyService::search($kw);
            } catch (Exception $e) {
                pvw_json(500, $e->getMessage());
            }
            PvCache::set($ck, $list, 20);
        }
        $u = PvAuth::user();
        PvQueryLogRepository::add($u['username'], $kw, count($list));
        pvw_json(200, 'success', array(
            'list' => $list,
            'total' => count($list),
            'mode' => PvPacsClient::mode(),
            'remote' => PvPacsClient::isRemote(),
            'source' => PvStudyService::sourceInfo(),
            'fhir_error' => PvStudyService::lastFhirError(),
        ));
    }

    /** 调阅单次检查（患者 + 检查 + 序列） */
    public static function study() {
        PvAuth::requireLoginJson();
        $uid = (string)pvw_input('uid');
        if ($uid === '') pvw_json(400, '缺少检查标识');
        $ck = 'study:' . self::sourceFingerprint() . ':' . $uid;
        $data = PvCache::get($ck);
        if ($data === null) {
            try {
                $data = PvStudyService::study($uid);
            } catch (Exception $e) {
                pvw_json(500, $e->getMessage());
            }
            PvCache::set($ck, $data, 30);
        }
        pvw_json(200, 'success', $data);
    }

    /** 接口连通性测试（管理端） */
    public static function ping() {
        PvAuth::requireAdmin();
        try {
            $p = PvPacsClient::ping();
            pvw_json(200, 'success', $p);
        } catch (Exception $e) {
            pvw_json(500, $e->getMessage());
        }
    }

    /** 保存用户偏好 */
    public static function pref() {
        PvAuth::requireLoginJson();
        pvw_csrf_check();
        $u = PvAuth::user();
        $v = (string)pvw_input('clear_on_open') === '1' ? 1 : 0;
        PvUserRepository::setClearOnOpen($u['id'], $v);
        pvw_json(200, 'success', array('clear_on_open' => $v));
    }

    /** 记录前端操作日志（读片 / 下载 / 阅读 DICOM 等） */
    public static function log() {
        PvAuth::requireLoginJson();
        pvw_csrf_check();
        $action = substr((string)pvw_input('action'), 0, 32);
        $detail = mb_substr((string)pvw_input('detail'), 0, 200, 'UTF-8');
        $allowed = array('read', 'download', 'dicom', 'search');
        if (!in_array($action, $allowed, true)) pvw_json(400, '非法的操作类型');
        $u = PvAuth::user();
        PvQueryLogRepository::event($u['username'], $action, $detail);
        pvw_json(200, 'success', null);
    }
}
