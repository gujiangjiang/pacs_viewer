<?php
/** views/partials/footer.php — 公共页脚外壳（含脚本装配与页面初始化） */
?>
</main>
<footer class="pv-footer">
    <span class="pv-footer-left"><span class="pv-footer-site"><?php echo pvw_e(PvSettings::get('site_title', 'PACS 影像浏览器')); ?></span><span class="pv-footer-ver"> · v<?php echo pvw_e(PV_VERSION); ?></span></span>
    <span class="pv-footer-hosp"><?php echo pvw_e(pvw_hospital()); ?></span>
    <?php $pvFooterSrc = PvStudyService::sourceInfo(); ?>
    <span id="pvFooterSource" class="pv-footer-src <?php echo $pvFooterSrc['state'] !== 'unset' ? 'is-ok' : 'is-off'; ?>"><?php echo pvw_e($pvFooterSrc['label']); ?></span>
</footer>
<?php foreach (PvAssets::commonJs() as $pvCommonJs) { ?>
<script src="<?php echo pvw_asset('js/' . $pvCommonJs); ?>"></script>
<?php } ?>
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
