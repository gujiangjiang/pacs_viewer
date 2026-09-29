<?php
/**
 * app/Services/StudyService.php — 检查数据聚合服务
 * ------------------------------------------------------------
 * · 影像与检查检索以 **DICOM / PACS 接口为必选基础**；
 * · **FHIR R4 为可选的患者信息补充**（非二选一）：命中则用 FHIR 主数据丰富
 *   患者姓名 / 性别 / 出生日期 / 年龄 / 门诊号等，并可补充「已登记但暂无影像」
 *   的检查（两者不同步时优雅共处，互不影响）。
 */
class PvStudyService {

    private static $lastFhirError = '';

    /** 最近一次检索中 FHIR 的错误（为空表示无错误） */
    public static function lastFhirError() { return self::$lastFhirError; }

    /** 是否启用 FHIR 患者信息补充 */
    public static function isFhirEnabled() {
        return (string)PvSettings::get('fhir_enabled', '0') === '1';
    }

    /** 检索来源展示信息（用于检索页顶部标签） */
    public static function sourceInfo() {
        $endpoint = trim((string)PvSettings::get('pacs_endpoint', ''));
        if ($endpoint === '') {
            $state = 'unset'; $label = '未配置 PACS 接口';
        } elseif (PvMockServer::isSelfEndpoint($endpoint)) {
            $state = 'mock'; $label = '内置模拟 PACS 服务器';
        } else {
            $state = 'remote';
            $host = parse_url($endpoint, PHP_URL_HOST);
            $label = '远程 PACS 接口' . ($host ? ' · ' . $host : '');
        }
        return array('state' => $state, 'label' => $label, 'fhir' => self::isFhirEnabled());
    }

    /** 检索：PACS 为基础，FHIR 可选补充 */
    public static function search($keyword) {
        self::$lastFhirError = '';
        $list = PvPacsClient::search($keyword);                 // DICOM / PACS 必选
        $site = pvw_hospital();
        foreach ($list as &$row) {
            if (empty($row['institution'])) $row['institution'] = $site;
            $row['status_name'] = '已完成';
            $row['has_images'] = true;
            $row['fhir'] = false;
        }
        unset($row);

        if (self::isFhirEnabled()) {
            try {
                $fhir = PvFhirClient::search($keyword);
                $list = self::mergeFhir($list, $fhir);
            } catch (Exception $e) {
                self::$lastFhirError = $e->getMessage();        // FHIR 异常不影响 PACS 检索结果
            }
        }
        return $list;
    }

    /** 调阅：影像必来自 PACS，FHIR 仅补充患者主数据 */
    public static function study($uid) {
        if (strpos((string)$uid, 'fhir-') === 0) {
            throw new RuntimeException('该检查仅有 FHIR 登记信息，暂无影像数据');
        }
        $d = PvPacsClient::study($uid);                         // DICOM / PACS 必选
        $fhirUsed = false;
        if (self::isFhirEnabled()) {
            try { $fhirUsed = self::enrichStudyPatient($d); } catch (Exception $e) { /* 忽略，保留 PACS 数据 */ }
        }
        $site = pvw_hospital();
        if (empty($d['study']['institution'])) $d['study']['institution'] = $site;
        if (empty($d['study']['station_name'])) $d['study']['station_name'] = ($d['study']['modality'] . '-ROOM');
        $d['study']['default_ww'] = (int)PvSettings::get('viewer_default_ww', '400');
        $d['study']['default_wl'] = (int)PvSettings::get('viewer_default_wl', '40');
        $endpoint = (string)PvSettings::get('pacs_endpoint', '');
        $d['meta'] = array(
            'source' => !PvPacsClient::isRemote() ? 'demo' : (PvMockServer::isSelfEndpoint($endpoint) ? 'mock' : 'remote'),
            'mode'   => PvPacsClient::mode(),
            'fhir'   => $fhirUsed,
        );
        return $d;
    }

    /* ---------------- FHIR 合并（优雅处理不同步） ---------------- */

    /** 患者匹配键：姓名 + 出生日期 */
    private static function nameKey($name, $birth) {
        $n = preg_replace('/\s+/', '', (string)$name);
        if ($n === '') return '';
        $b = preg_replace('/\D/', '', (string)$birth);
        return $n . '|' . $b;
    }

    /** 将 FHIR 检查合并进 PACS 列表：命中则补充患者信息，未命中则作为「仅登记」条目追加 */
    private static function mergeFhir(array $list, array $fhir) {
        $byAcc = array(); $byName = array();
        foreach ($fhir as $r) {
            $acc = strtoupper(trim((string)(isset($r['accession_no']) ? $r['accession_no'] : '')));
            if ($acc !== '') $byAcc[$acc] = $r;
            $nk = self::nameKey(isset($r['name']) ? $r['name'] : '', isset($r['birth_date']) ? $r['birth_date'] : '');
            if ($nk !== '') $byName[$nk] = $r;
        }
        $used = array();
        foreach ($list as &$row) {
            $acc = strtoupper(trim((string)(isset($row['accession_no']) ? $row['accession_no'] : '')));
            $m = null;
            if ($acc !== '' && isset($byAcc[$acc])) $m = $byAcc[$acc];
            else {
                $nk = self::nameKey(isset($row['name']) ? $row['name'] : '', isset($row['birth_date']) ? $row['birth_date'] : '');
                if ($nk !== '' && isset($byName[$nk])) $m = $byName[$nk];
            }
            if ($m) {
                foreach (array('name', 'gender', 'age', 'birth_date', 'outpatient_no') as $k) {
                    if (!empty($m[$k])) $row[$k] = $m[$k];
                }
                if (!empty($m['patient_id'])) $row['fhir_patient_id'] = $m['patient_id'];
                $row['fhir'] = true;
                if (isset($m['study_uid'])) $used[$m['study_uid']] = 1;
            }
        }
        unset($row);
        /* FHIR 有登记但 PACS 暂无影像：补充展示为「仅登记」条目 */
        foreach ($fhir as $r) {
            $su = isset($r['study_uid']) ? $r['study_uid'] : '';
            if ($su !== '' && isset($used[$su])) continue;
            $r['fhir'] = true;
            $r['has_images'] = false;
            $r['source'] = 'fhir';
            $r['status_name'] = '已登记 · 暂无影像';
            $list[] = $r;
        }
        return $list;
    }

    /** 调阅时用 FHIR 补充患者主数据；返回是否命中 */
    private static function enrichStudyPatient(&$d) {
        $acc = isset($d['study']['accession_no']) ? (string)$d['study']['accession_no'] : '';
        $name = isset($d['patient']['name']) ? (string)$d['patient']['name'] : '';
        $birth = isset($d['patient']['birth_date']) ? (string)$d['patient']['birth_date'] : '';
        $fhir = PvFhirClient::search($acc !== '' ? $acc : $name);
        foreach ($fhir as $r) {
            $accMatch = $acc !== '' && strtoupper(trim((string)$r['accession_no'])) === strtoupper(trim($acc));
            $nameMatch = self::nameKey(isset($r['name']) ? $r['name'] : '', isset($r['birth_date']) ? $r['birth_date'] : '') === self::nameKey($name, $birth);
            if (!$accMatch && !$nameMatch) continue;
            foreach (array('name', 'gender', 'age', 'birth_date', 'outpatient_no') as $k) {
                if (!empty($r[$k])) $d['patient'][$k] = $r[$k];
            }
            if (!empty($r['patient_id'])) $d['patient']['fhir_patient_id'] = $r['patient_id'];
            return true;
        }
        return false;
    }
}
