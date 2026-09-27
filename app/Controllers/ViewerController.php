<?php
/** app/Controllers/ViewerController.php — 阅片页 */
class PvViewerController {

    public static function show() {
        PvAuth::requireLogin();
        $uid = (string)pvw_input('uid');
        $mode = (string)pvw_input('mode') === 'replace' ? 'replace' : 'append';
        pvw_page('viewer', array(
            'user' => PvAuth::user(),
            'uid'  => $uid,
            'mode' => $mode,
            'site' => PvSettings::get('site_title', 'PACS 影像浏览器'),
            'isAdmin' => PvAuth::isAdmin(),
        ));
    }
}
