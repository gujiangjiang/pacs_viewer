<?php
/**
 * ============================================================
 * app/Support/Cache.php — 轻量响应缓存（APCu，短 TTL）
 * ============================================================
 * 供检索 / 调阅等只读响应在高并发下复用，减少对远程 PACS 的重复调用。
 * APCu 不可用时退化为「进程内数组」。键中应包含数据来源指纹，来源变更时自然失效。
 * ============================================================ */
class PvCache {

    const PREFIX = 'pvcache:';

    private static $mem = array();

    /** 当前请求是否可用 APCu */
    public static function enabled() {
        return function_exists('apcu_fetch') && function_exists('apcu_store');
    }

    public static function get($key) {
        $key = (string)$key;
        if (self::enabled()) {
            $v = apcu_fetch(self::PREFIX . $key, $ok);
            if ($ok) return $v;
        }
        return array_key_exists($key, self::$mem) ? self::$mem[$key] : null;
    }

    /**
     * @param string $key
     * @param mixed  $value
     * @param int    $ttl 秒
     */
    public static function set($key, $value, $ttl = 20) {
        $key = (string)$key;
        $ttl = max(1, (int)$ttl);
        if (self::enabled()) apcu_store(self::PREFIX . $key, $value, $ttl);
        self::$mem[$key] = $value;
    }
}
