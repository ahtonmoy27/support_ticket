<?php

namespace App\Domain\Tickets\Actions;

use App\Jobs\ProcessTicketImport;
use App\Models\TicketImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class StartTicketImport
{
    public function execute(User $actor, UploadedFile $file): TicketImport
    {
        Gate::forUser($actor)->authorize('administer-support');
        $path = $file->store('imports', 'private');
        if ($path === false) {
            throw new \RuntimeException('Cannot store import file.');
        }
        try {
            $import = TicketImport::create([
                'created_by' => $actor->id,
                'filename' => basename($file->getClientOriginalName()),
                'storage_key' => $path,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }
        ProcessTicketImport::dispatch($import->id)->afterCommit();

        return $import;
    }
}
