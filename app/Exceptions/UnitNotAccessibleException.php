<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thrown when a caller tries to access a unit outside their organizational scope.
 * Renders as a 403 JSON response identical to the previous hand-rolled
 * `response()->json(['message' => 'Unit not accessible.'], 403)`.
 */
class UnitNotAccessibleException extends HttpException
{
    public function __construct()
    {
        parent::__construct(403, 'Unit not accessible.');
    }
}
