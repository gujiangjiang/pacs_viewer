<?php
/**
 * ============================================================
 * app/Pacs/Fhir/FhirWorklistTrait.php — FHIR 摄片工作列表
 * ============================================================
 * 由 PvFhirClient 使用：读取 Task 工作列表并映射为内部行结构、
 * 由检查项目名推断模态码。
 * 依赖类内静态属性 $lastError。
 * ============================================================ */
trait PvFhirWorklistTrait {

    /**
     * 读取「摄片登记」工作列表：Task?status=requested,accepted,in-progress（含患者）。
     * 返回行结构供模拟服务器「摄片登记」页展示与摄片使用。
     */
    public static function worklist() {
        self::$lastError = '';
        if (!self::isConfigured()) throw new RuntimeException('未配置门诊系统 FHIR 接口地址');
        $b = self::getJson(self::base() . '/Task?' . http_build_query(array(
            'status' => 'requested,accepted,in-progress,completed',
            '_count' => 300,
            '_include' => 'Task:patient',
        )));
        $tasks = array(); $patients = array();
        foreach (self::entries($b) as $r) {
            if (self::isType($r, 'Task')) $tasks[] = $r;
            elseif (self::isType($r, 'Patient')) {
                $pid = isset($r['id']) ? (string)$r['id'] : '';
                if ($pid !== '') $patients[$pid] = $r;
            }
        }
        $rows = array();
        foreach ($tasks as $t) {
            $ref = self::refId(isset($t['for']['reference']) ? (string)$t['for']['reference'] : '');
            $p = array('id' => $ref, 'resourceType' => 'Patient');
            if ($ref !== '') {
                if (isset($patients['patient-' . $ref])) $p = $patients['patient-' . $ref];
                elseif (isset($patients[$ref])) $p = $patients[$ref];
            }
            $itemName = isset($t['description']) ? (string)$t['description'] : '';
            $birth = isset($p['birthDate']) ? (string)$p['birthDate'] : '';
            $rows[] = array(
                'task_id'         => isset($t['id']) ? (string)$t['id'] : '',
                'status'          => isset($t['status']) ? (string)$t['status'] : 'requested',
                'business_status' => isset($t['businessStatus']['text']) ? (string)$t['businessStatus']['text'] : '',
                'accession_no'    => self::accessionOf($t),
                'item_name'       => $itemName,
                'modality'        => self::inferModality($itemName),
                'patient_id'      => self::patientNo($p),
                'name'            => self::patientName($p),
                'gender'          => self::gender($p),
                'age'             => PvDicom::ageText($birth),
                'birth_date'      => $birth,
                'outpatient_no'   => self::visitNo($t),
                'registered_at'   => isset($t['executionPeriod']['start']) ? self::fmtDate($t['executionPeriod']['start']) : '',
                'authored_at'     => isset($t['authoredOn']) ? self::fmtDate($t['authoredOn']) : '',
            );
        }
        return $rows;
    }

    /** 由检查项目名推断模态码（CT/MR/DR/US/OT），供模拟器选择生成器 */
    private static function inferModality($text) {
        $s = strtoupper(trim((string)$text));
        if ($s === '') return 'OT';
        if (preg_match('/\bMR|核磁|磁共振|MRI/i', $text)) return 'MR';
        if (preg_match('/\bCT|断层/i', $text)) return 'CT';
        if (preg_match('/DR|X线|X光|摄片|胸片|平片/i', $text)) return 'DR';
        if (preg_match('/US|超[声生]|彩超|B超/i', $text)) return 'US';
        return 'OT';
    }
}
