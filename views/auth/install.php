<?php
/** views/auth/install.php — 多步安装向导（环境 → 数据库 → 初始化 → 站点 → 管理员 → 完成） */
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>首次运行安装 · <?php echo pvw_e($site); ?></title>
<meta name="theme-color" content="#0b0f17">
<link rel="icon" type="image/png" sizes="32x32" href="<?php echo pvw_e(PvPwaController::iconUrl(32)); ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?php echo pvw_e(PvPwaController::iconUrl(180)); ?>">
<link rel="stylesheet" href="<?php echo pvw_asset('css/base.css'); ?>">
<link rel="stylesheet" href="<?php echo pvw_asset('css/ui.css'); ?>">
<link rel="stylesheet" href="<?php echo pvw_asset('css/auth.css'); ?>">
</head>
<body class="pv-auth-body">
<div class="pv-auth-card pv-auth-wide">
    <div class="pv-auth-head">
        <img class="pv-auth-logo" src="<?php echo pvw_e(PvPwaController::iconUrl(96)); ?>" width="56" height="56" alt="">
        <h1>首次运行安装</h1>
        <p class="pv-auth-sub">完成初始化后即可登录使用；全站时间按所选时区显示（存储与接口均为 UTC）</p>
    </div>

    <div class="pv-wiz-steps" id="wiz-steps">
        <span class="pv-wiz-step" data-step="env">环境</span>
        <span class="pv-wiz-step" data-step="database">数据库</span>
        <span class="pv-wiz-step" data-step="migrate">初始化</span>
        <span class="pv-wiz-step" data-step="site">站点</span>
        <span class="pv-wiz-step" data-step="admin">管理员</span>
        <span class="pv-wiz-step" data-step="finish">完成</span>
    </div>

    <!-- ① 环境检测 -->
    <div class="pv-wiz-pane" data-pane="env" style="display:none">
        <ul class="pv-wiz-check" id="wiz-env"></ul>
        <button type="button" class="pv-btn pv-btn-primary pv-btn-block" id="wiz-env-next" disabled>下一步</button>
    </div>

    <!-- ② 数据库 -->
    <div class="pv-wiz-pane" data-pane="database" style="display:none">
        <label class="pv-field">
            <span>SQLite 数据文件路径</span>
            <input type="text" id="wiz-db-path" placeholder="默认 data/pacs_viewer.db">
        </label>
        <p class="pv-hint">当前版本使用内置 SQLite 存储；填写相对路径时按 data/ 目录展开。</p>
        <button type="button" class="pv-btn pv-btn-primary pv-btn-block" id="wiz-db-submit">保存并测试</button>
    </div>

    <!-- ③ 初始化 -->
    <div class="pv-wiz-pane" data-pane="migrate" style="display:none">
        <p class="pv-hint" id="wiz-migrate-hint">将创建系统数据表并写入默认设置（幂等，可重复执行）。</p>
        <button type="button" class="pv-btn pv-btn-primary pv-btn-block" id="wiz-migrate-submit">开始初始化</button>
        <button type="button" class="pv-wiz-link" id="wiz-migrate-next" style="display:none">下一步</button>
    </div>

    <!-- ④ 站点信息 -->
    <div class="pv-wiz-pane" data-pane="site" style="display:none">
        <label class="pv-field">
            <span>站点名称</span>
            <input type="text" id="wiz-site-title" value="<?php echo pvw_e($default['site_title']); ?>" placeholder="如：PACS 影像浏览器">
        </label>
        <label class="pv-field">
            <span>医院 / 机构名称</span>
            <input type="text" id="wiz-site-hosp" value="<?php echo pvw_e($default['hospital_name']); ?>" placeholder="可选，如：某某门诊诊疗中心">
        </label>
        <label class="pv-field">
            <span>全站时区</span>
            <select id="wiz-site-tz" class="pv-wiz-select"></select>
        </label>
        <p class="pv-hint">存储与对外接口均为标准 UTC；此处仅决定全站显示时区。</p>
        <button type="button" class="pv-btn pv-btn-primary pv-btn-block" id="wiz-site-submit">保存</button>
    </div>

    <!-- ⑤ 管理员 -->
    <div class="pv-wiz-pane" data-pane="admin" style="display:none">
        <label class="pv-field">
            <span>管理员用户名</span>
            <input type="text" id="wiz-admin-user" value="admin" placeholder="以字母开头，2-32 位">
        </label>
        <label class="pv-field">
            <span>显示名称</span>
            <input type="text" id="wiz-admin-name" placeholder="可选，如：系统管理员">
        </label>
        <div class="pv-grid2" style="max-width:none">
            <label class="pv-field">
                <span>密码</span>
                <input type="password" id="wiz-admin-pass" placeholder="至少 6 位">
            </label>
            <label class="pv-field">
                <span>确认密码</span>
                <input type="password" id="wiz-admin-pass2" placeholder="再次输入">
            </label>
        </div>
        <p class="pv-hint">该管理员为安装管理员，创建后<b>不可删除、不可停用</b>。</p>
        <button type="button" class="pv-btn pv-btn-primary pv-btn-block" id="wiz-admin-submit">创建管理员</button>
    </div>

    <!-- ⑥ 完成 -->
    <div class="pv-wiz-pane" data-pane="finish" style="display:none">
        <p class="pv-hint" id="wiz-finish-hint">全部配置已完成，点击进入系统。</p>
        <button type="button" class="pv-btn pv-btn-primary pv-btn-block" id="wiz-finish-submit">完成安装并进入系统</button>
    </div>
</div>
<script>
window.PV_INSTALL = {
    base: <?php echo json_encode(rtrim(PV_URL_SITE, '/') . '/', JSON_UNESCAPED_UNICODE); ?>,
    csrf: <?php echo json_encode($csrf); ?>
};
</script>
<script src="<?php echo pvw_asset('js/ui.js'); ?>"></script>
<script src="<?php echo pvw_asset('js/install.js'); ?>"></script>
<?php if (!empty($error)) { ?>
<script>if (window.PvUI) PvUI.toast(<?php echo json_encode($error, JSON_UNESCAPED_UNICODE); ?>, 'err');</script>
<?php } ?>
</body>
</html>
