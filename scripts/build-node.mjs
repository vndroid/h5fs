import fs from 'node:fs/promises';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
import {createRequire} from 'node:module';
import {glob} from 'glob';
import * as esbuild from 'esbuild';
import less from 'less';
import postcss from 'postcss';
import autoprefixer from 'autoprefixer';
import pug from 'pug';

const require = createRequire(import.meta.url);
const includeit = require('./lib/include.cjs');
const cssmin = require('cssmin');
const createArchive = require('./lib/archive.cjs');
const ROOT = path.resolve(import.meta.dirname, '..');
const SRC = path.join(ROOT, 'src');
const TEST = path.join(ROOT, 'test');
const BUILD = path.resolve(process.env.H5FS_BUILD_DIR || path.join(ROOT, 'build-node'));
const pkg = JSON.parse(await fs.readFile(path.join(ROOT, 'package.json'), 'utf8'));

function buildVersion() {
    if (process.env.H5FS_VERSION) return process.env.H5FS_VERSION;
    try {
        const hashes = execFileSync('git', ['rev-list', `v${pkg.version}..HEAD`], {cwd: ROOT, encoding: 'utf8'}).trim().split(/\r?\n/).filter(Boolean);
        return hashes.length ? `${pkg.version}+${String(hashes.length).padStart(3, '0')}~${hashes[0].slice(0, 7)}` : pkg.version;
    } catch { return pkg.version; }
}

const version = buildVersion();
const comment = `${pkg.name} v${version} - ${pkg.homepage}`;
const commentJs = `/* ${comment} */\n`;
const commentHtml = `<!-- ${comment} -->`;
const emptyJsdom = {name: 'empty-jsdom', setup(build) {
    build.onResolve({filter: /^jsdom$/}, () => ({path: 'jsdom', namespace: 'empty'}));
    build.onLoad({filter: /.*/, namespace: 'empty'}, () => ({contents: 'module.exports = {};'}));
}};

async function write(dest, content) {
    await fs.mkdir(path.dirname(dest), {recursive: true});
    await fs.writeFile(dest, content);
}
async function bundle(source, minify = false) {
    const result = await esbuild.build({stdin: {contents: await fs.readFile(source, 'utf8'), resolveDir: path.dirname(source), sourcefile: source},
        bundle: true, format: 'iife', platform: 'browser', target: 'es2017', minify, sourcemap: false,
        legalComments: 'none', plugins: [emptyJsdom], write: false});
    return result.outputFiles[0].text;
}
async function allFiles(pattern, cwd = ROOT) {
    return (await glob(pattern, {cwd, absolute: true, dot: true, nodir: true})).sort();
}
function mapped(source) {
    return path.join(BUILD, path.relative(SRC, source)).replace(/\.less$/, '.css').replace(/\.pug$/, '');
}
function textOrBuffer(buffer) {
    for (const char of buffer.toString('utf8', 0, 24)) {
        const code = char.charCodeAt(0);
        if (code <= 8 || code === 65533) return buffer;
    }
    return buffer.toString('utf8');
}

await fs.rm(BUILD, {recursive: true, force: true});

const mainJs = path.join(SRC, '_h5fs/public/js/scripts.js');
let scripts = await bundle(mainJs, true);
scripts = includeit({file: mainJs, content: `\n\n// @include "pre.js"\n\n${scripts}`, charset: 'utf-8'});
await write(mapped(mainJs), commentJs + scripts);

for (const source of await allFiles('_h5fs/public/css/*.less', SRC)) {
    let css = includeit({file: source, content: await fs.readFile(source, 'utf8'), charset: 'utf-8'});
    css = (await less.render(css, {paths: [path.dirname(source)], filename: source, syncImport: false, async: false, fileAsync: false, silent: false, verbose: false, ieCompat: true, compress: false, cleancss: false, sourceMap: false})).css;
    css = (await postcss([autoprefixer]).process(css, {from: source})).css;
    await write(mapped(source), commentJs + cssmin(css, -1));
}

for (const source of (await allFiles('**/*.pug', SRC)).filter(file => !file.endsWith('.tpl.pug'))) {
    const html = pug.compile(await fs.readFile(source, 'utf8'), {filename: source})({pkg: {...pkg, version}});
    await write(mapped(source), html + commentHtml);
}

const sourceFiles = await allFiles('**', SRC);
for (const source of sourceFiles) {
    const rel = path.relative(SRC, source);
    if (/\.js$|\.less$|\.pug$|\.DS_Store$/.test(rel)) continue;
    let content = textOrBuffer(await fs.readFile(source));
    if (/[/\\]conf[/\\][^/\\]+\.json$/.test(source)) content = commentJs + content;
    if (/index\.php$/.test(source)) content = content.replace('{{VERSION}}', version);
    await write(mapped(source), content);
}
for (const source of await allFiles('*.md')) await write(path.join(BUILD, '_h5fs', path.basename(source)), await fs.readFile(source));

const css = await fs.readFile(path.join(BUILD, '_h5fs/public/css/styles.css'));
await write(path.join(BUILD, 'test/h5fs-styles.css'), css);
await write(path.join(BUILD, 'test/index.html'), await fs.readFile(path.join(TEST, 'index.html')));
const testSource = path.join(TEST, 'index.js');
let testJs = await bundle(testSource);
testJs = includeit({file: testSource, content: `\n\n// @include "${SRC}/**/js/pre.js"\n\n${testJs}`, charset: 'utf-8'});
await write(path.join(BUILD, 'test/index.js'), testJs);

await createArchive(BUILD, path.join(BUILD, `${pkg.name}-${version}.zip`));
console.log(`built ${path.relative(ROOT, BUILD)} (${version})`);
