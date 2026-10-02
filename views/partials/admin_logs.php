<?php /** views/partials/admin_logs.php — 管理设置子面板（由 admin.php 装配） */ ?>
<!-- 操作日志（首屏服务端渲染，后续滚动到底由 PvInfiniteScroll 追加） -->
<section class="pv-tabpane<?php echo $tabCls('logs'); ?>" data-pane="logs">
    <div class="pv-card">
        <div class="pv-card-head">
            <h3 class="pv-form-title">操作日志（共 <?php echo (int)$logCount; ?> 条）</h3>
            <button type="button" id="pvLogClear" class="pv-btn pv-btn-outline pv-btn-sm">清空</button>
        </div>
        <div class="pv-logscroll" id="pvLogScroll" data-total="<?php echo (int)$logCount; ?>">
        <table class="pv-table">
            <colgroup>
                <col style="width:15%"><col style="width:10%"><col style="width:8%">
                <col style="width:34%"><col style="width:13%"><col style="width:8%"><col style="width:12%">
            </colgroup>
            <thead><tr><th>时间</th><th>账号</th><th>操作</th><th>详情</th><th>关键词</th><th>结果数</th><th>IP</th></tr></thead>
            <tbody id="pvLogBody">
            <?php if (!$logs) { ?>
                <tr data-row="1"><td colspan="7" class="pv-dim" style="text-align:center">暂无记录</td></tr>
            <?php } foreach ($logs as $l) {
                $act = isset($l['action']) ? $l['action'] : 'search';
            ?>
                <tr data-row="1"><td class="pv-dim"><?php echo pvw_e($l['created_at']); ?></td>
                    <td><?php echo pvw_e($l['username']); ?></td>
                    <td><span class="pv-badge op-<?php echo pvw_e($act); ?>"><?php echo pvw_e(PvQueryLogRepository::actionName($act)); ?></span></td>
                    <td><span class="pv-log-detail" title="<?php echo pvw_e(isset($l['detail']) ? $l['detail'] : ''); ?>"><?php echo pvw_e(isset($l['detail']) ? $l['detail'] : ''); ?></span></td>
                    <td><?php echo pvw_e($l['keyword']); ?></td>
                    <td><?php echo $act === 'search' ? (int)$l['result_count'] : '—'; ?></td>
                    <td class="pv-dim"><?php echo pvw_e($l['ip']); ?></td></tr>
            <?php } ?>
            </tbody>
        </table>
        </div>
    </div>
</section>
