<?php
/**
 * ============================================================
 * app/Pacs/Mock/Generators/US/AbdomenUS.php — 腹部扇形超声
 * ============================================================
 * 凸阵 / 扇形视野：肝实质均匀中低回声 + 瑞利斑点颗粒，门静脉等管道系统
 * 无回声暗区，胆囊无回声伴后方回声增强，结石强回声伴特征性声影，深部声衰减。
 * ============================================================ */
class PvMockAbdomenUS extends PvMockAbstractUS {

    protected function initUS() {
        $this->configure(array(
            'bodyPartExamined' => 'ABDOMEN', 'seriesDescription' => 'US Cine',
            'frameCount' => 20, 'sliceThickness' => 0.0, 'spacingBetweenSlices' => 0.0,
            'rowSpacing' => 0.35, 'colSpacing' => 0.35,
            'noise' => $this->seed . '|abdus',
        ));
    }

    protected function sample($nx, $ny, $p, $x, $y, $i) {
        $G = 'PvMockGeometryHelper';
        $noise = $this->noise;
        list($in, $depth, $ang) = $this->beam($nx, $ny, 0.5, -0.10, 1.15, 0.62);
        if (!$in) return 0;

        /* 深部声衰减（TGC 补偿后的残余衰减） */
        $gain = 210.0 * exp(-1.35 * $depth);
        $grain = PvMockProceduralNoise::fbm($noise, $nx * 5, $ny * 5, 3);

        /* 肝实质：均匀中低回声 */
        $echo = 95.0 + 30.0 * $grain;

        /* 门静脉 / 肝静脉：无回声管状暗区 */
        $pv = $G::distanceToPolyline(array(
            array(0.5, 0.05), array(0.52, 0.28), array(0.47, 0.50), array(0.56, 0.72),
        ), $nx, $ny);
        $pv2 = $G::distanceToPolyline(array(
            array(0.30, 0.42), array(0.44, 0.40), array(0.60, 0.36), array(0.74, 0.40),
        ), $nx, $ny);
        $pipe = min($pv, $pv2);
        if ($pipe < 0.020) {
            $echo = 6.0 + 6.0 * $grain;                              // 无回声血管腔
        } elseif ($pipe < 0.030) {
            $echo = 170.0;                                           // 管壁强回声
        }

        /* 胆囊：无回声 + 后方回声增强 */
        $gb = $G::ellipseField($nx, $ny, 0.63, 0.60, 0.075, 0.055);
        if ($gb < 0) {
            $echo = 8.0 + 5.0 * $grain;
        }
        /* 胆囊结石：强回声伴后方声影 */
        $stone = $G::ellipseField($nx, $ny, 0.615, 0.585, 0.020, 0.016);
        if ($stone < 0) {
            $echo = 245.0;
        } elseif ($ny > 0.60 && abs($nx - 0.615) < 0.028 && $ny < 0.78) {
            $echo = 4.0 + 6.0 * $grain;                              // 声影
        }

        /* 肾：皮质 / 髓质锥体（较低回声）与集合系统强回声 */
        $kid = $G::ellipseField($nx, $ny, 0.30, 0.56, 0.070, 0.095);
        if ($kid < 0) {
            $echo = 70.0 + 25.0 * $grain;
            $medulla = PvMockProceduralNoise::fbm($noise, $nx * 10, $ny * 10, 3);
            if ($medulla > 0.60) $echo = 40.0 + 20.0 * $grain;       // 锥体
            if ($G::inEllipse($nx, $ny, 0.30, 0.56, 0.022, 0.040)) $echo = 190.0;  // 集合系统
        }

        /* 深部强反射界面（膈肌 / 腹壁） */
        if ($depth > 0.92) $echo *= 1.6;

        /* 斑点乘性噪声 + 深度增益 */
        $echo *= $this->speckle($x, $y, $i);
        $val = $echo * ($gain / 210.0);
        /* 声束边缘渐暗 */
        $val *= (1.0 - 0.35 * $ang * $ang);
        return $val;
    }
}
