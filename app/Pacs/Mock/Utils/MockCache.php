<?php
/**
 * ============================================================
 * app/Pacs/Mock/Utils/MockCache.php — 生成影像的两级缓存
 * ============================================================
 * 一级：APCu 共享内存（命中最快，跨请求共享；不可用时退化为进程内数组）；
 * 二级：磁盘目录 data/mock_cache/（跨进程持久，首次生成后长期复用，重启不丢）。
 * 读取顺序：内存 → 磁盘（命中则回填内存）；写入：内存 + 磁盘。
 *
 * 容量控制（均可在管理端「存储情况 → 设置」中配置，留空表示不限制）：
 *   · 内存层：超过「缓存大小」上限后按插入顺序淘汰最旧条目；
 *   · 磁盘层：索引文件 mock_cache/__idx.json 记录 [key, size, 时间]，超过
 *     容量上限或超过日期上限即从最旧开始淘汰，防止无限增长；
 *   · 日期上限为内存层与磁盘层共享设置，谁先满足执行谁。
 * ============================================================ */
class PvMockCache {

    const PREFIX = 'pvmock:';
    const IDX = 'pvmock:__idx';
    const TTL = 3600;
    const DISK_DIR = 'mock_cache';
    const DISK_IDX = '__idx.json';

    private static $mem = array();       // 进程内兜底
    private static $memBytes = 0;
    private static $maxBytes = null;
    private static $diskIdx = null;      // 磁盘索引 [key, size, time]

    /** 运行环境是否提供 APCu 扩展 */
    public static function apcuAvailable() {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    /** 是否启用 APCu 内存缓存（扩展可用且管理员未关闭） */
    public static function apcuEnabled() {
        if (!self::apcuAvailable()) return false;
        return (string)PvSettings::get('cache_apcu_enabled', '1') === '1';
    }

    /** 是否启用磁盘缓存 */
    public static function diskEnabled() {
        return (string)PvSettings::get('cache_disk_enabled', '1') === '1';
    }

    /** 当前请求是否可用 APCu 共享内存（兼容旧调用） */
    public static function enabled() {
        return self::apcuEnabled();
    }

    public static function get($key) {
        if (self::enabled()) {
            $v = apcu_fetch(self::PREFIX . $key, $ok);
            if ($ok) return $v;
        } elseif (isset(self::$mem[$key])) {
            return self::$mem[$key];
        }
        // 磁盘回退（跨进程持久；管理员关闭硬盘缓存时不再读取）
        if (!self::diskEnabled()) return null;
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
        if (self::diskEnabled()) self::storeDisk($key, $value);
        return true;
    }

    public static function clear() {
        // 无论开关状态，均清除可能残留的 APCu 条目
        if (self::apcuAvailable()) {
            $idx = self::index();
            foreach (array_keys($idx) as $k) apcu_delete(self::PREFIX . $k);
            apcu_delete(self::IDX);
        }
        self::$mem = array();
        self::$memBytes = 0;
        self::clearDisk();
    }

    /** 缓存统计：{enabled, backend, count, bytes, max_bytes, ttl_seconds, disk_*} */
    public static function stats() {
        $common = array(
            'max_bytes'   => self::maxBytes(),
            'ttl_seconds' => self::ttlSeconds(),
            'disk_enabled' => self::diskEnabled(),
            'disk_bytes'  => self::diskBytes(),
            'disk_files'  => self::diskFiles(),
            'disk_max_bytes' => self::diskMax(),
            'disk_ttl_seconds' => self::diskTtl(),
        );
        if (self::apcuEnabled()) {
            $idx = self::index();
            return array_merge($common, array(
                'enabled' => true,
                'backend' => 'APCu（共享内存）',
                'count' => count($idx),
                'bytes' => (int)array_sum($idx),
            ));
        }
        return array_merge($common, array(
            'enabled' => false,
            'backend' => self::apcuAvailable() ? '进程内（APCu 已关闭）' : '进程内（APCu 不可用）',
            'count' => count(self::$mem),
            'bytes' => self::$memBytes,
        ));
    }

    /* ---------------- 内存层 ---------------- */

    private static function storeMemory($key, $value) {
        if (!is_string($value) || $value === '') return;
        $len = strlen($value);
        if (self::apcuEnabled()) {
            $idx = self::index();
            apcu_store(self::PREFIX . $key, $value, self::ttlSeconds());
            $idx[$key] = $len;
            $max = self::maxBytes();
            $total = array_sum($idx);
            if ($max > 0 && $total > $max) {          // max<=0 表示不限制，不做容量淘汰
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
        if (!self::apcuAvailable()) return array();
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

        // 1) TTL 淘汰（保留刚写入的；<=0 表示不限制日期）
        $ttl = self::diskTtl();
        if ($ttl > 0) {
            $cut = $now - $ttl;
            $kept = array();
            foreach ($out as $e) {
                if ($e[0] !== $key && $e[2] < $cut) { @unlink(self::diskFile($e[0])); continue; }
                $kept[] = $e;
            }
            $out = $kept;
        }

        // 2) 容量淘汰（从最旧开始，保留刚写入的；<=0 表示不限制容量）
        $max = self::diskMax();
        if ($max > 0) {
            $total = 0; foreach ($out as $e) $total += $e[1];
            $i = 0;
            while ($total > $max && $i < count($out)) {
                if ($out[$i][0] === $key) { $i++; continue; }
                @unlink(self::diskFile($out[$i][0]));
                $total -= $out[$i][1];
                array_splice($out, $i, 1);
            }
        }

        self::$diskIdx = $out;
        self::saveDiskIdx();
    }

    /** 按当前设置立即执行清理：关闭的层清空，开启的层按容量 / 日期上限淘汰 */
    public static function enforceLimits() {
        if (!self::apcuEnabled()) {
            if (self::apcuAvailable()) {
                $idx = self::index();
                foreach (array_keys($idx) as $k) apcu_delete(self::PREFIX . $k);
                apcu_delete(self::IDX);
            }
            self::$mem = array();
            self::$memBytes = 0;
        } else {
            self::trimMemory();
        }
        if (!self::diskEnabled()) {
            self::clearDisk();
        } else {
            self::trimDisk();
        }
    }

    /** 按内存容量上限淘汰最旧条目（<=0 不限制） */
    private static function trimMemory() {
        if (!self::apcuEnabled()) return;
        $max = self::maxBytes();
        if ($max <= 0) return;
        $idx = self::index();
        $total = array_sum($idx);
        if ($total <= $max) return;
        foreach (array_keys($idx) as $old) {
            if ($total <= $max) break;
            $total -= $idx[$old];
            apcu_delete(self::PREFIX . $old);
            unset($idx[$old]);
        }
        apcu_store(self::IDX, $idx, 0);
    }

    /** 按磁盘容量 / 日期上限淘汰最旧文件 */
    private static function trimDisk() {
        $idx = self::loadDiskIdx();
        if (!$idx) return;
        $now = time();
        $ttl = self::diskTtl();
        $max = self::diskMax();
        $out = array(); $total = 0;
        // 索引按写入时间自然有序：先丢弃缺失文件与超期项，再从最旧删到容量以内
        foreach ($idx as $e) {
            if (!is_file(self::diskFile($e[0]))) continue;                       // 文件已不存在
            if ($ttl > 0 && $e[2] < $now - $ttl) { @unlink(self::diskFile($e[0])); continue; }
            $out[] = $e; $total += $e[1];
        }
        while ($max > 0 && $total > $max && $out) {
            $e = array_shift($out);
            @unlink(self::diskFile($e[0]));
            $total -= $e[1];
        }
        self::$diskIdx = array_values($out);
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

    /* ---------------- 容量 / 日期解析（均为可选项，空即不限制） ---------------- */

    /** 内存缓存容量上限：设置值（字节）>0 生效，留空表示不限制 */
    private static function maxBytes() {
        if (self::$maxBytes !== null) return self::$maxBytes;
        $v = (int)PvSettings::get('cache_max_bytes', '');
        self::$maxBytes = $v > 0 ? $v : 0;
        return self::$maxBytes;
    }

    /** 磁盘缓存容量上限：设置值（字节）>0 生效，留空表示不限制 */
    private static function diskMax() {
        $v = (int)PvSettings::get('cache_disk_max_bytes', '');
        return $v > 0 ? $v : 0;
    }

    /**
     * 共享日期上限（秒）：APCu 与磁盘共用同一设置；
     * 留空表示不限制（APCu TTL=0 永不过期，磁盘不做日期淘汰）。
     */
    public static function ttlSeconds() {
        $days = (int)PvSettings::get('cache_max_days', '');
        return $days > 0 ? $days * 86400 : 0;
    }

    /** 磁盘层日期上限（与内存层共享设置） */
    private static function diskTtl() {
        return self::ttlSeconds();
    }
}
