<?php
/**
 * ============================================================
 * app/Pacs/Mock/Utils/ProceduralNoise.php — 仿真数学算子
 * ============================================================
 * 提供确定性的伪随机发生器、Perlin 值噪声、分形叠加（fBm）、高斯平滑、
 * 高斯 / 瑞利（超声斑点）分布等数学工具。所有算子均以「种子」驱动，
 * 同一输入永远得到同一输出，保证多帧连续切片可复现。
 *
 * 设计：以闭包返回发生器，避免静态可变状态在并发请求间串扰。
 * ============================================================ */
class PvMockProceduralNoise {

    /** 将任意字符串种子折叠为 32 位无符号整数（FNV-1a） */
    public static function seed($str) {
        $h = 2166136261;
        $str = (string)$str;
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $h ^= ord($str[$i]);
            $h = ($h * 16777619) & 0xFFFFFFFF;
        }
        return $h;
    }

    /**
     * mulberry32 伪随机发生器。
     * @return callable 返回 [0,1) 浮点数
     */
    public static function rng($seed) {
        $a = (int)$seed & 0xFFFFFFFF;
        return function () use (&$a) {
            $a = ($a + 0x6D2B79F5) & 0xFFFFFFFF;
            $t = $a;
            $t = self::mul32($t ^ ($t >> 15), 1 | $t);
            $t = ($t + self::mul32($t ^ ($t >> 7), 61 | $t)) & 0xFFFFFFFF;
            $t = ($t ^ ($t >> 14)) & 0xFFFFFFFF;
            return $t / 4294967296.0;
        };
    }

    /** 32 位无符号模乘，避免 PHP 64 位整数溢出为浮点 */
    private static function mul32($a, $b) {
        $a = $a & 0xFFFFFFFF;
        $b = $b & 0xFFFFFFFF;
        $aLo = $a & 0xFFFF;
        $aHi = ($a >> 16) & 0xFFFF;
        return (($aLo * $b) + ((($aHi * $b) & 0xFFFF) << 16)) & 0xFFFFFFFF;
    }

    /**
     * 二维 Perlin 值噪声（双线性插值 + 平滑曲线）。
     * @param int|string $seed
     * @param int        $grid 网格分辨率
     * @return callable function($x, $y) → [0,1)
     */
    public static function perlin2D($seed, $grid = 64) {
        $grid = max(2, (int)$grid);
        $r = self::rng(self::seed($seed));
        $tab = array();
        $n = $grid * $grid;
        for ($i = 0; $i < $n; $i++) $tab[$i] = $r();
        return function ($x, $y) use (&$tab, $grid) {
            $x = $x * $grid; $y = $y * $grid;
            $xi = (int)floor($x); $yi = (int)floor($y);
            $xf = $x - $xi; $yf = $y - $yi;
            $x0 = (($xi % $grid) + $grid) % $grid; $y0 = (($yi % $grid) + $grid) % $grid;
            $x1 = ($x0 + 1) % $grid; $y1 = ($y0 + 1) % $grid;
            $v00 = $tab[$y0 * $grid + $x0]; $v10 = $tab[$y0 * $grid + $x1];
            $v01 = $tab[$y1 * $grid + $x0]; $v11 = $tab[$y1 * $grid + $x1];
            $sx = $xf * $xf * (3 - 2 * $xf);
            $sy = $yf * $yf * (3 - 2 * $yf);
            $a = $v00 + ($v10 - $v00) * $sx;
            $b = $v01 + ($v11 - $v01) * $sx;
            return $a + ($b - $a) * $sy;
        };
    }

    /** 分形布朗运动：多倍频 Perlin 叠加，返回近似 [0,1) */
    public static function fbm($noise, $x, $y, $octaves = 4) {
        $sum = 0.0; $amp = 0.5; $freq = 1.0;
        for ($i = 0; $i < $octaves; $i++) {
            $sum += $noise($x * $freq, $y * $freq) * $amp;
            $freq *= 2.0; $amp *= 0.5;
        }
        return $sum;
    }

    /**
     * 一维高斯卷积核（归一化）。
     * @return array 浮点数组
     */
    public static function gaussianKernel($sigma) {
        $sigma = max(0.1, (float)$sigma);
        $radius = (int)max(1, ceil($sigma * 3));
        $kernel = array();
        $sum = 0.0;
        for ($i = -$radius; $i <= $radius; $i++) {
            $v = exp(-($i * $i) / (2 * $sigma * $sigma));
            $kernel[$i] = $v; $sum += $v;
        }
        foreach ($kernel as $k => $v) $kernel[$k] = $v / $sum;
        return $kernel;
    }

    /**
     * 对二维浮点场执行可分离高斯模糊。
     * @param array $field 索引为 y*width + x 的浮点数组
     * @return array 模糊后的等长数组
     */
    public static function gaussianBlur(array $field, $width, $height, $sigma) {
        $k = self::gaussianKernel($sigma);
        $radius = max(array_keys($k));
        $tmp = array_fill(0, $width * $height, 0.0);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $acc = 0.0;
                for ($d = -$radius; $d <= $radius; $d++) {
                    $xx = $x + $d;
                    if ($xx < 0 || $xx >= $width) $xx = $x;
                    $acc += $field[$y * $width + $xx] * $k[$d];
                }
                $tmp[$y * $width + $x] = $acc;
            }
        }
        $out = array_fill(0, $width * $height, 0.0);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $acc = 0.0;
                for ($d = -$radius; $d <= $radius; $d++) {
                    $yy = $y + $d;
                    if ($yy < 0 || $yy >= $height) $yy = $y;
                    $acc += $tmp[$yy * $width + $x] * $k[$d];
                }
                $out[$y * $width + $x] = $acc;
            }
        }
        return $out;
    }

    /** 高斯分布采样（Box-Muller），均值 mean、标准差 std */
    public static function gaussian($rng, $mean = 0.0, $std = 1.0) {
        $u1 = max(1e-9, $rng()); $u2 = $rng();
        $z = sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
        return $mean + $z * $std;
    }

    /**
     * 瑞利（Rayleigh）分布采样，用于超声斑点噪声。
     * @param callable $rng
     * @param float $sigma 尺度参数
     */
    public static function rayleigh($rng, $sigma = 1.0) {
        $u = max(1e-9, $rng());
        return $sigma * sqrt(-2.0 * log($u));
    }

    /**
     * 生成「斑点乘性噪声」场：符合瑞利分布的幅度，叠加为乘性噪声。
     * @param int $seed
     * @param int $width
     * @param int $height
     * @param float $sigma
     * @return array 长度 width*height 的浮点场
     */
    public static function speckleField($seed, $width, $height, $sigma = 0.45) {
        $r = self::rng(self::seed($seed));
        $n = $width * $height;
        $out = array_fill(0, $n, 1.0);
        $scale = $sigma * sqrt(2.0 / M_PI);
        for ($i = 0; $i < $n; $i++) {
            $out[$i] = 1.0 + ($r() - 0.5) * 2.0 * $scale;
        }
        return $out;
    }
}
