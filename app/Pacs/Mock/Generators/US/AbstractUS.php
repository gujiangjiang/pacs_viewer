<?php
/**
 * ============================================================
 * app/Pacs/Mock/Generators/US/AbstractUS.php — 超声生成器基类
 * ============================================================
 * 8 位灰度；统一凸阵 / 扇形声束视野裁剪、瑞利分布斑点噪声（乘性）与
 * 深部声衰减。子类只需描述组织界面反射与无回声管道 / 腔室。
 * ============================================================ */
abstract class PvMockAbstractUS extends PvMockAbstractGenerator {

    protected $noise;
    protected $speckleTile = null;
    protected $tileSize = 128;

    public function __construct($seed = 'mock', $weight = 'T1') {
        $this->seed = (string)$seed;
        $this->modality = 'US';
        $this->orientation = 'US';
        $this->bitsAllocated = 8;
        $this->bitsStored = 8;
        $this->highBit = 7;
        $this->samplesPerPixel = 1;
        $this->photometric = 'MONOCHROME2';
        $this->rescaleIntercept = 0.0;
        $this->rescaleSlope = 1.0;
        $this->windowCenter = 128.0;
        $this->windowWidth = 256.0;
        $this->initUS();
    }

    abstract protected function initUS();

    protected function encodePixel($value) {
        $v = (int)round($value);
        return $v < 0 ? 0 : ($v > 255 ? 255 : $v);
    }

    /** 惰性构建瑞利分布斑点噪声瓦片（均值归一化为 1） */
    protected function ensureSpeckle() {
        if ($this->speckleTile !== null) return;
        $T = $this->tileSize;
        $rng = PvMockProceduralNoise::rng(PvMockProceduralNoise::seed($this->seed . '|speckle'));
        $mean = sqrt(M_PI / 2.0);                       // 瑞利(sigma=1) 均值
        $tile = array();
        for ($i = 0; $i < $T * $T; $i++) {
            $tile[$i] = PvMockProceduralNoise::rayleigh($rng, 1.0) / $mean;
        }
        $this->speckleTile = $tile;
    }

    /** 采样斑点噪声（按帧平移，产生动态颗粒感） */
    protected function speckle($x, $y, $i) {
        $this->ensureSpeckle();
        $T = $this->tileSize;
        $ox = ($i * 37) % $T;
        $oy = ($i * 61) % $T;
        $xx = ($x + $ox) % $T;
        $yy = ($y + $oy) % $T;
        return $this->speckleTile[$yy * $T + $xx];
    }

    /** 声束坐标：返回 [是否在视野内, 深度比 0..1, 横向角 -1..1] */
    protected function beam($nx, $ny, $cx, $cy, $radius, $halfAngle) {
        $dx = $nx - $cx; $dy = $ny - $cy;
        $r = sqrt($dx * $dx + $dy * $dy);
        if ($r > $radius || $r < 1e-6) return array(false, 0.0, 0.0);
        $ang = atan2($dx, $dy);
        if (abs($ang) > $halfAngle) return array(false, 0.0, 0.0);
        return array(true, $r / $radius, $ang / $halfAngle);
    }
}
