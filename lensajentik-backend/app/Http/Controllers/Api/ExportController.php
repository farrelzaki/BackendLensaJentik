<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wilayah;
use App\Models\AbjLaporan;
use App\Exports\AbjExport;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;

class ExportController extends Controller
{
    /**
     * GET /api/export/abj/excel?wilayah_kode=xxx&dari=2026-07-01&sampai=2026-07-31
     */
    public function excel(Request $request)
    {
        $request->validate([
            'wilayah_kode' => 'required|exists:wilayah,kode',
            'dari' => 'nullable|date',
            'sampai' => 'nullable|date',
        ]);

        $wilayah = Wilayah::find($request->wilayah_kode);
        $namaFile = "abj-{$wilayah->nama}-" . now()->format('Ymd') . ".xlsx";
        $namaFile = str_replace(' ', '-', strtolower($namaFile));

        return Excel::download(
            new AbjExport($request->wilayah_kode, $request->dari, $request->sampai),
            $namaFile
        );
    }

    /**
     * GET /api/export/abj/pdf?wilayah_kode=xxx&dari=2026-07-01&sampai=2026-07-31
     */
    public function pdf(Request $request)
    {
        $request->validate([
            'wilayah_kode' => 'required|exists:wilayah,kode',
            'dari' => 'nullable|date',
            'sampai' => 'nullable|date',
        ]);

        $wilayah = Wilayah::find($request->wilayah_kode);

        $query = AbjLaporan::where('wilayah_kode', $request->wilayah_kode)
            ->with('user:id,nama')
            ->orderBy('tanggal_pemeriksaan');

        if ($request->dari) $query->where('tanggal_pemeriksaan', '>=', $request->dari);
        if ($request->sampai) $query->where('tanggal_pemeriksaan', '<=', $request->sampai);

        $data = $query->get();
        $periode = $request->dari && $request->sampai
            ? "{$request->dari} s/d {$request->sampai}"
            : 'Semua data';

        $pdf = Pdf::loadView('exports.abj-pdf', [
            'wilayah' => $wilayah,
            'data' => $data,
            'periode' => $periode,
            'rataRata' => round($data->avg('abj_persen') ?? 0, 2),
        ]);

        $namaFile = "abj-{$wilayah->nama}-" . now()->format('Ymd') . ".pdf";
        $namaFile = str_replace(' ', '-', strtolower($namaFile));

        return $pdf->download($namaFile);
    }
}