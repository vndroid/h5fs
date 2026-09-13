const createDOMPurify = require('dompurify');

const purifier = createDOMPurify(global.window);

const sanitize_html = html => purifier.sanitize(html);

module.exports = {
    sanitizeHtml: sanitize_html
};
