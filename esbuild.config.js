"use strict";

const manifest = require("./scripts/asset-entrypoints.json");
const { buildAssets, createBuildOptions } = require("./scripts/build-assets");
const paidBoundary = require("./scripts/paid-feature-boundaries");

function assertBundleOwnership(result) {
    const paid = manifest.edition === "free" ? new Set() : undefined;
    paidBoundary.assertBundleOwnership(result, __dirname, paid);
}

async function build() {
    const result = await buildAssets({
        root: __dirname,
        manifest,
        minify: process.argv.includes("--minify"),
        watch: process.argv.includes("--watch"),
        inspectResult: assertBundleOwnership,
    });
    console.log(`Built ${result.outputs.size} assets.`);
}

module.exports = { manifest, assertBundleOwnership, buildAssets, createBuildOptions };
if (require.main === module) {
    build().catch(error => {
        console.error(error.message);
        process.exitCode = 1;
    });
}
