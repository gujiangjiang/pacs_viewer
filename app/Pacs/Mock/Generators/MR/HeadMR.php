<?php
/**
 * ============================================================
 * app/Pacs/Mock/Generators/MR/HeadMR.php — 颅脑 T1/T2 加权多层切片
 * ============================================================
 * 24 帧连续轴位。T1WI 脑脊液呈低信号、白质较高信号；T2WI 脑脊液与水肿
 * 呈高亮信号。包含灰白质对比、侧脑室 / 第三脑室、脑干小脑与中线结构。
 * ============================================================ */
class PvMockHeadMR extends PvMockAbstractMR {

    private $noise;

    protected function initMR() {
        $this->bodyPartExamined = 'HEAD';
        $this->seriesDescription = $this->isT2() ? 'T2WI Axial' : 'T1WI Axial';
        $this->frameCount = 24;
        $this->sliceThickness = 5.0;
        $this->spacingBetweenSlices = 6.0;
        $this->rowSpacing = 0.45;
        $this->colSpacing = 0.45;
        $this->windowCenter = $this->isT2() ? 700 : 600;
        $this->windowWidth = $this->isT2() ? 1400 : 1200;
        $this->noise = PvMockProceduralNoise::perlin2D($this->seed . '|headmr' . $this->weight, 64);
    }

    protected function sample($nx, $ny, $p, $x, $y, $i) {
        $G = 'PvMockGeometryHelper';
        $noise = $this->noise;
        $s = $this->tissueSignals();
        $grain = PvMockProceduralNoise::fbm($noise, $nx * 3.5, $ny * 3.5, 3);

        if ($p < 0.45) $scale = $G::lerp(0.80, 1.0, $G::smoothstep(0, 0.45, $p));
        else $scale = $G::lerp(1.0, 0.34, $G::smoothstep(0.45, 1.0, $p));
        $rx = 0.340 * $scale; $ry = 0.305 * $scale;
        $rho2 = $G::ellipseValue($nx, $ny, 0.5, 0.49, $rx, $ry);
        if ($rho2 > 1.0) return 5.0 + 25.0 * PvMockProceduralNoise::fbm($noise, $nx, $ny, 2);

        $rho = sqrt($rho2);
        $scalp = 0.055;
        if ($rho > 1.0 - $scalp) {
            /* 头皮 / 皮下脂肪：T1 高信号、T2 中等信号 */
            return $s['fat'] * (0.85 + 0.3 * $grain);
        }
        $skull = 0.075;
        if ($rho > 1.0 - $scalp - $skull) {
            /* 颅骨：骨皮质低信号 + 板障脂肪信号 */
            $t = (1.0 - $scalp - $rho) / $skull;
            return $t > 0.4 && $t < 0.75 ? $s['fat'] * 0.75 : $s['bone'] + 60 * $grain;
        }

        $brainR = 1.0 - $scalp - $skull;
        $br = $rho / $brainR;
        $hu = $s['wm'] * (0.92 + 0.16 * $grain);
        if ($br > 0.80) $hu = $s['gm'] * (0.92 + 0.16 * $grain);
        /* 皮层脑沟 CSF */
        $sul = PvMockProceduralNoise::fbm($noise, $nx * 7.0, $ny * 7.0, 3);
        if ($br > 0.70 && $sul > 0.62) $hu = $s['csf'] * (0.9 + 0.2 * $grain);

        /* 侧脑室（帧带） */
        $vAmp = $G::band($p, 0.30, 0.80, 0.10);
        if ($vAmp > 0.01) {
            $d = 1.0;
            $d = min($d, $G::ellipseField($nx, $ny, 0.443, 0.500, 0.045, 0.075));
            $d = min($d, $G::ellipseField($nx, $ny, 0.557, 0.500, 0.045, 0.075));
            $d = min($d, $G::ellipseField($nx, $ny, 0.465, 0.415, 0.028, 0.045));
            $d = min($d, $G::ellipseField($nx, $ny, 0.535, 0.415, 0.028, 0.045));
            if ($d < 0) $hu = $hu * (1.0 - $vAmp) + $s['csf'] * (0.95 + 0.1 * $grain) * $vAmp;
        }
        /* 第三脑室 */
        $tAmp = $G::band($p, 0.38, 0.72, 0.08);
        if ($tAmp > 0.01 && abs($nx - 0.5) < 0.013 && $ny > 0.47 && $ny < 0.56) {
            $hu = $hu * (1.0 - $tAmp) + $s['csf'] * $tAmp;
        }

        /* 颅底：脑干 / 小脑（含叶状结构） */
        $baseAmp = 1.0 - $G::smoothstep(0.18, 0.36, $p);
        if ($baseAmp > 0.01) {
            $folia = PvMockProceduralNoise::fbm($noise, $nx * 12.0, $ny * 12.0, 3);
            if ($G::inEllipse($nx, $ny, 0.5, 0.615, 0.055, 0.075)) {
                $hu = $hu * (1.0 - $baseAmp) + $s['gm'] * (1.0 + 0.15 * $folia) * $baseAmp;
            }
            if ($G::inEllipse($nx, $ny, 0.375, 0.665, 0.110, 0.090) || $G::inEllipse($nx, $ny, 0.625, 0.665, 0.110, 0.090)) {
                $hu = $hu * (1.0 - $baseAmp) + $s['gm'] * (0.95 + 0.2 * $folia) * $baseAmp;
            }
        }
        return $hu;
    }
}
