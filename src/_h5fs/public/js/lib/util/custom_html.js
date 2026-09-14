const {marked} = require('marked');
const {sanitizeHtml} = require('./sanitize_html');

const render_custom_html = (content, type) => {
    const html = type === 'md' ? marked(content) : content;
    return sanitizeHtml(html);
};

module.exports = {
    renderCustomHtml: render_custom_html
};
