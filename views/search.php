<?php
/** views/search.php — 研究检索主页（片段） */
$page = 'search';
$pageTitle = '研究检索';
$active = 'search';
$bodyClass = 'pv-page-search';
$extraCss = array('search.css');
$extraJs = array('search.js');
$pageData = array('mode' => $mode, 'flash' => isset($flash) ? $flash : '');
?>
<div class="pv-searchbox pv-card">
    <form id="pvSearchForm" onsubmit="return false;">
        <input type="text" id="pvKeyword" class="pv-input pv-search-input"
               placeholder="输入患者姓名 / 患者号 / 检查号 / 门诊号 / 检查项目">
        <button type="button" id="pvSearchBtn" class="pv-btn pv-btn-primary">检索</button>
    </form>
    <div class="pv-search-meta">
        <span>数据来源：<b id="pvMode" class="<?php echo $mode === 'Remote' ? 'is-remote' : 'is-demo'; ?>"><?php echo $mode === 'Remote' ? '远程 PACS 接口' : '未配置 PACS 接口'; ?></b></span>
        <label class="pv-check" title="勾选后打开影像会先清空影像视图；不勾选则追加到已打开的检查之后">
            <input type="checkbox" id="pvClearOnOpen"> 打开影像时清空已加载序列
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
