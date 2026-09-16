// explicit extensions, so bundlers (esbuild) can resolve the dynamic requires
const reqlib = x => {
    const file = `../../src/_h5fs/public/js/lib/${x}.js`;
    try {
        return require(`../../src/_h5fs/public/js/lib/${x}.js`);
    } catch (err) {
        const missingInBundle = err && err.message === `Module not found in bundle: ${file}`;
        const missingInNode = err && err.code === 'MODULE_NOT_FOUND' &&
            err.message.startsWith(`Cannot find module '${file}'`);
        if (!missingInBundle && !missingInNode) throw err;
        return require(`../../src/_h5fs/public/js/lib/${x}/index.js`);
    }
};

module.exports = reqlib;
