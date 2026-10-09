<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\Messaging\MessageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $messageId) {}

    public function handle(MessageService $service): void
    {
        $message = Message::withoutGlobalScopes()->find($this->messageId);
        if ($message && $message->status === 'queued') {
            $service->deliver($message);
        }
    }
}
