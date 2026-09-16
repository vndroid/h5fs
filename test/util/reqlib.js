// explicit extensions, so bundlers (esbuild) can resolve the dynamic requires
const reqlib = x => {
    try {
        return require(`../../src/_h5fs/public/js/lib/${x}.js`);
    } catch {
        return require(`../../src/_h5fs/public/js/lib/${x}/index.js`);
    }
};

module.exports = reqlib;
