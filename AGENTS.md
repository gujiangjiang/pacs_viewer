# AGENTS.md — 项目约定（给 AI 协作的维护指南）

本文件约束本仓库（模拟 Web PACS 影像浏览器）后续每次代码更新 / 修改，
请严格遵守。

## 项目定位

- 一个**完全独立**的轻量级 PHP 网站，用于 DICOM / PACS 接口联调测试。
- 技术栈：**PHP 7.x + SQLite + 原生 JS/CSS**，**无 Composer、无第三方依赖**。
- 不读取 / 不写入任何宿主系统（如门诊一体化）的文件、数据库或配置；
  所有数据通过 PACS 接口或本仓库自带 SQLite 获取。

## 版本标识

- 唯一版本来源：`app/bootstrap.php` 的 `PV_VERSION`；`README.md` 顶部徽章、
  `docs/CHANGELOG.md` 小节三处保持一致。
- 版本号**独立计算**，从 `0.1.0` 起，与任何宿主系统完全隔离。

## 本地运行与检查（本机 macOS arm64，无系统 php）

- 运行 / 守护（Web 根指向 `public/`，端口默认 8090）：

  ```bash
  tools/serve.sh ensure     # 挂了就自动重启（任务收尾前必跑）
  tools/serve.sh status     # up / down
  tools/serve.sh restart    # 强制重启
  ```

  等价于手工命令（如需前台观察日志）：

  ```bash
  ~/.local/bin/frankenphp php-server --root public --listen 0.0.0.0:8090
  ```

- PHP 语法检查（基于 tokenizer，扫描 `app/` `public/` `views/`）：

  ```bash
  ~/.local/bin/frankenphp php-cli tools/lint.php
  ```

- 首次访问自动建库 `data/pacs_viewer.db`（含默认账号与设置）。

## 开发服务器守护（重要，AI 每次收尾前必做）

- 该 frankenphp 开发服务器**非常容易因会话结束 / 端口冲突 / 误杀而断开**。
  这不是代码缺陷：本地起服务器、跑测试后若未正确脱离终端，进程会随调用结束
  被回收，表现为「PHP 服务器又挂了」。
- **铁律**：每完成一个任务 / 每次 `git commit` 前，先执行

  ```bash
  tools/serve.sh ensure
  ```

  若输出 `up` 则继续；若 `down` 则脚本会自动重启并等待可用；只有确认服务器
  可用后才算任务完成。
- 启动一律走 `tools/serve.sh`（内部使用 `nohup + 日志 data/.serve.log + pid
  data/.serve.pid`），**不要**再手工 `--listen` 起临时实例，避免端口 / 实例冲突。
- 需要临时端口验证时可 `PV_PORT=8099 tools/serve.sh ensure`，用完 `stop`。

## 自测数据保护（铁律）

- **严禁覆盖 `data/` 下的运行数据**：尤其是 `pacs_viewer.db` 中的 `settings`
  （`pacs_endpoint` / `pacs_api_key` / `pacs_protocol` 等为使用者的真实配置）。
- 自测如需临时改设置，**必须先读取原值、测试后原样写回**；不得写死默认值恢复。
- 优先用「临时覆盖」机制（如 `PingWith` 的 override）或独立测试库，避免触碰线上设置。

## 架构与目录约定

- `public/` 为 Web 根：`public/index.php` 是**唯一前端控制器**（`?r=` 路由），
  `public/assets/{css,js}` 为前端资源；禁止把入口或资源放到别处。
- `app/` 后端按职责拆分：`Pacs/`（远程接口 + 内置模拟）、`Controllers/`、
  `Services/`、`Repositories/`，各单文件保持小、职责单一。
- `views/` 页面模板；`views/partials/` 公共页头页脚。
- `public/assets/js/modules/` 前端按功能拆分：`render`（虚拟影像）、`osd`（水印）、
  `sidebar`（序列栏）、`toolbar`（工具栏）、`measurements`（测量）；
  `viewer.js` 仅做装配与画布管线编排。
- 前端静态资源清单统一由 `app/Support/Assets.php`（`PvAssets`）维护：视图额外
  CSS/JS、公共资源与 Service Worker 预缓存清单均取自此。**新增前端模块须在此登记**，
  禁止在视图或 `PwaController` 另维护列表（顺序敏感：基础模块先于其扩展文件）。
- `data/` 为运行时数据（SQLite + 会话），**禁止提交**（已 gitignore）。

## 部署路径自适应

- 本仓库为**完全独立项目**（Web 根 = `public/`），不通过 git subtree 挂载到任何宿主仓库，
  也与任何宿主系统零耦合（独立代码库、数据库、账号与文档）。
- 页面 / 接口 / 静态资源链接一律使用助手函数：`pvw_url()` / `pvw_asset()`，
  **严禁硬编码任何路径前缀**；静态资源统一放 `public/assets`，由引导计算 `PV_URL_ASSET` 输出。
  即便被外部以 iframe 嵌入或反向代理到子目录，链接也能自适应。

## 接口约定

- 远程 PACS 接口使用 `{action,q,uid,key}` 查询参数，返回 `{code,msg,data}`；
  详见 `docs/HELP.md`「远程 PACS 接口约定」。新增接口动作时同步更新文档。

## 安全约定

- 登录使用 `password_hash` / `password_verify`；会话名独立（`PACSVIEWSID`），
  会话文件存放于本项目 `data/session/`。
- 所有写操作（POST）必须校验 CSRF 令牌（`pvw_csrf()` / `pvw_csrf_check()`）。
- 输出一律经 `pvw_e()` 转义防 XSS；数据库访问一律 PDO 预处理防注入。

## 每次修改必须执行的自动化步骤

1. **同步版本与文档**：
   - 有功能变化时递增 `PV_VERSION`（与 README 徽章、`docs/CHANGELOG.md` 同步）。
   - 在 `docs/CHANGELOG.md` 顶部按既有格式新增条目
     （新增 / 修复 / 变更 / 移除 / 安全），日期用当天日期；
     纯文档改动可在现有版本小节补充「文档」条目，不必单独升版本。
   - 目录 / 运行方式变化需同步更新 `README.md`。
2. **分步提交**：
   - 接到任务先拆分（用 todo 跟踪），逐个完成；**一处修改 = 一次提交**，
     便于溯源与调试。
   - 提交信息（Conventional Commits）：`<type>: <中文简述>`，type 常用
     `feat` / `fix` / `docs` / `style` / `refactor` / `perf` / `chore`；
     标题下空一行，附逐条正文：
     `git commit -m "<标题>" -m "<正文>"`。
3. **提交前自检**：`~/.local/bin/frankenphp php-cli tools/lint.php`；
   `tools/serve.sh ensure`（**确认开发服务器存活，挂了自动重启**）；
   `git status` 确认未把 `data/` 运行时数据纳入提交。

## 其他约定

- 严格保持 **PHP 7.x 兼容**（不使用 PHP 8 专有语法）；不新增第三方依赖。
- 全中文注释；单文件小、职责单一；接口与页面分离。
- 前端为原生 JS（无框架），模块通过全局命名空间 `Pv*` 暴露，
  保持暗色医学 UI 风格一致。
