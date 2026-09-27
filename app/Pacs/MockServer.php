<?php
/**
 * ============================================================
 * app/Pacs/MockServer.php — 内置模拟 PACS 服务器
 * ============================================================
 * 本 PACS 浏览器自身不含数据。为便于联调，内置一个「模拟 PACS 服务器」：
 *   · 通过【对外 API】提供标准 PACS 接口（search / study / ping），
 *     可配置到本浏览器的 DICOM/PACS 接口地址，或提供给门诊系统调用；
 *   · 患者数据来源二选一：
 *       - builtin：确定性仿真数据（开箱即用，默认）；
 *       - fhir   ：通过 FHIR R4 从门诊系统获取「已缴费、已登记」患者及其检查；
 *   · 影像仍由前端算法确定性生成（series.is_mock = true），用于验证阅片链路。
 *
 * 对外接口（与 PvPacsClient 约定一致）：
 *   GET {mock}?action=ping&key=KEY
 *   GET {mock}?action=search&q=关键词&key=KEY
 *   GET {mock}?action=study&uid=检查UID&key=KEY
 * ============================================================ */
class PvMockServer {

    public static function enabled() { return (string)PvSettings::get('mock_enabled', '1') === '1'; }
    public static function apiKey()  { return trim((string)PvSettings::get('mock_api_key', '')); }
    public static function source()  { return PvSettings::get('mock_patient_source', 'builtin') === 'fhir' ? 'fhir' : 'builtin'; }

    /** 对外 API 的绝对地址（供配置到 DICOM/PACS 接口或门诊系统） */
    public static function externalEndpoint() { return pvw_abs_url('mock'); }

    /** 校验对外接口密钥 */
    public static function checkKey($key) {
        $k = self::apiKey();
        return $k !== '' && is_string($key) && hash_equals($k, $key);
    }

    /** 判断给定接口地址是否指向本模拟服务器（用于内部直连、避免自请求） */
    public static function isSelfEndpoint($url) {
        $q = parse_url((string)$url, PHP_URL_QUERY);
        if (!$q) return false;
        $params = array();
        parse_str($q, $params);
        return isset($params['r']) && $params['r'] === 'mock';
    }

    /* ---------------- 数据来源 ---------------- */

    /** 患者检查行（统一结构） */
    public static function rows($keyword = '') {
        if (self::source() === 'fhir') {
            return PvFhirClient::search($keyword);
        }
        return self::filterBuiltin(PvDemoPacs::studies(), $keyword);
    }

    /** 供管理界面预览：按患者聚合（患者 + 其检查） */
    public static function patients($keyword = '') {
        $patients = array();
        foreach (self::rows($keyword) as $r) {
            $key = $r['patient_id'];
            if (!isset($patients[$key])) {
                $patients[$key] = array(
                    'patient_id'    => $r['patient_id'],
                    'name'          => $r['name'],
                    'gender'        => $r['gender'],
                    'age'           => $r['age'],
                    'outpatient_no' => isset($r['outpatient_no']) ? $r['outpatient_no'] : '',
                    'exams'         => array(),
                );
            }
            $patients[$key]['exams'][] = array(
                'study_uid'   => $r['study_uid'],
                'accession_no'=> isset($r['accession_no']) ? $r['accession_no'] : '',
                'modality'    => $r['modality'],
                'description' => $r['description'],
                'study_date'  => isset($r['study_date']) ? $r['study_date'] : '',
                'status'      => isset($r['status']) ? $r['status'] : 'completed',
            );
        }
        return array_values($patients);
    }

    /* ---------------- 对外 API ---------------- */

    public static function search($keyword = '') {
        $rows = self::rows($keyword);
        $site = PvSettings::get('hospital_name', '');
        foreach ($rows as &$r) {
            if (empty($r['institution'])) $r['institution'] = $site;
            $r['status_name'] = '已完成';
        }
        unset($r);
        return $rows;
    }

    public static function study($uid) {
        $row = null;
        foreach (self::rows('') as $r) {
            if ($r['study_uid'] === $uid || (isset($r['accession_no']) && $r['accession_no'] === $uid)) { $row = $r; break; }
        }
        if (!$row) return null;
        // 若按检查号匹配，回填 study_uid 以便前端展示
        $patient = array(
            'patient_id'    => $row['patient_id'],
            'name'          => $row['name'],
            'gender'        => $row['gender'],
            'age'           => $row['age'],
            'birth_date'    => isset($row['birth_date']) ? $row['birth_date'] : '',
            'outpatient_no' => isset($row['outpatient_no']) ? $row['outpatient_no'] : '',
        );
        $st = array(
            'accession_no'    => isset($row['accession_no']) ? $row['accession_no'] : '',
            'study_uid'       => $row['study_uid'],
            'modality'        => $row['modality'],
            'description'     => $row['description'],
            'study_date'      => isset($row['study_date']) ? $row['study_date'] : '',
            'institution'     => isset($row['institution']) ? $row['institution'] : '',
            'station_name'    => isset($row['station_name']) ? $row['station_name'] : '',
            'apply_dept'      => isset($row['apply_dept']) ? $row['apply_dept'] : '',
            'apply_doctor'    => isset($row['apply_doctor']) ? $row['apply_doctor'] : '',
            'slice_thickness' => in_array(strtoupper($row['modality']), array('CT', 'MR'), true) ? 5.0 : 0,
        );
        return array(
            'patient' => $patient,
            'study'   => $st,
            'series'  => PvDemoPacs::seriesFor($row['modality'], $row['study_uid']),
        );
    }

    public static function ping() {
        $count = 0;
        try { $count = count(self::rows('')); } catch (Exception $e) { $count = 0; }
        return array(
            'name'    => '内置模拟 PACS 服务器',
            'version' => PV_VERSION,
            'mode'    => 'Mock',
            'source'  => self::source(),
            'studies' => $count,
        );
    }

    /* ---------------- 内部工具 ---------------- */

    private static function filterBuiltin($list, $keyword) {
        $kw = trim((string)$keyword);
        if ($kw === '') return $list;
        $out = array();
        foreach ($list as $s) {
            $hay = $s['name'] . ' ' . $s['patient_id'] . ' ' . $s['accession_no'] . ' ' . $s['outpatient_no'] . ' ' . $s['description'];
            if (mb_stripos($hay, $kw, 0, 'UTF-8') !== false) $out[] = $s;
        }
        return $out;
    }
}
