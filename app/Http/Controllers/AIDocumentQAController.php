<?php

namespace App\Http\Controllers;

use App\Actions\AskAIDocument;
use App\Actions\DeleteAIDocument;
use App\Actions\UploadAIDocument;
use App\Http\Requests\AskAIDocumentRequest;
use App\Http\Requests\StoreAIDocumentRequest;
use App\Models\AiRun;
use App\Models\UploadedDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AIDocumentQAController extends Controller
{
    public function __construct(
        private readonly UploadAIDocument $upload,
        private readonly AskAIDocument $ask,
        private readonly DeleteAIDocument $delete,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $documents = UploadedDocument::query()
            ->ownedBy($user)
            ->latest()
            ->get()
            ->map(fn (UploadedDocument $document): array => [
                'id' => $document->id,
                'file_name' => $document->file_name,
                'created_at' => $document->created_at?->toIso8601String(),
            ])
            ->values();

        $lastAnswer = session('ai_document_answer');
        session()->forget('ai_document_answer');

        return Inertia::render('ai/DocumentQA', [
            'documents' => $documents,
            'storeReady' => $user->ai_document_store_id !== null,
            'lastQuestion' => session('ai_document_question'),
            'lastAnswer' => $lastAnswer,
            'lastDocumentId' => session('ai_document_document_id'),
            'lastRunStatus' => session('ai_document_status'),
            'pendingAiRun' => $this->pendingAiRunPayload($user),
        ]);
    }

    public function store(StoreAIDocumentRequest $request): RedirectResponse
    {
        $this->upload->execute(
            $request->user(),
            $request->file('document'),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Document uploaded and indexed.'),
        ]);

        return to_route('ai.documents.index');
    }

    public function ask(AskAIDocumentRequest $request): RedirectResponse
    {
        $user = $request->user();
        $document = $this->resolveDocument($user, $request);

        if ($user->ai_document_store_id === null) {
            return to_route('ai.documents.index')
                ->with('toast', [
                    'type' => 'error',
                    'message' => __('Upload a document before asking a question.'),
                ]);
        }

        $result = $this->ask->execute(
            $user,
            $request->validated('question'),
            $document,
        );

        $redirect = to_route('ai.documents.index')
            ->with('ai_document_question', $request->validated('question'))
            ->with('ai_document_document_id', $document?->id)
            ->with('ai_document_status', $result['status']);

        if ($result['status'] === 'succeeded') {
            $redirect->with('ai_document_answer', $result['answer']);
        } else {
            $redirect->with('toast', [
                'type' => 'info',
                'message' => __('AI provider is slow or unavailable. Answer is queued for background processing.'),
            ]);
        }

        return $redirect;
    }

    public function destroy(Request $request, UploadedDocument $document): RedirectResponse
    {
        $this->delete->execute($request->user(), $document);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Document deleted.'),
        ]);

        return to_route('ai.documents.index');
    }

    private function resolveDocument($user, Request $request): ?UploadedDocument
    {
        $documentId = $request->input('document_id');

        if ($documentId === null || $documentId === '' || $documentId === 'all') {
            return null;
        }

        return UploadedDocument::query()
            ->ownedBy($user)
            ->find($documentId);
    }

    /**
     * Return the user's latest document Q&A run whose work is still in flight,
     * or `null` when nothing is pending. The Vue page polls while this is set.
     *
     * @return array{id: int, status: string}|null
     */
    private function pendingAiRunPayload($user): ?array
    {
        $run = AiRun::query()
            ->where('user_id', $user->id)
            ->where('feature', 'document-qa')
            ->whereIn('status', ['running', 'queued'])
            ->latest('id')
            ->first();

        if ($run === null) {
            return null;
        }

        return [
            'id' => $run->id,
            'status' => $run->status,
        ];
    }
}
