"use strict";

const fs = require("node:fs/promises");
const path = require("node:path");
const esbuild = require("esbuild");
const { minify: terserMinify } = require("terser");

function createBuildOptions({ root, minify = true, watch = false }) {
  const common = { absWorkingDir: root, bundle: true, sourcemap: watch ? "inline" : false, minify, logLevel: "silent", entryNames: "[name].bundle" };
  return {
    js: { ...common, target: "es2020", outdir: "dist/js" },
    css: { ...common, outdir: "dist/css", loader: { ".svg": "dataurl", ".png": "file", ".jpg": "file", ".woff": "file", ".woff2": "file", ".ttf": "file" }, assetNames: "../assets/[name]-[hash]" },
  };
}

// Both the private build and the public free-source build use this implementation.
// Build in memory so ownership checks finish before any outputs are written.
async function buildAssets({ root, manifest, minify = true, watch = false, write = true, inspectResult = () => {} }) {
  root = await fs.realpath(root);
  const options = createBuildOptions({ root, minify, watch });
  const inputs = new Set();
  const outputs = new Map();
  const jobs = [];
  for (const kind of ["js", "css"]) {
    for (const [name, entry] of Object.entries(manifest[kind])) {
      jobs.push(esbuild.build({ ...options[kind], entryPoints: { [name]: entry }, write: false, metafile: true }).then(async (result) => {
        inspectResult(result);
        for (const input of Object.keys(result.metafile.inputs)) inputs.add(input);
        for (const file of result.outputFiles) {
          const relative = path.relative(root, file.path).split(path.sep).join("/");
          let contents = Buffer.from(file.contents);
          if (minify && relative.endsWith(".bundle.js")) {
            const result = await terserMinify(contents.toString("utf8"), { ecma: 2020, compress: { passes: 3, drop_console: true, toplevel: true }, mangle: { toplevel: true }, format: { comments: false } });
            if (!result.code) throw new Error(`Minification produced no output for ${relative}`);
            contents = Buffer.from(result.code);
          }
          const previous = outputs.get(relative);
          if (previous && !previous.equals(contents)) throw new Error(`Conflicting build output: ${relative}`);
          outputs.set(relative, contents);
        }
      }));
    }
  }
  await Promise.all(jobs);
  for (const vendor of manifest.vendor) {
    inputs.add(vendor.from);
    outputs.set(vendor.to, await fs.readFile(path.join(root, vendor.from)));
  }
  if (write) {
    for (const [relative, contents] of outputs) {
      const destination = path.join(root, relative);
      await fs.mkdir(path.dirname(destination), { recursive: true });
      await fs.writeFile(destination, contents);
    }
  }
  return { inputs: [...inputs].sort(), outputs };
}

module.exports = { createBuildOptions, buildAssets };
