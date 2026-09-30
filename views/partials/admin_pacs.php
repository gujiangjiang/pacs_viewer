<?php /** views/partials/admin_pacs.php — 管理设置子面板（由 admin.php 装配） */ ?>
<!-- 外部接口 -->
<section class="pv-tabpane<?php echo $tabCls('pacs'); ?>" data-pane="pacs">
    <form class="pv-card pv-form" method="post" data-ajax-form action="<?php echo pvw_e(pvw_url('admin/save')); ?>">
        <input type="hidden" name="_csrf" value="<?php echo pvw_e(pvw_csrf()); ?>">
        <input type="hidden" name="tab" value="pacs">
        <h3 class="pv-form-title">影像接口（DICOM / PACS）</h3>
        <p class="pv-hint">影像检索与调阅均通过下方 <b>HTTP 接口</b> 获取，请按 PACS 实际能力选择协议：
            <b>DICOMweb</b>（DICOM 标准，QIDO-RS 检索 / WADO-RS 取像，推荐）或
            <b>JSON 网关</b>（本项目自定义的轻量 JSON 接口）。传统 DICOM（DIMSE，TCP 104）
            无法由浏览器直接连接，需先经网关转换为上述其一。FHIR R4 为可选的患者信息补充。</p>

        <div class="pv-split">
            <aside class="pv-split-nav">
                <button type="button" class="pv-split-item active" data-ext="pacs">DICOM / PACS <span class="pv-split-tag">必填</span></button>
                <button type="button" class="pv-split-item" data-ext="fhir">FHIR R4 <span class="pv-split-tag">补充</span></button>
            </aside>
            <div class="pv-split-body">
                <div class="pv-ext-pane" data-ext-pane="pacs">
                    <h4 class="pv-ext-title">DICOMweb 接口（必填）</h4>
                    <p class="pv-hint">采用 DICOM 标准 HTTP 接口：<b>QIDO-RS</b> 检索、<b>WADO-RS</b> 取像
                        （含原生多帧）。若 PACS 仅提供传统 DICOM（DIMSE，TCP），需先经网关转成
                        DICOMweb 再填此处。</p>
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
                    <p class="pv-hint">AE Title / 主机 / DICOM 端口属传统 DICOM（DIMSE）网络身份；本项目通过
                        DICOMweb（HTTP）取数，这些参数用于标识与对接展示（DIMSE 需由网关转换）。</p>
                    <div class="pv-form-actions">
                        <button type="button" id="pvTestPacs" class="pv-btn pv-btn-outline">测试接口连通性</button>
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
