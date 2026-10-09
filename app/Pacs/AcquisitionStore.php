<?php
/**
 * app/Pacs/AcquisitionStore.php — 模拟服务器摄片记录存取
 *
 * 模拟 PACS「摄片」完成后落库（mock_acquisitions），并为该检查分配
 * StudyInstanceUID，使其经 DICOMweb 可被检出、取像。影像像素仍由内置
 * 生成器按模态实时生成（与既有内置序列规划一致），本表仅存检查账与主数据。
 */
class PvAcquisitionStore {

    /** 分配标准 StudyInstanceUID（A2：按 检查号+检查项目 确定性派生；重复摄片幂等） */
    public static function assignStudyUid($accession, $seed = '') {
        $a = (string)$accession;
        $seed = (string)$seed;
        if ($a === '' && $seed === '') $a = (string)microtime(true);
        $key = 'mock-pacs|' . $a;
        if ($seed !== '') $key .= '|' . $seed;
        $u = sprintf('%u', crc32($key));
        return '1.2.826.0.1.3680043.8.498.' . $u . '.1';
    }

    /** 按检查号 / 研究 UID 查记录（返回首条） */
    public static function find($key) {
        $key = trim((string)$key);
        if ($key === '') return null;
        $r = PvDatabase::one("SELECT * FROM mock_acquisitions WHERE study_uid=? OR accession_no=? ORDER BY id DESC LIMIT 1", array($key, $key));
        return $r ?: null;
    }

    /** 按 检查号 + 检查项目（task_ref）查记录（A2：一申请单多项各一条 Study） */
    public static function findByTask($accession, $taskRef) {
        $accession = trim((string)$accession);
        $taskRef = trim((string)$taskRef);
        if ($accession === '') return null;
        if ($taskRef === '') return self::find($accession);
        $r = PvDatabase::one("SELECT * FROM mock_acquisitions WHERE accession_no=? AND task_ref=? ORDER BY id DESC LIMIT 1", array($accession, $taskRef));
        return $r ?: null;
    }

    /** 某检查号下的全部摄片记录（A2：多 Study 共享 Accession） */
    public static function allByAccession($accession) {
        $accession = trim((string)$accession);
        if ($accession === '') return array();
        return PvDatabase::q("SELECT * FROM mock_acquisitions WHERE accession_no=? ORDER BY id", array($accession));
    }

    /** 全部/关键字检索（姓名 / 检查号 / 患者号 / 项目） */
    public static function all($keyword = '', $limit = 500) {
        $limit = max(1, min(2000, (int)$limit));
        $kw = trim((string)$keyword);
        if ($kw === '') {
            return PvDatabase::q("SELECT * FROM mock_acquisitions ORDER BY id DESC LIMIT " . $limit);
        }
        $like = '%' . $kw . '%';
        return PvDatabase::q(
            "SELECT * FROM mock_acquisitions WHERE name LIKE ? OR accession_no LIKE ? OR patient_id LIKE ? OR description LIKE ? ORDER BY id DESC LIMIT " . $limit,
            array($like, $like, $like, $like)
        );
    }

    /**
     * 保存摄片记录（按检查号幂等 upsert）。
     * @param array $d task_ref,accession_no,patient_id,name,gender,birth_date,outpatient_no,modality,description,operator
     * @return array 记录行
     */
    public static function save(array $d) {
        $acc = trim((string)(isset($d['accession_no']) ? $d['accession_no'] : ''));
        $taskRef = trim((string)(isset($d['task_ref']) ? $d['task_ref'] : ''));
        $existing = $acc !== '' ? self::findByTask($acc, $taskRef) : null;
        $now = date('Y-m-d H:i:s');
        $uid = $existing ? (string)$existing['study_uid'] : self::assignStudyUid($acc, $taskRef);
        if ($existing) {
            PvDatabase::exec(
                "UPDATE mock_acquisitions SET patient_id=?, name=?, gender=?, birth_date=?, outpatient_no=?, modality=?, description=?, acquired_at=?, operator=? WHERE id=?",
                array(
                    (string)(isset($d['patient_id']) ? $d['patient_id'] : $existing['patient_id']),
                    (string)(isset($d['name']) ? $d['name'] : $existing['name']),
                    (string)(isset($d['gender']) ? $d['gender'] : $existing['gender']),
                    (string)(isset($d['birth_date']) ? $d['birth_date'] : $existing['birth_date']),
                    (string)(isset($d['outpatient_no']) ? $d['outpatient_no'] : $existing['outpatient_no']),
                    (string)(isset($d['modality']) ? $d['modality'] : $existing['modality']),
                    (string)(isset($d['description']) ? $d['description'] : $existing['description']),
                    $now,
                    (string)(isset($d['operator']) ? $d['operator'] : $existing['operator']),
                    (int)$existing['id'],
                )
            );
            return self::find($acc);
        }
        PvDatabase::insert(
            "INSERT INTO mock_acquisitions(task_ref,accession_no,study_uid,patient_id,name,gender,birth_date,outpatient_no,modality,description,registered_at,acquired_at,operator,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            array(
                (string)(isset($d['task_ref']) ? $d['task_ref'] : ''),
                $acc, $uid,
                (string)(isset($d['patient_id']) ? $d['patient_id'] : ''),
                (string)(isset($d['name']) ? $d['name'] : ''),
                (string)(isset($d['gender']) ? $d['gender'] : ''),
                (string)(isset($d['birth_date']) ? $d['birth_date'] : ''),
                (string)(isset($d['outpatient_no']) ? $d['outpatient_no'] : ''),
                (string)(isset($d['modality']) ? $d['modality'] : ''),
                (string)(isset($d['description']) ? $d['description'] : ''),
                (string)(isset($d['registered_at']) ? $d['registered_at'] : ''),
                $now,
                (string)(isset($d['operator']) ? $d['operator'] : ''),
                $now,
            )
        );
        return self::find($acc);
    }

    /** 摄片记录 → 检索行结构（供 QIDO / 调阅；序列由内置规划生成） */
    public static function asRow($acq) {
        if (!is_array($acq)) return null;
        $birth = (string)(isset($acq['birth_date']) ? $acq['birth_date'] : '');
        return array(
            'study_uid'     => (string)$acq['study_uid'],
            'patient_id'    => (string)$acq['patient_id'],
            'name'          => (string)$acq['name'],
            'gender'        => (string)$acq['gender'],
            'age'           => PvDicom::ageText($birth),
            'birth_date'    => $birth,
            'outpatient_no' => (string)$acq['outpatient_no'],
            'accession_no'  => (string)$acq['accession_no'],
            'modality'      => (string)$acq['modality'] !== '' ? (string)$acq['modality'] : 'OT',
            'description'   => (string)$acq['description'] !== '' ? (string)$acq['description'] : '影像检查',
            'study_date'    => (string)$acq['acquired_at'],
            'institution'   => PvMockServer::builtinInstitution(),
            'station_name'  => ((string)$acq['modality'] !== '' ? (string)$acq['modality'] : 'OT') . '-ROOM',
            'apply_dept'    => '',
            'apply_doctor'  => '',
            'status'        => 'completed',
            'series_count'  => 1,
            'acquired'      => true,
        );
    }

    /** 全部摄片记录 → 检索行列表 */
    public static function rows($keyword = '') {
        $out = array();
        foreach (self::all($keyword) as $a) {
            $row = self::asRow($a);
            if ($row) $out[] = $row;
        }
        return $out;
    }
}
