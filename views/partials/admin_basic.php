<?php /** views/partials/admin_basic.php — 管理设置子面板（由 admin.php 装配） */ ?>
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
        <label class="pv-field"><span>影像视图序列上限</span>
            <select name="viewer_study_limit">
                <?php for ($i = 3; $i <= 10; $i++) { ?>
                <option value="<?php echo $i; ?>" <?php echo (int)$v('viewer_study_limit', '5') === $i ? 'selected' : ''; ?>><?php echo $i; ?> 个</option>
                <?php } ?>
            </select>
            <em class="pv-hint">影像视图最多同时打开的患者检查数，超出后自动移除最早打开的检查（3-10）</em></label>
        <button type="submit" class="pv-btn pv-btn-primary">保存基础设置</button>
    </form>

    <div class="pv-card">
        <h3 class="pv-form-title">站点图标</h3>
        <div class="pv-icon-row">
            <img id="pvIconPreview" class="pv-icon-preview" src="<?php echo pvw_e(PvPwaController::iconUrl(64)); ?>" alt="图标预览" width="64" height="64">
            <form method="post" data-ajax-form action="<?php echo pvw_e(pvw_url('admin/icon-upload')); ?>" enctype="multipart/form-data" class="pv-icon-form">
                <input type="hidden" name="_csrf" value="<?php echo pvw_e(pvw_csrf()); ?>">
                <input type="file" name="icon" accept="image/png,image/jpeg,image/gif,image/webp" required>
                <button type="submit" class="pv-btn pv-btn-primary pv-btn-sm">上传图标</button>
            </form>
            <button type="button" id="pvIconReset" class="pv-btn pv-btn-ghost pv-btn-sm">恢复默认</button>
        </div>
        <p class="pv-hint">未设置时使用内置<b>代码绘制</b>的默认图标；上传后作为站点标题栏与 PWA 图标（支持 PNG / JPG / GIF / WebP，≤4MB）。</p>
    </div>
</section>
