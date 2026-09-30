<?php
/**
 * ============================================================
 * app/Pacs/PacsClient.php — 外部 DICOM / PACS 接口客户端
 * ============================================================
 * 本 PACS 浏览器自身不含数据：检索（search / study / ping）全部指向管理设置
 * 中配置的远程 PACS / DICOMWeb 网关。未配置接口地址时明确报错，不再内置回退。
 *
 * 本地联调可把接口地址指向【内置模拟 PACS 服务器】提供的对外 API
 * （由「模拟服务器」页一键填入）。
 *
 * 远程接口约定（JSON）：
 *   GET {endpoint}?action=search&q=关键词&key=APIKEY
 *       → {code:200, msg, data:{list:[{study_uid,patient_id,name,gender,age,
 *            outpatient_no,accession_no,modality,description,study_date,
 *            institution,station_name,series_count}, ...]}}
 *   GET {endpoint}?action=study&uid=STUDY_UID&key=APIKEY
 *       → {code:200, msg, data:{patient:{...}, study:{...}, series:[...]}}
 *   GET {endpoint}?action=ping&key=APIKEY
 *       → {code:200, data:{name,version}}
 * ============================================================ */
class PvPacsClient {

    /** 临时配置覆盖（仅用于「测试当前输入」场景，请求结束后清空） */
    private static $override = null;

    /** 数据来源模式：Remote（已配置接口地址）/ Unset（未配置） */
    public static function mode() {
        return self::isRemote() ? 'Remote' : 'Unset';
    }

    /** 接口协议：gateway（简化网关）/ dicomweb（标准 DICOMweb） */
    public static function protocol() {
        return (string)PvSettings::get('pacs_protocol', 'gateway') === 'dicomweb' ? 'dicomweb' : 'gateway';
    }
    public static function isDicomWeb() { return self::protocol() === 'dicomweb'; }

    public static function isRemote() {
        return self::endpoint() !== '';
    }

    /** 使用指定配置测试连通性（不读取已保存设置）；可按协议走 DICOMweb */
    public static function pingWith($endpoint, $key = null, $timeout = null, $protocol = null) {
        $proto = ($protocol !== null && $protocol !== '') ? (string)$protocol : self::protocol();
        if ($proto === 'dicomweb') {
            return PvDicomWebClient::pingWith($endpoint, $key, $timeout);
        }
        self::$override = array(
            'endpoint' => $endpoint !== null ? trim((string)$endpoint) : null,
            'key' => $key !== null ? (string)$key : null,
            'timeout' => $timeout !== null && $timeout !== '' ? (int)$timeout : null,
        );
        try {
            $res = self::request('ping', array());
            $p = isset($res['data']) && is_array($res['data']) ? $res['data'] : array();
            $p['endpoint'] = self::endpoint();
            $p['mode'] = 'Remote';
            return $p;
        } finally {
            self::$override = null;
        }
    }

    /** 检索检查列表（仅列表，兼容旧调用） */
    public static function search($keyword) {
        $page = self::searchPage($keyword);
        return $page['list'];
    }

    /**
     * 分页检索：向接口传 limit/offset，返回结构化结果。
     * 兼容两种远端返回：{list,total,has_more} 或直接数组。
     * @return array {list, total, has_more}
     */
    public static function searchPage($keyword, $limit = 0, $offset = 0) {
        if (self::isDicomWeb()) return PvDicomWebClient::search($keyword, $limit, $offset);
        $params = array('q' => (string)$keyword);
        if ((int)$limit > 0) { $params['limit'] = (int)$limit; $params['offset'] = max(0, (int)$offset); }
        $res = self::request('search', $params);
        $data = isset($res['data']) && is_array($res['data']) ? $res['data'] : array();
        if (isset($data['list']) && is_array($data['list'])) {
            $list = $data['list'];
            $total = isset($data['total']) ? (int)$data['total'] : count($list);
            $hasMore = array_key_exists('has_more', $data) ? (bool)$data['has_more'] : ((int)$limit > 0 ? (count($list) >= (int)$limit) : false);
        } else {
            $list = $data;
            $total = count($list);
            $hasMore = false;
        }
        return array('list' => $list, 'total' => $total, 'has_more' => $hasMore);
    }

    /** 调阅单次检查（患者 + 检查 + 序列） */
    public static function study($uid) {
        if (self::isDicomWeb()) return PvDicomWebClient::study($uid);
        $res = self::request('study', array('uid' => (string)$uid));
        $d = isset($res['data']) && is_array($res['data']) ? $res['data'] : array();
        if (!isset($d['patient']) || !isset($d['study'])) {
            throw new RuntimeException('PACS 接口返回的数据格式不正确');
        }
        if (!isset($d['series']) || !is_array($d['series'])) $d['series'] = array();
        return $d;
    }

    /** 接口连通性测试 */
    public static function ping() {
        if (self::isDicomWeb()) return PvDicomWebClient::ping();
        $res = self::request('ping', array());
        $p = isset($res['data']) && is_array($res['data']) ? $res['data'] : array();
        $p['endpoint'] = PvSettings::get('pacs_endpoint', '');
        $p['mode'] = 'Remote';
        return $p;
    }

    /* ---------- HTTP 请求 ---------- */
    private static function request($action, array $params) {
        $endpoint = self::endpoint();
        if ($endpoint === '') throw new RuntimeException('未配置 PACS 接口地址');
        $params['action'] = $action;
        $key = self::apiKey();
        if ($key !== '') $params['key'] = $key;

        // 指向内置模拟服务器时进程内直连，避免服务器向自身发起 HTTP 请求
        if (PvMockServer::isSelfEndpoint($endpoint)) {
            return self::mockRequest($action, $params);
        }

        $url = $endpoint . (strpos($endpoint, '?') === false ? '?' : '&') . http_build_query($params);
        $timeout = self::timeout();

        $raw = self::httpGet($url, $timeout);
        if ($raw === false || $raw === '') throw new RuntimeException('无法连接 PACS 接口：' . $url);
        $j = json_decode($raw, true);
        if (!is_array($j)) throw new RuntimeException('PACS 接口返回非 JSON 数据');
        if (!isset($j['code']) || (int)$j['code'] !== 200) {
            throw new RuntimeException('PACS 接口返回错误：' . (isset($j['msg']) ? $j['msg'] : '未知错误'));
        }
        return $j;
    }

    /* ---------- 配置读取（支持临时覆盖） ---------- */
    private static function endpoint() {
        if (self::$override !== null && self::$override['endpoint'] !== null) return self::$override['endpoint'];
        return trim((string)PvSettings::get('pacs_endpoint', ''));
    }
    private static function apiKey() {
        if (self::$override !== null && self::$override['key'] !== null) return self::$override['key'];
        return trim((string)PvSettings::get('pacs_api_key', ''));
    }
    private static function timeout() {
        if (self::$override !== null && self::$override['timeout'] !== null) return max(1, (int)self::$override['timeout']);
        return max(1, (int)PvSettings::get('pacs_timeout', '5'));
    }

    /** 进程内直连内置模拟服务器（等价于对外 API，返回同样的 JSON 结构） */
    private static function mockRequest($action, array $params) {
        if (!PvMockServer::enabled()) throw new RuntimeException('内置模拟服务器未启用');
        $key = isset($params['key']) ? (string)$params['key'] : '';
        if (!PvMockServer::checkKey($key)) throw new RuntimeException('内置模拟服务器密钥校验失败');
        return PvMockServer::dispatch($action, $params);
    }

    private static function httpGet($url, $timeout) {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => array('Accept: application/json'),
            ));
            $raw = curl_exec($ch);
            if (PHP_VERSION_ID < 80500) curl_close($ch);   // 8.5 起 curl_close 已弃用
            return $raw;
        }
        $ctx = stream_context_create(array('http' => array(
            'method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true,
            'header' => "Accept: application/json\r\n",
        )));
        return @file_get_contents($url, false, $ctx);
    }
}
