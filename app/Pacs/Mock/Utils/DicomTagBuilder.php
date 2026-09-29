<?php
/**
 * ============================================================
 * app/Pacs/Mock/Utils/DicomTagBuilder.php — 标准 DICOM 数据集组装
 * ============================================================
 * 将生成器输出严格封装为 DICOM Part 10 文件（128 字节前导 + 'DICM' +
 * File Meta Information + 数据集），数据集采用 Explicit VR Little Endian
 * 传输语法。仅写入标准 Tag，不含任何「模拟 / 伪数据」私有标记。
 * ============================================================ */
class PvMockDicomTagBuilder {

    /* 传输语法 */
    const TS_IMPLICIT_LE = '1.2.840.10008.1.2';
    const TS_EXPLICIT_LE = '1.2.840.10008.1.2.1';

    /* SOP Class（按模态选择标准存储类） */
    const SOP_CT        = '1.2.840.10008.5.1.4.1.1.2';
    const SOP_MR        = '1.2.840.10008.5.1.4.1.1.4';
    const SOP_CR        = '1.2.840.10008.5.1.4.1.1.1';
    const SOP_SC        = '1.2.840.10008.5.1.4.1.1.7';       // 二次采集
    const SOP_US        = '1.2.840.10008.5.1.4.1.1.6.1';
    const SOP_US_MF     = '1.2.840.10008.5.1.4.1.1.3.1';

    const IMPLEMENTATION_CLASS_UID = '1.2.826.0.1.3680043.8.498.1.1';
    const IMPLEMENTATION_VERSION   = 'PVMOCK_1_0';

    /** 按模态返回标准 SOP Class UID */
    public static function sopClassFor($modality) {
        switch (strtoupper((string)$modality)) {
            case 'CT': return self::SOP_CT;
            case 'MR': return self::SOP_MR;
            case 'CR': return self::SOP_CR;
            case 'DR': return self::SOP_CR;
            case 'US': return self::SOP_US;
            default:   return self::SOP_SC;
        }
    }

    /**
     * 组装 DICOM 文件。
     *
     * @param array  $m 标准元数据（见调用处约定）
     * @param string $pixelData 已编码的像素字节流
     * @return string 完整 DICOM Part 10 字节流
     */
    public static function build(array $m, $pixelData) {
        $ts = isset($m['transfer_syntax']) ? $m['transfer_syntax'] : self::TS_EXPLICIT_LE;

        /* ---------- File Meta Information（始终 Explicit VR LE） ---------- */
        $meta = '';
        $meta .= self::el(0x0002, 0x0001, 'OB', "\x00\x01");                                   // FileMetaInformationVersion
        $meta .= self::el(0x0002, 0x0002, 'UI', self::_s($m, 'sop_class_uid', self::SOP_SC));   // MediaStorageSOPClassUID
        $meta .= self::el(0x0002, 0x0003, 'UI', self::_s($m, 'sop_instance_uid'));              // MediaStorageSOPInstanceUID
        $meta .= self::el(0x0002, 0x0010, 'UI', $ts);                                           // TransferSyntaxUID
        $meta .= self::el(0x0002, 0x0012, 'UI', self::IMPLEMENTATION_CLASS_UID);                 // ImplementationClassUID
        $meta .= self::el(0x0002, 0x0013, 'SH', self::IMPLEMENTATION_VERSION);                   // ImplementationVersionName
        $meta = self::el(0x0002, 0x0000, 'UL', pack('V', strlen($meta))) . $meta;               // FileMetaInformationGroupLength

        /* ---------- 数据集（Explicit VR LE） ---------- */
        $ds = '';
        $ds .= self::el(0x0008, 0x0005, 'CS', self::_s($m, 'charset', 'ISO_IR 192'));           // SpecificCharacterSet(UTF-8)
        $ds .= self::el(0x0008, 0x0016, 'UI', self::_s($m, 'sop_class_uid', self::SOP_SC));
        $ds .= self::el(0x0008, 0x0018, 'UI', self::_s($m, 'sop_instance_uid'));
        $ds .= self::el(0x0008, 0x0020, 'DA', self::_s($m, 'study_date'));
        $ds .= self::el(0x0008, 0x0030, 'TM', self::_s($m, 'study_time'));
        $ds .= self::el(0x0008, 0x0050, 'SH', self::_s($m, 'accession_number'));
        $ds .= self::el(0x0008, 0x0060, 'CS', strtoupper(self::_s($m, 'modality')));
        $ds .= self::el(0x0008, 0x0070, 'LO', self::_s($m, 'manufacturer', 'PACMOCK'));
        $ds .= self::el(0x0008, 0x0080, 'LO', self::_s($m, 'institution'));
        $ds .= self::el(0x0008, 0x0090, 'PN', self::_s($m, 'referring_physician'));
        $ds .= self::el(0x0008, 0x1030, 'LO', self::_s($m, 'study_description'));
        $ds .= self::el(0x0008, 0x103E, 'LO', self::_s($m, 'series_description'));
        $ds .= self::el(0x0008, 0x1050, 'PN', self::_s($m, 'performing_physician'));
        $ds .= self::el(0x0010, 0x0010, 'PN', self::_s($m, 'patient_name'));
        $ds .= self::el(0x0010, 0x0020, 'LO', self::_s($m, 'patient_id'));
        $ds .= self::el(0x0010, 0x0030, 'DA', self::_s($m, 'patient_birth_date'));
        $ds .= self::el(0x0010, 0x0040, 'CS', self::_s($m, 'patient_sex'));
        $ds .= self::el(0x0018, 0x0050, 'DS', self::ds(self::_f($m, 'slice_thickness', 0)));
        $ds .= self::el(0x0018, 0x0088, 'DS', self::ds(self::_f($m, 'spacing_between_slices', self::_f($m, 'slice_thickness', 0))));
        if (self::_s($m, 'body_part') !== '') $ds .= self::el(0x0018, 0x0015, 'CS', self::_s($m, 'body_part'));
        $ds .= self::el(0x0018, 0x1020, 'LO', self::IMPLEMENTATION_VERSION);
        $ds .= self::el(0x0020, 0x000D, 'UI', self::_s($m, 'study_uid'));
        $ds .= self::el(0x0020, 0x000E, 'UI', self::_s($m, 'series_uid'));
        $ds .= self::el(0x0020, 0x0011, 'IS', (string)(int)self::_f($m, 'series_number', 1));
        $ds .= self::el(0x0020, 0x0013, 'IS', (string)(int)self::_f($m, 'instance_number', 1));
        if (!empty($m['image_position'])) $ds .= self::el(0x0020, 0x0032, 'DS', self::ds($m['image_position']));
        if (!empty($m['image_orientation'])) $ds .= self::el(0x0020, 0x0037, 'DS', self::ds($m['image_orientation']));
        $ds .= self::el(0x0020, 0x1041, 'DS', self::ds(self::_f($m, 'slice_location', 0)));
        $ds .= self::el(0x0028, 0x0002, 'US', self::us(self::_f($m, 'samples_per_pixel', 1)));
        $ds .= self::el(0x0028, 0x0004, 'CS', self::_s($m, 'photometric', 'MONOCHROME2'));
        if ((int)self::_f($m, 'number_of_frames', 0) > 1) $ds .= self::el(0x0028, 0x0008, 'IS', (string)(int)self::_f($m, 'number_of_frames', 0));
        $ds .= self::el(0x0028, 0x0010, 'US', self::us(self::_f($m, 'rows', 512)));
        $ds .= self::el(0x0028, 0x0011, 'US', self::us(self::_f($m, 'columns', 512)));
        if (!empty($m['pixel_spacing'])) $ds .= self::el(0x0028, 0x0030, 'DS', self::ds($m['pixel_spacing']));
        $ds .= self::el(0x0028, 0x0100, 'US', self::us(self::_f($m, 'bits_allocated', 16)));
        $ds .= self::el(0x0028, 0x0101, 'US', self::us(self::_f($m, 'bits_stored', 16)));
        $ds .= self::el(0x0028, 0x0102, 'US', self::us(self::_f($m, 'high_bit', 15)));
        $ds .= self::el(0x0028, 0x0103, 'US', self::us(self::_f($m, 'pixel_representation', 0)));
        if (isset($m['window_center'])) {
            $wc = is_array($m['window_center']) ? $m['window_center'] : array($m['window_center']);
            $ww = is_array($m['window_width']) ? $m['window_width'] : array($m['window_width']);
            $ds .= self::el(0x0028, 0x1050, 'DS', self::ds($wc));
            $ds .= self::el(0x0028, 0x1051, 'DS', self::ds($ww));
        }
        if (isset($m['rescale_intercept'])) $ds .= self::el(0x0028, 0x1052, 'DS', self::ds(self::_f($m, 'rescale_intercept', 0)));
        if (isset($m['rescale_slope']))     $ds .= self::el(0x0028, 0x1053, 'DS', self::ds(self::_f($m, 'rescale_slope', 1)));
        $ds .= self::el(0x7FE0, 0x0010, ((int)self::_f($m, 'bits_allocated', 16) > 8) ? 'OW' : 'OB', $pixelData);

        return str_repeat("\x00", 128) . 'DICM' . $meta . $ds;
    }

    /* ---------------- 元素编码 ---------------- */

    private static function el($group, $element, $vr, $value) {
        $value = (string)$value;
        if (strlen($value) % 2 === 1) {
            $pad = ($vr === 'UI' || $vr === 'OB' || $vr === 'OW' || $vr === 'UN') ? "\x00" : ' ';
            $value .= $pad;
        }
        $tag = pack('vv', $group, $element);
        if (in_array($vr, array('OB', 'OW', 'OF', 'SQ', 'UT', 'UN'), true)) {
            return $tag . $vr . "\x00\x00" . pack('V', strlen($value)) . $value;
        }
        return $tag . $vr . pack('v', strlen($value)) . $value;
    }

    /* ---------------- 值编码 ---------------- */

    private static function us($v) { return pack('v', ((int)$v) & 0xFFFF); }
    private static function ss($v) { return pack('v', ((int)$v) & 0xFFFF); }

    /** DS 数值 / 数组 → 反斜杠分隔的 ASCII 字符串 */
    public static function ds($v) {
        if (is_array($v)) {
            $parts = array();
            foreach ($v as $x) $parts[] = self::num($x);
            return implode('\\', $parts);
        }
        return self::num($v);
    }

    private static function num($x) {
        $f = (float)$x;
        if ($f == (int)$f) return (string)(int)$f;
        $s = rtrim(rtrim(sprintf('%.6f', $f), '0'), '.');
        return $s === '' || $s === '-' ? '0' : $s;
    }

    private static function _s(array $m, $k, $default = '') {
        return isset($m[$k]) && $m[$k] !== null ? (string)$m[$k] : (string)$default;
    }
    private static function _f(array $m, $k, $default = 0.0) {
        return isset($m[$k]) ? (float)$m[$k] : (float)$default;
    }

    /* ---------------- UID 工具 ---------------- */

    /** 由根 UID 派生标准子 UID（保证同一序列内树状拓扑） */
    public static function deriveUid($root, array $suffix) {
        $root = preg_replace('/[^0-9.]/', '', (string)$root);
        $root = trim($root, '.');
        if ($root === '') $root = '1.2.826.0.1.3680043.8.498';
        $out = $root;
        foreach ($suffix as $s) {
            $s = (int)$s;
            $out .= '.' . str_pad((string)$s, 3, '0', STR_PAD_LEFT);
        }
        if (strlen($out) > 64) $out = substr($out, 0, 64);
        return trim($out, '.');
    }
}
