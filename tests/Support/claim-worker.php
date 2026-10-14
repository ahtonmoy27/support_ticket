<?php

use App\Domain\Assignment\Actions\AssignTicket;
use App\Domain\Tickets\Actions\CreateTicket;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'support_ticket_sla_test') {
    fwrite(STDERR, 'Concurrency worker requires the isolated support_ticket_sla_test MySQL database.');
    exit(2);
}
try {
    DB::statement('SET SESSION innodb_lock_wait_timeout = 3');
    $user = User::findOrFail((int) $argv[2]);
    $startAt = (float) $argv[3];
    while (microtime(true) < $startAt) {
        usleep(1000);
    }
    if ($argv[1] === 'claim') {
        app(AssignTicket::class)->execute((int) $argv[4], $user, claim: true);
    } else {
        app(CreateTicket::class)->execute($user, [
            'subject' => 'Concurrent ticket', 'description' => 'Concurrent creation', 'category_id' => (int) $argv[4], 'priority' => 'critical',
        ]);
    }
    echo '200';
    exit(0);
} catch (HttpExceptionInterface $exception) {
    echo $exception->getStatusCode();
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage());
    exit(1);
}
