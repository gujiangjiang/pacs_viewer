<?php
/**
 * ============================================================
 * app/Pacs/Mock/Generators/DR/LimbDR.php — 四肢骨骼 / 关节投影像
 * ============================================================
 * 单幅 1024×1024 灰度投影，以膝关节正位为例：股骨髁、胫骨平台、髌骨与
 * 腓骨小头等骨性结构高密度重叠，周围软组织与脂肪透亮。可适配手 / 腕 /
 * 踝等长骨关节（默认膝）。
 * ============================================================ */
class PvMockLimbDR extends PvMockAbstractGenerator {

    private $noise;

    public function __construct($seed = 'mock', $weight = 'T1') {
        $this->seed = (string)$seed;
        $this->modality = 'DR';
        $this->orientation = 'PA';
        $this->bodyPartExamined = 'KNEE';
        $this->seriesDescription = 'AP Knee';
        $this->frameCount = 1;
        $this->rows = 1024;
        $this->cols = 1024;
        $this->sliceThickness = 0.0;
        $this->spacingBetweenSlices = 0.0;
        $this->rowSpacing = 0.20;
        $this->colSpacing = 0.20;
        $this->bitsStored = 12;
        $this->highBit = 11;
        $this->windowCenter = 1500.0;
        $this->windowWidth = 3000.0;
        $this->noise = PvMockProceduralNoise::perlin2D($this->seed . '|limbdr', 64);
    }

    protected function sample($nx, $ny, $p, $x, $y, $i) {
        $G = 'PvMockGeometryHelper';
        $noise = $this->noise;
        $grain = PvMockProceduralNoise::fbm($noise, $nx * 5, $ny * 5, 3);
        $clamp = function ($v) { return $v < 0 ? 0 : ($v > 4095 ? 4095 : (int)$v); };

        /* 四肢体表轮廓（竖长椭圆） */
        if ($G::ellipseValue($nx, $ny, 0.5, 0.50, 0.30, 0.47) > 1.0) return 0;

        /* 软组织衰减（外侧更薄） */
        $att = 1050 + 260 * $grain;
        $edge = $G::ellipseValue($nx, $ny, 0.5, 0.50, 0.30, 0.47);
        if ($edge > 0.85) $att = 800 + 250 * $grain;

        /* 股骨远端与髁（上方） */
        $femur = $G::inEllipse($nx, $ny, 0.50, 0.270, 0.110, 0.180);
        if ($femur) {
            $att = 2600 + 500 * $grain;                              // 骨皮质
            if ($G::ellipseValue($nx, $ny, 0.50, 0.270, 0.110, 0.180) < 0.55) {
                $att = 1500 + 250 * $grain;                          // 髓腔（较透亮）
            }
        }
        /* 胫骨近端（下方） */
        $tibia = $G::inEllipse($nx, $ny, 0.485, 0.760, 0.105, 0.145);
        if ($tibia) {
            $att = 2600 + 500 * $grain;
            if ($G::ellipseValue($nx, $ny, 0.485, 0.760, 0.105, 0.145) < 0.55) {
                $att = 1500 + 250 * $grain;
            }
        }
        /* 腓骨小头（外侧） */
        if ($G::inEllipse($nx, $ny, 0.655, 0.735, 0.045, 0.075)) {
            $att = 2400 + 400 * $grain;
        }
        /* 髌骨（重叠于股骨下段前方） */
        if ($G::inEllipse($nx, $ny, 0.495, 0.440, 0.055, 0.070)) {
            $att += 900 + 300 * $grain;
        }
        /* 关节间隙（胫股之间透亮线） */
        if (abs($ny - 0.585) < 0.020 && abs($nx - 0.5) < 0.20) {
            $att = 900 + 200 * $grain;
        }
        /* 骨小梁纹理 */
        $trab = PvMockProceduralNoise::fbm($noise, $nx * 16, $ny * 16, 3);
        if ($femur || $tibia) $att += 300 * $trab;
        return $clamp($att);
    }
}
