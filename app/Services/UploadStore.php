<?php
/**
 * ============================================================
 * app/Services/UploadStore.php — 通用上传存储（Web 根之外 + 鉴权下载）
 * ============================================================
 * 约定：所有用户上传文件存放于运行时目录 data/uploads/<category>/，**不在 Web 根
 * 之下**，未经授权无法通过 URL 直连；下载统一经路由 ?r=file&t=令牌 鉴权下发。
 *
 * 安全要点：
 *   · 文件类型按真实内容（finfo / getimagesize）探测，仅允许白名单类型；
 *   · 落盘文件名为随机令牌（不含用户输入），杜绝路径穿越与覆盖；
 *   · 记录写入 uploads 表，令牌 → 文件一一对应，删除按记录清理。
 *
 * 该能力为通用基础设施，供自定义图标、影像 / 资料上传等功能复用。
 * ============================================================ */
class PvUploadStore {

    const MAX_SIZE = 20971520;   // 单文件上限 20MB

    /** 允许的 MIME → 扩展名白名单 */
    private static $allowed = array(
        'image/png'     => 'png',
        'image/jpeg'    => 'jpg',
        'image/gif'     => 'gif',
        'image/webp'    => 'webp',
        'application/pdf' => 'pdf',
        'text/plain'    => 'txt',
    );

    public static function allowedTypes() { return array_keys(self::$allowed); }

    /** 上传根目录（Web 根之外）；可指定分类子目录，自动创建 */
    public static function dir($category = '') {
        $base = PV_DATA . '/uploads';
        $cat = self::sanitizeCategory($category);
        if ($cat !== '') $base .= '/' . $cat;
        if (!is_dir($base)) @mkdir($base, 0775, true);
        return $base;
    }

    public static function sanitizeCategory($category) {
        $c = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$category));
        return substr($c, 0, 32);
    }

    /**
     * 保存上传文件。
     * @param array  $file     $_FILES 中的单个条目
     * @param string $category 分类（子目录）
     * @param string $uploader 上传者用户名
     * @return array 记录信息（含 token / url 需由调用方补全）
     */
    public static function save($file, $category = 'general', $uploader = '') {
        if (!is_array($file) || !isset($file['error'])) throw new RuntimeException('未接收到上传文件');
        $err = (int)$file['error'];
        if ($err !== UPLOAD_ERR_OK) throw new RuntimeException(self::errorText($err));
        $size = isset($file['size']) ? (int)$file['size'] : 0;
        if ($size <= 0) throw new RuntimeException('上传文件为空');
        if ($size > self::MAX_SIZE) throw new RuntimeException('文件过大（上限 ' . round(self::MAX_SIZE / 1048576) . 'MB）');
        $tmp = isset($file['tmp_name']) ? $file['tmp_name'] : '';
        if ($tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('上传文件无效');

        $mime = self::detectMime($tmp);
        if ($mime === '' || !isset(self::$allowed[$mime])) {
            throw new RuntimeException('不支持的文件类型' . ($mime !== '' ? '：' . $mime : ''));
        }
        $ext = self::$allowed[$mime];
        $category = self::sanitizeCategory($category);
        if ($category === '') $category = 'general';

        $token = bin2hex(random_bytes(16));
        $stored = $token . '.' . $ext;
        $dest = self::dir($category) . '/' . $stored;
        if (!move_uploaded_file($tmp, $dest)) throw new RuntimeException('文件保存失败，请确认 data/uploads/ 目录可写');

        $orig = isset($file['name']) ? self::safeName((string)$file['name']) : '';
        PvDatabase::insert(
            "INSERT INTO uploads(token,category,orig_name,stored_name,mime,size,uploader,created_at) VALUES(?,?,?,?,?,?,?,?)",
            array($token, $category, $orig, $stored, $mime, $size, (string)$uploader, date('Y-m-d H:i:s'))
        );
        return array(
            'token'         => $token,
            'category'      => $category,
            'orig_name'     => $orig,
            'stored_name'   => $stored,
            'mime'          => $mime,
            'size'          => $size,
        );
    }

    public static function find($token) {
        if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/', $token)) return null;
        return PvDatabase::one("SELECT * FROM uploads WHERE token=?", array($token));
    }

    /** 由记录解析磁盘绝对路径（不信任任何用户输入） */
    public static function path($row) {
        return self::dir($row['category']) . '/' . basename($row['stored_name']);
    }

    /** 删除文件与记录 */
    public static function deleteByToken($token) {
        $row = self::find($token);
        if (!$row) return false;
        $path = self::path($row);
        if (is_file($path)) @unlink($path);
        PvDatabase::exec("DELETE FROM uploads WHERE id=?", array((int)$row['id']));
        return true;
    }

    /* ---------------- 内部工具 ---------------- */

    private static function detectMime($tmp) {
        if (function_exists('finfo_open')) {
            $fi = @finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $m = @finfo_file($fi, $tmp);
                if (PHP_VERSION_ID < 80500) finfo_close($fi);   // 8.5 起 finfo_close 已弃用
                if ($m) return (string)$m;
            }
        }
        if (function_exists('mime_content_type')) {
            $m = @mime_content_type($tmp);
            if ($m) return (string)$m;
        }
        if (function_exists('getimagesize')) {
            $info = @getimagesize($tmp);
            if ($info && isset($info['mime'])) return (string)$info['mime'];
        }
        return '';
    }

    /** 清洗原始文件名（仅用于展示 / 下载建议名） */
    private static function safeName($name) {
        $name = str_replace(array("\r", "\n", "\0"), '', (string)$name);
        $name = basename(str_replace('\\', '/', $name));
        return mb_substr($name, 0, 120, 'UTF-8');
    }

    private static function errorText($code) {
        $map = array(
            UPLOAD_ERR_INI_SIZE   => '文件超过服务器允许大小',
            UPLOAD_ERR_FORM_SIZE  => '文件超过表单允许大小',
            UPLOAD_ERR_PARTIAL    => '文件只上传了一部分',
            UPLOAD_ERR_NO_FILE    => '没有选择文件',
            UPLOAD_ERR_NO_TMP_DIR => '缺少临时目录',
            UPLOAD_ERR_CANT_WRITE => '写入磁盘失败',
            UPLOAD_ERR_EXTENSION  => '上传被扩展阻止',
        );
        return isset($map[$code]) ? $map[$code] : ('上传失败（错误码 ' . $code . '）');
    }
}
