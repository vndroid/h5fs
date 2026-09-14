const fs = require('fs/promises');
const path = require('path');
const {glob} = require('glob');
const JSZip = require('jszip');

module.exports = async (buildDir, target) => {
    const files = (await glob('_h5fs/**', {cwd: buildDir, absolute: true, dot: true, nodir: true})).sort();
    const zip = new JSZip();
    for (const source of files) {
        zip.file(path.relative(buildDir, source), await fs.readFile(source), {
            createFolders: false,
            date: new Date('2000-01-01T00:00:00.000Z')
        });
    }
    const content = await zip.generateAsync({
        type: 'nodebuffer',
        compression: 'DEFLATE',
        compressionOptions: {level: 9}
    });
    await fs.mkdir(path.dirname(target), {recursive: true});
    await fs.writeFile(target, content);
};
