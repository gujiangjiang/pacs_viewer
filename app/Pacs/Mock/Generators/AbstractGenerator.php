<?php
/**
 * ============================================================
 * app/Pacs/Mock/Generators/AbstractGenerator.php — 生成器抽象基类
 * ============================================================
 * 承载多帧连续切片的公共编排（帧循环、二进制像素编码、尺寸 / 窗宽窗位 /
 * 层厚 / 模态 Tag 输出），具体解剖形态由子类实现 sample()。
 *
 * 子类只需关注「像素强度场」的数学建模，基类保证：
 *   · 输出字节数严格等于 Rows × Columns × (BitsAllocated / 8)；
 *   · 帧序推进时解剖形态连续演变；
 *   · 元数据符合标准 DICOM 语义（HU = 存储值 + RescaleIntercept）。
 * ============================================================ */
abstract class PvMockAbstractGenerator implements PvMockSliceGeneratorInterface, PvMockVolumeGeneratorInterface {

    /** @var int 序列总帧数 */
    protected $frameCount = 1;
    protected $rows = 512;
    protected $cols = 512;
    protected $modality = 'CT';
    protected $orientation = 'AXIAL';
    protected $seriesDescription = '';
    protected $bodyPartExamined = '';
    protected $sliceThickness = 5.0;
    protected $spacingBetweenSlices = 5.0;
    protected $rowSpacing = 0.7;
    protected $colSpacing = 0.7;
    protected $bitsAllocated = 16;
    protected $bitsStored = 16;
    protected $highBit = 15;
    protected $pixelRepresentation = 0;      // 0=无符号
    protected $samplesPerPixel = 1;
    protected $photometric = 'MONOCHROME2';
    protected $windowCenter = 40.0;
    protected $windowWidth = 80.0;
    protected $rescaleIntercept = -1024.0;   // 存储值 + Intercept = HU
    protected $rescaleSlope = 1.0;
    protected $seed = 'mock';
    protected $noise;                        // 各生成器的 Perlin 噪声源

    /**
     * 统一配置：按需覆盖生成器属性。
     * - 传入键为属性名（modality / frameCount / rowSpacing …）时直接赋值；
     * - 特殊键 `noise`：其值作为种子，构建 64×64 Perlin 噪声源。
     * 供各子类构造函数消除重复的属性赋值样板。
     */
    protected function configure(array $cfg) {
        foreach ($cfg as $key => $val) {
            if ($key === 'noise') { $this->noise = PvMockProceduralNoise::perlin2D($val, 64); continue; }
            $this->$key = $val;
        }
    }

    /* ---------------- 核心：逐帧像素生成 ---------------- */

    public function generateFrame($frameIndex, $totalFrames = null) {
        // 帧相位按「调用方给定的实际帧总数」归一化：序列实例数可与内置解剖帧数不同，
        // 若按内置帧数截断会导致超过该数量的帧全部相同（序列后段影像无变化）。
        $total = ($totalFrames !== null && (int)$totalFrames > 0) ? (int)$totalFrames : $this->frameCount;
        $last = $total > 0 ? $total - 1 : 0;
        $i = (int)$frameIndex;
        if ($i < 0) $i = 0;
        if ($i > $last) $i = $last;
        $p = $total > 1 ? $i / ($total - 1) : 0.0;

        $rows = (int)$this->rows;
        $cols = (int)$this->cols;
        $bytesPer = $this->bitsAllocated > 8 ? 2 : 1;
        $buf = '';
        for ($y = 0; $y < $rows; $y++) {
            $ny = ($y + 0.5) / $rows;
            for ($x = 0; $x < $cols; $x++) {
                $nx = ($x + 0.5) / $cols;
                $stored = $this->encodePixel($this->sample($nx, $ny, $p, $x, $y, $i));
                if ($bytesPer === 2) $buf .= pack('v', $stored & 0xFFFF);
                else $buf .= chr($stored & 0xFF);
            }
        }
        return $buf;
    }

    /**
     * 子类实现：返回指定帧的解剖强度（CT 为 HU，MR/DR/US 为相对信号）。
     */
    abstract protected function sample($nx, $ny, $p, $x, $y, $i);

    /**
     * 生成缩略图灰度字节（$size × $size，0..255），按推荐窗宽窗位映射。
     * 直接以低分辨率采样，避免生成整幅像素（约 (Rows/size)² 倍加速）；取中间帧
     * 作为代表，供侧栏缩略图端点使用。
     *
     * @param int $size 目标边长
     * @return string 长度 size*size 的二进制字符串
     */
    public function generateThumbnailGray($size) {
        $size = max(16, min(256, (int)$size));
        $i = $this->frameCount > 0 ? (int)intdiv($this->frameCount - 1, 2) : 0;
        $p = $this->frameCount > 1 ? $i / ($this->frameCount - 1) : 0.0;
        $lo = $this->windowCenter - $this->windowWidth / 2;
        $k = 255.0 / max(1.0, (float)$this->windowWidth);
        $cols = (int)$this->cols; $rows = (int)$this->rows;
        $out = '';
        for ($yy = 0; $yy < $size; $yy++) {
            $ny = ($yy + 0.5) / $size;
            $py = (int)($ny * $rows);
            for ($xx = 0; $xx < $size; $xx++) {
                $nx = ($xx + 0.5) / $size;
                $v = $this->sample($nx, $ny, $p, (int)($nx * $cols), $py, $i);
                $o = (int)round(($v - $lo) * $k);
                if ($o < 0) $o = 0; elseif ($o > 255) $o = 255;
                $out .= chr($o);
            }
        }
        return $out;
    }

    /** 强度 → DICOM 存储值（默认按 RescaleIntercept 平移到无符号 16 位） */
    protected function encodePixel($value) {
        $stored = (int)round($value - $this->rescaleIntercept);
        if ($stored < 0) $stored = 0;
        if ($stored > 65535) $stored = 65535;
        return $stored;
    }

    /**
     * 单帧固定的确定性随机源（同一帧多次生成结果一致）。
     * @return callable
     */
    protected function frameRng($i) {
        return PvMockProceduralNoise::rng(PvMockProceduralNoise::seed($this->seed . '#' . (int)$i));
    }

    /** 帧序号归一化参数（0..1） */
    protected function progress($i) {
        return $this->frameCount > 1 ? $i / ($this->frameCount - 1) : 0.0;
    }

    /* ---------------- 标准属性输出 ---------------- */

    /** 覆盖方位（用于重建序列元数据，不改变像素生成逻辑） */
    public function setOrientation($orientation) { $this->orientation = strtoupper((string)$orientation); }

    /** 覆盖切片数量（管理后台可配置） */
    public function setFrameCount($n) { $n = (int)$n; if ($n > 0) $this->frameCount = $n; }

    public function getFrameCount() { return $this->frameCount; }
    public function getOrientation() { return $this->orientation; }
    public function getSeriesDescription() { return $this->seriesDescription; }
    public function getBodyPartExamined() { return $this->bodyPartExamined; }
    public function getModality() { return $this->modality; }
    public function getSliceThickness() { return (float)$this->sliceThickness; }

    public function getDimensions() {
        return array(
            'rows' => (int)$this->rows,
            'columns' => (int)$this->cols,
            'bits_allocated' => (int)$this->bitsAllocated,
            'bits_stored' => (int)$this->bitsStored,
            'high_bit' => (int)$this->highBit,
            'pixel_representation' => (int)$this->pixelRepresentation,
            'samples_per_pixel' => (int)$this->samplesPerPixel,
            'photometric' => $this->photometric,
        );
    }

    public function getRecommendedWindow() {
        return array('center' => (float)$this->windowCenter, 'width' => (float)$this->windowWidth);
    }

    public function getPixelSpacing() {
        return array((float)$this->rowSpacing, (float)$this->colSpacing);
    }

    public function getModalitySpecificTags() {
        return array(
            'modality' => $this->modality,
            'body_part' => $this->bodyPartExamined,
            'orientation' => $this->orientation,
            'series_description' => $this->seriesDescription,
            'slice_thickness' => (float)$this->sliceThickness,
            'spacing_between_slices' => (float)$this->spacingBetweenSlices,
            'pixel_spacing' => $this->getPixelSpacing(),
            'rows' => (int)$this->rows,
            'columns' => (int)$this->cols,
            'bits_allocated' => (int)$this->bitsAllocated,
            'bits_stored' => (int)$this->bitsStored,
            'high_bit' => (int)$this->highBit,
            'pixel_representation' => (int)$this->pixelRepresentation,
            'samples_per_pixel' => (int)$this->samplesPerPixel,
            'photometric' => $this->photometric,
            'window_center' => (float)$this->windowCenter,
            'window_width' => (float)$this->windowWidth,
            'rescale_intercept' => (float)$this->rescaleIntercept,
            'rescale_slope' => (float)$this->rescaleSlope,
            'number_of_frames' => (int)$this->frameCount,
        );
    }
}
