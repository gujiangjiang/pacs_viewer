<?php
/**
 * ============================================================
 * tools/backfill_acquisitions.php — 摄片记录回填（一次性）
 * ============================================================
 * 用途：将本机「摄片记录」（mock_acquisitions）按标准 FHIR 回写门诊系统的
 * 工作项（Task=completed），触发门诊侧按检查号从区域 PACS 解析并登记影像引用，
 * 使历史（回写令牌曾缺写权限时产生的）摄片在门诊 FHIR / DICOMweb 立即可检索。
 *
 * 幂等：重复执行仅重放回写，不会产生重复影像引用（门诊侧按 order_item 去重）。
 * 前置：门诊系统 FHIR 可访问，且其 FHIR 令牌具备 system/Task.write。
 *
 * 用法：
 *   ~/.local/bin/frankenphp php-cli tools/backfill_acquisitions.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

if (PvMockServer::source() !== 'fhir') {
    fwrite(STDERR, "当前患者来源为「内置模拟数据」，无门诊 FHIR 可回写；退出。\n");
    exit(1);
}

$all = PvAcquisitionStore::all();
if (!$all) { echo "无摄片记录，无需回填。\n"; exit(0); }

// 以工作列表建立 检查号 → task_id 映射（兼容 task_ref 为空的记录）
$taskByAcc = array();
try {
    foreach (PvFhirClient::worklist() as $w) {
        $acc = isset($w['accession_no']) ? (string)$w['accession_no'] : '';
        if ($acc !== '' && !empty($w['task_id'])) $taskByAcc[$acc] = (string)$w['task_id'];
    }
} catch (Exception $e) {
    fwrite(STDERR, "读取工作列表失败：" . $e->getMessage() . "\n");
}

$ok = 0; $fail = 0; $skip = 0;
foreach ($all as $a) {
    $acc = (string)$a['accession_no'];
    $task = trim((string)$a['task_ref']);
    if ($task === '' && isset($taskByAcc[$acc])) $task = $taskByAcc[$acc];
    if ($task === '') { $skip++; echo "跳过（无工作项）：{$acc}\n"; continue; }
    try {
        PvFhirClient::writeTask($task, array(
            'resourceType' => 'Task', 'id' => $task, 'status' => 'completed',
            'businessStatus' => array('text' => '已摄片'),
            'owner' => array('display' => 'PACS'),
            'executionPeriod' => array('end' => date('c')),
        ));
        $ok++;
        PvActivityLogRepository::mock('backfill', '回填摄片：' . $acc . ' → ' . $task);
        echo "已回填：{$acc}（{$task}）\n";
    } catch (Exception $e) {
        $fail++;
        PvActivityLogRepository::mock('backfill', '回填失败：' . $acc . ' → ' . $task . '：' . $e->getMessage(), 'error');
        echo "失败：{$acc}（{$task}）：" . $e->getMessage() . "\n";
    }
}
echo "完成：成功 {$ok}，失败 {$fail}，跳过 {$skip}。\n";
