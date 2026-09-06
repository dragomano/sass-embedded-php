<?php

declare(strict_types=1);

namespace Bugo\Sass;

/**
 * Signals a failure of the embedded protocol transport itself: a broken pipe,
 * a timeout, a malformed packet or a `ProtocolError` from the compiler.
 *
 * Unlike a plain {@see Exception} (which reports a Sass language error and
 * leaves the compiler process healthy), this always tears the process down.
 */
class ProtocolException extends Exception {}
