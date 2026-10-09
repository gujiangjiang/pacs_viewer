<?php
/**
 * ============================================================
 * app/Pacs/Fhir/FhirMapTrait.php — FHIR 资源 → 内部模型映射
 * ============================================================
 * 由 PvFhirClient 使用：Bundle / 资源解析、患者 / 检查 / 就诊映射、
 * 标识（患者号 / 检查号 / 就诊号）挑选与引用尾段解析。
 * 依赖类内静态属性与 FhirHttpTrait 的取数能力，不可独立使用。
 * ============================================================ */
trait PvFhirMapTrait {

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
                $oid = trim(preg_replace('/^urn:oid:/i', '', $val));
                // 仅接受标准 DICOM UID（数字与点、至少两段、每段无前导零、≤64）；
                // 不合规（如旧的 BG 占位）不臆造，交由上层回退，避免产生非标准 UID。
                if (strlen($oid) <= 64 && preg_match('/^(0|[1-9][0-9]*)(\.(0|[1-9][0-9]*))+$/', $oid)) return $oid;
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
            $modality = self::codeOf(isset($s['modality']) ? $s['modality'] : array());
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
        $pid = self::patientNo($patient);
        $name = self::patientName($patient);
        $gender = self::gender($patient);
        $birth = isset($patient['birthDate']) ? (string)$patient['birthDate'] : '';
        $age = PvDicom::ageText($birth);
        /* modality 可能是 R4 的单个 CodeableConcept，也可能是数组（部分实现用 R5 风格），兼容两者 */
        $modality = self::codeOf(isset($im['modality'][0]) ? $im['modality'][0] : (isset($im['modality']) ? $im['modality'] : array()));
        $uid = isset($im['id']) ? (string)$im['id'] : '';
        $acc = self::accessionOf($im);
        if ($acc === '') $acc = 'ACC' . preg_replace('/\D/', '', $uid);
        $started = isset($im['started']) ? (string)$im['started'] : '';
        $desc = isset($im['description']) ? (string)$im['description'] : '';
        $seriesCount = isset($im['numberOfSeries']) ? (int)$im['numberOfSeries'] : 0;
        if ($seriesCount <= 0) $seriesCount = in_array($modality, array('CT', 'MR'), true) ? 3 : 1;
        // 优先使用 FHIR ImagingStudy.identifier 的真实 DICOM StudyInstanceUID（urn:dicom:uid），
        // 与门诊/区域 PACS 保持一致；缺失时回退内部 id（fhir-{id}）。
        $realUid = self::dicomStudyUid($im);
        // 缺失时回退：派生合规 DICOM UID（根 1.2.826.0.1.3680043.8.498），不再用 fhir-{id} 之类非标准串
        $studyUid = $realUid !== '' ? $realUid : ('1.2.826.0.1.3680043.8.498.'
            . sprintf('%u', crc32('uid|fhir|' . $uid . '|a')) . '.'
            . sprintf('%u', crc32('uid|fhir|' . $uid . '|b')));
        return array(
            'study_uid'     => $studyUid,
            'patient_id'    => $pid,
            'name'          => $name,
            'gender'        => $gender,
            'age'           => $age,
            'birth_date'    => $birth,
            'outpatient_no' => self::visitNo($im),
            'accession_no'  => $acc,
            'modality'      => $modality !== '' ? $modality : 'OT',
            'description'   => $desc !== '' ? $desc : '影像检查',
            'study_date'    => self::fmtDate($started),
            'institution'   => (self::hospitalName() !== '' ? self::hospitalName() : pvw_hospital_source()),
            'station_name'  => ($modality !== '' ? $modality : 'OT') . '-ROOM',
            'apply_dept'    => '',
            'apply_doctor'  => '',
            'status'        => 'completed',
            'series_count'  => $seriesCount,
            'series'        => self::seriesFromImagingStudy($im),   // 真实序列（UID/描述/张数），供模拟 DICOMweb 对齐
        );
    }

    /** Encounter（门诊就诊记录）→ 检索行（无影像模态，标注 OT，影像由模拟器生成） */
    private static function mapEncounter($patient, $enc) {
        $pid = self::patientNo($patient);
        $birth = isset($patient['birthDate']) ? (string)$patient['birthDate'] : '';
        $id = isset($enc['id']) ? (string)$enc['id'] : 'enc';
        $desc = self::encounterText($enc);
        $started = isset($enc['period']['start']) ? (string)$enc['period']['start'] : '';
        $acc = self::accessionOf($enc);
        if ($acc === '') $acc = 'ENC' . preg_replace('/\D/', '', $id);
        return array(
            'study_uid'     => 'fhir-enc-' . $id,
            'patient_id'    => $pid,
            'name'          => self::patientName($patient),
            'gender'        => self::gender($patient),
            'age'           => PvDicom::ageText($birth),
            'birth_date'    => $birth,
            'outpatient_no' => self::visitNo($enc),
            'accession_no'  => $acc,
            'modality'      => 'OT',
            'description'   => $desc !== '' ? $desc : '门诊就诊 · 影像检查',
            'study_date'    => self::fmtDate($started),
            'institution'   => (self::hospitalName() !== '' ? self::hospitalName() : pvw_hospital_source()),
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
        return self::identifierValue($p, array('MR', 'OP'), array('outpatient', 'mrn'));
    }

    /** 患者号：优先门诊号/病案号（OP/MR）标识，回退去掉内部 id 的 patient- 前缀 */
    private static function patientNo($p) {
        $v = self::outpatientNo($p);
        if ($v !== '') return $v;
        $id = isset($p['id']) ? (string)$p['id'] : '';
        return preg_replace('/^patient-/', '', $id);
    }

    /** 门诊号：就诊流水号（ImagingStudy/Encounter 的 VN 标识，与患者号不同） */
    private static function visitNo($res) {
        return self::identifierValue($res, array('VN'), array('identifier:visit'));
    }

    /**
     * 检查号（AccessionNumber）：申请单号。按标准标识优先级挑选，绝不使用 DICOM UID 或报告号。
     * 依次：ACSN（Accession ID）→ PLAC/FILL（申请单号）→ VN（就诊号，兜底）。
     */
    private static function accessionOf($res) {
        $v = self::identifierValue($res, array('ACSN'), array('identifier:order'));
        if ($v === '') $v = self::identifierValue($res, array('PLAC', 'FILL'), array('identifier:order'));
        if ($v === '') $v = self::identifierValue($res, array('VN'), array('identifier:visit'));
        return $v;
    }

    /** 按标识类型码 / 系统线索挑选标识值 */
    private static function identifierValue($res, array $codes = array(), array $sysHints = array()) {
        foreach ((array)(isset($res['identifier']) ? $res['identifier'] : array()) as $id) {
            $val = isset($id['value']) ? (string)$id['value'] : '';
            if ($val === '') continue;
            $type = isset($id['type']['coding'][0]['code']) ? strtoupper((string)$id['type']['coding'][0]['code']) : '';
            $sys = isset($id['system']) ? (string)$id['system'] : '';
            if ($codes && in_array($type, $codes, true)) return $val;
            foreach ($sysHints as $h) {
                if ($h !== '' && stripos($sys, $h) !== false) return $val;
            }
        }
        return '';
    }

    /** 取 CodeableConcept / Coding 的编码（大写），兼容 coding[].code 与裸 code */
    private static function codeOf($concept) {
        if (!is_array($concept)) return '';
        if (isset($concept['coding'][0]['code'])) return strtoupper((string)$concept['coding'][0]['code']);
        if (isset($concept['code'])) return strtoupper((string)$concept['code']);
        return '';
    }
    private static function fmtDate($s) {
        if ($s === '') return '';
        $t = strtotime($s);
        return $t === false ? $s : date('Y-m-d H:i:s', $t);
    }
}
