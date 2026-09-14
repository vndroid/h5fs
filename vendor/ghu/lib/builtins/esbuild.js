const esbuild = require('esbuild');
const each = require('../actions/each');

const emptyJsdom = {
    name: 'empty-jsdom',
    setup(build) {
        build.onResolve({filter: /^jsdom$/}, () => ({path: 'jsdom', namespace: 'empty'}));
        build.onLoad({filter: /.*/, namespace: 'empty'}, () => ({contents: 'module.exports = {};'}));
    }
};

module.exports = options => each(obj => {
    const settings = Object.assign({
        bundle: true,
        format: 'iife',
        platform: 'browser',
        target: 'es2017',
        minify: false,
        sourcemap: false,
        legalComments: 'none'
    }, options, {
        stdin: {
            contents: obj.content,
            resolveDir: require('path').dirname(obj.source),
            sourcefile: obj.source
        },
        plugins: [...(options && options.plugins || []), emptyJsdom],
        write: false
    });
    return esbuild.build(settings).then(result => {
        obj.content = result.outputFiles[0].text;
    });
});
