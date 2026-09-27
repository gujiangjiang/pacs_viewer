<?php
/**
 * app/Support/icons.php — 统一内联 SVG 图标库
 * ============================================================
 * 供 PHP 模板（pvw_icon()）与前端（经 pvw_icons() 注入 PvIcons）共用，
 * 保证工具栏与右键菜单图标一致。统一 24 视图框、currentColor 描边。
 * 预设窗（软组织/肺/骨/默认）暂用 emoji，不在此列。
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
        'link'       => '<path d="M9 15l6-6"/><path d="M8.5 12 6.5 14a3 3 0 0 0 4 4l2-2"/><path d="M15.5 12l2-2a3 3 0 0 0-4-4l-2 2"/>',
        'save-image' => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="10" r="1.6"/><path d="M4 17l5-5 3 3 3-3 5 5"/>',
        'save-series'=> '<path d="M12 3v10M8.5 9.5 12 13l3.5-3.5"/><path d="M5 16v3a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3"/>',
    );
}

/** 输出完整 SVG 图标 */
function pvw_icon($name) {
    $icons = pvw_icons();
    $inner = isset($icons[$name]) ? $icons[$name] : '';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
}
