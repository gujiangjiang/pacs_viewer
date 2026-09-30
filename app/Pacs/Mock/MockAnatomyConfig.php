<?php
/**
 * ============================================================
 * app/Pacs/Mock/MockAnatomyConfig.php — 解剖部位与切片数量配置
 * ============================================================
 * 允许在管理后台注册 / 维护「可用的解剖部位、匹配关键词、适用模态与切片数量」。
 * 配置以 JSON 存于设置表 mock_anatomy；为空时回退内置默认（与调度器规则一致）。
 *
 * 条目结构：{ modality, body_key, label, keywords, frames, enabled }
 *   modality  适用模态（CT/MR/DR/CR/US；空表示不限）
 *   body_key  解剖关键字（head/chest/lumbar/abdomen/knee/cardiac）
 *   keywords  逗号分隔的匹配关键词（中英均可）
 *   frames    切片数量（>0 生效）
 *   enabled   是否启用
 * ============================================================ */
class PvMockAnatomyConfig {

    const KEY = 'mock_anatomy';

    private static $cache = null;

    /** 内置默认条目（与 MockDispatcher 的默认路由一致） */
    public static function defaults() {
        $rows = array(
            array('head',    '颅脑',   '头,颅,脑,head,brain,cranium',          array('CT' => 40, 'MR' => 24)),
            array('cardiac', '心脏',   '心脏,心超,心彩,心动图,cardiac,heart',   array('US' => 24)),
            array('chest',   '胸部',   '胸,肺,thorax,lung,chest',               array('CT' => 40, 'DR' => 1, 'CR' => 1)),
            array('lumbar',  '腰椎脊柱', '腰,颈,脊柱,脊椎,spine,lumbar,cervical,vertebra,neck', array('CT' => 28, 'MR' => 20)),
            array('abdomen', '腹部',   '腹,肝,胆,脾,肾,abdomen,liver,kidney',   array('CT' => 32, 'US' => 20)),
            array('knee',    '膝/四肢', '膝,关节,四肢,腕,踝,肩,肘,knee,joint,limb', array('MR' => 16, 'DR' => 1, 'CR' => 1)),
        );
        $out = array();
        foreach ($rows as $r) {
            foreach ($r[3] as $mod => $frames) {
                $out[] = array(
                    'modality' => $mod,
                    'body_key' => $r[0],
                    'label' => $r[1],
                    'keywords' => $r[2],
                    'frames' => $frames,
                    'enabled' => true,
                );
            }
        }
        return $out;
    }

    /** 读取配置条目（空 / 非法时回退默认；同一请求内记忆化） */
    public static function entries() {
        if (self::$cache !== null) return self::$cache;
        $raw = trim((string)PvSettings::get(self::KEY, ''));
        if ($raw === '') { self::$cache = self::defaults(); return self::$cache; }
        $j = json_decode($raw, true);
        if (!is_array($j)) { self::$cache = self::defaults(); return self::$cache; }
        $out = array();
        foreach ($j as $e) {
            if (!is_array($e)) continue;
            $out[] = self::normalize($e);
        }
        self::$cache = $out;
        return self::$cache;
    }

    /** 保存配置（清空即恢复默认） */
    public static function save(array $entries) {
        $clean = array();
        foreach ($entries as $e) {
            if (!is_array($e)) continue;
            $n = self::normalize($e);
            if ($n['body_key'] === '' || trim($n['keywords']) === '') continue;
            $clean[] = $n;
        }
        PvSettings::set(self::KEY, json_encode($clean, JSON_UNESCAPED_UNICODE));
        self::$cache = null;
    }

    public static function reset() { PvSettings::set(self::KEY, ''); self::$cache = null; }

    /** 按 (模态, 部位) 查切片数量（未配置返回 0） */
    public static function framesFor($modality, $bodyKey) {
        if ($bodyKey === '') return 0;
        $modality = strtoupper((string)$modality);
        foreach (self::entries() as $e) {
            if (empty($e['enabled'])) continue;
            if ($e['body_key'] !== $bodyKey) continue;
            if ($e['modality'] !== '' && strtoupper($e['modality']) !== $modality) continue;
            if ((int)$e['frames'] > 0) return (int)$e['frames'];
        }
        return 0;
    }

    private static function normalize(array $e) {
        return array(
            'modality' => isset($e['modality']) ? strtoupper(trim((string)$e['modality'])) : '',
            'body_key' => isset($e['body_key']) ? trim((string)$e['body_key']) : '',
            'label' => isset($e['label']) ? trim((string)$e['label']) : '',
            'keywords' => isset($e['keywords']) ? trim((string)$e['keywords']) : '',
            'frames' => isset($e['frames']) ? (int)$e['frames'] : 0,
            'enabled' => !isset($e['enabled']) || $e['enabled'] === '1' || $e['enabled'] === 1 || $e['enabled'] === true,
        );
    }
}
