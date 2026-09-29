<?php
/**
 * ============================================================
 * app/Pacs/Mock/MockDispatcher.php — 模拟数据总控调度中心
 * ============================================================
 * 职责：
 *   1. 从请求上下文提取 Modality / StudyDescription / BodyPartExamined；
 *   2. 以正则分发树将「检查部位」映射到对应解剖模型生成器；
 *   3. 未匹配部位时按模态回退到默认切片生成器；
 *   4. 输出可供服务端接口使用的标准序列规划（Series Plan）。
 *
 * 本调度器与远程 PACS 代理、前端渲染完全解耦：只负责「选型」与「实例化」。
 * ============================================================ */
class PvMockDispatcher {

    /* ---------------- 解剖部位识别 ---------------- */

    /**
     * 依据检查描述 / 检查部位识别解剖关键字。
     * @return string head|chest|lumbar|abdomen|knee|''
     */
    public static function bodyKey($description, $bodyPart = '') {
        $text = mb_strtolower(trim((string)$description . ' ' . (string)$bodyPart), 'UTF-8');
        if ($text === '') return '';
        $rules = array(
            'head'    => '/(头|颅|脑|head|brain|cranium|cerebr)/iu',
            'cardiac' => '/(心脏|心超|心彩|心动图|cardiac|heart|echocardio)/iu',
            'chest'   => '/(胸|肺|thorax|thoracic|lung|chest|pulmo)/iu',
            'lumbar'  => '/(腰|颈|脊柱|脊椎|spine|spinal|lumbar|cervical|thoracic spine|vertebra|neck)/iu',
            'abdomen' => '/(腹|肝|胆|脾|肾|abdomen|abdominal|liver|hepat|kidney|renal)/iu',
            'knee'    => '/(膝|关节|四肢|腕|踝|肩|肘|knee|joint|limb|extremity|femur|tibia)/iu',
        );
        foreach ($rules as $key => $re) {
            if (preg_match($re, $text)) return $key;
        }
        return '';
    }

    /** 部位支持下限（供文档 / 验收报告使用） */
    public static function supportedParts() {
        return array('head', 'chest', 'lumbar', 'abdomen', 'knee');
    }

    /* ---------------- 生成器实例化 ---------------- */

    /**
     * 创建解剖模型生成器。
     *
     * @param array $ctx 上下文：
     *   modality  模态（CT/MR/DR/CR/US）
     *   body_key  解剖关键字（可空，空则按模态回退）
     *   seed      确定性种子
     *   weight    MR 权重（T1/T2）
     *   orientation 覆盖方位（可选）
     * @return PvMockAbstractGenerator
     */
    public static function createGenerator(array $ctx) {
        $modality = strtoupper(isset($ctx['modality']) ? $ctx['modality'] : 'CT');
        $body = isset($ctx['body_key']) ? $ctx['body_key'] : '';
        $seed = isset($ctx['seed']) ? $ctx['seed'] : 'mock';
        $weight = isset($ctx['weight']) ? strtoupper($ctx['weight']) : 'T1';
        $orientation = isset($ctx['orientation']) ? $ctx['orientation'] : null;

        $class = self::resolveClass($modality, $body);
        $gen = new $class($seed, $weight);
        if ($orientation !== null && $orientation !== '') {
            $gen->setOrientation($orientation);
        }
        return $gen;
    }

    /** 解析类名（模态 + 部位 → 生成器；未匹配则按模态回退） */
    private static function resolveClass($modality, $body) {
        $map = array(
            'CT' => array('head' => 'PvMockHeadCT', 'chest' => 'PvMockChestCT', 'lumbar' => 'PvMockLumbarCT', 'abdomen' => 'PvMockAbdomenCT', 'knee' => 'PvMockLumbarCT'),
            'MR' => array('head' => 'PvMockHeadMR', 'lumbar' => 'PvMockLumbarMR', 'knee' => 'PvMockKneeMR', 'chest' => 'PvMockHeadMR', 'abdomen' => 'PvMockLumbarMR'),
            'DR' => array('chest' => 'PvMockChestDR', 'head' => 'PvMockLimbDR', 'lumbar' => 'PvMockLimbDR', 'abdomen' => 'PvMockLimbDR', 'knee' => 'PvMockLimbDR'),
            'CR' => array('chest' => 'PvMockChestDR', 'head' => 'PvMockLimbDR', 'lumbar' => 'PvMockLimbDR', 'abdomen' => 'PvMockLimbDR', 'knee' => 'PvMockLimbDR'),
            'US' => array('abdomen' => 'PvMockAbdomenUS', 'cardiac' => 'PvMockCardiacUS', 'head' => 'PvMockAbdomenUS', 'chest' => 'PvMockCardiacUS', 'lumbar' => 'PvMockAbdomenUS', 'knee' => 'PvMockAbdomenUS'),
        );
        $defaults = array('CT' => 'PvMockChestCT', 'MR' => 'PvMockHeadMR', 'DR' => 'PvMockChestDR', 'CR' => 'PvMockChestDR', 'US' => 'PvMockAbdomenUS');

        if (isset($map[$modality]) && $body !== '' && isset($map[$modality][$body])) {
            return $map[$modality][$body];
        }
        return isset($defaults[$modality]) ? $defaults[$modality] : 'PvMockHeadCT';
    }

    /* ---------------- 序列规划 ---------------- */

    /**
     * 为一次检查生成标准序列规划（供 search / study 接口与 DICOM 端点使用）。
     *
     * @return array 序列数组，元素含 series_id / description / orientation /
     *               slice_count / slice_thickness / pixel_spacing / seed /
     *               is_mock / body_key / modality / weight / class
     */
    public static function seriesPlan($modality, $description, $studyUid, $bodyPart = '') {
        $modality = strtoupper((string)$modality);
        $body = self::bodyKey($description, $bodyPart);
        $out = array();

        if ($modality === 'CT' || $modality === 'MR') {
            if ($modality === 'MR') {
                $weights = array(array('1', 'T1WI', 'T1'), array('2', 'T2WI', 'T2'));
            } else {
                $weights = array(array('1', 'Axial', null));
            }
            foreach ($weights as $w) {
                $ctx = array('modality' => $modality, 'body_key' => $body, 'seed' => 'p|' . $studyUid . '|s' . $w[0], 'weight' => $w[2] ? $w[2] : 'T1');
                $gen = self::createGenerator($ctx);
                $out[] = self::seriesMeta($w[0], $w[1], $gen, $body, $modality, $w[2], $ctx['seed']);
            }
            // 重建序列（元数据；主序列用于 DICOM 端点）
            $recon = array(array('9', 'Coronal Reconstruction', 'CORONAL'), array('10', 'Sagittal Reconstruction', 'SAGITTAL'));
            foreach ($recon as $r) {
                $ctx = array('modality' => $modality, 'body_key' => $body, 'seed' => 'p|' . $studyUid . '|s' . $r[0], 'weight' => 'T1', 'orientation' => $r[2]);
                $gen = self::createGenerator($ctx);
                $out[] = self::seriesMeta($r[0], $r[1], $gen, $body, $modality, null, $ctx['seed'], $r[2], false);
            }
            return $out;
        }

        // DR / CR / US：单序列
        $ctx = array('modality' => $modality, 'body_key' => $body, 'seed' => 'p|' . $studyUid . '|s1');
        $gen = self::createGenerator($ctx);
        $desc = $gen->getSeriesDescription();
        $out[] = self::seriesMeta('1', $desc, $gen, $body, $modality, null, $ctx['seed']);
        return $out;
    }

    /** 组装单条序列元数据（兼容既有前端字段） */
    private static function seriesMeta($id, $label, PvMockAbstractGenerator $gen, $body, $modality, $weight, $seed, $orientation = null, $primary = true) {
        $ori = $orientation !== null ? $orientation : $gen->getOrientation();
        $count = $gen->getFrameCount();
        if (!$primary && ($ori === 'CORONAL' || $ori === 'SAGITTAL')) $count = max(6, (int)round($count * 0.6));
        $ps = $gen->getPixelSpacing();
        return array(
            'series_id' => (string)$id,
            'description' => $label,
            'orientation' => $ori,
            'slice_count' => (int)$count,
            'is_mock' => true,
            'slice_thickness' => $gen->getSliceThickness(),
            'pixel_spacing' => $ps[0],
            'seed' => $seed,
            'body_key' => $body,
            'modality' => $modality,
            'weight' => $weight,
            'generator' => get_class($gen),
            'primary' => (bool)$primary,
            'images' => array(),
        );
    }

    /**
     * 取得「可按需生成标准 DICOM 像素」的序列生成器。
     * 仅主序列（Axial / T1 / T2 / DR / US）可生成标准像素；重建序列返回 null。
     */
    public static function generatorForSeries($modality, $description, $studyUid, $seriesIndex, $bodyPart = '') {
        $plan = self::seriesPlan($modality, $description, $studyUid, $bodyPart);
        $idx = (int)$seriesIndex;
        if (!isset($plan[$idx])) return null;
        $s = $plan[$idx];
        if (empty($s['primary'])) return null;
        return self::createGenerator(array(
            'modality' => $modality,
            'body_key' => $s['body_key'],
            'seed' => $s['seed'],
            'weight' => $s['weight'] ? $s['weight'] : 'T1',
        ));
    }
}
