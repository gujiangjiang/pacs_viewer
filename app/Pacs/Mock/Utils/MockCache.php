<?php
/**
 * ============================================================
 * app/Pacs/Mock/Utils/MockCache.php — 生成影像的两级缓存
 * ============================================================
 * 一级：APCu 共享内存（命中最快，跨请求共享；不可用时退化为进程内数组）；
 * 二级：磁盘目录 data/mock_cache/（跨进程持久，首次生成后长期复用，重启不丢）。
 * 读取顺序：内存 → 磁盘（命中则回填内存）；写入：内存 + 磁盘。
 *
 * 容量控制：
 *   · 内存层：默认取 apc.shm_size 的 75%，超出后按插入顺序淘汰最旧条目；
 *   · 磁盘层：索引文件 mock_cache/__idx.json 记录 [key, size, 时间]，超出
 *     DISK_MAX 或超过 DISK_TTL 未使用即从最旧开始淘汰，防止无限增长。
 * ============================================================ */
class PvMockCache {

    const PREFIX = 'pvmock:';
    const IDX = 'pvmock:__idx';
    const TTL = 3600;
    const DISK_DIR = 'mock_cache';
    const DISK_IDX = '__idx.json';
    const DISK_MAX = 536870912;   // 磁盘缓存上限 512MB
    const DISK_TTL = 604800;      // 磁盘条目最长保留 7 天

    private static $mem = array();       // 进程内兜底
    private static $memBytes = 0;
    private static $maxBytes = null;
    private static $diskIdx = null;      // 磁盘索引 [key, size, time]

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

    /** 读取磁盘索引（缺失时由现存文件重建一次） */
    private static function loadDiskIdx() {
        if (is_array(self::$diskIdx)) return self::$diskIdx;
        $file = self::diskDir() . '/' . self::DISK_IDX;
        $idx = array();
        if (is_file($file)) {
            $j = json_decode((string)@file_get_contents($file), true);
            if (is_array($j)) $idx = $j;
        }
        if (!$idx) {
            foreach ((array)@glob(self::diskDir() . '/*.bin') as $f) {
                $idx[] = array(basename($f, '.bin'), (int)@filesize($f), (int)@filemtime($f));
            }
        }
        self::$diskIdx = $idx;
        return $idx;
    }

    private static function saveDiskIdx() {
        @file_put_contents(self::diskDir() . '/' . self::DISK_IDX, json_encode(self::$diskIdx), LOCK_EX);
    }

    private static function storeDisk($key, $value) {
        if (self::DISK_MAX <= 0) return;
        $file = self::diskFile($key);
        if (!is_file($file) || @filesize($file) !== strlen($value)) {
            @file_put_contents($file, $value, LOCK_EX);
        }
        $idx = self::loadDiskIdx();
        $now = time();
        // 移除同键旧条目后追加到末尾（最新）
        $out = array();
        foreach ($idx as $e) { if ($e[0] !== $key) $out[] = $e; }
        $out[] = array($key, strlen($value), $now);

        // 1) TTL 淘汰（保留刚写入的）
        $cut = $now - self::DISK_TTL;
        $kept = array();
        foreach ($out as $e) {
            if ($e[0] !== $key && $e[2] < $cut) { @unlink(self::diskFile($e[0])); continue; }
            $kept[] = $e;
        }
        $out = $kept;

        // 2) 容量淘汰（从最旧开始，保留刚写入的）
        $total = 0; foreach ($out as $e) $total += $e[1];
        $i = 0;
        while ($total > self::DISK_MAX && $i < count($out)) {
            if ($out[$i][0] === $key) { $i++; continue; }
            @unlink(self::diskFile($out[$i][0]));
            $total -= $out[$i][1];
            array_splice($out, $i, 1);
        }

        self::$diskIdx = $out;
        self::saveDiskIdx();
    }

    private static function diskBytes() {
        $n = 0; foreach (self::loadDiskIdx() as $e) $n += $e[1];
        return $n;
    }

    private static function diskFiles() {
        return count(self::loadDiskIdx());
    }

    private static function clearDisk() {
        foreach ((array)@glob(self::diskDir() . '/*.bin') as $f) { @unlink($f); }
        @unlink(self::diskDir() . '/' . self::DISK_IDX);
        self::$diskIdx = array();
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
