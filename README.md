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

默认使用蓝色配色、中文导航和 GB2312 Early 页面。复制 [legacy.example.json](legacy.example.json) 后编辑，未提供的项保留默认值：

```bash
cp legacy.example.json legacy.json
./build.sh --wordpress-root=/var/www/html --output=/var/www/html/legacy --config=legacy.json
```

配置示例：

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

| 参数 | 说明 |
|---|---|
| `theme` | 配色，接受 `#RGB` 或 `#RRGGBB`。示例中的 `primary`、`background`、`surface`、`text` 分别为主色、页面背景、内容背景和正文色；完整颜色键见配置文件示例。 |
| `language` | 导航语言，内置 `zh-CN`（默认）和 `en`；其他语言使用英文兜底，可配合 `messages` 自定义。不会翻译文章内容。 |
| `messages` | 覆盖导航文案，如 `notice`（站点说明）、`home`（首页）、`page_status`（分页状态）。使用纯文本，保留原有占位符：分页为 `{page}`、`{pages}`，年份为 `{year}`，文章数为 `{count}`。 |
| `early_charset` | Early 页面编码，默认 `GB2312`；其他三版固定为 UTF-8。与导航语言独立设置。 |
| `unmappable` | 无法用目标编码表示的字符：`entity`（默认）保留为数字字符引用并提示；`error` 停止导出并报错。 |

可选编码：`GB2312`、`GBK`、`GB18030`、`Big5`、`Shift_JIS`、`EUC-JP`、`EUC-KR`、`windows-1252`、`windows-1251`、`ISO-8859-1`、`KOI8-R`、`UTF-8`。请按目标浏览器选择。

修改配置后，需重新导出并完整部署输出目录。

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
