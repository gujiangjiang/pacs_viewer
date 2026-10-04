<?php
/**
 * ============================================================
 * app/Support/Dicom.php — DICOM 值规范化助手
 * ============================================================
 * 集中处理患者 / 检查元数据到标准 DICOM 值（DA / TM / CS）的转换，
 * 供内置模拟服务器与 DICOMweb 端点复用，避免多处重复实现。
 * 纯函数、无状态、无依赖。
 * ============================================================ */
class PvDicom {

    /**
     * 日期 → DICOM DA（YYYYMMDD）。
     * @param mixed $s       原始日期串（如 `2026-08-03 08:00:00`）
     * @param bool  $lenient 宽松模式：仅取前 10 个字符、不足 8 位亦返回已提取数字
     *                       （保留内置模拟服务器既有行为）；严格模式不足 8 位返回空串
     */
    public static function da($s, $lenient = false) {
        $s = trim((string)$s);
        if ($s === '') return '';
        $src = $lenient ? substr($s, 0, 10) : $s;
        $d = preg_replace('/\D/', '', $src);
        if (strlen($d) >= 8) return substr($d, 0, 8);
        return $lenient ? $d : '';
    }

    /**
     * 时间 → DICOM TM（HHMMSS[.F...]）。
     * @param mixed $s         原始日期时间串（如 `2026-08-03 08:00:00`）
     * @param int   $maxDigits 最大保留位数（0 表示不截断，保留最多 8 位；
     *                         6 表示仅 HHMMSS，保留 DICOMweb 端点既有行为）
     */
    public static function tm($s, $maxDigits = 0) {
        $s = trim((string)$s);
        if (strlen($s) < 16) return '';
        $t = preg_replace('/\D/', '', substr($s, 11, 8));
        if (strlen($t) < 6) return '';
        return $maxDigits > 0 ? substr($t, 0, $maxDigits) : $t;
    }

    /** 性别 → DICOM PatientSex（M / F / O） */
    public static function sex($s) {
        $s = trim((string)$s);
        if ($s === '男' || strtoupper($s) === 'M' || $s === '1') return 'M';
        if ($s === '女' || strtoupper($s) === 'F' || $s === '2') return 'F';
        return 'O';
    }

    /* ---------------- 检索 / 映射通用值助手（供 PACS / FHIR 映射复用） ---------------- */

    /** 性别 → DICOM PatientSex（M / F / O），兼容中文、英文全称与数字代码 */
    public static function sexCode($s) {
        $s = strtoupper(trim((string)$s));
        if ($s === '男' || $s === 'M' || $s === 'MALE' || $s === '1') return 'M';
        if ($s === '女' || $s === 'F' || $s === 'FEMALE' || $s === '2') return 'F';
        return 'O';
    }

    /** DICOM PatientSex（M / F / O）→ 中文展示；未知返回「未知」 */
    public static function sexText($code) {
        $c = self::sexCode($code);
        return $c === 'M' ? '男' : ($c === 'F' ? '女' : '未知');
    }

    /**
     * 年龄展示（xx岁）：优先 DICOM PatientAge（如 062Y），其次按出生日期推算。
     * 无法解析或超出 1..129 岁返回空串。
     * @param mixed  $birth  出生日期（任意含 YYYYMMDD 的串）
     * @param string $ageTag DICOM PatientAge（0010,1010）
     */
    public static function ageText($birth = '', $ageTag = '') {
        $ageTag = strtoupper(trim((string)$ageTag));
        if ($ageTag !== '' && preg_match('/^(\d+)([YMWD])$/', $ageTag, $m) && $m[2] === 'Y') {
            return (int)$m[1] . '岁';
        }
        $d = preg_replace('/\D/', '', (string)$birth);
        if (strlen($d) < 8) return '';
        $t = strtotime(substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2));
        if (!$t) return '';
        $y = (int)floor((time() - $t) / (365.25 * 86400));
        return ($y > 0 && $y < 130) ? ($y . '岁') : '';
    }

    /** 任意日期时间串 → 'Y-m-d H:i:s'（无法解析返回空串） */
    public static function dateTimeText($s) {
        $s = trim((string)$s);
        if ($s === '') return '';
        $t = strtotime($s);
        return $t === false ? '' : date('Y-m-d H:i:s', $t);
    }

    /** DICOM DA（YYYYMMDD）→ 'Y-m-d'；不足 8 位数字返回空串 */
    public static function formatDicomDate($d) {
        $d = preg_replace('/\D/', '', (string)$d);
        return strlen($d) >= 8 ? substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2) : '';
    }

    /** DICOM TM（HHMMSS）→ 'H:i[:s]'；不足 4 位数字返回空串 */
    public static function formatDicomTime($t) {
        $t = preg_replace('/\D/', '', (string)$t);
        if (strlen($t) < 4) return '';
        return substr($t, 0, 2) . ':' . substr($t, 2, 2) . (strlen($t) >= 6 ? ':' . substr($t, 4, 2) : '');
    }

    /**
     * 检查日期是否匹配单个 YYYYMMDD 或范围 YYYYMMDD-YYYYMMDD。
     * @param string $studyDate 检查时间（取前 10 字符内的数字）
     * @param string $range     单个或范围（可含分隔符）
     */
    public static function dateMatch($studyDate, $range) {
        $d = preg_replace('/\D/', '', substr((string)$studyDate, 0, 10));
        if (strlen($d) < 8) return false;
        $d = substr($d, 0, 8);
        $range = preg_replace('/[^0-9\-]/', '', (string)$range);
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
}
