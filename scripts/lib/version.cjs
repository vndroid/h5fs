const {execFileSync} = require('node:child_process');
const fs = require('node:fs');

const git = (cwd, ...args) => {
    try {
        return execFileSync('git', args, {
            cwd,
            encoding: 'utf8',
            stdio: ['ignore', 'pipe', 'ignore']
        }).trim();
    } catch {
        return null;
    }
};

module.exports = (baseVersion, cwd, env = process.env) => {
    if (env.H5FS_VERSION !== undefined) {
        const version = env.H5FS_VERSION;
        if (!/^[0-9A-Za-z][0-9A-Za-z._+~-]{0,127}$/.test(version)) {
            throw new Error('H5FS_VERSION must be 1-128 characters using letters, digits, dots, underscores, +, ~ or -');
        }
        return version;
    }

    const repositoryRoot = git(cwd, 'rev-parse', '--show-toplevel');
    if (!repositoryRoot || fs.realpathSync(repositoryRoot) !== fs.realpathSync(cwd)) return baseVersion;

    const head = git(cwd, 'rev-parse', '--verify', 'HEAD');
    if (!head || !/^[0-9a-f]{40,64}$/.test(head)) return baseVersion;

    if (git(cwd, 'rev-parse', '--is-shallow-repository') === 'false') {
        const tag = git(cwd, 'rev-parse', '--verify', '--quiet', `refs/tags/v${baseVersion}^{commit}`);
        if (tag && git(cwd, 'merge-base', '--is-ancestor', tag, 'HEAD') !== null) {
            const count = git(cwd, 'rev-list', '--count', `${tag}..HEAD`);
            if (count === '0') return baseVersion;
            if (count && /^\d+$/.test(count)) {
                return `${baseVersion}+${count.padStart(3, '0')}~${head.slice(0, 7)}`;
            }
        }
    }

    return `${baseVersion}+git~${head.slice(0, 12)}`;
};
