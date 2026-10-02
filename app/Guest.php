<?php
/**
 * app/Guest.php — 链接访客阅片令牌（无登录、无数据库写入、无用户关联）
 * ------------------------------------------------------------
 * 通过「阅片直链」（?r=viewer&uid=...）访问时，服务端签发一枚 HMAC 令牌
 * （仅绑定所阅检查 UID），以独立 Cookie（PV_GUEST）承载：
 *   · 不复用登录会话（PACSVIEWSID），不影响同时段登录用户的 Cookie / 会话；
 *   · 不写入任何数据库，令牌只做只读范围校验；
 *   · 访客仅能读取该 UID 对应的检查 / 序列 / 实例，无法枚举其它检查。
 * 令牌密钥复用安装时生成的 mock_api_key（稳定且非用户身份）。
 */
class PvGuest {

    const COOKIE = 'PV_GUEST';

    private static function secret() {
        $s = trim((string)PvSettings::get('mock_api_key', ''));
        return $s !== '' ? $s : 'pacs-viewer-guest';
    }

    /** 计算某个检查 UID 的访客令牌（截断为 32 位十六进制） */
    public static function token($uid) {
        return substr(hash_hmac('sha256', 'guest|' . (string)$uid, self::secret()), 0, 32);
    }

    /** 签发访客令牌 Cookie（独立 Cookie，HttpOnly，不关联用户会话） */
    public static function issue($uid) {
        $uid = trim((string)$uid);
        if ($uid === '') return;
        $val = rawurlencode($uid) . ':' . self::token($uid);
        $path = PV_URL_SITE === '' ? '/' : PV_URL_SITE . '/';
        if (!headers_sent()) @setcookie(self::COOKIE, $val, 0, $path, '', false, true);
    }

    /** 清除访客令牌（用于切换到正常登录等场景） */
    public static function clear() {
        $path = PV_URL_SITE === '' ? '/' : PV_URL_SITE . '/';
        if (!headers_sent()) @setcookie(self::COOKIE, '', time() - 3600, $path, '', false, true);
    }

    /** 解析并校验访客令牌，返回允许访问的检查 UID；无效返回 null */
    public static function uid() {
        if (empty($_COOKIE[self::COOKIE])) return null;
        $raw = (string)$_COOKIE[self::COOKIE];
        $p = strpos($raw, ':');
        if ($p === false) return null;
        $uid = rawurldecode(substr($raw, 0, $p));
        $tok = substr($raw, $p + 1);
        if ($uid === '' || $tok === '') return null;
        return hash_equals(self::token($uid), $tok) ? $uid : null;
    }

    /** 当前请求是否处于访客阅片上下文（未登录且令牌有效） */
    public static function active() {
        return !PvAuth::check() && self::uid() !== null;
    }
}
