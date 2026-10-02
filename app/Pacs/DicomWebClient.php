<?php
/**
 * ============================================================
 * app/Pacs/DicomWebClient.php — 标准 DICOMweb 客户端（QIDO-RS / WADO-RS）
 * ============================================================
 * 将标准 DICOMweb 服务映射为本项目内部数据模型，供「接口协议 = dicomweb」时使用：
 *   · QIDO-RS  检索：GET {base}/studies?PatientName=&limit=&offset=&includefield=all
 *   · WADO-RS  调阅：GET {base}/studies/{uid}/series
 *                    GET {base}/studies/{uid}/series/{se}/instances（含 NumberOfFrames）
 *   影像帧经本服务端代理（?r=wadoprx）按实例获取，避免浏览器跨域。
 * ============================================================ */
class PvDicomWebClient {

    /** 临时配置覆盖（仅用于「测试当前输入」场景） */
    private static $override = null;

    public static function base() {
        if (self::$override !== null && self::$override['endpoint'] !== null) return rtrim(self::$override['endpoint'], '/');
        return rtrim((string)PvSettings::get('pacs_endpoint', ''), '/');
    }
    public static function isConfigured() { return self::base() !== ''; }
    private static function key() {
        if (self::$override !== null && self::$override['key'] !== null) return self::$override['key'];
        return trim((string)PvSettings::get('pacs_api_key', ''));
    }
    private static function timeout() {
        if (self::$override !== null && self::$override['timeout'] !== null) return max(1, (int)self::$override['timeout']);
        return max(1, (int)PvSettings::get('pacs_timeout', '5'));
    }

    /** 使用指定配置测试连通性（不读取已保存设置） */
    public static function pingWith($endpoint, $key = null, $timeout = null) {
        self::$override = array(
            'endpoint' => $endpoint !== null ? trim((string)$endpoint) : null,
            'key' => $key !== null ? (string)$key : null,
            'timeout' => $timeout !== null && $timeout !== '' ? (int)$timeout : null,
        );
        try { return self::ping(); }
        finally { self::$override = null; }
    }

    /** GET JSON（DICOM JSON），base 含 '?' 时以 & 追加查询 */
    private static function getJson($path, array $query = array()) {
        $base = self::base();
        if ($base === '') throw new RuntimeException('未配置 DICOMweb 接口地址');
        $sep = (strpos($base, '?') !== false) ? '&' : '?';
        $url = $base . $path . ($query ? $sep . http_build_query($query) : '');
        $headers = array('Accept: application/dicom+json');
        $k = self::key();
        if ($k !== '') { $headers[] = 'Authorization: Bearer ' . $k; $headers[] = 'X-API-Key: ' . $k; }
        $r = PvHttp::get($url, self::timeout(), $headers);
        if ($r['body'] === false || $r['body'] === '') throw new RuntimeException('无法连接 DICOMweb 接口：' . $url);
        $j = json_decode($r['body'], true);
        if (!is_array($j)) throw new RuntimeException('DICOMweb 返回非 JSON 数据');
        if (isset($j['resourceType'])) throw new RuntimeException('DICOMweb 返回错误：' . (isset($j['issue'][0]['diagnostics']) ? $j['issue'][0]['diagnostics'] : 'OperationOutcome'));
        return $j;
    }

    /** 连通性测试：拉取 studies（limit=1），并返回机构名称（InstitutionName） */
    public static function ping() {
        try {
            $arr = self::getJson('/studies', array('limit' => 1, 'includefield' => 'all'));
            $first = (is_array($arr) && isset($arr[0]) && is_array($arr[0])) ? $arr[0] : array();
            $inst = self::val('00080080', $first);
            if ($inst === '') $inst = pvw_hospital_api();
            return array(
                'name' => 'DICOMweb 服务', 'endpoint' => self::base(), 'mode' => 'DICOMweb',
                'studies' => count((array)$arr), 'institution' => $inst,
            );
        } catch (Exception $e) {
            throw new RuntimeException('DICOMweb 连接失败：' . $e->getMessage());
        }
    }

    /**
     * 检索（QIDO-RS）：QIDO 无总数，has_more 依据返回数量推断。
     * @param array $filters 可选：gender(M/F/O)、date_from/date_to(YYYY-MM-DD)、modality
     */
    public static function search($keyword, $limit = 0, $offset = 0, $filters = array()) {
        $q = array('includefield' => 'all');
        $kw = trim((string)$keyword);
        if ($kw !== '') $q['PatientName'] = $kw;
        $filters = is_array($filters) ? $filters : array();
        $sex = strtoupper(trim((string)(isset($filters['gender']) ? $filters['gender'] : '')));
        if (in_array($sex, array('M', 'F', 'O'), true)) $q['PatientSex'] = $sex;
        $mod = trim((string)(isset($filters['modality']) ? $filters['modality'] : ''));
        if ($mod !== '') $q['ModalitiesInStudy'] = strtoupper($mod);
        $df = preg_replace('/\D/', '', (string)(isset($filters['date_from']) ? $filters['date_from'] : ''));
        $dt = preg_replace('/\D/', '', (string)(isset($filters['date_to']) ? $filters['date_to'] : ''));
        if (strlen($df) >= 8) {
            $q['StudyDate'] = (strlen($dt) >= 8 && $dt !== $df) ? ($df . '-' . $dt) : $df;
        }
        if ((int)$limit > 0) { $q['limit'] = (int)$limit; $q['offset'] = max(0, (int)$offset); }
        $arr = self::getJson('/studies', $q);
        $list = array();
        foreach ((array)$arr as $res) { if (is_array($res)) $list[] = self::mapStudyRow($res); }
        $hasMore = ((int)$limit > 0) ? (count($list) >= (int)$limit) : false;
        $total = $hasMore ? 0 : ((int)$offset + count($list));   // 0 表示未知（前端显示「已加载 N 条」）
        return array('list' => $list, 'total' => $total, 'has_more' => $hasMore);
    }

    /** 调阅（WADO-RS）：检查 + 序列 + 实例（含原生多帧帧数） */
    public static function study($uid) {
        $arr = self::getJson('/studies', array('StudyInstanceUID' => $uid, 'includefield' => 'all'));
        if (!is_array($arr) || !count($arr)) throw new RuntimeException('未找到该检查');
        $row = self::mapStudyRow($arr[0]);

        $patient = array(
            'patient_id' => $row['patient_id'], 'name' => $row['name'], 'gender' => $row['gender'],
            'age' => $row['age'], 'birth_date' => $row['birth_date'], 'outpatient_no' => $row['outpatient_no'],
        );
        $st = array(
            'accession_no' => $row['accession_no'], 'study_uid' => $uid, 'modality' => $row['modality'],
            'description' => $row['description'], 'study_date' => $row['study_date'],
            'institution' => $row['institution'], 'station_name' => $row['station_name'],
            'apply_dept' => '', 'apply_doctor' => '', 'slice_thickness' => 0,
        );

        $seriesRes = self::getJson('/studies/' . rawurlencode($uid) . '/series', array('includefield' => 'all'));
        $series = array(); $seNo = 0;
        foreach ((array)$seriesRes as $sres) {
            if (!is_array($sres)) continue;
            $seUid = self::val('0020000E', $sres);
            if ($seUid === '') continue;
            $seNo++;
            $mod = strtoupper(self::val('00080060', $sres));
            $desc = self::val('0008103E', $sres);
            if ($desc === '') $desc = 'Series ' . $seNo;

            $instRes = self::getJson('/studies/' . rawurlencode($uid) . '/series/' . rawurlencode($seUid) . '/instances', array('includefield' => 'all'));
            $instances = array(); $counts = array(); $count = 0;
            foreach ((array)$instRes as $ires) {
                if (!is_array($ires)) continue;
                $iuid = self::val('00080018', $ires);
                if ($iuid === '') continue;
                $nf = (int)self::val('00280008', $ires); if ($nf < 1) $nf = 1;
                $instances[] = array('uid' => $iuid, 'frames' => $nf);
                $counts[] = $nf;
                $count += $nf;
            }
            if (!$instances) continue;
            $fpi = $instances[0]['frames'];
            $images = array();
            foreach ($instances as $it) {
                $images[] = pvw_url('wadoprx', array('study' => $uid, 'series' => $seUid, 'instance' => $it['uid']));
            }
            // 从首个实例的元数据解析像素 / 窗宽窗位参数（保证正确渲染，避免过曝）
            $first = array();
            foreach ((array)$instRes as $r0) { if (is_array($r0)) { $first = $r0; break; } }
            $series[] = array(
                'series_id' => (string)$seNo, 'description' => $desc,
                'orientation' => self::orientation(self::val('00200037', $first)),
                'slice_count' => $count, 'is_mock' => false, 'format' => 'dicom',
                'is_hu' => ($mod === 'CT'),
                'slice_thickness' => self::num('00180050', $first),
                'pixel_spacing' => self::num('00280030', $first),
                'rows' => (int)self::num('00280010', $first),
                'columns' => (int)self::num('00280011', $first),
                'bits_allocated' => (int)self::num('00280100', $first) ?: 16,
                'bits_stored' => (int)self::num('00280101', $first) ?: 16,
                'pixel_representation' => (int)self::num('00280103', $first),
                'window_center' => self::num('00281050', $first),
                'window_width' => self::num('00281051', $first),
                'rescale_intercept' => self::num('00281052', $first),
                'rescale_slope' => self::num('00281053', $first),
                'seed' => '', 'images' => $images, 'frames_per_instance' => $fpi,
                'instances' => $counts,
                'thumbnail' => PvMockServer::isSelfEndpoint(self::base())
                    ? self::base() . '/studies/' . rawurlencode($uid) . '/series/' . rawurlencode($seUid)
                      . '/instances/' . rawurlencode($instances[0]['uid']) . '/rendered'
                    : '',
            );
        }
        return array('patient' => $patient, 'study' => $st, 'series' => $series);
    }

    /* ---------------- DICOM JSON 映射 ---------------- */

    private static function val($tag, $res, $idx = 0) {
        if (!isset($res[$tag]['Value'])) return '';
        $v = $res[$tag]['Value'];
        if (!is_array($v)) return (string)$v;
        $x = isset($v[$idx]) ? $v[$idx] : '';
        if (is_array($x)) {
            if (isset($x['Alphabetic'])) return (string)$x['Alphabetic'];
            if (isset($x['Value'])) return implode('', (array)$x['Value']);
            return '';
        }
        return (string)$x;
    }

    private static function firstArrayVal($tag, $res) { return self::val($tag, $res, 0); }

    /** 取数值型标签（DS/IS），无值时返回 0 */
    private static function num($tag, $res) {
        $s = self::val($tag, $res);
        if ($s === '') return 0;
        $f = (float)$s;
        return is_finite($f) ? $f : 0;
    }

    /** ImageOrientationPatient → AXIAL / SAGITTAL / CORONAL 方位名 */
    private static function orientation($iop) {
        $v = array_map('trim', explode('\\', (string)$iop));
        if (count($v) < 6) return '';
        $row = array((float)$v[0], (float)$v[1], (float)$v[2]);
        $col = array((float)$v[3], (float)$v[4], (float)$v[5]);
        $nz = abs($row[0] * $col[1] - $row[1] * $col[0]);   // Z 分量的法向大小
        if ($nz > 0.7) return 'AXIAL';
        if (abs($row[1]) > 0.7 || abs($col[1]) > 0.7) return 'CORONAL';
        if (abs($row[0]) > 0.7 || abs($col[0]) > 0.7) return 'SAGITTAL';
        return '';
    }

    private static function mapStudyRow($res) {
        $sex = self::val('00100040', $res);
        $gender = ($sex === 'M') ? '男' : (($sex === 'F') ? '女' : ($sex !== '' ? $sex : '未知'));
        $birth = self::fmtDate(self::val('00100030', $res));
        $age = self::ageOf($birth, self::val('00101010', $res));   // 优先 PatientAge(0010,1010)，否则由出生日期推算
        $mod = self::firstArrayVal('00080061', $res);            // ModalitiesInStudy
        if ($mod === '') $mod = self::val('00080060', $res);     // Modality
        return array(
            'study_uid' => self::val('0020000D', $res),
            'patient_id' => self::val('00100020', $res),
            'name' => self::val('00100010', $res),
            'gender' => $gender,
            'age' => $age,
            'birth_date' => $birth,
            'outpatient_no' => self::val('00101000', $res),
            'accession_no' => self::val('00080050', $res),
            'modality' => strtoupper($mod),
            'description' => self::val('00081030', $res),
            'study_date' => trim(self::fmtDate(self::val('00080020', $res)) . ' ' . self::fmtTime(self::val('00080030', $res))),
            'institution' => self::val('00080080', $res),
            'station_name' => self::val('00081010', $res),
            'apply_dept' => '',
            'apply_doctor' => '',
            'status' => 'completed',
            'series_count' => (int)self::val('00201209', $res),
        );
    }

    /** 年龄：优先 DICOM PatientAge(0010,1010，如 062Y)；否则由出生日期推算（xx岁） */
    private static function ageOf($birth, $ageTag) {
        $ageTag = strtoupper(trim((string)$ageTag));
        if ($ageTag !== '' && preg_match('/^(\d+)([YMWD])$/', $ageTag, $m)) {
            if ($m[2] === 'Y') return (int)$m[1] . '岁';
        }
        $d = preg_replace('/\D/', '', (string)$birth);
        if (strlen($d) < 8) return '';
        $t = strtotime(substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2));
        if (!$t) return '';
        $y = (int)floor((time() - $t) / (365.25 * 86400));
        return ($y > 0 && $y < 130) ? ($y . '岁') : '';
    }

    private static function fmtDate($d) {
        $d = preg_replace('/\D/', '', (string)$d);
        return strlen($d) >= 8 ? substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2) : '';
    }
    private static function fmtTime($t) {
        $t = preg_replace('/\D/', '', (string)$t);
        if (strlen($t) < 4) return '';
        return substr($t, 0, 2) . ':' . substr($t, 2, 2) . (strlen($t) >= 6 ? ':' . substr($t, 4, 2) : '');
    }
}
