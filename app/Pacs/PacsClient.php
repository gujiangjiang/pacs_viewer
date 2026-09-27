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

    /** 数据来源模式：Remote（已配置接口地址）/ Unset（未配置） */
    public static function mode() {
        return self::isRemote() ? 'Remote' : 'Unset';
    }

    public static function isRemote() {
        return trim((string)PvSettings::get('pacs_endpoint', '')) !== '';
    }

    /** 检索检查列表 */
    public static function search($keyword) {
        $res = self::request('search', array('q' => (string)$keyword));
        $list = isset($res['data']['list']) && is_array($res['data']['list']) ? $res['data']['list'] : array();
        return $list;
    }

    /** 调阅单次检查（患者 + 检查 + 序列） */
    public static function study($uid) {
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
        $res = self::request('ping', array());
        $p = isset($res['data']) && is_array($res['data']) ? $res['data'] : array();
        $p['endpoint'] = PvSettings::get('pacs_endpoint', '');
        $p['mode'] = 'Remote';
        return $p;
    }

    /* ---------- HTTP 请求 ---------- */
    private static function request($action, array $params) {
        $endpoint = trim((string)PvSettings::get('pacs_endpoint', ''));
        if ($endpoint === '') throw new RuntimeException('未配置 PACS 接口地址');
        $params['action'] = $action;
        $key = trim((string)PvSettings::get('pacs_api_key', ''));
        if ($key !== '') $params['key'] = $key;

        // 指向内置模拟服务器时进程内直连，避免服务器向自身发起 HTTP 请求
        if (PvMockServer::isSelfEndpoint($endpoint)) {
            return self::mockRequest($action, $params);
        }

        $url = $endpoint . (strpos($endpoint, '?') === false ? '?' : '&') . http_build_query($params);
        $timeout = max(1, (int)PvSettings::get('pacs_timeout', '5'));

        $raw = self::httpGet($url, $timeout);
        if ($raw === false || $raw === '') throw new RuntimeException('无法连接 PACS 接口：' . $url);
        $j = json_decode($raw, true);
        if (!is_array($j)) throw new RuntimeException('PACS 接口返回非 JSON 数据');
        if (!isset($j['code']) || (int)$j['code'] !== 200) {
            throw new RuntimeException('PACS 接口返回错误：' . (isset($j['msg']) ? $j['msg'] : '未知错误'));
        }
        return $j;
    }

    /** 进程内直连内置模拟服务器（等价于对外 API，返回同样的 JSON 结构） */
    private static function mockRequest($action, array $params) {
        if (!PvMockServer::enabled()) throw new RuntimeException('内置模拟服务器未启用');
        $key = isset($params['key']) ? (string)$params['key'] : '';
        if (!PvMockServer::checkKey($key)) throw new RuntimeException('内置模拟服务器密钥校验失败');
        if ($action === 'ping') {
            return array('code' => 200, 'msg' => 'success', 'data' => PvMockServer::ping());
        }
        if ($action === 'search') {
            $q = isset($params['q']) ? (string)$params['q'] : '';
            return array('code' => 200, 'msg' => 'success', 'data' => array('list' => PvMockServer::search($q)));
        }
        if ($action === 'study') {
            $uid = isset($params['uid']) ? (string)$params['uid'] : '';
            $d = PvMockServer::study($uid);
            if (!$d) return array('code' => 404, 'msg' => '未找到该检查', 'data' => null);
            return array('code' => 200, 'msg' => 'success', 'data' => $d);
        }
        return array('code' => 400, 'msg' => '未知操作', 'data' => null);
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
            curl_close($ch);
            return $raw;
        }
        $ctx = stream_context_create(array('http' => array(
            'method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true,
            'header' => "Accept: application/json\r\n",
        )));
        return @file_get_contents($url, false, $ctx);
    }
}
