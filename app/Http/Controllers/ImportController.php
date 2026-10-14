<?php

namespace App\Http\Controllers;

use App\Domain\Tickets\Actions\StartTicketImport;
use App\Http\Requests\StartTicketImportRequest;
use App\Http\Resources\TicketImportResource;
use App\Models\TicketImport;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    public function index(): JsonResponse
    {
        return TicketImportResource::collection(TicketImport::query()->orderByDesc('id')->paginate(25))->response();
    }

    public function store(StartTicketImportRequest $request, StartTicketImport $action): JsonResponse
    {
        $import = $action->execute($request->user(), $request->file('file'));

        return response()->json(['data' => new TicketImportResource($import)], 202);
    }

    public function show(TicketImport $import): JsonResponse
    {
        return response()->json(['data' => new TicketImportResource($import)]);
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
