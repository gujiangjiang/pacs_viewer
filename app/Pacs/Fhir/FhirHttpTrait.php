<?php
/**
 * ============================================================
 * app/Pacs/Fhir/FhirHttpTrait.php — FHIR 配置 / HTTP / 连通性 / 工作项回写
 * ============================================================
 * 由 PvFhirClient 使用：接口地址与密钥读取（含测试覆盖）、JSON 取数、
 * 机构名称、连通性测试与 Task 回写、出向调用日志。
 * 依赖类内静态属性 $override / $lastError / $lastStatus / $hospital。
 * ============================================================ */
trait PvFhirHttpTrait {

    public static function isConfigured() {
        return self::base() !== '';
    }

    /**
     * 机构（医院）名称：标准 FHIR R4 Organization.name。取到后记录到设置
     * pacs_hospital_name，供全局展示；未提供 Organization 时返回空。
     */
    public static function hospitalName() {
        if (self::$hospital !== null) return self::$hospital;
        self::$hospital = '';
        if (!self::isConfigured()) return '';
        try {
            $b = self::getJson(self::base() . '/Organization?' . http_build_query(array('_count' => 1)));
            $r = null;
            if (is_array($b) && !empty($b['entry'][0]['resource'])) $r = $b['entry'][0]['resource'];
            elseif (is_array($b) && isset($b['resourceType']) && $b['resourceType'] === 'Organization') $r = $b;
            if (is_array($r) && !empty($r['name'])) {
                self::$hospital = (string)$r['name'];
                if (trim((string)PvSettings::get('pacs_hospital_name', '')) !== self::$hospital) {
                    PvSettings::set('pacs_hospital_name', self::$hospital);
                }
            }
        } catch (Exception $e) {
            /* 未提供 Organization 资源时忽略（不记为错误） */
        }
        return self::$hospital;
    }
    public static function lastError() { return self::$lastError; }
    private static function note(Exception $e) { self::$lastError = $e->getMessage(); }

    /** 接口连通性测试（读取 CapabilityStatement / metadata） */
    public static function ping() {
        return self::pingWith(null);
    }

    /** 使用指定配置测试连通性（不读取已保存设置） */
    public static function pingWith($endpoint, $key = null, $timeout = null) {
        self::$override = array(
            'endpoint' => $endpoint !== null ? trim((string)$endpoint) : null,
            'key' => $key !== null ? (string)$key : null,
            'timeout' => $timeout !== null && $timeout !== '' ? (int)$timeout : null,
        );
        try {
            $base = self::base();
            if ($base === '') throw new RuntimeException('未配置门诊系统 FHIR 接口地址');
            $meta = self::getJson($base . '/metadata');
            $name = 'FHIR R4 服务';
            if (isset($meta['name'])) $name = (string)$meta['name'];
            elseif (isset($meta['software']['name'])) $name = (string)$meta['software']['name'];
            /* metadata（CapabilityStatement）通常可匿名访问，无法校验密钥；
             * 再对一个真实数据端点（Patient 检索）发一次带鉴权的请求，
             * 使错误密钥返回 401/403 时能够被识别为失败。 */
            self::getJson($base . '/Patient?' . http_build_query(array('_count' => 1)));
            // 机构名称（FHIR Organization.name）；未提供时留空
            $institution = '';
            try {
                $ob = self::getJson($base . '/Organization?' . http_build_query(array('_count' => 1)));
                $or = null;
                if (is_array($ob) && !empty($ob['entry'][0]['resource'])) $or = $ob['entry'][0]['resource'];
                elseif (is_array($ob) && isset($ob['resourceType']) && $ob['resourceType'] === 'Organization') $or = $ob;
                if (is_array($or) && !empty($or['name'])) $institution = (string)$or['name'];
            } catch (Exception $e) { /* 未提供 Organization 时忽略 */ }
            return array('name' => $name, 'endpoint' => $base, 'source' => 'fhir', 'institution' => $institution);
        } finally {
            self::$override = null;
        }
    }

    /**
     * 回写工作项状态到门诊 FHIR（PACS 侧登记 / 摄片）。
     * @param string $taskId  Task 资源 id（如 task-123）
     * @param array  $payload FHIR Task（至少含 status；可含 businessStatus/executionPeriod/owner）
     * @return array 更新后的资源
     * @throws RuntimeException 写入失败
     */
    public static function writeTask($taskId, array $payload) {
        if (!self::isConfigured()) throw new RuntimeException('未配置门诊系统 FHIR 接口地址');
        $url = self::base() . '/Task/' . rawurlencode((string)$taskId);
        $headers = array('Accept: application/fhir+json', 'Content-Type: application/fhir+json');
        $key = self::fhirKey();
        if ($key !== '') { $headers[] = 'Authorization: Bearer ' . $key; $headers[] = 'X-API-Key: ' . $key; }
        $r = PvHttp::request('PUT', $url, self::fhirTimeout(), $headers, json_encode($payload, JSON_UNESCAPED_UNICODE));
        self::logApi($url, (int)$r['code']);
        if ((int)$r['code'] < 200 || (int)$r['code'] >= 300) {
            $msg = (string)$r['body'];
            $j = json_decode((string)$r['body'], true);
            if (is_array($j) && isset($j['issue'][0]['diagnostics'])) $msg = (string)$j['issue'][0]['diagnostics'];
            throw new RuntimeException('FHIR 工作项回写失败（HTTP ' . (int)$r['code'] . '）：' . $msg);
        }
        $out = json_decode((string)$r['body'], true);
        return is_array($out) ? $out : array();
    }

    /* ---------------- HTTP ---------------- */

    private static function base() {
        if (self::$override !== null && self::$override['endpoint'] !== null) return rtrim(self::$override['endpoint'], '/');
        return rtrim((string)PvSettings::get('fhir_endpoint', ''), '/');
    }

    private static function fhirKey() {
        if (self::$override !== null && self::$override['key'] !== null) return self::$override['key'];
        return trim((string)PvSettings::get('fhir_api_key', ''));
    }

    private static function fhirTimeout() {
        if (self::$override !== null && self::$override['timeout'] !== null) return max(1, (int)self::$override['timeout']);
        return max(1, (int)PvSettings::get('fhir_timeout', '5'));
    }

    private static function getJson($url) {
        $timeout = self::fhirTimeout();
        $key = self::fhirKey();
        $headers = array('Accept: application/fhir+json');
        if ($key !== '') { $headers[] = 'Authorization: Bearer ' . $key; $headers[] = 'X-API-Key: ' . $key; }
        $raw = self::httpGet($url, $timeout, $headers);
        $status = self::$lastStatus;
        self::logApi($url, $status);
        if ($status === 401 || $status === 403) {
            throw new RuntimeException('鉴权失败：密钥无效或无访问权限（HTTP ' . $status . '）');
        }
        if ($raw === false || $raw === '') {
            throw new RuntimeException($status >= 400
                ? 'FHIR 接口请求失败（HTTP ' . $status . '）'
                : '无法连接门诊系统 FHIR 接口：' . $url);
        }
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            throw new RuntimeException($status >= 400
                ? 'FHIR 接口请求失败（HTTP ' . $status . '）'
                : 'FHIR 接口返回非 JSON 数据');
        }
        if (isset($j['resourceType']) && $j['resourceType'] === 'OperationOutcome') {
            $msg = isset($j['issue'][0]['diagnostics']) ? $j['issue'][0]['diagnostics'] : '操作失败';
            throw new RuntimeException('FHIR 接口返回错误：' . $msg);
        }
        return $j;
    }

    /** 复用统一 HTTP 助手（curl 优先 / file 回退），并记录响应状态码供鉴权识别 */
    private static function httpGet($url, $timeout, array $headers) {
        $r = PvHttp::get($url, $timeout, $headers);
        self::$lastStatus = (int)$r['code'];
        return $r['body'];
    }

    /** 记录一次 FHIR 来源 API 调用到「模拟服务器日志」（channel=mock） */
    private static function logApi($url, $status) {
        if (!class_exists('PvActivityLogRepository')) return;
        $path = (string)parse_url((string)$url, PHP_URL_PATH);
        $query = (string)parse_url((string)$url, PHP_URL_QUERY);
        $query = PvHttp::redactQuery($query);
        $ok = $status >= 200 && $status < 300;
        $level = $ok ? 'info' : ($status === 0 ? 'error' : 'warn');
        $stTxt = $status === 0 ? '连接失败' : ('HTTP ' . $status);
        PvActivityLogRepository::mock('fhir/api', 'FHIR 取数（' . $stTxt . '）：' . $path, $level, array('status' => $status, 'query' => $query));
    }
}
