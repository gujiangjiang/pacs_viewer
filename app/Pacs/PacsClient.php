<?php
/**
 * ============================================================
 * app/Pacs/PacsClient.php — PACS 接口门面（标准 DICOMweb）
 * ============================================================
 * 本 PACS 浏览器通过**标准 DICOMweb**（QIDO-RS 检索 / WADO-RS 取像）对接 PACS，
 * 实现委托给 PvDicomWebClient。自定义 JSON 网关已移除。
 *
 * DICOM 网络身份参数（AE Title / 主机 / 端口）见设置项 pacs_ae_title /
 * pacs_remote_ae / pacs_server_host / pacs_server_port，供传统 DICOM（DIMSE）
 * 环境标识与对接展示使用（浏览器不能直连 DIMSE，DIMSE 需经网关转 DICOMweb）。
 * ============================================================ */
class PvPacsClient {

    /** 数据来源模式：Remote（已配置接口地址）/ Unset（未配置） */
    public static function mode() {
        return self::isRemote() ? 'Remote' : 'Unset';
    }

    public static function isRemote() {
        return self::endpoint() !== '';
    }

    /** DICOMweb 根地址 */
    public static function endpoint() {
        return rtrim((string)PvSettings::get('pacs_endpoint', ''), '/');
    }

    /* ---------- 检索 / 调阅（DICOMweb） ---------- */

    /** 检索检查列表（仅列表） */
    public static function search($keyword) {
        $page = self::searchPage($keyword);
        return $page['list'];
    }

    /** 分页检索：{list,total,has_more} */
    public static function searchPage($keyword, $limit = 0, $offset = 0) {
        return PvDicomWebClient::search($keyword, $limit, $offset);
    }

    /** 调阅单次检查（患者 + 检查 + 序列） */
    public static function study($uid) {
        return PvDicomWebClient::study($uid);
    }

    /** 接口连通性测试 */
    public static function ping() {
        return PvDicomWebClient::ping();
    }

    /** 使用指定配置测试连通性（不读取已保存设置） */
    public static function pingWith($endpoint, $key = null, $timeout = null) {
        return PvDicomWebClient::pingWith($endpoint, $key, $timeout);
    }
}
