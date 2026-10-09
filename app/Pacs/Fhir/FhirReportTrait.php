<?php
/**
 * ============================================================
 * app/Pacs/Fhir/FhirReportTrait.php — FHIR 影像报告（DiagnosticReport）
 * ============================================================
 * 由 PvFhirClient 使用：报告取回（按 ImagingStudy 引用优先、按患者回退匹配）、
 * Bundle 资源提取与报告归一化映射。
 * 依赖类内静态属性 $lastError。
 * ============================================================ */
trait PvFhirReportTrait {

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
}
