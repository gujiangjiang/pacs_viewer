<?php
/** app/Controllers/ApiController.php — 前端 JSON 接口（全部数据来自 PACS 接口） */
class PvApiController {

    /** 数据来源指纹：来源配置变化时缓存自然失效 */
    private static function sourceFingerprint() {
        return md5(
            PvPacsClient::mode() . '|' . PvSettings::get('pacs_endpoint', '') . '|'
            . (PvMockServer::enabled() ? '1' : '0') . '|' . PvMockServer::source()
        );
    }

    /** 检索检查列表（支持分页；响应短时缓存，减少高并发下的重复检索） */
    public static function search() {
        PvAuth::requireLoginJson();
        $kw = (string)pvw_input('q');
        $limit = (int)pvw_input('limit', 0);
        $offset = (int)pvw_input('offset', 0);
        if ($limit < 0) $limit = 0;
        if ($limit > 200) $limit = 200;
        if ($offset < 0) $offset = 0;
        $filters = array(
            'gender'    => (string)pvw_input('gender'),
            'modality'  => (string)pvw_input('modality'),
            'date_from' => (string)pvw_input('date_from'),
            'date_to'   => (string)pvw_input('date_to'),
            'sort'      => (string)pvw_input('sort'),
            'dir'       => (string)pvw_input('dir'),
        );
        // 日期区间拦截：起始不得晚于截止（允许相同）
        if ($filters['date_from'] !== '' && $filters['date_to'] !== '' && $filters['date_from'] > $filters['date_to']) {
            pvw_json(400, '起始日期不能晚于截止日期');
        }

        $ck = 'search:' . self::sourceFingerprint() . ':' . $limit . ':' . $offset . ':' . $kw . ':' . md5(json_encode($filters));
        $res = PvCache::get($ck);
        if ($res === null) {
            try {
                $res = PvStudyService::search($kw, $limit, $offset, $filters);
            } catch (Exception $e) {
                pvw_json(500, $e->getMessage());
            }
            PvCache::set($ck, $res, 20);
        }
        $u = PvAuth::user();
        PvQueryLogRepository::add($u['username'], $kw, (int)$res['total']);
        pvw_json(200, 'success', array(
            'list' => $res['list'],
            'total' => (int)$res['total'],
            'has_more' => (bool)$res['has_more'],
            'offset' => $offset,
            'limit' => $limit,
            'mode' => PvPacsClient::mode(),
            'remote' => PvPacsClient::isRemote(),
            'source' => PvStudyService::sourceInfo(),
            'fhir_error' => PvMockServer::fhirError(),
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

    /**
     * 调阅影像报告（FHIR DiagnosticReport）。
     * 仅当本项目配置了 FHIR（模拟服务器患者来源=FHIR，或直连 FHIR）时可用；
     * 无报告时返回 { available:false }，前端显示占位。
     */
    public static function report() {
        PvAuth::requireLoginJson();
        $uid = (string)pvw_input('uid');
        $patient = (string)pvw_input('patient');
        if ($uid === '' && $patient === '') pvw_json(400, '缺少检查标识');
        $ck = 'report:' . self::sourceFingerprint() . ':' . $uid . ':' . $patient . ':' . (string)pvw_input('modality') . ':' . (string)pvw_input('study_date');
        $data = PvCache::get($ck);
        if ($data === null) {
            $data = array('available' => false);
            if (PvFhirClient::isConfigured()) {
                try {
                    $r = PvFhirClient::diagnosticReport(
                        $uid,
                        $patient,
                        (string)pvw_input('modality'),
                        (string)pvw_input('study_date'),
                        (string)pvw_input('title')
                    );
                    if (is_array($r)) $data = $r;
                } catch (Exception $e) {
                    /* 报告获取失败按「暂无报告」处理 */
                }
            }
            PvCache::set($ck, $data, 30);
        }
        pvw_json(200, 'success', $data);
    }

    /** 检索可选筛选项：检查类型（模态） */
    public static function facets() {
        PvAuth::requireLoginJson();
        $mods = array();
        try { $mods = PvMockServer::modalities(); } catch (Exception $e) { $mods = array(); }
        sort($mods);
        pvw_json(200, 'success', array('modalities' => array_values($mods)));
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
        $out = array();
        $clear = pvw_input('clear_on_open', null);
        if ($clear !== null) {
            $v = (string)$clear === '1' ? 1 : 0;
            PvUserRepository::setClearOnOpen($u['id'], $v);
            $out['clear_on_open'] = $v;
        }
        $view = pvw_input('search_view', null);
        if ($view !== null && (string)$view !== '') {
            $view = in_array((string)$view, array('table', 'list', 'card'), true) ? (string)$view : 'table';
            PvUserRepository::setSearchView($u['id'], $view);
            $out['search_view'] = $view;
        }
        pvw_json(200, 'success', $out);
    }

    /** 记录前端操作日志（读片 / 下载 / 阅读 DICOM 等） */
    public static function log() {
        PvAuth::requireLoginJson();
        pvw_csrf_check();
        $action = substr((string)pvw_input('action'), 0, 32);
        $detail = mb_substr((string)pvw_input('detail'), 0, 200, 'UTF-8');
        $allowed = array('read', 'download', 'dicom', 'search', 'report');
        if (!in_array($action, $allowed, true)) pvw_json(400, '非法的操作类型');
        $u = PvAuth::user();
        PvQueryLogRepository::event($u['username'], $action, $detail);
        pvw_json(200, 'success', null);
    }
}
