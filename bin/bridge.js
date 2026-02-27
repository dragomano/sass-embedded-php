#!/usr/bin/env node
import {compileString} from "sass-embedded";
import {transform} from "lightningcss";
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
 * Normalizes sourceMap format for consistent output
 */
function normalizeSourceMap(sourceMap, options) {
  if (!sourceMap) return sourceMap;

  const map = typeof sourceMap === 'string' ? JSON.parse(sourceMap) : sourceMap;

  // If sources is data URI, extract content and set sources to filename
  if (map.sources && map.sources.length > 0) {
    const sources = [];
    const sourcesContent = [];

    for (let i = 0; i < map.sources.length; i++) {
      const source = map.sources[i];

      if (source.startsWith('data:')) {
        // Extract content from data URI
        const commaIndex = source.indexOf(',');
        const encodedContent = source.substring(commaIndex + 1);
        const content = decodeURIComponent(encodedContent);

        sourcesContent.push(content);

        // Use sourceFile from options or default
        sources.push(options.sourceFile || 'input.scss');
      } else {
        sources.push(source);

        if (map.sourcesContent && map.sourcesContent[i]) {
          sourcesContent.push(map.sourcesContent[i]);
        }
      }
    }

    map.sources = sources;
    if (sourcesContent.length > 0) {
      map.sourcesContent = sourcesContent;
    } else if (!('includeSources' in options) || options.includeSources === false) {
      delete map.sourcesContent;
    }
  }

  return map;
}

function preserveComments(css) {
  const comments = [];
  let index = 0;

  const preserved = css.replace(/\/\*[\s\S]*?\*\//g, (match) => {
    comments.push(match);

    return `__COMMENT_PLACEHOLDER_${index++}__`;
  });

  return { preserved, comments };
}

function restoreComments(css, comments, minify = false) {
  if (comments.length === 0) return css;

  let restored = css.replace(/__COMMENT_PLACEHOLDER_(\d+)__/g, (_, index) => {
    const comment = comments[parseInt(index, 10)] || '';

    return minify && !comment.startsWith('/*!') ? '' : comment;
  });

  if (!minify) {
    restored = restored.replace(/\*\/(?:\s*(?=\/\*)|\s+(?=[^\/\s]))/g, '*/\n');
  }

  return restored;
}

function optimizeCss(css, sourceMap, options, minify = false) {
  const filename = options.sourceFile || 'style.css';

  try {
    const { preserved, comments } = preserveComments(css);

    const transformOptions = {
      filename,
      code: Buffer.from(preserved),
      minify: Boolean(minify),
      sourceMap: Boolean(sourceMap),
    };

    if (sourceMap) {
      transformOptions.inputSourceMap = JSON.stringify(sourceMap);
    }

    const optimized = transform(transformOptions);
    let optimizedCss = Buffer.from(optimized.code).toString('utf8');

    if (comments.length > 0) {
      optimizedCss = restoreComments(optimizedCss, comments, minify);
    }

    let optimizedMap = sourceMap;
    if (sourceMap && optimized.map) {
      optimizedMap = JSON.parse(Buffer.from(optimized.map).toString('utf8'));
    }

    return { css: optimizedCss, sourceMap: optimizedMap };
  } catch (error) {
    throw new Error(`Lightning CSS optimization failed: ${error?.message || error}`);
  }
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

  if (options.sourceMap) {
    compileOpts.sourceMap = options.sourceMap;

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
  const normalizedSourceMap = result.sourceMap ? normalizeSourceMap(result.sourceMap, options) : undefined;
  const shouldMinify = options.minimize || ('compressed' in options && options.compressed) || options.style === 'compressed';

  const optimized = optimizeCss(result.css, normalizedSourceMap, options, shouldMinify);
  const finalCss = optimized.css;
  const finalSourceMap = optimized.sourceMap;

  const response = {
    css: finalCss,
    ...(finalSourceMap && { sourceMap: finalSourceMap }),
  };

  // Check if streaming mode is requested for large results
  if ('streamResult' in options && options.streamResult && finalCss.length > 1024 * 1024) {
    response.chunks = Array.from(cssChunkGenerator(finalCss));
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
