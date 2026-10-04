"use strict";

const fs = require("node:fs");
const path = require("node:path");
const crypto = require("node:crypto");

const sha256 = bytes => crypto.createHash("sha256").update(bytes).digest("hex");

function listFiles(root, prefix = "") {
  const files = [];
  for (const entry of fs.readdirSync(path.join(root, prefix), { withFileTypes: true })) {
    if (!prefix && [".git", "node_modules"].includes(entry.name)) continue;
    const relative = prefix ? `${prefix}/${entry.name}` : entry.name;
    if (entry.isSymbolicLink()) throw new Error(`Symlink is not allowed: ${relative}`);
    if (entry.isDirectory()) files.push(...listFiles(root, relative));
    else if (entry.isFile()) files.push(relative);
    else throw new Error(`Unsupported file type: ${relative}`);
  }
  return files.sort();
}

function assertPublicPath(relative) {
  if (!relative || relative.includes("\\") || relative.startsWith("/") || relative.split("/").some(part => !part || part === "." || part === "..")) throw new Error(`Unsafe source path: ${relative}`);
  if (/^(?:lib|docs|tests|node_modules|\.git|\.svn|\.claude)(?:\/|$)/.test(relative) || /(?:^|\/)\.env(?:\.|$)|\.DS_Store$|\.(?:zip|log|bak|map)$/.test(relative)) throw new Error(`Private or development file is not allowed: ${relative}`);
}

function assertPublicContent(relative, bytes) {
  if (!/\.(?:m?js|json|php|css|md|txt|ya?ml|svg)$/.test(relative)) return;
  // Only report the path, never the matching credential value.
  if (/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|\bgh[pousr]_[A-Za-z0-9_]{30,}|\bsk-(?:proj-|or-v1-)?[A-Za-z0-9_-]{20,}|\bsk_[A-Za-z0-9_?<>-]{20,}|\bAKIA[A-Z0-9]{16}\b/.test(bytes.toString("utf8"))) throw new Error(`Possible private credential in ${relative}; review before publishing.`);
}

function verifySource(root) {
  const release = JSON.parse(fs.readFileSync(path.join(root, "release-source.json"), "utf8"));
  if (release.format !== 1 || release.edition !== "free" || !["prepared", "complete"].includes(release.status)) throw new Error("Not a supported free-source snapshot.");
  const actual = listFiles(root).filter(file => file !== "release-source.json");
  const expected = Object.keys(release.files).sort();
  if (JSON.stringify(actual) !== JSON.stringify(expected)) throw new Error("Source file inventory changed; create a new verified export.");
  for (const relative of actual) {
    assertPublicPath(relative);
    const bytes = fs.readFileSync(path.join(root, relative));
    assertPublicContent(relative, bytes);
    if (sha256(bytes) !== release.files[relative]) throw new Error(`Source hash mismatch: ${relative}`);
  }
  for (const [relative, hash] of Object.entries(release.bundles)) {
    assertPublicPath(relative);
    if (!relative.startsWith("dist/") || sha256(fs.readFileSync(path.join(root, relative))) !== hash) throw new Error(`Bundle hash mismatch: ${relative}`);
  }
  if (release.status === "complete") {
    const header = fs.readFileSync(path.join(root, "gpt3-ai-content-generator.php"), "utf8");
    if (!/'is_premium'\s*=>\s*false/.test(header) || /'is_premium'\s*=>\s*true/.test(header)) throw new Error("The snapshot is not the Freemius free edition.");
    if (header.match(/\*\s*Version:\s*(\S+)/)?.[1] !== release.version) throw new Error("Free PHP version does not match the source snapshot.");
    if (!/^[a-f0-9]{64}$/.test(release.freeZipSha256 || "")) throw new Error("The processed free archive checksum is missing.");
  }
  return release;
}

function writeReleaseManifest(root, release) {
  const files = {};
  for (const file of listFiles(root)) {
    if (file === "release-source.json") continue;
    assertPublicPath(file);
    const bytes = fs.readFileSync(path.join(root, file));
    assertPublicContent(file, bytes);
    files[file] = sha256(bytes);
  }
  fs.writeFileSync(path.join(root, "release-source.json"), JSON.stringify({ ...release, files }, null, 2) + "\n");
}

module.exports = { sha256, listFiles, assertPublicPath, assertPublicContent, verifySource, writeReleaseManifest };
if (require.main === module) {
  try {
    const release = verifySource(path.resolve(__dirname, ".."));
    console.log(`Verified ${release.version}: ${Object.keys(release.bundles).length} bundles; ${release.status} free source.`);
  } catch (error) {
    console.error(error.message);
    process.exitCode = 1;
  }
}
