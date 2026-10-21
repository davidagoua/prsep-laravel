<?php

namespace App\Finance;

use RuntimeException;

/**
 * Opération financière refusée par une règle de gestion (message destiné à l'utilisateur).
 */
class FinanceException extends RuntimeException {}
