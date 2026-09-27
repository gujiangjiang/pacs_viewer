<?php
/** views/auth/login.php — 登录窗口 */
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>登录 · <?php echo pvw_e($site); ?></title>
<link rel="stylesheet" href="<?php echo pvw_asset('css/base.css'); ?>">
<link rel="stylesheet" href="<?php echo pvw_asset('css/auth.css'); ?>">
</head>
<body class="pv-auth-body">
<div class="pv-auth-card">
    <div class="pv-auth-head">
        <div class="pv-auth-logo">🩻</div>
        <h1><?php echo pvw_e($site); ?></h1>
        <p class="pv-auth-sub">DICOM / PACS 接口联调测试工具</p>
    </div>
    <?php if (!empty($error)) { ?><div class="pv-alert pv-alert-error"><?php echo pvw_e($error); ?></div><?php } ?>
    <form method="post" action="<?php echo pvw_e(pvw_url('login')); ?>" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?php echo pvw_e(pvw_csrf()); ?>">
        <label class="pv-field">
            <span>用户名</span>
            <input type="text" name="username" required autofocus placeholder="请输入用户名">
        </label>
        <label class="pv-field">
            <span>密码</span>
            <input type="password" name="password" required placeholder="请输入密码">
        </label>
        <button type="submit" class="pv-btn pv-btn-primary pv-btn-block">登 录</button>
    </form>
    <div class="pv-auth-hint">
        使用安装时创建的管理员账号登录
    </div>
</div>
</body>
</html>
