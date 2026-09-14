import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import {glob} from 'glob';

const root = path.resolve(import.meta.dirname, '..');
const left = path.resolve(process.env.H5FS_BUILD_LEFT || path.join(root, 'build-node'));
const right = path.resolve(process.env.H5FS_BUILD_RIGHT || path.join(root, 'build-ghu'));
const files = async dir => (await glob('**', {cwd: dir, nodir: true, dot: true})).sort();
const [a, b] = await Promise.all([files(left), files(right)]);
if (JSON.stringify(a) !== JSON.stringify(b)) throw new Error(`file lists differ\nleft-only: ${a.filter(x => !b.includes(x)).join(', ')}\nright-only: ${b.filter(x => !a.includes(x)).join(', ')}`);
for (const file of a) {
    const [x, y] = await Promise.all([fs.readFile(path.join(left, file)), fs.readFile(path.join(right, file))]);
    if (!x.equals(y)) throw new Error(`content differs: ${file}`);
}
const digest = crypto.createHash('sha256');
for (const file of a) digest.update(file).update(await fs.readFile(path.join(left, file)));
console.log(`identical: ${a.length} files, sha256 ${digest.digest('hex')}`);
