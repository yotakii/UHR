<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** «uhr.changed» على قناة الشركة: أسماء الجداول التي تغيّرت، ومن غيّرها (ليتجاهلها جهازه) */
class Changed implements ShouldBroadcastNow
{
    public function __construct(public int $companyId, public array $tables, public ?int $by = null) {}

    public function broadcastOn(): array { return [new PrivateChannel("company.{$this->companyId}")]; }

    public function broadcastAs(): string { return 'uhr.changed'; }

    public function broadcastWith(): array { return ['tables' => $this->tables, 'by' => $this->by]; }
}
