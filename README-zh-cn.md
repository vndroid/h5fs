# h5fs 构建说明

服务端运行环境需要 PHP 8.2 或更高版本。

## 打包

使用 Node.js 24.18 或更高版本安装依赖并构建。JavaScript 源码使用 ES 模块
（`import`/`export`）并允许使用 ES2024 语法，构建后仍打包为单个浏览器脚本，
输出语法目标为 ES2020。浏览器无需原生模块加载能力，但需支持输出脚本使用的
语法和 API；esbuild 不会自动补齐缺失的 API。

```sh
npm ci
npm run build
```

构建完成后，可在 `build-node` 目录中找到 `h5fs-<version>.zip`。

推送形如 `vX.X.X`（三段数字）的标签时，GitHub Actions 会确认该标签指向
`main` 历史中的提交，再以标签版本号构建并把 ZIP 上传到对应的 GitHub Release。
其他标签或不属于 `main` 的标签不会发布。标签指向的提交必须包含发布工作流；
已存在的旧标签不会因新增工作流而自动触发。

如需指定页面和压缩包使用的版本号，在构建命令前设置 `H5FS_VERSION`：

```sh
H5FS_VERSION=0.30.0 npm run build
```

`npm run build:node` 和 `npm run build:ghu` 都支持该环境变量。版本号长度为
1～128 个字符，首字符必须是英文字母或数字，其余字符只能包含英文字母、数字、
`.`、`_`、`+`、`~` 和 `-`。
未设置时，完整 Git 仓库若含有对应的 `v<package-version>` 标签，会使用
`<package-version>+<提交数>~<短哈希>`；恰好位于标签上则仅使用包版本号。
浅克隆或缺少标签时使用 `<package-version>+git~<12 位哈希>`；不含 Git 元数据的
源码压缩包使用包版本号。构建过程不会自动拉取标签。需要比较两种构建的结果时，
依次运行 `npm run build:node`、`npm run build:ghu` 和 `npm run build:compare`。
