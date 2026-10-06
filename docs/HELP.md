# 📗 使用帮助（HELP）

Web PACS 影像浏览器 · 独立 PHP 网站

## 一、快速开始

1. **启动服务**（本机使用 FrankenPHP，Web 根指向 `public/`；推荐守护脚本）：

   ```bash
   tools/serve.sh ensure   # 启动 / 健康检查，挂了自动重启
   # 等价手工前台启动（如需观察日志）：
   # ~/.local/bin/frankenphp php-server --root public --listen 0.0.0.0:8090
   ```

2. **打开首页**：`http://localhost:8090/`，会自动进入**首次运行安装向导**。
3. **创建管理员**：填写站点 / 医院信息与管理员用户名、密码，提交后自动登录
   （不再有硬编码默认账号）。
4. **配置数据来源**：真实 PACS 请在【管理设置 → 外部接口】填写地址；
   本地联调可去【模拟服务器】一键应用模拟数据。
5. **检索 / 调阅**：在【研究检索】输入姓名 / 患者号 / 检查号 / 门诊号 / 检查项目，
   点击结果卡片即进入【影像】视图阅片；顶部「影像」Tab 可随时切回已打开的检查。

> 首次访问会在 `data/` 自动生成 `pacs_viewer.db` 与 `session/`，均为运行时数据，
> 不纳入版本管理。

> **导航说明**：站内页面切换为 **AJAX 局部刷新（地址栏保持不变）**；外部直接链接
> （如 `?r=viewer&uid=...`）仍可整页进入；阅片器可用【复制直链】获取可分享地址。

## 二、管理设置（外部接口等）

管理员登录后进入【管理设置】，分为六个页签：**基础设置 / 外部接口 /
账号管理 / 日志查询 / 模拟服务器 / 存储情况**。

### 日志查询

左栏分为三个通道，各自独立维护「条数 / 天数」上限，支持**实时刷新 / 一键清空**：
- **操作日志**：登录用户的检索 / 读片 / 下载等业务操作；
- **协议日志**：系统对外 / 出向的 DICOMweb 接口调用（动作、路径、状态、耗时、IP）；
- **系统日志**：PHP 服务器运行日志（默认 `data/.serve.log`）。

### 基础设置
- `站点名称`、`医院名称`（接口未返回机构名时的兜底展示）；
- `默认窗宽 WW` / `默认窗位 WL`；
- `影像视图序列上限`（3-10）：影像视图最多同时打开的患者检查数，超出后自动移除最早打开的检查。

### 外部接口

只配置 **DICOMweb** 接口即可；**患者信息随接口返回，无需额外配置 FHIR**。

- **PACS 接口（必填）**：影像检索与调阅的基础，采用 **DICOM 标准 HTTP 接口 DICOMweb**：
  - **QIDO-RS** 检索 + **WADO-RS** 取像（含原生多帧），适配 dcm4chee、Orthanc（DICOMweb
    插件）等现代 PACS 网关；
  - 同时支持标准 DICOM 网络身份参数（**AE Title / 主机 / DICOM 端口**）用于标识与展示；
  - DICOMweb 是 DICOM 标准的 HTTP 版本。**传统 DICOM（DIMSE，TCP 104）不能由浏览器
    直连**；若 PACS 仅提供 DIMSE，需在中间部署网关将其转为 DICOMweb 后再在此配置。
- **FHIR R4**：不再作为真实接口的补充；仅在【模拟服务器 → 患者数据来源】中可选
  「FHIR 接口获取」来给内置模拟服务器提供患者数据（见下节）。

PACS 接口字段：

| 字段 | 说明 |
| --- | --- |
| DICOMweb 根地址 | 以 `/dicom-web` 结尾的根地址（如 `http://192.168.1.100:8042/dicom-web`） |
| 接口密钥 | 可选：以 `Authorization: Bearer` / `X-API-Key` 请求头发送 |
| 超时（秒） | 远程请求超时 |
| 本系统 / 目标 AE Title | DICOM 网络身份（DIMSE 标识），用于对接展示 |
| PACS 主机 / DICOM 端口 | 传统 DICOM（DIMSE）网络地址；本项目经 DICOMweb(HTTP) 取数 |

> 【测试接口连通性】测试的是**当前输入框中的值**（无需先保存）；
> 点击【保存外部接口配置】时会**先自动测试**，失败则报错且不保存。

### 账号管理
以**模态框**新增 / 编辑账号、启停账号、重置密码、删除账号。安装时创建的管理员为
**安装管理员**（🔒 徽章），不可删除、不可停用；不能停用 / 删除当前登录账号。

### 操作日志
记录每次操作的账号、类型、详情与 IP，可一键清空。操作类型包括：搜索、读片、
下载、阅读 DICOM、**查看影像报告**。搜索类记录额外包含关键词与结果数。
头部提供【刷新】与【实时】（勾选后每 5 秒增量更新新日志）；【设置】可配置**上限条数**
与**上限天数**（任一先达到即删除最早的日志）；两项均可留空表示不限制。

### 存储情况
展示数据库、上传文件、缓存区（内存 / APCu）、会话文件与磁盘缓存占用。
头部【清空】下拉可选择清空上传文件或缓存区；【刷新】与【实时】（定时刷新缓存占用）；
【设置】可分别开关 APCu 内存缓存与硬盘缓存、设置各自**容量上限（MB）**，以及内存与
硬盘**共享的日期上限（天）**；超过上限自动删除最早生成的缓存，容量与日期谁先满足
执行谁，均可留空表示不限制。

## 三、模拟服务器（内置 PACS 模拟）

【管理设置 → 模拟服务器】子 Tab 提供一台内置的模拟 PACS 服务器，供无真实设备时联调：

- **服务器状态**：启用 / 停用；展示并复制**DICOMweb 地址**与**接口密钥**，
  可重新生成密钥（生成后需重新「一键应用」）。
- **患者数据来源（左右分栏选择）**：
  - **内置模拟数据**（默认）：确定性仿真患者数据，开箱即用；
  - **FHIR 接口获取**：填写 FHIR R4 地址 / 密钥 / 超时并【测试 FHIR 连接】，由门诊
    系统获取「已缴费、已登记」患者及其检查；影像仍由内置模拟服务器按标准 DICOM 生成。
- **患者查询（全部患者）**：按关键词查看当前来源下的全部患者与检查（含已摄片）。
- **摄片登记**（来源为 FHIR 时）：读取门诊系统 FHIR 工作项（`Task`）并列出「已缴费未摄片」
  的检查；可先「登记」（回写 `Task=accepted`）再「摄片」（分配标准 `StudyInstanceUID`、生成影像、
  回写 `Task=completed`）。摄片后该检查经 DICOMweb 立即可检索 / 取像。
  **回写要求 FHIR 访问密钥具备 `system/Task.write`（及 `system/Task.read` 取工作列表）**；
  仅取像联调时只读密钥即可（摄片仍会生成影像，仅状态回写失败并提示）。
- **日志查询**：查看模拟服务器运行事件（FHIR 取数 API `fhir/api`、`register` 登记、
  `acquire` 摄片、`generate` 生图），支持实时 / 刷新 / 设置 / 清空与行数 / 天数上限。
- **一键应用模拟服务器数据**：自动把 **DICOMweb 地址 / 密钥**填入【外部接口】。

> 内置模拟服务器对外提供**标准接口**：DICOMweb（`/dicom-web/studies` 等 QIDO-RS /
> WADO-RS）与标准 DICOM 文件（WADO-URI，`?r=dicom`），可被任何标准客户端联调。

### 标准 DICOM 输出（WADO-URI）

模拟服务器同时提供**标准 DICOM Part 10 二进制流**，可被任何通用 DICOM /
PACS / WADO 客户端直接解析，像素与元数据均为标准 Tag（无任何模拟标记）：

| 方式 | 地址 | 参数 |
| --- | --- | --- |
| 直取（登录或密钥） | `?r=dicom&...` | `studyUID` / `seriesUID` / `objectUID` 或 `uid` / `series` / `instance` / `key` |

- `series` / `instance` 为从 1 起的序列号与帧号；`objectUID` 末段亦映射到帧号。
- 返回 `Content-Type: application/dicom`，每个实例单帧；多帧序列按实例号递增，
  并携带 `ImagePositionPatient` / `ImageOrientationPatient` / `SliceLocation`，
  支持客户端滚轮连续切换。
- 重建序列（冠状 / 矢状 MPR）仅提供元数据，不输出像素实例。
- SOP Class 按模态选择：CT / MR / CR(DR) / US Image Storage；传输语法为
  Explicit VR Little Endian（`1.2.840.10008.1.2.1`）。

## 四、通用上传与鉴权下载（基础设施）

供影像 / 资料 / 图标等上传功能复用，文件 **不在 Web 根下**，经路由鉴权下发：

| 操作 | 路由 | 说明 |
| --- | --- | --- |
| 上传 | `POST ?r=upload` | 需登录 + CSRF；字段 `file`、`category`(可选)；返回 `token` 与访问 URL |
| 下载 / 预览 | `GET ?r=file&t=令牌[&download=1]` | 需登录；图片内联预览，其余强制下载 |
| 删除 | `POST ?r=upload/delete` | 需登录 + CSRF；管理员或上传者本人 |

- 存放路径：`data/uploads/<category>/`（Web 根之外，不提交）。
- 类型白名单：PNG / JPEG / GIF / WebP / PDF / 纯文本；单文件上限 20MB。
- 安全：内容探测类型、随机令牌文件名、`uploads` 表记录、防路径穿越、
  响应附 `X-Content-Type-Options: nosniff` 与 `CSP: sandbox`。
- 前端调用：`PvUI.upload('upload', file, { category: 'demo' }).then(...)`。

## 五、远程 PACS 接口约定（标准 DICOMweb）

本项目通过**标准 DICOMweb（HTTP / REST）**访问 PACS：

```
GET {根}/studies?PatientName=关键词&limit=20&offset=0&includefield=all   （QIDO-RS 检索）
GET {根}/studies/{studyUID}                                              （检查元数据）
GET {根}/studies/{studyUID}/series                                       （序列）
GET {根}/studies/{studyUID}/series/{seriesUID}/instances                 （实例，含 NumberOfFrames）
GET {根}/studies/{studyUID}/series/{seriesUID}/instances/{sopUID}        （WADO-RS 实例字节流）
```

- 元数据返回 DICOM JSON（`application/dicom+json`）；实例返回 `application/dicom`
  （支持原生多帧 `NumberOfFrames > 1`）。
- 认证（可选）：`Authorization: Bearer` / `X-API-Key` 请求头。
- 本项目将 DICOM JSON 解析为内部模型；`series[].images` 经服务端代理 `?r=wadoprx`
  同源下发，避免浏览器跨域与密钥外泄。
- 传统 DICOM（DIMSE）不由本项目直连；若 PACS 仅有 DIMSE，需先在中间部署网关将其
  转换为 DICOMweb。

### 对接传统 DICOM（DIMSE，可选）

本浏览器通过 **DICOMweb（HTTP）**取数，**不能直连 DIMSE**（C-FIND / C-MOVE / C-STORE，
TCP 104）。若你的 PACS 仅提供 DIMSE，可在中间部署一个 **DICOMweb 网关**，再由本浏览器访问：

- **Orthanc**：启用其 DICOMweb 插件（访问根为 `/dicom-web`），并以 DIMSE 关联（C-MOVE/C-GET）
  连接到 PACS；
- **dcm4chee-arc**：自带 DICOMweb（QIDO/WADO-RS），可配置 DIMSE 关联；
- 其他商业网关同理，将 DIMSE 转为 DICOMweb。

把网关的 DICOMweb 根地址（以 `/dicom-web` 结尾）填入【外部接口】即可；【外部接口】的
「DICOM 网络身份参数（AE Title / 主机 / 端口）」用于登记与网关/PACS 的 DIMSE 配置对应。

## 六、键鼠快捷交互速查表

> 阅片器顶部为商用 PACS 风格**大图标工具栏**；左侧为可同时展开多个检查的工作区；
> 搜索结果卡片可进入【影像】视图。搜索页的「打开影像时清空已加载序列」勾选项
> 决定新打开检查是清空还是追加。

| 操作 | 效果 |
| --- | --- |
| 滚轮直接滚动（CT/MR 多帧） | 断层切片逐帧平滑翻页（Cine） |
| 拖动图像右侧**帧滚动条** | 连续滚动浏览整个序列（单帧时隐藏） |
| 鼠标在图像上移动 | 底部居中显示该点 **CT 值（HU）** 与像素坐标 |
| **右键单击**图像 | 弹出快捷菜单（预设窗 / 缩放 / 平移 / 使用窗口 / 原图 / 测量 / 变换，含二级菜单） |
| 点击左栏检查标题 | 展开 / 收起该检查的序列；可同时展开多个 |
| 【布局▾】 | 单视图 / 左右双视图 / 上下双视图 / 四视图；点击窗格激活后再点左侧序列载入该窗格 |
| 拖动序列栏右侧分隔条 | 调整序列栏宽度（双击复位） |
| 左栏检查标题的 × / 底部【关闭全部】 | 关闭单个检查 / 清空全部并显示空态 |
| 按住鼠标右键拖拽 | 无级调节窗宽 WW（水平）/ 窗位 WL（垂直） |
| 按住鼠标中键拖拽 | 自由平移画布 |
| `Ctrl + 滚轮` 或【缩放】 | 以鼠标指针为中心无级放大 / 缩小 |
| 左键依次点击两点 / 三点 | 测距（mm）/ 测角（°） |
| 左键拖拽框选 | 矩形 / 椭圆 ROI（面积 mm² + 平均灰度值） |
| 【预设窗▾】 | 软组织窗 400/40 · 肺窗 1500/-600 · 骨窗 2000/350 · 默认窗 2500/250 |
| 【变换▾】 | 左旋 / 右旋 / 水平镜像 / 垂直镜像 / 反色 |
| 【序列栏】 | 显示 / 隐藏左侧工作区 |
| 【工具▾】 | DICOM 详情 / 复制阅片直链 / 保存当前图像 / 保存序列（ZIP） |
| 【测量▾】 | 测距 / 测角 / 矩形 ROI / 椭圆 ROI / 清除标注 |
| 【适应窗口】/【1:1 原图】 | 复位视图比例 |
| 【退出】 | 返回研究检索 |

> 操作会写入【管理设置 → 操作日志】：搜索、读片、下载（图像 / 序列）、阅读 DICOM
> 均记录账号、患者姓名与检查类型，便于溯源。

## 七、部署与集成

- **独立部署**：Web 根指向 `public/`；Nginx 示例（`root …/public; index index.php;`）：

  ```nginx
  server {
      listen 80;
      server_name pacs.local;
      root /path/to/pacs-viewer/public;
      index index.php;
      location / { try_files $uri $uri/ /index.php?$query_string; }
      location ~ \.php$ {
          include fastcgi_params;
          fastcgi_pass 127.0.0.1:9000;
          fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
      }
  }
  ```

- **集成到门诊一体化主项目**：主项目以 `git subtree` 将本仓库挂载在
  `tools/pacs_viewer/`，此时可通过 `http://<主项目>/tools/pacs_viewer/` 访问
  （入口路径自适应，无需改代码）。更新同步命令见根目录 `README.md`。

### PWA 与图标
- 全站支持 PWA：浏览器地址栏出现「安装」图标即可装到桌面 / 主屏幕，离线也能
  打开（静态资源缓存优先，接口实时直连不缓存）。
- Manifest 与 Service Worker 由 `?r=manifest` / `?r=sw` 动态生成，随部署路径
  自适应（子目录挂载也能正确设置 scope）。
- 默认图标**由代码绘制，无预置图片**（`?r=icon&size=N`）。管理员可在
  【管理设置 → 基础设置 → 站点图标】上传自定义图标（PNG / JPG / GIF / WebP，
  ≤4MB）覆盖默认，并可「恢复默认」；自定义图标保存为运行时 `data/uploads/icon.png`
  （Web 根之外，不纳入版本管理，经 `?r=icon` 路由下发）。

## 八、常见问题

- **检索报「无法连接 PACS 接口」**：检查【管理设置 → 外部接口】的地址与
  网络可达性；本地联调可去【模拟服务器】点【一键应用模拟服务器数据】。
- **模拟服务器检索报密钥错误**：重新生成密钥后需重新「一键应用」，使 DICOM/PACS
  接口里的密钥同步更新。
- **FHIR 来源取不到患者**：确认门诊系统已暴露 FHIR R4 端点、存在对应的
  `Patient` 与 `ImagingStudy` 资源；可先在【模拟服务器】点【测试 FHIR 连接】。
- **登录后空白 / 无法安装**：确认 `data/` 目录可写（用于建库与会话）。
- **端口冲突**：更换 `--listen` 端口即可。
- **改动前端后仍加载旧文件**：浏览器可能命中 Service Worker 缓存，请强刷
  （Cmd/Ctrl+Shift+R）或在开发者工具 → Application → Service Workers 中
  「Update / Unregister」。
- **忘记管理员密码 / 重新安装**：删除 `data/pacs_viewer.db` 后会再次进入首次运行
  安装向导，重新创建管理员与设置（仅本项目数据，不影响任何宿主系统）。
