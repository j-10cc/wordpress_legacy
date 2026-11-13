# WordPress Legacy Exporter

通过 WordPress 的 `wp-load.php` 读取已发布文章，一次导出早期桌面、经典桌面、掌上和触屏四套静态页面。通用版面向使用标准文章内容的 WordPress 博客，可配置颜色、导航语言和 Early 编码。站名、站点说明、主站地址、文章日期和题图取自 WordPress。访问导出目录、`index2.html` 或 `post_1561.html` 时，服务器按请求的 User-Agent 返回对应版本，地址栏始终使用同一个地址。

## 导出

需要 PHP CLI（能加载本站 WordPress，含 DOM、iconv）、可用的 WordPress 数据库和 ImageMagick。PHP 需要能通过 `exec()` 调用 `convert` 或 `magick`，并能读取文章图片。生成器只读文章。

```bash
./build.sh --wordpress-root=/var/www/html --output=/var/www/html/legacy
```

也可以直接运行 `php generate.php`。导出器与 `wp-load.php` 同目录时可省略 WordPress 路径，也可设置 `WORDPRESS_ROOT`。默认输出目录为项目下的 `legacy/`。若系统使用 ImageMagick 7，可设置 `IMAGEMAGICK_CONVERT=/usr/bin/magick`。

| 参数 | 用途 |
|---|---|
| `--wordpress-root=PATH` | 包含 `wp-load.php` 的目录 |
| `--output=PATH` | 导出站点的独立目录 |
| `--per-page=N` | 每页文章数，默认 5，范围 1–100 |
| `--config=PATH` | UTF-8 JSON 配置文件，路径相对于执行命令时的工作目录 |
| `--help` | 显示帮助 |

## 配色、语言与编码

不传 `--config` 时继续使用蓝色配色、中文导航和 GB2312 Early 页面。配置只在导出时读取，不增加客户端脚本、CSS 变量或网络请求；浏览页面也不需要读取配置文件。

复制 [legacy.example.json](legacy.example.json) 后编辑：

```bash
cp legacy.example.json legacy.json
./build.sh --wordpress-root=/var/www/html --output=/var/www/html/legacy --config=legacy.json
```

示例采用英文导航和 Windows-1252 编码，并列出全部颜色键；不需要覆盖的颜色可以删去。实际配置可以很短，未提供的项继承默认值：

```json
{
  "language": "en",
  "early_charset": "windows-1252",
  "unmappable": "entity",
  "theme": {
    "primary": "#315c45",
    "background": "#eef4ee",
    "surface": "#ffffff",
    "text": "#222222"
  },
  "messages": {
    "notice": "A lightweight edition for older browsers.",
    "page_status": "Page {page} of {pages}"
  }
}
```

颜色值仅接受 `#RGB`、`#RRGGBB`。常用颜色为 `primary`（页头及 Early 站名）、`on_primary`（主色背景上的文字）、`background`（页面外背景）、`surface`（内容背景）、`text`、`muted`、`link`、`visited`、`border`、`code_background`、`table_header_background`。边栏、文章标题链接及 Touch 菜单的细分颜色也可独立设置，完整名称和默认值见示例的 `theme`。未指定的细分颜色保持默认，不自动推算整套色板。

Early 的链接颜色由 `early_link` 控制，已访问链接由 `early_visited` 控制；不显式提供时，前者跟随已配置的 `link`，其次跟随已配置的 `primary`，后者跟随已配置的 `visited`。完全不提供配置时保留原先蓝色链接和灰色已访问链接。其他三版只有显式提供 `visited` 时才统一覆盖已访问链接颜色，否则保留各组件原有颜色。其他三版使用 CSS，Early 写入传统 `bgcolor/text/link/vlink` 和 `font color` 属性。配置不会修改文章图片的颜色。

`language` 与 `early_charset` 独立：切换编码不会翻译文字。内置 `zh-CN`、`en` 导航；其他合法语言标签使用英文兜底，再通过 `messages` 覆盖文案，HTML 的 `lang` 随语言标签输出。文章标题、正文、摘要、站名和简介保持 WordPress 原文，不自动翻译。

`messages` 支持如下键，所有值为纯文本，输出时转义，不能注入 HTML：

| 键 | 用途 / 必须保留的占位符 |
|---|---|
| `notice`, `main_site`, `home` | 旧浏览器说明、返回主站、首页 |
| `previous_post`, `next_post` | 上一篇、下一篇 |
| `previous_page`, `next_page`, `page_status` | 上一页、下一页、分页状态；状态保留 `{page}` 和 `{pages}`，可调整顺序 |
| `empty` | 无文章时的提示 |
| `index`, `index_label`, `open`, `close` | Touch 索引按钮、标题及辅助提示 |
| `browse_all`, `recent_posts`, `recent_updates`, `by_year` | 浏览全部、近期文章、最近更新、按年浏览 |
| `year_label`, `post_count` | 年份和文章数；分别保留 `{year}`、`{count}` |

Early 编码支持 `GB2312`、`GBK`、`GB18030`、`Big5`、`Shift_JIS`、`EUC-JP`、`EUC-KR`、`windows-1252`、`windows-1251`、`ISO-8859-1`、`KOI8-R`、`UTF-8`，名称不区分大小写，并检查当前 PHP 的 iconv 支持。选用客户端实际支持的编码；UTF-8 可导出并不意味着所有古董浏览器都能显示。

`unmappable` 默认 `entity`：无法用目标编码表示的字符改写为十进制字符引用，如 `&#128512;`，按文件报告数量，不静默删除。浏览器还需要支持相应字符引用和字体才能正确显示。设为 `error` 则遇到不可表示字符时停止导出；失败的输出目录不应部署。GB2312 的破折号保留历史兼容映射。

无效 JSON、未知配置项、不合法颜色、语言标签、编码或文案占位符会在加载 WordPress、清理 `editions/` 之前报错。**修改任意配置后，都要重新导出并完整部署 HTML、样式及 `dispatch.php`**；只替换 JSON 不会改变已生成站点，单独部署旧分流器还可能造成响应头与字节编码不一致。

当前范围为已发布的 `post` 文章：不导出独立页面、自定义文章类型、评论或插件交互功能，也不承诺任意插件短代码的完整还原。

## 四套页面

| 版本 | 匹配设备 | 页面适配 |
|---|---|---|
| 早期桌面 | Windows 3.x、IE1–3、OS/2 IBM WebExplorer、早期 Netscape/Mosaic、CE 1–3、Palm OS 1–4 | 编码默认 GB2312，可配置；原生 HTML 文档流、低色 GIF。无整页布局表格、CSS 或脚本；列表题图左对齐，正文插图及图注仍直接显示并居中，数据表格占满正文宽度。 |
| 经典桌面 | IE4–9、Opera 4 起的桌面版、同期 Mac 浏览器，以及未命中专用规则的桌面 UA、移动浏览器的桌面模式 UA | 按 800×600 设计，窗口变窄时页面与列表双栏按可用宽度收窄。列表主栏含标题、日期、大题图和摘要，右栏为近期文章及按年浏览。正文图库可双列对照。 |
| 掌上版 | CE 4–6、Windows Mobile、Palm OS 5、Sharp Zaurus（NetFront/Opera）、Symbian/S60、BB10 之前的 BlackBerry OS、Opera Mobile/Mini，以及未命中专用规则的移动 UA | 窄屏单栏，192 像素题图和摘要，保留表格行列和代码换行。 |
| 触屏版 | iPhone、iPod、iPad、Windows Phone 7–10、Android 1 起，以及 webOS | 流式单栏图文、可局部滚动的表格和代码、触屏分页链接。 |

图片按版本处理：Early 正文图缩入 320×240、列表题图缩入 200×150，保持比例、不放大，输出无抖动、非交错的 32 色 GIF；同时限制宽高，降低长图的解码内存。Classic、Touch 正文使用保留像素尺寸的优化图（EXIF 方向会纠正），列表题图仍分别限制宽度 672、480 像素。JPEG 使用质量 78、4:2:0 色度采样及基线编码，是有损压缩；PNG 使用最高级别压缩和自适应过滤，不量化颜色，保留截图文字和透明度。压缩幅度取决于原图已经优化的程度，保留像素尺寸也意味着解码内存不会随文件体积同比减少。Pocket 仍限制宽度 192 像素。图片缓存键包含编码策略版本，更新策略后重新导出会生成新文件并清理不再使用的旧图片，无需手动删除缓存。

Early 优先减少浏览器排版负担：白底、蓝色站名及链接，使用默认字体和原生标题；移除页头及整页布局表格、逐段和逐单元格样式。代码直接使用 `<pre>` 保留缩进与换行，并在导出时折断长行，不再使用代码表格、大量空格实体和 `<br>` 标签。导出时压缩代码区外的多余空白；完整正文、题图、插图、图注和数据表格仍保留，不将正文图片改成按需加载链接。

CE2 Pocket IE 上，列表日期与题图即使由 `<br>` 分隔也可能显示在同一行。Early 首页及列表因此仅为每条文章的标题、日期、题图使用一个无边框单列表格，三者分行并左对齐；无题图时不生成图片行。摘要在表格外，整页仍是原生文档流，不增加 CSS、脚本或网络请求。

首页就是第一页文章列表。四版都在站名下显示旧式浏览器说明和返回主站链接。正文页底部按发表顺序链接上一篇、下一篇及首页，首尾文章只显示存在的邻篇。通用版不生成嵌入式播放器、视频封面占位页或播放器脚本，四版都移除 iframe、video、audio、object、embed 等嵌入元素。文章中已有的普通视频链接按普通链接保留，导出器不解析平台播放地址。原 j10cc.site 适配版本保留在 `release/j10cc.site` 分支。

Classic 首页和列表使用同一行的两列表格，主栏占 73%、边栏占 27%，内容均从顶部开始；列宽使用 HTML 属性和 `table-layout:fixed` 指定，不依赖浮动来维持左右布局。边栏内边距放在单元格内部，避免挤占另一列。题图缩放以主栏单元格宽度减去文章块的左右间距为上限，防止旧 IE 先被固定像素图片撑宽后，再把错误的宽度用于缩放。固定表格布局的行为参见 [Microsoft tableLayout 文档](https://learn.microsoft.com/en-us/previous-versions/windows/internet-explorer/ie-developer/platform-apis/aa768726(v=vs.85))。

Touch 首页、文章列表和正文页的站名右侧提供汉堡菜单“索引”，在右侧覆盖显示，不挤动正文。菜单在 HTML 中默认隐藏，脚本紧跟菜单初始化，不等待正文、图片加载完成；禁用 JavaScript 时通过 `noscript` 显示普通导航。打开和关闭不使用动画、阴影、透明遮罩，也不修改整页滚动及内边距、不强制读取布局或监听页面滚动；触屏控制按钮直接响应轻触，并抑制随后重复的合成点击。点击外侧遮罩、关闭按钮或按 Esc 可收起。菜单保留大点击区域的文章列表（附日期）和双列年份按钮（附文章数），面板独立滚动；旧式触屏浏览器仅在打开和调整窗口时设置视口位置，并保留触摸滚动回退。脚本内嵌、样式合并进已有样式表，不增加网络请求。

UA 匹配使用 `exporter/ua-rules.json`，按文件顺序取首个匹配项，不区分大小写。桌面默认 Classic，移动默认 Pocket；明确的早期设备和触屏设备仍使用专用版本。匹配顺序如下：

1. `WPDesktop`、`XBLWP7`、`ZuneWP7` 等桌面模式标记选择 Classic。完全伪装成普通桌面浏览器的设备按实际发送的 UA 匹配；仅凭 UA 无法还原其真实设备。
2. iPhone/iPad/iPod、Android、Windows Phone 7 起和 webOS 选择 Touch，包括这些平台上的 Opera Mobile/Mini。不能仅因没有 `Mobile` 就判为桌面模式，例如 Android 平板仍选 Touch。
3. CE 1–3、Pocket PC 2000/2002、Palm OS 1–4 选择 Early；CE 4 起、Windows Mobile、Palm OS 5 等选择 Pocket。明确的系统版本优先于通用移动标记。
4. Sharp Zaurus 及相近的嵌入式掌上 UA，通过 `Zaurus`、`Qtopia`、`Embedix`、`Qt embedded`（也接受 `Qt/Embedded`）、`SL-C750`/`SL-5500` 等 SL 系列型号或独立的 `PDA` 标记选择 Pocket，优先于桌面 Opera 和 Mozilla 规则。包含 `PHONE`、`Mobile`、`Mobi`、`Opera Mini` 等移动标记，以及版本不明的 CE/Palm、Symbian、BlackBerry、J2ME/MIDP、NetFront 等，也默认选择 Pocket。即使同时带有 `MSIE`、`Trident` 或旧 `Mozilla` 兼容标记，也不会因此转为桌面版。仅有 `Linux` 或 ARM 架构标记不据此判为掌上设备。
5. 其余桌面 UA 中，Windows 3.x、IE1–3、IBM WebExplorer（含 `IBM-WebExplorer-DLL/v1.2`）、Opera 1–3、早期 Netscape/Mosaic 等选择 Early；IE4–9、Opera 4 起及其他桌面浏览器选择 Classic。不按 OS/2 系统标记一概降级，OS/2 上的 Netscape 4、Opera 等仍按浏览器匹配。未识别或空 UA 也回退到 Classic。

Opera Mobile 使用 `Mobi` 标记，Opera Mini 使用 `Opera Mini` 标记，参见 [Opera 官方移动适配指南](https://help.opera.com/en/mobile-web-optimization-guide/)。这些规则决定页面版本，实际显示效果仍取决于浏览器的 HTML/CSS 实现。

Pocket 的首页、列表和每篇文章均只生成一个 HTML 文件，例如 `editions/pocket/post_1561.html`。移动 IE、黑莓和 Opera Mobile 共用同一份正文、代码和表格，不生成兼容副本或子目录。服务端识别 Windows Mobile/CE 的移动 IE 后，只增加 `pocket-ie` 样式类和窄屏提示。正文与代码保持原有字号，仅将移动 IE 图注缩小为 11px；表格字号与正文统一为 `small`。所有 Pocket 表格使用固定列网格，按逻辑列数等分可用宽度，以 `colgroup`、单元格 `width` 属性和 CSS 同时指定列宽；跨列单元格取对应列宽之和，不根据文字长短估算宽度。跨行单元格按所属行组处理，`rowspan="0"` 转换为实际行数，兼容旧表格引擎。

Pocket 代码框随正文或单元格的可用宽度铺满。含代码的 Pocket 正文页内嵌一段兼容旧式 JavaScript 的折行脚本：读取代码内容区的实际像素宽度，按实际字体批量测量不同字符的宽度并缓存，逐字填满一行，再生成 `<br>` 强制折行；不保留完整单词，无空格长字符串同样处理。测量节点禁止自动折行，测量成功的代码也禁止浏览器按单词进行二次折行，仅保留原始换行和脚本计算出的换行。窗口大小或横竖屏变化时延迟重排，始终使用缓存的原始代码，因此屏幕变宽后可合并此前的显示用换行。脚本按字体共享字符宽度缓存，批量测量未缓存的字符，并批量读取代码框宽度；不将全部英文字符按 `W` 估算。尺寸与字体未变的代码框不重写；折行引起滚动条出现或消失时，最多追加一次宽度校正，不逐个源字符触发布局、不持续轮询、不增加脚本请求，列表及无代码文章不加载折行脚本。

旧浏览器若不能查询实际字体，按代码框分别缓存测量结果，避免不同字体混用字符宽度；只能读取整数像素时，用重复字符测量来减小累计舍入误差。仅在禁用 JavaScript 或浏览器无法测量宽度时，保留导出时生成的保守折行（最多 20 个半角显示列，表格内进一步缩短）。这些兜底换行与原始换行有独立标记，正常测量成功后会重新计算，不把 20 列当作动态折行上限。正文、表格字号保持一致；复制代码时仍需留意显示用的额外换行。兼容样式合并到已有的 `reader.css`、`list.css`，Pocket 页面只请求一份样式表。更新时需重新导出并部署页面、`dispatch.php` 和样式。

Classic 正文和列表会随窄窗口收缩，图片按所在容器缩小，宽表格和长代码保留局部滚动；Early 仍以 640 像素级桌面为目标，代码折行同时计入中英文显示宽度，超长标题和导航文字也会断行。

Classic、Pocket 的题图和正文插图在导出时同时写入像素宽、高，避免首次下载尚未完成时依赖百分比宽度和自动高度计算比例。图片另存不随布局变化的尺寸基准，内嵌缩放脚本按容器可用宽度等比缩小，并同时更新宽、高，不放大小图；不再从旧 IE 的 `width/height` DOM 属性读取缩放基准（相关行为差异见 [Microsoft getAttribute 文档](https://learn.microsoft.com/en-us/previous-versions/hh771987(v=vs.85))）。Pocket 也使用该脚本，移动 IE 表格图片不再强制拉到单元格的 100%。脚本在页尾立即执行，并在单张图片载入完成时单独校正，不必等其他图片下载完；窗口变化合并处理，尺寸未变不重复写入，无轮询、无额外图片或脚本请求。Touch 共用稳定尺寸基准，Early 不增加脚本或尺寸元数据。更新需重新导出 HTML 和样式，不能只替换服务端分流文件。

## 部署与验证

将整个输出目录（包括隐藏文件）放到 Web 服务器。PHP 仅在请求时选择已经生成的 HTML，浏览页面不需要启动 WordPress。静态图片和样式由 Web 服务器直接发送。

**Apache 2.4** 开启 `mod_rewrite`、PHP，并允许导出目录使用 `.htaccess` 中的 `DirectoryIndex` 和重写指令（`AllowOverride Indexes FileInfo`）。导出器会自动创建或更新输出目录的 .htaccess，替换其中 WordPress Legacy Exporter 标记内的规则，并保留其他内容。该操作仅作用于输出目录的 .htaccess；Apache 全局配置、VirtualHost 和服务重载由部署环境管理。若部署在已有 WordPress 站点的子目录，主站重写规则需放行该目录，由导出目录的规则处理请求。

**Nginx** 以导出目录部署在站点 `/legacy/` 为例，把以下规则加到站点 `server` 配置中，并让现有 PHP-FPM 配置处理 `/legacy/dispatch.php`。部署到其他路径时，相应修改前缀。

```nginx
location ^~ /legacy/editions/ { return 404; }
rewrite ^/legacy/(?:index(?:[2-9]|[1-9][0-9]+)?\.html|post_[0-9]+\.html)?$ /legacy/dispatch.php last;
```

**本机预览** 可使用 PHP 内置服务器，命令从项目根目录运行。此时站点位于 `http://127.0.0.1:8080/`。

```bash
php -S 127.0.0.1:8080 -t legacy legacy/dispatch.php
curl -sI -A 'Mozilla/2.0 (compatible; MSIE 3.0; Windows 3.1)' http://127.0.0.1:8080/index.html
curl -s -A 'Mozilla/4.0 (compatible; MSIE 4.01; Windows 95)' http://127.0.0.1:8080/index.html
```

早期版响应使用配置的 `early_charset`（默认 GB2312），其他三版使用 UTF-8。导出时将编码写入 `dispatch.php`，使响应头、HTML META 和实际文件字节一致。HTML 响应带有 `Vary: User-Agent`，代理或 CDN 必须按 UA 区分缓存。需要老设备直接接入时，为它们提供能够建立连接的 HTTP 入口。输出根目录不再保留静态 HTML 副本，首页、列表和正文均需要上述服务端路由。

针对 WebExplorer 的“未知文件类型／要求下载”问题，分流器为该浏览器返回不带参数的 `Content-Type: text/html`，并在本次请求内关闭 PHP 自动补充字符集；正文仍使用配置的 Early 编码，编码声明保留在 HTML 的 META 中。其他浏览器继续收到带字符集的响应头。生成的 `.htaccess` 使用 `AddDefaultCharset Off`，避免 Apache 为裸 MIME 类型重新追加参数（参见 [Apache 官方说明](https://httpd.apache.org/docs/2.4/mod/core.html#adddefaultcharset)）。这是针对响应类型识别问题的兼容处理，实际效果仍需 WebExplorer 真机复测。更新时重新导出并部署 `dispatch.php`、`ua-rules.json` 和 `.htaccess`；代理若强制改写响应类型，也需检查其实际返回值。

## 文件及更新

- `generate.php` 源码使用 GBK/CP936；`exporter/` 中的 PHP、CSS 和 UA 规则使用 UTF-8。四套页面均由 `exporter/render.php` 和 `exporter/publish.php` 生成；`exporter/config.php` 负责配置校验、颜色、文案和 Early 编码。源码 CSS 中的 `{{颜色名}}` 是导出占位符，只部署输出目录中已经替换为实际颜色的 CSS。
- 输出中的 `images/` 保存各尺寸的本地图片，`styles/` 保存样式。`editions/` 下直接保存 `early/`、`classic/`、`pocket/`、`touch/` 四个目录，`dispatch.php` 根据 UA 读取对应目录的页面。根目录不再复制 HTML；导出成功后会清理旧版生成的 `index.html`、`index2.html` 等分页文件及 `post_数字.html`，保留其他名称的自定义文件。
- 每次导出只允许一个写入者，开始时清空 `editions/` 中的全部内容并重建四个版本目录。删除或取消发布文章后重新导出，对应公开地址返回 404。`editions/` 是生成器专用目录，自定义文件请放到其他位置。导出失败时需要重新执行；对外服务的站点应在导出完成后部署输出目录。
- 图片下载或处理失败时继续生成文字，并逐项输出文章 ID、图片地址、失败阶段及具体原因；转换失败还包含版本、格式、目标宽度、ImageMagick 退出码和错误输出。汇总统计的是失败操作数，一张图的多个尺寸失败会分别计数，并非丢失图片张数。图片下载超时为 300 秒（5 分钟），WordPress HTTP API 与备用下载路径使用相同设置。下载超过 20 MiB 会明确提示大小限制；缓存图片复用前检查格式和尺寸，校验不通过会重新转换。正文中的相对图片地址以文章地址解析。检查输出中的图片警告后再发布；若使用 ImageMagick 7，按前述方式设置 `IMAGEMAGICK_CONVERT`。

本项目使用 [GNU GPL v2.0](LICENSE)。
