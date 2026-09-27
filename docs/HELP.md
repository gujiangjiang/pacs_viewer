# 📗 使用帮助（HELP）

模拟 Web PACS 影像浏览器 · 独立 PHP 网站

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
5. **检索 / 调阅**：输入姓名 / 患者号 / 检查号 / 门诊号 / 检查项目，点击结果卡片阅片。

> 首次访问会在 `data/` 自动生成 `pacs_viewer.db` 与 `session/`，均为运行时数据，
> 不纳入版本管理。

> **导航说明**：站内页面切换为 **AJAX 局部刷新（地址栏保持不变）**；外部直接链接
> （如 `?r=viewer&uid=...`）仍可整页进入；阅片器可用【复制直链】获取可分享地址。

## 二、管理设置（DICOM / PACS 接口配置）

管理员登录后进入【管理设置】，分为四个页签：

### 基础设置
- `站点名称`、`医院名称`（接口未返回机构名时的兜底展示）；
- `默认窗宽 WW` / `默认窗位 WL`。

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

【模拟服务器】页提供一台内置的模拟 PACS 服务器，供无真实设备时联调：

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

- `series[].is_mock=true` 时由前端算法生成仿真切片；`images` 为空数组。
- `series[].images` 若给出图片 URL，前端将加载真实图像。
- 接口返回 `code!=200` 时前端展示其 `msg`。

## 六、键鼠快捷交互速查表

> 阅片器顶部为商用 PACS 风格**大图标工具栏**，下拉项（预设窗 / 测量 / 变换 / 工具）
> 选择或点击其他区域后自动收起；左侧为可展开的患者信息卡 + 序列缩略图。

| 操作 | 效果 |
| --- | --- |
| 滚轮直接滚动（CT/MR 多帧） | 断层切片逐帧平滑翻页（Cine） |
| 拖动图像右侧**帧滚动条** | 连续滚动浏览整个序列（单帧时隐藏） |
| 按住鼠标右键拖拽 | 无级调节窗宽 WW（水平）/ 窗位 WL（垂直） |
| 按住鼠标中键拖拽 | 自由平移画布 |
| `Ctrl + 滚轮` 或【缩放】 | 以鼠标指针为中心无级放大 / 缩小 |
| 左键依次点击两点 / 三点 | 测距（mm）/ 测角（°） |
| 左键拖拽框选 | 矩形 / 椭圆 ROI（面积 mm² + 平均灰度值） |
| 【预设窗▾】 | 软组织窗 400/40 · 肺窗 1500/-600 · 骨窗 2000/350 · 默认窗 2500/250 |
| 【变换▾】 | 左旋 / 右旋 / 水平镜像 / 垂直镜像 / 反色 |
| 【序列栏】 | 显示 / 隐藏左侧序列与患者信息栏 |
| 【工具▾】 | 复制阅片直链 / 保存当前图像（PNG）/ 保存序列（ZIP）/ 清除标注 |
| 【适应窗口】/【1:1 原图】 | 复位视图比例 |
| 【退出】 | 返回研究检索 |

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
