<?php
/** views/search.php — 研究检索主页（片段） */
$page = 'search';
$pageTitle = '研究检索';
$active = 'search';
$bodyClass = 'pv-page-search';
$extraCss = array('search.css');
$extraJs = array('search.js');
$pageData = array('mode' => $mode, 'source' => isset($source) ? $source : null, 'flash' => isset($flash) ? $flash : '', 'clearOnOpen' => !empty($clearOnOpen) ? 1 : 0);
$pvSrc = isset($source) ? $source : array('label' => '未配置 PACS 接口', 'state' => 'unset', 'fhir' => false);
?>
<div class="pv-searchbox pv-card">
    <form id="pvSearchForm" onsubmit="return false;">
        <input type="text" id="pvKeyword" class="pv-input pv-search-input"
               placeholder="输入患者姓名 / 患者号 / 检查号 / 门诊号 / 检查项目">
        <button type="button" id="pvSearchBtn" class="pv-btn pv-btn-primary">检索</button>
    </form>
    <div class="pv-search-meta">
        <span>数据来源：<b id="pvMode" class="<?php echo $pvSrc['state'] !== 'unset' ? 'is-remote' : 'is-demo'; ?>"><?php echo pvw_e($pvSrc['label']); ?></b><?php if (!empty($pvSrc['fhir'])) { ?> <span class="pv-dim">+ FHIR 补充</span><?php } ?></span>
        <label class="pv-check" title="勾选后打开影像会先清空影像视图；不勾选则追加到已打开的检查之后">
            <input type="checkbox" id="pvClearOnOpen" <?php echo !empty($clearOnOpen) ? 'checked' : ''; ?>> 打开影像时清空已加载序列
        </label>
    </div>
</div>

<div id="pvResultMeta" class="pv-result-meta" style="display:none"></div>
<div id="pvResults" class="pv-results"></div>
<div id="pvEmpty" class="pv-card pv-empty" style="display:none">
    <div class="pv-empty-ico">🔍</div>
    <div class="pv-empty-title">输入关键词开始检索</div>
    <div class="pv-empty-sub">支持姓名、患者号、检查号、门诊号与检查项目模糊匹配</div>
</div>
