<?php

namespace Database\Seeders;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Seeder;

class DocumentSeeder extends Seeder
{
    /**
     * Seed the application's knowledge base documents.
     */
    public function run(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'rui95fer@gmail.com'],
            ['name' => 'Rui Fernandes', 'password' => 'password']
        );

        $documents = [
            [
                'title' => 'Billing and refunds',
                'body' => 'Customers on monthly plans can request a refund within 14 days of being charged. Duplicate charges are refunded automatically and the user is notified by email.',
            ],
            [
                'title' => 'How to log in after a password reset',
                'body' => 'If the forgot-password link has been used but new credentials are rejected, ask the customer to clear cookies, try a private window, and confirm the latest reset email was used within the last 30 minutes.',
            ],
            [
                'title' => 'Requesting a feature',
                'body' => 'Feature requests are tracked through tags on tickets. When a customer asks for a new capability, tag the ticket "Feature Request" and add a short summary of the use case in the AI triage response.',
            ],
            [
                'title' => 'Account suspensions',
                'body' => 'Accounts may be suspended automatically when fraud signals trigger. A reinstated account preserves the original plan, project history, and team memberships.',
            ],
            [
                'title' => 'API rate limits',
                'body' => 'The default API rate limit is 60 requests per minute per token. Customers on the Pro plan can request an increase by replying to the rate-limit notification email with their average usage profile.',
            ],
            [
                'title' => 'Exporting project data',
                'body' => 'A bulk export of all project data, including files, comments, and activity history, can be generated from Settings > Data. Compliance exports are run weekly and the file is delivered as a zip archive.',
            ],
            [
                'title' => 'Team invitations',
                'body' => 'Team invitations are sent from the workspace owner email. If the invitee does not receive the message, ask them to check spam filters and confirm the address was typed correctly.',
            ],
        ];

        foreach ($documents as $data) {
            Document::query()->updateOrCreate(
                ['user_id' => $user->id, 'title' => $data['title']],
                ['body' => $data['body'], 'embedding' => null]
            );
        }
    }
}
