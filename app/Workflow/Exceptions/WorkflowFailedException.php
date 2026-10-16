<?php

namespace App\Workflow\Exceptions;

use RuntimeException;

/** Aborts the whole execution with a failed status. */
class WorkflowFailedException extends RuntimeException {}
