<?php
/**
 * ============================================================
 * app/Pacs/Mock/Contracts/VolumeGeneratorInterface.php
 * ============================================================
 * 三维 / 多帧序列生成器通用接口。
 *
 * 描述一个「序列」的自有属性：总帧数、方位、序列描述、检查部位与模态，
 * 供调度器与 DICOM 封装层构建标准 Instance / Series 结构。
 * 帧级像素生成仍由 SliceGeneratorInterface 提供。
 * ============================================================ */
interface PvMockVolumeGeneratorInterface {

    /** 序列总帧数（连续断层切片数） */
    public function getFrameCount();

    /** 图像方位：AXIAL / CORONAL / SAGITTAL / PA / US 等 */
    public function getOrientation();

    /** 序列描述（SeriesDescription） */
    public function getSeriesDescription();

    /** 检查部位（BodyPartExamined 标准值） */
    public function getBodyPartExamined();

    /** 模态（Modality）：CT / MR / DR / CR / US 等 */
    public function getModality();
}
