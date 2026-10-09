<?php
/**
 * ============================================================
 * app/Pacs/Fhir/FhirSearchTrait.php — FHIR 检索与调阅
 * ============================================================
 * 由 PvFhirClient 使用：患者 / 检查检索（关键字、列表、回退路径）、
 * 单次检查调阅与患者资源解析。
 * 依赖类内静态属性 $lastError / $lastStatus / $searchCache。
 * ============================================================ */
trait PvFhirSearchTrait {

    /**
     * 检索患者检查（规范化为 PACS 检索行结构）。
     * 兼容不同 FHIR 服务的实现差异：
     *   · 患者：优先标准搜索 Patient?name= / identifier=；否则按患者号读取 Patient/{id}；
     *     再回退到列出 Encounter 推导患者。
     *   · 检查：优先 ImagingStudy?patient=；否则用 Encounter?patient=（门诊就诊记录）。
     */
    public static function search($keyword = '') {
        $ck = (string)$keyword;
        if (array_key_exists($ck, self::$searchCache)) return self::$searchCache[$ck];
        return self::$searchCache[$ck] = self::searchUncached($keyword);
    }

    /** 实际检索实现（带请求级记忆化，见 search） */
    private static function searchUncached($keyword = '') {
        self::$lastError = '';
        if (!self::isConfigured()) {
            throw new RuntimeException('未配置门诊系统 FHIR 接口地址');
        }
        $kw = trim((string)$keyword);
        $rows = array();

        if ($kw !== '') {
            foreach (self::queryPatients($kw) as $p) {
                $pid = isset($p['id']) ? (string)$p['id'] : '';
                if ($pid === '') continue;
                foreach (self::queryStudies('Patient/' . $pid) as $res) {
                    $rows[] = self::mapStudy($p, $res);
                }
            }
            return $rows;
        }

        /* 无关键字：以 ImagingStudy 为主列出「已登记影像」的检查，
         * 避免把仅有就诊记录（未开影像/未缴费）的患者也列出来。 */
        try {
            $ls = self::listStudies(100);
            foreach ($ls['studies'] as $im) {
                $rows[] = self::mapStudy(self::patientForStudy($im, $ls['patients']), $im);
            }
        } catch (Exception $e) {
            self::note($e);
        }
        if ($rows) return $rows;

        /* 回退：服务不提供 ImagingStudy 时，按患者列表 + Encounter 推导 */
        return self::rowsFromEncounters();
    }

    /**
     * 列出检查资源（ImagingStudy?_count），并随附 `_include=ImagingStudy:patient`
     * 在同一次请求中带回患者资源，供上层直接取用——避免逐个 Patient/{id} 回读的 N+1。
     * @return array { studies: ImagingStudy[], patients: array<PatientId, Patient> }
     */
    private static function listStudies($count = 50) {
        $b = self::getJson(self::base() . '/ImagingStudy?' . http_build_query(array(
            '_count'   => (int)$count,
            '_include' => 'ImagingStudy:patient',
        )));
        $out = array('studies' => array(), 'patients' => array());
        foreach (self::entries($b) as $r) {
            if (self::isType($r, 'ImagingStudy')) {
                $out['studies'][] = $r;
            } elseif (self::isType($r, 'Patient')) {
                $pid = isset($r['id']) ? (string)$r['id'] : '';
                if ($pid !== '') $out['patients'][$pid] = $r;
            }
        }
        return $out;
    }

    /**
     * 由 ImagingStudy.subject 取患者资源：
     * 优先使用 `_include` 随附的患者（$patients 映射），缺失时才回退单资源回读。
     * 失败退回仅 id 的占位（不再逐条触发 Patient/{id} 请求）。
     */
    private static function patientForStudy($im, $patients = null) {
        $ref = self::patientRef($im);
        if ($ref === '') return array('id' => '', 'resourceType' => 'Patient');
        if (is_array($patients) && isset($patients[$ref]) && self::isType($patients[$ref], 'Patient')) {
            return $patients[$ref];
        }
        try {
            $p = self::getJson(self::base() . '/Patient/' . rawurlencode($ref));
            if (self::isType($p, 'Patient')) return $p;
        } catch (Exception $e) { self::note($e); }
        return array('id' => $ref, 'resourceType' => 'Patient');
    }

    /** 回退路径：无 ImagingStudy 支持时，按 Encounter（就诊）推导检查行 */
    private static function rowsFromEncounters() {
        $rows = array();
        $patients = array();
        try {
            $b = self::getJson(self::base() . '/Patient?' . http_build_query(array('_count' => 20)));
            foreach (self::entries($b) as $p) { if (self::isType($p, 'Patient')) $patients[] = $p; }
        } catch (Exception $e) { self::note($e); }
        if (!$patients) {
            try {
                $b = self::getJson(self::base() . '/Encounter?' . http_build_query(array('_count' => 50)));
                $seen = array();
                foreach (self::entries($b) as $enc) {
                    if (!self::isType($enc, 'Encounter')) continue;
                    $id = self::refId(self::subjectRef($enc));
                    if ($id === '' || isset($seen[$id])) continue;
                    $seen[$id] = 1;
                    $patients[] = array('id' => $id, 'resourceType' => 'Patient');
                }
            } catch (Exception $e) { self::note($e); }
        }
        foreach ($patients as $p) {
            $pid = isset($p['id']) ? (string)$p['id'] : '';
            if ($pid === '') continue;
            try {
                $eb = self::getJson(self::base() . '/Encounter?' . http_build_query(array('patient' => 'Patient/' . $pid, '_count' => 50)));
                foreach (self::entries($eb) as $enc) {
                    if (self::isType($enc, 'Encounter')) $rows[] = self::mapEncounter($p, $enc);
                }
            } catch (Exception $e) { self::note($e); }
        }
        return $rows;
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

    /**
     * 取某患者的检查资源：以 ImagingStudy 为准。
     * 仅当服务不支持 ImagingStudy（非鉴权类的 4xx/5xx）时才回退 Encounter（门诊就诊）；
     * ImagingStudy 返回空，表示该患者没有已登记影像，应排除，而不是当成就诊检查列出。
     */
    private static function queryStudies($patientRef) {
        $base = self::base();
        try {
            $b = self::getJson($base . '/ImagingStudy?' . http_build_query(array('patient' => $patientRef, '_count' => 50)));
        } catch (Exception $e) {
            if (self::$lastStatus === 401 || self::$lastStatus === 403) throw $e;   // 鉴权/权限问题不掩盖
            self::note($e);
            try {
                $b = self::getJson($base . '/Encounter?' . http_build_query(array('patient' => $patientRef, '_count' => 50)));
            } catch (Exception $e2) { self::note($e2); return array(); }
            $out = array();
            foreach (self::entries($b) as $r) { if (self::isType($r, 'Encounter')) $out[] = $r; }
            return $out;
        }
        $out = array();
        foreach (self::entries($b) as $r) { if (self::isType($r, 'ImagingStudy')) $out[] = $r; }
        return $out;
    }
}
