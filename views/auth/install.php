<?php
/** views/auth/install.php — 首次运行安装向导（创建首个管理员） */
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
        <div class="pv-auth-logo">🩻</div>
        <h1>首次运行安装</h1>
        <p class="pv-auth-sub">创建管理员账号并完成初始化，之后即可登录使用</p>
    </div>
    <form method="post" action="<?php echo pvw_e(pvw_url('install/submit')); ?>" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?php echo pvw_e(pvw_csrf()); ?>">
        <h3 class="pv-form-title">站点信息</h3>
        <label class="pv-field">
            <span>站点名称</span>
            <input type="text" name="site_title" value="<?php echo pvw_e($default['site_title']); ?>" placeholder="如：PACS 影像浏览器">
        </label>
        <label class="pv-field">
            <span>医院 / 机构名称<span class="pv-hint" style="display:inline">（作为接口未返回机构名时的兜底展示）</span></span>
            <input type="text" name="hospital_name" value="<?php echo pvw_e($default['hospital_name']); ?>" placeholder="可选，如：某某门诊诊疗中心">
        </label>
        <h3 class="pv-form-title" style="margin-top:22px">管理员账号</h3>
        <label class="pv-field">
            <span>用户名</span>
            <input type="text" name="username" required autofocus placeholder="以字母开头，2-32 位" value="admin">
        </label>
        <label class="pv-field">
            <span>显示名称</span>
            <input type="text" name="display_name" placeholder="可选，如：系统管理员">
        </label>
        <div class="pv-grid2" style="max-width:none">
            <label class="pv-field">
                <span>密码</span>
                <input type="password" name="password" required placeholder="至少 6 位">
            </label>
            <label class="pv-field">
                <span>确认密码</span>
                <input type="password" name="password_confirm" required placeholder="再次输入">
            </label>
        </div>
        <p class="pv-hint">该管理员为安装管理员，创建后<b>不可删除、不可停用</b>；后续可在【管理设置 → 账号管理】中新增与管理其他用户。</p>
        <button type="submit" class="pv-btn pv-btn-primary pv-btn-block" style="margin-top:8px">完成安装并进入</button>
    </form>
</div>
<script src="<?php echo pvw_asset('js/ui.js'); ?>"></script>
<?php if (!empty($error)) { ?>
<script>if (window.PvUI) PvUI.toast(<?php echo json_encode($error, JSON_UNESCAPED_UNICODE); ?>, 'err');</script>
<?php } ?>
</body>
</html>
