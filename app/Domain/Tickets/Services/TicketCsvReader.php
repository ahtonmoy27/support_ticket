<?php

namespace App\Domain\Tickets\Services;

use Generator;
use Illuminate\Support\Facades\Storage;

class TicketCsvReader
{
    /** @return Generator<int, array{headers:list<string>,values:list<string|null>}> */
    public function read(string $storageKey): Generator
    {
        $stream = Storage::disk('private')->readStream($storageKey);
        if (! is_resource($stream)) {
            throw new \RuntimeException('Cannot read import file.');
        }
        try {
            $headers = fgetcsv($stream, null, ',', '"', '');
            if (! is_array($headers)) {
                throw new \InvalidArgumentException('CSV is empty.');
            }
            $headers = array_map(fn (?string $header): string => trim((string) $header), $headers);
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
            if (count($headers) !== count(array_unique($headers)) || array_diff(['customer_email', 'category', 'subject', 'description', 'priority'], $headers)) {
                throw new \InvalidArgumentException('Required unique CSV headers: customer_email,category,subject,description,priority.');
            }
            $rowNumber = 0;
            while (($values = fgetcsv($stream, null, ',', '"', '')) !== false) {
                if ($values === [null]) {
                    continue;
                }
                $rowNumber++;
                if ($rowNumber > config('support.import_max_rows')) {
                    throw new \InvalidArgumentException('CSV exceeds the configured row limit.');
                }
                yield $rowNumber => ['headers' => $headers, 'values' => $values];
            }
        } finally {
            fclose($stream);
        }
    }
}
