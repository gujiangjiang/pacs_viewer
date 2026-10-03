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
}
