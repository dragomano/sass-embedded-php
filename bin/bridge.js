#!/usr/bin/env node
import { compileString } from "sass-embedded";

let stdin = "";
process.stdin.setEncoding("utf8");
process.stdin.on("data", chunk => stdin += chunk);
process.stdin.on("end", () => {
  try {
    const payload = JSON.parse(stdin || "{}");
    const source = String(payload.source || "");
    const options = payload.options || {};
    const url = payload.url ? new URL(String(payload.url)) : undefined;
    const compileOpts = {};

    if (url) compileOpts.url = url;

    if (options.syntax === 'sass' || options.syntax === 'indented') {
      compileOpts.syntax = 'indented';
    }

    if (options.minimize || ('compressed' in options && options.compressed) || options.style === 'compressed') {
      compileOpts.style = 'compressed';
    }

    if (options.sourceMap) {
      compileOpts.sourceMap = options.sourceMap;

      if (options.sourceMapIncludeSources) {
        compileOpts.sourceMapIncludeSources = options.sourceMapIncludeSources;
      }
    }

    const result = compileString(source, compileOpts);

    process.stdout.write(JSON.stringify({
      css: result.css,
      sourceMap: result.sourceMap || null,
    }));
  } catch (err) {
    process.stdout.write(JSON.stringify({ error: String(err?.message || err) }));
  }
});
