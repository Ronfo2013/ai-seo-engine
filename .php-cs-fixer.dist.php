<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/ai-seo-engine', __DIR__ . '/tests'])
    ->name('*.php')
    // I tre file ereditati dalla v1. Non li normalizziamo ora: la Fase 2
    // riscrive GeminiSEO.php e sostituisce integration.php e cron.php, e un
    // riformattaggio di massa oggi renderebbe illeggibile quel diff.
    // Tutto il codice nuovo resta coperto.
    ->notPath('GeminiSEO.php')
    ->notPath('integration.php')
    ->notPath('cron.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'trailing_comma_in_multiline' => true,
        'single_quote' => true,
        'no_trailing_whitespace' => true,
    ])
    ->setFinder($finder);
