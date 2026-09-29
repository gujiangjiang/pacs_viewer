<?php
/**
 * app/Controllers/UploadController.php — 通用上传 / 鉴权下载
 * ============================================================
 * GET  ?r=file&t=令牌[&download=1]   鉴权下载（默认图片内联预览，其余强制下载）
 * POST ?r=upload                    上传（需登录 + CSRF），字段 file[、category]
 * POST ?r=upload/delete             删除（需登录 + CSRF；管理员或上传者本人）
 * ============================================================ */
class PvUploadController {

    /** 上传 */
    public static function upload() {
        PvAuth::requireLoginJson();
        pvw_csrf_check();
        @set_time_limit(60);
        try {
            $u = PvAuth::user();
            $file = isset($_FILES['file']) ? $_FILES['file'] : null;
            $row = PvUploadStore::save($file, (string)pvw_input('category', 'general'), $u['username']);
            pvw_json(200, '上传成功', array(
                'token'        => $row['token'],
                'name'         => $row['orig_name'],
                'mime'         => $row['mime'],
                'size'         => (int)$row['size'],
                'url'          => pvw_url('file', array('t' => $row['token'])),
                'download_url' => pvw_url('file', array('t' => $row['token'], 'download' => 1)),
            ));
        } catch (Exception $e) {
            pvw_json(400, $e->getMessage());
        }
    }

    /** 鉴权下载 / 内联预览 */
    public static function file() {
        PvAuth::requireLoginJson();
        $row = PvUploadStore::find((string)pvw_input('t'));
        if (!$row) { http_response_code(404); echo '文件不存在'; exit; }
        $path = PvUploadStore::path($row);
        if (!is_file($path)) { http_response_code(404); echo '文件已丢失'; exit; }

        $mime = (string)$row['mime'];
        $inline = (strpos($mime, 'image/') === 0) && !pvw_input('download');
        $name = $row['orig_name'] !== '' ? $row['orig_name'] : $row['stored_name'];

        if (!headers_sent()) {
            header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
            header('Content-Length: ' . filesize($path));
            header('X-Content-Type-Options: nosniff');
            header('Content-Security-Policy: default-src \'none\'; sandbox');
            header('Cache-Control: private, max-age=86400');
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
                . '; filename="' . str_replace('"', '', $name) . '"'
                . "; filename*=UTF-8''" . rawurlencode($name));
        }
        readfile($path);
        exit;
    }

    /** 删除（管理员或上传者本人） */
    public static function delete() {
        PvAuth::requireLoginJson();
        pvw_csrf_check();
        $token = (string)pvw_input('t');
        $row = PvUploadStore::find($token);
        if (!$row) pvw_json(404, '文件不存在');
        $u = PvAuth::user();
        if (!PvAuth::isAdmin() && (string)$row['uploader'] !== (string)$u['username']) {
            pvw_json(403, '无权删除该文件');
        }
        PvUploadStore::deleteByToken($token);
        pvw_json(200, '文件已删除');
    }
}
