<?php
namespace Database\Seeders;

use App\Models\KontenEdukasi;
use Illuminate\Database\Seeder;

class KontenEdukasiSeeder extends Seeder
{
    public function run(): void
    {
        KontenEdukasi::updateOrCreate(['slug' => 'panduan-3m-plus'], [
            'tipe' => 'panduan',
            'judul' => 'Panduan 3M Plus untuk Cegah DBD',
            'ringkasan' => 'Langkah dasar mencegah perkembangbiakan nyamuk Aedes aegypti di lingkungan rumah.',
            'isi' => "3M Plus adalah gerakan pencegahan DBD yang terdiri dari:\n\n1. **Menguras** tempat penampungan air minimal seminggu sekali.\n2. **Menutup** rapat tempat penampungan air.\n3. **Memanfaatkan/mendaur ulang** barang bekas yang berpotensi menampung air.\n\nPlus: gunakan kelambu, obat nyamuk, dan tanaman pengusir nyamuk.",
            'sumber' => 'Kementerian Kesehatan RI',
        ]);

        KontenEdukasi::updateOrCreate(['slug' => 'kenali-gejala-dbd'], [
            'tipe' => 'panduan',
            'judul' => 'Kenali Gejala DBD Sejak Dini',
            'ringkasan' => 'Gejala umum DBD yang perlu diwaspadai dan kapan harus ke fasilitas kesehatan.',
            'isi' => "Gejala DBD meliputi demam tinggi mendadak, nyeri kepala hebat, nyeri di belakang mata, nyeri otot dan sendi, ruam kulit, serta mudah lebam atau mimisan.\n\nSegera ke fasilitas kesehatan terdekat bila demam tidak turun setelah 2 hari.",
            'sumber' => 'Kementerian Kesehatan RI',
        ]);

        KontenEdukasi::updateOrCreate(['slug' => 'malaria-apa-yang-perlu-diketahui'], [
            'tipe' => 'artikel',
            'judul' => 'Malaria: Apa yang Perlu Diketahui',
            'ringkasan' => 'Penjelasan singkat tentang penyebab, penularan, dan pencegahan malaria.',
            'isi' => "Malaria disebabkan oleh parasit Plasmodium yang ditularkan melalui gigitan nyamuk Anopheles betina, umumnya aktif pada malam hari.\n\nPencegahan utama meliputi penggunaan kelambu berinsektisida dan menghindari genangan air di sekitar rumah.",
            'sumber' => 'Kementerian Kesehatan RI',
        ]);
    }
}