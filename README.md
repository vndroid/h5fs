# h5fs

[![license][license-img]][github] [![web][web-img]][web] [![github][github-img]][github]

A modern HTML5 file sharing directory index for Apache httpd, lighttpd, and nginx.


## Important

* Do **not** install any files from the `src` folder, they need to be
  preprocessed to work correctly!
* Find a preprocessed package and detailed install instructions on the
  [project page][web].
* For bug reports and feature requests please use [issues][github-issues].

The server runtime requires PHP 8.2 or later.


## Build

There are installation ready packages for the latest [releases][release] and
[dev builds][develop]. But to build **h5fs** yourself either `git clone` or
download the repository. From within the root folder run the following
commands to find a fresh zipball in folder `build-node` (tested on linux only,
requires [`Node.js 24.18+`][node] to be installed).
JavaScript source uses ES modules (`import`/`export`) and allows ES2024 syntax.
The build bundles it into a single browser script targeting ES2020 syntax, so
deployment does not require native module loading. Browsers must support the
output script's syntax and APIs; esbuild does not polyfill missing APIs.

Pushing a `vX.X.X` tag (three numeric components) triggers a GitHub Actions
release only when the tagged commit is in `main` history. The workflow builds
with the tag version and uploads the ZIP to the matching GitHub Release. Other
tags are skipped. The tagged commit must contain the workflow; existing older
tags will not trigger it retroactively.

~~~sh
npm ci
npm run build
~~~

Set `H5FS_VERSION` to choose the version embedded in the pages and package
filename:

~~~sh
H5FS_VERSION=0.30.0 npm run build
~~~

The same variable works with `npm run build:node` and `npm run build:ghu`.
The value must be 1–128 characters long, start with a letter or digit, and
otherwise contain only letters, digits, `.`, `_`, `+`, `~`, or `-`. If it is
unset, a full Git checkout with a
`v<package-version>` tag uses `<package-version>+<commits>~<short-hash>`;
an exact tagged checkout uses the package version. A shallow checkout or a
checkout without that tag uses `<package-version>+git~<12-character-hash>`.
A source archive without Git metadata uses the package version. The build
never fetches tags. Both build commands use the same version rules; run
`npm run build:compare` after both builds to compare their output.


## License

The MIT License (MIT)

Copyright (c) 2020 Lars Jung (https://larsjung.de)

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.


## References

**h5fs** is based on the original **h5ai** project and profits from other projects,
all of them licensed under the MIT license too. Exceptions are some
[Material Design icons][material-design-icons] (CC BY 4.0).


[web]: https://larsjung.de/h5ai/
[github]: https://github.com/lrsjng/h5ai
[github-issues]: https://github.com/lrsjng/h5ai/issues
[release]: https://release.larsjung.de/h5ai/
[develop]: https://release.larsjung.de/h5ai/develop/
[node]: https://nodejs.org
[material-design-icons]: https://github.com/google/material-design-icons

[license-img]: https://img.shields.io/badge/license-MIT-a0a060.svg?style=flat-square
[web-img]: https://img.shields.io/badge/web-larsjung.de/h5ai-a0a060.svg?style=flat-square
[github-img]: https://img.shields.io/badge/github-lrsjng/h5ai-a0a060.svg?style=flat-square
