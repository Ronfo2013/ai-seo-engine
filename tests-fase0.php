<?php
declare(strict_types=1);
require_once '/home/user/ai-seo-engine/ai-seo-engine/GeminiSEO.php';
use AISEOEngine\GeminiSEO;

$tmp = sys_get_temp_dir() . '/aiseo_test_' . bin2hex(random_bytes(4));
mkdir($tmp, 0755, true);
$cfg = $tmp . '/seo-config.json';

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  OK   $name\n"; }
    else     { $fail++; echo "  FAIL $name" . ($detail ? " -> $detail" : '') . "\n"; }
}

echo "\n[B1] business_type come stringa non deve piu' crashare\n";
putenv('GEMINI_API_KEY=chiave-finta-per-test');
$seo = new GeminiSEO($cfg);

$siteDataStringa = [
    'site_name' => 'Test',
    'seo' => ['business_type' => 'Restaurant, BowlingAlley', 'title' => 't'],
    'contact' => ['address' => 'Via Test 1, Portogruaro'],
    'sections' => [],
];
try {
    $ctx = $seo->analyzeSiteContent($siteDataStringa);
    check('stringa: nessun TypeError', true);
    check('stringa: tipo tradotto', str_contains($ctx['business_type'], 'Ristorante'), $ctx['business_type']);
    check('stringa: USP dedotte', str_contains($ctx['unique_points'], 'bowling'), $ctx['unique_points']);
    check('stringa: target dedotto', str_contains($ctx['target_audience'], 'amici'), $ctx['target_audience']);
} catch (\Throwable $e) {
    check('stringa: nessun TypeError', false, get_class($e) . ': ' . $e->getMessage());
}

$siteDataArray = $siteDataStringa;
$siteDataArray['seo']['business_type'] = ['Restaurant', 'BowlingAlley'];
try {
    $ctx = $seo->analyzeSiteContent($siteDataArray);
    check('array: comportamento invariato', str_contains($ctx['unique_points'], 'bowling'), $ctx['unique_points']);
} catch (\Throwable $e) {
    check('array: comportamento invariato', false, $e->getMessage());
}

$siteDataVuoto = $siteDataStringa;
unset($siteDataVuoto['seo']['business_type']);
try {
    $ctx = $seo->analyzeSiteContent($siteDataVuoto);
    check('assente: fallback', $ctx['business_type'] === 'Attività locale', $ctx['business_type']);
} catch (\Throwable $e) {
    check('assente: fallback', false, $e->getMessage());
}

echo "\n[S1] la chiave arriva dall'ambiente e non torna mai su disco\n";
check('letta da GEMINI_API_KEY', $seo->getConfig()['api_key'] === 'chiave-finta-per-test');
$seo->updateConfig(['enabled' => true]);   // forza una saveConfig()
$suDisco = json_decode(file_get_contents($cfg), true);
check('non persistita nel file', ($suDisco['api_key'] ?? null) === '', var_export($suDisco['api_key'] ?? null, true));
check('altre impostazioni persistite', ($suDisco['enabled'] ?? null) === true);
putenv('GEMINI_API_KEY');
$seo2 = new GeminiSEO($cfg);
check('senza env resta vuota', $seo2->getConfig()['api_key'] === '');

echo "\n[B4] il modello riportato coincide con l'endpoint\n";
$m = new ReflectionMethod(GeminiSEO::class, 'modelName');
$m->setAccessible(true);
$modello = $m->invoke($seo);
check('modelName = gemini-2.5-flash', $modello === 'gemini-2.5-flash', $modello);

echo "\n[B6] scritture concorrenti sullo storico\n";
$h = new ReflectionMethod(GeminiSEO::class, 'saveToHistory');
$h->setAccessible(true);
for ($i = 0; $i < 25; $i++) {
    $h->invoke($seo, ['title' => "vecchio $i"], ['title' => "nuovo $i", 'confidence' => 90]);
}
$storico = json_decode(file_get_contents($tmp . '/history.json'), true);
check('JSON valido dopo 25 scritture', is_array($storico) && isset($storico['entries']));
check('25 voci presenti', count($storico['entries'] ?? []) === 25, (string) count($storico['entries'] ?? []));

// due processi che scrivono davvero in parallelo
$figli = [];
for ($i = 0; $i < 4; $i++) {
    $cmd = sprintf(
        'php -r %s > /dev/null 2>&1 &',
        escapeshellarg(
            'require "/home/user/ai-seo-engine/ai-seo-engine/GeminiSEO.php";' .
            '$s = new AISEOEngine\GeminiSEO(' . var_export($cfg, true) . ');' .
            '$m = new ReflectionMethod($s, "saveToHistory"); $m->setAccessible(true);' .
            'for ($i=0;$i<15;$i++) { $m->invoke($s, ["title"=>"o"], ["title"=>"n"]); }'
        )
    );
    exec($cmd);
}
sleep(3);
$storico = json_decode(file_get_contents($tmp . '/history.json'), true);
check('storico non corrotto dopo 4 processi paralleli', is_array($storico) && isset($storico['entries']));
check('cap a 100 voci rispettato', count($storico['entries'] ?? []) <= 100, (string) count($storico['entries'] ?? []));

echo "\n[B2] --force scavalca l'attesa fra due run\n";
putenv('GEMINI_API_KEY=chiave-finta-per-test');
$seo3 = new GeminiSEO($cfg);
$seo3->updateConfig(['enabled' => true, 'last_run' => date('Y-m-d H:i:s'), 'frequency_days' => 30]);
$senza = $seo3->autoGenerateAndApply($siteDataArray, fn () => true, false);
check('senza force: bloccato dalla finestra', ($senza['error'] ?? '') === 'Troppo presto per rigenerare', json_encode($senza));
$con = $seo3->autoGenerateAndApply($siteDataArray, fn () => true, true);
check('con force: la finestra non blocca', ($con['error'] ?? '') !== 'Troppo presto per rigenerare', json_encode($con['error'] ?? $con));

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n----------------------------------------\n";
echo "  $pass superati, $fail falliti\n";
exit($fail === 0 ? 0 : 1);
