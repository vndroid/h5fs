import {test} from 'node:test';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {pathToFileURL} from 'node:url';
import buildVersion from '../scripts/lib/version.js';

const git = (...args) => execFileSync('git', args, {encoding: 'utf8'}).trim();
const commit = (repo, message) => git('-C', repo,
    '-c', 'user.name=h5fs', '-c', 'user.email=h5fs@example.invalid', '-c', 'commit.gpgsign=false',
    'commit', '--allow-empty', '-q', '-m', message);

test('build versions in full, shallow, untagged, and non-Git sources', () => {
    const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'h5fs-version-'));
    try {
        const repo = path.join(temp, 'repo');
        const shallow = path.join(temp, 'shallow');
        const sourceArchive = path.join(temp, 'source-archive');
        fs.mkdirSync(sourceArchive);
        git('init', '-q', repo);
        commit(repo, 'tagged release');
        git('-C', repo, 'tag', 'v0.30.0');
        assert.equal(buildVersion('0.30.0', repo, {}), '0.30.0');

        commit(repo, 'development change');
        const hash = git('-C', repo, 'rev-parse', 'HEAD');
        assert.notEqual(hash, git('-C', repo, 'rev-parse', 'v0.30.0'));
        assert.equal(git('-C', repo, 'rev-list', '--count', 'v0.30.0..HEAD'), '1');
        assert.equal(buildVersion('0.30.0', repo, {}), `0.30.0+001~${hash.slice(0, 7)}`);

        git('clone', '-q', '--depth', '1', '--no-tags', pathToFileURL(repo).href, shallow);
        assert.equal(git('-C', shallow, 'rev-parse', '--is-shallow-repository'), 'true');
        assert.equal(buildVersion('0.30.0', shallow, {}), `0.30.0+git~${hash.slice(0, 12)}`);

        git('-C', repo, 'tag', '-d', 'v0.30.0');
        assert.equal(buildVersion('0.30.0', repo, {}), `0.30.0+git~${hash.slice(0, 12)}`);
        assert.equal(buildVersion('0.30.0', sourceArchive, {}), '0.30.0');
        assert.equal(buildVersion('0.30.0', sourceArchive, {H5FS_VERSION: '0.30.0-rc.1'}), '0.30.0-rc.1');
        assert.throws(() => buildVersion('0.30.0', repo, {H5FS_VERSION: '../escape'}), /H5FS_VERSION/);
    } finally {
        fs.rmSync(temp, {recursive: true, force: true});
    }
});
