<?php

namespace App\Exceptions;

use RuntimeException;

/** The AI provider refused the request (e.g. a safety refusal), as opposed to failing. */
class AiRequestDeclined extends RuntimeException
{
}
