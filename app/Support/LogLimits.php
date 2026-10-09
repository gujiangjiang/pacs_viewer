<?php
/**
 * ============================================================
 * app/Support/LogLimits.php — 日志「条数 / 天数」保留上限公共助手
 * ============================================================
 * 三类日志（操作 / 协议与模拟 / 系统）各自维护「条数 / 天数」上限，
 * 设置键为 `{prefix}_max_count` / `{prefix}_max_days`（空 / 0 表示不限制）。
 * 本类集中提供上限读取 / 保存，以及「按自增 id 保留最新 N 条」「删除 N 天前
 * 记录」的通用清理，避免各仓库重复实现同类 SQL。
 *
 * 无状态工具类；实际清理由调用方在写入 / 保存设置后触发，语义与既有实现一致。
 * ============================================================ */
class PvLogLimits {

    /** 读取上限：{count, days}（<=0 表示不限制） */
    public static function read($prefix) {
        $count = (int)PvSettings::get($prefix . '_max_count', '');
        $days  = (int)PvSettings::get($prefix . '_max_days', '');
        return array('count' => $count > 0 ? $count : 0, 'days' => $days > 0 ? $days : 0);
    }

    /** 保存上限（清洗为可选项：留空 / 非法 / 非正数 → 不限制） */
    public static function save($prefix, $count, $days) {
        PvSettings::set($prefix . '_max_count', PvNumber::positiveInt($count));
        PvSettings::set($prefix . '_max_days', PvNumber::positiveInt($days));
    }

    /**
     * 按「条数上限」删除最早记录（按自增 id 倒序保留最新 N 条）。
     * @param string      $table   表名（调用方常量）
     * @param string      $prefix  设置键前缀
     * @param string|null $channel 通道过滤；null 表示表无通道列
     */
    public static function trimById($table, $prefix, $channel = null) {
        $l = self::read($prefix);
        if ($l['count'] <= 0) return;
        if ($channel === null) {
            PvDatabase::exec("DELETE FROM " . $table . " WHERE id NOT IN (SELECT id FROM " . $table . " ORDER BY id DESC LIMIT " . $l['count'] . ")");
        } else {
            PvDatabase::exec(
                "DELETE FROM " . $table . " WHERE channel=? AND id NOT IN (SELECT id FROM " . $table . " WHERE channel=? ORDER BY id DESC LIMIT " . $l['count'] . ")",
                array($channel, $channel)
            );
        }
    }

    /** 按「天数上限」删除超期记录（created_at < 当前时间 - N 天） */
    public static function trimByDate($table, $prefix, $channel = null) {
        $l = self::read($prefix);
        if ($l['days'] <= 0) return;
        $cut = date('Y-m-d H:i:s', time() - $l['days'] * 86400);
        if ($channel === null) {
            PvDatabase::exec("DELETE FROM " . $table . " WHERE created_at < ?", array($cut));
        } else {
            PvDatabase::exec("DELETE FROM " . $table . " WHERE channel=? AND created_at < ?", array($channel, $cut));
        }
    }

    /** 按上限执行两类清理（先条数后天数，与既有实现一致） */
    public static function enforce($table, $prefix, $channel = null) {
        self::trimById($table, $prefix, $channel);
        self::trimByDate($table, $prefix, $channel);
    }
}
