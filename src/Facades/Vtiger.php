<?php

namespace Tzendos\Vtiger\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Class Vtiger
 * @package Tzendos\Vtiger\Facades
 */
class Vtiger extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'tzendos-vtiger';
    }
}
