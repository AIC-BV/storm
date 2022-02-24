<?php namespace Winter\Storm\Support\Facades;

use Winter\Storm\Support\Facade;

/**
 * @see \Illuminate\Http\Request
 */
class Input extends Facade
{
    /**
     * Get an item from the input data.
     *
     * This method is used for all request verbs (GET, POST, PUT, and DELETE)
     *
     * @param  string|null  $key
     * @param  mixed   $default
     * @return mixed
     */
    public static function get($key = null, $default = null)
    {
        return static::$app['request']->input($key, $default);
    }

    /**
     * Gets all input data items.
     *
     * This method is used for all request verbs (GET, POST, PUT, and DELETE)
     *
     * @return array|null
     */
    public static function all()
    {
        return static::$app['request']->input();
    }

    /**
     * Get the registered name of the component.
     *
     * Resolves to:
     * - Illuminate\Http\Request
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'request';
    }
}
