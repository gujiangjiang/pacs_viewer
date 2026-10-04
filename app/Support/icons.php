<?php
/**
 * app/Support/icons.php — 统一内联 SVG 图标库
 * ============================================================
 * 供 PHP 模板（pvw_icon()）与前端（经 pvw_icons() 注入 PvIcons）共用，
 * 保证工具栏与右键菜单图标一致。统一 24 视图框、currentColor 描边。
 * ============================================================ */

/** 图标内部路径集合：name => SVG 内部标记 */
function pvw_icons() {
    return array(
        'sidebar'    => '<rect x="3" y="4" width="18" height="16" rx="2"/><line x1="9" y1="4" x2="9" y2="20"/>',
        'wl'         => '<circle cx="12" cy="12" r="8"/><path d="M12 4a8 8 0 0 1 0 16z" fill="currentColor" stroke="none"/>',
        'preset'     => '<line x1="4" y1="7" x2="20" y2="7"/><circle cx="9" cy="7" r="2.2"/><line x1="4" y1="12" x2="20" y2="12"/><circle cx="15" cy="12" r="2.2"/><line x1="4" y1="17" x2="20" y2="17"/><circle cx="11" cy="17" r="2.2"/>',
        'layout'     => '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="12" y1="3" x2="12" y2="21"/><line x1="3" y1="12" x2="21" y2="12"/>',
        'zoom'       => '<circle cx="11" cy="11" r="6.5"/><line x1="16" y1="16" x2="21" y2="21"/><line x1="11" y1="8.5" x2="11" y2="13.5"/><line x1="8.5" y1="11" x2="13.5" y2="11"/>',
        'pan'        => '<path d="M12 3v18M3 12h18"/><path d="M12 3l-2.2 2.2M12 3l2.2 2.2M12 21l-2.2-2.2M12 21l2.2-2.2M3 12l2.2-2.2M3 12l2.2 2.2M21 12l-2.2-2.2M21 12l-2.2 2.2"/>',
        'prev'       => '<polygon points="9,12 19,6 19,18" fill="currentColor" stroke="none"/><line x1="6" y1="6" x2="6" y2="18"/>',
        'next'       => '<polygon points="15,12 5,6 5,18" fill="currentColor" stroke="none"/><line x1="18" y1="6" x2="18" y2="18"/>',
        'fit'        => '<path d="M4 9V4h5M15 4h5v5M20 15v5h-5M9 20H4v-5"/>',
        'oneone'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><text x="12" y="15.5" text-anchor="middle" font-size="9" font-family="monospace" fill="currentColor" stroke="none">1:1</text>',
        'measure'    => '<rect x="2" y="7" width="20" height="10" rx="2"/><line x1="7" y1="7" x2="7" y2="11"/><line x1="12" y1="7" x2="12" y2="12"/><line x1="17" y1="7" x2="17" y2="11"/>',
        'transform'  => '<path d="M20 12a8 8 0 1 1-2.34-5.66"/><polyline points="18 4 18 9 13 9"/>',
        'tools'      => '<rect x="3" y="8" width="18" height="11" rx="2"/><path d="M9 8V6a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/><line x1="3" y1="13" x2="21" y2="13"/><line x1="10" y1="13" x2="10" y2="15"/><line x1="14" y1="13" x2="14" y2="15"/>',
        'about'      => '<circle cx="12" cy="12" r="9"/><line x1="12" y1="11" x2="12" y2="17"/><circle cx="12" cy="7.5" r="1" fill="currentColor" stroke="none"/>',
        'close'      => '<line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/>',
        // 测量
        'length'     => '<line x1="5" y1="18" x2="19" y2="6"/><circle cx="5" cy="18" r="1.8" fill="currentColor" stroke="none"/><circle cx="19" cy="6" r="1.8" fill="currentColor" stroke="none"/>',
        'angle'      => '<path d="M5 18h14M5 18L17 6"/><path d="M10.5 18a6 6 0 0 0-1.7-4.2"/>',
        'rect'       => '<rect x="4" y="6" width="16" height="12" rx="1"/>',
        'ellipse'    => '<ellipse cx="12" cy="12" rx="8" ry="6"/>',
        'clear'      => '<path d="M4 7h16"/><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/><path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12"/>',
        // 变换
        'rotate-ccw' => '<path d="M4 12a8 8 0 1 0 2.34-5.66"/><polyline points="4 4 4 9 9 9"/>',
        'rotate-cw'  => '<path d="M20 12a8 8 0 1 1-2.34-5.66"/><polyline points="20 4 20 9 15 9"/>',
        'flip-h'     => '<line x1="12" y1="3" x2="12" y2="21"/><path d="M9 7 4 12l5 5V7z"/><path d="M15 7l5 5-5 5V7z"/>',
        'flip-v'     => '<line x1="3" y1="12" x2="21" y2="12"/><path d="M7 9 12 4l5 5H7z"/><path d="M7 15l5 5 5-5H7z"/>',
        'invert'     => '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M6 4H4v16h2z" fill="currentColor" stroke="none"/><path d="M5 5h13a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H5z" fill="none" stroke="none"/><path d="M4 6v12a2 2 0 0 0 2 2h10z" fill="currentColor" stroke="none"/>',
        // 工具
        'dicom'      => '<rect x="4" y="3" width="16" height="18" rx="2"/><circle cx="12" cy="9" r="2.2"/><path d="M6 18l4-4 3 3 2-2 3 3"/>',
        'report'     => '<rect x="4" y="3" width="16" height="18" rx="2"/><line x1="8" y1="8" x2="16" y2="8"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="8" y1="16" x2="13" y2="16"/>',
        'search'     => '<circle cx="11" cy="11" r="6.5"/><line x1="16" y1="16" x2="21" y2="21"/>',
        'view-list'  => '<line x1="8" y1="6" x2="20" y2="6"/><line x1="8" y1="12" x2="20" y2="12"/><line x1="8" y1="18" x2="20" y2="18"/><circle cx="4.5" cy="6" r="1.2" fill="currentColor" stroke="none"/><circle cx="4.5" cy="12" r="1.2" fill="currentColor" stroke="none"/><circle cx="4.5" cy="18" r="1.2" fill="currentColor" stroke="none"/>',
        'view-table' => '<rect x="3" y="4" width="18" height="16" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="4" x2="9" y2="20"/><line x1="15" y1="4" x2="15" y2="20"/>',
        'view-card'  => '<rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="8" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/><rect x="13" y="13" width="8" height="8" rx="1.5"/>',
        'link'       => '<path d="M9 15l6-6"/><path d="M8.5 12 6.5 14a3 3 0 0 0 4 4l2-2"/><path d="M15.5 12l2-2a3 3 0 0 0-4-4l-2 2"/>',
        'save-image' => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="10" r="1.6"/><path d="M4 17l5-5 3 3 3-3 5 5"/>',
        'save-series'=> '<path d="M12 3v10M8.5 9.5 12 13l3.5-3.5"/><path d="M5 16v3a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3"/>',
        'lightbox'   => '<circle cx="12" cy="12" r="4.2"/><line x1="12" y1="2.5" x2="12" y2="5.5"/><line x1="12" y1="18.5" x2="12" y2="21.5"/><line x1="2.5" y1="12" x2="5.5" y2="12"/><line x1="18.5" y1="12" x2="21.5" y2="12"/><line x1="5.2" y1="5.2" x2="7.3" y2="7.3"/><line x1="16.7" y1="16.7" x2="18.8" y2="18.8"/><line x1="18.8" y1="5.2" x2="16.7" y2="7.3"/><line x1="7.3" y1="16.7" x2="5.2" y2="18.8"/>',
        // 通用 UI
        'chevron-down'  => '<polyline points="6 9 12 15 18 9"/>',
        'chevron-right' => '<polyline points="9 6 15 12 9 18"/>',
        'check'         => '<polyline points="20 6 9 17 4 12"/>',
        'cross'         => '<line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/>',
        'plus'          => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'lock'          => '<rect x="4" y="11" width="16" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
        'alert'         => '<path d="M12 3 2.6 20h18.8L12 3z"/><line x1="12" y1="9.5" x2="12" y2="14.5"/><circle cx="12" cy="17.4" r="1" fill="currentColor" stroke="none"/>',
        'import'        => '<path d="M12 3v12"/><polyline points="8 11 12 15 16 11"/><path d="M5 19h14"/>',
        'sample'        => '<path d="M9 3h6M10 3v6l-5 8.5A2 2 0 0 0 6.7 21h10.6a2 2 0 0 0 1.7-3.5L14 9V3"/><line x1="8.2" y1="15" x2="15.8" y2="15"/>',
        'keyboard'      => '<rect x="3" y="6.5" width="18" height="11" rx="2"/><circle cx="7" cy="10" r=".9" fill="currentColor" stroke="none"/><circle cx="11" cy="10" r=".9" fill="currentColor" stroke="none"/><circle cx="15" cy="10" r=".9" fill="currentColor" stroke="none"/><line x1="8" y1="14" x2="16" y2="14"/>',
        // 布局
        'layout-1'      => '<rect x="3" y="4" width="18" height="16" rx="2"/>',
        'layout-2h'     => '<rect x="3" y="4" width="18" height="16" rx="2"/><line x1="12" y1="4" x2="12" y2="20"/>',
        'layout-2v'     => '<rect x="3" y="4" width="18" height="16" rx="2"/><line x1="3" y1="12" x2="21" y2="12"/>',
        'layout-4'      => '<rect x="3" y="4" width="18" height="16" rx="2"/><line x1="12" y1="4" x2="12" y2="20"/><line x1="3" y1="12" x2="21" y2="12"/>',
        // 窗宽窗位预设
        'win-soft'      => '<rect x="4" y="5" width="16" height="14" rx="2"/><circle cx="12" cy="12" r="4" fill="currentColor" stroke="none"/>',
        'win-lung'      => '<path d="M12 4v9"/><path d="M12 8c-1.8-1.6-5-1.2-6 1.3S5 16 7.5 16.6 12 15 12 12"/><path d="M12 8c1.8-1.6 5-1.2 6 1.3S19 16 16.5 16.6 12 15 12 12"/>',
        'win-bone'      => '<path d="M8.5 15.5 15.5 8.5"/><circle cx="7" cy="17" r="2.2"/><circle cx="17" cy="7" r="2.2"/>',
        'win-full'      => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="10" r="1.6"/><path d="M4 17l5-5 3 3 3-3 5 5"/>',
    );
}

/** 输出完整 SVG 图标 */
function pvw_icon($name) {
    $icons = pvw_icons();
    $inner = isset($icons[$name]) ? $icons[$name] : '';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
}
