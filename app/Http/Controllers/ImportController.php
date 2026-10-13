<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTicketImport;
use App\Models\TicketImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(TicketImport::query()->orderByDesc('id')->paginate(25));
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'extensions:csv', 'max:51200']]);
        $path = $request->file('file')->store('imports', 'private');
        try {
            $import = TicketImport::create(['created_by' => $request->user()->id, 'filename' => basename($request->file('file')->getClientOriginalName()), 'storage_key' => $path]);
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }
        ProcessTicketImport::dispatch($import->id)->afterCommit();

        return response()->json(['data' => $import], 202);
    }

    public function show(TicketImport $import): JsonResponse
    {
        return response()->json(['data' => $import]);
    }

    public function errors(TicketImport $import): StreamedResponse
    {
        return response()->streamDownload(function () use ($import) {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['row_number', 'errors'], ',', '"', '');
            foreach ($import->rows()->where('status', 'failed')->orderBy('row_number')->cursor() as $row) {
                $error = json_encode($row->errors, JSON_UNESCAPED_UNICODE);
                if (preg_match('/^[=+@-]/', $error)) {
                    $error = "'".$error;
                }
                fputcsv($output, [$row->row_number, $error], ',', '"', '');
            }
            fclose($output);
        }, 'import-'.$import->id.'-errors.csv', ['Content-Type' => 'text/csv']);
    }
}
