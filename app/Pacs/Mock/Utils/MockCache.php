<?php
/**
 * ============================================================
 * app/Pacs/Mock/Utils/MockCache.php — 生成影像的两级缓存
 * ============================================================
 * 一级：APCu 共享内存（命中最快，跨请求共享；不可用时退化为进程内数组）；
 * 二级：磁盘目录 data/mock_cache/（跨进程持久，首次生成后长期复用，重启不丢）。
 * 读取顺序：内存 → 磁盘（命中则回填内存）；写入：内存 + 磁盘。
 *
 * 容量控制（内存层）：默认取 apc.shm_size 的 75%，超出后按插入顺序淘汰最旧条目。
 * ============================================================ */
class PvMockCache {

    const PREFIX = 'pvmock:';
    const IDX = 'pvmock:__idx';
    const TTL = 3600;
    const DISK_DIR = 'mock_cache';

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
            if ($ok) return $v;
        } elseif (isset(self::$mem[$key])) {
            return self::$mem[$key];
        }
        // 磁盘回退（跨进程持久）
        $file = self::diskFile($key);
        if (is_file($file)) {
            $v = @file_get_contents($file);
            if (is_string($v) && $v !== '') { self::storeMemory($key, $v); return $v; }
        }
        return null;
    }

    public static function set($key, $value) {
        if (!is_string($value) || $value === '') return false;
        self::storeMemory($key, $value);
        self::storeDisk($key, $value);
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
        self::clearDisk();
    }

    /** 缓存统计：{enabled, backend, count, bytes, max_bytes, disk_bytes, disk_files} */
    public static function stats() {
        if (self::enabled()) {
            $idx = self::index();
            return array(
                'enabled' => true,
                'backend' => 'APCu（共享内存）',
                'count' => count($idx),
                'bytes' => (int)array_sum($idx),
                'max_bytes' => self::maxBytes(),
                'disk_bytes' => self::diskBytes(),
                'disk_files' => self::diskFiles(),
            );
        }
        return array(
            'enabled' => false,
            'backend' => '进程内（APCu 不可用）',
            'count' => count(self::$mem),
            'bytes' => self::$memBytes,
            'max_bytes' => 0,
            'disk_bytes' => self::diskBytes(),
            'disk_files' => self::diskFiles(),
        );
    }

    /* ---------------- 内存层 ---------------- */

    private static function storeMemory($key, $value) {
        if (!is_string($value) || $value === '') return;
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
            return;
        }
        if (!isset(self::$mem[$key])) self::$memBytes += $len;
        self::$mem[$key] = $value;
    }

    private static function index() {
        if (!self::enabled()) return array();
        $idx = apcu_fetch(self::IDX, $ok);
        return ($ok && is_array($idx)) ? $idx : array();
    }

    /* ---------------- 磁盘层 ---------------- */

    public static function diskDir() {
        $dir = PV_DATA . '/' . self::DISK_DIR;
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir;
    }

    private static function diskFile($key) {
        $key = preg_replace('/[^a-f0-9]/i', '', (string)$key);
        if ($key === '') $key = md5((string)$key);
        return self::diskDir() . '/' . $key . '.bin';
    }

    private static function storeDisk($key, $value) {
        $file = self::diskFile($key);
        if (is_file($file) && @filesize($file) === strlen($value)) return;   // 已存在
        @file_put_contents($file, $value, LOCK_EX);
    }

    private static function diskBytes() {
        $n = 0;
        foreach ((array)@glob(self::diskDir() . '/*.bin') as $f) { $n += (int)@filesize($f); }
        return $n;
    }

    private static function diskFiles() {
        return count((array)@glob(self::diskDir() . '/*.bin'));
    }

    private static function clearDisk() {
        foreach ((array)@glob(self::diskDir() . '/*.bin') as $f) { @unlink($f); }
    }

    /* ---------------- 容量解析 ---------------- */

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
