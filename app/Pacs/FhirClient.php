<?php
/**
 * ============================================================
 * app/Pacs/FhirClient.php — 门诊系统 FHIR R4 客户端（只读）
 * ============================================================
 * 内置模拟 PACS 服务器的患者数据来源之一：通过 FHIR R4 从门诊一体化系统
 * （Clinic_OPD_System 等）获取「已缴费、已登记」的患者及其检查信息。
 *
 * 取数口径（约定）：
 *   1) 列表/工作台：`GET {fhir}/ImagingStudy?_count=100&_include=ImagingStudy:patient`
 *      —— 一次请求同时带回检查与其患者资源，避免逐个 Patient/{id} 回读（消除 N+1）。
 *   2) 关键字检索：先按姓名/标识检索 Patient（name / identifier / _id），
 *      再对匹配到的患者查询 ImagingStudy?patient=Patient/{id}&_count=50。
 *      存在 ImagingStudy 即视为「已缴费并已登记」（门诊系统在登记后创建该资源）。
 *   3) 资源同时兼容「Patient / ImagingStudy 以 Bundle 返回」与「直接数组返回」。
 *
 * 认证（可选）：配置密钥后同时发送 Authorization: Bearer 与 X-API-Key 头。
 * 返回结构与内置模拟数据保持一致，便于模拟服务器统一对外。
 *
 * 实现按职责拆分于 app/Pacs/Fhir/ 下的 trait（HTTP / 检索 / 报告 / 映射 / 工作列表）。
 * ============================================================ */
class PvFhirClient {

    use PvFhirHttpTrait;      // 配置 / HTTP / 连通性测试 / 工作项回写（app/Pacs/Fhir/FhirHttpTrait.php）
    use PvFhirSearchTrait;    // 检索与调阅（app/Pacs/Fhir/FhirSearchTrait.php）
    use PvFhirReportTrait;    // 影像报告（app/Pacs/Fhir/FhirReportTrait.php）
    use PvFhirMapTrait;       // 资源映射 / Bundle 解析（app/Pacs/Fhir/FhirMapTrait.php）
    use PvFhirWorklistTrait;  // 摄片工作列表（app/Pacs/Fhir/FhirWorklistTrait.php）

    /** 临时配置覆盖（仅用于「测试当前输入」场景，请求结束后清空） */
    private static $override = null;
    /** 最近一次检索的错误信息（便于界面提示） */
    private static $lastError = '';
    /** 最近一次 HTTP 请求的响应状态码（0 表示未收到响应） */
    private static $lastStatus = 0;
    /** 机构名称缓存（FHIR Organization.name） */
    private static $hospital = null;
    /** 请求级检索结果缓存（同一请求内避免对同一关键词重复取数） */
    private static $searchCache = array();
}
