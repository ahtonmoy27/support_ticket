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

    public function sample(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $output = fopen('php://output', 'w');
            fputcsv($output, [
                'customer_email',
                'category',
                'subject',
                'description',
                'priority',
                'source_system',
                'external_ticket_id',
            ], ',', '"', '');

            // Sample rows with realistic support scenarios
            $samples = [
                [
                    'user@gmail.com',
                    'Technical',
                    'Unable to access dashboard after login',
                    'When I attempt to log into the client dashboard, the page continually redirects back to the login screen.',
                    'high',
                    'legacy_zendesk',
                    'EXT-1001',
                ],
                [
                    'user@gmail.com',
                    'Billing',
                    'Monthly invoice discrepancy for October',
                    'The subscription charge on my credit card statement was $49 instead of the agreed $39 annual discounted tier.',
                    'medium',
                    'billing_portal',
                    'INV-5502',
                ],
                [
                    'user@gmail.com',
                    'General',
                    'Question regarding business hour response SLA',
                    'Could you clarify whether critical priority tickets are handled during Friday-Saturday off-calendar days?',
                    'low',
                    'web_inquiry',
                    'INQ-901',
                ],
            ];

            foreach ($samples as $row) {
                fputcsv($output, $row, ',', '"', '');
            }

            fclose($output);
        }, 'ticket-import-sample.csv', [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="ticket-import-sample.csv"',
        ]);
    }
}
