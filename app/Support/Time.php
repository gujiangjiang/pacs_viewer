<?php
/**
 * ============================================================
 * app/Support/Time.php — 时间与时区统一助手（PvTime）
 * ============================================================
 * 三系统统一时间契约（PACS / HIS / 门诊一体化）：
 *  - 存储：本系统自身 DATETIME 列一律 UTC（'Y-m-d H:i:s'），写入唯一来源 now()；
 *  - 显示：全站页面与内部接口经 local() / localize() 按站点时区
 *    （config.db timezone，安装向导设置）换算；
 *  - 对外接口：isoUtc() 输出标准 UTC（ISO 8601 带 Z），不受站点时区影响；
 *    入站外部时间经 fromUtc() 按 UTC 解析落库；
 *  - 业务日期（birth_date、DICOM DA/TM 等）：业务口径直通，不做时区换算；
 *  - 业务日期时间（用户选择的本地时间）：nowLocal() + 展示原样（与存储 UTC 列区分）。
 *
 * 双模式过渡（存量库平滑迁移）：config.db `time.storage` = 'utc'（新装向导写入）/
 * 'local'（存量默认，迁移脚本 convert 后置 'utc'）；local 模式下本文件
 * 全部行为与旧版一致。
 */
class PvTime {

    /** 是否 UTC 存储模式（config.db 运行时读取——向导同请求内写入即时生效） */
    public static function storageUtc() {
        return (string)PvConfigDb::get('time.storage', 'local') === 'utc';
    }

    /** 当前模式名（'utc' | 'local'） */
    public static function modeName() {
        return self::storageUtc() ? 'utc' : 'local';
    }

    /* ==================== 写入（存储值唯一来源） ==================== */

    /** 当前存储时刻（utc 模式 = UTC；local 模式 = 站点墙钟，行为同旧版） */
    public static function now() {
        return self::storageUtc() ? gmdate('Y-m-d H:i:s') : date('Y-m-d H:i:s');
    }

    /** 今日业务日（站点时区日期，两模式一致） */
    public static function today() {
        return date('Y-m-d');
    }

    /** 站点时区当前墙钟串（业务本地时间字段专用，不随存储模式换算） */
    public static function nowLocal() {
        return date('Y-m-d H:i:s');
    }

    /** N 天前的存储时刻串（日志保留裁剪等「按时间删旧」统一口径） */
    public static function stampDaysAgo($days) {
        $cut = time() - max(0, (int)$days) * 86400;
        return self::storageUtc() ? gmdate('Y-m-d H:i:s', $cut) : date('Y-m-d H:i:s', $cut);
    }

    /* ==================== 解析与换算 ==================== */

    /** 存储串 → epoch（模式感知）；空/非法返回 null */
    public static function ts($v) {
        $s = trim((string)$v);
        if ($s === '') return null;
        $t = self::storageUtc() ? strtotime($s . ' UTC') : strtotime($s);
        return $t === false ? null : $t;
    }

    /** 存储串 → 站点时区显示串（local 模式恒等；utc 模式换算） */
    public static function local($v, $fmt = null) {
        $s = (string)$v;
        if ($s === '' || !self::storageUtc()) return $s;
        $t = self::ts($s);
        if ($t === null) return $s;
        return date(($fmt !== null && $fmt !== '') ? $fmt : 'Y-m-d H:i:s', $t);
    }

    /** 存储串 → 站点时区业务日 'Y-m-d' */
    public static function localDate($v) {
        $s = (string)$v;
        if ($s === '') return '';
        if (!self::storageUtc()) return substr($s, 0, 10);
        $t = self::ts($s);
        return $t === null ? '' : date('Y-m-d', $t);
    }

    /** 站点时区墙钟串 → 存储值 */
    public static function fromLocal($v) {
        $s = trim((string)$v);
        if ($s === '') return '';
        if (!self::storageUtc()) return $s;
        $t = strtotime($s);
        return $t === false ? '' : gmdate('Y-m-d H:i:s', $t);
    }

    /** 站点业务日 [start, end) 的存储边界串（分日窗口查询统一用） */
    public static function dayRange($ymd) {
        $ymd = trim((string)$ymd);
        if ($ymd === '') return array('', '');
        $next = date('Y-m-d', strtotime($ymd . ' +1 day'));
        return array(self::fromLocal($ymd . ' 00:00:00'), self::fromLocal($next . ' 00:00:00'));
    }

    /* ==================== 对外接口（UTC 契约） ==================== */

    /** 存储串 → 对外 UTC 输出（ISO 8601 带 Z；两模式输出一致） */
    public static function isoUtc($v) {
        $t = self::ts($v);
        return $t === null ? '' : gmdate('Y-m-d\TH:i:s\Z', $t);
    }

    /** 外部 UTC 入站（ISO 8601 'Z'/±偏移，或纯串按 UTC 解释）→ 存储值 */
    public static function fromUtc($v) {
        $s = trim((string)$v);
        if ($s === '') return '';
        if (!preg_match('/[Zz]$|[+-]\d{2}:?\d{2}$/', $s)) $s .= ' UTC';
        $t = strtotime($s);
        if ($t === false) return '';
        return self::storageUtc() ? gmdate('Y-m-d H:i:s', $t) : date('Y-m-d H:i:s', $t);
    }

    /* ==================== 数组本地化 ==================== */

    /** 本地化豁免键：业务本地时间字段（用户选择，展示原样，不做存储换算） */
    private static $localBizKeys = array();

    /**
     * 递归本地化：数组中所有 *_at / *_time 键值经 local() 换算为站点时区显示串。
     * 仅用于内部展示数据（日志/用户列表等）；DICOM 业务日期与透传数据不得经此处理。
     */
    public static function localize($data) {
        if (!is_array($data)) return $data;
        $out = array();
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $out[$k] = self::localize($v);
            } elseif (is_string($k) && !isset(self::$localBizKeys[$k]) && preg_match('/_at$|_time$/', $k)) {
                $out[$k] = self::local($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
