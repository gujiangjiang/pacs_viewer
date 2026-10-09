<?php
/**
 * ============================================================
 * app/Pacs/FhirClient.php — 门诊系统 FHIR R4 客户端（只读）
 * ============================================================
 * 内置模拟 PACS 服务器的患者数据来源之一：通过 FHIR R4 从门诊一体化系统
 * （Clinic_OPD_System 等）获取「已缴费、已登记」的患者及其检查信息。
 *
 * 取数口径（约定）：
 *   1) 列表/工作台：`GET {fhir}/ImagingStudy?_count=100&_include=ImagingStudy:patient`
 *      —— 一次请求同时带回检查与其患者资源，避免逐个 Patient/{id} 回读（消除 N+1）。
 *   2) 关键字检索：先按姓名/标识检索 Patient（name / identifier / _id），
 *      再对匹配到的患者查询 ImagingStudy?patient=Patient/{id}&_count=50。
 *      存在 ImagingStudy 即视为「已缴费并已登记」（门诊系统在登记后创建该资源）。
 *   3) 资源同时兼容「Patient / ImagingStudy 以 Bundle 返回」与「直接数组返回」。
 *
 * 认证（可选）：配置密钥后同时发送 Authorization: Bearer 与 X-API-Key 头。
 * 返回结构与内置模拟数据保持一致，便于模拟服务器统一对外。
 * ============================================================ */
class PvFhirClient {

    use PvFhirMapTrait;   // 资源映射 / Bundle 解析（app/Pacs/Fhir/FhirMapTrait.php）

    /** 临时配置覆盖（仅用于「测试当前输入」场景，请求结束后清空） */
    private static $override = null;
    /** 最近一次检索的错误信息（便于界面提示） */
    private static $lastError = '';
    /** 最近一次 HTTP 请求的响应状态码（0 表示未收到响应） */
    private static $lastStatus = 0;
    /** 机构名称缓存（FHIR Organization.name） */
    private static $hospital = null;
    /** 请求级检索结果缓存（同一请求内避免对同一关键词重复取数） */
    private static $searchCache = array();

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

    /**
     * 调阅影像报告（FHIR R4 DiagnosticReport）。
     * @param string $imagingStudyUid 检查标识（可为 fhir-imagingstudy-N、imagingstudy-N 或裸 N）
     * @return array|null 归一化报告结构；未配置 FHIR 或无报告返回 null
     */
    public static function diagnosticReport($imagingStudyUid, $patientNo = '', $modality = '', $studyDate = '', $title = '') {
        self::$lastError = '';
        if (!self::isConfigured()) return null;
        // ① 优先按 ImagingStudy 引用精确取报告
        $ref = self::imagingStudyRef($imagingStudyUid);
        if ($ref !== '') {
            try {
                $b = self::getJson(self::base() . '/DiagnosticReport?' . http_build_query(array('imagingStudy' => $ref, '_count' => 1)));
                $r = self::firstResource($b, 'DiagnosticReport');
                if ($r) return self::mapReport($r);
            } catch (Exception $e) {
                self::note($e);
            }
        }
        // ② 回退：按患者匹配（同一天优先，其次检查项目/模态包含），解决「打开的是真实 UID 而非 fhir-imagingstudy-N」时取不到报告
        $pno = trim((string)$patientNo);
        if ($pno === '') return null;
        if (strpos($pno, 'patient-') === 0) $pno = substr($pno, 8);
        try {
            $b = self::getJson(self::base() . '/DiagnosticReport?' . http_build_query(array('patient' => $pno, '_count' => 50)));
        } catch (Exception $e) {
            self::note($e);
            return null;
        }
        $list = self::bundleResources($b, 'DiagnosticReport');
        if (!$list) return null;
        $wantDate = preg_replace('/\D/', '', (string)$studyDate);
        $wantDate = strlen($wantDate) >= 8 ? substr($wantDate, 0, 8) : '';
        $wantTitle = trim((string)$title);
        $best = null; $bestScore = -1;
        foreach ($list as $r) {
            $score = 0;
            $d = preg_replace('/\D/', '', (string)(isset($r['effectiveDateTime']) ? $r['effectiveDateTime'] : ''));
            $d = strlen($d) >= 8 ? substr($d, 0, 8) : '';
            if ($wantDate !== '' && $d === $wantDate) $score += 2;
            $codeText = isset($r['code']['text']) ? (string)$r['code']['text'] : '';
            if ($wantTitle !== '' && $codeText !== '' && (mb_stripos($codeText, $wantTitle, 0, 'UTF-8') !== false || mb_stripos($wantTitle, $codeText, 0, 'UTF-8') !== false)) $score += 1;
            if ($score > $bestScore) { $bestScore = $score; $best = $r; }
        }
        return $best ? self::mapReport($best) : null;
    }

    /** Bundle 中首个指定类型资源 */
    private static function firstResource($b, $type) {
        $list = self::bundleResources($b, $type);
        return $list ? $list[0] : null;
    }

    /** Bundle（或直接资源）→ 资源数组 */
    private static function bundleResources($b, $type) {
        $out = array();
        if (!is_array($b)) return $out;
        if (isset($b['entry']) && is_array($b['entry'])) {
            foreach ($b['entry'] as $e) {
                if (!empty($e['resource']) && is_array($e['resource'])) {
                    $rt = isset($e['resource']['resourceType']) ? $e['resource']['resourceType'] : '';
                    if ($rt === '' || $rt === $type) $out[] = $e['resource'];
                }
            }
        } elseif (isset($b['resourceType']) && $b['resourceType'] === $type) {
            $out[] = $b;
        }
        return $out;
    }

    /** 检查标识 → ImagingStudy 引用（ImagingStudy/imagingstudy-N） */
    private static function imagingStudyRef($uid) {
        $uid = trim((string)$uid);
        if ($uid === '') return '';
        if (strpos($uid, 'ImagingStudy/') === 0) return $uid;
        if (strpos($uid, 'fhir-') === 0) $uid = substr($uid, 5);
        if (strpos($uid, 'imagingstudy-') !== 0) {
            if (ctype_digit($uid)) $uid = 'imagingstudy-' . $uid;
            else return '';
        }
        return 'ImagingStudy/' . $uid;
    }

    /** DiagnosticReport → 前端归一化结构 */
    private static function mapReport($r) {
        $status = isset($r['status']) ? (string)$r['status'] : '';
        $statusText = array(
            'final' => '已出具', 'amended' => '已修订', 'corrected' => '已更正',
            'preliminary' => '初步报告', 'registered' => '已登记 · 待出报告', 'cancelled' => '已作废',
        );
        $reportNo = ''; $orderNo = '';
        foreach ((array)(isset($r['identifier']) ? $r['identifier'] : array()) as $id) {
            $sys = isset($id['system']) ? (string)$id['system'] : '';
            $val = isset($id['value']) ? (string)$id['value'] : '';
            if ($val === '') continue;
            if (stripos($sys, 'identifier:report') !== false) $reportNo = $val;
            elseif (stripos($sys, 'identifier:order') !== false) $orderNo = $val;
        }
        $doctor = '';
        if (!empty($r['performer'][0]['display'])) $doctor = (string)$r['performer'][0]['display'];
        elseif (!empty($r['resultsInterpreter'][0]['display'])) $doctor = (string)$r['resultsInterpreter'][0]['display'];
        $item = !empty($r['code']['text']) ? (string)$r['code']['text'] : '';
        $issued = isset($r['issued']) ? (string)$r['issued'] : '';
        $pdf = !empty($r['presentedForm'][0]['url']) ? self::safeHttpUrl((string)$r['presentedForm'][0]['url']) : '';
        $findings = trim((string)self::extString($r, 'urn:clinic:extension:imaging-findings'));
        $conclusion = trim((string)(isset($r['conclusion']) ? $r['conclusion'] : ''));
        $clin = trim((string)self::extString($r, 'urn:clinic:extension:clinical-diagnosis'));
        return array(
            'available'          => true,
            'status'             => $status,
            'status_text'        => isset($statusText[$status]) ? $statusText[$status] : $status,
            'report_no'          => $reportNo,
            'order_no'           => $orderNo,
            'item_name'          => $item,
            'findings'           => $findings,
            'conclusion'         => $conclusion,
            'clinical_diagnosis' => $clin,
            'apply_doctor'       => trim((string)self::extString($r, 'urn:clinic:extension:ordering-physician')),
            'apply_dept'         => trim((string)self::extString($r, 'urn:clinic:extension:ordering-department')),
            'report_doctor'      => $doctor,
            'issued'             => $issued !== '' ? self::fmtDate($issued) : '',
            'pdf_url'            => $pdf,
            'has_body'           => ($findings !== '' || $conclusion !== '' || $clin !== ''),
        );
    }

    /** 读取扩展 valueString */
    private static function extString($res, $url) {
        foreach ((array)(isset($res['extension']) ? $res['extension'] : array()) as $e) {
            if (isset($e['url']) && $e['url'] === $url && isset($e['valueString'])) {
                return (string)$e['valueString'];
            }
        }
        return '';
    }

    /** 仅放行 http(s) 绝对地址；其余（含 javascript:、data: 等）返回空串，防 XSS */
    private static function safeHttpUrl($u) {
        $u = trim((string)$u);
        return preg_match('#^https?://#i', $u) ? $u : '';
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

    /* ---------------- 摄片工作列表 / 工作项回写（标准 FHIR 工作流） ---------------- */

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
