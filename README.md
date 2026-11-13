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
| 早期桌面 | Windows 3.x、IE1–3、OS/2 WebExplorer、早期 Netscape/Mosaic、CE 1–3、Palm OS 1–4 | 极简页面，编码默认 GB2312、可配置；低色图片，列表题图左对齐，正文插图及图注居中，保留表格和代码折行。 |
| 经典桌面 | IE4–9、Opera 4 起的桌面版、同期 Mac 浏览器，以及默认桌面 UA、移动浏览器的桌面模式 UA | 按 800×600 设计并随窗口收窄；列表为文章主栏与索引边栏，含大题图和摘要；正文支持双列图库，宽表格和代码可局部滚动。 |
| 掌上版 | CE 4–6、Windows Mobile、Palm OS 5、Sharp Zaurus（NetFront/Opera）、Symbian、BB10 之前的 BlackBerry OS、Opera Mobile/Mini，以及默认移动 UA | 窄屏单栏，192 像素题图和摘要，保留表格行列，代码按可用宽度折行。 |
| 触屏版 | iPhone、iPod、iPad、Windows Phone 7–10、Android 1 起，以及 webOS | 流式单栏、大题图和触屏分页；右侧覆盖式索引菜单，宽表格和代码可局部滚动。 |

## 部署与验证

部署整个输出目录，包括 `.htaccess` 等隐藏文件。服务器需要 PHP；浏览导出页面不需要加载 WordPress。

### Apache 2.4

启用 PHP 和 `mod_rewrite`，允许导出目录读取生成的 `.htaccess`：

```apache
<Directory /var/www/html/legacy>
    AllowOverride Indexes FileInfo
    Require all granted
</Directory>
```

若部署在已有 WordPress 站点的子目录，主站重写规则需放行该目录。

### Nginx

以站点 `/legacy/` 为导出目录，在 `server` 中添加以下规则，并确保现有 PHP-FPM 配置能处理 `/legacy/dispatch.php`。部署路径不同时，相应修改前缀。

```nginx
location ^~ /legacy/editions/ { return 404; }
rewrite ^/legacy/(?:index(?:[2-9]|[1-9][0-9]+)?\.html|post_[0-9]+\.html)?$ /legacy/dispatch.php last;
```

### 本机预览

从项目根目录启动 PHP 内置服务器，然后访问 `http://127.0.0.1:8080/`：

```bash
php -S 127.0.0.1:8080 -t legacy legacy/dispatch.php
```

可以通过指定 UA 检查分流：

```bash
curl -sI -A 'Mozilla/4.0 (compatible; MSIE 4.01; Windows 95)' http://127.0.0.1:8080/index.html
```

本项目使用 [GNU GPL v2.0](LICENSE)。
