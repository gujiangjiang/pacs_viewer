<?php
/**
 * ============================================================
 * app/Pacs/Mock/Generators/CT/HeadCT.php — 颅脑连续横断面 CT
 * ============================================================
 * 32~48 帧连续轴位：从颅底（脑干、小脑、蝶鞍、岩骨）→ 侧脑室体部 →
 * 半卵圆中心 → 顶叶，Z 轴形态连续演变。
 *
 * HU 规范：致密骨 +800~+1200，板障 +300~+500，灰质 +38~+45，
 * 白质 +28~+35，脑脊液 0~+15，空气 -1000。
 * ============================================================ */
class PvMockHeadCT extends PvMockAbstractGenerator {

    public function __construct($seed = 'mock', $weight = 'T1') {
        $this->seed = (string)$seed;
        $this->configure(array(
            'modality' => 'CT', 'orientation' => 'AXIAL', 'bodyPartExamined' => 'HEAD',
            'seriesDescription' => 'Axial 5.0mm', 'frameCount' => 40,
            'sliceThickness' => 5.0, 'spacingBetweenSlices' => 5.0,
            'rowSpacing' => 0.48, 'colSpacing' => 0.48,
            'windowCenter' => 40.0, 'windowWidth' => 80.0,   // 脑窗
            'noise' => $this->seed . '|headct',
        ));
    }

    /** 脑窗 + 骨窗双预设 */
    public function getModalitySpecificTags() {
        $tags = parent::getModalitySpecificTags();
        $tags['window_center'] = array(40, 400);
        $tags['window_width'] = array(80, 2000);
        return $tags;
    }

    protected function sample($nx, $ny, $p, $x, $y, $i) {
        $G = 'PvMockGeometryHelper';
        $noise = $this->noise;
        $cx = 0.5; $cy = 0.49;

        /* 头轮廓随帧：颅底中等 → 中部最宽 → 颅顶收缩 */
        if ($p < 0.45) $scale = $G::lerp(0.80, 1.0, $G::smoothstep(0, 0.45, $p));
        else $scale = $G::lerp(1.0, 0.34, $G::smoothstep(0.45, 1.0, $p));

        $rx = 0.340 * $scale;
        $ry = 0.305 * $scale;
        $rho2 = $G::ellipseValue($nx, $ny, $cx, $cy, $rx, $ry);

        if ($rho2 > 1.0) {
            /* 颅外空气（含极少噪声） */
            $air = -1000.0 + 25.0 * PvMockProceduralNoise::fbm($noise, $nx, $ny, 2);
            return $air;
        }

        $rho = sqrt($rho2);
        $skull = 0.085 + 0.02 * $G::band($p, 0.0, 0.35, 0.1);   // 颅底骨质更厚
        if ($rho > 1.0 - $skull) {
            $t = (1.0 - $rho) / $skull;                          // 0 外板 → 1 内板
            $n = PvMockProceduralNoise::fbm($noise, $nx * 4, $ny * 4, 3);
            if ($t < 0.32) return 980.0 + 200.0 * $n;            // 外板
            if ($t < 0.64) return 330.0 + 160.0 * $n;            // 板障
            return 1020.0 + 180.0 * $n;                          // 内板
        }

        /* 脑实质：灰白质对比 + 皮层沟回 */
        $brainR = 1.0 - $skull;
        $br = $rho / $brainR;
        $grain = PvMockProceduralNoise::fbm($noise, $nx * 3.5, $ny * 3.5, 3);
        $hu = 30.0 + 6.0 * $grain;                                // 白质
        if ($br > 0.80) $hu = 40.0 + 6.0 * $grain;                // 灰质
        $sul = PvMockProceduralNoise::fbm($noise, $nx * 7.0, $ny * 7.0, 3);
        if ($br > 0.70 && $sul > 0.62 && $rho2 < 0.98) $hu = 12.0 + 6.0 * $grain;  // 脑沟 CSF

        /* 大脑纵裂 / 镰（中线硬膜） */
        if (abs($nx - 0.5) < 0.006 && $ny < $cy) $hu = 72.0;
        if (abs($nx - 0.5) < 0.022 && $ny < $cy && $br > 0.6) $hu = 14.0 + 5.0 * $grain;

        /* 侧脑室体部与额角（帧带 0.32~0.78） */
        $vAmp = $G::band($p, 0.32, 0.80, 0.10);
        if ($vAmp > 0.01) {
            $d = 1.0;
            $d = min($d, $G::ellipseField($nx, $ny, 0.443, 0.500, 0.045, 0.075));
            $d = min($d, $G::ellipseField($nx, $ny, 0.557, 0.500, 0.045, 0.075));
            $d = min($d, $G::ellipseField($nx, $ny, 0.465, 0.415, 0.028, 0.045));  // 左额角
            $d = min($d, $G::ellipseField($nx, $ny, 0.535, 0.415, 0.028, 0.045));  // 右额角
            if ($d < 0) $hu = $hu * (1.0 - $vAmp) + (7.0 + 4.0 * $grain) * $vAmp;
        }

        /* 第三脑室：中线窄缝（帧带 0.38~0.72） */
        $tAmp = $G::band($p, 0.38, 0.72, 0.08);
        if ($tAmp > 0.01 && abs($nx - 0.5) < 0.013 && $ny > 0.47 && $ny < 0.56) {
            $hu = $hu * (1.0 - $tAmp) + 6.0 * $tAmp;
        }

        /* 颅底结构（p < 0.32 渐隐）：脑干、小脑、岩骨、蝶鞍 */
        $baseAmp = 1.0 - $G::smoothstep(0.18, 0.36, $p);
        if ($baseAmp > 0.01) {
            if ($G::ellipseField($nx, $ny, 0.5, 0.615, 0.055, 0.075) < 0) {
                $hu = $hu * (1.0 - $baseAmp) + (32.0 + 5.0 * $grain) * $baseAmp;     // 脑干
            }
            $folia = PvMockProceduralNoise::fbm($noise, $nx * 12.0, $ny * 12.0, 3);
            if ($G::inEllipse($nx, $ny, 0.375, 0.665, 0.110, 0.090)) {
                $hu = $hu * (1.0 - $baseAmp) + (36.0 + 8.0 * $folia) * $baseAmp;     // 左小脑
            }
            if ($G::inEllipse($nx, $ny, 0.625, 0.665, 0.110, 0.090)) {
                $hu = $hu * (1.0 - $baseAmp) + (36.0 + 8.0 * $folia) * $baseAmp;     // 右小脑
            }
            if ($G::inEllipse($nx, $ny, 0.315, 0.560, 0.030, 0.045) || $G::inEllipse($nx, $ny, 0.685, 0.560, 0.030, 0.045)) {
                $hu = $hu * (1.0 - $baseAmp) + 950.0 * $baseAmp;                     // 岩骨
            }
            if ($G::inEllipse($nx, $ny, 0.5, 0.545, 0.040, 0.022)) {
                $hu = $hu * (1.0 - $baseAmp) + 620.0 * $baseAmp;                     // 蝶鞍
            }
            /* 第四脑室（小脑前方） */
            if ($G::inEllipse($nx, $ny, 0.5, 0.565, 0.018, 0.030)) {
                $hu = $hu * (1.0 - $baseAmp) + 6.0 * $baseAmp;
            }
        }

        return $hu;
    }
}
