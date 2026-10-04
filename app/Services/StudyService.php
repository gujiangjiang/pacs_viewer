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

    /** 数据来源状态（用于页脚「服务器已连接」展示；不暴露主机 / IP） */
    public static function sourceInfo() {
        $endpoint = trim((string)PvSettings::get('pacs_endpoint', ''));
        if ($endpoint === '') {
            return array('state' => 'unset', 'label' => 'DICOMweb 服务器未连接');
        }
        if (PvMockServer::isSelfEndpoint($endpoint)) {
            return array('state' => 'mock', 'label' => 'DICOMweb 服务器已连接 · 内置模拟');
        }
        return array('state' => 'remote', 'label' => 'DICOMweb 服务器已连接 · 远程');
    }

    /** 记录远程接口返回的机构名称（DICOMweb InstitutionName）到 pacs_hospital_name */
    private static function captureHospital($name) {
        $name = trim((string)$name);
        if ($name === '') return;
        $endpoint = (string)PvSettings::get('pacs_endpoint', '');
        if (!PvPacsClient::isRemote() || PvMockServer::isSelfEndpoint($endpoint)) return;
        if (trim((string)PvSettings::get('pacs_hospital_name', '')) !== $name) {
            PvSettings::set('pacs_hospital_name', $name);
        }
    }

    /** 检索（分页）：数据来自 PACS/DICOMweb，补充展示字段后返回 {list,total,has_more} */
    public static function search($keyword, $limit = 0, $offset = 0, $filters = array()) {
        $page = PvPacsClient::searchPage($keyword, $limit, $offset, $filters);
        $list = $page['list'];
        $site = pvw_hospital();
        foreach ($list as &$row) {
            if (!empty($row['institution'])) self::captureHospital($row['institution']);
            else $row['institution'] = $site;
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
        if (!empty($d['study']['institution'])) self::captureHospital($d['study']['institution']);
        // DICOM 详情 / 影像预览 / 报告机构名：接口返回值优先，缺失回退项目名称（默认医院）
        if (empty($d['study']['institution'])) $d['study']['institution'] = pvw_hospital_source();
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
