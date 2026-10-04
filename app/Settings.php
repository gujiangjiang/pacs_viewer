<?php
/**
 * app/Settings.php — 管理设置读写（settings 表）
 * 仅作用于本工具自身数据库，与门诊主系统无任何关联。
 */
class PvSettings {

    private static $cache = null;

    private static function load() {
        if (is_array(self::$cache)) return;
        self::$cache = array();
        foreach (PvDatabase::q("SELECT skey, svalue FROM settings") as $r) {
            self::$cache[$r['skey']] = $r['svalue'];
        }
    }

    public static function get($key, $default = '') {
        self::load();
        return array_key_exists($key, self::$cache) ? self::$cache[$key] : $default;
    }

    public static function set($key, $value) {
        self::load();
        // skey 为主键：单语句 upsert，省去先查后写
        PvDatabase::exec("INSERT OR REPLACE INTO settings(skey,svalue) VALUES(?,?)", array((string)$key, (string)$value));
        self::$cache[$key] = (string)$value;
    }

    public static function all() { self::load(); return self::$cache; }

    /** 是否已完成首次运行安装（据此决定是否强制进入安装向导） */
    public static function isInstalled() {
        return (string)self::get('installed', '0') === '1';
    }

    /** 批量保存（仅允许已知键，调用方过滤） */
    public static function saveMany(array $pairs) {
        foreach ($pairs as $k => $v) { self::set($k, $v); }
    }
}
