<?php
/**
 * ============================================================
 * app/Pacs/Mock/Generators/MR/KneeMR.php — 膝关节矢状位多层切片
 * ============================================================
 * 16 帧矢状位（内侧 → 髁间窝 → 外侧）：股骨髁、胫骨平台、髌骨、半月板
 * （低信号楔形）、关节软骨与关节液（T2 高信号）、前后交叉韧带区。
 * ============================================================ */
class PvMockKneeMR extends PvMockAbstractMR {

    private $noise;

    protected function initMR() {
        $this->bodyPartExamined = 'KNEE';
        $this->orientation = 'SAGITTAL';
        $this->seriesDescription = $this->isT2() ? 'T2WI Sagittal' : 'T1WI Sagittal';
        $this->frameCount = 16;
        $this->sliceThickness = 3.0;
        $this->spacingBetweenSlices = 3.3;
        $this->rowSpacing = 0.31;
        $this->colSpacing = 0.31;
        $this->windowCenter = $this->isT2() ? 700 : 600;
        $this->windowWidth = $this->isT2() ? 1400 : 1200;
        $this->noise = PvMockProceduralNoise::perlin2D($this->seed . '|kneemr' . $this->weight, 64);
    }

    protected function sample($nx, $ny, $p, $x, $y, $i) {
        $G = 'PvMockGeometryHelper';
        $noise = $this->noise;
        $s = $this->tissueSignals();
        $grain = PvMockProceduralNoise::fbm($noise, $nx * 5, $ny * 5, 3);
        $lat = abs($p - 0.5) * 2.0;

        /* 膝部体表轮廓（横椭圆） */
        if ($G::ellipseValue($nx, $ny, 0.5, 0.50, 0.40, 0.44) > 1.0) {
            return 5.0 + 20.0 * PvMockProceduralNoise::fbm($noise, $nx, $ny, 2);
        }

        /* 软组织 / 皮下脂肪 */
        $hu = $s['gm'] * (0.55 + 0.22 * $grain);
        if ($G::ellipseValue($nx, $ny, 0.5, 0.50, 0.40, 0.44) > 0.80) {
            $hu = $s['fat'] * (0.7 + 0.3 * $grain);
        }

        /* 股骨远端 / 髁（上方） */
        if ($G::inEllipse($nx, $ny, 0.48, 0.300, 0.180, 0.145)) {
            $hu = $s['bone'] + 80 * $grain;                       // 骨皮质
            if ($G::ellipseValue($nx, $ny, 0.48, 0.300, 0.180, 0.145) < 0.72) {
                $hu = $this->isT2() ? 560 + 140 * $grain : 900 + 200 * $grain;   // 骨髓
            }
        }
        /* 胫骨近端平台（下方） */
        if ($G::inEllipse($nx, $ny, 0.50, 0.700, 0.165, 0.125)) {
            $hu = $s['bone'] + 80 * $grain;
            if ($G::ellipseValue($nx, $ny, 0.50, 0.700, 0.165, 0.125) < 0.72) {
                $hu = $this->isT2() ? 560 + 140 * $grain : 900 + 200 * $grain;
            }
        }
        /* 髌骨（前方） */
        if ($G::inEllipse($nx, $ny, 0.225, 0.360, 0.045, 0.075, 0.2)) {
            $hu = $s['bone'] + 60 * $grain;
            if ($G::ellipseValue($nx, $ny, 0.225, 0.360, 0.045, 0.075) < 0.6) {
                $hu = $this->isT2() ? 520 + 120 * $grain : 860 + 160 * $grain;
            }
        }

        /* 关节腔与关节液（T2 高亮） */
        $joint = $G::ellipseField($nx, $ny, 0.50, 0.520, 0.150, 0.045);
        if ($joint < 0) {
            $hu = $this->isT2() ? $s['csf'] * (0.9 + 0.12 * $grain) : $s['csf'] * 0.8;
        }

        /* 半月板：髁间区域低信号楔形（旁正中层面） */
        if ($lat < 0.6) {
            if ($G::inEllipse($nx, $ny, 0.415, 0.595, 0.075, 0.030, 0.35) ||
                $G::inEllipse($nx, $ny, 0.585, 0.595, 0.075, 0.030, -0.35)) {
                $hu = 150 + 80 * $grain;                          // 半月板纤维软骨低信号
            }
        } else {
            /* 旁矢状位：股胫关节面与软骨 */
            if ($G::inEllipse($nx, $ny, 0.50, 0.585, 0.140, 0.020)) {
                $hu = 420 + 120 * $grain;                         // 关节软骨
            }
        }

        /* 髁间窝韧带（交叉韧带，低信号带） */
        if ($lat < 0.5 && abs($nx - 0.53 + ($ny - 0.50) * 0.25) < 0.016) {
            $hu = 190 + 90 * $grain;
        }
        return $hu;
    }
}
