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
 * 令牌密钥使用**独立的 `guest_secret`**（安装 / 首次运行播种时随机生成，不对外
 * 展示、不下发），不再与对外公开的模拟服务器密钥（`mock_api_key`）同源。
 * 为兼容历史版本已发出的直链，校验时先按新密钥比对，再回退旧密钥（mock_api_key）
 * 比对；兼容期可后续版本移除。
 */
class PvGuest {

    /** 当前签名密钥（guest_secret；极端情况下未播种时退化为固定串） */
    private static function secret() {
        $s = trim((string)PvSettings::get('guest_secret', ''));
        return $s !== '' ? $s : 'pacs-viewer-guest';
    }

    /** 历史版本使用的密钥（曾复用对外公开的模拟服务器密钥），仅用于兼容既有直链 */
    private static function legacySecret() {
        $s = trim((string)PvSettings::get('mock_api_key', ''));
        return $s !== '' ? $s : 'pacs-viewer-guest';
    }

    /** 按指定密钥对检查 UID 签名（截断 32 位十六进制） */
    private static function sign($uid, $secret) {
        return substr(hash_hmac('sha256', 'guest|' . (string)$uid, $secret), 0, 32);
    }

    /** 计算某个检查 UID 的访客签名令牌（使用独立 guest_secret） */
    public static function token($uid) {
        return self::sign($uid, self::secret());
    }

    /** 校验 (uid, token)：新密钥优先，兼容旧密钥（时序安全比较） */
    public static function verify($uid, $token) {
        $uid = trim((string)$uid);
        $token = (string)$token;
        if ($uid === '' || $token === '') return false;
        if (hash_equals(self::token($uid), $token)) return true;
        return hash_equals(self::sign($uid, self::legacySecret()), $token);
    }
}
