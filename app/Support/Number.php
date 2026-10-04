<?php
/**
 * ============================================================
 * app/Support/Number.php — 可选项数值清洗助手
 * ============================================================
 * 管理端多处把「容量 / 条数 / 天数」等可选项从表单解析为存储值，约定
 * 「留空 / 非法 / 非正数」一律返回空串（表示不限制）。集中于此避免重复实现。
 * 纯函数、无状态、无依赖。
 * ============================================================ */
class PvNumber {

    /** 正整数（>0）文本：留空 / 非法 / 非正 → ''（表示不限制） */
    public static function positiveInt($v) {
        $v = trim((string)$v);
        if ($v === '' || !is_numeric($v)) return '';
        $n = (int)$v;
        return $n > 0 ? (string)$n : '';
    }

    /** MB 数值 → 字节文本：留空 / 非法 / 非正 → ''（表示不限制） */
    public static function mbToBytes($mb) {
        $mb = trim((string)$mb);
        if ($mb === '' || !is_numeric($mb)) return '';
        $bytes = (int)round((float)$mb * 1048576);
        return $bytes > 0 ? (string)$bytes : '';
    }
}
