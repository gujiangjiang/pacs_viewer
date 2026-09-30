<?php
/**
 * ============================================================
 * tools/mock_warm.php — 预生成模拟影像缓存（落盘，供首次访问即时）
 * ============================================================
 * 用法：
 *   ~/.local/bin/frankenphp php-cli tools/mock_warm.php [每序列帧数] [检查数]
 *
 * 默认：每个序列预生成「中间代表帧 + 首帧」中的前 1 帧、全部检查。
 * 生成结果写入 data/mock_cache/（跨进程持久，重启不丢）。
 * 可选在维护窗口执行，以换取首次阅片的近即时响应。
 * ============================================================ */
require dirname(__DIR__) . '/app/bootstrap.php';

$perSeries  = isset($argv[1]) ? max(1, (int)$argv[1]) : 1;
$studyLimit = isset($argv[2]) ? max(1, (int)$argv[2]) : 1000;

$studies = PvDemoPacs::studies();
$frames = 0; $thumbs = 0; $studiesDone = 0;
$t0 = microtime(true);
$i = 0;
foreach ($studies as $row) {
    if ($i++ >= $studyLimit) break;
    $modality = strtoupper($row['modality']);
    $desc = isset($row['description']) ? $row['description'] : '';
    $plan = PvMockDispatcher::seriesPlan($modality, $desc, $row['study_uid']);
    foreach ($plan as $si => $s) {
        $gen = PvMockDispatcher::generatorForSeries($modality, $desc, $row['study_uid'], $si);
        if (!$gen) continue;
        $n = min($perSeries, $gen->getFrameCount());
        for ($f = 1; $f <= $n; $f++) {
            PvMockServer::wado(array('uid' => $row['study_uid'], 'series' => $si + 1, 'instance' => $f));
            $frames++;
        }
        PvMockServer::thumbnail(array('uid' => $row['study_uid'], 'series' => $si + 1));
        $thumbs++;
    }
    $studiesDone++;
}

printf("预生成完成：%d 个检查 · %d 帧 + %d 缩略图 · 用时 %.1fs\n", $studiesDone, $frames, $thumbs, microtime(true) - $t0);
printf("磁盘缓存目录：%s\n", PvMockCache::diskDir());
