<?php
/** app/Repositories/UserRepository.php — 本工具账号仓库 */
class PvUserRepository {

    public static function all() {
        return PvDatabase::q("SELECT id,username,display_name,role,status,is_owner,created_at FROM users ORDER BY id ASC");
    }
    public static function find($id) {
        return PvDatabase::one("SELECT * FROM users WHERE id=?", array((int)$id));
    }
    public static function findByUsername($username) {
        return PvDatabase::one("SELECT * FROM users WHERE username=? LIMIT 1", array((string)$username));
    }

    /**
     * 创建账号。
     * @param bool $owner 是否为首个「安装管理员」（受保护：不可删除 / 停用）
     */
    public static function create($username, $password, $displayName, $role, $owner = false) {
        $username = trim((string)$username);
        if ($username === '' || (string)$password === '') throw new RuntimeException('用户名与密码不能为空');
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{1,31}$/', $username)) {
            throw new RuntimeException('用户名须以字母开头，仅含字母 / 数字 / 下划线（2-32 位）');
        }
        if (mb_strlen((string)$password) < 6) throw new RuntimeException('密码长度至少 6 位');
        if (self::findByUsername($username)) throw new RuntimeException('用户名已存在');
        $role = $role === 'admin' ? 'admin' : 'user';
        return PvDatabase::insert(
            "INSERT INTO users(username,password_hash,display_name,role,status,is_owner,created_at) VALUES(?,?,?,?,1,?,?)",
            array($username, password_hash($password, PASSWORD_DEFAULT), (string)$displayName, $role, $owner ? 1 : 0, date('Y-m-d H:i:s'))
        );
    }

    /** 创建首个安装管理员（owner） */
    public static function createOwner($username, $password, $displayName) {
        return self::create($username, $password, $displayName, 'admin', true);
    }

    public static function setStatus($id, $status) {
        $u = self::find($id);
        if (!$u) throw new RuntimeException('账号不存在');
        if ((int)$u['is_owner'] === 1 && !$status) throw new RuntimeException('安装管理员不可停用');
        return PvDatabase::exec("UPDATE users SET status=? WHERE id=?", array($status ? 1 : 0, (int)$id));
    }

    public static function setPassword($id, $password) {
        if (strlen((string)$password) < 6) throw new RuntimeException('密码长度至少 6 位');
        $u = self::find($id);
        if (!$u) throw new RuntimeException('账号不存在');
        return PvDatabase::exec("UPDATE users SET password_hash=? WHERE id=?", array(password_hash($password, PASSWORD_DEFAULT), (int)$id));
    }

    public static function updateProfile($id, $displayName, $role) {
        $u = self::find($id);
        if (!$u) throw new RuntimeException('账号不存在');
        $isOwner = (int)$u['is_owner'] === 1;
        // owner 必须保持管理员角色，避免安装管理员被降权后无人可管
        $role = $isOwner ? 'admin' : ($role === 'admin' ? 'admin' : 'user');
        return PvDatabase::exec("UPDATE users SET display_name=?, role=? WHERE id=?", array((string)$displayName, $role, (int)$id));
    }

    /** 删除账号（owner / 最后一个管理员 / 自身 均受保护，由控制器兜底） */
    public static function delete($id) {
        $u = self::find($id);
        if (!$u) throw new RuntimeException('账号不存在');
        if ((int)$u['is_owner'] === 1) throw new RuntimeException('安装管理员不可删除');
        return PvDatabase::exec("DELETE FROM users WHERE id=?", array((int)$id));
    }

    public static function countAdmins() {
        return (int)PvDatabase::val("SELECT COUNT(*) FROM users WHERE role='admin' AND status=1");
    }

    /** 保存用户偏好：打开影像时是否清空已加载序列 */
    public static function setClearOnOpen($id, $value) {
        return PvDatabase::exec("UPDATE users SET clear_on_open=? WHERE id=?", array($value ? 1 : 0, (int)$id));
    }

    /** 检索视图偏好：table / list / card（默认 table） */
    public static function setSearchView($id, $value) {
        $value = in_array($value, array('table', 'list', 'card'), true) ? $value : 'table';
        return PvDatabase::exec("UPDATE users SET search_view=? WHERE id=?", array($value, (int)$id));
    }

    public static function countAll() {
        return (int)PvDatabase::val("SELECT COUNT(*) FROM users");
    }
}
