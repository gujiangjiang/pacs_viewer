<?php
/**
 * views/partials/mock_pane.php — 模拟服务器面板（管理设置子 Tab）
 * 左栏导航：服务器状态（指标预览）/ 控制 / 数据来源 / 部位与切片 / 标准 API / 患者查询。
 * 变量：$v($k,$d)、$mockUrl、$mockKey、$mockViewerUrl、$mockAeTitle、$anatomy
 */
$pvMockAe = isset($mockAeTitle) ? $mockAeTitle : 'PACSVIEWMOCK';
$pvHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
$pvEnabled = $v('mock_enabled', '1') === '1';
$pvSrc = $v('mock_patient_source', 'builtin') === 'fhir' ? 'fhir' : 'builtin';
$pvPartList = array();
foreach ((array)$anatomy as $e) {
    if (empty($e['enabled'])) continue;
    $pvPartList[] = ($e['modality'] !== '' ? $e['modality'] . '·' : '') . $e['label'] . '（' . (int)$e['frames'] . ' 帧）';
}
$pvPartCount = count($pvPartList);
$pvPartText = $pvPartList ? implode('、', $pvPartList) : '未启用任何部位';
?>
<div class="pv-alert pv-alert-info">
    <b>关于「模拟服务器」</b>：本 PACS 浏览器自身不含数据。这里内置了一个模拟 PACS
    服务器，对外提供<b>标准 HTTP 接口</b>：<b>DICOMweb</b>（QIDO-RS 检索 / WADO-RS 取像）
    与<b>标准 DICOM 文件</b>（WADO-URI）；患者数据可来自<b>内置仿真数据</b>或<b>FHIR R4 接口</b>，
    影像按<b>标准 DICOM</b>（含多模态、原生多帧连续断层）生成，可被任何标准客户端联调。
</div>

<div class="pv-split pv-mock-split">
    <aside class="pv-split-nav">
        <button type="button" class="pv-split-item" data-mp="status">服务器状态</button>
        <button type="button" class="pv-split-item" data-mp="control">服务器控制</button>
        <button type="button" class="pv-split-item" data-mp="source">数据来源</button>
        <button type="button" class="pv-split-item" data-mp="anatomy">部位与切片</button>
        <button type="button" class="pv-split-item" data-mp="api">标准 API</button>
        <button type="button" class="pv-split-item" data-mp="patients">患者查询</button>
    </aside>
    <div class="pv-split-body">

        <!-- 服务器状态：指标预览（只读） -->
        <div class="pv-card" data-mp-pane="status">
            <div class="pv-card-head">
                <h3 class="pv-form-title">服务器状态</h3>
                <span id="pvStatBadge" class="pv-badge <?php echo $pvEnabled ? 'ok' : 'off'; ?>"><?php echo $pvEnabled ? '运行中' : '已停用'; ?></span>
            </div>
            <div class="pv-table-wrap">
                <table class="pv-table">
                    <tbody>
                        <tr><th>模拟服务器</th><td id="pvStatEnabled"><?php echo $pvEnabled ? '运行中' : '已停用'; ?></td></tr>
                        <tr><th>患者数据来源</th><td id="pvStatSource"><?php echo $pvSrc === 'fhir' ? 'FHIR 接口获取' : '内置模拟数据'; ?></td></tr>
                        <tr><th>已启用部位</th><td><span id="pvStatPartCount"><?php echo $pvPartCount; ?></span> 项</td></tr>
                        <tr><th>部位与切片</th><td id="pvStatParts"><?php echo pvw_e($pvPartText); ?></td></tr>
                        <tr><th>影像生成</th><td>标准 DICOM · 多模态 · 原生多帧</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="pv-hint">此处仅为预览指标；参数配置请到「服务器控制」与「数据来源」，部位与切片数量到「部位与切片」。</p>
        </div>

        <!-- 控制 + 数据来源：共用一个保存表单（保存后停留当前子页） -->
        <form class="pv-form" method="post" data-ajax-form data-ok-noreload id="pvMockForm" action="<?php echo pvw_e(pvw_url('api/mock/save')); ?>">
            <input type="hidden" name="_csrf" value="<?php echo pvw_e(pvw_csrf()); ?>">

            <!-- 服务器控制 -->
            <div class="pv-card pv-hidden" data-mp-pane="control">
                <h3 class="pv-form-title">服务器控制</h3>

                <div class="pv-mock-switch">
                    <label class="pv-switch">
                        <input type="hidden" name="mock_enabled" value="0">
                        <input type="checkbox" name="mock_enabled" value="1" id="pvMockEnabled" <?php echo $pvEnabled ? 'checked' : ''; ?>>
                        <span class="pv-track"></span>
                        <span class="pv-switch-label">启用模拟服务器</span>
                    </label>
                    <em class="pv-hint">关闭后对外 API 返回 403，本浏览器也无法通过模拟地址检索。</em>
                </div>

                <div id="pvMockParams" class="pv-mock-params <?php echo $pvEnabled ? '' : 'pv-hidden'; ?>">

                    <section class="pv-field-group">
                        <h4 class="pv-group-title">对外接口地址（供外部客户端对接，点击输入框即可复制）</h4>
                        <label class="pv-field"><span>DICOMweb 根地址 · QIDO-RS 检索 / WADO-RS 取像</span>
                            <input type="text" id="pvMockUrl" class="pv-copy" data-copy="#pvMockUrl" readonly title="点击复制" value="<?php echo pvw_e($mockUrl); ?>">
                            <em class="pv-hint">以 <code>/dicom-web</code> 结尾；检索（<code>/studies</code>）与取像均基于它拼接。</em>
                        </label>
                        <label class="pv-field"><span>接口密钥 · 请求头 <code>X-API-Key</code> 或 <code>Authorization: Bearer</code></span>
                            <span class="pv-copy-row">
                                <input type="text" id="pvMockKey" class="pv-copy" data-copy="#pvMockKey" readonly title="点击复制" value="<?php echo pvw_e($mockKey); ?>">
                                <button type="button" class="pv-btn pv-btn-ghost pv-btn-sm" id="pvRegenKey">重新生成</button>
                            </span>
                        </label>
                        <label class="pv-field"><span>Web 阅片器 URL 模板</span>
                            <input type="text" id="pvMockViewerUrl" class="pv-copy" data-copy="#pvMockViewerUrl" readonly title="点击复制" value="<?php echo pvw_e($mockViewerUrl); ?>">
                            <em class="pv-hint">把 <code>{study_uid}</code> 替换为当前检查的 StudyInstanceUID，即可直接打开本浏览器阅片；它指向浏览器本身，与上方 DICOMweb 取像接口相互独立。</em>
                        </label>
                    </section>

                    <section class="pv-field-group">
                        <h4 class="pv-group-title">DICOM 网络身份（传统 DIMSE，仅供参考）</h4>
                        <div class="pv-grid2">
                            <label class="pv-field"><span>AE Title</span>
                                <input type="text" id="pvMockAe" class="pv-copy" data-copy="#pvMockAe" readonly title="点击复制" value="<?php echo pvw_e($pvMockAe); ?>">
                            </label>
                            <label class="pv-field"><span>DICOM 端口</span>
                                <input type="text" id="pvMockPort" class="pv-copy" data-copy="#pvMockPort" readonly title="点击复制" value="104">
                            </label>
                        </div>
                        <label class="pv-field"><span>主机</span>
                            <input type="text" id="pvMockHost" class="pv-copy" data-copy="#pvMockHost" readonly title="点击复制" value="<?php echo pvw_e($pvHost); ?>">
                        </label>
                        <p class="pv-hint">AE Title / 主机 / 端口为传统 DICOM（DIMSE）网络身份，仅供参考；本项目经 DICOMweb(HTTP) 取数，不使用、也无需与对端匹配。</p>
                    </section>

                    <div class="pv-form-actions pv-apply-bar">
                        <button type="button" class="pv-btn pv-btn-accent" id="pvApplyMock">⇩ 一键应用模拟服务器数据</button>
                        <span class="pv-hint">将 DICOMweb 地址与密钥自动填入【外部接口】。</span>
                    </div>
                </div>

                <div class="pv-form-actions pv-form-actions-bar">
                    <button type="submit" class="pv-btn pv-btn-primary">保存服务器控制</button>
                    <span class="pv-hint">地址 / 密钥为只读，由系统生成；此处保存启停状态。</span>
                </div>
            </div>

            <!-- 患者数据来源 -->
            <div class="pv-card pv-hidden" data-mp-pane="source">
                <h3 class="pv-form-title">患者数据来源</h3>
                <div class="pv-split">
                    <aside class="pv-split-nav">
                        <button type="button" class="pv-split-item" data-src="builtin">内置模拟数据</button>
                        <button type="button" class="pv-split-item" data-src="fhir">FHIR 接口获取</button>
                    </aside>
                    <div class="pv-split-body">
                        <div data-src-pane="builtin">
                            <p class="pv-hint">使用内置<b>确定性仿真患者数据</b>（开箱即用）。</p>
                            <label class="pv-field"><span>模拟数据机构名称</span>
                                <input type="text" name="mock_hospital_name" value="<?php echo pvw_e($v('mock_hospital_name')); ?>" placeholder="默认医院">
                                <em class="pv-hint">对外提供 DICOMweb 时的机构名称（InstitutionName）；留空显示「默认医院」。</em></label>
                        </div>
                        <div data-src-pane="fhir" class="pv-hidden">
                            <p class="pv-hint">由 <b>FHIR R4</b> 接口获取患者与就诊记录（如 `Patient/{patient_no}`、
                                `Encounter?patient=`）；影像仍由内置模拟服务器按标准 DICOM 生成。</p>
                            <label class="pv-field"><span>FHIR 接口地址</span>
                                <input type="text" name="fhir_endpoint" value="<?php echo pvw_e($v('fhir_endpoint')); ?>" placeholder="如 http://127.0.0.1:8000/api/fhir/r4"></label>
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
                <input type="hidden" name="mock_patient_source" value="<?php echo pvw_e($pvSrc); ?>">
                <div class="pv-form-actions">
                    <button type="submit" class="pv-btn pv-btn-primary">保存数据来源</button>
                </div>
            </div>
        </form>

        <!-- 部位与切片 -->
        <div class="pv-card pv-hidden" data-mp-pane="anatomy">
            <h3 class="pv-form-title">解剖部位与切片数量</h3>
            <p class="pv-hint">按「模态 + 匹配关键词」路由到对应解剖模型，并可配置切片数量。
                关键词中英均可（逗号分隔），命中即按部位生成对应解剖影像；未命中则按模态回退。</p>
            <form class="pv-form" method="post" data-ajax-form data-ok-noreload action="<?php echo pvw_e(pvw_url('api/mock/anatomy')); ?>">
                <input type="hidden" name="_csrf" value="<?php echo pvw_e(pvw_csrf()); ?>">
                <div class="pv-table-wrap">
                    <table class="pv-table pv-anatomy-table">
                        <thead><tr><th>模态</th><th>部位</th><th>标签</th><th>匹配关键词</th><th>切片数</th><th>启用</th><th></th></tr></thead>
                        <tbody id="pvAnatomyRows">
                        <?php
                        $bodyKeys = array('head' => '颅脑', 'chest' => '胸部', 'lumbar' => '腰椎/脊柱', 'abdomen' => '腹部', 'knee' => '膝/四肢', 'cardiac' => '心脏');
                        $ai = 0;
                        foreach ($anatomy as $e):
                            $idx = $ai++;
                        ?>
                        <tr>
                            <td><input type="text" class="pv-input pv-in-sm" name="anatomy[<?php echo $idx; ?>][modality]" value="<?php echo pvw_e($e['modality']); ?>" placeholder="CT/MR/DR/US"></td>
                            <td>
                                <select class="pv-input pv-in-sm" name="anatomy[<?php echo $idx; ?>][body_key]">
                                    <?php foreach ($bodyKeys as $bk => $bl): ?>
                                    <option value="<?php echo pvw_e($bk); ?>" <?php echo $e['body_key'] === $bk ? 'selected' : ''; ?>><?php echo pvw_e($bl); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="text" class="pv-input pv-in-sm" name="anatomy[<?php echo $idx; ?>][label]" value="<?php echo pvw_e($e['label']); ?>"></td>
                            <td><input type="text" class="pv-input pv-in-lg" name="anatomy[<?php echo $idx; ?>][keywords]" value="<?php echo pvw_e($e['keywords']); ?>"></td>
                            <td><input type="number" min="1" max="512" class="pv-input pv-in-xs" name="anatomy[<?php echo $idx; ?>][frames]" value="<?php echo (int)$e['frames']; ?>"></td>
                            <td><input type="checkbox" name="anatomy[<?php echo $idx; ?>][enabled]" value="1" <?php echo $e['enabled'] ? 'checked' : ''; ?>></td>
                            <td><button type="button" class="pv-btn pv-btn-ghost pv-btn-sm" data-anatomy-remove>删除</button></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pv-form-actions">
                    <button type="button" class="pv-btn pv-btn-outline" id="pvAnatomyAdd">＋ 添加部位</button>
                    <button type="submit" class="pv-btn pv-btn-primary">保存解剖部位配置</button>
                    <button type="button" class="pv-btn pv-btn-ghost" id="pvAnatomyReset">恢复默认</button>
                    <span class="pv-hint">修改切片数后，重新打开检查即可生效（新序列规划）</span>
                </div>
            </form>
            <template id="pvAnatomyTpl">
                <tr>
                    <td><input type="text" class="pv-input pv-in-sm" data-f="modality" value="" placeholder="CT/MR/DR/US"></td>
                    <td>
                        <select class="pv-input pv-in-sm" data-f="body_key">
                            <?php foreach ($bodyKeys as $bk => $bl): ?>
                            <option value="<?php echo pvw_e($bk); ?>"><?php echo pvw_e($bl); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="text" class="pv-input pv-in-sm" data-f="label" value=""></td>
                    <td><input type="text" class="pv-input pv-in-lg" data-f="keywords" value=""></td>
                    <td><input type="number" min="1" max="512" class="pv-input pv-in-xs" data-f="frames" value="32"></td>
                    <td><input type="checkbox" data-f="enabled" value="1" checked></td>
                    <td><button type="button" class="pv-btn pv-btn-ghost pv-btn-sm" data-anatomy-remove>删除</button></td>
                </tr>
            </template>
        </div>

        <!-- 标准 API -->
        <div class="pv-card pv-hidden" data-mp-pane="api">
            <h3 class="pv-form-title">标准 DICOMweb 端点（可供外部客户端对接）</h3>
            <p class="pv-hint">以根地址 <code><?php echo pvw_e($mockUrl); ?></code> 为前缀，按标准语义访问；
                认证在请求头携带密钥（<code>X-API-Key</code> 或 <code>Authorization: Bearer</code>）。</p>
            <div class="pv-table-wrap">
                <table class="pv-table">
                    <thead><tr><th>方法</th><th>路径</th><th>说明</th></tr></thead>
                    <tbody>
                        <tr><td>GET</td><td><code>{根}/studies?PatientName=&amp;limit=&amp;offset=&amp;includefield=all</code></td><td>QIDO-RS 检索检查</td></tr>
                        <tr><td>GET</td><td><code>{根}/studies/{studyUID}/series</code></td><td>序列列表</td></tr>
                        <tr><td>GET</td><td><code>{根}/studies/{studyUID}/series/{seriesUID}/instances</code></td><td>实例列表（含 NumberOfFrames）</td></tr>
                        <tr><td>GET</td><td><code>{根}/studies/{studyUID}/series/{seriesUID}/instances/{sopUID}</code></td><td>WADO-RS 实例字节流（application/dicom）</td></tr>
                        <tr><td>GET</td><td><code>{根}/studies/{studyUID}/series/{seriesUID}/instances/{sopUID}/rendered</code></td><td>渲染图（缩略图）</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 患者查询 -->
        <div class="pv-card pv-hidden" data-mp-pane="patients">
            <div class="pv-card-head">
                <h3 class="pv-form-title">已缴费已登记患者预览</h3>
                <span class="pv-dim" id="pvMockSourceLabel">来源：内置仿真数据</span>
            </div>
            <form class="pv-searchbox" onsubmit="return false;">
                <input type="text" id="pvMockKeyword" class="pv-input pv-search-input" placeholder="输入患者号 / 姓名 / 检查号 / 门诊号 / 检查项目">
                <button type="button" id="pvMockSearch" class="pv-btn pv-btn-primary">检索患者</button>
            </form>
            <div id="pvMockResultMeta" class="pv-result-meta" style="display:none"></div>
            <div id="pvMockPatients" class="pv-mock-patients"></div>
            <div id="pvMockEmpty" class="pv-empty" style="display:none">
                <div class="pv-empty-ico">🧪</div>
                <div class="pv-empty-title">点击「检索患者」查看模拟服务器中的患者与检查</div>
            </div>
        </div>

    </div>
</div>
