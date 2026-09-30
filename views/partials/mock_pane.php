<?php
/**
 * views/partials/mock_pane.php — 模拟服务器面板（管理设置子 Tab / 独立页共用）
 * 需要变量：$v($k,$d) 设置读取、$mockUrl、$mockKey、$pacsEndpoint
 */
?>
<div class="pv-alert pv-alert-info">
    <b>关于「模拟服务器」</b>：本 PACS 浏览器自身不含数据。这里内置了一个模拟 PACS
    服务器，对外提供<b>标准 HTTP 接口</b>：<b>DICOMweb</b>（QIDO-RS 检索 / WADO-RS 取像）
    与<b>标准 DICOM 文件</b>（WADO-URI），使用<b>内置仿真患者数据</b>，影像按<b>标准 DICOM</b>
    （含多模态、原生多帧连续断层）生成，可被任何标准客户端联调。
    可一键把其 <b>DICOMweb 地址</b>与密钥填入【外部接口】；真实部署请在【外部接口】配置。
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
        <label class="pv-field"><span>DICOMweb 地址（标准接口根地址）</span>
            <span class="pv-copy-row">
                <input type="text" id="pvMockUrl" readonly value="<?php echo pvw_e($mockUrl); ?>">
                <button type="button" class="pv-btn pv-btn-outline pv-btn-sm" data-copy="#pvMockUrl">复制</button>
            </span>
        </label>
        <label class="pv-field"><span>DICOM 网络身份（AE Title / 主机:端口）</span>
            <span class="pv-copy-row">
                <input type="text" id="pvMockAe" readonly
                       value="AE: <?php echo pvw_e(isset($mockAeTitle) ? $mockAeTitle : 'PACSVIEWMOCK'); ?> · <?php echo pvw_e(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost'); ?> / 104">
                <button type="button" class="pv-btn pv-btn-outline pv-btn-sm" data-copy="#pvMockAe">复制</button>
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
            <button type="button" class="pv-btn pv-btn-accent" id="pvApplyMock">⇩ 一键应用模拟服务器数据（标准 DICOMweb）</button>
            <span class="pv-hint">将上方 DICOMweb 地址与密钥、协议自动填入【管理设置 → 外部接口】</span>
        </div>
    </form>
</div>

<!-- 解剖部位与切片数量 -->
<div class="pv-card">
    <h3 class="pv-form-title">解剖部位与切片数量</h3>
    <p class="pv-hint">按「模态 + 匹配关键词」路由到对应解剖模型，并可配置切片数量。
        关键词中英均可（逗号分隔），命中即按部位生成对应解剖影像；未命中则按模态回退。</p>
    <form class="pv-form" method="post" data-ajax-form action="<?php echo pvw_e(pvw_url('api/mock/anatomy')); ?>">
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

<!-- 患者预览 -->
<div class="pv-card">
    <div class="pv-card-head">
        <h3 class="pv-form-title">已缴费已登记患者预览</h3>
        <span class="pv-dim" id="pvMockSourceLabel">来源：内置仿真数据</span>
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
