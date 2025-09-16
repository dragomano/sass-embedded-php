#!/usr/bin/env node
import {compileString} from "sass-embedded";
import readline from "readline";

/**
 * Optimized bridge for sass compilation with support for:
 * - Large data streams
 * - Persistent mode for multiple requests
 * - Reduced JSON overhead
 * - Memory-efficient processing
 */

/** @type {string[]} */
const argv = process.argv;
const isPersistent = argv.includes('--persistent');

if (isPersistent) {
  processPersistentMode();
} else {
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
      const response = compilePayload(payload);

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

/**
 * Generator function for processing sourceMap in chunks
 * Useful for large sourceMap data
 */
function* sourceMapChunkGenerator(sourceMap, chunkSize = 64 * 1024) {
  const mapString = JSON.stringify(sourceMap);

  for (let i = 0; i < mapString.length; i += chunkSize) {
    yield mapString.slice(i, i + chunkSize);
  }
}

function processPersistentMode() {
  const rl = readline.createInterface({
    input: process.stdin,
    output: process.stdout,
    terminal: false
  });

  rl.on('line', (line) => {
    if (line.trim()) {
      try {
        const request = JSON.parse(line);

        if (request.exit === true) {
          rl.close();
          process.exit(0);
        }

        const response = compilePayload(request);

        process.stdout.write(JSON.stringify(response) + '\n');
      } catch (err) {
        sendError(err.message);
      }
    }
  });

  rl.on('close', () => {
    process.exit(0);
  });
}

/**
 * Compiles a single payload and returns the response object
 */
function compilePayload(payload) {
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

  const sourceMapPath = 'sourceMapPath' in options && options.sourceMapPath ? options.sourceMapPath : false;

  if (options.sourceMap || sourceMapPath) {
    compileOpts.sourceMap = sourceMapPath || options.sourceMap;

    if ('includeSources' in options && options.includeSources) {
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
  if ('streamResult' in options && options.streamResult && result.css.length > 1024 * 1024) {
    response.chunks = Array.from(cssChunkGenerator(result.css));
    response.isStreamed = true;

    delete response.css;
  }

  // Handle large sourceMap
  if (response.sourceMap && JSON.stringify(response.sourceMap).length > 1024 * 1024) {
    response.sourceMapChunks = Array.from(sourceMapChunkGenerator(response.sourceMap));
    response.sourceMapIsStreamed = true;

    delete response.sourceMap;
  }

  return response;
}

function sendError(message) {
  process.stdout.write(JSON.stringify({ error: message }) + '\n');
}
