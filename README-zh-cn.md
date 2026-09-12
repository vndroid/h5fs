# h5fs 构建说明

服务端运行环境需要 PHP 8.2 或更高版本。

## 版本替换

构建出来的版本号自带标识，使用命令进行去除：

```sh
cd _h5fs
find . -type f -exec sed -i 's/0.30.0+000~0000000/0.30.0/g' {} \;
```

## 打包

使用 Node.js 22.18+ 或 24.11+ 安装依赖并构建：

```sh
npm ci
npm run build
```

构建完成后，可在 `build` 目录中找到 `h5fs-<version>.zip`。
