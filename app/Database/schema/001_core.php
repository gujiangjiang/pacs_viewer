<?php
/**
 * schema/001_core.php — 核心设置表
 * 执行上下文：$pdo（PDO 实例，Web 根之外，幂等）
 */
if (!isset($pdo) || !($pdo instanceof PDO)) return;

$pdo->exec("CREATE TABLE IF NOT EXISTS settings (
    skey TEXT PRIMARY KEY,
    svalue TEXT DEFAULT ''
)");
