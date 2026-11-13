<?php

declare(strict_types=1);

/* Generates docs/api-reference.md from public classes and methods (signatures + first doc line).
   Classes and methods marked @internal are left out. */

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__) . '/src';
$classes = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($it as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $class = 'Omnitrix\\Core\\' . str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($root) + 1));
    if (class_exists($class) || interface_exists($class) || enum_exists($class)) {
        $classes[] = $class;
    }
}
sort($classes);

$summary = static function (string|false $doc): string {
    if ($doc === false) {
        return '';
    }
    foreach (explode("\n", $doc) as $line) {
        $line = trim(preg_replace(['#^\s*/?\*+/?#', '#\s*\*/\s*$#'], '', $line) ?? '');
        if ($line !== '' && !str_starts_with($line, '@')) {
            return $line;
        }
    }

    return '';
};
$isInternal = static fn(string|false $doc): bool => $doc !== false && preg_match('/@internal\b/', $doc) === 1;
$type = static fn(?ReflectionType $t): string => $t === null ? 'mixed' : (string) $t;

$out = "# API Reference\n\nPublic classes and methods, generated from the source with `php tools/api_reference.php`. Narrative documentation lives in the other files of `docs/`.\n";
$currentNs = '';
foreach ($classes as $class) {
    $r = new ReflectionClass($class);
    if (str_starts_with($r->getShortName(), '_') || $isInternal($r->getDocComment())) {
        continue;
    }
    $ns = $r->getNamespaceName();
    if ($ns !== $currentNs) {
        $out .= "\n## {$ns}\n";
        $currentNs = $ns;
    }
    $kind = $r->isInterface() ? 'interface' : ($r->isEnum() ? 'enum' : ($r->isAbstract() ? 'abstract class' : 'class'));
    $out .= "\n### {$r->getShortName()} ({$kind})\n\n";
    $doc = $summary($r->getDocComment());
    if ($doc !== '') {
        $out .= $doc . "\n\n";
    }
    foreach ($r->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if ($m->getDeclaringClass()->getName() !== $class || str_starts_with($m->getName(), '__') && $m->getName() !== '__construct' && $m->getName() !== '__invoke' || $isInternal($m->getDocComment())) {
            continue;
        }
        $params = implode(', ', array_map(static function (ReflectionParameter $p) use ($type): string {
            return ($p->hasType() ? $type($p->getType()) . ' ' : '') . ($p->isVariadic() ? '...' : '') . '$' . $p->getName() . ($p->isDefaultValueAvailable() && !$p->isVariadic() ? ' = ' . (is_scalar($p->getDefaultValue()) || $p->getDefaultValue() === null ? var_export($p->getDefaultValue(), true) : '…') : '');
        }, $m->getParameters()));
        $ret = $m->hasReturnType() ? ': ' . $type($m->getReturnType()) : '';
        $static = $m->isStatic() ? 'static ' : '';
        $line = "- `{$static}{$m->getName()}({$params}){$ret}`";
        $d = $summary($m->getDocComment());
        $out .= $line . ($d !== '' ? " — {$d}" : '') . "\n";
    }
}
file_put_contents(dirname(__DIR__) . '/docs/api-reference.md', $out);
echo count($classes), " classes documented\n";
