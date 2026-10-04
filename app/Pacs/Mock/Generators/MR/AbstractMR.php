<?php
/**
 * ============================================================
 * app/Pacs/Mock/Generators/MR/AbstractMR.php — MR 生成器基类
 * ============================================================
 * 统一 T1WI / T2WI 信号权重与 12 位信号编码，并提供组织信号对照表：
 *   T1WI：脑脊液低信号（暗）、脑白质较高信号、脂肪高信号；
 *   T2WI：脑脊液与水肿高信号（亮）、脑白质较低信号。
 * ============================================================ */
abstract class PvMockAbstractMR extends PvMockAbstractGenerator {

    protected $weight = 'T1';

    public function __construct($seed = 'mock', $weight = 'T1') {
        $this->seed = (string)$seed;
        $this->modality = 'MR';
        $this->orientation = 'AXIAL';
        $this->rescaleIntercept = 0.0;
        $this->rescaleSlope = 1.0;
        $this->bitsStored = 12;
        $this->highBit = 11;
        $this->weight = strtoupper((string)$weight) === 'T2' ? 'T2' : 'T1';
        $this->initMR();
    }

    /** 子类设置帧数 / 方位 / 部位 / 描述 / 窗宽窗位 / 噪声 */
    abstract protected function initMR();

    protected function isT2() { return $this->weight === 'T2'; }

    protected function encodePixel($value) {
        $v = (int)round($value);
        if ($v < 0) $v = 0;
        if ($v > 4095) $v = 4095;
        return $v;
    }

    /** 组织信号：按权重返回 [脑脊液, 白质, 灰质, 脂肪, 骨皮质] */
    protected function tissueSignals() {
        if ($this->isT2()) {
            return array('csf' => 1380, 'wm' => 520, 'gm' => 780, 'fat' => 900, 'bone' => 80);
        }
        return array('csf' => 120, 'wm' => 1050, 'gm' => 760, 'fat' => 1350, 'bone' => 70);
    }

    public function getModalitySpecificTags() {
        $tags = parent::getModalitySpecificTags();
        $tags['window_center'] = $this->isT2() ? 700 : 600;
        $tags['window_width'] = $this->isT2() ? 1400 : 1200;
        return $tags;
    }
}
