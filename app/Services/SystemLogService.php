<?php
/**
 * app/Services/SystemLogService.php — 系统日志（PHP 服务器运行日志）
 *
 * 数据来源：开发服务器 / 生产服务器的运行日志文件（默认 data/.serve.log，
 * 由 tools/serve.sh 以 nohup 追加写入）。
 * 能力：尾部读取（最新在前）、一键清空（截断）、按「行数 / 天数」上限裁剪。
 *
 * 日志行可能为 frankenphp 的 JSON 行（含 "ts":<unix>）或普通文本行；
 * 时间列尽力解析，解析不到则留空（不阻断展示）。
 */
class PvSystemLogService {

    /** 系统日志文件路径 */
    public static function file() {
        return PV_DATA . '/.serve.log';
    }

    /** 是否存在日志文件 */
    public static function exists() {
        return is_file(self::file());
    }

    /* ---------------- 上限设置（键前缀 log_system） ---------------- */

    public static function maxCount() {
        $l = PvLogLimits::read('log_system');
        return $l['count'];
    }

    public static function maxDays() {
        $l = PvLogLimits::read('log_system');
        return $l['days'];
    }

    public static function limits() {
        return PvLogLimits::read('log_system');
    }

    /** 保存上限设置并立即裁剪 */
    public static function saveLimits($count, $days) {
        PvLogLimits::save('log_system', $count, $days);
        self::enforceLimits();
    }

    /* ---------------- 读取 ---------------- */

    /** 尾部读取最多 $maxBytes 字节，返回按时间倒序的日志行 */
    public static function lines($limit = 200, $maxBytes = 1048576) {
        $file = self::file();
        if (!is_file($file)) return array();
        $size = (int)@filesize($file);
        $limit = max(1, min(2000, (int)$limit));
        $fh = @fopen($file, 'rb');
        if (!$fh) return array();
        $start = ($size > $maxBytes) ? ($size - $maxBytes) : 0;
        if ($start > 0) {
            fseek($fh, $start);
            fgets($fh);   // 丢弃可能被截断的首行
        }
        $raw = array();
        while (($ln = fgets($fh)) !== false) {
            $ln = rtrim($ln, "\r\n");
            if ($ln !== '') $raw[] = $ln;
        }
        fclose($fh);
        // 最新在前
        $raw = array_reverse($raw);
        if (count($raw) > $limit) $raw = array_slice($raw, 0, $limit);
        $out = array();
        foreach ($raw as $ln) {
            $out[] = array('time' => self::parseTime($ln), 'text' => $ln);
        }
        return $out;
    }

    /** 从一行日志尽力解析时间（Y-m-d H:i:s）；失败返回空串 */
    private static function parseTime($line) {
        $line = (string)$line;
        if ($line !== '' && $line[0] === '{') {
            if (preg_match('/"ts"\s*:\s*([0-9]+(?:\.[0-9]+)?)/', $line, $m)) {
                $ts = (float)$m[1];
                if ($ts > 0) return date('Y-m-d H:i:s', (int)$ts);
            }
            if (preg_match('/"(?:time|timestamp)"\s*:\s*"([^"]+)"/', $line, $m)) {
                $t = strtotime($m[1]);
                if ($t) return date('Y-m-d H:i:s', $t);
            }
        }
        if (preg_match('/(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/', $line, $m)) {
            $t = strtotime(str_replace('T', ' ', $m[1]));
            if ($t) return date('Y-m-d H:i:s', $t);
        }
        return '';
    }

    /** 估算行数（按 1MB 抽样外推，避免整文件扫描） */
    public static function approxCount() {
        $file = self::file();
        if (!is_file($file)) return 0;
        $size = (int)@filesize($file);
        if ($size <= 0) return 0;
        $fh = @fopen($file, 'rb');
        if (!$fh) return 0;
        $sample = fread($fh, 262144);
        fclose($fh);
        $n = substr_count((string)$sample, "\n");
        if ($n <= 0) return 1;
        return (int)round($n * ($size / max(1, strlen((string)$sample))));
    }

    /* ---------------- 清空 / 裁剪 ---------------- */

    public static function clear() {
        $file = self::file();
        if (!is_file($file)) return true;
        return @file_put_contents($file, '') !== false;
    }

    /**
     * 按上限裁剪文件：天数上限先删超期行，行数上限再保留最新 N 行。
     * 采用整体重写（日志文件通常不大），避免复杂的偏移计算。
     */
    public static function enforceLimits() {
        $file = self::file();
        if (!is_file($file)) return;
        $count = self::maxCount();
        $days = self::maxDays();
        if ($count <= 0 && $days <= 0) return;
        $raw = @file($file, FILE_IGNORE_NEW_LINES);
        if (!is_array($raw) || !$raw) return;
        if ($days > 0) {
            $cut = time() - $days * 86400;
            $raw = array_values(array_filter($raw, function ($ln) use ($cut) {
                $t = self::parseTime($ln);
                if ($t === '') return true;   // 无法解析时间的行保留
                return strtotime($t) >= $cut;
            }));
        }
        if ($count > 0 && count($raw) > $count) {
            $raw = array_slice($raw, -$count);
        }
        @file_put_contents($file, implode("\n", $raw) . (count($raw) ? "\n" : ""));
    }
}
