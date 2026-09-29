<?php
/**
 * ============================================================
 * tools/mock_validate.php — 模拟 DICOM 生成子系统自检
 * ============================================================
 * 用法：
 *   ~/.local/bin/frankenphp php-cli tools/mock_validate.php        # 抽帧快速校验
 *   ~/.local/bin/frankenphp php-cli tools/mock_validate.php --all  # 全帧校验（较慢）
 *
 * 校验内容：
 *   1. 各模态 / 各解剖部位生成器逐帧字节数 == Rows × Columns × (BitsAllocated/8)；
 *   2. InstanceNumber 连续递增、SliceLocation 单调推进、SOPInstanceUID 唯一；
 *   3. 未传部位参数时按模态默认降级可用；
 *   4. 帧间连续性（相邻帧像素内容不同，非静止画面）。
 * 全部通过输出 OK 并返回 0，否则输出 FAIL 明细并返回非 0。
 * ============================================================ */
$_SERVER['SCRIPT_NAME'] = '/index.php';
require dirname(__DIR__) . '/app/bootstrap.php';

$full = in_array('--all', $argv, true);
$fails = array();
$checks = 0;

function vfail(&$fails, $msg) { $fails[] = $msg; echo "  FAIL " . $msg . PHP_EOL; }

/* ---------- 1/3/4. 生成器字节数与连续性 ---------- */
$matrix = array(
    array('CT', '头颅CT平扫', 'head'),
    array('CT', '胸部CT平扫', 'chest'),
    array('CT', '腰椎CT平扫', 'lumbar'),
    array('CT', '腹部CT平扫', 'abdomen'),
    array('CT', '', ''),                                  // 默认降级
    array('MR', '颅脑MRI平扫', 'head'),
    array('MR', '腰椎MRI平扫', 'lumbar'),
    array('MR', '膝关节MRI', 'knee'),
    array('MR', '', ''),                                  // 默认降级
    array('DR', '胸部正位片', 'chest'),
    array('DR', '膝关节正位片', 'knee'),
    array('DR', '', ''),                                  // 默认降级
    array('US', '腹部彩超', 'abdomen'),
    array('US', '心脏彩超', 'cardiac'),
    array('US', '', ''),                                  // 默认降级
);

echo "== 生成器字节数 / 连续性 ==" . PHP_EOL;
foreach ($matrix as $c) {
    list($mod, $desc, $body) = $c;
    $label = $mod . '/' . ($desc !== '' ? $desc : '(默认)');
    $plan = PvMockDispatcher::seriesPlan($mod, $desc, '1.2.3.4.5');
    $gen = PvMockDispatcher::generatorForSeries($mod, $desc, '1.2.3.4.5', 0);
    if (!$gen) { vfail($fails, $label . '：未取得主序列生成器'); continue; }
    $dim = $gen->getDimensions();
    $rows = $dim['rows']; $cols = $dim['columns'];
    $expected = $rows * $cols * ($dim['bits_allocated'] / 8);
    $n = $gen->getFrameCount();
    if ($plan[0]['slice_count'] !== $n) vfail($fails, $label . '：序列规划切片数(' . $plan[0]['slice_count'] . ')与生成器(' . $n . ')不一致');

    $frames = $full ? range(0, $n - 1) : array_values(array_unique(array(0, intdiv($n, 2), $n - 1)));
    $prevHash = null; $grainDiff = false;
    foreach ($frames as $fi) {
        $b = $gen->generateFrame($fi);
        $checks++;
        if (strlen($b) !== $expected) vfail($fails, $label . " 帧{$fi} 字节数 " . strlen($b) . " != {$expected}");
        $h = md5($b);
        if ($prevHash !== null && $h !== $prevHash) $grainDiff = true;
        $prevHash = $h;
    }
    if (count($frames) > 1 && !$grainDiff) vfail($fails, $label . '：相邻帧像素完全相同，缺少连续演变');
    echo "  OK  {$label}  帧数={$n}  {$rows}x{$cols}x{$dim['bits_allocated']}b  抽样帧=" . count($frames) . PHP_EOL;
}

/* ---------- 2. DICOM 实例连续性 ---------- */
echo "== DICOM 实例连续性（InstanceNumber / SliceLocation / SOP UID） ==" . PHP_EOL;
$studies = PvDemoPacs::studies();
$checked = array();
foreach ($studies as $row) {
    $m = strtoupper($row['modality']);
    if (isset($checked[$m])) continue;
    $checked[$m] = true;
    try {
        $gen = PvMockDispatcher::generatorForSeries($m, $row['description'], $row['study_uid'], 0);
        $maxInst = $gen ? min(3, $gen->getFrameCount()) : 1;
        $lastLoc = null; $uids = array();
        for ($inst = 1; $inst <= $maxInst; $inst++) {
            $r = PvMockServer::wado(array('uid' => $row['study_uid'], 'series' => 1, 'instance' => $inst));
            $tags = mock_parse_dicom($r['binary']);
            $checks++;
            if (substr($r['binary'], 128, 4) !== 'DICM') vfail($fails, "$m：非 DICOM Part 10 文件");
            $in = isset($tags['0020,0013']) ? (int)trim($tags['0020,0013'][2]) : -1;
            if ($in !== $inst) vfail($fails, "$m 实例{$inst}：InstanceNumber={$in}");
            $loc = isset($tags['0020,1041']) ? (float)trim($tags['0020,1041'][2]) : 0.0;
            if ($lastLoc !== null && $loc < $lastLoc) vfail($fails, "$m 实例{$inst}：SliceLocation 未递增");
            $lastLoc = $loc;
            $sop = isset($tags['0008,0018']) ? trim($tags['0008,0018'][2], "\0 ") : '';
            if ($sop === '' || isset($uids[$sop])) vfail($fails, "$m 实例{$inst}：SOPInstanceUID 缺失或重复");
            $uids[$sop] = 1;
            $pxl = isset($tags['7FE0,0010']) ? $tags['7FE0,0010'][1] : 0;
            $rows = isset($tags['0028,0010']) ? unpack('v', $tags['0028,0010'][2])[1] : 0;
            $cols = isset($tags['0028,0011']) ? unpack('v', $tags['0028,0011'][2])[1] : 0;
            $bits = isset($tags['0028,0100']) ? unpack('v', $tags['0028,0100'][2])[1] : 0;
            if ($pxl !== $rows * $cols * ($bits / 8)) vfail($fails, "$m 实例{$inst}：PixelData 长度 {$pxl} != " . ($rows * $cols * ($bits / 8)));
        }
        echo "  OK  {$m} · " . $row['description'] . "  (前 {$maxInst} 个实例)" . PHP_EOL;
    } catch (Exception $e) {
        vfail($fails, "$m：WADO 输出异常 " . $e->getMessage());
    }
}

echo PHP_EOL . "共执行 {$checks} 项校验。" . PHP_EOL;
if ($fails) {
    echo 'RESULT: FAIL (' . count($fails) . ')' . PHP_EOL;
    exit(1);
}
echo 'RESULT: OK' . PHP_EOL;
exit(0);

/** 极简 Explicit VR Little Endian 解析器：返回 tag => [vr, len, value] */
function mock_parse_dicom($b) {
    $pos = 132; $n = strlen($b); $tags = array();
    while ($pos + 8 <= $n) {
        $g = unpack('v', substr($b, $pos, 2))[1];
        $e = unpack('v', substr($b, $pos + 2, 2))[1];
        $vr = substr($b, $pos + 4, 2);
        if (in_array($vr, array('OB', 'OW', 'OF', 'SQ', 'UT', 'UN'), true)) {
            $len = unpack('V', substr($b, $pos + 8, 4))[1]; $vpos = $pos + 12;
        } else {
            $len = unpack('v', substr($b, $pos + 6, 2))[1]; $vpos = $pos + 8;
        }
        $tags[sprintf('%04X,%04X', $g, $e)] = array($vr, $len, substr($b, $vpos, $len));
        $pos = $vpos + $len;
    }
    return $tags;
}
