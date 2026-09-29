<?php
/**
 * ============================================================
 * app/Pacs/Mock/Generators/US/CardiacUS.php — 心脏超声切面（cine）
 * ============================================================
 * 扇形声束：胸骨旁左室长轴 / 心尖四腔样切面。心肌中等回声、心腔血液无回声、
 * 瓣膜强回声、心包线状回声，随帧呈现心脏收缩 / 舒张的动态改变。
 * ============================================================ */
class PvMockCardiacUS extends PvMockAbstractUS {

    protected function initUS() {
        $this->bodyPartExamined = 'HEART';
        $this->seriesDescription = 'US Cine';
        $this->frameCount = 24;
        $this->sliceThickness = 0.0;
        $this->spacingBetweenSlices = 0.0;
        $this->rowSpacing = 0.35;
        $this->colSpacing = 0.35;
        $this->noise = PvMockProceduralNoise::perlin2D($this->seed . '|cardus', 64);
    }

    protected function sample($nx, $ny, $p, $x, $y, $i) {
        $G = 'PvMockGeometryHelper';
        $noise = $this->noise;
        list($in, $depth, $ang) = $this->beam($nx, $ny, 0.5, -0.08, 1.10, 0.60);
        if (!$in) return 0;

        $beat = sin(2 * M_PI * $p);                 // 收缩 / 舒张周期
        $contract = 1.0 - 0.12 * max(0.0, $beat);   // 收缩期心腔缩小
        $gain = 210.0 * exp(-1.25 * $depth);
        $grain = PvMockProceduralNoise::fbm($noise, $nx * 5, $ny * 5, 3);

        /* 组织背景 / 胸壁 */
        $echo = 60.0 + 25.0 * $grain;

        /* 左心室心肌环（椭圆环） */
        $lv = $G::ellipseValue($nx, $ny, 0.50, 0.58, 0.155 * $contract, 0.185 * $contract);
        if ($lv < 1.0) {
            $innerLv = $G::ellipseValue($nx, $ny, 0.50, 0.58, 0.115 * $contract, 0.140 * $contract);
            if ($innerLv < 1.0) {
                $echo = 12.0 + 8.0 * $grain;                     // 左心室腔（血液无回声）
            } else {
                $echo = 120.0 + 35.0 * $grain;                   // 心肌
            }
        }
        /* 右心室腔（前方较小） */
        if ($G::ellipseValue($nx, $ny, 0.455, 0.320, 0.085 * $contract, 0.075 * $contract) < 1.0) {
            $echo = 14.0 + 8.0 * $grain;
        }
        /* 左心房（后方） */
        if ($G::ellipseValue($nx, $ny, 0.615, 0.360, 0.075, 0.070) < 1.0) {
            $echo = 12.0 + 8.0 * $grain;
        }
        /* 室间隔 */
        if (abs($nx - 0.50 + ($ny - 0.45) * 0.10) < 0.022 && $ny > 0.30 && $ny < 0.60) {
            $echo = 135.0 + 30.0 * $grain;
        }
        /* 二尖瓣 / 主动脉瓣：强回声开放移动 */
        $valveOpen = 0.55 + 0.45 * max(0.0, $beat);
        $mvY = 0.455 - 0.030 * $valveOpen;
        if (abs($ny - $mvY) < 0.010 && abs($nx - 0.50) < 0.13 * $valveOpen) {
            $echo = 225.0;
        }
        if (abs($ny - 0.430) < 0.008 && abs($nx - 0.585) < 0.035) $echo = 215.0;   // 主动脉瓣
        /* 心包线状强回声 */
        if (abs($lv - 1.0) < 0.010) $echo = 195.0;

        $echo *= $this->speckle($x, $y, $i);
        $val = $echo * ($gain / 210.0);
        $val *= (1.0 - 0.35 * $ang * $ang);
        return $val;
    }
}
