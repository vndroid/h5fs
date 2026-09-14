const {minify} = require('html-minifier-terser');
const each = require('../actions/each');

module.exports = options => {
    const settings = Object.assign({}, options);

    return each(obj => {
        return minify(obj.content, settings).then(content => {
            obj.content = content;
        });
    });
};
