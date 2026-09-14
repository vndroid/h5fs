const {resolve, join} = require('path');
const {
    ghu, autoprefixer, cssmin, each, esbuild, ife, less, mapfn,
    pug, read, remove, run, wrap, write
} = require('ghu');

const ROOT = resolve(__dirname);
const SRC = join(ROOT, 'src');
const TEST = join(ROOT, 'test');
const BUILD = resolve(process.env.H5FS_BUILD_DIR || join(ROOT, 'build-ghu'));
const createArchive = require('./scripts/lib/archive.cjs');
const expandIncludes = require('./scripts/lib/include.cjs');
const includeit = () => each(obj => { obj.content = expandIncludes({file: obj.source, content: obj.content}); });
const mapper = mapfn.p(SRC, BUILD).s('.less', '.css').s('.pug', '');

ghu.defaults('release');
ghu.before(runtime => {
    runtime.pkg = Object.assign({}, require('./package.json'));
    const forcedVersion = process.env.H5FS_VERSION;
    if (forcedVersion) {
        runtime.pkg.version = forcedVersion;
    } else {
        const res = run.sync(`git rev-list v${runtime.pkg.version}..HEAD`, {silent: true});
        if (res.code === 0) {
            const hashes = res.stdout.split(/\r?\n/).filter(Boolean);
            if (hashes.length) {
                runtime.pkg.version += `+${('000' + hashes.length).slice(-3)}~${hashes[0].slice(0, 7)}`;
            }
        }
    }
    runtime.comment = `${runtime.pkg.name} v${runtime.pkg.version} - ${runtime.pkg.homepage}`;
    runtime.comment_js = `/* ${runtime.comment} */\n`;
    runtime.comment_html = `<!-- ${runtime.comment} -->`;
});

ghu.task('force-production', runtime => { runtime.args.production = true; });
ghu.task('clean', () => remove(BUILD));
ghu.task('build:scripts', runtime => read(`${SRC}/_h5fs/public/js/scripts.js`)
    .then(esbuild({minify: runtime.args.production}))
    .then(wrap('\n\n// @include "pre.js"\n\n')).then(includeit())
    .then(wrap(runtime.comment_js)).then(write(mapper, {overwrite: true})));
ghu.task('build:styles', runtime => read(`${SRC}/_h5fs/public/css/*.less`)
    .then(includeit()).then(less()).then(autoprefixer())
    .then(ife(() => runtime.args.production, cssmin()))
    .then(wrap(runtime.comment_js)).then(write(mapper, {overwrite: true})));
ghu.task('build:pages', runtime => read(`${SRC}: **/*.pug, ! **/*.tpl.pug`)
    .then(pug({pkg: runtime.pkg})).then(wrap('', runtime.comment_html))
    .then(write(mapper, {overwrite: true})));
ghu.task('build:copy', runtime => {
    const mapperRoot = mapfn.p(ROOT, join(BUILD, '_h5fs'));
    return Promise.all([
        read(`${SRC}/**/conf/*.json`).then(wrap(runtime.comment_js))
            .then(write(mapper, {overwrite: true, cluster: true})),
        read(`${SRC}: **, ! **/*.js, ! **/*.less, ! **/*.pug, ! **/conf/*.json, ! **/.DS_Store`)
            .then(each(obj => { if ((/index\.php$/).test(obj.source)) obj.content = obj.content.replace('{{VERSION}}', runtime.pkg.version); }))
            .then(write(mapper, {overwrite: true, cluster: true})),
        read(`${ROOT}/*.md`).then(write(mapperRoot, {overwrite: true, cluster: true}))
    ]);
});
ghu.task('build:tests', ['build:styles'], () => Promise.all([
    read(`${BUILD}/_h5fs/public/css/styles.css`).then(write(`${BUILD}/test/h5fs-styles.css`, {overwrite: true})),
    read(`${TEST}/index.html`).then(write(`${BUILD}/test/index.html`, {overwrite: true})),
    read(`${TEST}: index.js`).then(esbuild())
        .then(wrap(`\n\n// @include "${SRC}/**/js/pre.js"\n\n`)).then(includeit())
        .then(write(mapfn.p(TEST, `${BUILD}/test`), {overwrite: true}))
]));
ghu.task('build', ['build:scripts', 'build:styles', 'build:pages', 'build:copy', 'build:tests']);
ghu.task('release', ['force-production', 'clean', 'build'], runtime => {
    const target = join(BUILD, `${runtime.pkg.name}-${runtime.pkg.version}.zip`);
    return createArchive(BUILD, target);
});
