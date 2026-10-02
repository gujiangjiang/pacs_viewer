<?php
/**
 * ============================================================
 * app/Pacs/MockServer.php — 内置模拟 PACS 服务器
 * ============================================================
 * 本 PACS 浏览器自身不含数据。为便于联调，内置一个「模拟 PACS 服务器」，
 * 对外提供**标准接口**，可配置到本浏览器或供任何标准客户端联调：
 *   · DICOMweb（QIDO-RS 检索 / WADO-RS 取像），见 DicomWebController；
 *   · 标准 DICOM 文件（WADO-URI，?r=dicom，多模态原生多帧）。
 *   · 患者数据使用确定性仿真数据（开箱即用）。
 * 认证：登录或对外密钥（key / X-API-Key / Bearer）二选一。
 * ============================================================ */
class PvMockServer {

    public static function enabled() { return (string)PvSettings::get('mock_enabled', '1') === '1'; }
    public static function apiKey()  { return trim((string)PvSettings::get('mock_api_key', '')); }

    /** 内置模拟数据的机构名称（对外 DICOMweb InstitutionName）：未配置回退「默认医院」 */
    public static function builtinInstitution() {
        $n = trim((string)PvSettings::get('mock_hospital_name', ''));
        return $n !== '' ? $n : '默认医院';
    }

    /** 患者数据来源：builtin 内置仿真数据 / fhir 门诊 FHIR R4 获取 */
    public static function source() {
        return (string)PvSettings::get('mock_patient_source', 'builtin') === 'fhir' ? 'fhir' : 'builtin';
    }

    /** 最近一次 FHIR 取数错误（仅当来源为 fhir 时有意义） */
    public static function fhirError() {
        return self::source() === 'fhir' ? PvFhirClient::lastError() : '';
    }

    /** 标准 DICOMweb 根地址（QIDO-RS / WADO-RS，内置模拟服务器对外提供） */
    public static function dicomWebEndpoint() {
        $base = (PV_URL_SITE === '' ? '' : PV_URL_SITE) . '/dicom-web';
        return pvw_abs_path($base);
    }

    /**
     * Web 阅片器 URL 模板（供外部系统直接打开当前检查）：
     * {study_uid} 为检查 UID 占位符，外部系统按检查自动替换后打开本浏览器直链。
     */
    public static function viewerUrlTemplate() {
        return pvw_abs_path(pvw_url('viewer')) . '&uid={study_uid}';
    }

    /** 校验对外接口密钥 */
    public static function checkKey($key) {
        $k = self::apiKey();
        return $k !== '' && is_string($key) && hash_equals($k, $key);
    }

    /** 判断给定接口地址是否指向本模拟服务器（DICOMweb /dicom-web） */
    public static function isSelfEndpoint($url) {
        $path = parse_url((string)$url, PHP_URL_PATH);
        return is_string($path) && $path !== '' && strpos($path, '/dicom-web') !== false;
    }

    /* ---------------- 数据来源 ---------------- */

    /** 患者检查行（统一结构）：按来源取内置仿真数据或 FHIR */
    public static function rows($keyword = '') {
        if (self::source() === 'fhir') {
            try {
                $rows = PvFhirClient::search($keyword);
                foreach ($rows as &$r) { $r['fhir'] = true; }
                unset($r);
                return $rows;
            } catch (Exception $e) { return array(); }
        }
        return PvDemoPacs::search($keyword);
    }

    /** 数据中出现过的检查模态（去重，供检索页「检查类型」筛选） */
    public static function modalities() {
        $out = array();
        foreach (self::rows('') as $r) {
            $m = strtoupper(trim((string)(isset($r['modality']) ? $r['modality'] : '')));
            if ($m !== '') $out[$m] = 1;
        }
        return array_keys($out);
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
        if (self::source() === 'fhir') {   // FHIR：优先 Organization.name
            $h = PvFhirClient::hospitalName();
            $site = $h !== '' ? $h : self::builtinInstitution();
        } else {                           // 内置模拟数据：机构名称取「模拟数据机构名称」
            $site = self::builtinInstitution();
        }
        foreach ($rows as &$r) {
            if (empty($r['institution'])) $r['institution'] = $site;
            $r['status_name'] = '已完成';
        }
        unset($r);
        return $rows;
    }

    /** 调阅单次检查（按来源行组装，含按检查号回退匹配） */
    public static function study($uid) {
        $row = self::findStudyRow($uid);
        return $row ? PvDemoPacs::assembleStudy($row) : null;
    }

    /* ---------------- 标准 DICOM 输出（WADO-URI 二进制流） ---------------- */

    /**
     * 以标准 DICOM Part 10 二进制流返回指定实例。
     * 兼容两种寻址：WADO-URI（studyUID/seriesUID/objectUID）与简洁参数（uid/series/instance）。
     *
     * @param array $p 请求参数
     * @return array {binary:string, filename:string, content_type:string}
     * @throws RuntimeException 参数非法 / 未找到 / 序列不支持标准像素
     */
    public static function wado(array $p) {
        list($row, $series, $seriesIndex, $gen) = self::resolveSeries($p);

        $totalFrames = $gen->getFrameCount();
        $frameCounts = (isset($series['instances']) && is_array($series['instances']) && $series['instances'])
            ? array_map('intval', $series['instances'])
            : array(max(1, $totalFrames));
        $totalInstances = max(1, count($frameCounts));

        $instance = 1;
        if (!empty($p['objectUID']) || !empty($p['object_uid'])) {
            $ouid = !empty($p['objectUID']) ? (string)$p['objectUID'] : (string)$p['object_uid'];
            $instance = (int)self::lastUidSegment($ouid);
        } elseif (isset($p['instance']) && $p['instance'] !== '') {
            $instance = (int)$p['instance'];
        }
        if ($instance < 1) $instance = 1;
        if ($instance > $totalInstances) $instance = $totalInstances;

        $start = 0;
        for ($k = 0; $k < $instance - 1; $k++) $start += $frameCounts[$k];
        $frameCount = max(1, $frameCounts[$instance - 1]);

        $base = ($row['patient_id'] ? $row['patient_id'] : 'patient') . '_' . ($row['accession_no'] ? $row['accession_no'] : 'study')
            . '_s' . ($seriesIndex + 1) . '_i' . $instance . '.dcm';

        /* 内存 / 磁盘缓存：同一实例仅生成一次，后续直接读取 */
        $key = md5(implode('|', array(
            PV_VERSION, $row['study_uid'], $seriesIndex, $instance,
            $gen->getFrameCount(), $gen->getBodyPartExamined(),
        )));
        $cached = PvMockCache::get($key);
        if (is_string($cached) && $cached !== '') {
            return array('binary' => $cached, 'filename' => $base, 'content_type' => 'application/dicom');
        }

        // 多帧：把本实例包含的各帧像素拼接为一个 DICOM 实例
        $pixels = '';
        for ($f = 0; $f < $frameCount; $f++) {
            $pixels .= $gen->generateFrame($start + $f);
        }
        $binary = self::buildDicom($row, $series, $seriesIndex, $instance, $gen, $pixels, $frameCount, $start);
        PvMockCache::set($key, $binary);
        return array('binary' => $binary, 'filename' => $base, 'content_type' => 'application/dicom');
    }

    /**
     * 生成序列缩略图（PNG）：以低分辨率直接采样，避免生成整幅像素，
     * 供侧栏缩略图使用，首屏更快、流量更小。
     */
    public static function thumbnail(array $p) {
        list($row, $series, $seriesIndex, $gen) = self::resolveSeries($p);
        $size = 128;
        $key = md5(implode('|', array(
            'thumb', PV_VERSION, $row['study_uid'], $seriesIndex,
            $gen->getFrameCount(), $gen->getBodyPartExamined(), $size,
        )));
        $cached = PvMockCache::get($key);
        if (is_string($cached) && $cached !== '') {
            return array('binary' => $cached, 'content_type' => 'image/png');
        }
        $png = self::grayToPng($gen->generateThumbnailGray($size), $size);
        PvMockCache::set($key, $png);
        return array('binary' => $png, 'content_type' => 'image/png');
    }

    /** 解析请求对应的检查 / 序列 / 生成器（wado 与 thumbnail 共用） */
    private static function resolveSeries(array $p) {
        $studyUid = '';
        foreach (array('studyUID', 'study_uid', 'uid') as $k) {
            if (!empty($p[$k])) { $studyUid = (string)$p[$k]; break; }
        }
        if ($studyUid === '') throw new RuntimeException('缺少检查标识 studyUID');

        $row = self::findStudyRow($studyUid);
        if (!$row) throw new RuntimeException('未找到该检查');

        $modality = strtoupper($row['modality']);
        $desc = isset($row['description']) ? $row['description'] : '';
        $plan = PvMockDispatcher::seriesPlan($modality, $desc, $row['study_uid']);

        $seriesIndex = 0;
        if (!empty($p['seriesUID']) || !empty($p['series_uid'])) {
            $suid = !empty($p['seriesUID']) ? (string)$p['seriesUID'] : (string)$p['series_uid'];
            $tail = self::lastUidSegment($suid);
            foreach ($plan as $i => $s) {
                if ((string)$s['series_id'] === $tail || strpos($suid, '.' . $s['series_id']) !== false || strpos($suid, $s['series_id']) !== false) {
                    $seriesIndex = $i; break;
                }
            }
        } elseif (isset($p['series']) && $p['series'] !== '') {
            $seriesIndex = (int)$p['series'] - 1;
        }
        if ($seriesIndex < 0 || !isset($plan[$seriesIndex])) throw new RuntimeException('未找到该序列');

        $series = $plan[$seriesIndex];
        $gen = PvMockDispatcher::generatorForSeries($modality, $desc, $row['study_uid'], $seriesIndex);
        if (!$gen) throw new RuntimeException('该序列为重建序列，暂不支持标准 DICOM 像素输出');
        return array($row, $series, $seriesIndex, $gen);
    }

    /** 8 位灰度字节（size×size）→ PNG 二进制（GD 不可用时返回 1×1 透明 PNG） */
    private static function grayToPng($gray, $size) {
        if (!function_exists('imagecreatetruecolor') || strlen($gray) < $size * $size) {
            return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        }
        $im = imagecreatetruecolor($size, $size);
        $cache = array();
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $g = ord($gray[$y * $size + $x]);
                if (!isset($cache[$g])) $cache[$g] = imagecolorallocate($im, $g, $g, $g);
                imagesetpixel($im, $x, $y, $cache[$g]);
            }
        }
        ob_start();
        imagepng($im);
        $bin = ob_get_clean();
        if (PHP_VERSION_ID < 80500) imagedestroy($im);
        return $bin;
    }

    /** 组装单实例标准 DICOM 数据集（支持原生多帧） */
    private static function buildDicom($row, array $series, $seriesIndex, $instance, PvMockAbstractGenerator $gen, $pixels, $frameCount = 1, $startFrame = 0) {
        $modality = strtoupper($row['modality']);
        $studyUid = $row['study_uid'];
        $seriesNo = $seriesIndex + 1;
        $seriesUid = PvMockDicomTagBuilder::deriveUid($studyUid, array($seriesNo));
        $sopUid = PvMockDicomTagBuilder::deriveUid($seriesUid, array($instance));

        list($ipp, $iop, $sliceLoc) = self::spatial($series['orientation'], $startFrame + 1, $gen->getSliceThickness());

        $m = $gen->getModalitySpecificTags();
        $m['sop_class_uid'] = PvMockDicomTagBuilder::sopClassFor($modality);
        $m['sop_instance_uid'] = $sopUid;
        $m['study_uid'] = $studyUid;
        $m['series_uid'] = $seriesUid;
        $m['series_number'] = $seriesNo;
        $m['instance_number'] = $instance;
        $m['transfer_syntax'] = PvMockDicomTagBuilder::TS_EXPLICIT_LE;
        $m['number_of_frames'] = (int)$frameCount;        // 原生多帧：本实例帧数
        $m['slice_location'] = $sliceLoc;
        $m['image_position'] = $ipp;
        $m['image_orientation'] = $iop;
        $m['modality'] = $modality;
        $m['patient_name'] = isset($row['name']) ? $row['name'] : '';
        $m['patient_id'] = isset($row['patient_id']) ? $row['patient_id'] : '';
        $m['patient_birth_date'] = self::toDa(isset($row['birth_date']) ? $row['birth_date'] : '');
        $m['patient_sex'] = self::toSex(isset($row['gender']) ? $row['gender'] : '');
        $m['study_date'] = self::toDa(isset($row['study_date']) ? $row['study_date'] : '');
        $m['study_time'] = self::toTm(isset($row['study_date']) ? $row['study_date'] : '');
        $m['study_description'] = isset($row['description']) ? $row['description'] : '';
        $m['series_description'] = $series['description'];
        $m['accession_number'] = isset($row['accession_no']) ? $row['accession_no'] : '';
        $m['institution'] = isset($row['institution']) ? $row['institution'] : '';
        $m['referring_physician'] = isset($row['apply_doctor']) ? $row['apply_doctor'] : '';
        $m['performing_physician'] = isset($row['apply_doctor']) ? $row['apply_doctor'] : '';
        return PvMockDicomTagBuilder::build($m, $pixels);
    }

    /** 空间定位：返回 [ImagePositionPatient, ImageOrientationPatient, SliceLocation] */
    private static function spatial($orientation, $instance, $thickness) {
        $orientation = strtoupper((string)$orientation);
        $th = (float)$thickness;
        $loc = ($instance - 1) * ($th > 0 ? $th : 1.0);
        if ($orientation === 'SAGITTAL') {
            return array(array($loc, 0, 0), array(0, 1, 0, 0, 0, -1), $loc);
        }
        if ($orientation === 'CORONAL') {
            return array(array(0, $loc, 0), array(1, 0, 0, 0, 0, -1), $loc);
        }
        if ($orientation === 'AXIAL') {
            return array(array(0, 0, $loc), array(1, 0, 0, 0, 1, 0), $loc);
        }
        // PA / DR / US 等投影：单幅，不做层间距递增
        return array(array(0, 0, 0), array(1, 0, 0, 0, 1, 0), 0);
    }

    private static function findStudyRow($uid) {
        foreach (self::rows('') as $r) {
            if ($r['study_uid'] === $uid || (isset($r['accession_no']) && $r['accession_no'] === $uid)) return $r;
        }
        return null;
    }

    private static function lastUidSegment($uid) {
        $parts = explode('.', trim((string)$uid));
        $last = end($parts);
        return preg_replace('/\D/', '', (string)$last);
    }

    private static function toDa($s) {
        $s = trim((string)$s);
        if ($s === '') return '';
        $d = preg_replace('/[^0-9]/', '', substr($s, 0, 10));
        return strlen($d) >= 8 ? substr($d, 0, 8) : $d;
    }

    private static function toTm($s) {
        $s = trim((string)$s);
        if (strlen($s) < 16) return '';
        $t = preg_replace('/[^0-9]/', '', substr($s, 11, 8));
        return strlen($t) >= 6 ? $t : '';
    }

    private static function toSex($s) {
        $s = trim((string)$s);
        if ($s === '男' || strtoupper($s) === 'M' || $s === '1') return 'M';
        if ($s === '女' || strtoupper($s) === 'F' || $s === '2') return 'F';
        return 'O';
    }

    /* ---------------- 内部工具 ---------------- */
}
