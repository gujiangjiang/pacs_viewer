<?php
/**
 * ============================================================
 * app/ConfigDb.php — 独立基础设施配置库（data/config.db，SQLite）
 * ============================================================
 * 参考门诊一体化系统：数据库连接、全站时区等「最底层」配置独立于业务库存放；
 * 删除 config.db 仅重置基础设施配置、不破坏业务数据（可经安装向导重新绑定）。
 * 未生成 config.db 时按默认常量运行（旧版向后兼容，行为不变）。
 *
 * 配置键（唯一数据源；新增键只在此登记说明）：
 *   db.type       sqlite（当前仅支持；预留其他驱动）     （默认 sqlite）
 *   db.path       业务 SQLite 数据文件路径               （默认 data/pacs_viewer.db）
 *   timezone      全站时区（如 Asia/Shanghai）——安装向导设置（存储恒为 UTC）
 *   setup.stage   多步安装向导进度（断点续填）
 */
class PvConfigDb {

    private static $pdo = null;
    private static $cache = null;
    private static $loaded = false;

    /** config.db 文件路径 */
    public static function path() {
        return PV_DATA . '/config.db';
    }

    /** 是否已存在（已初始化过基础设施配置） */
    public static function exists() {
        return is_file(self::path());
    }

    /**
     * 打开 config.db（严格校验 SQLite Magic Header）。
     * 损坏文件自动备份为 config.db.corrupt.[时间戳] 并优雅降级（返回 null，
     * 调用方回退默认配置），严禁抛 500。
     */
    private static function pdo() {
        if (self::$pdo instanceof PDO) return self::$pdo;
        $file = self::path();
        if (!is_file($file)) return null;
        $head = @file_get_contents($file, false, null, 0, 16);
        if ($head !== "SQLite format 3\0") {
            @rename($file, $file . '.corrupt.' . date('YmdHis'));
            error_log('[PvConfigDb] config.db 头校验失败，已备份为 .corrupt 并回退默认配置');
            return null;
        }
        try {
            $pdo = new PDO('sqlite:' . $file, null, null, array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ));
            $pdo->exec('PRAGMA busy_timeout = 5000');
        } catch (Exception $e) {
            error_log('[PvConfigDb] config.db 打开失败（回退默认配置）：' . $e->getMessage());
            return null;
        }
        self::$pdo = $pdo;
        return $pdo;
    }

    /** 建表（幂等） */
    private static function ensureTable(PDO $pdo) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS config (
            ckey       TEXT PRIMARY KEY,
            cvalue     TEXT DEFAULT '',
            updated_at TEXT DEFAULT ''
        )");
    }

    /** 读取全部配置（缓存；未初始化/损坏 → 空数组） */
    private static function load($force = false) {
        if (self::$loaded && !$force) return;
        self::$loaded = true;
        self::$cache = array();
        $pdo = self::pdo();
        if (!$pdo) return;
        try {
            foreach ($pdo->query("SELECT ckey, cvalue FROM config")->fetchAll() as $r) {
                self::$cache[(string)$r['ckey']] = (string)$r['cvalue'];
            }
        } catch (Exception $e) {
            self::$cache = array();   // 表不存在等：视为空
        }
    }

    /** 读单键（缺省返回 $default） */
    public static function get($key, $default = '') {
        self::load();
        return array_key_exists($key, self::$cache) ? self::$cache[$key] : $default;
    }

    /** 全部键值（引导早期使用：一次性应用覆盖默认） */
    public static function all() {
        self::load();
        return self::$cache;
    }

    /** 写单键（首次写入自动创建 config.db 与表）；@return bool 成功 */
    public static function set($key, $value) {
        $pdo = self::pdo();
        if (!$pdo) {
            if (!is_dir(PV_DATA)) @mkdir(PV_DATA, 0775, true);
            try {
                $pdo = new PDO('sqlite:' . self::path(), null, null, array(
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ));
                $pdo->exec('PRAGMA busy_timeout = 5000');
                self::$pdo = $pdo;
            } catch (Exception $e) {
                error_log('[PvConfigDb] config.db 创建失败：' . $e->getMessage());
                return false;
            }
        }
        try {
            self::ensureTable($pdo);
            $st = $pdo->prepare("INSERT OR REPLACE INTO config(ckey,cvalue,updated_at) VALUES(?,?,?)");
            $st->execute(array((string)$key, (string)$value, gmdate('Y-m-d H:i:s')));
            self::load(true);
            return true;
        } catch (Exception $e) {
            error_log('[PvConfigDb] 写入失败（' . $key . '）：' . $e->getMessage());
            return false;
        }
    }

    /** 批量写；@return bool 全部成功 */
    public static function setMany(array $pairs) {
        $ok = true;
        foreach ($pairs as $k => $v) {
            if (!self::set($k, $v)) $ok = false;
        }
        return $ok;
    }

    /** 删除单键 */
    public static function remove($key) {
        $pdo = self::pdo();
        if (!$pdo) return true;
        try {
            $st = $pdo->prepare("DELETE FROM config WHERE ckey=?");
            $st->execute(array((string)$key));
            self::load(true);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /** 业务数据库文件路径（相对路径按 data/ 展开；缺省 data/pacs_viewer.db） */
    public static function dbPath() {
        $p = trim((string)self::get('db.path', ''));
        if ($p === '') $p = PV_DATA . '/pacs_viewer.db';
        elseif (strpos($p, '/') !== 0) $p = PV_DATA . '/' . $p;
        return $p;
    }

    /**
     * 时区解析：config.db timezone 优先，非法/缺失回退 $fallback；
     * 返回经 DateTimeZone 校验的合法时区名（存储恒为 UTC，时区仅显示口径）。
     */
    public static function timezone($fallback = 'Asia/Shanghai') {
        $tz = trim((string)self::get('timezone', ''));
        if ($tz === '') return $fallback;
        try {
            new DateTimeZone($tz);
            return $tz;
        } catch (Exception $e) {
            return $fallback;
        }
    }
}
