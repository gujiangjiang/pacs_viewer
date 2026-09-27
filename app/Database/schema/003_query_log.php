<?php
/**
 * schema/003_query_log.php — 操作 / 检索日志
 */
if (!isset($pdo) || !($pdo instanceof PDO)) return;

$pdo->exec("CREATE TABLE IF NOT EXISTS query_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT DEFAULT '',
    keyword TEXT DEFAULT '',
    result_count INTEGER DEFAULT 0,
    ip TEXT DEFAULT '',
    action TEXT DEFAULT 'search',
    detail TEXT DEFAULT '',
    created_at TEXT DEFAULT ''
)");

// 兼容旧库：补充 action / detail 列
$cols = $pdo->query("PRAGMA table_info(query_log)")->fetchAll();
$names = array();
foreach ($cols as $c) { if (isset($c['name'])) $names[$c['name']] = true; }
if (!isset($names['action'])) $pdo->exec("ALTER TABLE query_log ADD COLUMN action TEXT DEFAULT 'search'");
if (!isset($names['detail'])) $pdo->exec("ALTER TABLE query_log ADD COLUMN detail TEXT DEFAULT ''");
