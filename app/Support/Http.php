<?php
/**
 * ============================================================
 * app/Support/Http.php — 轻量 HTTP GET 助手（curl 优先，file 回退）
 * ============================================================
 * 返回 {body, code}，便于 DICOMweb 客户端与代理复用；不抛异常。
 * ============================================================ */
class PvHttp {

    public static function get($url, $timeout = 5, array $headers = array()) {
        $timeout = max(1, (int)$timeout);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => $headers,
            ));
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (PHP_VERSION_ID < 80500) curl_close($ch);   // 8.5 起 curl_close 已弃用
            return array('body' => $body, 'code' => $code);
        }
        $ctx = stream_context_create(array('http' => array(
            'method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true,
            'header' => implode("\r\n", $headers) . "\r\n",
        )));
        $body = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (PHP_VERSION_ID < 80500 && isset($http_response_header) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $code = (int)$m[1];                                  // PHP < 8.5 的 $http_response_header
        } elseif (function_exists('http_get_last_response_headers')) {
            $hdrs = http_get_last_response_headers();
            if (is_array($hdrs) && isset($hdrs[0]) && preg_match('#\s(\d{3})\s#', $hdrs[0], $m)) $code = (int)$m[1];
        }
        return array('body' => $body, 'code' => $code);
    }
}
