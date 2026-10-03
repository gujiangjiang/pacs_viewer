<?php
/**
 * ============================================================
 * app/Controllers/DicomWebController.php — 内置模拟 DICOMweb 端点
 * ============================================================
 * 供「接口协议 = dicomweb」时本浏览器自测；以标准语义暴露 QIDO-RS / WADO-RS：
 *   GET ?r=dicomweb/studies                            QIDO 检索
 *   GET ?r=dicomweb/studies/{uid}                      检查元数据
 *   GET ?r=dicomweb/studies/{uid}/series               序列列表
 *   GET ?r=dicomweb/studies/{uid}/series/{se}/instances 实例列表（含 NumberOfFrames）
 *   GET ?r=dicomweb/studies/{uid}/series/{se}/instances/{i}  DICOM 字节流
 * 认证：登录或对外密钥（key）二选一。
 * ============================================================ */
class PvDicomWebController {

    /**
     * @param string|null $path DICOMweb 子路径（如 /studies/...）；为空时从 ?r=dicomweb... 解析
     */
    public static function handle($path = null) {
        if (!PvMockServer::enabled()) { self::jsonError(403, '内置模拟 PACS 服务器未启用'); }
        if (!PvAuth::check() && !PvMockServer::checkKey(self::requestKey())) {
            self::jsonError(403, '模拟服务器密钥校验失败');
        }
        if ($path === null) {
            $r = isset($_GET['r']) ? (string)$_GET['r'] : '';
            $path = substr($r, strlen('dicomweb'));
        }
        $path = trim((string)$path, '/');
        // 根地址（/dicom-web 或 /dicom-web/）视为 QIDO 检查检索，便于外部连通性测试
        if ($path === '') { self::qidoStudies(); return; }
        $seg = explode('/', $path);
        if (!isset($seg[0]) || $seg[0] !== 'studies') { self::jsonError(404, '不支持的 DICOMweb 路径'); }

        if (count($seg) === 1) { self::qidoStudies(); return; }
        $studyUid = $seg[1];
        if (count($seg) === 2) { self::studyMeta($studyUid); return; }
        if (count($seg) === 3 && $seg[2] === 'series') { self::series($studyUid); return; }
        if (count($seg) === 5 && $seg[2] === 'series' && $seg[4] === 'instances') { self::instances($studyUid, $seg[3]); return; }
        if (count($seg) === 7 && $seg[2] === 'series' && $seg[4] === 'instances' && $seg[6] === 'rendered') { self::rendered($studyUid, $seg[3]); return; }
        if (count($seg) === 6 && $seg[2] === 'series' && $seg[4] === 'instances') { self::instance($studyUid, $seg[3], $seg[5]); return; }
        self::jsonError(404, '不支持的 DICOMweb 路径');
    }

    /* ---------------- QIDO-RS ---------------- */

    private static function qidoStudies() {
        $name = trim((string)pvw_input('PatientName'));
        $pid = trim((string)pvw_input('PatientID'));
        $acc = trim((string)pvw_input('AccessionNumber'));
        $suid = trim((string)pvw_input('StudyInstanceUID'));
        $sex = strtoupper(trim((string)pvw_input('PatientSex')));          // M / F / O
        $sdate = trim((string)pvw_input('StudyDate'));                     // YYYYMMDD 或 YYYYMMDD-YYYYMMDD
        $mods = trim((string)pvw_input('ModalitiesInStudy'));              // 逗号分隔模态码
        $limit = max(0, (int)pvw_input('limit', 0));
        $offset = max(0, (int)pvw_input('offset', 0));

        $rows = PvMockServer::rows('');
        // 排序：默认按检查时间倒序；支持 orderby/order 自定义排序（供列表表头点击）
        $orderby = trim((string)pvw_input('orderby'));
        $order = strtolower(trim((string)pvw_input('order')));
        $allowed = array('name', 'gender', 'birth_date', 'patient_id', 'outpatient_no', 'accession_no', 'study_date', 'modality', 'description', 'station_name');
        if ($orderby === '' || !in_array($orderby, $allowed, true)) $orderby = 'study_date';
        $desc = ($order !== 'asc');   // 默认降序
        usort($rows, function ($a, $b) use ($orderby, $desc) {
            $va = isset($a[$orderby]) ? (string)$a[$orderby] : '';
            $vb = isset($b[$orderby]) ? (string)$b[$orderby] : '';
            if ($va === $vb) return 0;
            $cmp = ($va < $vb) ? -1 : 1;
            return $desc ? -$cmp : $cmp;
        });
        $modList = array();
        if ($mods !== '') {
            foreach (explode(',', $mods) as $m) { $m = strtoupper(trim($m)); if ($m !== '') $modList[] = $m; }
        }
        $out = array();
        foreach ($rows as $row) {
            if ($suid !== '' && $row['study_uid'] !== $suid) continue;
            if ($pid !== '' && stripos($row['patient_id'], $pid) === false) continue;
            if ($acc !== '' && stripos((string)(isset($row['accession_no']) ? $row['accession_no'] : ''), $acc) === false) continue;
            if ($name !== '' && mb_stripos($row['name'], $name, 0, 'UTF-8') === false) continue;
            if ($sex !== '' && self::sexCode(isset($row['gender']) ? $row['gender'] : '') !== $sex) continue;
            if ($modList && !in_array(strtoupper((string)(isset($row['modality']) ? $row['modality'] : '')), $modList, true)) continue;
            if ($sdate !== '' && !self::dateMatch(isset($row['study_date']) ? $row['study_date'] : '', $sdate)) continue;
            $out[] = self::studyResource($row);
        }
        if ($limit > 0) $out = array_slice($out, $offset, $limit);
        self::json($out);
    }

    /** 性别归一化为 DICOM PatientSex（M/F/O） */
    private static function sexCode($g) {
        $g = strtoupper(trim((string)$g));
        if ($g === '男' || $g === 'M' || $g === 'MALE' || $g === '1') return 'M';
        if ($g === '女' || $g === 'F' || $g === 'FEMALE' || $g === '2') return 'F';
        return 'O';
    }

    /** StudyDate 匹配：支持单个 YYYYMMDD 或范围 YYYYMMDD-YYYYMMDD */
    private static function dateMatch($studyDate, $range) {
        $d = preg_replace('/\D/', '', substr((string)$studyDate, 0, 10));
        if (strlen($d) < 8) return false;
        $d = substr($d, 0, 8);
        $range = preg_replace('/[^0-9\-]/', '', $range);
        if (strpos($range, '-') !== false) {
            list($a, $b) = array_pad(explode('-', $range, 2), 2, '');
            $a = substr(preg_replace('/\D/', '', $a), 0, 8);
            $b = substr(preg_replace('/\D/', '', $b), 0, 8);
            if ($a !== '' && $d < $a) return false;
            if ($b !== '' && $d > $b) return false;
            return true;
        }
        $one = substr(preg_replace('/\D/', '', $range), 0, 8);
        return $one === '' ? true : ($d === $one);
    }

    private static function studyMeta($uid) {
        $row = PvMockServer::findRow($uid);
        if (!$row) self::jsonError(404, '未找到该检查');
        self::json(array(self::studyResource($row)));
    }

    /* ---------------- WADO-RS ---------------- */

    /** 真实序列定义（来自 FHIR ImagingStudy.series），无则返回 null */
    private static function realSeries($row) {
        return (isset($row['series']) && is_array($row['series']) && $row['series']) ? $row['series'] : null;
    }

    /** 在真实序列中匹配指定序列 UID（先精确、再按末段数字） */
    private static function matchRealSeries($row, $seUid) {
        $real = self::realSeries($row);
        if (!$real) return null;
        foreach ($real as $s) {
            if ((string)(isset($s['series_id']) ? $s['series_id'] : '') === (string)$seUid) return $s;
        }
        $want = PvMockDicomTagBuilder::uidTailInt($seUid, 0);
        if ($want > 0) {
            foreach ($real as $s) {
                if (PvMockDicomTagBuilder::uidTailInt(isset($s['series_id']) ? $s['series_id'] : '', 0) === $want) return $s;
            }
        }
        return null;
    }

    private static function series($studyUid) {
        $row = PvMockServer::findRow($studyUid);
        if (!$row) self::jsonError(404, '未找到该检查');
        $out = array();
        $real = self::realSeries($row);
        if ($real) {
            foreach ($real as $s) {
                $seUid = isset($s['series_id']) ? (string)$s['series_id'] : '';
                if ($seUid === '') continue;
                $mod = isset($s['modality']) && $s['modality'] !== '' ? (string)$s['modality'] : (string)$row['modality'];
                $out[] = array(
                    '0020000E' => array('vr' => 'UI', 'Value' => array($seUid)),
                    '00080060' => array('vr' => 'CS', 'Value' => array(strtoupper($mod))),
                    '0008103E' => array('vr' => 'LO', 'Value' => array((string)(isset($s['description']) ? $s['description'] : ''))),
                    '00201209' => array('vr' => 'IS', 'Value' => array((string)max(0, (int)(isset($s['slice_count']) ? $s['slice_count'] : 0)))),
                );
            }
            self::json($out);
        }
        // 回退：内置序列规划
        $plan = PvMockDispatcher::seriesPlan(strtoupper($row['modality']), $row['description'], $row['study_uid']);
        foreach ($plan as $i => $s) {
            $seUid = PvMockDicomTagBuilder::deriveUid($row['study_uid'], array($i + 1));
            $out[] = array(
                '0020000E' => array('vr' => 'UI', 'Value' => array($seUid)),
                '00080060' => array('vr' => 'CS', 'Value' => array(strtoupper($row['modality']))),
                '0008103E' => array('vr' => 'LO', 'Value' => array((string)$s['description'])),
                '00201209' => array('vr' => 'IS', 'Value' => array((string)(isset($s['instances']) ? count($s['instances']) : 1))),
            );
        }
        self::json($out);
    }

    private static function instances($studyUid, $seUid) {
        $row = PvMockServer::findRow($studyUid);
        if (!$row) self::jsonError(404, '未找到该检查');
        // 可选分页：供区域客户端仅取首个实例元数据（如按需获取像素参数）
        $limit = max(0, (int)pvw_input('limit', 0));
        $offset = max(0, (int)pvw_input('offset', 0));
        // 优先：真实序列（与区域 PACS / FHIR 一致）
        $real = self::matchRealSeries($row, $seUid);
        if ($real) {
            $seUidReal = (string)(isset($real['series_id']) ? $real['series_id'] : $seUid);
            $seriesNo = max(1, PvMockDicomTagBuilder::uidTailInt($seUidReal, 1));
            $mod = isset($real['modality']) && $real['modality'] !== '' ? (string)$real['modality'] : (string)$row['modality'];
            $count = max(0, (int)(isset($real['slice_count']) ? $real['slice_count'] : 0));
            $gen = PvMockDispatcher::generatorForSeriesIndex(strtoupper($row['modality']), $row['description'], $row['study_uid'], $seriesNo - 1);
            $dim = $gen->getDimensions();
            $ps = $gen->getPixelSpacing();
            $win = $gen->getRecommendedWindow();
            $tags = $gen->getModalitySpecificTags();
            $out = array();
            for ($i = 1; $i <= $count; $i++) {
                $out[] = array(
                    '00080018' => array('vr' => 'UI', 'Value' => array($seUidReal . '.' . $i)),
                    '0020000E' => array('vr' => 'UI', 'Value' => array($seUidReal)),
                    '00200013' => array('vr' => 'IS', 'Value' => array((string)$i)),
                    '00280008' => array('vr' => 'IS', 'Value' => array('1')),
                    '00080060' => array('vr' => 'CS', 'Value' => array(strtoupper($mod))),
                    '00280010' => array('vr' => 'US', 'Value' => array((int)$dim['rows'])),
                    '00280011' => array('vr' => 'US', 'Value' => array((int)$dim['columns'])),
                    '00280100' => array('vr' => 'US', 'Value' => array((int)$dim['bits_allocated'])),
                    '00280101' => array('vr' => 'US', 'Value' => array((int)$dim['bits_stored'])),
                    '00280103' => array('vr' => 'US', 'Value' => array((int)$dim['pixel_representation'])),
                    '00281050' => array('vr' => 'DS', 'Value' => array((string)$win['center'])),
                    '00281051' => array('vr' => 'DS', 'Value' => array((string)$win['width'])),
                    '00281052' => array('vr' => 'DS', 'Value' => array((string)(isset($tags['rescale_intercept']) ? $tags['rescale_intercept'] : 0))),
                    '00281053' => array('vr' => 'DS', 'Value' => array((string)(isset($tags['rescale_slope']) ? $tags['rescale_slope'] : 1))),
                    '00280030' => array('vr' => 'DS', 'Value' => array((string)$ps[0])),
                    '00180050' => array('vr' => 'DS', 'Value' => array((string)$gen->getSliceThickness())),
                    '00200037' => array('vr' => 'DS', 'Value' => array(self::iopFor($gen->getOrientation()))),
                );
            }
            self::json(self::sliceOut($out, $limit, $offset));
        }
        // 回退：内置序列规划
        $idx = self::seriesIndexByUid($row, $seUid);
        if ($idx < 0) self::jsonError(404, '未找到该序列');
        $plan = PvMockDispatcher::seriesPlan(strtoupper($row['modality']), $row['description'], $row['study_uid']);
        $s = $plan[$idx];
        $counts = (isset($s['instances']) && is_array($s['instances']) && $s['instances']) ? $s['instances'] : array(max(1, (int)$s['slice_count']));
        $totalInst = count($counts);
        $seriesUid = PvMockDicomTagBuilder::deriveUid($row['study_uid'], array($idx + 1));
        $out = array();
        for ($i = 1; $i <= $totalInst; $i++) {
            $nf = max(1, (int)$counts[$i - 1]);
            $iuid = PvMockDicomTagBuilder::deriveUid($seriesUid, array($i));
            $out[] = array(
                '00080018' => array('vr' => 'UI', 'Value' => array($iuid)),
                '0020000E' => array('vr' => 'UI', 'Value' => array($seriesUid)),
                '00200013' => array('vr' => 'IS', 'Value' => array((string)$i)),
                '00280008' => array('vr' => 'IS', 'Value' => array((string)$nf)),
                '00080060' => array('vr' => 'CS', 'Value' => array(strtoupper($row['modality']))),
                '00280010' => array('vr' => 'US', 'Value' => array((int)$s['rows'])),
                '00280011' => array('vr' => 'US', 'Value' => array((int)$s['columns'])),
                '00280100' => array('vr' => 'US', 'Value' => array((int)$s['bits_allocated'])),
                '00280101' => array('vr' => 'US', 'Value' => array((int)$s['bits_stored'])),
                '00280103' => array('vr' => 'US', 'Value' => array((int)$s['pixel_representation'])),
                '00281050' => array('vr' => 'DS', 'Value' => array((string)$s['window_center'])),
                '00281051' => array('vr' => 'DS', 'Value' => array((string)$s['window_width'])),
                '00281052' => array('vr' => 'DS', 'Value' => array((string)$s['rescale_intercept'])),
                '00281053' => array('vr' => 'DS', 'Value' => array((string)$s['rescale_slope'])),
                '00280030' => array('vr' => 'DS', 'Value' => array((string)$s['pixel_spacing'])),
                '00180050' => array('vr' => 'DS', 'Value' => array((string)$s['slice_thickness'])),
                '00200037' => array('vr' => 'DS', 'Value' => array(self::iopFor($s['orientation']))),
            );
        }
        self::json(self::sliceOut($out, $limit, $offset));
    }

    private static function instance($studyUid, $seUid, $iuid) {
        $row = PvMockServer::findRow($studyUid);
        if (!$row) self::jsonError(404, '未找到该检查');
        try {
            $r = PvMockServer::wadoByUids($studyUid, $seUid, $iuid);
        } catch (Exception $e) {
            self::jsonError(404, $e->getMessage());
        }
        PvHttp::sendBinary($r['binary'], 'application/dicom', 86400);
    }

    /** WADO-RS 渲染图（rendered）：返回小尺寸 PNG，供缩略图使用 */
    private static function rendered($studyUid, $seUid) {
        $row = PvMockServer::findRow($studyUid);
        if (!$row) self::jsonError(404, '未找到该检查');
        try {
            $r = PvMockServer::thumbnailByUids($studyUid, $seUid);
        } catch (Exception $e) {
            self::jsonError(404, $e->getMessage());
        }
        PvHttp::sendBinary($r['binary'], $r['content_type'], 86400);
    }

    /* ---------------- 工具 ---------------- */

    /** 从查询参数或请求头（X-API-Key / Authorization: Bearer）取访问密钥 */
    private static function requestKey() {
        $key = (string)pvw_input('key');
        if ($key !== '') return $key;
        if (isset($_SERVER['HTTP_X_API_KEY'])) return (string)$_SERVER['HTTP_X_API_KEY'];
        if (isset($_SERVER['HTTP_AUTHORIZATION']) && stripos($_SERVER['HTTP_AUTHORIZATION'], 'Bearer ') === 0) {
            return substr($_SERVER['HTTP_AUTHORIZATION'], 7);
        }
        return '';
    }

    private static function seriesIndexByUid($row, $seUid) {
        $plan = PvMockDispatcher::seriesPlan(strtoupper($row['modality']), $row['description'], $row['study_uid']);
        foreach ($plan as $i => $s) {
            if (PvMockDicomTagBuilder::deriveUid($row['study_uid'], array($i + 1)) === $seUid) return $i;
        }
        return -1;
    }

    private static function instanceByUid($seriesUid, $iuid) {
        for ($i = 1; $i <= 9999; $i++) {
            if (PvMockDicomTagBuilder::deriveUid($seriesUid, array($i)) === $iuid) return $i;
        }
        return 1;
    }

    private static function studyResource($row) {
        return array(
            '0020000D' => array('vr' => 'UI', 'Value' => array((string)$row['study_uid'])),
            '00100010' => array('vr' => 'PN', 'Value' => array(array('Alphabetic' => (string)$row['name']))),
            '00100020' => array('vr' => 'LO', 'Value' => array((string)$row['patient_id'])),
            // 门诊号：DICOM 无专用标签，用 OtherPatientIDs 承载（本模拟服务约定）
            '00101000' => array('vr' => 'LO', 'Value' => array(isset($row['outpatient_no']) ? (string)$row['outpatient_no'] : '')),
            '00100030' => array('vr' => 'DA', 'Value' => array(PvDicom::da($row['birth_date']))),
            '00100040' => array('vr' => 'CS', 'Value' => array(PvDicom::sex($row['gender']))),
            '00080050' => array('vr' => 'SH', 'Value' => array((string)$row['accession_no'])),
            '00080020' => array('vr' => 'DA', 'Value' => array(PvDicom::da($row['study_date']))),
            '00080030' => array('vr' => 'TM', 'Value' => array(PvDicom::tm($row['study_date'], 6))),
            '00080060' => array('vr' => 'CS', 'Value' => array(strtoupper($row['modality']))),
            '00080061' => array('vr' => 'CS', 'Value' => array(strtoupper($row['modality']))),
            '00081030' => array('vr' => 'LO', 'Value' => array((string)$row['description'])),
            '00080080' => array('vr' => 'LO', 'Value' => array(isset($row['institution']) ? (string)$row['institution'] : '')),
            '00081010' => array('vr' => 'SH', 'Value' => array(isset($row['station_name']) ? (string)$row['station_name'] : '')),
            '00201209' => array('vr' => 'IS', 'Value' => array((string)(isset($row['series_count']) ? $row['series_count'] : 1))),
        );
    }

    /** 方位名 → ImageOrientationPatient（DS，反斜杠分隔） */
    private static function iopFor($orientation) {
        switch (strtoupper((string)$orientation)) {
            case 'AXIAL':    return '1\\0\\0\\0\\1\\0';
            case 'SAGITTAL': return '0\\1\\0\\0\\0\\-1';
            case 'CORONAL':  return '1\\0\\0\\0\\0\\-1';
            default:         return '';
        }
    }

    /** 实例列表分页切片（limit=0 表示不限） */
    private static function sliceOut($out, $limit, $offset) {
        if ($limit > 0) return array_slice($out, $offset, $limit);
        if ($offset > 0) return array_slice($out, $offset);
        return $out;
    }

    private static function json($data) {
        if (!headers_sent()) header('Content-Type: application/dicom+json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
    private static function jsonError($code, $msg) {
        if (!headers_sent()) { http_response_code((int)$code); header('Content-Type: application/json; charset=utf-8'); }
        echo json_encode(array('resourceType' => 'OperationOutcome', 'issue' => array(array('severity' => 'error', 'diagnostics' => $msg))), JSON_UNESCAPED_UNICODE);
        exit;
    }
}
