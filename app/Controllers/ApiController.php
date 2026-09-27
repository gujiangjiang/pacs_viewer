<?php
/** app/Controllers/ApiController.php — 前端 JSON 接口（全部数据来自 PACS 接口） */
class PvApiController {

    /** 鉴权：未登录返回 JSON 401（避免 fetch 拿到登录页 HTML） */
    private static function guard() {
        if (!PvAuth::check()) pvw_json(401, '登录会话已失效，请重新登录', array('need_login' => true));
    }

    /** 检索检查列表 */
    public static function search() {
        self::guard();
        $kw = (string)pvw_input('q');
        try {
            $list = PvStudyService::search($kw);
        } catch (Exception $e) {
            pvw_json(500, $e->getMessage());
        }
        $u = PvAuth::user();
        PvQueryLogRepository::add($u['username'], $kw, count($list));
        pvw_json(200, 'success', array(
            'list' => $list,
            'total' => count($list),
            'mode' => PvPacsClient::mode(),
            'remote' => PvPacsClient::isRemote(),
        ));
    }

    /** 调阅单次检查（患者 + 检查 + 序列） */
    public static function study() {
        self::guard();
        $uid = (string)pvw_input('uid');
        if ($uid === '') pvw_json(400, '缺少检查标识');
        try {
            $data = PvStudyService::study($uid);
        } catch (Exception $e) {
            pvw_json(500, $e->getMessage());
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
        self::guard();
        pvw_csrf_check();
        $u = PvAuth::user();
        $v = (string)pvw_input('clear_on_open') === '1' ? 1 : 0;
        PvUserRepository::setClearOnOpen($u['id'], $v);
        pvw_json(200, 'success', array('clear_on_open' => $v));
    }

    /** 记录前端操作日志（读片 / 下载 / 阅读 DICOM 等） */
    public static function log() {
        self::guard();
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
