<?php

namespace App\Workflow;

use RuntimeException;

/**
 * Action refusée par les règles du circuit (message destiné à l'utilisateur).
 */
class WorkflowException extends RuntimeException {}
