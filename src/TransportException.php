<?php

declare(strict_types=1);

namespace Bugo\Sass;

/**
 * Signals a retryable failure of the embedded process transport.
 */
final class TransportException extends ProtocolException {}
