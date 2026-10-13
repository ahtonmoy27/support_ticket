<?php

namespace App\Domain\Tickets\Enums;

enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case WaitingForCustomer = 'waiting_for_customer';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Open => [self::InProgress, self::Resolved],
            self::InProgress => [self::WaitingForCustomer, self::Resolved],
            self::WaitingForCustomer => [self::InProgress, self::Resolved],
            self::Resolved => [self::Closed], self::Closed => [],
        }, true);
    }

    public function isActive(): bool
    {
        return ! in_array($this, [self::Resolved, self::Closed], true);
    }

    public static function activeValues(): array
    {
        return ['open', 'in_progress', 'waiting_for_customer'];
    }
}
