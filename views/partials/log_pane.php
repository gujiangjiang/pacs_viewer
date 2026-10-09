<?php
/**
 * views/partials/log_pane.php — 日志面板卡片（由 admin_logs.php / mock_pane.php 装配）
 *
 * 变量：
 *   $lpAttrs     面板属性（data-*-pane / data-channel / 限额，由调用方拼接）
 *   $lpChannel  日志通道（用于 tbody data-channel）
 *   $lpTitle     面板标题（如「操作日志」）
 *   $lpTotal     初始总条数
 *   $lpCols      <colgroup> 内部 HTML
 *   $lpHead      <thead><tr> 内部 HTML
 *   $lpBody      <tbody> 内部 HTML（服务端首屏行或加载占位）
 *   $lpHidden    是否初始隐藏（可选）
 *   $lpOuterCard 外层元素即卡片（模拟服务器日志），默认内层卡片
 *   $lpLiveTitle 「实时」按钮 title（可选）
 *   $lpHint      额外提示 HTML（可选，卡片头之后）
 */
$lpHidden = !empty($lpHidden);
$lpOuterCard = !empty($lpOuterCard);
?>
<?php if ($lpOuterCard) { ?>
<div class="pv-card<?php echo $lpHidden ? ' pv-hidden' : ''; ?> pv-logpane" <?php echo $lpAttrs; ?>>
<?php } else { ?>
<div class="pv-logpane<?php echo $lpHidden ? ' pv-hidden' : ''; ?>" <?php echo $lpAttrs; ?>>
    <div class="pv-card">
<?php } ?>
        <div class="pv-card-head">
            <h3 class="pv-form-title"><?php echo pvw_e($lpTitle); ?>（共 <span class="pv-log-total"><?php echo (int)$lpTotal; ?></span> 条）</h3>
            <span class="pv-card-actions">
                <button type="button" class="pv-btn pv-btn-outline pv-btn-sm pv-btn-toggle pv-log-live"<?php echo !empty($lpLiveTitle) ? ' title="' . pvw_e($lpLiveTitle) . '"' : ''; ?>>实时</button>
                <button type="button" class="pv-btn pv-btn-outline pv-btn-sm pv-log-refresh">刷新</button>
                <button type="button" class="pv-btn pv-btn-outline pv-btn-sm pv-log-settings">设置</button>
                <button type="button" class="pv-btn pv-btn-outline pv-btn-sm pv-log-clear">清空</button>
            </span>
        </div>
        <?php if (!empty($lpHint)) echo $lpHint; ?>
        <div class="pv-logscroll pv-log-scroll" data-total="<?php echo (int)$lpTotal; ?>">
            <table class="pv-table">
                <colgroup><?php echo $lpCols; ?></colgroup>
                <thead><tr><?php echo $lpHead; ?></tr></thead>
                <tbody class="pv-log-body" data-channel="<?php echo pvw_e($lpChannel); ?>"><?php echo $lpBody; ?></tbody>
            </table>
        </div>
<?php if ($lpOuterCard) { ?>
</div>
<?php } else { ?>
    </div>
</div>
<?php } ?>
