<?php
/** app/Controllers/SearchController.php — 检索主页 */
class PvSearchController {

    public static function index() {
        PvAuth::requireLogin();
        $u = PvAuth::user();
        $flash = isset($_SESSION['pv_flash']) ? $_SESSION['pv_flash'] : '';
        unset($_SESSION['pv_flash']);
        pvw_page('search', array(
            'user'    => $u,
            'flash'   => $flash,
            'site'    => PvSettings::get('site_title', '模拟 PACS 影像浏览器'),
            'hospital'=> PvSettings::get('hospital_name', ''),
            'mode'    => PvPacsClient::mode(),
            'isAdmin' => PvAuth::isAdmin(),
        ));
    }
}
