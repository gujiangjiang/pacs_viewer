<?php
/** app/Controllers/ViewerController.php — 阅片页 */
class PvViewerController {

    public static function show() {
        PvAuth::requireLogin();
        $uid = (string)pvw_input('uid');
        pvw_page('viewer', array(
            'user' => PvAuth::user(),
            'uid'  => $uid,
            'site' => PvSettings::get('site_title', '模拟 PACS 影像浏览器'),
            'isAdmin' => PvAuth::isAdmin(),
        ));
    }
}
