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

    /** 超椭圆（|x|^n + |y|^n = 1），n=2 即椭圆，n 越大越接近矩形 */
    public static function inSuperellipse($nx, $ny, $cx, $cy, $rx, $ry, $n = 2.0) {
        $dx = abs(($nx - $cx) / ($rx + 1e-9));
        $dy = abs(($ny - $cy) / ($ry + 1e-9));
        return (pow($dx, $n) + pow($dy, $n)) <= 1.0;
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

    /** 多椭圆并集（器官复合轮廓） */
    public static function inUnion(array $shapes, $nx, $ny) {
        foreach ($shapes as $s) {
            $cx = $s[0]; $cy = $s[1]; $rx = $s[2]; $ry = $s[3];
            $ang = isset($s[4]) ? $s[4] : 0.0;
            if (self::inEllipse($nx, $ny, $cx, $cy, $rx, $ry, $ang)) return true;
        }
        return false;
    }

    /** 两个椭圆的最大值（交集） */
    public static function inIntersection(array $shapes, $nx, $ny) {
        foreach ($shapes as $s) {
            $cx = $s[0]; $cy = $s[1]; $rx = $s[2]; $ry = $s[3];
            $ang = isset($s[4]) ? $s[4] : 0.0;
            if (!self::inEllipse($nx, $ny, $cx, $cy, $rx, $ry, $ang)) return false;
        }
        return true;
    }

    /**
     * 平滑多边形插值：在顶点间做 Catmull-Rom 采样，得到平滑闭合轮廓点集。
     * @param array $pts [[x,y], ...]
     * @param int   $samplesPerSeg 每段采样数
     * @return array 采样后的点集
     */
    public static function smoothPolygon(array $pts, $samplesPerSeg = 8) {
        $n = count($pts);
        if ($n < 3) return $pts;
        $out = array();
        for ($i = 0; $i < $n; $i++) {
            $p0 = $pts[($i - 1 + $n) % $n];
            $p1 = $pts[$i];
            $p2 = $pts[($i + 1) % $n];
            $p3 = $pts[($i + 2) % $n];
            for ($t = 0; $t < $samplesPerSeg; $t++) {
                $u = $t / $samplesPerSeg;
                $out[] = array(self::catmull($p0[0], $p1[0], $p2[0], $p3[0], $u), self::catmull($p0[1], $p1[1], $p2[1], $p3[1], $u));
            }
        }
        return $out;
    }

    private static function catmull($p0, $p1, $p2, $p3, $t) {
        $t2 = $t * $t; $t3 = $t2 * $t;
        return 0.5 * ((2 * $p1) + (-$p0 + $p2) * $t + (2 * $p0 - 5 * $p1 + 4 * $p2 - $p3) * $t2 + (-$p0 + 3 * $p1 - 3 * $p2 + $p3) * $t3);
    }

    /** 判断点是否在平滑多边形内（射线法） */
    public static function inPolygon(array $pts, $x, $y) {
        $inside = false;
        $n = count($pts);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $pts[$i][0]; $yi = $pts[$i][1];
            $xj = $pts[$j][0]; $yj = $pts[$j][1];
            if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi + 1e-12) + $xi)) {
                $inside = !$inside;
            }
        }
        return $inside;
    }

    /**
     * 扇形（凸阵）视野判定，用于超声。
     * @param float $nx,$ny 归一化坐标
     * @param float $cx,$cy 顶点位置
     * @param float $radius 归一化最大深度
     * @param float $halfAngle 半张角（弧度）
     * @return bool
     */
    public static function inSector($nx, $ny, $cx, $cy, $radius, $halfAngle) {
        $dx = $nx - $cx; $dy = $ny - $cy;
        $r = sqrt($dx * $dx + $dy * $dy);
        if ($r > $radius || $r < 1e-6) return false;
        $ang = abs(atan2($dx, $dy));
        return $ang <= $halfAngle;
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
