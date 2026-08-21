# WordPress Legacy Exporter

将 WordPress 博客导出为面向 Internet Explorer 3 等早期浏览器的静态站点。

项目以 HTML 3.2、GB2312 和简单的表格布局为基础，尽量避免依赖现代 JavaScript、复杂 CSS、Web 字体以及新式图片格式，使旧电脑、旧 PDA 和其他资源受限设备也能阅读博客文章。

## 项目用途

程序通过 WordPress 的 `wp-load.php` 读取所有已发布文章，并生成文章列表页、文章正文页及本地图片资源。主要处理内容包括：

- 生成分页的文章列表、摘要和文章独立页面；
- 输出适合旧浏览器解析的 HTML 3.2 页面；
- 将页面统一编码为 GB2312；
- 将文章图片和特色图片下载并转换为低色彩 GIF；
- 处理标题、段落、列表、表格、代码块、图片注解和 WordPress 图库；
- 使用固定内容宽度和简单 HTML 属性，降低旧浏览器的排版压力；
- 移除脚本、表单、音视频、iframe 等不适合目标浏览器的元素；
- 清理已经失效的旧文章页面和旧图片文件。

本项目生成的是只读静态副本，不会修改 WordPress 数据库中的文章。

## 项目结构

```text
wordpress_legacy/
├── generate.php       主程序：读取 WordPress、转换内容并生成静态站点
├── build.sh           Shell 启动脚本，将参数转交给 generate.php
├── .gitattributes     固定 PHP、Shell 和 HTML 文件的 LF 换行符
├── template.html      内置的 GBK 首页模板，可按需修改
└── legacy/            默认输出目录，首次运行时自动创建
    ├── index.html     第一页文章列表
    ├── index2.html    后续文章列表页
    ├── post_ID.html   各篇文章的静态页面
    └── images/        转换后的 GIF 图片
```

### 编码约定

- `generate.php` 使用 GBK/CP936 编码；
- `template.html` 必须使用 GBK/CP936 编码；
- 最终生成的 HTML 使用 GB2312 编码；
- `README.md` 使用 GitHub 通用的 UTF-8 编码。

请勿使用会自动把所有文件转换为 UTF-8 的格式化工具改写 `generate.php` 或 `template.html`。

## 使用手册

### 1. 准备运行环境

需要以下软件：

- 可运行当前 WordPress 站点的 PHP CLI；
- PHP DOM 扩展；
- PHP iconv 扩展；
- ImageMagick，且 PHP 的 `exec()` 函数可以调用其命令行程序；
- 一个可正常连接数据库并加载的 WordPress 安装。

Debian/Ubuntu 可使用以下命令安装常见依赖：

```bash
sudo apt update
sudo apt install php-cli php-xml imagemagick
```

检查依赖：

```bash
php -m | grep -Ei 'dom|iconv'
convert -version
```

`gd` 扩展不是本程序的必需依赖。若 PHP 提示 `Module "gd" is already loaded`，表示 PHP 配置中重复加载了 GD，需要删除其中一条重复的 `extension=gd` 配置。

### 2. 配置首页模板

项目已经提供 `template.html`，默认命令可以直接使用。修改站点名称、联系方式或页面布局时，请使用支持 GBK 的编辑器保存文件，并原样保留以下两组生成标记：

```html
<!-- Begin Articles -->
<!-- End Articles -->

<!-- Begin Pagination -->
<!-- End Pagination -->
```

若希望使用另一份模板，可通过 `--template=PATH` 指定；自定义模板同样必须使用 GBK/CP936 编码并包含上述标记。

### 3. 执行导出

如果项目位于 WordPress 根目录，也就是与 `wp-load.php` 同级，可以直接运行：

```bash
./build.sh
```

也可以直接运行 PHP 程序：

```bash
php generate.php
```

如果项目不在 WordPress 根目录，应明确指定 WordPress 路径：

```bash
./build.sh --wordpress-root=/var/www/html
```

也可以使用环境变量：

```bash
WORDPRESS_ROOT=/var/www/html ./build.sh
```

成功后，默认在 `legacy/` 目录中生成站点。将该目录部署到 Web 服务器的独立路径即可，例如 `https://example.com/legacy/`。

### 4. 常用参数

```text
--wordpress-root=PATH  包含 wp-load.php 的 WordPress 根目录
--output=PATH          输出目录，默认为项目目录下的 legacy
--template=PATH        GBK 首页模板，默认为 template.html
--per-page=NUMBER      列表页每页文章数，默认为 5，允许 1 至 100
--help                 显示命令行帮助
```

示例：

```bash
./build.sh \
  --wordpress-root=/var/www/html \
  --template=/var/www/legacy-template.html \
  --output=/var/www/html/legacy \
  --per-page=10
```

如果系统使用 ImageMagick 7，只有 `magick` 而没有 `convert` 命令，可以指定可执行文件：

```bash
IMAGEMAGICK_CONVERT=/usr/bin/magick ./build.sh --wordpress-root=/var/www/html
```

### 5. 注意事项

- 导出过程会读取 WordPress 数据库并访问文章中的图片 URL；请确保 CLI 环境能够连接数据库和访问图片。
- 图片会转换为最多 64 色的 GIF；正文图片最大宽度为 500 像素，列表页特色图片最大宽度为 300 像素。
- 每次运行都会覆盖对应的生成页面，并清理输出目录中已失效的生成文件。不要把手工维护且符合 `post_ID.html`、`indexN.html` 或程序图片命名规则的文件放进输出目录。
- 若 PHP 禁用了 `exec()`，图片转换会失败，但文字页面仍会继续生成，并在结束时报告图片警告数量。
- 建议先在测试目录导出并检查结果，再将输出目录发布到正式站点。
