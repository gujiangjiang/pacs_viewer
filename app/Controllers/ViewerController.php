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
        // 访客阅片：仅当「阅片直链」请求（uid + 无 mode）。无 Cookie / 无会话，
        // 仅向页面下发按 UID 计算的签名令牌，供后续 API / 取像按请求校验。
        if (self::isLink()) {
            pvw_page('viewer', array(
                'user'       => null,
                'guest'      => true,
                'uid'        => $uid,
                'mode'       => 'replace',
                'guestToken' => PvGuest::token($uid),
                'site'       => PvSettings::get('site_title', 'PACS 影像浏览器'),
                'isAdmin'    => false,
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
            'guestToken' => '',
            'site' => PvSettings::get('site_title', 'PACS 影像浏览器'),
            'isAdmin' => PvAuth::isAdmin(),
        ));
    }
}
