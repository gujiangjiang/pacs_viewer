<?php
/**
 * ============================================================
 * app/Services/StorageService.php — 运行时存储统计与清理
 * ============================================================
 * 汇总数据库、上传文件、内存缓存、会话等占用，供管理后台「存储情况」查询；
 * 并提供上传文件与缓存区的一键清空。
 * ============================================================ */
class PvStorageService {

    /** 全部存储统计 */
    public static function stats() {
        return array(
            'db'       => self::dbStats(),
            'uploads'  => self::dirStats(PV_DATA . '/uploads'),
            'cache'    => PvMockCache::stats(),
            'legacy'   => self::dirStats(PV_DATA . '/mock_cache'),
            'session'  => self::dirStats(PV_DATA . '/session'),
        );
    }

    /** 数据库文件占用（含 WAL / SHM 旁文件） */
    public static function dbStats() {
        $files = array(PV_DATA . '/pacs_viewer.db', PV_DATA . '/pacs_viewer.db-wal', PV_DATA . '/pacs_viewer.db-shm');
        $bytes = 0;
        foreach ($files as $f) { if (is_file($f)) $bytes += (int)@filesize($f); }
        return array('bytes' => $bytes, 'path' => 'data/pacs_viewer.db');
    }

    /** 清空全部上传文件与记录 */
    public static function clearUploads() {
        $removed = self::removeDirContents(PV_DATA . '/uploads');
        $records = 0;
        try { $records = (int)PvDatabase::exec("DELETE FROM uploads"); } catch (Exception $e) { $records = 0; }
        return array('files' => $removed, 'records' => $records);
    }

    /** 清空内存缓存与遗留的磁盘缓存 */
    public static function clearCache() {
        PvMockCache::clear();
        $removed = self::removeDirContents(PV_DATA . '/mock_cache');
        return array('files' => $removed);
    }

    /* ---------------- 内部工具 ---------------- */

    private static function dirStats($dir) {
        if (!is_dir($dir)) return array('bytes' => 0, 'files' => 0);
        $bytes = 0; $files = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $f) {
            if ($f->isFile()) { $bytes += $f->getSize(); $files++; }
        }
        return array('bytes' => $bytes, 'files' => $files);
    }

    /** 递归删除目录内容但保留目录本身，返回删除的文件数 */
    private static function removeDirContents($dir) {
        if (!is_dir($dir)) return 0;
        $removed = 0;
        $items = @scandir($dir);
        if (!is_array($items)) return 0;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $removed += self::removeDirContents($path);
                @rmdir($path);
            } elseif (is_file($path)) {
                if (@unlink($path)) $removed++;
            }
        }
        return $removed;
    }
}
