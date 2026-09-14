const {each, dom, renderCustomHtml} = require('../util');
const server = require('../server');
const event = require('../core/event');
const allsettings = require('../core/settings');


const settings = Object.assign({
    enabled: false
}, allsettings.custom);

const update = (data, key) => {
    const $el = dom(`#content-${key}`);

    if (data && data[key].content) {
        $el.html(renderCustomHtml(data[key].content, data[key].type)).show();
    } else {
        $el.hide();
    }
};

const onLocationChanged = item => {
    server.request({action: 'get', custom: item.absHref}).then(response => {
        const data = response && response.custom;
        each(['header', 'footer'], key => update(data, key));
    });
};

const init = () => {
    if (!settings.enabled) {
        return;
    }

    dom('<div id="content-header"></div>').hide().preTo('#content');
    dom('<div id="content-footer"></div>').hide().appTo('#content');

    event.sub('location.changed', onLocationChanged);
};


init();
