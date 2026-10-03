<?php
/**
 * app/Guest.php — 链接访客阅片令牌（无登录 / 无 Cookie / 无会话 / 无数据库写入）
 * ------------------------------------------------------------
 * 访客阅片采用「无状态签名令牌」：直链 `?r=viewer&uid=<UID>` 由服务端按 UID
 * 计算出签名 token，并随访客页下发；访客页后续的检查 / 报告 / 取像请求均携带
 * 该 token，服务端按 (uid, token) 校验是否放行。
 *
 * 与登录态的隔离性（铁律）：
 *   · 不写任何 Cookie（不设 PV_GUEST，不碰 PACSVIEWSID）；
 *   · 不读写 Session 身份；
 *   · 因此登录用户与访客互不影响：登录用户不会被误判为访客，
 *     访客也不可能劫持或清空登录状态。纯按请求内的 token 授权。
 *
 * 令牌密钥复用安装时生成的 mock_api_key（稳定且非用户身份）。
 */
class PvGuest {

    private static function secret() {
        $s = trim((string)PvSettings::get('mock_api_key', ''));
        return $s !== '' ? $s : 'pacs-viewer-guest';
    }

    /** 计算某个检查 UID 的访客签名令牌（截断 32 位十六进制） */
    public static function token($uid) {
        return substr(hash_hmac('sha256', 'guest|' . (string)$uid, self::secret()), 0, 32);
    }

    /** 校验 (uid, token) 是否为有效访客令牌（时序安全比较） */
    public static function verify($uid, $token) {
        $uid = trim((string)$uid);
        $token = (string)$token;
        if ($uid === '' || $token === '') return false;
        return hash_equals(self::token($uid), $token);
    }
}
