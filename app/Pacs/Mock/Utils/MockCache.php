<?php
/**
 * ============================================================
 * app/Pacs/Mock/Utils/MockCache.php — 生成影像的内存缓存
 * ============================================================
 * 以 APCu 共享内存存放已生成的标准 DICOM 字节流，避免磁盘反复读写并提升
 * 命中速度；APCu 不可用时退化为「进程内数组」（仅当次请求内有效）。
 *
 * 容量控制：默认取 apc.shm_size 的 75%，超出后按插入顺序淘汰最旧条目。
 * ============================================================ */
class PvMockCache {

    const PREFIX = 'pvmock:';
    const IDX = 'pvmock:__idx';
    const TTL = 3600;

    private static $mem = array();       // 进程内兜底
    private static $memBytes = 0;
    private static $maxBytes = null;

    /** 当前请求是否可用 APCu 共享内存 */
    public static function enabled() {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    public static function get($key) {
        if (self::enabled()) {
            $v = apcu_fetch(self::PREFIX . $key, $ok);
            return $ok ? $v : null;
        }
        return isset(self::$mem[$key]) ? self::$mem[$key] : null;
    }

    public static function set($key, $value) {
        if (!is_string($value) || $value === '') return false;
        $len = strlen($value);

        if (self::enabled()) {
            $idx = self::index();
            apcu_store(self::PREFIX . $key, $value, self::TTL);
            $idx[$key] = $len;

            $max = self::maxBytes();
            $total = array_sum($idx);
            if ($total > $max) {
                foreach (array_keys($idx) as $old) {
                    if ($total <= $max) break;
                    if ($old === $key) continue;              // 保留刚写入的条目
                    $total -= $idx[$old];
                    unset($idx[$old]);
                    apcu_delete(self::PREFIX . $old);
                }
            }
            apcu_store(self::IDX, $idx, 0);
            return true;
        }

        if (!isset(self::$mem[$key])) self::$memBytes += $len;
        self::$mem[$key] = $value;
        return true;
    }

    public static function clear() {
        if (self::enabled()) {
            $idx = self::index();
            foreach (array_keys($idx) as $k) apcu_delete(self::PREFIX . $k);
            apcu_delete(self::IDX);
        }
        self::$mem = array();
        self::$memBytes = 0;
    }

    /** 缓存统计：{enabled, backend, count, bytes, max_bytes} */
    public static function stats() {
        if (self::enabled()) {
            $idx = self::index();
            return array(
                'enabled' => true,
                'backend' => 'APCu（共享内存）',
                'count' => count($idx),
                'bytes' => (int)array_sum($idx),
                'max_bytes' => self::maxBytes(),
            );
        }
        return array(
            'enabled' => false,
            'backend' => '进程内（APCu 不可用）',
            'count' => count(self::$mem),
            'bytes' => self::$memBytes,
            'max_bytes' => 0,
        );
    }

    private static function index() {
        if (!self::enabled()) return array();
        $idx = apcu_fetch(self::IDX, $ok);
        return ($ok && is_array($idx)) ? $idx : array();
    }

    private static function maxBytes() {
        if (self::$maxBytes !== null) return self::$maxBytes;
        $bytes = self::parseSize(ini_get('apc.shm_size'));
        if ($bytes <= 0) $bytes = 33554432;             // 缺省 32MB
        self::$maxBytes = (int)($bytes * 0.75);
        return self::$maxBytes;
    }

    private static function parseSize($s) {
        $s = trim((string)$s);
        if ($s === '') return 0;
        $unit = strtoupper(substr($s, -1));
        $n = (int)$s;
        if ($unit === 'G') return $n * 1073741824;
        if ($unit === 'M') return $n * 1048576;
        if ($unit === 'K') return $n * 1024;
        return $n;
    }
}
