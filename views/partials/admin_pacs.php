<?php /** views/partials/admin_pacs.php — 管理设置子面板（由 admin.php 装配） */ ?>
<!-- 外部接口：标准 DICOMweb（患者信息随接口返回，无需 FHIR） -->
<section class="pv-tabpane<?php echo $tabCls('pacs'); ?>" data-pane="pacs">
    <form class="pv-card pv-form" method="post" data-ajax-form action="<?php echo pvw_e(pvw_url('admin/save')); ?>">
        <input type="hidden" name="_csrf" value="<?php echo pvw_e(pvw_csrf()); ?>">
        <input type="hidden" name="tab" value="pacs">
        <h3 class="pv-form-title">影像接口（DICOMweb）</h3>
        <p class="pv-hint">影像检索与调阅通过 <b>标准 DICOMweb</b>（QIDO-RS 检索 / WADO-RS 取像，
            含原生多帧）获取，<b>患者信息随接口返回</b>，无需额外配置 FHIR。
            传统 DICOM（DIMSE，TCP 104）不能由浏览器直连，需先经网关转成 DICOMweb。</p>

        <label class="pv-field"><span>DICOMweb 根地址</span>
            <input type="text" name="pacs_endpoint" value="<?php echo pvw_e($v('pacs_endpoint')); ?>" placeholder="如 http://192.168.1.100:8042/dicom-web">
            <em class="pv-hint">以 <code>/dicom-web</code> 结尾的根地址；检索（/studies）与取像均基于它拼接。</em></label>
        <div class="pv-grid2">
            <label class="pv-field"><span>接口密钥</span>
                <input type="text" name="pacs_api_key" value="<?php echo pvw_e($v('pacs_api_key')); ?>" placeholder="可选">
                <em class="pv-hint">以 <code>Authorization: Bearer</code> / <code>X-API-Key</code> 请求头发送。</em></label>
            <label class="pv-field"><span>超时（秒）</span>
                <input type="number" name="pacs_timeout" value="<?php echo pvw_e($v('pacs_timeout', '5')); ?>"></label>
        </div>

        <h4 class="pv-ext-title" style="margin-top:8px">DICOM 网络身份参数</h4>
        <div class="pv-grid2">
            <label class="pv-field"><span>本系统 AE Title</span>
                <input type="text" name="pacs_ae_title" value="<?php echo pvw_e($v('pacs_ae_title')); ?>" placeholder="如 CLINIC_OPD"></label>
            <label class="pv-field"><span>目标 PACS AE Title</span>
                <input type="text" name="pacs_remote_ae" value="<?php echo pvw_e($v('pacs_remote_ae')); ?>" placeholder="如 PACS_SERVER"></label>
        </div>
        <div class="pv-grid2">
            <label class="pv-field"><span>PACS 主机</span>
                <input type="text" name="pacs_server_host" value="<?php echo pvw_e($v('pacs_server_host')); ?>" placeholder="192.168.1.100"></label>
            <label class="pv-field"><span>DICOM 端口</span>
                <input type="text" name="pacs_server_port" value="<?php echo pvw_e($v('pacs_server_port', '104')); ?>" placeholder="104"></label>
        </div>
        <p class="pv-hint">AE Title / 主机 / DICOM 端口属传统 DICOM（DIMSE）网络身份，
            <b>仅作登记与展示</b>：本项目经 DICOMweb（HTTP）取数，<b>不使用、也无需与对端匹配</b>
            （DIMSE 需由网关转为 DICOMweb）。</p>

        <div class="pv-form-actions">
            <button type="button" id="pvTestPacs" class="pv-btn pv-btn-outline">测试接口连通性</button>
            <span id="pvTestResult" class="pv-test-result"></span>
        </div>
        <div class="pv-form-actions">
            <button type="submit" class="pv-btn pv-btn-primary">保存外部接口配置</button>
        </div>
    </form>
</section>
