# AI Puffer — Free source

Public source and build tools for the free WordPress plugin, version **2.4.96**.

This snapshot includes the Freemius-generated free PHP edition and the original sources for its bundled assets.

## Build and verify

Use Node.js 20 or later:

```sh
npm ci
npm run build
npm run verify
```

Original JavaScript and CSS are under `admin/js`, `admin/css`, `public/js` and `public/css`. The build manifest contains only free entrypoints. `release-source.json` records the source inventory, bundle checksums and, for a complete release, the processed free ZIP checksum. Verification rejects unexpected files and changed source or bundle bytes.

## Releases

Version tags such as `v2.4.96` identify complete snapshots verified against the matching Freemius-generated free package. The private development repository remains the source of development; this public repository is generated from releases. Premium implementations and private development history are excluded.

Install the released free plugin from [WordPress.org](https://wordpress.org/plugins/gpt3-ai-content-generator/). Documentation: [docs.aipower.org](https://docs.aipower.org/).

## Third-party source

See [THIRD-PARTY.md](THIRD-PARTY.md) for the readable Markdown library source, license and exact upstream version. Build dependencies and their versions are recorded in `package-lock.json`.

## License

AI Puffer is GPL-2.0-or-later; see [LICENSE.txt](LICENSE.txt). Third-party libraries retain their own compatible licenses.
