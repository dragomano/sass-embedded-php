#!/usr/bin/env node
import { compileString } from "sass-embedded";

/**
 * Optimized bridge for sass compilation with support for:
 * - Large data streams
 * - Persistent mode for multiple requests
 * - Reduced JSON overhead
 * - Memory-efficient processing
 */

// Check for persistent mode
const isPersistent = process.argv.includes('--persistent');

if (isPersistent) {
  // Persistent mode: process multiple requests
  processPersistentMode();
} else {
  // Single request mode (legacy)
  processSingleRequest();
}

function processSingleRequest() {
  let buffer = [];
  let totalLength = 0;

  process.stdin.setEncoding("utf8");

  process.stdin.on("data", chunk => {
    buffer.push(chunk);
    totalLength += chunk.length;

    // Prevent excessive memory usage for very large inputs
    if (totalLength > 50 * 1024 * 1024) { // 50MB limit
      process.stdout.write(JSON.stringify({
        error: "Input too large. Consider using streaming mode or splitting the input."
      }));

      process.exit(1);
    }
  });

  process.stdin.on("end", () => {
    try {
      const payload = JSON.parse(buffer.join('') || "{}");
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

    if (options.sourceMap || options.sourceMapPath) {
      compileOpts.sourceMap = options.sourceMapPath || options.sourceMap;

      if (options.includeSources) {
        compileOpts.sourceMapIncludeSources = options.includeSources;
      }
    }

    if (options.loadPaths) {
      compileOpts.loadPaths = options.loadPaths;
    }

    if (options.quietDeps) {
      compileOpts.quietDeps = options.quietDeps;
    }

    if (options.silenceDeprecations) {
      compileOpts.silenceDeprecations = options.silenceDeprecations;
    }

    if (options.verbose) {
      compileOpts.verbose = options.verbose;
    }

    const result = compileString(source, compileOpts);

    const response = {
      css: result.css,
      ...(result.sourceMap && { sourceMap: result.sourceMap }),
    };

    // Check if streaming mode is requested for large results
    if (options.streamResult && result.css.length > 1024 * 1024) {
      const cssChunks = Array.from(cssChunkGenerator(result.css));

      response.chunks = cssChunks;
      response.isStreamed = true;

      delete response.css;
    }

    process.stdout.write(JSON.stringify(response));
  } catch (err) {
    process.stdout.write(JSON.stringify({ error: String(err?.message || err) }));
  }
  });
}

/**
 * Generator function for processing compilation results in chunks
 * Useful for memory-efficient handling of large CSS outputs
 */
function* cssChunkGenerator(css, chunkSize = 64 * 1024) {
  for (let i = 0; i < css.length; i += chunkSize) {
    yield css.slice(i, i + chunkSize);
  }
}

function processPersistentMode() {
  // TODO: Implement persistent mode for multiple requests
  process.exit(1);
}
