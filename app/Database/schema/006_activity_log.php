<?php
/**
 * schema/006_activity_log.php — 通用活动日志（协议日志 / 模拟服务器日志）
 *
 * channel 通道：
 *   protocol 协议日志（DICOMweb 等对外/出向接口调用）
 *   mock     模拟服务器日志（FHIR 来源取数、登记 / 摄片 / 生图等事件）
 * level：info / warn / error
 */
if (!isset($pdo) || !($pdo instanceof PDO)) return;

$pdo->exec("CREATE TABLE IF NOT EXISTS activity_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    channel TEXT DEFAULT '',
    level TEXT DEFAULT 'info',
    action TEXT DEFAULT '',
    detail TEXT DEFAULT '',
    meta TEXT DEFAULT '',
    ip TEXT DEFAULT '',
    created_at TEXT DEFAULT ''
)");

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_activity_channel_id ON activity_log(channel, id)");
