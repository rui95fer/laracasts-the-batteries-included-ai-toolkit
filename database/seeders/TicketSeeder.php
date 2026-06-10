<?php

namespace Database\Seeders;

use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use App\TicketMessageType;
use App\TicketStatus;
use Illuminate\Database\Seeder;

class TicketSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'rui95fer@gmail.com'],
            ['name' => 'Rui Fernandes', 'password' => 'password']
        );

        $tags = collect([
            'Bug',
            'Billing',
            'Refund',
            'VIP',
            'Login',
            'Feature Request',
            'Onboarding',
            'Account',
            'Urgent',
            'Follow Up',
            'Documentation',
            'Performance',
        ])->map(fn (string $name): Tag => Tag::firstOrCreate(
            ['slug' => Tag::slugFor($name)],
            ['name' => $name]
        ));

        $tickets = [
            [
                'subject' => 'Charged twice for Pro subscription this month',
                'customer_name' => 'Sarah Johnson',
                'customer_email' => 'sarah.johnson@example.com',
                'initial_message' => 'I just checked my bank statement and noticed I was billed $29 twice for the Pro plan. One payment went through on the 1st and another on the 3rd. Please refund the duplicate charge as soon as possible. This is the second time this has happened.',
                'created_at' => now()->subDays(2),
            ],
            [
                'subject' => 'Cannot log in after password reset',
                'customer_name' => 'Miguel Torres',
                'customer_email' => 'mtorres@example.com',
                'initial_message' => 'I reset my password this morning using the forgot password link, but now I keep getting "Invalid credentials" when trying to log in. I\'ve tried on both Chrome and Safari, cleared my cache, and even used incognito mode. Can you help me regain access to my account?',
                'created_at' => now()->subDays(1),
            ],
            [
                'subject' => 'Feature request: dark mode toggle',
                'customer_name' => 'Aisha Patel',
                'customer_email' => 'aisha.p@example.com',
                'initial_message' => 'I work late nights and the bright interface is really straining my eyes. It would be great if you could add a dark mode toggle. I know several colleagues who would appreciate this as well. Any plans to support this?',
                'created_at' => now()->subDays(5),
            ],
            [
                'subject' => 'Account suspended without warning',
                'customer_name' => 'James O\'Brien',
                'customer_email' => 'james.obrien@example.com',
                'initial_message' => 'My account was suddenly suspended today without any prior notice or email explanation. I\'ve been a paying customer for over two years and I need this resolved urgently. I can\'t access any of my projects or data. Please investigate and reinstate my account.',
                'created_at' => now()->subHours(6),
            ],
            [
                'subject' => 'Slow page load times on the dashboard',
                'customer_name' => 'Emily Chen',
                'customer_email' => 'emily.chen@example.com',
                'initial_message' => 'The dashboard has been loading extremely slowly for the past week. Charts take 10-15 seconds to render and sometimes the page times out entirely. I\'ve tested on multiple networks and devices. Is there a known issue or is something wrong with my account?',
                'created_at' => now()->subDays(3),
            ],
            [
                'subject' => 'How to export all project data?',
                'customer_name' => 'David Kim',
                'customer_email' => 'david.kim@example.com',
                'initial_message' => 'I need to export all of my project data including files, comments, and activity history. I looked through the settings but only found an option to export individual projects. Is there a way to do a bulk export of everything at once? I need this for compliance purposes.',
                'created_at' => now()->subDays(7),
            ],
            [
                'subject' => 'Team member invitation not received',
                'customer_name' => 'Priya Sharma',
                'customer_email' => 'priya.sharma@example.com',
                'initial_message' => 'I invited two new team members to our workspace yesterday but they never received the invitation email. I\'ve checked their spam folders and tried resending multiple times. The invitations show as "pending" on my end. Can you check if there\'s an issue with the email delivery?',
                'created_at' => now()->subDays(4),
            ],
            [
                'subject' => 'Refund request for annual plan upgrade',
                'customer_name' => 'Carlos Mendez',
                'customer_email' => 'carlos.m@example.com',
                'initial_message' => 'I upgraded from the monthly Starter plan to the annual Pro plan three days ago, but I realized it doesn\'t have the features I need. I\'d like to request a refund and go back to my previous plan. The upgrade cost was $299. Please let me know if this is possible and what the process is.',
                'created_at' => now()->subDays(1)->subHours(12),
            ],
            [
                'subject' => 'API rate limit too restrictive',
                'customer_name' => 'Hannah Schmidt',
                'customer_email' => 'hannah.schmidt@example.com',
                'initial_message' => 'Our integration is hitting the API rate limit constantly even though we\'re within what I believe are reasonable usage patterns. We\'re making about 200 requests per minute but it seems like the limit is set much lower. Could you please review our account limits and let us know if they can be increased?',
                'created_at' => now()->subDays(6),
            ],
            [
                'subject' => 'Billing address update not saving',
                'customer_name' => 'Tom Williams',
                'customer_email' => 'tom.w@example.com',
                'initial_message' => 'I changed my billing address in the settings, saved it, got the green success message, but when I go back to the page it still shows the old address. I\'ve tried this four times now with the same result. My invoices are being sent to the wrong address because of this.',
                'created_at' => now()->subDay(),
            ],
        ];

        foreach ($tickets as $index => $data) {
            $createdAt = $data['created_at'];

            $ticket = Ticket::forceCreate([
                'user_id' => $user->id,
                'number' => sprintf('TCK-%06d', $index + 1),
                'subject' => $data['subject'],
                'customer_name' => $data['customer_name'],
                'customer_email' => $data['customer_email'],
                'status' => TicketStatus::Open,
                'priority' => null,
                'department' => null,
                'sentiment' => null,
                'last_message_at' => $createdAt,
                'closed_at' => null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            $ticket->messages()->create([
                'type' => TicketMessageType::CustomerMessage,
                'body' => $data['initial_message'],
                'author_name' => $data['customer_name'],
                'author_email' => $data['customer_email'],
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
    }
}
