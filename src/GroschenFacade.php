<?php
namespace lasselehtinen\Groschen;

use Illuminate\Support\Facades\Facade;

class GroschenFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'groschen';
    }
}
