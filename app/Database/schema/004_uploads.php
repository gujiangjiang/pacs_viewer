<?php
/**
 * schema/004_uploads.php — 通用上传记录
 * 文件本体存放于 Web 根之外 data/uploads/，经 ?r=file 鉴权下发。
 */
if (!isset($pdo) || !($pdo instanceof PDO)) return;

$pdo->exec("CREATE TABLE IF NOT EXISTS uploads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token TEXT UNIQUE NOT NULL,
    category TEXT DEFAULT 'general',
    orig_name TEXT DEFAULT '',
    stored_name TEXT NOT NULL,
    mime TEXT DEFAULT '',
    size INTEGER DEFAULT 0,
    uploader TEXT DEFAULT '',
    created_at TEXT DEFAULT ''
)");
