// Copies the Lucide icons referenced anywhere in the PHP code into ../app/icons, so the deployed app needs no node_modules.
// Any quoted token that matches a Lucide icon file name counts as a reference.
import fs from "node:fs"; import path from "node:path";
const root = path.resolve(import.meta.dirname, "..");
const src = path.join(import.meta.dirname, "node_modules/lucide-static/icons"); const dst = path.join(root, "app/icons");
const available = new Set(fs.readdirSync(src).filter((f) => f.endsWith(".svg")).map((f) => f.slice(0, -4)));
const used = new Set();
const walk = (d) => { for (const f of fs.readdirSync(d, { withFileTypes: true })) { const p = path.join(d, f.name); if (f.isDirectory()) { if (!["icons", "tools", "node_modules"].includes(f.name)) walk(p); } else if (f.name.endsWith(".php")) { for (const m of fs.readFileSync(p, "utf8").matchAll(/'([a-z0-9]+(?:-[a-z0-9]+)*)'/g)) if (available.has(m[1])) used.add(m[1]); } } };
walk(root);
fs.rmSync(dst, { recursive: true, force: true }); fs.mkdirSync(dst, { recursive: true });
for (const n of used) fs.copyFileSync(path.join(src, n + ".svg"), path.join(dst, n + ".svg"));
console.log(`copied ${used.size} icons`);
