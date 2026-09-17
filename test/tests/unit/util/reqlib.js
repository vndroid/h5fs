const {test, assert} = require('scar');
const {isNotFound} = require('../../../util/reqlib');

test('util.reqlib() only falls back for a missing target', () => {
    const file = '../../src/_h5fs/public/js/lib/util.js';
    const nodeMissing = new Error(`Cannot find module '${file}'\nRequire stack:`);
    nodeMissing.code = 'MODULE_NOT_FOUND';
    assert.ok(isNotFound(nodeMissing, file));
    assert.ok(isNotFound(new Error(`Module not found in bundle: ${file}`), file));

    const dependencyMissing = new Error(`Cannot find module '${file}/dependency'`);
    dependencyMissing.code = 'MODULE_NOT_FOUND';
    assert.ok(!isNotFound(dependencyMissing, file));
    assert.ok(!isNotFound(new Error(`Resource not found while initializing ${file}`), file));
    assert.ok(!isNotFound(new Error(`Module not found in bundle: ${file}/dependency`), file));
    assert.ok(!isNotFound(null, file));
});
