# 📗 使用帮助（HELP）

Web PACS 影像浏览器 · 独立 PHP 网站

## 一、快速开始

1. **启动服务**（本机使用 FrankenPHP，Web 根指向 `public/`）：

   ```bash
   ~/.local/bin/frankenphp php-server --root public --listen 0.0.0.0:8090
   ```

2. **打开首页**：`http://localhost:8090/`，会自动进入**首次运行安装向导**。
3. **创建管理员**：填写站点 / 医院信息与管理员用户名、密码，提交后自动登录
   （不再有硬编码默认账号）。
4. **配置数据来源**：真实 PACS 请在【管理设置 → DICOM / PACS 接口】填写地址；
   本地联调可去【模拟服务器】一键应用模拟数据。
5. **检索 / 调阅**：在【研究检索】输入姓名 / 患者号 / 检查号 / 门诊号 / 检查项目，
   点击结果卡片即进入【影像】视图阅片；顶部「影像」Tab 可随时切回已打开的检查。

> 首次访问会在 `data/` 自动生成 `pacs_viewer.db` 与 `session/`，均为运行时数据，
> 不纳入版本管理。

> **导航说明**：站内页面切换为 **AJAX 局部刷新（地址栏保持不变）**；外部直接链接
> （如 `?r=viewer&uid=...`）仍可整页进入；阅片器可用【复制直链】获取可分享地址。

## 二、管理设置（DICOM / PACS 接口配置）

管理员登录后进入【管理设置】，分为五个页签：**基础设置 / DICOM-PACS 接口 /
账号管理 / 检索日志 / 模拟服务器**。

### 基础设置
- `站点名称`、`医院名称`（接口未返回机构名时的兜底展示）；
- `默认窗宽 WW` / `默认窗位 WL`；
- `影像视图序列上限`（3-10）：影像视图最多同时打开的患者检查数，超出后自动移除最早打开的检查。

### DICOM / PACS 接口
本项目**只作为 PACS 前端**，检索 / 调阅全部来自所配置的远程接口地址：

| 字段 | 说明 |
| --- | --- |
| PACS 接口地址 | 远程接口基址，如 `http://192.168.1.100:8042/dicom-web/gateway`；也可填模拟服务器对外地址 |
| 接口密钥 | 可选，作为 `key` 参数随请求发送 |
| 超时（秒） | 远程请求超时 |
| 本系统 AETitle | 本工具侧 AETitle（如 `CLINIC_OPD`） |
| 目标 PACS AETitle | 远程 PACS 侧 AETitle |
| PACS 主机 / DICOM 端口 | DICOM 元数据（如 `192.168.1.100` / `104`） |

点击【测试接口】调用远程 `ping`，成功显示接口名称 / 版本。

### 账号管理
以**模态框**新增 / 编辑账号、启停账号、重置密码、删除账号。安装时创建的管理员为
**安装管理员**（🔒 徽章），不可删除、不可停用；不能停用 / 删除当前登录账号。

### 检索日志
记录每次检索的账号、关键词、结果数与 IP，可一键清空。

## 三、模拟服务器（内置 PACS 模拟）

【管理设置 → 模拟服务器】子 Tab 提供一台内置的模拟 PACS 服务器，供无真实设备时联调：

- **服务器状态**：启用 / 停用；展示并复制**对外 API 地址**与**接口密钥**，
  可重新生成密钥（生成后需重新「一键应用」）。
- **患者数据来源**：
  - `内置仿真数据`：确定性生成，开箱即用；
  - `门诊系统 FHIR R4`：填写 FHIR 地址（可选密钥 / 超时），
    按 `Patient?name=` 搜索患者，再按 `ImagingStudy?patient=` 获取其已登记影像检查
    （存在 ImagingStudy 视为「已缴费、已登记」）。可点【测试 FHIR 连接】。
- **已缴费已登记患者预览**：按关键词查看当前来源下的患者与检查。
- **一键应用模拟服务器数据**：自动把地址与密钥填入【管理设置 → DICOM / PACS 接口】。

> 对外 API 使用标准 `{action,q,uid,key}` 约定，既可被本浏览器调用，也可提供给
> 门诊系统等其他系统集成。

### 标准 DICOM 输出（WADO-URI）

模拟服务器同时提供**标准 DICOM Part 10 二进制流**，可被任何通用 DICOM /
PACS / WADO 客户端直接解析，像素与元数据均为标准 Tag（无任何模拟标记）：

| 方式 | 地址 | 参数 |
| --- | --- | --- |
| WADO-URI（外部） | `{对外地址}&action=wado&...` | `studyUID` / `seriesUID` / `objectUID` / `key` |
| 直取（登录或密钥） | `?r=dicom&...` | 同上，或 `uid` / `series` / `instance` |

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

## 五、远程 PACS 接口约定

```
GET {endpoint}?action=search&q=关键词&key=APIKEY
    → {"code":200,"msg":"ok","data":{"list":[
        {"study_uid":"...","patient_id":"...","name":"...","gender":"男",
         "age":"45岁","outpatient_no":"...","accession_no":"...",
         "modality":"CT","description":"胸部CT平扫","study_date":"2026-09-27 10:00:00",
         "institution":"...","station_name":"...","series_count":3}, ...]}}

GET {endpoint}?action=study&uid=STUDY_UID&key=APIKEY
    → {"code":200,"data":{
        "patient":{...,"patient_id","name","gender","age","outpatient_no"},
        "study":{"accession_no","study_uid","modality","description","study_date",
                 "institution","station_name","slice_thickness"},
        "series":[{"series_id","description","orientation","slice_count",
                   "is_mock","slice_thickness","pixel_spacing","seed","images":[]}]}}

GET {endpoint}?action=ping&key=APIKEY
    → {"code":200,"data":{"name":"...","version":"..."}}
```

- `series[].format="dicom"` 时，`images` 为**标准 DICOM 帧地址（WADO-URI）**列表，
  由前端按标准 DICOM 协议取像并解码渲染；`is_hu`、`window_center/width`、
  `rows/columns`、`bits_*`、`rescale_*` 描述像素参数。
- `series[].images` 若给出普通图片 URL（无 `format`），前端按图像加载。
- 接口返回 `code!=200` 时前端展示其 `msg`。

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

> 操作会写入【管理设置 → 检索日志】：搜索、读片、下载（图像 / 序列）、阅读 DICOM
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

- **检索报「无法连接 PACS 接口」**：检查【管理设置 → DICOM / PACS 接口】的地址与
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
