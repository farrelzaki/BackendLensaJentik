<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KontenEdukasi;
use Illuminate\Http\Request;

class EdukasiController extends Controller
{
    /**
     * GET /api/edukasi?kategori=dbd
     */
    public function index(Request $request)
    {
        $query = KontenEdukasi::query()->latest();

        if ($request->filled('kategori')) {
            $query->where('kategori', $request->kategori);
        }

        return response()->json($query->paginate(10));
    }

    /**
     * GET /api/edukasi/{slug}
     */
    public function show(string $slug)
    {
        $konten = KontenEdukasi::where('slug', $slug)->first();

        if (!$konten) {
            return response()->json(['message' => 'Konten tidak ditemukan'], 404);
        }

        return response()->json(['data' => $konten]);
    }

    /**
     * GET /api/edukasi/kuis/pertanyaan
     * Kuis kalkulator risiko personal — statis, gak perlu tabel database.
     */
    public function pertanyaanKuis()
    {
        return response()->json([
            'pertanyaan' => [
                [
                    'id' => 'genangan_air',
                    'teks' => 'Apakah ada genangan air (bak mandi, vas bunga, ban bekas, dll) di sekitar rumah yang jarang dikuras?',
                    'opsi' => [
                        ['value' => 'sering', 'label' => 'Ya, sering ada', 'bobot' => 25],
                        ['value' => 'kadang', 'label' => 'Kadang-kadang', 'bobot' => 15],
                        ['value' => 'tidak', 'label' => 'Tidak pernah', 'bobot' => 0],
                    ],
                ],
                [
                    'id' => 'kuras_bak',
                    'teks' => 'Seberapa sering kamu menguras bak mandi/penampungan air?',
                    'opsi' => [
                        ['value' => 'jarang', 'label' => 'Jarang/tidak pernah', 'bobot' => 25],
                        ['value' => 'kadang', 'label' => '1x per 2 minggu', 'bobot' => 10],
                        ['value' => 'rutin', 'label' => 'Rutin tiap minggu', 'bobot' => 0],
                    ],
                ],
                [
                    'id' => 'kelambu_kasa',
                    'teks' => 'Apakah rumah punya kasa nyamuk atau kelambu?',
                    'opsi' => [
                        ['value' => 'tidak', 'label' => 'Tidak ada', 'bobot' => 20],
                        ['value' => 'sebagian', 'label' => 'Sebagian ruangan', 'bobot' => 10],
                        ['value' => 'lengkap', 'label' => 'Lengkap di semua ruangan', 'bobot' => 0],
                    ],
                ],
                [
                    'id' => 'lingkungan_sekitar',
                    'teks' => 'Bagaimana kondisi lingkungan sekitar rumah (parit, sampah)?',
                    'opsi' => [
                        ['value' => 'kotor', 'label' => 'Banyak sampah/parit tersumbat', 'bobot' => 20],
                        ['value' => 'sedang', 'label' => 'Cukup bersih', 'bobot' => 10],
                        ['value' => 'bersih', 'label' => 'Bersih dan terawat', 'bobot' => 0],
                    ],
                ],
                [
                    'id' => 'riwayat_kasus',
                    'teks' => 'Apakah ada kasus DBD/Malaria di lingkungan sekitar rumah dalam 3 bulan terakhir?',
                    'opsi' => [
                        ['value' => 'ada', 'label' => 'Ada', 'bobot' => 10],
                        ['value' => 'tidak_tahu', 'label' => 'Tidak tahu', 'bobot' => 5],
                        ['value' => 'tidak_ada', 'label' => 'Tidak ada', 'bobot' => 0],
                    ],
                ],
            ],
        ]);
    }

    /**
     * POST /api/edukasi/kuis/hitung
     * Body: { "jawaban": { "genangan_air": "sering", "kuras_bak": "jarang", ... } }
     */
    public function hitungKuis(Request $request)
    {
        $request->validate(['jawaban' => 'required|array']);

        $bobotPerPertanyaan = [
            'genangan_air' => ['sering' => 25, 'kadang' => 15, 'tidak' => 0],
            'kuras_bak' => ['jarang' => 25, 'kadang' => 10, 'rutin' => 0],
            'kelambu_kasa' => ['tidak' => 20, 'sebagian' => 10, 'lengkap' => 0],
            'lingkungan_sekitar' => ['kotor' => 20, 'sedang' => 10, 'bersih' => 0],
            'riwayat_kasus' => ['ada' => 10, 'tidak_tahu' => 5, 'tidak_ada' => 0],
        ];

        $skor = 0;
        foreach ($request->jawaban as $pertanyaanId => $jawabanValue) {
            $skor += $bobotPerPertanyaan[$pertanyaanId][$jawabanValue] ?? 0;
        }

        if ($skor < 30) {
            $level = 'rendah';
            $rekomendasi = 'Lingkungan rumahmu cukup aman. Tetap jaga kebersihan dan lakukan 3M Plus secara rutin.';
        } elseif ($skor <= 60) {
            $level = 'sedang';
            $rekomendasi = 'Ada beberapa faktor risiko di rumahmu. Mulai rutin kuras bak mandi dan pastikan tidak ada genangan air.';
        } else {
            $level = 'tinggi';
            $rekomendasi = 'Rumahmu berisiko tinggi jadi tempat berkembang biak nyamuk. Segera lakukan 3M Plus dan laporkan genangan air lewat fitur Laporan.';
        }

        return response()->json([
            'skor' => $skor,
            'level_risiko' => $level,
            'rekomendasi' => $rekomendasi,
        ]);
    }
}