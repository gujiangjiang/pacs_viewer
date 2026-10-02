<?php
/** app/Controllers/ViewerController.php — 阅片页 */
class PvViewerController {

    /** 是否「阅片直链」（?r=viewer&uid=... 且不带 mode，用于与登录用户的检索打开区分） */
    public static function isLink() {
        $uid = trim((string)pvw_input('uid'));
        $mode = (string)pvw_input('mode');
        return $uid !== '' && $mode === '';
    }

    public static function show() {
        $uid = trim((string)pvw_input('uid'));
        // 访客阅片：直链请求，或已持有有效访客令牌（被拦截重定向而来）
        $guest = self::isLink() || PvGuest::active();
        if ($guest) {
            if ($uid === '') $uid = (string)PvGuest::uid();   // 重定向而来：复用令牌中的检查
            if ($uid !== '' && !PvAuth::check()) PvGuest::issue($uid);   // 登录用户不签发访客令牌，避免残留
            pvw_page('viewer', array(
                'user'    => null,
                'guest'   => true,
                'uid'     => $uid,
                'mode'    => 'replace',
                'site'    => PvSettings::get('site_title', 'PACS 影像浏览器'),
                'isAdmin' => false,
            ));
            return;
        }
        PvAuth::requireLogin();
        $mode = (string)pvw_input('mode') === 'replace' ? 'replace' : 'append';
        pvw_page('viewer', array(
            'user' => PvAuth::user(),
            'guest' => false,
            'uid'  => $uid,
            'mode' => $mode,
            'site' => PvSettings::get('site_title', 'PACS 影像浏览器'),
            'isAdmin' => PvAuth::isAdmin(),
        ));
    }
}
