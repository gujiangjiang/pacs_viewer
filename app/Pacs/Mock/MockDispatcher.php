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
     * 优先使用管理后台维护的配置关键词，未命中再回退内置规则。
     * @return string head|chest|lumbar|abdomen|knee|cardiac|''
     */
    public static function bodyKey($description, $bodyPart = '', $modality = '') {
        $text = mb_strtolower(trim((string)$description . ' ' . (string)$bodyPart), 'UTF-8');
        if ($text === '') return '';

        /* 管理后台配置优先 */
        $modality = strtoupper((string)$modality);
        foreach (PvMockAnatomyConfig::entries() as $e) {
            if (empty($e['enabled'])) continue;
            if ($modality !== '' && $e['modality'] !== '' && strtoupper($e['modality']) !== $modality) continue;
            $parts = array_filter(array_map('trim', explode(',', (string)$e['keywords'])));
            if (!$parts) continue;
            $re = '/(?:' . implode('|', array_map(function ($p) { return preg_quote($p, '/'); }, $parts)) . ')/iu';
            if (preg_match($re, $text)) return $e['body_key'];
        }

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
        /* 管理后台配置的切片数量优先 */
        $frames = PvMockAnatomyConfig::framesFor($modality, $body);
        if ($frames > 0) $gen->setFrameCount($frames);
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
     * 每条序列附带标准 DICOM 帧地址（images）与像素参数，通用前端据此按
     * 标准 DICOM 协议取像、解码、渲染，无需任何模拟特化分支。
     *
     * @return array 序列数组
     */
    public static function seriesPlan($modality, $description, $studyUid, $bodyPart = '') {
        $modality = strtoupper((string)$modality);
        $body = self::bodyKey($description, $bodyPart, $modality);
        $out = array();

        if ($modality === 'CT' || $modality === 'MR') {
            $weights = $modality === 'MR'
                ? array(array('1', 'T1WI', 'T1'), array('2', 'T2WI', 'T2'))
                : array(array('1', 'Axial', null));
            foreach ($weights as $w) {
                $seed = 'p|' . $studyUid . '|s' . $w[0];
                $ctx = array('modality' => $modality, 'body_key' => $body, 'seed' => $seed, 'weight' => $w[2] ? $w[2] : 'T1');
                $gen = self::createGenerator($ctx);
                $out[] = self::seriesMeta($w[0], $w[1], $gen, $body, $modality, $w[2], $seed, $studyUid);
            }
            return $out;
        }

        // DR / CR / US：单序列
        $seed = 'p|' . $studyUid . '|s1';
        $gen = self::createGenerator(array('modality' => $modality, 'body_key' => $body, 'seed' => $seed));
        $out[] = self::seriesMeta('1', $gen->getSeriesDescription(), $gen, $body, $modality, null, $seed, $studyUid);
        return $out;
    }

    /** 组装单条序列元数据（含标准 DICOM 帧地址与像素参数） */
    private static function seriesMeta($id, $label, PvMockAbstractGenerator $gen, $body, $modality, $weight, $seed, $studyUid) {
        $dim = $gen->getDimensions();
        $ps = $gen->getPixelSpacing();
        $win = $gen->getRecommendedWindow();
        $tags = $gen->getModalitySpecificTags();
        $count = (int)$gen->getFrameCount();
        $seriesNo = (int)$id;

        $images = array();
        $ver = substr(md5(PV_VERSION . '|' . $modality . '|' . $body . '|' . $weight . '|' . $count), 0, 8);
        for ($i = 1; $i <= $count; $i++) {
            $images[] = pvw_url('dicom', array('uid' => $studyUid, 'series' => $seriesNo, 'instance' => $i, 'v' => $ver));
        }
        $thumb = pvw_url('thumb', array('uid' => $studyUid, 'series' => $seriesNo, 'v' => $ver));

        return array(
            'series_id' => (string)$id,
            'description' => $label,
            'orientation' => $gen->getOrientation(),
            'slice_count' => $count,
            'is_mock' => false,
            'format' => 'dicom',
            'is_hu' => ($modality !== 'US'),
            'slice_thickness' => $gen->getSliceThickness(),
            'pixel_spacing' => $ps[0],
            'rows' => (int)$dim['rows'],
            'columns' => (int)$dim['columns'],
            'bits_allocated' => (int)$dim['bits_allocated'],
            'bits_stored' => (int)$dim['bits_stored'],
            'pixel_representation' => (int)$dim['pixel_representation'],
            'window_center' => (float)$win['center'],
            'window_width' => (float)$win['width'],
            'rescale_intercept' => (float)(isset($tags['rescale_intercept']) ? $tags['rescale_intercept'] : 0),
            'rescale_slope' => (float)(isset($tags['rescale_slope']) ? $tags['rescale_slope'] : 1),
            'seed' => $seed,
            'body_key' => $body,
            'modality' => $modality,
            'weight' => $weight,
            'generator' => get_class($gen),
            'primary' => true,
            'images' => $images,
            'thumbnail' => $thumb,
        );
    }

    /**
     * 取得「可按需生成标准 DICOM 像素」的序列生成器。
     * 所有序列均为解剖主序列，可直接输出标准像素。
     */
    public static function generatorForSeries($modality, $description, $studyUid, $seriesIndex, $bodyPart = '') {
        $plan = self::seriesPlan($modality, $description, $studyUid, $bodyPart);
        $idx = (int)$seriesIndex;
        if (!isset($plan[$idx])) return null;
        $s = $plan[$idx];
        return self::createGenerator(array(
            'modality' => $modality,
            'body_key' => $s['body_key'],
            'seed' => $s['seed'],
            'weight' => $s['weight'] ? $s['weight'] : 'T1',
        ));
    }
}
