<?php
/** app/Repositories/QueryLogRepository.php — 操作日志（管理端可查） */
class PvQueryLogRepository {

    /** 操作类型 → 中文名 */
    public static function actionName($action) {
        $map = array(
            'search'   => '搜索',
            'read'     => '读片',
            'download' => '下载',
            'dicom'    => '阅读 DICOM',
            'report'   => '查看影像报告',
        );
        return isset($map[$action]) ? $map[$action] : ($action !== '' ? $action : '—');
    }

    /** 记录一次搜索（兼容旧调用） */
    public static function add($username, $keyword, $resultCount) {
        return self::record($username, 'search', (string)$keyword, (string)$keyword, (int)$resultCount);
    }

    /** 记录一次操作事件（读片 / 下载 / 阅读 DICOM 等） */
    public static function event($username, $action, $detail, $keyword = '', $resultCount = 0) {
        return self::record($username, $action, $detail, $keyword, $resultCount);
    }

    private static function record($username, $action, $detail, $keyword, $resultCount) {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
        $id = PvDatabase::insert(
            "INSERT INTO query_log(username,keyword,result_count,ip,action,detail,created_at) VALUES(?,?,?,?,?,?,?)",
            array((string)$username, (string)$keyword, (int)$resultCount, $ip, (string)$action, (string)$detail, date('Y-m-d H:i:s'))
        );
        self::enforceLimits();   // 写入后按「条数 / 天数」上限清理最早记录（哪个先到执行哪个）
        return $id;
    }

    /** 日志保留上限设置：{count, days}（0 表示不限制） */
    public static function limits() {
        return PvLogLimits::read('log');
    }

    /** 条数上限（<=0 不限制） */
    public static function maxCount() {
        $l = PvLogLimits::read('log');
        return $l['count'];
    }

    /** 天数上限（<=0 不限制） */
    public static function maxDays() {
        $l = PvLogLimits::read('log');
        return $l['days'];
    }

    /**
     * 按设置清理超限日志：条数上限保留最新 N 条；天数上限删除 N 天前的记录。
     * 两者可同时生效（谁先满足就清理谁），未设置则对应维度不限制。
     */
    public static function enforceLimits() {
        PvLogLimits::enforce('query_log', 'log');
    }
    public static function recent($limit = 50) {
        $limit = max(1, min(500, (int)$limit));
        return PvDatabase::q("SELECT * FROM query_log ORDER BY id DESC LIMIT " . $limit);
    }
    /** 分页读取（管理端滚动加载） */
    public static function page($offset = 0, $limit = 30) {
        $limit = max(1, min(200, (int)$limit));
        $offset = max(0, (int)$offset);
        return PvDatabase::q("SELECT * FROM query_log ORDER BY id DESC LIMIT " . $limit . " OFFSET " . $offset);
    }
    /** 读取 id 大于 sinceId 的新日志（管理端「实时」增量拉取，最新在前） */
    public static function since($sinceId, $limit = 50) {
        $limit = max(1, min(200, (int)$limit));
        $sinceId = max(0, (int)$sinceId);
        return PvDatabase::q("SELECT * FROM query_log WHERE id > ? ORDER BY id DESC LIMIT " . $limit, array($sinceId));
    }
    public static function count() {
        return (int)PvDatabase::val("SELECT COUNT(*) FROM query_log");
    }
    public static function clear() {
        return PvDatabase::exec("DELETE FROM query_log");
    }
}
