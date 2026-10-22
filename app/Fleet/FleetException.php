<?php

namespace App\Fleet;

use RuntimeException;

/**
 * Opération refusée par une règle de gestion du parc (message destiné à l'utilisateur).
 */
class FleetException extends RuntimeException {}
