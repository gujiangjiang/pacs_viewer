<?php
/**
 * ============================================================
 * app/Database.php — 独立 SQLite 数据库（PDO）
 * ============================================================
 * 本工具自带数据库 data/pacs_viewer.db，首次访问自动建库建表并播种
 * 默认管理员与设置项；不与门诊主系统数据库发生任何关系。
 * ============================================================ */
class PvDatabase {

    /** @var PDO|null */
    private static $pdo = null;

    /** 初始化（幂等）：建立连接 + 建表 + 播种 */
    public static function init() {
        self::pdo();
        self::migrate();
        self::seed();
    }

    /** 获取 PDO 单例 */
    public static function pdo() {
        if (self::$pdo instanceof PDO) return self::$pdo;
        if (!is_dir(PV_DATA)) @mkdir(PV_DATA, 0775, true);
        $file = PV_DATA . '/pacs_viewer.db';
        try {
            $pdo = new PDO('sqlite:' . $file, null, null, array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ));
            // 并发写保护：短时锁等待 + WAL（读写并发），避免高并发写入报 database is locked
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA journal_mode = WAL');
        } catch (Exception $e) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            echo '<h3>无法打开 PACS 浏览器数据库</h3><p>请确认目录 <code>' . htmlspecialchars(PV_DATA) . '</code> 可写。</p>';
            echo '<p style="color:#888">' . htmlspecialchars($e->getMessage()) . '</p>';
            exit;
        }
        self::$pdo = $pdo;
        return self::$pdo;
    }

    /** 建表：按顺序执行 app/Database/schema/*.php 迁移文件（幂等，记录于 schema_migrations） */
    private static function migrate() {
        $pdo = self::pdo();
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            name TEXT PRIMARY KEY,
            applied_at TEXT DEFAULT ''
        )");
        $dir = PV_APP . '/Database/schema';
        $files = is_dir($dir) ? glob($dir . '/*.php') : array();
        sort($files);
        foreach ($files as $file) {
            $name = basename($file, '.php');
            $st = $pdo->prepare("SELECT COUNT(*) FROM schema_migrations WHERE name=?");
            $st->execute(array($name));
            if ((int)$st->fetchColumn() > 0) continue;
            include $file;   // 迁移文件使用作用域内的 $pdo
            $pdo->prepare("INSERT INTO schema_migrations(name,applied_at) VALUES(?,?)")
                ->execute(array($name, date('Y-m-d H:i:s')));
        }
    }

    /** 播种默认设置（仅当为空；不再播种硬编码账号，账号由首次运行安装向导创建） */
    private static function seed() {
        $pdo = self::pdo();
        $setCount = (int)$pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn();
        if ($setCount === 0) {
            $defaults = array(
                'installed'        => '0',               // 是否已完成首次运行安装
                'site_title'       => 'PACS 影像浏览器',
                'hospital_name'    => '',
                'pacs_endpoint'    => '',                // 远程 DICOMweb 接口根地址
                'pacs_api_key'     => '',
                'pacs_ae_title'    => 'CLINIC_OPD',
                'pacs_remote_ae'   => 'PACS_SERVER',
                'pacs_server_host' => '',
                'pacs_server_port' => '104',
                'pacs_timeout'     => '5',
                'viewer_default_ww'=> '400',
                'viewer_default_wl'=> '40',
                'viewer_study_limit'=> '5',            // 影像视图最多同时打开的患者检查数（3-10）
                // 内置模拟 PACS 服务器
                'mock_enabled'        => '1',
                'mock_api_key'        => bin2hex(random_bytes(8)),
                'mock_ae_title'       => 'PACSVIEWMOCK',   // 模拟服务器 DICOM AE Title
                'mock_patient_source' => 'builtin',        // builtin 内置仿真 / fhir 门诊 FHIR R4
                'mock_hospital_name'  => '',               // 内置模拟数据机构名称（对外 DICOMweb 提供）
                'fhir_endpoint'       => '',               // FHIR 接口（模拟服务器患者数据来源）
                'fhir_api_key'        => '',
                'fhir_timeout'        => '5',
            );
            $st = $pdo->prepare("INSERT INTO settings(skey,svalue) VALUES(?,?)");
            foreach ($defaults as $k => $v) { $st->execute(array($k, (string)$v)); }
        }
        // 品牌更名迁移：旧默认站点名更新为「PACS 影像浏览器」
        if ((string)PvSettings::get('site_title', '') === '模拟 PACS 影像浏览器') {
            PvSettings::set('site_title', 'PACS 影像浏览器');
        }

        // 访客令牌独立密钥：首次运行 / 升级后播种一次（不对外展示，替代曾与 mock_api_key 同源的签名密钥）
        if (trim((string)PvSettings::get('guest_secret', '')) === '') {
            PvSettings::set('guest_secret', bin2hex(random_bytes(16)));
        }

        // 兼容旧库：已存在账号则视为已完成安装
        $userCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($userCount > 0) {
            $has = (int)$pdo->query("SELECT COUNT(*) FROM settings WHERE skey='installed'")->fetchColumn();
            if (!$has) {
                $pdo->prepare("INSERT INTO settings(skey,svalue) VALUES('installed','1')")->execute();
            } elseif ((string)PvDatabase::val("SELECT svalue FROM settings WHERE skey='installed'") !== '1') {
                PvDatabase::exec("UPDATE settings SET svalue='1' WHERE skey='installed'");
            }
        }
    }

    /* ---------- 查询门面 ---------- */
    public static function q($sql, $params = array()) {
        $st = self::pdo()->prepare($sql); $st->execute($params); return $st->fetchAll();
    }
    public static function one($sql, $params = array()) {
        $st = self::pdo()->prepare($sql); $st->execute($params);
        $r = $st->fetch(); return $r === false ? null : $r;
    }
    public static function val($sql, $params = array()) {
        $st = self::pdo()->prepare($sql); $st->execute($params);
        $v = $st->fetchColumn(); return $v === false ? null : $v;
    }
    public static function exec($sql, $params = array()) {
        $st = self::pdo()->prepare($sql); $st->execute($params); return $st->rowCount();
    }
    public static function insert($sql, $params = array()) {
        $st = self::pdo()->prepare($sql); $st->execute($params); return (int)self::pdo()->lastInsertId();
    }
}
