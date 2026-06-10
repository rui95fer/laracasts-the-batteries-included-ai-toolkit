<?php

namespace App;

enum TicketDepartment: string
{
    case Support = 'support';
    case Billing = 'billing';
    case Technical = 'technical';
    case Sales = 'sales';

    public function label(): string
    {
        return match ($this) {
            self::Support => 'Support',
            self::Billing => 'Billing',
            self::Technical => 'Technical',
            self::Sales => 'Sales',
        };
    }
}
