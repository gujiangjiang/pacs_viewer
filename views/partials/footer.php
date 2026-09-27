<?php
/** views/partials/footer.php — 公共页脚外壳（含脚本装配与页面初始化） */
?>
</main>
<footer class="pv-footer">
    <span class="pv-footer-left"><?php echo pvw_e(PvSettings::get('site_title', 'PACS 影像浏览器')); ?> · v<?php echo pvw_e(PV_VERSION); ?></span>
    <span class="pv-footer-hosp"><?php echo pvw_e(pvw_hospital()); ?></span>
    <span class="pv-footer-dim">仅供 DICOM / PACS 接口联调测试</span>
</footer>
<script src="<?php echo pvw_asset('js/api.js'); ?>"></script>
<script src="<?php echo pvw_asset('js/ui.js'); ?>"></script>
<script src="<?php echo pvw_asset('js/spa.js'); ?>"></script>
<script src="<?php echo pvw_asset('js/pwa.js'); ?>"></script>
<?php if (!empty($extraJs)) { foreach ((array)$extraJs as $j) { ?>
<script src="<?php echo pvw_asset('js/' . $j); ?>"></script>
<?php } } ?>
<script>
(function () {
    var page = window.PV_BOOT && window.PV_BOOT.page;
    if (page && window.PvPages && window.PvPages[page] && typeof window.PvPages[page].init === 'function') {
        try { window.PvPages[page].init(window.PV_BOOT.data || {}); } catch (e) { if (window.console) console.error(e); }
    }
})();
</script>
</body>
</html>
