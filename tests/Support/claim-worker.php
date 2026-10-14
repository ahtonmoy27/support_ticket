<?php

use App\Domain\Assignment\Actions\AssignTicket;
use App\Domain\Tickets\Actions\CreateTicket;
use App\Domain\Tickets\Actions\ImportTicketRow;
use App\Models\Category;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'support_ticket_sla_test') {
    fwrite(STDERR, 'Concurrency worker requires the isolated support_ticket_sla_test MySQL database.');
    exit(2);
}
$queries = 0;
$queryMs = 0.0;
$rollbacks = 0;
$startedAt = 0;
$report = function (int $status) use (&$queries, &$queryMs, &$rollbacks, &$startedAt): void {
    if (($_SERVER['argv'][5] ?? '') === 'metrics') {
        echo json_encode(['status' => $status, 'duration_ms' => round((hrtime(true) - $startedAt) / 1e6, 2),
            'queries' => $queries, 'query_ms' => round($queryMs, 2), 'transaction_rollbacks' => $rollbacks], JSON_THROW_ON_ERROR);
    } else {
        echo $status;
    }
};
try {
    DB::statement('SET SESSION innodb_lock_wait_timeout = 3');
    $user = User::findOrFail((int) $argv[2]);
    $startAt = (float) $argv[3];
    while (microtime(true) < $startAt) {
        usleep(1000);
    }
    DB::listen(function (QueryExecuted $query) use (&$queries, &$queryMs): void {
        $queries++;
        $queryMs += $query->time;
    });
    Event::listen(TransactionRolledBack::class, function () use (&$rollbacks): void {
        $rollbacks++;
    });
    $startedAt = hrtime(true);
    if ($argv[1] === 'assign') {
        app(AssignTicket::class)->execute((int) $argv[4], $user, (int) $argv[6]);
    } elseif (in_array($argv[1], ['import', 'import-duplicate'], true)) {
        app(ImportTicketRow::class)->execute((int) $argv[4], 1, [
            'customer_email' => $user->email, 'category' => Category::first()->name,
            'subject' => 'Concurrent import', 'description' => 'Import load test', 'priority' => 'critical',
            'source_system' => 'load-test', 'external_ticket_id' => $argv[1] === 'import-duplicate' ? 'shared-id' : $argv[4],
        ]);
    } elseif ($argv[1] === 'claim') {
        app(AssignTicket::class)->execute((int) $argv[4], $user, claim: true);
    } else {
        app(CreateTicket::class)->execute($user, [
            'subject' => 'Concurrent ticket', 'description' => 'Concurrent creation', 'category_id' => (int) $argv[4], 'priority' => 'critical',
        ]);
    }
    $report(200);
    exit(0);
} catch (HttpExceptionInterface $exception) {
    $report($exception->getStatusCode());
    exit(0);
} catch (Throwable $exception) {
    if (($argv[5] ?? '') === 'metrics') {
        $report(500);
        exit(0);
    }
    fwrite(STDERR, $exception->getMessage());
    exit(1);
}
