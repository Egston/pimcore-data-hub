<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

/*
 * The L3 functional suite extends Pimcore\Tests\Support\Test\TestCase, which
 * lives under the Pimcore core vendor directory but is not registered in
 * composer's PSR-4 map (Pimcore ships its test support files without a
 * dedicated autoload mapping). Walk the namespace prefix to vendor path so
 * the Functional suite's parent class is reachable under host phpunit
 * invocations such as `phpunit --list-tests --testsuite=Functional`.
 *
 * Only registers when the directory actually exists; a different vendor
 * layout (Pimcore 10 BC) is the codeception path's concern, not phpunit's.
 */
$pimcoreSupportDir = __DIR__ . '/../vendor/pimcore/pimcore/tests/Support';
if (is_dir($pimcoreSupportDir)) {
    spl_autoload_register(static function (string $class) use ($pimcoreSupportDir): void {
        $prefix = 'Pimcore\\Tests\\Support\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $candidate = $pimcoreSupportDir . '/' . $relative . '.php';
        if (is_file($candidate)) {
            require_once $candidate;
        }
    });
}
