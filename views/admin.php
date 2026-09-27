<?php
/** views/admin.php — 管理员设置（片段） */
$page = 'admin';
$pageTitle = '管理设置';
$active = 'admin';
$bodyClass = 'pv-page-admin';
$extraCss = array('admin.css');
$s = $settings;
$v = function ($k, $d = '') use ($s) { return isset($s[$k]) ? $s[$k] : $d; };
?>
<?php if (!empty($flash)) { ?><div class="pv-alert pv-alert-ok"><?php echo pvw_e($flash); ?></div><?php } ?>

<div class="pv-tabs">
    <button type="button" class="pv-tab active" data-tab="basic">基础设置</button>
    <button type="button" class="pv-tab" data-tab="pacs">DICOM / PACS 接口</button>
    <button type="button" class="pv-tab" data-tab="users">账号管理</button>
    <button type="button" class="pv-tab" data-tab="logs">检索日志</button>
</div>

<!-- 基础设置 -->
<section class="pv-tabpane active" data-pane="basic">
    <form class="pv-card pv-form" method="post" action="<?php echo pvw_e(pvw_url('admin/save')); ?>">
        <input type="hidden" name="_csrf" value="<?php echo pvw_e(pvw_csrf()); ?>">
        <input type="hidden" name="pacs_query_mode" value="<?php echo pvw_e($v('pacs_query_mode', 'Demo')); ?>">
        <input type="hidden" name="pacs_endpoint" value="<?php echo pvw_e($v('pacs_endpoint')); ?>">
        <input type="hidden" name="pacs_api_key" value="<?php echo pvw_e($v('pacs_api_key')); ?>">
        <input type="hidden" name="pacs_ae_title" value="<?php echo pvw_e($v('pacs_ae_title')); ?>">
        <input type="hidden" name="pacs_remote_ae" value="<?php echo pvw_e($v('pacs_remote_ae')); ?>">
        <input type="hidden" name="pacs_server_host" value="<?php echo pvw_e($v('pacs_server_host')); ?>">
        <input type="hidden" name="pacs_server_port" value="<?php echo pvw_e($v('pacs_server_port')); ?>">
        <input type="hidden" name="pacs_timeout" value="<?php echo pvw_e($v('pacs_timeout', '5')); ?>">
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
<section class="pv-tabpane" data-pane="pacs">
    <form class="pv-card pv-form" method="post" action="<?php echo pvw_e(pvw_url('admin/save')); ?>">
        <input type="hidden" name="_csrf" value="<?php echo pvw_e(pvw_csrf()); ?>">
        <input type="hidden" name="site_title" value="<?php echo pvw_e($v('site_title')); ?>">
        <input type="hidden" name="hospital_name" value="<?php echo pvw_e($v('hospital_name')); ?>">
        <input type="hidden" name="viewer_default_ww" value="<?php echo pvw_e($v('viewer_default_ww', '400')); ?>">
        <input type="hidden" name="viewer_default_wl" value="<?php echo pvw_e($v('viewer_default_wl', '40')); ?>">
        <h3 class="pv-form-title">DICOM / PACS 接口</h3>
        <label class="pv-field"><span>查询模式</span>
            <select name="pacs_query_mode">
                <option value="Demo" <?php echo $v('pacs_query_mode', 'Demo') === 'Demo' ? 'selected' : ''; ?>>内置模拟数据（本地演示）</option>
                <option value="Remote" <?php echo $v('pacs_query_mode') === 'Remote' ? 'selected' : ''; ?>>远程 PACS / DICOMWeb 接口</option>
            </select>
            <em class="pv-hint">选择「远程接口」后，检索与调阅数据全部来自下方接口地址；未配置或不可达时检索会提示错误。</em></label>

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
<section class="pv-tabpane" data-pane="users">
    <div class="pv-card">
        <h3 class="pv-form-title">新增账号</h3>
        <form class="pv-form pv-form-inline" method="post" action="<?php echo pvw_e(pvw_url('admin/user-create')); ?>">
            <input type="hidden" name="_csrf" value="<?php echo pvw_e(pvw_csrf()); ?>">
            <input type="text" name="username" class="pv-input" placeholder="用户名" required>
            <input type="text" name="display_name" class="pv-input" placeholder="显示名">
            <input type="password" name="password" class="pv-input" placeholder="初始密码" required>
            <select name="role" class="pv-input">
                <option value="user">普通用户</option>
                <option value="admin">管理员</option>
            </select>
            <button type="submit" class="pv-btn pv-btn-primary">创建</button>
        </form>
    </div>
    <div class="pv-card">
        <table class="pv-table">
            <thead><tr><th>ID</th><th>用户名</th><th>显示名</th><th>角色</th><th>状态</th><th>创建时间</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u) { ?>
                <tr>
                    <td><?php echo (int)$u['id']; ?></td>
                    <td><?php echo pvw_e($u['username']); ?></td>
                    <td><?php echo pvw_e($u['display_name']); ?></td>
                    <td><?php echo $u['role'] === 'admin' ? '管理员' : '普通'; ?></td>
                    <td><?php echo (int)$u['status'] === 1 ? '<span class="pv-badge ok">启用</span>' : '<span class="pv-badge off">停用</span>'; ?></td>
                    <td class="pv-dim"><?php echo pvw_e($u['created_at']); ?></td>
                    <td class="pv-actions">
                        <form method="post" action="<?php echo pvw_e(pvw_url('admin/user-status')); ?>">
                            <input type="hidden" name="_csrf" value="<?php echo pvw_e(pvw_csrf()); ?>">
                            <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                            <input type="hidden" name="status" value="<?php echo (int)$u['status'] === 1 ? '0' : '1'; ?>">
                            <button class="pv-btn pv-btn-ghost pv-btn-sm"><?php echo (int)$u['status'] === 1 ? '停用' : '启用'; ?></button>
                        </form>
                        <form method="post" action="<?php echo pvw_e(pvw_url('admin/user-password')); ?>" onsubmit="return confirm('确认重置该账号密码？');">
                            <input type="hidden" name="_csrf" value="<?php echo pvw_e(pvw_csrf()); ?>">
                            <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                            <input type="password" name="password" class="pv-input pv-input-sm" placeholder="新密码" required>
                            <button class="pv-btn pv-btn-ghost pv-btn-sm">重置密码</button>
                        </form>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
</section>

<!-- 检索日志 -->
<section class="pv-tabpane" data-pane="logs">
    <div class="pv-card">
        <div class="pv-card-head">
            <h3 class="pv-form-title">检索日志（共 <?php echo (int)$logCount; ?> 条）</h3>
            <form method="post" action="<?php echo pvw_e(pvw_url('admin/log-clear')); ?>" onsubmit="return confirm('确认清空全部检索日志？');">
                <input type="hidden" name="_csrf" value="<?php echo pvw_e(pvw_csrf()); ?>">
                <button class="pv-btn pv-btn-outline pv-btn-sm">清空</button>
            </form>
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
<?php
$extraJs = array('admin.js');
