"use strict";

const fs = require("node:fs");
const path = require("node:path");
const inventory = require("./paid-feature-boundaries.json");

// Graph ownership detects lib imports; markers also detect reviewed implementations
// copied or left in shared sources. Neither replaces a feature-by-feature audit.
function assertFreeImplementation(output, code) {
  const kind = path.extname(output).slice(1);
  for (const rule of inventory.forbiddenFreeImplementations) {
    if (rule.kind === kind && new RegExp(rule.pattern).test(code)) {
      throw new Error(`Free bundle ${output} contains reviewed paid ${rule.module} implementation.`);
    }
  }
}

function assertBundleOwnership(result, root, paidOutputs) {
  if (!paidOutputs) {
    const header = fs.readFileSync(path.join(root, "gpt3-ai-content-generator.php"), "utf8");
    const declaration = header.match(/@fs_premium_only\s+([^\r\n]+)/);
    paidOutputs = new Set((declaration?.[1] || "").split(",").map(value => value.trim().replace(/^\//, "")));
  }
  for (const [output, details] of Object.entries(result.metafile.outputs)) {
    const relative = path.isAbsolute(output) ? path.relative(root, output) : output;
    const paidInputs = Object.keys(details.inputs).filter(input =>
      (path.isAbsolute(input) ? path.relative(root, input) : input).startsWith("lib/")
    );
    if (paidOutputs.has(relative)) continue;
    if (paidInputs.length) {
      throw new Error(`Free bundle ${relative} includes paid sources: ${paidInputs.join(", ")}`);
    }
    const inMemory = result.outputFiles?.find(file => path.resolve(file.path) === path.resolve(root, output));
    const code = inMemory ? Buffer.from(inMemory.contents).toString("utf8") : fs.readFileSync(path.resolve(root, output), "utf8");
    assertFreeImplementation(relative, code);
  }
}

module.exports = { assertBundleOwnership, assertFreeImplementation };
