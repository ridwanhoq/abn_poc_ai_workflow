<?php

namespace App\Workflow\Exceptions;

use RuntimeException;

/** A node failed in an expected, user-presentable way. */
class NodeExecutionException extends RuntimeException {}
