<?php
/**
 * ============================================================
 * app/Services/StudyService.php — 检查数据聚合服务
 * ------------------------------------------------------------
 * 影像与检查检索以 **PACS 接口（DICOMweb）为唯一来源**；患者信息由接口本身携带，
 * 因此不再需要 FHIR 作为真实接口的补充。
 * FHIR R4 仅作为**内置模拟服务器的患者数据来源**（见 PvMockServer / 模拟服务器页）。
 * ============================================================ */
class PvStudyService {

    /** 检索来源展示信息（用于检索页顶部标签） */
    public static function sourceInfo() {
        $endpoint = trim((string)PvSettings::get('pacs_endpoint', ''));
        if ($endpoint === '') {
            $state = 'unset'; $label = '未配置 PACS 接口';
        } elseif (PvMockServer::isSelfEndpoint($endpoint)) {
            $state = 'mock'; $label = '内置模拟 DICOMweb 服务器';
        } else {
            $state = 'remote';
            $host = parse_url($endpoint, PHP_URL_HOST);
            $label = '远程 DICOMweb 接口' . ($host ? ' · ' . $host : '');
        }
        return array('state' => $state, 'label' => $label);
    }

    /** 检索（分页）：数据来自 PACS/DICOMweb，补充展示字段后返回 {list,total,has_more} */
    public static function search($keyword, $limit = 0, $offset = 0) {
        $page = PvPacsClient::searchPage($keyword, $limit, $offset);
        $list = $page['list'];
        $site = pvw_hospital();
        foreach ($list as &$row) {
            if (empty($row['institution'])) $row['institution'] = $site;
            if (!isset($row['status_name']) || $row['status_name'] === '') $row['status_name'] = '已完成';
            if (!isset($row['has_images'])) $row['has_images'] = true;
            if (!isset($row['fhir'])) $row['fhir'] = false;
        }
        unset($row);
        return array('list' => $list, 'total' => $page['total'], 'has_more' => $page['has_more']);
    }

    /** 调阅：患者 + 检查 + 序列，补充默认展示字段 */
    public static function study($uid) {
        $d = PvPacsClient::study($uid);                 // DICOM / PACS 必选
        $site = pvw_hospital();
        if (empty($d['study']['institution'])) $d['study']['institution'] = $site;
        if (empty($d['study']['station_name'])) $d['study']['station_name'] = ($d['study']['modality'] . '-ROOM');
        $d['study']['default_ww'] = (int)PvSettings::get('viewer_default_ww', '400');
        $d['study']['default_wl'] = (int)PvSettings::get('viewer_default_wl', '40');
        $endpoint = (string)PvSettings::get('pacs_endpoint', '');
        $d['meta'] = array(
            'source' => !PvPacsClient::isRemote() ? 'demo' : (PvMockServer::isSelfEndpoint($endpoint) ? 'mock' : 'remote'),
            'mode'   => PvPacsClient::mode(),
            'fhir'   => false,
        );
        return $d;
    }
}
