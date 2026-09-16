const LIB = '../../src/_h5fs/public/js/lib';

// Only a "module not found" error for exactly the requested file is a reason
// to try `<x>/index.js`. Node ("Cannot find module '<file>'") and esbuild
// ("Module not found in bundle: <file>") both name the requested path in the
// first line of the message; any other error (e.g. thrown while loading the
// module, or a missing dependency of it) names something else and is rethrown.
const isNotFound = (err, file) => {
    const firstLine = String(err && err.message).split('\n')[0];
    return firstLine.includes(file) && (err.code === 'MODULE_NOT_FOUND' || (/not found/i).test(firstLine));
};

const reqlib = x => {
    // NOTE: keep the full path template literals inside `require(...)`.
    // esbuild only bundles dynamic requires whose path is written inline as a
    // template literal; `require(file)` would not be resolvable in the bundle.
    try {
        return require(`../../src/_h5fs/public/js/lib/${x}.js`);
    } catch (err) {
        if (!isNotFound(err, `${LIB}/${x}.js`)) {
            throw err;
        }
        return require(`../../src/_h5fs/public/js/lib/${x}/index.js`);
    }
};

module.exports = reqlib;
