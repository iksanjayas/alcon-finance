<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Presence;
use App\Models\Employee;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;

class PresenceController extends Controller
{
    public function index()
    {
        $presences = Presence::with('employee')->get(); // Asumsikan ada relasi dengan tabel karyawan
        return view('pages.presence.presence', compact('presences'));
    }

    public function create()
    {
        return view('pages.presence.create');
    }

    public function edit($id)
    {
        $presence = Presence::find($id);
        $html = view('pages.presence.edit', compact('presence'))->render();

        return response()->json([
            'html' => $html,
            'presence_id' => $presence->id,
        ]);
    }

    public function store(Request $request)
    {
        // Validasi input
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|integer|exists:employees,id', // Validasi karyawan
            'date' => 'required|date',
            'status' => 'required|string|in:Present,Absent,Sick,Leave', // Contoh status presensi
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Simpan data presensi
            $presence = Presence::create([
                'employee_id' => $request->employee_id,
                'date' => $request->date,
                'status' => $request->status,
                'remarks' => $request->remarks,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Data presensi berhasil disimpan',
                'data' => $presence,
            ], 201);
        } catch (\Exception $e) {
            Log::error("Error: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan data presensi',
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|integer|exists:employees,id',
            'date' => 'required|date',
            'status' => 'required|string|in:Present,Absent,Sick,Leave',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $presence = Presence::findOrFail($id);
            $presence->update([
                'employee_id' => $request->employee_id,
                'date' => $request->date,
                'status' => $request->status,
                'remarks' => $request->remarks,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Data presensi berhasil diupdate',
                'data' => $presence,
            ], 200);
        } catch (\Exception $e) {
            Log::error("Error: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupdate data presensi',
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $presence = Presence::findOrFail($id);
            $presence->delete();

            return response()->json([
                'success' => true,
                'message' => 'Data presensi berhasil dihapus',
            ], 200);
        } catch (\Exception $e) {
            Log::error("Error: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus data presensi',
            ], 500);
        }
    }

    public function list()
    {
        $presences = Presence::select('id', 'employee_id', 'date', 'status')->get();
        return response()->json($presences);
    }

    // public function processImport(Request $request)
    // {
    //     $request->validate([
    //         'file' => 'required|mimes:xls,xlsx,xml',
    //         'start_date' => 'required|date',
    //         'end_date' => 'required|date|after_or_equal:start_date',
    //     ]);
    //     Log::info('Request All: ', $request->all());
    //     $file = $request->file('file');
    //     $data = [];

    //     if ($file->getClientOriginalExtension() === 'xml') {
    //         $xmlContent = file_get_contents($file);
    //         $xml = simplexml_load_string($xmlContent);
    //         foreach ($xml->presence as $row) {
    //             $data[] = [
    //                 'tanggal_scan' => (string)$row->tanggal_scan,
    //                 'tanggal' => (string)$row->tanggal,
    //                 'jam' => (string)$row->jam,
    //                 'nip' => (string)$row->nip,
    //                 'nama' => (string)$row->nama,
    //                 'sn' => (string)$row->sn,
    //             ];
    //         }
    //         Log::info('Data Presen:' . json_encode($data));
    //     } else {
    //         $data = Excel::toArray([], $file)[0];
    //     }

    //     // Filter data by date range
    //     $filteredData = array_filter($data, function ($row) use ($request) {
    //         $date = isset($row['tanggal']) ? $row['tanggal'] : $row['tanggal_scan'];
    //         return $date >= $request->start_date && $date <= $request->end_date;
    //     });

    //     // Validasi Data
    //     $validatedData = $this->validatePresenceData($filteredData);

    //     return response()->json([
    //         'data' => $validatedData['data'],
    //         'invalidData' => $validatedData['invalid'],
    //     ]);
    // }

    public function processImport(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xls,xlsx,xml',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        Log::info('Request All: ', $request->all());
        $file = $request->file('file');
        $data = [];

        if ($file->getClientOriginalExtension() === 'xml') {
            $fileContent = file_get_contents($file);
            $fileContent = preg_replace('/<\?xml:stylesheet(.*?)\?>/', '<?xml-stylesheet\1?>', $fileContent);
            $xml = simplexml_load_string($fileContent);

            if ($xml === false) {
                Log::error('Error parsing XML: ' . implode(", ", libxml_get_errors()));
                return response()->json(['error' => 'Failed to parse XML'], 400);
            }


            if (isset($xml->ROWS->ROW)) {
                foreach ($xml->ROWS->ROW as $row) {
                    $data[] = [
                        'tanggal_scan' => (string) $row['dbg_scanlogscan_date'] ?? null,
                        'tanggal' => (string) $row['dbg_scanlogtgl'] ?? null,
                        'jam' => (string) $row['dbg_scanlogjam'] ?? null,
                        'nip' => (string) $row['dbg_scanlogpegawai_nip'] ?? null,
                        'nama' => (string) $row['dbg_scanlogpegawai_nama'] ?? null,
                        'sn' => (string) $row['dbg_scanlogsn'] ?? null,
                    ];
                }
                Log::info('Data Presensi:' . json_encode($data));
            } else {
                Log::warning('Invalid XML structure');
            }
        } else {
            $data = Excel::toArray([], $file)[0];
        }

        $filteredData = array_filter($data, function ($row) use ($request) {
            $date = $row['tanggal'] ?? $row['tanggal_scan'] ?? null;

            if (!$date) {
                Log::warning('Invalid date in row: ' . json_encode($row));
                return false;
            }

            try {
                $date = Carbon::parse($date);
            } catch (\Exception $e) {
                Log::error('Invalid date format: ' . $date);
                return false;
            }

            $startDate = Carbon::parse($request->start_date);
            $endDate = Carbon::parse($request->end_date);

            return $date->between($startDate, $endDate);
        });

        Log::info('Filtered Data: ' . json_encode($filteredData));

        $validatedData = $this->validatePresenceData($filteredData);

        return response()->json([
            'data' => $validatedData['data'],
            'invalidData' => $validatedData['invalid'],
        ]);
    }

    private function validatePresenceData(array $filteredData)
    {
        $validated = [];
        $invalid = [];

        // Group data by nip
        $grouped = collect($filteredData)->groupBy('nip');

        foreach ($grouped as $nip => $entries) {
            $entries = collect($entries)->sortBy('jam')->values(); // Reset keys after sorting

            // Group further by date
            $dateGroups = $entries->groupBy('tanggal');

            foreach ($dateGroups as $date => $dailyEntries) {
                $dailyEntries = $dailyEntries->sortBy('jam')->values();

                // Filter for valid jam masuk (06:00 - 07:00) and jam pulang (16:00 - 23:59)
                $jamMasuk = $dailyEntries->first(function ($entry) {
                    $time = Carbon::createFromFormat('H:i', $entry['jam']);
                    return $time->between(Carbon::createFromTime(6, 0), Carbon::createFromTime(7, 0));
                });

                $jamPulang = $dailyEntries->last(function ($entry) {
                    $time = Carbon::createFromFormat('H:i', $entry['jam']);
                    return $time->between(Carbon::createFromTime(16, 0), Carbon::createFromTime(23, 59));
                });

                if ($jamMasuk && $jamPulang) {
                    $validated[] = [
                        'nip' => $nip,
                        'tanggal' => $date,
                        'jam_masuk' => $jamMasuk['jam'],
                        'jam_keluar' => $jamPulang['jam'],
                    ];
                } else {
                    $invalid[] = [
                        'nip' => $nip,
                        'tanggal' => $date,
                        'jam_masuk' => $jamMasuk['jam'] ?? null,
                        'jam_keluar' => $jamPulang['jam'] ?? null,
                    ];
                }
            }
        }

        return ['data' => $validated, 'invalid' => $invalid];
    }



    // private function validatePresenceData(array $filteredData)
    // {
    //     $validated = [];
    //     $invalid = [];
    //     Log::info('Group:'. json_encode($filteredData));

    //     $grouped = collect($filteredData)->groupBy('tanggal');

    //     Log::info('Group:'. json_encode($grouped));

    //     foreach ($grouped as $date => $entries) {
    //         $entries = $entries->sortBy('jam');

    //         if ($entries->count() === 1) {
    //             // Invalid: Hanya ada satu data untuk tanggal ini
    //             $invalid[] = $entries->first();
    //         } elseif ($entries->count() > 2) {
    //             // Ambil jam terkecil dan terbesar
    //             $validated[] = [
    //                 'tanggal' => $date,
    //                 'jam_masuk' => $entries->first()['jam'],
    //                 'jam_keluar' => $entries->last()['jam'],
    //             ];
    //         } else {
    //             // Valid: Hanya ada 2 data
    //             $validated[] = [
    //                 'tanggal' => $date,
    //                 'jam_masuk' => $entries->first()['jam'],
    //                 'jam_keluar' => $entries->last()['jam'],
    //             ];
    //         }
    //     }

    //     return ['data' => $validated, 'invalid' => $invalid];
    // }

    public function storeImport(Request $request)
    {
        $request->validate(['data' => 'required|array']);

        foreach ($request->data as $row) {
            Presence::create([
                'tanggal' => $row['tanggal'],
                'jam_masuk' => $row['jam_masuk'],
                'jam_keluar' => $row['jam_keluar'],
                'nip' => $row['nip'],
            ]);
        }

        return response()->json(['message' => 'Data berhasil disimpan!']);
    }
}