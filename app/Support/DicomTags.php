<?php
/**
 * ============================================================
 * app/Support/DicomTags.php — 标准 DICOM 标签号常量
 * ============================================================
 * DICOM JSON（DICOMweb）以「8 位十六进制标签号」为键，字符串常量散落多处易写错。
 * 本类集中声明项目内用到的标准标签，供 DICOMweb 客户端 / 端点复用。
 * 纯常量、无状态、无依赖。
 * ============================================================ */
class PvDicomTags {

    /* 0008 组：检查基本属性 */
    const SOP_INSTANCE_UID        = '00080018';
    const STUDY_DATE              = '00080020';
    const STUDY_TIME              = '00080030';
    const ACCESSION_NUMBER        = '00080050';
    const MODALITY                = '00080060';
    const MODALITIES_IN_STUDY     = '00080061';
    const INSTITUTION_NAME        = '00080080';
    const STATION_NAME            = '00081010';
    const STUDY_DESCRIPTION       = '00081030';
    const SERIES_DESCRIPTION      = '0008103E';

    /* 0010 组：患者属性 */
    const PATIENT_NAME            = '00100010';
    const PATIENT_ID              = '00100020';
    const PATIENT_BIRTH_DATE      = '00100030';
    const PATIENT_SEX             = '00100040';
    const PATIENT_OTHER_IDS       = '00101000';
    const PATIENT_AGE             = '00101010';

    /* 0018 组：采集属性 */
    const SLICE_THICKNESS         = '00180050';

    /* 0020 组：关系 / 实例 */
    const STUDY_INSTANCE_UID      = '0020000D';
    const SERIES_INSTANCE_UID     = '0020000E';
    const INSTANCE_NUMBER         = '00200013';
    const IMAGE_ORIENTATION       = '00200037';
    const NUM_STUDY_SERIES        = '00201206';
    const NUM_STUDY_INSTANCES     = '00201208';
    const NUM_SERIES_INSTANCES    = '00201209';

    /* 0028 组：图像像素 */
    const NUMBER_OF_FRAMES        = '00280008';
    const ROWS                    = '00280010';
    const COLUMNS                 = '00280011';
    const PIXEL_SPACING           = '00280030';
    const BITS_ALLOCATED          = '00280100';
    const BITS_STORED             = '00280101';
    const PIXEL_REPRESENTATION    = '00280103';
    const WINDOW_CENTER           = '00281050';
    const WINDOW_WIDTH            = '00281051';
    const RESCALE_INTERCEPT       = '00281052';
    const RESCALE_SLOPE           = '00281053';
}
