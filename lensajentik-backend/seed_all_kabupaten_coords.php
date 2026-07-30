<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Wilayah;
use Illuminate\Support\Facades\Http;

echo "=== Seeding Koordinat Kabupaten seluruh Indonesia ===\n";

$kabupatens = Wilayah::where('tingkat', 'kabupaten')
    ->where(function($q) {
        $q->whereNull('latitude')->orWhereNull('longitude');
    })
    ->get();

echo "Total Kabupaten tanpa koordinat: " . $kabupatens->count() . "\n";

$success = 0;
$failed = 0;

foreach ($kabupatens as $index => $kab) {
    $cleanName = trim(preg_replace('/^(KABUPATEN|KOTA ADM\.|KOTA)\s+/i', '', $kab->nama));
    $titleName = ucwords(strtolower($cleanName));
    
    $lat = null;
    $lng = null;
    
    // Attempt 1: OpenMeteo with Title Case name
    try {
        $res = Http::retry(2, 300)->timeout(4)->get('https://geocoding-api.open-meteo.com/v1/search', [
            'name' => $titleName,
            'count' => 3,
            'language' => 'id',
        ]);
        $results = $res->json('results');
        if (!empty($results)) {
            // Find Indonesia result
            $match = collect($results)->firstWhere('country_code', 'ID') ?? $results[0];
            $lat = $match['latitude'];
            $lng = $match['longitude'];
        }
    } catch (\Exception $e) {}
    
    // Attempt 2: Nominatim if OpenMeteo returned null
    if (!$lat || !$lng) {
        try {
            $nom = Http::withHeaders(['User-Agent' => 'LensaJentik/1.0'])
                ->retry(2, 500)->timeout(5)
                ->get("https://nominatim.openstreetmap.org/search", [
                    'q' => $titleName . ', Indonesia',
                    'format' => 'json',
                    'limit' => 1,
                ])->json();
            if (!empty($nom) && isset($nom[0]['lat'])) {
                $lat = (float) $nom[0]['lat'];
                $lng = (float) $nom[0]['lon'];
            }
        } catch (\Exception $e) {}
    }
    
    if ($lat && $lng) {
        $kab->update([
            'latitude' => $lat,
            'longitude' => $lng,
        ]);
        $success++;
    } else {
        $failed++;
        echo "❌ Gagal: {$kab->kode} - {$kab->nama}\n";
    }
    
    if (($index + 1) % 25 === 0 || $index === count($kabupatens) - 1) {
        echo "Progress: " . ($index + 1) . "/" . count($kabupatens) . " (Success: $success, Failed: $failed)\n";
    }
    
    usleep(80000); // 80ms delay to avoid rate-limiting
}

echo "\n=== Selesai! Success: $success, Failed: $failed ===\n";
