<?php
/**
 * views/partials/admin_logs.php — 日志查询（管理设置子面板，由 admin.php 装配）
 * 左栏：操作日志 / 协议日志 / 系统日志；各通道独立上限（条数 / 天数）。
 * 操作日志首屏服务端渲染；协议 / 系统日志首次进入时由 admin-logs.js 加载。
 */
$logLimits = isset($logLimits) && is_array($logLimits) ? $logLimits : array();
$lgAttr = function ($ch) use ($logLimits) {
    $x = isset($logLimits[$ch]) ? $logLimits[$ch] : array('count' => 0, 'days' => 0);
    return ' data-count="' . (int)$x['count'] . '" data-days="' . (int)$x['days'] . '"';
};
?>
<section class="pv-tabpane<?php echo $tabCls('logs'); ?>" data-pane="logs">
    <div class="pv-split pv-log-split">
        <aside class="pv-split-nav">
            <button type="button" class="pv-split-item" data-lg="operation">操作日志</button>
            <button type="button" class="pv-split-item" data-lg="protocol">协议日志</button>
            <button type="button" class="pv-split-item" data-lg="system">系统日志</button>
        </aside>
        <div class="pv-split-body">

            <!-- 操作日志（首屏服务端渲染） -->
            <div class="pv-logpane" data-lg-pane="operation" data-channel="operation"<?php echo $lgAttr('operation'); ?>>
                <div class="pv-card">
                    <div class="pv-card-head">
                        <h3 class="pv-form-title">操作日志（共 <span class="pv-log-total"><?php echo (int)$logCount; ?></span> 条）</h3>
                        <span class="pv-card-actions">
                            <button type="button" class="pv-btn pv-btn-outline pv-btn-sm pv-btn-toggle pv-log-live" title="勾选后自动检测并实时更新">实时</button>
                            <button type="button" class="pv-btn pv-btn-outline pv-btn-sm pv-log-refresh">刷新</button>
                            <button type="button" class="pv-btn pv-btn-outline pv-btn-sm pv-log-settings">设置</button>
                            <button type="button" class="pv-btn pv-btn-outline pv-btn-sm pv-log-clear">清空</button>
                        </span>
                    </div>
                    <div class="pv-logscroll pv-log-scroll" data-total="<?php echo (int)$logCount; ?>">
                        <table class="pv-table">
                            <colgroup>
                                <col style="width:15%"><col style="width:10%"><col style="width:10%">
                                <col style="width:29%"><col style="width:12%"><col style="width:8%"><col style="width:12%">
                            </colgroup>
                            <thead><tr><th>时间</th><th>账号</th><th>操作</th><th>详情</th><th>关键词</th><th>结果数</th><th>IP</th></tr></thead>
                            <tbody class="pv-log-body" data-channel="operation">
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
            </div>

            <!-- 协议日志（首次进入加载） -->
            <div class="pv-logpane pv-hidden" data-lg-pane="protocol" data-channel="protocol"<?php echo $lgAttr('protocol'); ?>>
                <div class="pv-card">
                    <div class="pv-card-head">
                        <h3 class="pv-form-title">协议日志（共 <span class="pv-log-total">0</span> 条）</h3>
                        <span class="pv-card-actions">
                            <button type="button" class="pv-btn pv-btn-outline pv-btn-sm pv-btn-toggle pv-log-live">实时</button>
                            <button type="button" class="pv-btn pv-btn-outline pv-btn-sm pv-log-refresh">刷新</button>
                            <button type="button" class="pv-btn pv-btn-outline pv-btn-sm pv-log-settings">设置</button>
                            <button type="button" class="pv-btn pv-btn-outline pv-btn-sm pv-log-clear">清空</button>
                        </span>
                    </div>
                    <div class="pv-logscroll pv-log-scroll" data-total="0">
                        <table class="pv-table">
                            <colgroup><col style="width:16%"><col style="width:8%"><col style="width:12%"><col style="width:44%"><col style="width:20%"></colgroup>
                            <thead><tr><th>时间</th><th>级别</th><th>动作</th><th>详情</th><th>IP</th></tr></thead>
                            <tbody class="pv-log-body" data-channel="protocol"><tr data-row="1"><td colspan="5" class="pv-dim" style="text-align:center">正在加载…</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- 系统日志（首次进入加载） -->
            <div class="pv-logpane pv-hidden" data-lg-pane="system" data-channel="system"<?php echo $lgAttr('system'); ?>>
                <div class="pv-card">
                    <div class="pv-card-head">
                        <h3 class="pv-form-title">系统日志（共 <span class="pv-log-total">0</span> 条）</h3>
                        <span class="pv-card-actions">
                            <button type="button" class="pv-btn pv-btn-outline pv-btn-sm pv-btn-toggle pv-log-live">实时</button>
                            <button type="button" class="pv-btn pv-btn-outline pv-btn-sm pv-log-refresh">刷新</button>
                            <button type="button" class="pv-btn pv-btn-outline pv-btn-sm pv-log-settings">设置</button>
                            <button type="button" class="pv-btn pv-btn-outline pv-btn-sm pv-log-clear">清空</button>
                        </span>
                    </div>
                    <div class="pv-logscroll pv-log-scroll" data-total="0">
                        <table class="pv-table">
                            <colgroup><col style="width:18%"><col style="width:82%"></colgroup>
                            <thead><tr><th>时间</th><th>内容</th></tr></thead>
                            <tbody class="pv-log-body" data-channel="system"><tr data-row="1"><td colspan="2" class="pv-dim" style="text-align:center">正在加载…</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</section>
