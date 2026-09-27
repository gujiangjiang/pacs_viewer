<?php
/** views/mock.php — 内置模拟 PACS 服务器（管理界面，片段） */
$page = 'mock';
$pageTitle = '模拟服务器';
$active = 'mock';
$bodyClass = 'pv-page-mock';
$extraCss = array('mock.css');
$extraJs = array('mock.js');
$s = $settings;
$v = function ($k, $d = '') use ($s) { return isset($s[$k]) ? $s[$k] : $d; };
$source = $v('mock_patient_source', 'builtin');
$pageData = array(
    'mockUrl'      => $mockUrl,
    'mockKey'      => $mockKey,
    'pacsEndpoint' => $pacsEndpoint,
    'flash'        => isset($flash) ? $flash : '',
);
?>
<div class="pv-alert pv-alert-info">
    <b>关于「模拟服务器」</b>：本 PACS 浏览器自身不含数据，仅用于查看影像。
    这里内置了一个模拟 PACS 服务器：通过<b>对外 API</b> 提供标准 PACS 接口（search / study / ping），
    可一键配置给本浏览器的 DICOM / PACS 接口，也可提供给门诊系统调用；患者数据可来自
    <b>内置仿真</b>或<b>门诊系统 FHIR R4（已缴费、已登记）</b>，影像由前端算法确定性生成用于联调。
</div>

<!-- 服务器状态与控制 -->
<div class="pv-card">
    <div class="pv-card-head">
        <h3 class="pv-form-title">服务器状态与控制</h3>
        <span class="pv-badge <?php echo $v('mock_enabled', '1') === '1' ? 'ok' : 'off'; ?>"><?php echo $v('mock_enabled', '1') === '1' ? '运行中' : '已停用'; ?></span>
    </div>
    <form class="pv-form" method="post" data-ajax-form id="pvMockForm" action="<?php echo pvw_e(pvw_url('api/mock/save')); ?>">
        <input type="hidden" name="_csrf" value="<?php echo pvw_e(pvw_csrf()); ?>">
        <label class="pv-field pv-inline-field">
            <span>启用模拟服务器</span>
            <input type="checkbox" name="mock_enabled" value="1" <?php echo $v('mock_enabled', '1') === '1' ? 'checked' : ''; ?>>
            <em class="pv-hint">关闭后对外 API 返回 403，本浏览器也无法通过模拟地址检索</em>
        </label>
        <label class="pv-field"><span>对外 API 地址（PACS 接口地址）</span>
            <span class="pv-copy-row">
                <input type="text" id="pvMockUrl" readonly value="<?php echo pvw_e($mockUrl); ?>">
                <button type="button" class="pv-btn pv-btn-outline pv-btn-sm" data-copy="#pvMockUrl">复制</button>
            </span>
        </label>
        <label class="pv-field"><span>接口密钥（请求参数 key）</span>
            <span class="pv-copy-row">
                <input type="text" id="pvMockKey" readonly value="<?php echo pvw_e($mockKey); ?>">
                <button type="button" class="pv-btn pv-btn-outline pv-btn-sm" data-copy="#pvMockKey">复制</button>
                <button type="button" class="pv-btn pv-btn-ghost pv-btn-sm" id="pvRegenKey">重新生成</button>
            </span>
        </label>
        <div class="pv-form-actions">
            <button type="submit" class="pv-btn pv-btn-primary">保存状态设置</button>
            <button type="button" class="pv-btn pv-btn-accent" id="pvApplyMock">⇩ 一键应用模拟服务器数据</button>
            <span class="pv-hint">将上方地址与密钥自动填入【管理设置 → DICOM / PACS 接口】</span>
        </div>
    </form>
</div>

<!-- 患者数据来源 -->
<div class="pv-card">
    <h3 class="pv-form-title">患者数据来源</h3>
    <p class="pv-hint">模拟服务器需要患者数据来生成检查。可选择内置仿真，或通过 FHIR R4 从门诊系统获取「已缴费、已登记」的患者及其检查。</p>
    <form class="pv-form" method="post" data-ajax-form action="<?php echo pvw_e(pvw_url('api/mock/save')); ?>">
        <input type="hidden" name="_csrf" value="<?php echo pvw_e(pvw_csrf()); ?>">
        <input type="hidden" name="mock_enabled" value="<?php echo $v('mock_enabled', '1') === '1' ? '1' : '0'; ?>">
        <label class="pv-field"><span>数据来源</span>
            <select name="mock_patient_source" id="pvMockSource">
                <option value="builtin" <?php echo $source === 'builtin' ? 'selected' : ''; ?>>内置仿真数据（开箱即用）</option>
                <option value="fhir" <?php echo $source === 'fhir' ? 'selected' : ''; ?>>门诊系统 FHIR R4（已缴费 / 已登记）</option>
            </select>
        </label>
        <div id="pvFhirBox" class="<?php echo $source === 'fhir' ? '' : 'pv-hidden'; ?>">
            <label class="pv-field"><span>FHIR 接口地址</span>
                <input type="text" name="fhir_endpoint" value="<?php echo pvw_e($v('fhir_endpoint')); ?>" placeholder="如 http://192.168.1.100/fhir/R4"></label>
            <div class="pv-grid2">
                <label class="pv-field"><span>访问密钥（可选）</span>
                    <input type="text" name="fhir_api_key" value="<?php echo pvw_e($v('fhir_api_key')); ?>" placeholder="Bearer / X-API-Key"></label>
                <label class="pv-field"><span>超时（秒）</span>
                    <input type="number" name="fhir_timeout" value="<?php echo pvw_e($v('fhir_timeout', '5')); ?>"></label>
            </div>
            <div class="pv-form-actions">
                <button type="submit" class="pv-btn pv-btn-primary">保存来源设置</button>
                <button type="button" class="pv-btn pv-btn-outline" id="pvFhirTest">测试 FHIR 连接</button>
                <span id="pvFhirResult" class="pv-test-result"></span>
            </div>
        </div>
        <div id="pvFhirSaveBuiltin" class="<?php echo $source === 'fhir' ? 'pv-hidden' : ''; ?>">
            <button type="submit" class="pv-btn pv-btn-primary">保存来源设置</button>
        </div>
    </form>
</div>

<!-- 患者预览 -->
<div class="pv-card">
    <div class="pv-card-head">
        <h3 class="pv-form-title">已缴费已登记患者预览</h3>
        <span class="pv-dim" id="pvMockSourceLabel">来源：<?php echo $source === 'fhir' ? '门诊系统 FHIR' : '内置仿真'; ?></span>
    </div>
    <form class="pv-searchbox" onsubmit="return false;">
        <input type="text" id="pvMockKeyword" class="pv-input pv-search-input" placeholder="输入姓名 / 患者号 / 检查号 / 门诊号 / 检查项目">
        <button type="button" id="pvMockSearch" class="pv-btn pv-btn-primary">检索患者</button>
    </form>
    <div id="pvMockResultMeta" class="pv-result-meta" style="display:none"></div>
    <div id="pvMockPatients" class="pv-mock-patients"></div>
    <div id="pvMockEmpty" class="pv-empty" style="display:none">
        <div class="pv-empty-ico">🧪</div>
        <div class="pv-empty-title">点击「检索患者」查看模拟服务器中的患者与检查</div>
    </div>
</div>
