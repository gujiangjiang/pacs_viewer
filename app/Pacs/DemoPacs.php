<?php
/**
 * ============================================================
 * app/Pacs/DemoPacs.php — 内置模拟 PACS 数据源
 * ============================================================
 * 当未配置远程 PACS 接口（或查询模式为 Demo）时，模拟返回已开单、
 * 已缴费、已登记并完成检查的患者检查数据，供搜索 / 调阅 / 接口测试。
 * 数据基于固定种子确定性生成，每次结果一致。
 * ============================================================ */
class PvDemoPacs {

    private static $studiesCache = null;

    /** 模拟检查列表（确定性；同一请求内记忆化，避免重复生成） */
    public static function studies() {
        if (self::$studiesCache !== null) return self::$studiesCache;
        self::$studiesCache = self::buildStudies();
        return self::$studiesCache;
    }

    private static function buildStudies() {
        $surnames = array('张', '王', '李', '赵', '刘', '陈', '杨', '黄', '周', '吴', '徐', '孙', '马', '朱', '胡', '郭');
        $male   = array('伟', '强', '磊', '洋', '勇', '军', '杰', '涛', '明', '超', '浩', '鹏');
        $female = array('芳', '娜', '敏', '静', '艳', '丽', '娟', '燕', '霞', '婷', '雪', '梅');
        $depts  = array('呼吸内科', '神经内科', '骨科', '普通外科', '心血管内科', '消化内科', '泌尿外科');
        $doctors = array('王医生', '李医生', '张医生', '刘医生', '陈医生');
        $site = self::institution();
        $mods = array(
            array('CT', array('胸部CT平扫', '头颅CT平扫', '腹部CT增强扫描', '颈椎CT平扫')),
            array('MR', array('颅脑MRI平扫', '颈椎MRI平扫', '腰椎MRI平扫')),
            array('DR', array('胸部正位片', '膝关节正侧位片', '腰椎正侧位片')),
            array('US', array('腹部彩超', '甲状腺彩超', '心脏彩超')),
        );
        $list = array();
        for ($i = 0; $i < 16; $i++) {
            $seed = 'demo-study-' . $i;
            $rng = self::rng($seed);
            $gender = $rng(0, 1) === 0 ? '男' : '女';
            $given = $gender === '男' ? $male : $female;
            $name = $surnames[$rng(0, count($surnames) - 1)] . $given[$rng(0, count($given) - 1)];
            $age = $rng(6, 88);
            $year = 2026 - $age;
            $birth = sprintf('%04d-%02d-%02d', $year, $rng(1, 12), $rng(1, 28));
            $pid = 'P' . str_pad((string)(100000 + $i * 137), 6, '0', STR_PAD_LEFT);
            $m = $mods[$rng(0, count($mods) - 1)];
            $modality = $m[0];
            $desc = $m[1][$rng(0, count($m[1]) - 1)];
            $day = sprintf('2026-%02d-%02d', 8 + intdiv($i, 4), 3 + ($i % 4) * 6);
            $acc = 'ACC' . str_replace('-', '', $day) . str_pad((string)(1 + $i), 3, '0', STR_PAD_LEFT);
            $list[] = array(
                'study_uid'     => '1.2.826.0.1.3680043.8.498.' . (100000 + $i * 977),
                'patient_id'    => $pid,
                'name'          => $name,
                'gender'        => $gender,
                'age'           => $age . '岁',
                'birth_date'    => $birth,
                'outpatient_no' => 'OPD2026' . str_pad((string)(1000 + $i), 4, '0', STR_PAD_LEFT),
                'accession_no'  => $acc,
                'modality'      => $modality,
                'description'   => $desc,
                'study_date'    => $day . ' ' . sprintf('%02d:%02d:00', 8 + ($i % 9), ($i * 7) % 60),
                'institution'   => $site,
                'station_name'  => $modality . '-ROOM-' . (1 + ($i % 3)),
                'apply_dept'    => $depts[$rng(0, count($depts) - 1)],
                'apply_doctor'  => $doctors[$rng(0, count($doctors) - 1)],
                'status'        => 'completed',   // 已缴费 + 已登记 + 已完成检查
                'series_count'  => ($modality === 'CT' || $modality === 'MR') ? 3 : 1,
            );
        }
        return $list;
    }

    /**
     * 关键词检索（姓名 / 患者号 / 检查号 / 门诊号 / 项目）。
     * @param int $limit  返回上限（0 表示不限）
     * @param int $offset 起始偏移
     */
    public static function search($keyword, $limit = 0, $offset = 0) {
        $kw = trim((string)$keyword);
        $out = array();
        foreach (self::studies() as $s) {
            if ($kw === '') { $out[] = $s; continue; }
            $hay = $s['name'] . ' ' . $s['patient_id'] . ' ' . $s['accession_no'] . ' ' . $s['outpatient_no'] . ' ' . $s['description'];
            if (mb_stripos($hay, $kw, 0, 'UTF-8') !== false) $out[] = $s;
        }
        if ((int)$limit > 0) return array_slice($out, max(0, (int)$offset), (int)$limit);
        if ((int)$offset > 0) return array_slice($out, (int)$offset);
        return $out;
    }

    /**
     * 由检索行组装完整检查（患者 + 检查 + 序列元数据）。
     * 供内置仿真数据与 FHIR 数据统一使用（字段缺失时安全降级）。
     */
    public static function assembleStudy(array $row) {
        $g = function ($k, $d = '') use ($row) { return isset($row[$k]) ? $row[$k] : $d; };
        $mod = strtoupper((string)$g('modality'));
        $patient = array(
            'patient_id'    => (string)$g('patient_id'),
            'name'          => (string)$g('name'),
            'gender'        => (string)$g('gender'),
            'age'           => (string)$g('age'),
            'birth_date'    => (string)$g('birth_date'),
            'outpatient_no' => (string)$g('outpatient_no'),
        );
        $st = array(
            'accession_no'    => (string)$g('accession_no'),
            'study_uid'       => (string)$g('study_uid'),
            'modality'        => $mod,
            'description'     => (string)$g('description'),
            'study_date'      => (string)$g('study_date'),
            'institution'     => (string)$g('institution'),
            'station_name'    => (string)$g('station_name'),
            'apply_dept'      => (string)$g('apply_dept'),
            'apply_doctor'    => (string)$g('apply_doctor'),
            'slice_thickness' => ($mod === 'CT' || $mod === 'MR') ? 5.0 : 0,
        );
        return array(
            'patient' => $patient,
            'study'   => $st,
            'series'  => self::seriesFor($mod, (string)$g('study_uid'), (string)$g('description')),
        );
    }

    /** 按 Study UID 取完整检查（亦支持按检查号） */
    public static function study($uid) {
        $row = null;
        foreach (self::studies() as $s) {
            if ($s['study_uid'] === $uid || (isset($s['accession_no']) && $s['accession_no'] === $uid)) { $row = $s; break; }
        }
        if (!$row) return null;
        return self::assembleStudy($row);
    }

    /** 序列元数据：委托模拟数据调度中心按检查部位 / 模态规划标准序列 */
    public static function seriesFor($modality, $seed, $description = '', $bodyPart = '') {
        return PvMockDispatcher::seriesPlan($modality, $description, $seed, $bodyPart);
    }

    public static function institution() { return PvMockServer::builtinInstitution(); }

    /* ---------- 确定性伪随机 ---------- */
    private static function rng($seedStr) {
        $s = crc32((string)$seedStr) & 0x7FFFFFFF;
        return function ($min, $max) use (&$s) {
            $s = (1103515245 * $s + 12345) & 0x7FFFFFFF;
            $v = (int)floor($min + ($s / 0x7FFFFFFF) * ($max - $min + 1));
            return $v > $max ? $max : $v;
        };
    }
}
