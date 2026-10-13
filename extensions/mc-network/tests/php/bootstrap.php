<?php

// The pure classes under src/Support need no panel: autoload them directly.
spl_autoload_register(function (string $class): void {
    $prefix = 'McNetwork\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__, 2).'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

// The panel ships symfony/yaml. Outside the panel, use the repository's dev
// dependencies (composer install in the repository root), or point
// MCNET_YAML_AUTOLOAD at another vendor/autoload.php that has it.
if (! class_exists(\Symfony\Component\Yaml\Yaml::class)) {
    foreach ([getenv('MCNET_YAML_AUTOLOAD'), dirname(__DIR__, 4).'/vendor/autoload.php'] as $autoload) {
        if (is_string($autoload) && is_file($autoload)) {
            require_once $autoload;
            break;
        }
    }
}
