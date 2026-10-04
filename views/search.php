<?php
/** views/search.php — 患者查询主页（片段，左右分栏） */
$page = 'search';
$pageTitle = '患者查询';
$active = 'search';
$bodyClass = 'pv-page-search';
$extraCss = PvAssets::pageCss('search');
$extraJs = PvAssets::pageJs('search');
$pageData = array('mode' => $mode, 'source' => isset($source) ? $source : null, 'flash' => isset($flash) ? $flash : '', 'clearOnOpen' => !empty($clearOnOpen) ? 1 : 0, 'searchView' => isset($searchView) ? $searchView : 'table');
?>
<div class="pv-search-wrap">
    <aside class="pv-search-side pv-card">
        <div class="pv-search-title"><?php echo pvw_icon('search'); ?> 患者查询</div>

        <form id="pvSearchForm" onsubmit="return false;">
            <div class="pv-search-inputrow">
                <input type="text" id="pvKeyword" class="pv-input pv-search-input"
                       placeholder="姓名 / 患者号 / 检查号 / 门诊号 / 检查项目">
                <button type="button" id="pvSearchBtn" class="pv-btn pv-btn-primary">检索</button>
            </div>
        </form>

        <div class="pv-filter">
            <div class="pv-filter-block">
                <label class="pv-filter-label">性别</label>
                <div class="pv-chips" id="pvGenderChips">
                    <button type="button" class="pv-chip active" data-gender="">全部</button>
                    <button type="button" class="pv-chip" data-gender="M">男</button>
                    <button type="button" class="pv-chip" data-gender="F">女</button>
                </div>
            </div>

            <div class="pv-filter-block">
                <label class="pv-filter-label">检查日期</label>
                <div class="pv-chips" id="pvDateChips">
                    <button type="button" class="pv-chip active" data-range="all">全部</button>
                    <button type="button" class="pv-chip" data-range="today">当天</button>
                    <button type="button" class="pv-chip" data-range="3d">三天内</button>
                    <button type="button" class="pv-chip" data-range="week">一周内</button>
                    <button type="button" class="pv-chip" data-range="year">一年内</button>
                    <button type="button" class="pv-chip" data-range="custom">自定义</button>
                </div>
                <div class="pv-daterange" id="pvDateRange">
                    <input type="date" id="pvDateFrom" class="pv-input" disabled>
                    <span class="pv-daterange-sep">至</span>
                    <input type="date" id="pvDateTo" class="pv-input" disabled>
                </div>
                <div class="pv-filter-err" id="pvDateErr" style="display:none"></div>
            </div>

            <div class="pv-filter-block">
                <label class="pv-filter-label">检查类型</label>
                <select id="pvModality" class="pv-input">
                    <option value="">全部</option>
                </select>
            </div>
        </div>

        <div class="pv-side-foot">
            <label class="pv-switch" title="开启后打开影像会先清空影像视图；关闭则追加到已打开的检查之后">
                <input type="checkbox" id="pvClearOnOpen" <?php echo !empty($clearOnOpen) ? 'checked' : ''; ?>>
                <span class="pv-track"></span>
                <span class="pv-switch-label">打开影像时清空已加载序列</span>
            </label>
        </div>
    </aside>

    <main class="pv-search-main">
        <div class="pv-search-toolbar">
            <div id="pvResultMeta" class="pv-result-meta"></div>
            <div class="pv-view-toggle" id="pvViewToggle">
                <button type="button" class="pv-toggle-btn active" data-view="table" title="纯列表（表格）"><?php echo pvw_icon('view-table'); ?></button>
                <button type="button" class="pv-toggle-btn" data-view="list" title="紧凑列表"><?php echo pvw_icon('view-list'); ?></button>
                <button type="button" class="pv-toggle-btn" data-view="card" title="卡片视图"><?php echo pvw_icon('view-card'); ?></button>
            </div>
        </div>
        <div id="pvResults" class="pv-results pv-results-list"></div>
        <div id="pvEmpty" class="pv-card pv-empty" style="display:none">
            <div class="pv-empty-ico"><?php echo pvw_icon('search'); ?></div>
            <div class="pv-empty-title">输入关键词或选择条件开始检索</div>
            <div class="pv-empty-sub">支持姓名、患者号、检查号、门诊号与检查项目模糊匹配</div>
        </div>
    </main>
</div>
