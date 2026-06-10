export type TicketOption = {
    value: string;
    label: string;
};

export type TicketTag = {
    name: string;
    slug: string;
};

export type TicketMessage = {
    id: number;
    type: string;
    type_label: string;
    body: string;
    author_name: string;
    author_email: string;
    created_at: string | null;
    updated_at: string | null;
};

export type Ticket = {
    id: number;
    number: string | null;
    subject: string;
    customer_name: string;
    customer_email: string;
    status: string;
    status_label: string;
    priority: string | null;
    priority_label: string | null;
    department: string | null;
    department_label: string | null;
    sentiment: string | null;
    sentiment_label: string | null;
    last_message_at: string | null;
    closed_at: string | null;
    created_at: string | null;
    updated_at: string | null;
    messages_count: number | null;
    tags: TicketTag[];
    messages?: TicketMessage[];
};

export type TicketOptions = {
    statuses: TicketOption[];
    priorities: TicketOption[];
    departments: TicketOption[];
    sentiments: TicketOption[];
    messageTypes: TicketOption[];
};

export type PaginatedTickets = {
    data: Ticket[];
    current_page: number;
    from: number | null;
    last_page: number;
    next_page_url: string | null;
    per_page: number;
    prev_page_url: string | null;
    to: number | null;
    total: number;
};
