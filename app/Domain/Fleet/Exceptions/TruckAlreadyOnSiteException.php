<?php

namespace App\Domain\Fleet\Exceptions;

use App\Domain\Cargo\Exceptions\CargoException;

class TruckAlreadyOnSiteException extends CargoException {}
