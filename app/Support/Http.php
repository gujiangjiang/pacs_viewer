<?php
/**
 * ============================================================
 * app/Support/Http.php — 轻量 HTTP GET 助手（curl 优先，file 回退）
 * ============================================================
 * 返回 {body, code}，便于 DICOMweb 客户端与代理复用；不抛异常。
 * ============================================================ */
class PvHttp {

    public static function get($url, $timeout = 5, array $headers = array()) {
        return self::request('GET', $url, $timeout, $headers, null);
    }

    /**
     * 通用 HTTP 请求（curl 优先，file 回退），支持任意方法与请求体。
     * @param string      $method  GET / POST / PUT / PATCH / DELETE
     * @param string      $url
     * @param int         $timeout
     * @param array       $headers
     * @param string|null $body    请求体（null 表示无）
     * @return array {body, code}
     */
    public static function request($method, $url, $timeout = 5, array $headers = array(), $body = null) {
        $timeout = max(1, (int)$timeout);
        $method = strtoupper((string)$method);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opt = array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
            );
            if ($body !== null) $opt[CURLOPT_POSTFIELDS] = $body;
            curl_setopt_array($ch, $opt);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (PHP_VERSION_ID < 80500) curl_close($ch);   // 8.5 起 curl_close 已弃用
            return array('body' => $resp, 'code' => $code);
        }
        $ctxArr = array(
            'method' => $method, 'timeout' => $timeout, 'ignore_errors' => true,
            'header' => implode("\r\n", $headers) . "\r\n",
        );
        if ($body !== null) $ctxArr['content'] = (string)$body;
        $ctx = stream_context_create(array('http' => $ctxArr));
        $resp = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (PHP_VERSION_ID < 80500 && isset($http_response_header) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $code = (int)$m[1];                                  // PHP < 8.5 的 $http_response_header
        } elseif (function_exists('http_get_last_response_headers')) {
            $hdrs = http_get_last_response_headers();
            if (is_array($hdrs) && isset($hdrs[0]) && preg_match('#\s(\d{3})\s#', $hdrs[0], $m)) $code = (int)$m[1];
        }
        return array('body' => $resp, 'code' => $code);
    }

    /**
     * 查询串 / URL 密钥参数脱敏（token / api_key / key），用于日志与协议记录。
     * 兼容「URL（以 ? 或 & 起段）」与「纯查询串（以 ^ 或 & 起段）」两种输入。
     * @param string $str
     * @return string
     */
    public static function redactQuery($str) {
        return preg_replace('/(^|[?&])(token|api_key|key)=[^&]*/i', '$1', (string)$str);
    }

    /**
     * 统一下发二进制响应（DICOM / PNG 等），设置类型 / 长度 / 缓存，可选文件名。
     * 发送后直接结束请求（与各调用点原有 `echo …; exit;` 行为一致）。
     * @param string      $body
     * @param string      $mime
     * @param int         $cacheTtl 秒（<=0 不发送 Cache-Control）
     * @param string|null $filename 内容处置文件名（null 表示不发送该头）
     */
    public static function sendBinary($body, $mime, $cacheTtl = 0, $filename = null) {
        if (!headers_sent()) {
            header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
            header('Content-Length: ' . strlen((string)$body));
            if ((int)$cacheTtl > 0) header('Cache-Control: private, max-age=' . (int)$cacheTtl);
            if ($filename !== null && $filename !== '') {
                header('Content-Disposition: inline; filename="' . str_replace('"', '', (string)$filename) . '"');
            }
        }
        echo $body;
        exit;
    }
}
