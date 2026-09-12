# Embedded process lifecycle

`EmbeddedCompiler` starts Dart Sass lazily and reuses one `sass --embedded` child for every compilation performed by the same compiler instance.

## Recovery

Before reusing a child, the compiler verifies that it is still running. A dead child is discarded and restarted before the next request is written. If an owned child dies during transport, the compiler closes it, starts a fresh process, performs the protocol handshake again, and retries the compilation once.

Only transport failures such as a broken pipe, EOF, or an unexpected process exit are retried. Sass compilation errors, timeouts, malformed protocol messages, compilation-ID mismatches, and incompatible protocol versions are not retried.

## Shutdown

Call `close()` when the compiler is no longer needed. The method is idempotent: it closes stdin first so Dart Sass can exit on EOF, drains remaining output while waiting, and uses `proc_terminate()` only if the child does not exit within the graceful-shutdown timeout. The destructor also calls `close()` as a fallback.

Long-running applications should still close compiler instances explicitly during worker shutdown rather than relying only on garbage collection.

## FPM and long-running workers

Performance improves when several compilations use the same `EmbeddedCompiler` instance. Standard PHP-FPM does not preserve an object between requests, so it provides no cross-request process reuse. Multiple compilations within one request still benefit.

RoadRunner, Swoole, ReactPHP, queue consumers, and similar long-running workers can retain one compiler across tasks. They should close it when the worker stops and avoid sharing one instance between concurrent compilations.
