<?php
/**
 * ============================================================
 * app/Services/IconRenderer.php — 站点 / PWA 图标（代码绘制）
 * ============================================================
 * 不再使用任何预置图片：默认图标完全由 PHP GD 代码绘制（4 倍超采样后降采样），
 * 可按需生成任意尺寸（favicon / apple-touch / PWA / maskable）。
 * 管理员可在【管理设置 → 基础设置】上传自定义图标覆盖默认；未设置时自动回退
 * 到代码绘制的默认图标。自定义图标以 PNG 归一化后存放于运行时目录
 * data/uploads/icon.png（Web 根之外，不纳入版本管理，经路由下发）。
 * ============================================================ */
class PvIconRenderer {

    const SS = 4;   // 超采样倍数

    private static $bg     = array(0x0f, 0x17, 0x2a);   // 深色背景
    private static $ring   = array(0x0e, 0xa5, 0xe9);   // 青色扫描环
    private static $cross0 = array(0x34, 0xd3, 0x99);   // 医疗十字渐变上
    private static $cross1 = array(0x0e, 0xa5, 0xe9);   // 医疗十字渐变下

    public static function gdReady() { return extension_loaded('gd') && function_exists('imagecreatetruecolor'); }

    /* ---------------- 自定义图标 ---------------- */

    /** 通用上传目录（Web 根之外）：data/uploads/（按需创建） */
    public static function uploadDir() {
        $dir = PV_DATA . '/uploads';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir;
    }

    public static function customFile() { return PV_DATA . '/uploads/icon.png'; }
    public static function hasCustom() { return is_file(self::customFile()); }
    public static function version() { return max(1, (int)PvSettings::get('icon_version', '1')); }
    private static function bumpVersion() { PvSettings::set('icon_version', (string)(self::version() + 1)); }

    /** 保存上传的自定义图标（归一化为 PNG，最长边 512） */
    public static function saveCustom($tmpFile) {
        if (!self::gdReady()) throw new RuntimeException('服务器未启用 GD，无法处理图片');
        if (!is_uploaded_file($tmpFile) && !is_file($tmpFile)) throw new RuntimeException('上传文件无效');
        $data = @file_get_contents($tmpFile);
        if ($data === false || $data === '') throw new RuntimeException('无法读取上传文件');
        if (strlen($data) > 4 * 1024 * 1024) throw new RuntimeException('图标文件过大（限 4MB）');
        $src = @imagecreatefromstring($data);
        if (!$src) throw new RuntimeException('无法识别的图片格式（请上传 PNG / JPG / GIF / WebP）');
        $w = imagesx($src); $h = imagesy($src);
        $max = 512;
        $sc = min(1, $max / max(1, max($w, $h)));
        $nw = max(1, (int)round($w * $sc)); $nh = max(1, (int)round($h * $sc));
        $out = imagecreatetruecolor($nw, $nh);
        imagealphablending($out, false);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagealphablending($out, true);
        imagesavealpha($out, true);
        imagecopyresampled($out, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        self::uploadDir();
        $ok = imagepng($out, self::customFile());
        if (PHP_VERSION_ID < 80500) { imagedestroy($out); imagedestroy($src); }
        if (!$ok) throw new RuntimeException('图标写入失败，请确认 data/uploads/ 目录可写');
        self::bumpVersion();
    }

    public static function clearCustom() {
        if (self::hasCustom()) @unlink(self::customFile());
        self::bumpVersion();
    }

    /* ---------------- 输出 ---------------- */

    /** 生成指定尺寸 PNG 二进制（自定义优先，否则代码绘制） */
    public static function pngString($size) {
        $size = max(16, min(1024, (int)$size));
        if (!self::gdReady()) return self::fallbackPng();
        $out = self::blank($size, $size);
        if (self::hasCustom()) {
            $data = @file_get_contents(self::customFile());
            $src = $data !== false ? @imagecreatefromstring($data) : false;
            if ($src) {
                $w = imagesx($src); $h = imagesy($src);
                $sc = min($size / max(1, $w), $size / max(1, $h));
                $dw = max(1, (int)round($w * $sc)); $dh = max(1, (int)round($h * $sc));
                imagecopyresampled($out, $src, (int)(($size - $dw) / 2), (int)(($size - $dh) / 2), 0, 0, $dw, $dh, $w, $h);
                if (PHP_VERSION_ID < 80500) imagedestroy($src);
            } else {
                self::drawDefault($out, $size);
            }
        } else {
            self::drawDefault($out, $size);
        }
        ob_start();
        imagepng($out);
        $bin = ob_get_clean();
        if (PHP_VERSION_ID < 80500) imagedestroy($out);
        return $bin;
    }

    private static function blank($w, $h) {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true);
        imagesavealpha($im, true);
        return $im;
    }

    /* ---------------- 默认图标绘制 ---------------- */

    private static function drawDefault($out, $size) {
        $S = $size * self::SS;
        $im = self::blank($S, $S);

        $pad = (int)round($S * 0.031);
        self::roundedRect($im, $pad, $pad, $S - 2 * $pad, $S - 2 * $pad, (int)round($S * 0.203), self::$bg);

        // 扫描环（一圈小圆点拼出粗描边）
        $cx = $S / 2; $cy = $S / 2; $rr = $S * 0.293; $lw = (int)round($S * 0.035);
        $ring = imagecolorallocatealpha($im, self::$ring[0], self::$ring[1], self::$ring[2], 55);
        for ($i = 0; $i < 720; $i++) {
            $a = $i * M_PI / 360;
            imagefilledellipse($im, (int)round($cx + cos($a) * $rr), (int)round($cy + sin($a) * $rr), $lw, $lw, $ring);
        }

        // 医疗十字（纵向渐变）
        $arm = (int)round($S * 0.34);
        $th  = (int)round($S * 0.13);
        $rad = (int)round($th * 0.34);
        self::roundedRect($im, (int)round($cx - $th / 2), (int)round($cy - $arm / 2), $th, $arm, $rad, null, true);
        self::roundedRect($im, (int)round($cx - $arm / 2), (int)round($cy - $th / 2), $arm, $th, $rad, null, true);

        imagecopyresampled($out, $im, 0, 0, 0, 0, $size, $size, $S, $S);
        if (PHP_VERSION_ID < 80500) imagedestroy($im);
    }

    private static function roundedRect($im, $x, $y, $w, $h, $r, $rgb, $gradient = false) {
        $r = min($r, (int)floor(min($w, $h) / 2));
        if ($gradient) {
            $S = imagesy($im);
            for ($yy = $y; $yy < $y + $h; $yy++) {
                $t = $yy / max(1, $S - 1);
                $col = imagecolorallocatealpha($im,
                    self::lerp(self::$cross0[0], self::$cross1[0], $t),
                    self::lerp(self::$cross0[1], self::$cross1[1], $t),
                    self::lerp(self::$cross0[2], self::$cross1[2], $t), 0);
                $range = self::rrectRange($yy, $x, $y, $w, $h, $r);
                if ($range) imageline($im, $range[0], $yy, $range[1], $yy, $col);
            }
            return;
        }
        $col = imagecolorallocatealpha($im, $rgb[0], $rgb[1], $rgb[2], 0);
        imagefilledrectangle($im, $x + $r, $y, $x + $w - $r, $y + $h, $col);
        imagefilledrectangle($im, $x, $y + $r, $x + $w, $y + $h - $r, $col);
        foreach (array(array($x + $r, $y + $r), array($x + $w - $r, $y + $r), array($x + $r, $y + $h - $r), array($x + $w - $r, $y + $h - $r)) as $c) {
            imagefilledellipse($im, $c[0], $c[1], $r * 2, $r * 2, $col);
        }
    }

    private static function rrectRange($yy, $x, $y, $w, $h, $r) {
        if ($yy < $y || $yy >= $y + $h) return null;
        $xa = $x; $xb = $x + $w - 1;
        if ($yy < $y + $r) { $dy = ($y + $r) - $yy; $dx = (int)round(sqrt(max(0, $r * $r - $dy * $dy))); $xa = $x + $r - $dx; $xb = $x + $w - $r + $dx; }
        elseif ($yy >= $y + $h - $r) { $dy = $yy - ($y + $h - $r); $dx = (int)round(sqrt(max(0, $r * $r - $dy * $dy))); $xa = $x + $r - $dx; $xb = $x + $w - $r + $dx; }
        return array($xa, $xb);
    }

    private static function lerp($a, $b, $t) { return (int)round($a + ($b - $a) * $t); }

    /** GD 不可用时的兜底：1x1 透明 PNG */
    private static function fallbackPng() {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    }
}
