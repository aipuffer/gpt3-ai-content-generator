# Third-party source

## markdown-it 14.3.2

The shipped `dist/vendor/js/markdown-it.min.js` is the official npm distribution for this exact version. Its readable browser build, original modules and MIT license are included under `vendor-sources/markdown-it/`. The matching npm package is pinned in `package-lock.json`.

[Full upstream source and build tools](https://github.com/markdown-it/markdown-it/tree/14.3.2). Upstream build: `npm ci`, then `npm run build` in that upstream checkout.

The AI Puffer build copies the official browser distribution to preserve its upstream output. esbuild, Terser and fs-extra are build dependencies; their source, versions and package integrity values are available through the npm locations recorded in the lockfile.
