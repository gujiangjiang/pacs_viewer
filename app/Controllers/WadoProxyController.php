<?php
/**
 * ============================================================
 * app/Controllers/WadoProxyController.php — DICOMweb 影像代理
 * ============================================================
 * 浏览器不能直接跨域访问远端 DICOMweb 时，由本服务端按已配置的 DICOMweb 接口
 * 代为获取实例字节流（WADO-RS），同源下发，避免跨域与密钥外泄。
 *   GET ?r=wadoprx&study=&series=&instance=   （需登录）
 * ============================================================ */
class PvWadoProxyController {

    public static function instance() {
        PvAuth::requireLogin();
        @set_time_limit(120);   // 大序列 / 首次生成可能较慢
        if (!PvPacsClient::isDicomWeb()) { self::fail(404, '当前接口协议不是 DICOMweb'); }

        $study = (string)pvw_input('study');
        $series = (string)pvw_input('series');
        $inst = (string)pvw_input('instance');
        if ($study === '' || $series === '' || $inst === '') self::fail(400, '缺少参数');

        $base = PvDicomWebClient::base();
        if ($base === '') self::fail(400, '未配置 DICOMweb 接口地址');
        $url = $base . '/studies/' . rawurlencode($study)
            . '/series/' . rawurlencode($series) . '/instances/' . rawurlencode($inst);

        $headers = array('Accept: application/dicom');
        $key = trim((string)PvSettings::get('pacs_api_key', ''));
        if ($key !== '') { $headers[] = 'Authorization: Bearer ' . $key; $headers[] = 'X-API-Key: ' . $key; }

        // 影像可能较大且远端生成较慢，超时取 max(60, 配置值)
        $timeout = max(60, (int)PvSettings::get('pacs_timeout', '5'));
        $r = PvHttp::get($url, $timeout, $headers);
        if ($r['body'] === false || $r['body'] === '') self::fail(502, '无法获取影像：' . $url);

        $body = self::extractDicom($r['body']);
        if (!headers_sent()) {
            header('Content-Type: application/dicom');
            header('Content-Length: ' . strlen($body));
            header('Cache-Control: private, max-age=86400');
        }
        echo $body;
        exit;
    }

    /** 若为 multipart/related（WADO-RS 可能返回），提取其中的 DICOM 字节流 */
    private static function extractDicom($body) {
        if (substr($body, 128, 4) === 'DICM') return $body;
        $p = strpos($body, 'DICM');
        if ($p !== false && $p >= 128) return substr($body, $p - 128);
        return $body;
    }

    private static function fail($code, $msg) {
        if (!headers_sent()) { http_response_code((int)$code); header('Content-Type: text/plain; charset=utf-8'); }
        echo $msg;
        exit;
    }
}
