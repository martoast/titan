<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an AI provider (OpenAI or Gemini) is unreachable, misconfigured,
 * or returns something unusable. Caught at the controller/job boundary and shown
 * to the user as a friendly message.
 */
class AiException extends RuntimeException {}
