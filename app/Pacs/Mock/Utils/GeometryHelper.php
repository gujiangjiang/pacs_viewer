<?php
/**
 * ============================================================
 * app/Pacs/Mock/Utils/GeometryHelper.php — 解剖几何算子
 * ============================================================
 * 提供归一化坐标系下的椭圆 / 超椭圆判定、平滑插值、有向距离场（SDF）、
 * 多边形掩膜与形态学膨胀等几何工具，用于快速绘制平滑的器官 / 骨骼形状。
 *
 * 约定：所有坐标均为归一化坐标（0..1），不依赖像素尺寸。
 * ============================================================ */
class PvMockGeometryHelper {

    public static function clamp($v, $a, $b) { return $v < $a ? $a : ($v > $b ? $b : $v); }
    public static function lerp($a, $b, $t) { return $a + ($b - $a) * $t; }
    public static function smoothstep($edge0, $edge1, $x) {
        $t = self::clamp(($x - $edge0) / ($edge1 - $edge0 + 1e-9), 0.0, 1.0);
        return $t * $t * (3 - 2 * $t);
    }

    /** 椭圆「归一化半径平方」：<1 在内部，=1 在边界，>1 在外部 */
    public static function ellipseValue($nx, $ny, $cx, $cy, $rx, $ry) {
        $dx = ($nx - $cx) / ($rx + 1e-9);
        $dy = ($ny - $cy) / ($ry + 1e-9);
        return $dx * $dx + $dy * $dy;
    }

    /** 是否落在椭圆内（可带旋转角，弧度） */
    public static function inEllipse($nx, $ny, $cx, $cy, $rx, $ry, $angle = 0.0) {
        if ($angle != 0.0) {
            $c = cos($angle); $s = sin($angle);
            $dx = $nx - $cx; $dy = $ny - $cy;
            $rxp = $dx * $c + $dy * $s;
            $ryp = -$dx * $s + $dy * $c;
            $nx = $cx + $rxp; $ny = $cy + $ryp;
        }
        return self::ellipseValue($nx, $ny, $cx, $cy, $rx, $ry) <= 1.0;
    }

    /**
     * 椭圆近似有向距离场：负值在内部、正值为到边界的近似归一化距离。
     * 返回 0..1 边界处为 0，中心为 -1（近似）。
     */
    public static function ellipseField($nx, $ny, $cx, $cy, $rx, $ry) {
        $q = self::ellipseValue($nx, $ny, $cx, $cy, $rx, $ry);
        return sqrt($q) - 1.0;
    }

    /**
     * 帧间特征带：在 [a,b] 区间内平滑出现又消失，用于解剖结构随帧推进的
     * 生灭（如脑室、肺门、椎间盘）。
     * @return float 0..1
     */
    public static function band($p, $a, $b, $fade = 0.08) {
        if ($b <= $a) return 0.0;
        $fade = max(1e-4, min($fade, ($b - $a) / 2));
        return self::smoothstep($a, $a + $fade, $p) * (1.0 - self::smoothstep($b - $fade, $b, $p));
    }

    /** 到折线（脊柱等）的水平距离 */
    public static function distanceToPolyline(array $pts, $x, $y) {
        $min = 1e9;
        $n = count($pts);
        for ($i = 0; $i < $n - 1; $i++) {
            $x1 = $pts[$i][0]; $y1 = $pts[$i][1];
            $x2 = $pts[$i + 1][0]; $y2 = $pts[$i + 1][1];
            $dx = $x2 - $x1; $dy = $y2 - $y1;
            $len2 = $dx * $dx + $dy * $dy;
            $t = $len2 > 0 ? self::clamp((($x - $x1) * $dx + ($y - $y1) * $dy) / $len2, 0.0, 1.0) : 0.0;
            $px = $x1 + $t * $dx; $py = $y1 + $t * $dy;
            $d = sqrt(($x - $px) * ($x - $px) + ($y - $py) * ($y - $py));
            if ($d < $min) $min = $d;
        }
        return $min;
    }
}
