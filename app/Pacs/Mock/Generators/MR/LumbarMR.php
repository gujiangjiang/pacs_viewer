<?php
/**
 * ============================================================
 * app/Pacs/Mock/Generators/MR/LumbarMR.php — 腰椎矢状位多层切片
 * ============================================================
 * 20 帧矢状位（旁正中 → 正中 → 对侧）：椎体、椎间盘、椎管与硬脊膜囊、
 * 马尾 / 圆锥及后方棘突。椎间盘水分在 T2WI 呈高信号，T1WI 呈低信号；
 * 脑脊液 T2 高亮、T1 暗。
 * ============================================================ */
class PvMockLumbarMR extends PvMockAbstractMR {

    private $noise;

    protected function initMR() {
        $this->bodyPartExamined = 'SPINE';
        $this->orientation = 'SAGITTAL';
        $this->seriesDescription = $this->isT2() ? 'T2WI Sagittal' : 'T1WI Sagittal';
        $this->frameCount = 20;
        $this->sliceThickness = 4.0;
        $this->spacingBetweenSlices = 4.4;
        $this->rowSpacing = 0.55;
        $this->colSpacing = 0.55;
        $this->windowCenter = $this->isT2() ? 700 : 600;
        $this->windowWidth = $this->isT2() ? 1400 : 1200;
        $this->noise = PvMockProceduralNoise::perlin2D($this->seed . '|lumbarmr' . $this->weight, 64);
    }

    protected function sample($nx, $ny, $p, $x, $y, $i) {
        $G = 'PvMockGeometryHelper';
        $noise = $this->noise;
        $s = $this->tissueSignals();
        $grain = PvMockProceduralNoise::fbm($noise, $nx * 4, $ny * 4, 3);
        $lat = abs($p - 0.5) * 2.0;                 // 0 正中 → 1 旁矢状

        /* 体部：矢状位为竖长椭圆 */
        if ($G::ellipseValue($nx, $ny, 0.48, 0.50, 0.30, 0.46) > 1.0) {
            return 5.0 + 20.0 * PvMockProceduralNoise::fbm($noise, $nx, $ny, 2);
        }

        /* 软组织 / 肌肉背景 */
        $hu = $s['gm'] * (0.55 + 0.25 * $grain);
        /* 后方椎旁肌 */
        if ($nx > 0.80) $hu = $s['gm'] * (0.62 + 0.2 * $grain);
        /* 前方腹腔脂肪（T1 高信号） */
        if ($nx < 0.22) $hu = $s['fat'] * (0.75 + 0.25 * $grain);

        /* 椎列：沿 y 堆叠，居中 x=0.60 */
        $unit = 5.4;
        $vpos = $ny * $unit;
        $bIdx = (int)floor($vpos);
        $frac = $vpos - $bIdx;
        $yc = ($bIdx + 0.5) / $unit;
        $discBand = ($frac < 0.15 || $frac > 0.85);
        $xIn = abs($nx - 0.60) < 0.085;

        /* 棘突（后方中线骨性结构，正中层面清晰） */
        if ($G::inEllipse($nx, $ny, 0.845, $yc, 0.030, 0.045) && $lat < 0.5) {
            return $s['bone'] + 40 * $grain;
        }

        if ($xIn) {
            if ($discBand) {
                /* 椎间盘：T2 髓核高信号，T1 低信号 */
                $nuc = $G::ellipseField($nx, $ny, 0.60, ($bIdx + ($frac > 0.5 ? 1.0 : 0.0)) / $unit, 0.055, 0.028);
                if ($this->isT2()) {
                    $hu = $nuc < 0 ? $s['csf'] * (0.9 + 0.15 * $grain) : 620 + 120 * $grain;
                } else {
                    $hu = $s['csf'] * 0.6 + 180 * $grain;
                }
                /* 纤维环 */
                if (abs($nuc) < 0.25) $hu = 260 + 120 * $grain;
                return $hu;
            }
            /* 椎体：骨髓信号（T1 较高、T2 中等），边缘低信号骨皮质 */
            $ed = abs($nx - 0.60) - 0.070;
            $edge = $G::smoothstep(0.010, 0.0, $ed);
            $marrow = $this->isT2() ? 620 + 100 * $grain : 980 + 140 * $grain;
            $hu = $marrow;
            if ($ed > -0.012) $hu = $s['bone'] + 30 * $grain;      // 终板 / 皮质
            return $hu;
        }

        /* 椎管与硬脊膜囊（椎体后方） */
        $canal = $G::ellipseField($nx, $ny, 0.715, 0.50, 0.055, 0.44);
        if ($canal < 0) {
            if ($this->isT2()) $hu = $s['csf'] * (0.92 + 0.12 * $grain);
            else $hu = $s['csf'] * (1.0 + 0.2 * $grain);
            /* 马尾神经根（细点 / 线，T2 低信号） */
            if (((int)($ny * 40)) % 3 === 0 && abs($nx - 0.715) < 0.035) $hu *= 0.55;
            /* 圆锥 / 脊髓终丝（上段） */
            if ($ny < 0.42 && abs($nx - 0.712) < 0.014) $hu = $s['gm'] * 0.9;
        }

        /* 椎间孔内神经根（旁矢状位） */
        if ($lat > 0.35 && $discBand && abs($nx - 0.665) < 0.020) {
            $hu = $s['gm'] * 0.95;
        }
        return $hu;
    }
}
