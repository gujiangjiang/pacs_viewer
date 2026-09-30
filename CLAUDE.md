# CLAUDE.md

本项目（模拟 Web PACS 影像浏览器）的全部开发约定统一维护在根目录
**[AGENTS.md](AGENTS.md)**，开始任何开发前请先完整阅读并严格遵守。

## 版本与文档索引

- 版本号唯一来源：`app/bootstrap.php` 的 `PV_VERSION`（README 徽章 / `docs/CHANGELOG.md` 同步）。
- 版本独立计算（自 `0.1.0` 起），与任何宿主系统隔离。
- 变更日志：**`docs/CHANGELOG.md`**；使用帮助：**`docs/HELP.md`**。

## 常用命令（Commands）

### 本地运行（开发服务器易断，统一用守护脚本）
```bash
tools/serve.sh ensure     # 启动 / 健康检查，挂了自动重启（任务收尾前必跑）
tools/serve.sh status     # 查看 up / down
# http://localhost:8090/   首次访问进入安装向导，创建管理员账号
```

### 语法检查（基于 tokenizer，扫描 app/public/views）
```bash
~/.local/bin/frankenphp php-cli tools/lint.php
```

### 与宿主项目同步（git subtree，在宿主项目目录执行）
```bash
git subtree pull --prefix=tools/pacs_viewer \
  https://github.com/gujiangjiang/pacs_viewer main
```

## 重点速记

- 架构：`public/` 为 Web 根与唯一入口；`app/` 后端按职责拆分；`views/` 模板；
  `public/assets/js/modules/` 前端功能模块；`data/` 运行时数据禁止提交。
- **严禁硬编码路径前缀**：链接统一用 `pvw_url()` / `pvw_asset()`，兼容独立部署
  与子目录挂载（宿主 `tools/pacs_viewer/`）。
- 修改流程：先在本仓库提交推送，再到宿主项目 `git subtree pull`；
  **不要在宿主内直接编辑 `tools/pacs_viewer/` 源码**。
- 每次修改：同步 `PV_VERSION` / `docs/CHANGELOG.md` / README，并按 AGENTS.md
  分步提交（Conventional Commits 标题 + 空行 + 逐条正文）。
- 约束：不新增第三方依赖，严格保持 PHP 7.x 兼容；写操作必须校验 CSRF。
