<?php
/**
 * ============================================================
 * app/Pacs/Mock/Generators/CT/ChestCT.php — 胸部连续横断面 CT
 * ============================================================
 * 32~48 帧连续轴位：肺尖 → 气管分叉 → 心脏大血管 → 膈肌。呈现双肺野透亮
 * 低衰减、气管 / 支气管树、纵隔心脏血管轮廓、胸壁肋骨与胸椎断面。
 *
 * HU 规范：充气肺野 -700~-900，气管内气体约 -950，纵隔 / 心肌 +30~+50，
 * 肋骨 / 胸椎骨皮质 +500~+1200，胸壁软组织 +30~+50，空气 -1000。
 * ============================================================ */
class PvMockChestCT extends PvMockAbstractGenerator {

    public function __construct($seed = 'mock', $weight = 'T1') {
        $this->seed = (string)$seed;
        $this->configure(array(
            'modality' => 'CT', 'orientation' => 'AXIAL', 'bodyPartExamined' => 'CHEST',
            'seriesDescription' => 'Axial 5.0mm', 'frameCount' => 40,
            'sliceThickness' => 5.0, 'spacingBetweenSlices' => 5.0,
            'rowSpacing' => 0.72, 'colSpacing' => 0.72,
            // 默认纵隔/软组织窗（与其他 CT 一致）；肺窗作为第二预设可选
            'windowCenter' => 40.0, 'windowWidth' => 350.0,
            'noise' => $this->seed . '|chestct',
        ));
    }

    /** 纵隔/软组织窗（默认）+ 肺窗（预设） */
    public function getModalitySpecificTags() {
        $tags = parent::getModalitySpecificTags();
        $tags['window_center'] = array(40, -600);
        $tags['window_width'] = array(350, 1500);
        return $tags;
    }

    protected function sample($nx, $ny, $p, $x, $y, $i) {
        $G = 'PvMockGeometryHelper';
        $noise = $this->noise;
        $cx = 0.5; $cy = 0.50;

        /* 胸廓横径随帧：上窄 → 中部最宽 → 膈下渐小 */
        if ($p < 0.5) $scale = $G::lerp(0.72, 1.0, $G::smoothstep(0, 0.5, $p));
        else $scale = $G::lerp(1.0, 0.82, $G::smoothstep(0.5, 1.0, $p));
        $rx = 0.420 * $scale;
        $ry = 0.300 * $scale;
        $rho2 = $G::ellipseValue($nx, $ny, $cx, $cy, $rx, $ry);
        if ($rho2 > 1.0) return -1000.0 + 20.0 * PvMockProceduralNoise::fbm($noise, $nx, $ny, 2);

        $rho = sqrt($rho2);
        $grain = PvMockProceduralNoise::fbm($noise, $nx * 4, $ny * 4, 3);

        /* 胸壁软组织 */
        if ($rho > 0.90) return 38.0 + 8.0 * $grain;

        /* 肋骨：沿胸廓周向周期出现的高密度断面 */
        $ang = atan2($ny - $cy, $nx - $cx);
        $ribWave = sin($ang * 9.0 + 0.6);
        if ($rho > 0.80 && $rho < 0.92 && $ribWave > 0.72) {
            return 620.0 + 320.0 * $grain;
        }

        /* 胸椎（后方中心） */
        $vd = $G::ellipseField($nx, $ny, 0.5, 0.705, 0.055, 0.048);
        if ($vd < 0) {
            if ($vd > -0.22) return 780.0 + 220.0 * $grain;      // 骨皮质
            return 260.0 + 120.0 * $grain;                       // 椎体松质
        }
        /* 胸骨（前方中心） */
        if ($G::inEllipse($nx, $ny, 0.5, 0.205, 0.045, 0.018)) return 560.0 + 200.0 * $grain;

        /* 双肺野 */
        $lungSc = 0.72 + 0.34 * sin(M_PI * $G::clamp($p * 1.15, 0, 1));
        $lL = $G::ellipseField($nx, $ny, 0.325, 0.470, 0.155 * $lungSc, 0.215 * $lungSc);
        $lR = $G::ellipseField($nx, $ny, 0.675, 0.470, 0.155 * $lungSc, 0.215 * $lungSc);
        $lung = min($lL, $lR);
        if ($lung < 0 && $rho < 0.88) {
            $hu = -835.0 + 110.0 * PvMockProceduralNoise::fbm($noise, $nx * 3, $ny * 3, 4);
            /* 肺血管纹理：细支条索状稍高密度 */
            $vess = PvMockProceduralNoise::fbm($noise, $nx * 9, $ny * 9, 3);
            if ($vess > 0.66) $hu = -540.0 - 120.0 * $vess;
            /* 叶间裂 */
            if (abs($ny - (0.44 + 0.14 * ($nx - 0.5))) < 0.004) $hu = -300.0;

            /* 气管 / 支气管树：上段气管，中下段分叉 */
            $trachea = $G::ellipseField($nx, $ny, 0.5, 0.40, 0.024, 0.024);
            if ($p < 0.55) {
                if ($trachea < 0) $hu = -955.0;
            } else {
                $bif = 0.05 + 0.10 * ($p - 0.55);
                $blD = $G::ellipseField($nx, $ny, 0.5 - $bif, 0.45 + 0.12 * ($p - 0.55), 0.018, 0.018);
                $brD = $G::ellipseField($nx, $ny, 0.5 + $bif, 0.45 + 0.12 * ($p - 0.55), 0.018, 0.018);
                if (min($blD, $brD) < 0) $hu = -950.0;
            }
            return $hu;
        }

        /* 纵隔与心脏大血管 */
        $hu = 42.0 + 10.0 * $grain;
        if ($G::inEllipse($nx, $ny, 0.5, 0.46, 0.075, 0.170)) {
            $hu = 40.0 + 8.0 * $grain;                            // 纵隔
        }
        /* 气管位于纵隔中心 */
        if ($G::inEllipse($nx, $ny, 0.5, 0.40, 0.024, 0.024)) $hu = -955.0;
        /* 升主动脉 / 降主动脉 */
        if ($G::inEllipse($nx, $ny, 0.47, 0.40, 0.030, 0.030)) $hu = 45.0 + 6.0 * $grain;
        if ($G::inEllipse($nx, $ny, 0.55, 0.52, 0.026, 0.026)) $hu = 45.0 + 6.0 * $grain;
        /* 心脏：中下帧增大 */
        $hAmp = $G::band($p, 0.50, 1.0, 0.12);
        if ($hAmp > 0.01 && $G::inEllipse($nx, $ny, 0.485, 0.585, 0.115, 0.135)) {
            $hu = 45.0 + 10.0 * $grain * $hAmp;
        }
        /* 膈肌顶（低位帧） */
        if ($p > 0.80) {
            $dia = 0.62 - 0.06 * sin(M_PI * $nx);
            if ($ny > $dia) $hu = 55.0 + 40.0 * $grain;
        }
        return $hu;
    }
}
