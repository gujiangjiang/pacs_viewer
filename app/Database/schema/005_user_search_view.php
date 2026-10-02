<?php
/**
 * 005_user_search_view.php — 用户检索视图偏好（table/list/card）
 * 随用户保存，下次登录/进入自动启用；默认纯列表 table。
 */
$cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
$names = array();
foreach ($cols as $c) { $names[$c['name']] = true; }
if (!isset($names['search_view'])) {
    $pdo->exec("ALTER TABLE users ADD COLUMN search_view TEXT NOT NULL DEFAULT 'table'");
}
