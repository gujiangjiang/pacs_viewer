<?php /** views/partials/admin_storage.php — 管理设置子面板（由 admin.php 装配） */ ?>
<!-- 存储情况 -->
<section class="pv-tabpane<?php echo $tabCls('storage'); ?>" data-pane="storage">
    <div class="pv-card">
        <div class="pv-card-head">
            <h3 class="pv-form-title">存储情况</h3>
            <span class="pv-card-actions">
                <button type="button" class="pv-btn pv-btn-outline pv-btn-sm" id="pvStorageSettings">设置</button>
                <button type="button" class="pv-btn pv-btn-outline pv-btn-sm" id="pvStorageRefresh">刷新</button>
            </span>
        </div>
        <p class="pv-hint">统计运行时数据占用。缓存区为服务端生成影像的内存缓存（APCu 共享内存，避免磁盘反复读写）。</p>
        <div id="pvStorageBox" class="pv-storage"><div class="pv-dim">加载中…</div></div>
        <div class="pv-form-actions">
            <button type="button" class="pv-btn pv-btn-ghost" id="pvStorageClearUploads">清空上传文件</button>
            <button type="button" class="pv-btn pv-btn-ghost" id="pvStorageClearCache">清空缓存区</button>
            <span class="pv-hint">清空操作不可恢复，请谨慎执行</span>
        </div>
    </div>
</section>
