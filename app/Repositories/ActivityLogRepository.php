<?php
/**
 * app/Repositories/ActivityLogRepository.php
 * — 通用活动日志（协议日志 / 模拟服务器日志），按 channel 分通道存储与限额。
 *
 * 与「操作日志」（query_log，见 QueryLogRepository）相互独立：
 *   · 操作日志：登录用户的读片 / 检索 / 下载等业务操作；
 *   · 协议日志：系统对外 / 出向的 DICOMweb 等接口调用（channel=protocol）；
 *   · 模拟服务器日志：模拟服务器 FHIR 取数、登记 / 摄片 / 生图等事件（channel=mock）。
 *
 * 每个通道各自维护「条数 / 天数」上限（settings：log_{channel}_max_count / _max_days），
 * 写入后按上限清理最早记录（哪个先到执行哪个）。
 */
class PvActivityLogRepository {

    /** 通道 → 中文名（顺序即管理端左栏顺序） */
    public static function channels() {
        return array(
            'protocol' => '协议日志',
            'mock'     => '模拟服务器日志',
        );
    }

    public static function channelName($channel) {
        $m = self::channels();
        return isset($m[$channel]) ? $m[$channel] : (string)$channel;
    }

    /** 规范化通道名（非法通道返回空串） */
    public static function normalize($channel) {
        $c = strtolower(trim((string)$channel));
        return isset(self::channels()[$c]) ? $c : '';
    }

    /** 记录一条活动日志（返回自增 id；通道非法返回 0） */
    public static function record($channel, $action, $detail = '', $level = 'info', $meta = null) {
        $channel = self::normalize($channel);
        if ($channel === '') return 0;
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
        $metaStr = '';
        if ($meta !== null) {
            $metaStr = is_string($meta) ? $meta : json_encode($meta, JSON_UNESCAPED_UNICODE);
        }
        $id = PvDatabase::insert(
            "INSERT INTO activity_log(channel,level,action,detail,meta,ip,created_at) VALUES(?,?,?,?,?,?,?)",
            array($channel, (string)$level, (string)$action, (string)$detail, (string)$metaStr, $ip, date('Y-m-d H:i:s'))
        );
        self::enforceLimits($channel);
        return $id;
    }

    /** 协议日志快捷方法 */
    public static function protocol($action, $detail = '', $level = 'info', $meta = null) {
        return self::record('protocol', $action, $detail, $level, $meta);
    }

    /** 模拟服务器日志快捷方法 */
    public static function mock($action, $detail = '', $level = 'info', $meta = null) {
        return self::record('mock', $action, $detail, $level, $meta);
    }

    /* ---------------- 限额 ---------------- */

    /** 设置键前缀（合法通道 → log_{channel}；非法返回空串） */
    private static function limitPrefix($channel) {
        $c = self::normalize($channel);
        return $c === '' ? '' : ('log_' . $c);
    }

    /** 当前通道上限：{count, days}（0 表示不限制） */
    public static function limits($channel) {
        $p = self::limitPrefix($channel);
        return $p === '' ? array('count' => 0, 'days' => 0) : PvLogLimits::read($p);
    }

    public static function maxCount($channel) {
        $l = self::limits($channel);
        return $l['count'];
    }

    public static function maxDays($channel) {
        $l = self::limits($channel);
        return $l['days'];
    }

    /** 保存通道限额设置 */
    public static function saveLimits($channel, $count, $days) {
        $c = self::normalize($channel);
        if ($c === '') return false;
        PvLogLimits::save('log_' . $c, $count, $days);
        self::enforceLimits($c);
        return true;
    }

    /** 按设置清理超限日志（条数 / 天数，哪个先到执行哪个） */
    public static function enforceLimits($channel) {
        $c = self::normalize($channel);
        if ($c === '') return;
        PvLogLimits::enforce('activity_log', 'log_' . $c, $c);
    }

    /* ---------------- 读取 ---------------- */

    public static function recent($channel, $limit = 50) {
        $c = self::normalize($channel);
        if ($c === '') return array();
        $limit = max(1, min(500, (int)$limit));
        return PvDatabase::q("SELECT * FROM activity_log WHERE channel=? ORDER BY id DESC LIMIT " . $limit, array($c));
    }

    /** 分页读取（管理端滚动加载） */
    public static function page($channel, $offset = 0, $limit = 30) {
        $c = self::normalize($channel);
        if ($c === '') return array();
        $limit = max(1, min(200, (int)$limit));
        $offset = max(0, (int)$offset);
        return PvDatabase::q("SELECT * FROM activity_log WHERE channel=? ORDER BY id DESC LIMIT " . $limit . " OFFSET " . $offset, array($c));
    }

    /** 读取 id 大于 sinceId 的新日志（实时增量拉取，最新在前） */
    public static function since($channel, $sinceId, $limit = 50) {
        $c = self::normalize($channel);
        if ($c === '') return array();
        $limit = max(1, min(200, (int)$limit));
        $sinceId = max(0, (int)$sinceId);
        return PvDatabase::q("SELECT * FROM activity_log WHERE channel=? AND id > ? ORDER BY id DESC LIMIT " . $limit, array($c, $sinceId));
    }

    public static function count($channel) {
        $c = self::normalize($channel);
        if ($c === '') return 0;
        return (int)PvDatabase::val("SELECT COUNT(*) FROM activity_log WHERE channel=?", array($c));
    }

    public static function clear($channel) {
        $c = self::normalize($channel);
        if ($c === '') return 0;
        return PvDatabase::exec("DELETE FROM activity_log WHERE channel=?", array($c));
    }
}
