<?php
/**
 * ============================================================
 * app/Pacs/Mock/Contracts/SliceGeneratorInterface.php
 * ============================================================
 * 单切片 / 单帧生成器核心接口。
 *
 * 约定：实现方按标准 DICOM 语义务必只输出「像素缓冲」与「标准元数据」，
 * 不得携带任何「模拟 / 伪数据」标记；由上层 DicomTagBuilder 统一封装为
 * 标准 DICOM 数据集。
 * ============================================================ */
interface PvMockSliceGeneratorInterface {

    /**
     * 生成指定帧的二进制像素缓冲（小端序，长度严格等于
     * Rows * Columns * (BitsAllocated / 8)）。
     *
     * @param int      $frameIndex 帧序号（0 起）
     * @param int|null $totalFrames 总帧数，缺省时取 getFrameCount()
     * @return string 二进制像素数据
     */
    public function generateFrame($frameIndex, $totalFrames = null);

    /**
     * 像素矩阵规格。
     * @return array {rows:int, columns:int, bits_allocated:int, bits_stored:int, high_bit:int, pixel_representation:int, samples_per_pixel:int, photometric:string}
     */
    public function getDimensions();

    /**
     * 推荐窗宽窗位（WindowCenter / WindowWidth）。
     * @return array {center:float, width:float}
     */
    public function getRecommendedWindow();

    /**
     * 像素间距（Row spacing, Column spacing），单位 mm。
     * @return array {0:float, 1:float}
     */
    public function getPixelSpacing();

    /** 层厚（mm） */
    public function getSliceThickness();

    /**
     * 模态专属标准 Tag（不含像素与通用标识 Tag）。
     * @return array 关联数组，键为标准 DICOM 关键字
     */
    public function getModalitySpecificTags();
}
