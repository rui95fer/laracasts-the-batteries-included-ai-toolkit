<?php

namespace App;

enum TicketMessageType: string
{
    case CustomerMessage = 'customer_message';
    case AgentReply = 'agent_reply';
    case InternalNote = 'internal_note';
    case SystemMessage = 'system_message';

    public function label(): string
    {
        return match ($this) {
            self::CustomerMessage => 'Customer message',
            self::AgentReply => 'Agent reply',
            self::InternalNote => 'Internal note',
            self::SystemMessage => 'System message',
        };
    }
}
