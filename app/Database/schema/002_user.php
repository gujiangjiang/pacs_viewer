<?php
/**
 * schema/002_user.php — 用户与账号
 */
if (!isset($pdo) || !($pdo instanceof PDO)) return;

$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    display_name TEXT DEFAULT '',
    role TEXT NOT NULL DEFAULT 'user',
    status INTEGER NOT NULL DEFAULT 1,
    is_owner INTEGER NOT NULL DEFAULT 0,
    clear_on_open INTEGER NOT NULL DEFAULT 0,
    created_at TEXT DEFAULT ''
)");

// 兼容旧库：补充后续新增列
$cols = $pdo->query("PRAGMA table_info(users)")->fetchAll();
$names = array();
foreach ($cols as $c) { if (isset($c['name'])) $names[$c['name']] = true; }
if (!isset($names['is_owner'])) $pdo->exec("ALTER TABLE users ADD COLUMN is_owner INTEGER NOT NULL DEFAULT 0");
if (!isset($names['clear_on_open'])) $pdo->exec("ALTER TABLE users ADD COLUMN clear_on_open INTEGER NOT NULL DEFAULT 0");
