<?php

// Helper di Laravel usati dai modelli, per girare senza il framework completo.

if (! function_exists('config')) {
    function config($key = null, $default = null)
    {
        $repo = Illuminate\Container\Container::getInstance()->make('config');

        return $key === null ? $repo : (is_array($key) ? $repo->set($key) : $repo->get($key, $default));
    }
}

if (! function_exists('now')) {
    function now($tz = null): Illuminate\Support\Carbon
    {
        return Illuminate\Support\Carbon::now($tz);
    }
}

if (! function_exists('event')) {
    function event(...$args)
    {
        return Illuminate\Container\Container::getInstance()->make('events')->dispatch(...$args);
    }
}
