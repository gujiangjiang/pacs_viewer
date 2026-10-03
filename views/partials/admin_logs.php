<?php /** views/partials/admin_logs.php — 管理设置子面板（由 admin.php 装配） */ ?>
<!-- 操作日志（首屏服务端渲染，后续滚动到底由 PvInfiniteScroll 追加） -->
<section class="pv-tabpane<?php echo $tabCls('logs'); ?>" data-pane="logs">
    <div class="pv-card">
        <div class="pv-card-head">
            <h3 class="pv-form-title">操作日志（共 <span id="pvLogCount"><?php echo (int)$logCount; ?></span> 条）</h3>
            <span class="pv-card-actions">
                <button type="button" id="pvLogLive" class="pv-btn pv-btn-outline pv-btn-sm pv-btn-toggle" title="勾选后自动检测并实时更新">实时</button>
                <button type="button" id="pvLogRefresh" class="pv-btn pv-btn-outline pv-btn-sm">刷新</button>
                <button type="button" id="pvLogSettings" class="pv-btn pv-btn-outline pv-btn-sm">设置</button>
                <button type="button" id="pvLogClear" class="pv-btn pv-btn-outline pv-btn-sm">清空</button>
            </span>
        </div>
        <div class="pv-logscroll" id="pvLogScroll" data-total="<?php echo (int)$logCount; ?>">
        <table class="pv-table">
            <colgroup>
                <col style="width:16%"><col style="width:10%"><col style="width:14%">
                <col style="width:28%"><col style="width:12%"><col style="width:8%"><col style="width:12%">
            </colgroup>
            <thead><tr><th>时间</th><th>账号</th><th>操作</th><th>详情</th><th>关键词</th><th>结果数</th><th>IP</th></tr></thead>
            <tbody id="pvLogBody">
            <?php if (!$logs) { ?>
                <tr data-row="1"><td colspan="7" class="pv-dim" style="text-align:center">暂无记录</td></tr>
            <?php } foreach ($logs as $l) {
                $act = isset($l['action']) ? $l['action'] : 'search';
            ?>
                <tr data-row="1" data-id="<?php echo (int)$l['id']; ?>"><td class="pv-dim"><?php echo pvw_e($l['created_at']); ?></td>
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
