<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Attributes\UseSmartestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Providers\Tools\FileSearchQuery;
use Stringable;
use Tool;

#[Provider(Lab::OpenAI)]
#[UseSmartestModel]
#[MaxTokens(1600)]
#[MaxSteps(3)]
#[Timeout(120)]
class DocumentQAAssistant implements Agent, HasTools
{
    use Promptable;

    public function __construct(
        public readonly int $userId,
        public readonly string $storeId,
        public readonly ?string $providerFileId = null,
    ) {}

    public function instructions(): Stringable|string
    {
        return 'You are a support documentation assistant. '
            .'Only answer using the uploaded documents available through the file search tool. '
            .'If the answer is not in the document(s), say you do not know. '
            .'After the answer, include a short sources list with file names.';
    }

    /**
     * @return Tool[]
     */
    public function tools(): iterable
    {
        $where = function (FileSearchQuery $query): void {
            $query->where('user_id', $this->userId);

            if ($this->providerFileId !== null) {
                $query->where('provider_file_id', $this->providerFileId);
            }
        };

        return [
            new FileSearch(
                stores: [$this->storeId],
                where: $where,
            ),
        ];
    }
}
