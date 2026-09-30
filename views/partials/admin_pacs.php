<?php /** views/partials/admin_pacs.php — 管理设置子面板（由 admin.php 装配） */ ?>
<!-- 外部接口 -->
<section class="pv-tabpane<?php echo $tabCls('pacs'); ?>" data-pane="pacs">
    <form class="pv-card pv-form" method="post" data-ajax-form action="<?php echo pvw_e(pvw_url('admin/save')); ?>">
        <input type="hidden" name="_csrf" value="<?php echo pvw_e(pvw_csrf()); ?>">
        <input type="hidden" name="tab" value="pacs">
        <h3 class="pv-form-title">外部接口</h3>
        <p class="pv-hint">DICOM / PACS 网关为<b>必填</b>（患者检索与影像获取均以它为基础）；FHIR R4 为<b>可选的患者信息补充</b>，两者不同步时互为补充、互不影响。</p>

        <div class="pv-split">
            <aside class="pv-split-nav">
                <button type="button" class="pv-split-item active" data-ext="pacs">DICOM / PACS <span class="pv-split-tag">必填</span></button>
                <button type="button" class="pv-split-item" data-ext="fhir">FHIR R4 <span class="pv-split-tag">补充</span></button>
            </aside>
            <div class="pv-split-body">
                <div class="pv-ext-pane" data-ext-pane="pacs">
                    <h4 class="pv-ext-title">DICOM / PACS 网关（必填）</h4>
                    <p class="pv-hint">检索与调阅数据来自下方接口地址；本地联调可指向【模拟服务器】对外 API（在该页一键填入）。</p>
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
                        <button type="button" id="pvTestPacs" class="pv-btn pv-btn-outline">测试 DICOM / PACS 接口</button>
                        <span id="pvTestResult" class="pv-test-result"></span>
                    </div>
                </div>

                <div class="pv-ext-pane pv-hidden" data-ext-pane="fhir">
                    <h4 class="pv-ext-title">FHIR R4（患者信息补充，可选）</h4>
                    <p class="pv-hint">开启后：命中 FHIR 的检查会用其患者主数据（姓名 / 性别 / 出生日期 / 年龄 / 门诊号等）补充；FHIR 中「已登记但 PACS 暂无影像」的检查也会列出（标记为仅登记）。影像始终来自 DICOM / PACS。</p>
                    <label class="pv-field pv-inline-field">
                        <span>启用 FHIR 补充</span>
                        <input type="hidden" name="fhir_enabled" value="0">
                        <input type="checkbox" name="fhir_enabled" value="1" <?php echo $v('fhir_enabled', '0') === '1' ? 'checked' : ''; ?>>
                    </label>
                    <label class="pv-field"><span>FHIR 接口地址</span>
                        <input type="text" name="fhir_endpoint" value="<?php echo pvw_e($v('fhir_endpoint')); ?>" placeholder="如 http://192.168.1.100/fhir/R4"></label>
                    <div class="pv-grid2">
                        <label class="pv-field"><span>访问密钥（可选）</span>
                            <input type="text" name="fhir_api_key" value="<?php echo pvw_e($v('fhir_api_key')); ?>" placeholder="Bearer / X-API-Key"></label>
                        <label class="pv-field"><span>超时（秒）</span>
                            <input type="number" name="fhir_timeout" value="<?php echo pvw_e($v('fhir_timeout', '5')); ?>"></label>
                    </div>
                    <div class="pv-form-actions">
                        <button type="button" class="pv-btn pv-btn-outline" id="pvFhirTestMain">测试 FHIR 连接</button>
                        <span id="pvFhirResultMain" class="pv-test-result"></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="pv-form-actions">
            <button type="submit" class="pv-btn pv-btn-primary">保存外部接口配置</button>
        </div>
    </form>
</section>
