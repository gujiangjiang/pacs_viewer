<?php
/** views/admin.php — 管理员设置（片段） */
$page = 'admin';
$pageTitle = '管理设置';
$active = 'admin';
$bodyClass = 'pv-page-admin';
$extraCss = array('admin.css');
$extraJs = array('admin.js');
$s = $settings;
$v = function ($k, $d = '') use ($s) { return isset($s[$k]) ? $s[$k] : $d; };
$curTab = isset($tab) ? $tab : 'basic';
$tabCls = function ($t) use ($curTab) { return $curTab === $t ? ' active' : ''; };
$pageData = array('flash' => isset($flash) ? $flash : '');
?>
<div class="pv-tabs">
    <button type="button" class="pv-tab<?php echo $tabCls('basic'); ?>" data-tab="basic">基础设置</button>
    <button type="button" class="pv-tab<?php echo $tabCls('pacs'); ?>" data-tab="pacs">DICOM / PACS 接口</button>
    <button type="button" class="pv-tab<?php echo $tabCls('users'); ?>" data-tab="users">账号管理</button>
    <button type="button" class="pv-tab<?php echo $tabCls('logs'); ?>" data-tab="logs">检索日志</button>
</div>

<!-- 基础设置 -->
<section class="pv-tabpane<?php echo $tabCls('basic'); ?>" data-pane="basic">
    <form class="pv-card pv-form" method="post" data-ajax-form action="<?php echo pvw_e(pvw_url('admin/save')); ?>">
        <input type="hidden" name="_csrf" value="<?php echo pvw_e(pvw_csrf()); ?>">
        <input type="hidden" name="tab" value="basic">
        <h3 class="pv-form-title">基础设置</h3>
        <label class="pv-field"><span>站点名称</span>
            <input type="text" name="site_title" value="<?php echo pvw_e($v('site_title')); ?>"></label>
        <label class="pv-field"><span>医院名称</span>
            <input type="text" name="hospital_name" value="<?php echo pvw_e($v('hospital_name')); ?>">
            <em class="pv-hint">作为接口未返回机构名时的兜底展示</em></label>
        <div class="pv-grid2">
            <label class="pv-field"><span>默认窗宽 WW</span>
                <input type="number" name="viewer_default_ww" value="<?php echo pvw_e($v('viewer_default_ww', '400')); ?>"></label>
            <label class="pv-field"><span>默认窗位 WL</span>
                <input type="number" name="viewer_default_wl" value="<?php echo pvw_e($v('viewer_default_wl', '40')); ?>"></label>
        </div>
        <button type="submit" class="pv-btn pv-btn-primary">保存基础设置</button>
    </form>
</section>

<!-- PACS 接口 -->
<section class="pv-tabpane<?php echo $tabCls('pacs'); ?>" data-pane="pacs">
    <form class="pv-card pv-form" method="post" data-ajax-form action="<?php echo pvw_e(pvw_url('admin/save')); ?>">
        <input type="hidden" name="_csrf" value="<?php echo pvw_e(pvw_csrf()); ?>">
        <input type="hidden" name="tab" value="pacs">
        <h3 class="pv-form-title">DICOM / PACS 接口</h3>
        <p class="pv-hint">检索与调阅数据全部来自下方接口地址。本地联调可将地址指向【模拟服务器】提供的对外 API（在模拟服务器页一键填入）。</p>
        <label class="pv-field"><span>PACS 接口地址（PACS_SERVER_URL）</span>
            <input type="text" name="pacs_endpoint" value="<?php echo pvw_e($v('pacs_endpoint')); ?>" placeholder="如 http://192.168.1.100:8042/dicom-web/gateway"></label>
        <div class="pv-grid2">
            <label class="pv-field"><span>接口密钥</span>
                <input type="text" name="pacs_api_key" value="<?php echo pvw_e($v('pacs_api_key')); ?>" placeholder="可选"></label>
            <label class="pv-field"><span>超时（秒）</span>
                <input type="number" name="pacs_timeout" value="<?php echo pvw_e($v('pacs_timeout', '5')); ?>"></label>
        </div>
        <div class="pv-grid2">
            <label class="pv-field"><span>本系统 AETitle（PACS_AE_TITLE）</span>
                <input type="text" name="pacs_ae_title" value="<?php echo pvw_e($v('pacs_ae_title')); ?>"></label>
            <label class="pv-field"><span>目标 PACS AETitle</span>
                <input type="text" name="pacs_remote_ae" value="<?php echo pvw_e($v('pacs_remote_ae')); ?>"></label>
        </div>
        <div class="pv-grid2">
            <label class="pv-field"><span>PACS 主机</span>
                <input type="text" name="pacs_server_host" value="<?php echo pvw_e($v('pacs_server_host')); ?>" placeholder="192.168.1.100"></label>
            <label class="pv-field"><span>DICOM 端口</span>
                <input type="text" name="pacs_server_port" value="<?php echo pvw_e($v('pacs_server_port', '104')); ?>"></label>
        </div>
        <div class="pv-form-actions">
            <button type="submit" class="pv-btn pv-btn-primary">保存接口配置</button>
            <button type="button" id="pvTestPacs" class="pv-btn pv-btn-outline">测试接口</button>
            <span id="pvTestResult" class="pv-test-result"></span>
        </div>
    </form>
</section>

<!-- 账号管理 -->
<section class="pv-tabpane<?php echo $tabCls('users'); ?>" data-pane="users">
    <div class="pv-card">
        <div class="pv-card-head">
            <h3 class="pv-form-title">账号管理</h3>
            <button type="button" id="pvAddUser" class="pv-btn pv-btn-primary pv-btn-sm">＋ 新增账号</button>
        </div>
        <table class="pv-table" id="pvUserTable">
            <thead><tr><th>ID</th><th>用户名</th><th>显示名</th><th>角色</th><th>状态</th><th>创建时间</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u) {
                $isOwner = (int)$u['is_owner'] === 1;
                $enabled = (int)$u['status'] === 1;
            ?>
                <tr data-user
                    data-id="<?php echo (int)$u['id']; ?>"
                    data-username="<?php echo pvw_e($u['username']); ?>"
                    data-display="<?php echo pvw_e($u['display_name']); ?>"
                    data-role="<?php echo pvw_e($u['role']); ?>"
                    data-status="<?php echo (int)$u['status']; ?>"
                    data-owner="<?php echo $isOwner ? '1' : '0'; ?>">
                    <td><?php echo (int)$u['id']; ?></td>
                    <td><?php echo pvw_e($u['username']); ?><?php if ($isOwner) { ?> <span class="pv-badge demo" title="安装管理员：不可删除 / 停用">🔒 安装管理员</span><?php } ?></td>
                    <td><?php echo pvw_e($u['display_name']); ?></td>
                    <td><?php echo $u['role'] === 'admin' ? '管理员' : '普通'; ?></td>
                    <td><?php echo $enabled ? '<span class="pv-badge ok">启用</span>' : '<span class="pv-badge off">停用</span>'; ?></td>
                    <td class="pv-dim"><?php echo pvw_e($u['created_at']); ?></td>
                    <td class="pv-actions">
                        <button class="pv-btn pv-btn-ghost pv-btn-sm" data-act="edit">编辑</button>
                        <?php if (!$isOwner) { ?>
                        <button class="pv-btn pv-btn-ghost pv-btn-sm" data-act="status"><?php echo $enabled ? '停用' : '启用'; ?></button>
                        <?php } ?>
                        <button class="pv-btn pv-btn-ghost pv-btn-sm" data-act="password">重置密码</button>
                        <?php if (!$isOwner) { ?>
                        <button class="pv-btn pv-btn-ghost pv-btn-sm pv-btn-danger-text" data-act="delete">删除</button>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
</section>

<!-- 检索日志 -->
<section class="pv-tabpane<?php echo $tabCls('logs'); ?>" data-pane="logs">
    <div class="pv-card">
        <div class="pv-card-head">
            <h3 class="pv-form-title">检索日志（共 <?php echo (int)$logCount; ?> 条）</h3>
            <button type="button" id="pvLogClear" class="pv-btn pv-btn-outline pv-btn-sm">清空</button>
        </div>
        <table class="pv-table">
            <thead><tr><th>时间</th><th>账号</th><th>关键词</th><th>结果数</th><th>IP</th></tr></thead>
            <tbody>
            <?php if (!$logs) { ?>
                <tr><td colspan="5" class="pv-dim" style="text-align:center">暂无记录</td></tr>
            <?php } foreach ($logs as $l) { ?>
                <tr><td class="pv-dim"><?php echo pvw_e($l['created_at']); ?></td><td><?php echo pvw_e($l['username']); ?></td>
                    <td><?php echo pvw_e($l['keyword']); ?></td><td><?php echo (int)$l['result_count']; ?></td>
                    <td class="pv-dim"><?php echo pvw_e($l['ip']); ?></td></tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
</section>
