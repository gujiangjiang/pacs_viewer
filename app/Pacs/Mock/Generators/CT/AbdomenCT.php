<?php
/**
 * ============================================================
 * app/Pacs/Mock/Generators/CT/AbdomenCT.php — 腹部连续横断面 CT
 * ============================================================
 * 32 帧连续轴位：肝、脾、双肾、腹主动脉 / 下腔静脉、肠道气体、椎体与腹壁，
 * 帧推进时脏器大小连续演变（上腹 → 中腹 → 下腹）。
 *
 * HU 规范：肝 +50~+65，脾 +45~+55，肾 +30~+45，血管 / 肌肉 +40~+55，
 * 脂肪 -120~-60，肠腔气体 -900~-700，骨骼 +500~+1100。
 * ============================================================ */
class PvMockAbdomenCT extends PvMockAbstractGenerator {

    public function __construct($seed = 'mock', $weight = 'T1') {
        $this->seed = (string)$seed;
        $this->configure(array(
            'modality' => 'CT', 'orientation' => 'AXIAL', 'bodyPartExamined' => 'ABDOMEN',
            'seriesDescription' => 'Axial 5.0mm', 'frameCount' => 32,
            'sliceThickness' => 5.0, 'spacingBetweenSlices' => 5.0,
            'rowSpacing' => 0.75, 'colSpacing' => 0.75,
            'windowCenter' => 40.0, 'windowWidth' => 350.0,
            'noise' => $this->seed . '|abdomenct',
        ));
    }

    public function getModalitySpecificTags() {
        $tags = parent::getModalitySpecificTags();
        $tags['window_center'] = array(40, 400);
        $tags['window_width'] = array(350, 2000);
        return $tags;
    }

    protected function sample($nx, $ny, $p, $x, $y, $i) {
        $G = 'PvMockGeometryHelper';
        $noise = $this->noise;
        $grain = PvMockProceduralNoise::fbm($noise, $nx * 4, $ny * 4, 3);

        $scale = 0.84 + 0.16 * sin(M_PI * $p);
        $rho2 = $G::ellipseValue($nx, $ny, 0.5, 0.50, 0.360 * $scale, 0.280 * $scale);
        if ($rho2 > 1.0) return -1000.0 + 20.0 * PvMockProceduralNoise::fbm($noise, $nx, $ny, 2);
        $rho = sqrt($rho2);

        $hu = 48.0 + 10.0 * $grain;                                    // 肌肉
        if ($rho > 0.90) $hu = -100.0 + 35.0 * $grain;                 // 皮下脂肪

        /* 椎体（后方中心） */
        if ($G::inEllipse($nx, $ny, 0.5, 0.700, 0.060, 0.050)) {
            $vb = $G::ellipseField($nx, $ny, 0.5, 0.700, 0.060, 0.050);
            $hu = $vb > -0.22 ? 800.0 + 250.0 * $grain : 210.0 + 90.0 * $grain;
        }
        /* 腹主动脉 / 下腔静脉（椎体前方） */
        if ($G::inEllipse($nx, $ny, 0.465, 0.585, 0.026, 0.026)) $hu = 44.0 + 5.0 * $grain;
        if ($G::inEllipse($nx, $ny, 0.535, 0.590, 0.030, 0.024)) $hu = 40.0 + 5.0 * $grain;

        /* 肝脏（右上腹，帧带 0.05~0.75） */
        $liverAmp = $G::band($p, 0.05, 0.78, 0.12);
        if ($liverAmp > 0.01 && $G::inEllipse($nx, $ny, 0.355, 0.455, 0.175, 0.150)) {
            $hu = 56.0 + 12.0 * $grain;                                // 肝实质
            if ($G::inEllipse($nx, $ny, 0.330, 0.520, 0.030, 0.022)) $hu = 35.0;   // 胆囊
        }
        /* 脾脏（左上腹，帧带 0.15~0.70） */
        $spleenAmp = $G::band($p, 0.15, 0.70, 0.12);
        if ($spleenAmp > 0.01 && $G::inEllipse($nx, $ny, 0.700, 0.430, 0.100, 0.090)) {
            $hu = 50.0 + 10.0 * $grain;
        }
        /* 双肾（中下腹后侧，帧带 0.45~0.95） */
        $kidAmp = $G::band($p, 0.45, 0.95, 0.10);
        if ($kidAmp > 0.01 && ($G::inEllipse($nx, $ny, 0.285, 0.585, 0.062, 0.078) ||
                               $G::inEllipse($nx, $ny, 0.715, 0.585, 0.062, 0.078))) {
            $hu = 36.0 + 10.0 * $grain;                                // 肾实质
            if ($G::inEllipse($nx, $ny, 0.285, 0.585, 0.022, 0.030) ||
                $G::inEllipse($nx, $ny, 0.715, 0.585, 0.022, 0.030)) $hu = 12.0;   // 集合系统
        }
        /* 肠道气体与内容物 */
        $bowel = PvMockProceduralNoise::fbm($noise, $nx * 6, $ny * 6, 3);
        if ($G::inEllipse($nx, $ny, 0.470, 0.360, 0.130, 0.085)) {
            $hu = $bowel > 0.55 ? -820.0 + 90.0 * $bowel : 20.0 + 25.0 * $bowel;
        }
        if ($G::inEllipse($nx, $ny, 0.610, 0.480, 0.080, 0.065) && $bowel > 0.60) {
            $hu = -760.0 + 80.0 * $bowel;
        }
        return $hu;
    }
}
