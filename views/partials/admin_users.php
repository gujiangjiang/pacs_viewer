<?php /** views/partials/admin_users.php — 管理设置子面板（由 admin.php 装配） */ ?>
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
