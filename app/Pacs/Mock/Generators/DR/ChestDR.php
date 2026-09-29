<?php
/**
 * ============================================================
 * app/Pacs/Mock/Generators/DR/ChestDR.php — 立位后前位胸片投影
 * ============================================================
 * 单幅 1024×1024 灰度投影：X 线穿透人体后的密度叠加 —— 双肺野充气透亮，
 * 锁骨、肋骨骨小梁交错，心影纵隔重叠带，双侧膈肌与肋膈角清晰锐利。
 * 数值为衰减强度（越高越白，MONOCHROME2）。
 * ============================================================ */
class PvMockChestDR extends PvMockAbstractGenerator {

    private $noise;

    public function __construct($seed = 'mock', $weight = 'T1') {
        $this->seed = (string)$seed;
        $this->modality = 'DR';
        $this->orientation = 'PA';
        $this->bodyPartExamined = 'CHEST';
        $this->seriesDescription = 'PA Chest';
        $this->frameCount = 1;
        $this->rows = 1024;
        $this->cols = 1024;
        $this->sliceThickness = 0.0;
        $this->spacingBetweenSlices = 0.0;
        $this->rowSpacing = 0.35;
        $this->colSpacing = 0.35;
        $this->bitsStored = 12;
        $this->highBit = 11;
        $this->windowCenter = 1500.0;
        $this->windowWidth = 3000.0;
        $this->noise = PvMockProceduralNoise::perlin2D($this->seed . '|chestdr', 64);
    }

    protected function sample($nx, $ny, $p, $x, $y, $i) {
        $G = 'PvMockGeometryHelper';
        $noise = $this->noise;
        $grain = PvMockProceduralNoise::fbm($noise, $nx * 4, $ny * 4, 3);
        $clamp = function ($v) { return $v < 0 ? 0 : ($v > 4095 ? 4095 : (int)$v); };

        /* 体表轮廓 */
        $body = $G::ellipseValue($nx, $ny, 0.5, 0.52, 0.40, 0.47);
        if ($body > 1.0) return 0;                                   // 背景空气漆黑

        /* 基础软组织衰减 */
        $att = 1200 + 200 * $grain;
        if ($body > 0.90) $att = 950 + 250 * $grain;                 // 胸廓边缘软组织稍薄

        /* 双肺野：透亮（低衰减）+ 肺纹理 */
        $lL = $G::ellipseField($nx, $ny, 0.325, 0.440, 0.165, 0.255);
        $lR = $G::ellipseField($nx, $ny, 0.675, 0.440, 0.165, 0.255);
        $lung = min($lL, $lR);
        if ($lung < 0 && $body < 0.95) {
            $att = 520 + 130 * PvMockProceduralNoise::fbm($noise, $nx * 3, $ny * 3, 4);
            $vess = PvMockProceduralNoise::fbm($noise, $nx * 10, $ny * 10, 3);
            if ($vess > 0.66) $att = 850 + 250 * $vess;               // 肺血管纹理
            if (abs($ny - (0.42 + 0.18 * ($nx - 0.5))) < 0.004) $att = 1000;  // 叶间裂
        }

        /* 纵隔与心影（中央重叠带） */
        if (abs($nx - 0.5) < 0.075 && $ny < 0.74) $att = 1750 + 200 * $grain;
        if ($G::inEllipse($nx, $ny, 0.545, 0.585, 0.120, 0.135)) $att = 1850 + 200 * $grain;   // 心影
        if ($G::inEllipse($nx, $ny, 0.47, 0.360, 0.055, 0.070)) $att = 1700;                    // 肺动脉段

        /* 膈肌与肋膈角 */
        $dia = 0.760 - 0.055 * sin(M_PI * $nx);
        if ($ny > $dia) $att = 1400 + 250 * $grain;
        if ($ny > $dia && $body > 0.86) $att = 1150 + 200 * $grain;

        /* 胸椎（后正中，重叠心影内） */
        if (abs($nx - 0.5) < 0.030 && $ny > 0.28) $att = 2200 + 150 * $grain;

        /* 肋骨：斜向弧形高密度带 */
        $rib = $ny + 0.11 * cos(($nx - 0.5) * M_PI);
        $rf = $rib * 9.0 - floor($rib * 9.0);
        if ($rf < 0.11 && $lung < 0.2 && $body < 0.98 && $ny > 0.20 && $ny < 0.82) {
            $att += 900 + 400 * $grain;
        }
        /* 锁骨：上胸部横向骨性带 */
        $clav = abs($ny - (0.245 + 0.10 * abs($nx - 0.5))) ;
        if ($clav < 0.022 && abs($nx - 0.5) < 0.34) $att += 1100 + 300 * $grain;
        /* 肩胛骨外缘 */
        if ($G::inEllipse($nx, $ny, 0.145, 0.400, 0.045, 0.110) || $G::inEllipse($nx, $ny, 0.855, 0.400, 0.045, 0.110)) {
            $att += 700;
        }

        return $clamp($att);
    }
}
