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

    public static function isConfigured() {
        return trim((string)PvSettings::get('fhir_endpoint', '')) !== '';
    }

    /** 检索已缴费已登记的患者检查（规范化为 PACS 检索行结构） */
    public static function search($keyword = '') {
        if (!self::isConfigured()) {
            throw new RuntimeException('未配置门诊系统 FHIR 接口地址');
        }
        $kw = trim((string)$keyword);
        $patients = self::queryPatients($kw);
        $rows = array();
        foreach ($patients as $p) {
            $studies = self::queryImagingStudies('Patient/' . $p['id']);
            foreach ($studies as $im) {
                $rows[] = self::mapStudy($p, $im);
            }
        }
        return $rows;
    }

    /** 接口连通性测试（读取 CapabilityStatement / metadata） */
    public static function ping() {
        $base = self::base();
        $meta = self::getJson($base . '/metadata');
        $name = 'FHIR R4 服务';
        if (isset($meta['name'])) $name = (string)$meta['name'];
        elseif (isset($meta['software']['name'])) $name = (string)$meta['software']['name'];
        return array('name' => $name, 'endpoint' => $base, 'source' => 'fhir');
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
        $params = array('_count' => 20);
        if ($keyword !== '') {
            // 同时尝试姓名与患者标识；FHIR 服务端对不支持的参数通常忽略
            $params['name'] = $keyword;
        }
        $bundle = self::getJson(self::base() . '/Patient?' . http_build_query($params));
        $list = array();
        foreach (self::entries($bundle) as $r) {
            if (!isset($r['id'])) continue;
            $list[] = $r;
        }
        return $list;
    }

    private static function queryImagingStudies($patientRef) {
        $url = self::base() . '/ImagingStudy?' . http_build_query(array('patient' => $patientRef, '_count' => 50));
        $bundle = self::getJson($url);
        $list = array();
        foreach (self::entries($bundle) as $r) {
            if (isset($r['resourceType']) && $r['resourceType'] === 'ImagingStudy') $list[] = $r;
        }
        return $list;
    }

    private static function entries($bundle) {
        if (!is_array($bundle)) return array();
        if (isset($bundle['resourceType']) && $bundle['resourceType'] !== 'Bundle') return array($bundle);
        $out = array();
        foreach ((array)(isset($bundle['entry']) ? $bundle['entry'] : array()) as $e) {
            if (isset($e['resource'])) $out[] = $e['resource'];
        }
        return $out;
    }

    /* ---------------- 资源映射 ---------------- */

    private static function mapStudy($patient, $im) {
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

    private static function base() { return rtrim((string)PvSettings::get('fhir_endpoint', ''), '/'); }

    private static function getJson($url) {
        $timeout = max(1, (int)PvSettings::get('fhir_timeout', '5'));
        $key = trim((string)PvSettings::get('fhir_api_key', ''));
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
