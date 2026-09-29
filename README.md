# Web PACS 影像浏览器

![版本](https://img.shields.io/badge/版本-v0.14.2-blue) ![PHP](https://img.shields.io/badge/PHP-7.x-777BB4) ![数据库](https://img.shields.io/badge/数据库-SQLite-003B57) ![依赖](https://img.shields.io/badge/依赖-无第三方-brightgreen)

> 一个**完全独立**的轻量级 PHP 网站，用于 DICOM / PACS 接口联调测试。
> 拥有自己的代码库、数据库、账号与文档体系，与任何宿主系统零耦合。

## 简介

本项目用于在**没有真实 PACS 硬件**的环境下，验证 DICOM / PACS 接口的检索、
调阅与影像展示链路。**本浏览器自身不含数据**：所有患者、检查、医院名称等数据
均通过所配置的远程 PACS / DICOMWeb 接口获取。

为便于联调，项目内置了一个**模拟 PACS 服务器**（见「模拟服务器」页）：它通过
对外 API 提供标准 PACS 接口，使用内置确定性仿真患者数据；影像由内置模拟服务器按
标准 DICOM（WADO-URI）生成，用于验证完整阅片链路。对外地址与密钥可**一键填入**
【外部接口】。真实部署的 DICOM/PACS 网关为必填，FHIR R4 可选作患者信息补充。

部署形态为标准 PHP 网站：Web 根指向本仓库的 `public/`，入口为 `public/index.php`。

## 功能

- **首次运行安装向导**：首次访问创建管理员账号（不再硬编码默认账号）；
  安装管理员受保护，不可删除 / 停用，其余账号可在账号管理中管理。
- **全站 AJAX 局部刷新**：站内导航不整页重载、**地址栏保持不变**；外部直接链接
  （如阅片器 `?r=viewer&uid=...`）仍可整页进入，阅片器可一键复制直链。
- **PWA / 离线**：可「安装到桌面 / 主屏幕」，Service Worker 静态资源缓存优先 +
  后台更新、页面离线回退缓存、接口实时直连；图标由**代码绘制**（无预置图片），
  管理员可上传自定义图标。
- **通用上传 / 鉴权下载**（基础设施）：文件存于 Web 根之外的 `data/uploads/`，
  经 `?r=file` 鉴权下发；类型白名单 + 随机令牌 + 防路径穿越，供后续功能复用。
- **模态框交互**：创建 / 编辑用户、重置密码、删除确认、日志清空等均为模态框。
- **研究检索**：按姓名 / 患者号 / 检查号 / 门诊号 / 检查项目检索（数据来自 PACS 接口）。
- **影像视图工作区**（独立 Tab）：可同时打开多个检查（左栏分组展示、可折叠 /
  单独关闭 / 展开多个），会话内记忆已打开的检查与序列位置；支持追加或清空打开、
  已打开检查自动定位，超过管理员设置的序列上限自动移除最早检查；底部「关闭全部」
  一键清空并显示空态占位。
- **分栏视图**：工具栏「布局」支持 单视图 / 左右双视图 / 上下双视图 / 四视图；
  每个窗格是独立视窗（含 OSD、帧滚动条、右键菜单、测量），点击窗格激活（绿色
  描边），工具栏操作作用于激活窗格；点击左侧序列即载入激活窗格，便于对比阅片。
- **可调节序列栏**：拖动序列栏与视图之间的分隔条调整宽度（双击复位，单次登录内记忆）。
- **影像阅片器**：
  - 商用 PACS 风格**大图标工具栏** + 下拉菜单（预设窗 / 测量 / 变换 / 工具）；
  - **自定义右键快捷菜单**（预设窗 / 缩放 / 平移 / 使用窗口 / 原图 / 测量 / 变换，含二级菜单）；
  - 鼠标处 **CT 值（HU）** 实时显示；
  - 窗宽窗位（WW/WL）拖拽调节 + 预设（软组织窗 / 肺窗 / 骨窗 / 默认窗）；
  - 左侧栏按检查分组、可展开 / 收起序列；**右侧帧滚动条**多帧连续滚动；
  - 滚轮连续翻帧（CT/MR Cine）、以指针为中心缩放、平移；
  - 顺时针 / 逆时针旋转 90°、水平 / 垂直镜像、正负片反色；
  - 线段测距（mm）、三点测角（°）、矩形 / 椭圆 ROI（mm² + 平均灰度）；
  - 四角医学水印 OSD、**DICOM 详情**模态框；
  - **保存当前图像**（PNG）与**保存序列**（多帧打包 ZIP）。
- **内置模拟 PACS 服务器**（【管理设置 → 模拟服务器】子 Tab）：对外 API
  （`search` / `study` / `ping`）供本浏览器或门诊系统调用；使用内置仿真患者数据；
  可预览患者、一键应用模拟数据、重新生成密钥、整体启停。
- **管理设置**：站点 / 医院信息、外部接口配置、接口连通性测试、
  账号管理（模态框）、检索日志、影像视图序列上限（3-10）、模拟服务器、存储情况。
- **操作日志**：记录账号、操作（搜索 / 读片 / 下载 / 阅读 DICOM）、详情
  （患者姓名 + 检查类型）与 IP，便于溯源。
- 无真实 PACS 时由内置模拟服务器生成多模态仿真 DICOM（颅脑 / 胸部 / 腰椎 / 腹部 / 膝等，多帧连续切片）。

## 目录结构

```
.
├── README.md                 # 项目说明（本文件）
├── LICENSE                   # 开源许可
├── AGENTS.md / CLAUDE.md      # 开发约定（AI / 协作者维护指南）
├── index.php                 # 目录默认入口（以仓库根为站点时命中）
├── public/                   # Web 根（部署时 Web 服务器指向这里）
│   ├── index.php             #   唯一前端控制器（?r= 路由；含 AJAX 片段响应）
│   └── assets/
│       ├── css/              #   base / ui / auth / search / viewer / admin / mock
│       └── js/
│           ├── api.js        #   外部接口请求封装
│           ├── ui.js         #   PvModal 模态框 / PvUI 轻提示 / AJAX 表单
│           ├── spa.js        #   站内 AJAX 局部刷新导航（地址栏保持不变）
│           ├── pwa.js        #   Service Worker 注册
│           ├── search.js     #   检索页交互
│           ├── admin.js      #   管理页交互（模态框账号管理）
│           ├── mock.js       #   模拟服务器页交互
│           ├── viewer.js     #   阅片器主控制器
│           └── modules/      #   dicom(DICOM 解码) / render(像素窗宽窗位) / osd(水印) / sidebar(序列栏)
│                             #   / toolbar(工具栏) / measurements(测量) / zip(导出打包)
├── app/                      # 后端
│   ├── bootstrap.php         #   引导（部署路径自适应 / 会话 / AJAX 助手 / 布局）
│   ├── Database.php          #   自带 SQLite（连接 / 迁移 / 播种设置）
│   ├── Database/schema/      #   按编号拆分的数据表迁移文件（001_core / 002_user …）
│   ├── Auth.php              #   独立登录认证
│   ├── Settings.php          #   管理设置读写
│   ├── Pacs/                 #   PacsClient(远程接口) + DemoPacs(内置仿真)
│   │                         #   + FhirClient(门诊 FHIR) + MockServer(模拟服务器)
│   ├── Services/             #   StudyService(检查聚合) / IconRenderer(代码绘制图标) / UploadStore(上传存储)
│   ├── Controllers/          #   认证 / 安装 / 检索 / 阅片 / 管理 / 模拟服务器 / PWA / 上传 / JSON 接口
│   └── Repositories/         #   账号 / 检索日志
├── views/                    # 页面模板（auth / install / search / viewer / admin / mock / error）
├── tools/                    # 工具（lint.php 语法检查）
├── docs/                     # 详细文档（CHANGELOG / HELP）
└── data/                     # 运行时：pacs_viewer.db + session + uploads（自动生成，不提交）
```

## 启动

本机无系统 PHP，统一使用 FrankenPHP。Web 根指向 `public/`：

```bash
~/.local/bin/frankenphp php-server --root public --listen 0.0.0.0:8090
# 浏览器访问 http://localhost:8090/
```

首次访问自动创建 `data/pacs_viewer.db`，并进入**首次运行安装向导**：创建管理员
账号并填写站点信息后即可登录使用（不再有硬编码默认账号）。

> 入口已做部署路径自适应：若以仓库根或子目录方式挂载（如主项目的
> `tools/pacs_viewer/`），页面 / 接口 / 静态资源链接会自动适配，无需改代码。

## 首次运行

1. 打开站点首页，自动跳转至「首次运行安装」向导；
2. 填写站点 / 医院信息与管理员用户名、密码，提交后自动登录；
3. 该安装管理员**不可删除、不可停用**；其余用户可在【管理设置 → 账号管理】新增与管理。

## 内置模拟 PACS 服务器

本浏览器自身不含数据。若暂无真实 PACS，可在【模拟服务器】页：

1. 查看 / 复制**对外 API 地址**与**接口密钥**（对外接口：`{地址}&action=search|study|ping&key=密钥`）；
2. （内置模拟服务器固定使用内置仿真患者数据）
3. 点击【一键应用模拟服务器数据】，自动把地址与密钥填入【外部接口】；
4. 回到【研究检索】即可检索并阅片（影像由内置模拟服务器按标准 DICOM 生成）。

对外 API 也可提供给门诊系统等其他系统调用（需携带 `key`）。

## PACS 接口约定

在【管理设置 → 外部接口】配置接口地址后，检索与调阅数据全部来自该地址：

```
GET {endpoint}?action=search&q=关键词&key=APIKEY
    → {"code":200,"data":{"list":[{study_uid,patient_id,name,gender,age,
        outpatient_no,accession_no,modality,description,study_date,
        institution,station_name,series_count}, ...]}}

GET {endpoint}?action=study&uid=STUDY_UID&key=APIKEY
    → {"code":200,"data":{patient:{...}, study:{...}, series:[...]}}

GET {endpoint}?action=ping&key=APIKEY
    → {"code":200,"data":{name,version}}
```

管理页【测试接口】按钮即调用 `ping`。可指向内置模拟服务器对外 API，或真实 PACS 网关。

## 通用上传与鉴权下载

供后续功能（如影像 / 资料 / 图标）复用的基础设施：

```
POST {站点}?r=upload            （需登录 + CSRF）字段：file、category(可选)
     → {"code":200,"data":{"token","name","mime","size","url","download_url"}}

GET  {站点}?r=file&t=令牌[&download=1]   （需登录）图片内联预览，其余强制下载

POST {站点}?r=upload/delete      （需登录 + CSRF）字段：t；管理员或上传者本人
```

- 文件存于 Web 根之外的 `data/uploads/<category>/`，**不可通过 URL 直连**；
- 类型白名单：PNG / JPEG / GIF / WebP / PDF / 纯文本；单文件上限 20MB；
- 落盘文件名为随机令牌，记录写入 `uploads` 表，删除按记录清理，防路径穿越。

服务端：`PvUploadStore::save()/find()/path()/deleteByToken()`、助手 `pvw_file_url($token)`；
前端：`PvUI.upload(route, file, fields)`。

## 与门诊一体化主项目集成（git subtree）

本项目为**源仓库**；门诊一体化主项目（Clinic_OPD_System）通过 `git subtree`
把它挂载在 `tools/pacs_viewer/`，主项目内不含独立实现。集成不影响本仓库
以原生形态独立部署。

更新流程（在本仓库提交推送后，到主项目目录执行同步）：

```bash
# 1) 本仓库：改完即提交并推送
git push origin main

# 2) 主项目：拉取本仓库最新内容到挂载点
git subtree pull --prefix=tools/pacs_viewer \
  https://github.com/gujiangjiang/pacs_viewer main
```

## 更多文档

- [更新日志 CHANGELOG](./docs/CHANGELOG.md)
- [使用帮助 HELP](./docs/HELP.md)
