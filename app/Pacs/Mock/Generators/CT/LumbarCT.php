<?php
/**
 * ============================================================
 * app/Pacs/Mock/Generators/CT/LumbarCT.php — 腰椎连续横断面 CT
 * ============================================================
 * 24~32 帧连续轴位：椎体 → 椎弓根 → 椎管与硬脊膜囊 → 关节突 / 棘突 / 横突，
 * 帧推进时椎体大小与椎管形态连续变化，模拟自 L1 至 L5 的层面过渡。
 *
 * HU 规范：骨皮质 +700~+1100，松质骨 +120~+300，黄韧带 / 硬膜 +30~+70，
 * 硬脊膜囊脑脊液 0~+20，硬膜外脂肪 -100~-40，腰大肌 / 软组织 +40~+55。
 * ============================================================ */
class PvMockLumbarCT extends PvMockAbstractGenerator {

    private $noise;

    public function __construct($seed = 'mock', $weight = 'T1') {
        $this->seed = (string)$seed;
        $this->modality = 'CT';
        $this->orientation = 'AXIAL';
        $this->bodyPartExamined = 'SPINE';
        $this->seriesDescription = 'Axial 3.0mm';
        $this->frameCount = 28;
        $this->sliceThickness = 3.0;
        $this->spacingBetweenSlices = 3.0;
        $this->rowSpacing = 0.55;
        $this->colSpacing = 0.55;
        $this->windowCenter = 40.0;
        $this->windowWidth = 350.0;
        $this->noise = PvMockProceduralNoise::perlin2D($this->seed . '|lumbarct', 64);
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

        /* 体部轮廓：不同腰椎层面大小连续变化 */
        $scale = 0.82 + 0.18 * sin(M_PI * $p);
        $rho2 = $G::ellipseValue($nx, $ny, 0.5, 0.50, 0.345 * $scale, 0.290 * $scale);
        if ($rho2 > 1.0) return -1000.0 + 20.0 * PvMockProceduralNoise::fbm($noise, $nx, $ny, 2);
        $rho = sqrt($rho2);

        /* 皮下脂肪与肌肉层 */
        $hu = 46.0 + 10.0 * $grain;
        if ($rho > 0.90) $hu = -95.0 + 35.0 * $grain;                 // 皮下脂肪
        /* 腰大肌（双侧前方） */
        if ($G::inEllipse($nx, $ny, 0.305, 0.470, 0.075, 0.085, 0.35) ||
            $G::inEllipse($nx, $ny, 0.695, 0.470, 0.075, 0.085, -0.35)) {
            $hu = 48.0 + 8.0 * $grain;
        }
        /* 椎旁肌（后方双侧） */
        if ($ny > 0.60 && (($nx > 0.62 && $nx < 0.88) || ($nx > 0.12 && $nx < 0.38))) {
            $hu = 52.0 + 10.0 * $grain;
        }

        /* 横突（水平伸出的骨性结构） */
        if ($G::inEllipse($nx, $ny, 0.255, 0.585, 0.075, 0.022) ||
            $G::inEllipse($nx, $ny, 0.745, 0.585, 0.075, 0.022)) {
            return 620.0 + 260.0 * $grain;
        }

        $level = $p * 4.0;                                            // L1~L5 层面
        $disc = $G::band($p - floor($level), 0.80, 1.0, 0.12);        // 椎间盘帧带

        /* 椎体 */
        $bodyRx = 0.150 + 0.015 * sin($level);
        $bodyRy = 0.115 + 0.010 * cos($level);
        $vb = $G::ellipseField($nx, $ny, 0.5, 0.435, $bodyRx, $bodyRy);
        if ($vb < 0) {
            if ($disc > 0.5 && $p < 0.98) return 95.0 + 40.0 * $grain;      // 椎间盘纤维环
            if ($vb > -0.22) return 760.0 + 260.0 * $grain;                 // 骨皮质
            return 190.0 + 90.0 * $grain;                                   // 松质骨
        }

        /* 椎弓根（连接椎体与后方附件） */
        if ($G::inEllipse($nx, $ny, 0.415, 0.545, 0.042, 0.038) ||
            $G::inEllipse($nx, $ny, 0.585, 0.545, 0.042, 0.038)) {
            return 720.0 + 280.0 * $grain;
        }

        /* 椎管：硬膜外脂肪 → 硬脊膜囊（脑脊液） */
        $canal = $G::ellipseField($nx, $ny, 0.5, 0.585, 0.078, 0.062);
        if ($canal < 0) {
            $hu = -70.0 + 30.0 * $grain;                                // 硬膜外脂肪
            $sac = $G::ellipseField($nx, $ny, 0.5, 0.580, 0.050, 0.040);
            if ($sac < 0) $hu = 10.0 + 6.0 * $grain;                    // 硬脊膜囊 CSF
            /* 马尾神经根点状结构 */
            if ($G::inEllipse($nx, $ny, 0.5, 0.572, 0.016, 0.014)) $hu = 32.0;
        }

        /* 关节突关节（后方两侧） */
        if ($G::inEllipse($nx, $ny, 0.435, 0.665, 0.030, 0.030) ||
            $G::inEllipse($nx, $ny, 0.565, 0.665, 0.030, 0.030)) {
            return 700.0 + 260.0 * $grain;
        }
        /* 黄韧带（椎管后外侧稍高密度） */
        if ($G::inEllipse($nx, $ny, 0.455, 0.630, 0.018, 0.026) ||
            $G::inEllipse($nx, $ny, 0.545, 0.630, 0.018, 0.026)) {
            $hu = 62.0 + 12.0 * $grain;
        }
        /* 椎板 */
        if ($G::inEllipse($nx, $ny, 0.5, 0.635, 0.095, 0.030)) {
            return 680.0 + 240.0 * $grain;
        }
        /* 棘突 */
        if ($G::inEllipse($nx, $ny, 0.5, 0.760, 0.026, 0.055)) {
            return 640.0 + 240.0 * $grain;
        }

        return $hu;
    }
}
