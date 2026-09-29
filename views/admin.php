<?php
/** views/admin.php — 管理员设置（片段） */
$page = 'admin';
$pageTitle = '管理设置';
$active = 'admin';
$bodyClass = 'pv-page-admin';
$extraCss = array('admin.css', 'mock.css');
$extraJs = array('admin.js', 'mock.js');
$s = $settings;
$v = function ($k, $d = '') use ($s) { return isset($s[$k]) ? $s[$k] : $d; };
$curTab = isset($tab) ? $tab : 'basic';
$tabCls = function ($t) use ($curTab) { return $curTab === $t ? ' active' : ''; };
$pageData = array('flash' => isset($flash) ? $flash : '');
?>
<div class="pv-tabs">
    <button type="button" class="pv-tab<?php echo $tabCls('basic'); ?>" data-tab="basic">基础设置</button>
    <button type="button" class="pv-tab<?php echo $tabCls('pacs'); ?>" data-tab="pacs">外部接口</button>
    <button type="button" class="pv-tab<?php echo $tabCls('users'); ?>" data-tab="users">账号管理</button>
    <button type="button" class="pv-tab<?php echo $tabCls('logs'); ?>" data-tab="logs">检索日志</button>
    <button type="button" class="pv-tab<?php echo $tabCls('mock'); ?>" data-tab="mock">模拟服务器</button>
    <button type="button" class="pv-tab<?php echo $tabCls('storage'); ?>" data-tab="storage">存储情况</button>
</div>

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

<!-- 外部接口 -->
<section class="pv-tabpane<?php echo $tabCls('pacs'); ?>" data-pane="pacs">
    <form class="pv-card pv-form" method="post" data-ajax-form action="<?php echo pvw_e(pvw_url('admin/save')); ?>">
        <input type="hidden" name="_csrf" value="<?php echo pvw_e(pvw_csrf()); ?>">
        <input type="hidden" name="tab" value="pacs">
        <h3 class="pv-form-title">外部接口</h3>
        <p class="pv-hint">选择患者与检查的<b>检索来源</b>；影像统一按标准 DICOM 获取。DICOM / DICOMweb 网关的返回本身即携带患者信息（PatientName / PatientID / 出生日期 / 性别等），FHIR 为可选的 HIS 集成。</p>
        <label class="pv-field"><span>当前检索来源</span>
            <select name="patient_source" id="pvPatientSource">
                <option value="pacs" <?php echo $v('patient_source', 'pacs') !== 'fhir' ? 'selected' : ''; ?>>DICOM / PACS 网关（默认，含患者信息）</option>
                <option value="fhir" <?php echo $v('patient_source', 'pacs') === 'fhir' ? 'selected' : ''; ?>>FHIR R4（门诊系统已缴费 / 已登记）</option>
            </select>
        </label>

        <div class="pv-split">
            <aside class="pv-split-nav">
                <button type="button" class="pv-split-item active" data-ext="pacs">DICOM / PACS</button>
                <button type="button" class="pv-split-item" data-ext="fhir">FHIR R4</button>
            </aside>
            <div class="pv-split-body">
                <div class="pv-ext-pane" data-ext-pane="pacs">
                    <h4 class="pv-ext-title">DICOM / PACS 网关</h4>
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
                    <h4 class="pv-ext-title">FHIR R4（门诊系统）</h4>
                    <p class="pv-hint">从门诊系统获取「已缴费、已登记」的患者与检查（ImagingStudy）；影像仍由 PACS 按 ImagingStudy 中的真实 StudyInstanceUID 提供。</p>
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

<!-- 账号管理 -->
<section class="pv-tabpane<?php echo $tabCls('users'); ?>" data-pane="users">
    <div class="pv-card">
        <div class="pv-card-head">
            <h3 class="pv-form-title">账号管理</h3>
            <button type="button" id="pvAddUser" class="pv-btn pv-btn-primary pv-btn-sm">＋ 新增账号</button>
        </div>
        <table class="pv-table" id="pvUserTable">
            <thead><tr><th>ID</th><th>用户名</th><th>显示名</th><th>角色</th><th>状态</th><th>创建时间</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u) {
                $isOwner = (int)$u['is_owner'] === 1;
                $enabled = (int)$u['status'] === 1;
            ?>
                <tr data-user
                    data-id="<?php echo (int)$u['id']; ?>"
                    data-username="<?php echo pvw_e($u['username']); ?>"
                    data-display="<?php echo pvw_e($u['display_name']); ?>"
                    data-role="<?php echo pvw_e($u['role']); ?>"
                    data-status="<?php echo (int)$u['status']; ?>"
                    data-owner="<?php echo $isOwner ? '1' : '0'; ?>">
                    <td><?php echo (int)$u['id']; ?></td>
                    <td><?php echo pvw_e($u['username']); ?><?php if ($isOwner) { ?> <span class="pv-badge demo" title="安装管理员：不可删除 / 停用">🔒 安装管理员</span><?php } ?></td>
                    <td><?php echo pvw_e($u['display_name']); ?></td>
                    <td><?php echo $u['role'] === 'admin' ? '管理员' : '普通'; ?></td>
                    <td><?php echo $enabled ? '<span class="pv-badge ok">启用</span>' : '<span class="pv-badge off">停用</span>'; ?></td>
                    <td class="pv-dim"><?php echo pvw_e($u['created_at']); ?></td>
                    <td class="pv-actions">
                        <button class="pv-btn pv-btn-ghost pv-btn-sm" data-act="edit">编辑</button>
                        <?php if (!$isOwner) { ?>
                        <button class="pv-btn pv-btn-ghost pv-btn-sm" data-act="status"><?php echo $enabled ? '停用' : '启用'; ?></button>
                        <?php } ?>
                        <button class="pv-btn pv-btn-ghost pv-btn-sm" data-act="password">重置密码</button>
                        <?php if (!$isOwner) { ?>
                        <button class="pv-btn pv-btn-ghost pv-btn-sm pv-btn-danger-text" data-act="delete">删除</button>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
</section>

<!-- 检索日志 -->
<section class="pv-tabpane<?php echo $tabCls('logs'); ?>" data-pane="logs">
    <div class="pv-card">
        <div class="pv-card-head">
            <h3 class="pv-form-title">检索日志（共 <?php echo (int)$logCount; ?> 条）</h3>
            <button type="button" id="pvLogClear" class="pv-btn pv-btn-outline pv-btn-sm">清空</button>
        </div>
        <div class="pv-logscroll">
        <table class="pv-table">
            <thead><tr><th>时间</th><th>账号</th><th>操作</th><th>详情</th><th>关键词</th><th>结果数</th><th>IP</th></tr></thead>
            <tbody>
            <?php if (!$logs) { ?>
                <tr><td colspan="7" class="pv-dim" style="text-align:center">暂无记录</td></tr>
            <?php } foreach ($logs as $l) {
                $act = isset($l['action']) ? $l['action'] : 'search';
            ?>
                <tr><td class="pv-dim"><?php echo pvw_e($l['created_at']); ?></td>
                    <td><?php echo pvw_e($l['username']); ?></td>
                    <td><span class="pv-badge op-<?php echo pvw_e($act); ?>"><?php echo pvw_e(PvQueryLogRepository::actionName($act)); ?></span></td>
                    <td><?php echo pvw_e(isset($l['detail']) ? $l['detail'] : ''); ?></td>
                    <td><?php echo pvw_e($l['keyword']); ?></td>
                    <td><?php echo $act === 'search' ? (int)$l['result_count'] : '—'; ?></td>
                    <td class="pv-dim"><?php echo pvw_e($l['ip']); ?></td></tr>
            <?php } ?>
            </tbody>
        </table>
        </div>
    </div>
</section>

<!-- 模拟服务器 -->
<section class="pv-tabpane<?php echo $tabCls('mock'); ?>" data-pane="mock">
    <?php
    $anatomy = PvMockAnatomyConfig::entries();
    include PV_VIEWS . '/partials/mock_pane.php';
    ?>
</section>

<!-- 存储情况 -->
<section class="pv-tabpane<?php echo $tabCls('storage'); ?>" data-pane="storage">
    <div class="pv-card">
        <div class="pv-card-head">
            <h3 class="pv-form-title">存储情况</h3>
            <button type="button" class="pv-btn pv-btn-outline pv-btn-sm" id="pvStorageRefresh">刷新</button>
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
