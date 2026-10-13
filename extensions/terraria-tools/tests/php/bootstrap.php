<?php

// The pure classes under src/Support need no panel: autoload them directly.
spl_autoload_register(function (string $class): void {
    $prefix = 'TerrariaTools\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__, 2).'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
