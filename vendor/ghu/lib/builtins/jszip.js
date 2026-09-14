const path = require('path');
const JsZip = require('jszip');

module.exports = options => {
    return objs => Promise.resolve().then(() => {
        const settings = Object.assign({
            dir: process.cwd(),
            level: 0,
            date: new Date('2000-01-01T00:00:00.000Z')
        }, options);
        settings.dir = path.resolve(settings.dir);

        const jszip = new JsZip();
        objs.sort((a, b) => a.source < b.source ? -1 : a.source > b.source ? 1 : 0).forEach(obj => {
            const content = Buffer.isBuffer(obj.content) ? obj.content : Buffer.from(obj.content, 'utf8');
            jszip.file(path.relative(settings.dir, obj.source), content, {date: settings.date});
        });

        const gen_opts = {
            type: 'nodebuffer'
        };
        if (settings.level) {
            gen_opts.compression = 'DEFLATE';
            gen_opts.compressionOptions = {level: settings.level};
        }

        return jszip.generateAsync(gen_opts).then(zipped => {
            return [{source: '@jszip', content: zipped}];
        });
    });
};
