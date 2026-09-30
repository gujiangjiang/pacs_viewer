<?php
/**
 * ============================================================
 * app/Pacs/FhirClient.php — 门诊系统 FHIR R4 客户端（只读）
 * ============================================================
 * 内置模拟 PACS 服务器的患者数据来源之一：通过 FHIR R4 从门诊一体化系统
 * （Clinic_OPD_System 等）获取「已缴费、已登记」的患者及其检查信息。
 *
 * 取数口径（约定）：
 *   1) 先按姓名 / 患者标识检索 Patient：
 *        GET {fhir}/Patient?name=关键词&_count=20
 *        GET {fhir}/Patient?_id=患者ID
 *   2) 对每个 Patient 查询其已登记的影像检查 ImagingStudy：
 *        GET {fhir}/ImagingStudy?patient=Patient/{id}&_count=50
 *      存在 ImagingStudy 即视为「已缴费并已登记」（门诊系统在登记后创建该资源）。
 *   3) 资源同时兼容「Patient / ImagingStudy 以 Bundle 返回」与「直接数组返回」。
 *
 * 认证（可选）：配置密钥后同时发送 Authorization: Bearer 与 X-API-Key 头。
 * 返回结构与内置模拟数据保持一致，便于模拟服务器统一对外。
 * ============================================================ */
class PvFhirClient {

    /** 临时配置覆盖（仅用于「测试当前输入」场景，请求结束后清空） */
    private static $override = null;
    /** 最近一次检索的错误信息（便于界面提示） */
    private static $lastError = '';

    public static function isConfigured() {
        return self::base() !== '';
    }
    public static function lastError() { return self::$lastError; }
    private static function note(Exception $e) { self::$lastError = $e->getMessage(); }

    /**
     * 检索患者检查（规范化为 PACS 检索行结构）。
     * 兼容不同 FHIR 服务的实现差异：
     *   · 患者：优先标准搜索 Patient?name= / identifier=；否则按患者号读取 Patient/{id}；
     *     再回退到列出 Encounter 推导患者。
     *   · 检查：优先 ImagingStudy?patient=；否则用 Encounter?patient=（门诊就诊记录）。
     */
    public static function search($keyword = '') {
        self::$lastError = '';
        if (!self::isConfigured()) {
            throw new RuntimeException('未配置门诊系统 FHIR 接口地址');
        }
        $kw = trim((string)$keyword);
        $patients = self::queryPatients($kw);
        $rows = array();
        foreach ($patients as $p) {
            $pid = isset($p['id']) ? (string)$p['id'] : '';
            if ($pid === '') continue;
            foreach (self::queryStudies('Patient/' . $pid) as $res) {
                $rows[] = self::mapStudy($p, $res);
            }
        }
        return $rows;
    }

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
            return array('name' => $name, 'endpoint' => $base, 'source' => 'fhir');
        } finally {
            self::$override = null;
        }
    }

    /**
     * 调阅：FHIR 提供患者 / 检查主数据，影像经 PACS（真实 StudyInstanceUID）获取。
     * study_uid 约定为 `fhir-{ImagingStudy.id}`。
     */
    public static function study($uid) {
        $iid = preg_replace('/^fhir-/', '', (string)$uid);
        if ($iid === '') throw new RuntimeException('无法解析 FHIR 检查标识');
        $im = self::getJson(self::base() . '/ImagingStudy/' . rawurlencode($iid));
        $pid = self::patientRef($im);
        $patient = $pid !== '' ? self::getJson(self::base() . '/Patient/' . rawurlencode($pid)) : array('id' => $pid);
        $row = self::mapStudy($patient, $im);

        $patientArr = array(
            'patient_id'    => $row['patient_id'],
            'name'          => $row['name'],
            'gender'        => $row['gender'],
            'age'           => $row['age'],
            'birth_date'    => $row['birth_date'],
            'outpatient_no' => $row['outpatient_no'],
        );
        $studyArr = array(
            'accession_no'    => $row['accession_no'],
            'study_uid'       => $row['study_uid'],
            'modality'        => $row['modality'],
            'description'     => $row['description'],
            'study_date'      => $row['study_date'],
            'institution'     => $row['institution'],
            'station_name'    => $row['station_name'],
            'apply_dept'      => $row['apply_dept'],
            'apply_doctor'    => $row['apply_doctor'],
            'slice_thickness' => in_array($row['modality'], array('CT', 'MR'), true) ? 5.0 : 0,
        );

        /* 有真实 DICOM StudyInstanceUID 且已配置 PACS → 由 PACS 提供序列与影像 */
        $realUid = self::dicomStudyUid($im);
        if ($realUid !== '' && PvPacsClient::isRemote()) {
            try {
                $d = PvPacsClient::study($realUid);
                $d['patient'] = $patientArr;                      // FHIR 患者主数据优先
                $d['study']['study_uid'] = $realUid;
                $d['study']['accession_no'] = $studyArr['accession_no'];
                if (empty($d['study']['description'])) $d['study']['description'] = $studyArr['description'];
                if (empty($d['study']['modality'])) $d['study']['modality'] = $studyArr['modality'];
                return $d;
            } catch (Exception $e) {
                /* 回退到仅元数据 */
            }
        }

        return array('patient' => $patientArr, 'study' => $studyArr, 'series' => self::seriesFromImagingStudy($im));
    }

    /** 从 ImagingStudy.subject 提取患者 ID */
    private static function patientRef($im) {
        $ref = isset($im['subject']['reference']) ? (string)$im['subject']['reference'] : '';
        if ($ref === '') return '';
        return strpos($ref, '/') !== false ? substr(strrchr($ref, '/'), 1) : $ref;
    }

    /** 从 ImagingStudy.identifier 提取真实 DICOM StudyInstanceUID（urn:dicom:uid / urn:oid:） */
    private static function dicomStudyUid($im) {
        foreach ((array)(isset($im['identifier']) ? $im['identifier'] : array()) as $id) {
            $sys = isset($id['system']) ? (string)$id['system'] : '';
            $val = isset($id['value']) ? (string)$id['value'] : '';
            if ($val === '') continue;
            if (stripos($sys, 'dicom') !== false || stripos($val, 'urn:oid:') === 0) {
                $oid = preg_replace('/^urn:oid:/i', '', $val);
                $oid = preg_replace('/[^0-9.]/', '', $oid);
                if ($oid !== '') return $oid;
            }
        }
        return '';
    }

    /** 无 PACS 时：由 ImagingStudy.series 构建序列元数据（影像帧地址为空） */
    private static function seriesFromImagingStudy($im) {
        $out = array();
        $i = 0;
        foreach ((array)(isset($im['series']) ? $im['series'] : array()) as $s) {
            $i++;
            $modality = '';
            if (isset($s['modality']['code'])) $modality = strtoupper((string)$s['modality']['code']);
            $count = isset($s['numberOfInstances']) ? (int)$s['numberOfInstances'] : 0;
            $out[] = array(
                'series_id' => (string)(isset($s['uid']) ? $s['uid'] : $i),
                'description' => isset($s['description']) ? (string)$s['description'] : ('Series ' . $i),
                'orientation' => '',
                'slice_count' => max(1, $count),
                'is_mock' => false,
                'format' => 'dicom',
                'is_hu' => ($modality === 'CT'),
                'slice_thickness' => isset($s['sliceThickness']) ? (float)$s['sliceThickness'] : 0,
                'pixel_spacing' => 0.7,
                'seed' => '',
                'images' => array(),
            );
        }
        return $out;
    }

    /* ---------------- 查询 ---------------- */

    private static function queryPatients($keyword) {
        $base = self::base();
        $kw = trim((string)$keyword);
        $list = array();

        // 1) 标准搜索：name / identifier
        if ($kw !== '') {
            foreach (array('name', 'identifier') as $param) {
                try {
                    $b = self::getJson($base . '/Patient?' . http_build_query(array($param => $kw, '_count' => 20)));
                    foreach (self::entries($b) as $r) { if (self::isType($r, 'Patient')) $list[] = $r; }
                } catch (Exception $e) { self::note($e); }
                if ($list) return $list;
            }
            // 2) 按患者号直接读取：Patient/{patient_no}
            try {
                $p = self::getJson($base . '/Patient/' . rawurlencode($kw));
                if (self::isType($p, 'Patient')) $list[] = $p;
            } catch (Exception $e) { self::note($e); }
            if ($list) return $list;
            // 3) 通过 Encounter?patient= 校验并回读患者
            try {
                $b = self::getJson($base . '/Encounter?' . http_build_query(array('patient' => 'Patient/' . $kw, '_count' => 1)));
                $enc = self::entries($b);
                if ($enc) { $list[] = array('id' => $kw, 'resourceType' => 'Patient'); }
            } catch (Exception $e) { self::note($e); }
            return $list;
        }

        // 4) 列表：先 Patient?_count，再回退 Encounter?_count 推导患者
        //    （部分服务不提供列表，失败属正常，不记为错误）
        try {
            $b = self::getJson($base . '/Patient?' . http_build_query(array('_count' => 20)));
            foreach (self::entries($b) as $r) { if (self::isType($r, 'Patient')) $list[] = $r; }
        } catch (Exception $e) {}
        if (!$list) {
            try {
                $b = self::getJson($base . '/Encounter?' . http_build_query(array('_count' => 50)));
                $seen = array();
                foreach (self::entries($b) as $enc) {
                    if (!self::isType($enc, 'Encounter')) continue;
                    $ref = self::subjectRef($enc);
                    if ($ref === '') continue;
                    $id = self::refId($ref);
                    if ($id === '' || isset($seen[$id])) continue;
                    $seen[$id] = 1;
                    try {
                        $p = self::getJson($base . '/Patient/' . rawurlencode($id));
                        $list[] = self::isType($p, 'Patient') ? $p : array('id' => $id, 'resourceType' => 'Patient');
                    } catch (Exception $e) { $list[] = array('id' => $id, 'resourceType' => 'Patient'); }
                }
            } catch (Exception $e) {}
        }
        return $list;
    }

    /** 取某患者的检查资源：优先 ImagingStudy，回退 Encounter（门诊就诊） */
    private static function queryStudies($patientRef) {
        $base = self::base();
        try {
            $b = self::getJson($base . '/ImagingStudy?' . http_build_query(array('patient' => $patientRef, '_count' => 50)));
            $out = array();
            foreach (self::entries($b) as $r) { if (self::isType($r, 'ImagingStudy')) $out[] = $r; }
            if ($out) return $out;
        } catch (Exception $e) { self::note($e); }
        try {
            $b = self::getJson($base . '/Encounter?' . http_build_query(array('patient' => $patientRef, '_count' => 50)));
            $out = array();
            foreach (self::entries($b) as $r) { if (self::isType($r, 'Encounter')) $out[] = $r; }
            return $out;
        } catch (Exception $e) { self::note($e); }
        return array();
    }

    /** 资源类型判断（容忍缺少 resourceType 的直接资源） */
    private static function isType($r, $type) {
        if (!is_array($r)) return false;
        if (isset($r['resourceType'])) return $r['resourceType'] === $type;
        if ($type === 'Patient') return isset($r['id']) && (isset($r['name']) || isset($r['gender']) || isset($r['birthDate']));
        return false;
    }

    /** Encounter.subject.reference（如 Patient/123） */
    private static function subjectRef($enc) {
        return isset($enc['subject']['reference']) ? (string)$enc['subject']['reference'] : '';
    }
    private static function refId($ref) {
        $ref = (string)$ref;
        return strpos($ref, '/') !== false ? substr(strrchr($ref, '/'), 1) : $ref;
    }

    private static function entries($bundle) {
        if (!is_array($bundle)) return array();
        if (isset($bundle['entry'])) {
            $out = array();
            foreach ((array)$bundle['entry'] as $e) { if (isset($e['resource'])) $out[] = $e['resource']; }
            return $out;
        }
        // 直接资源（无 Bundle 包装）
        if (isset($bundle['resourceType']) && $bundle['resourceType'] !== 'Bundle') return array($bundle);
        if (isset($bundle['id']) || isset($bundle['name']) || isset($bundle['gender']) || isset($bundle['period'])) return array($bundle);
        return array();
    }

    /* ---------------- 资源映射 ---------------- */

    private static function mapStudy($patient, $im) {
        if (self::isType($im, 'Encounter')) return self::mapEncounter($patient, $im);
        $pid = (string)$patient['id'];
        $name = self::patientName($patient);
        $gender = self::gender($patient);
        $birth = isset($patient['birthDate']) ? (string)$patient['birthDate'] : '';
        $age = self::ageText($birth);
        $modality = '';
        if (isset($im['modality'][0]['code'])) $modality = strtoupper((string)$im['modality'][0]['code']);
        $uid = (string)$im['id'];
        $acc = self::firstIdentifier($im);
        if ($acc === '') $acc = 'ACC' . preg_replace('/\D/', '', $uid);
        $started = isset($im['started']) ? (string)$im['started'] : '';
        $desc = isset($im['description']) ? (string)$im['description'] : '';
        $seriesCount = isset($im['numberOfSeries']) ? (int)$im['numberOfSeries'] : 0;
        if ($seriesCount <= 0) $seriesCount = in_array($modality, array('CT', 'MR'), true) ? 3 : 1;
        return array(
            'study_uid'     => 'fhir-' . $uid,
            'patient_id'    => $pid,
            'name'          => $name,
            'gender'        => $gender,
            'age'           => $age,
            'birth_date'    => $birth,
            'outpatient_no' => self::outpatientNo($patient),
            'accession_no'  => $acc,
            'modality'      => $modality !== '' ? $modality : 'OT',
            'description'   => $desc !== '' ? $desc : '影像检查',
            'study_date'    => self::fmtDate($started),
            'institution'   => pvw_hospital(),
            'station_name'  => ($modality !== '' ? $modality : 'OT') . '-ROOM',
            'apply_dept'    => '',
            'apply_doctor'  => '',
            'status'        => 'completed',
            'series_count'  => $seriesCount,
        );
    }

    /** Encounter（门诊就诊记录）→ 检索行（无影像模态，标注 OT，影像由模拟器生成） */
    private static function mapEncounter($patient, $enc) {
        $pid = isset($patient['id']) ? (string)$patient['id'] : '';
        $birth = isset($patient['birthDate']) ? (string)$patient['birthDate'] : '';
        $id = isset($enc['id']) ? (string)$enc['id'] : 'enc';
        $desc = self::encounterText($enc);
        $started = isset($enc['period']['start']) ? (string)$enc['period']['start'] : '';
        $acc = self::firstIdentifier($enc);
        if ($acc === '') $acc = 'ENC' . preg_replace('/\D/', '', $id);
        return array(
            'study_uid'     => 'fhir-enc-' . $id,
            'patient_id'    => $pid,
            'name'          => self::patientName($patient),
            'gender'        => self::gender($patient),
            'age'           => self::ageText($birth),
            'birth_date'    => $birth,
            'outpatient_no' => self::outpatientNo($patient),
            'accession_no'  => $acc,
            'modality'      => 'OT',
            'description'   => $desc !== '' ? $desc : '门诊就诊 · 影像检查',
            'study_date'    => self::fmtDate($started),
            'institution'   => pvw_hospital(),
            'station_name'  => 'FHIR',
            'apply_dept'    => '',
            'apply_doctor'  => '',
            'status'        => 'completed',
            'series_count'  => 1,
        );
    }

    private static function encounterText($enc) {
        if (isset($enc['type'][0]['text']) && $enc['type'][0]['text'] !== '') return (string)$enc['type'][0]['text'];
        if (isset($enc['type'][0]['coding'][0]['display'])) return (string)$enc['type'][0]['coding'][0]['display'];
        if (isset($enc['serviceType']['text'])) return (string)$enc['serviceType']['text'];
        return '';
    }

    private static function patientName($p) {
        if (isset($p['name'][0]['text']) && $p['name'][0]['text'] !== '') return (string)$p['name'][0]['text'];
        $n = isset($p['name'][0]) ? $p['name'][0] : array();
        $given = isset($n['given']) ? implode('', (array)$n['given']) : '';
        $family = isset($n['family']) ? (string)$n['family'] : '';
        $full = $family . $given;
        return $full !== '' ? $full : ('患者' . (isset($p['id']) ? $p['id'] : ''));
    }
    private static function gender($p) {
        $g = isset($p['gender']) ? strtolower((string)$p['gender']) : '';
        if ($g === 'male') return '男';
        if ($g === 'female') return '女';
        return $g !== '' ? $g : '未知';
    }
    private static function outpatientNo($p) {
        foreach ((array)(isset($p['identifier']) ? $p['identifier'] : array()) as $id) {
            $val = isset($id['value']) ? (string)$id['value'] : '';
            $type = isset($id['type']['coding'][0]['code']) ? strtoupper((string)$id['type']['coding'][0]['code']) : '';
            $sys = isset($id['system']) ? (string)$id['system'] : '';
            if ($val === '') continue;
            if ($type === 'MR' || $type === 'OP' || stripos($sys, 'outpatient') !== false) return $val;
        }
        return '';
    }
    private static function firstIdentifier($res) {
        foreach ((array)(isset($res['identifier']) ? $res['identifier'] : array()) as $id) {
            if (isset($id['value']) && (string)$id['value'] !== '') return (string)$id['value'];
        }
        return '';
    }
    private static function ageText($birth) {
        if ($birth === '') return '';
        $t = strtotime($birth);
        if ($t === false) return '';
        $y = (int)floor((time() - $t) / (365.25 * 86400));
        return $y > 0 && $y < 130 ? ($y . '岁') : '';
    }
    private static function fmtDate($s) {
        if ($s === '') return '';
        $t = strtotime($s);
        return $t === false ? $s : date('Y-m-d H:i:s', $t);
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
        if ($raw === false || $raw === '') throw new RuntimeException('无法连接门诊系统 FHIR 接口：' . $url);
        $j = json_decode($raw, true);
        if (!is_array($j)) throw new RuntimeException('FHIR 接口返回非 JSON 数据');
        if (isset($j['resourceType']) && $j['resourceType'] === 'OperationOutcome') {
            $msg = isset($j['issue'][0]['diagnostics']) ? $j['issue'][0]['diagnostics'] : '操作失败';
            throw new RuntimeException('FHIR 接口返回错误：' . $msg);
        }
        return $j;
    }

    private static function httpGet($url, $timeout, array $headers) {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => $headers,
            ));
            $raw = curl_exec($ch);
            if (PHP_VERSION_ID < 80500) curl_close($ch);   // 8.5 起 curl_close 已弃用
            return $raw;
        }
        $ctx = stream_context_create(array('http' => array(
            'method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true,
            'header' => implode("\r\n", $headers) . "\r\n",
        )));
        return @file_get_contents($url, false, $ctx);
    }
}
