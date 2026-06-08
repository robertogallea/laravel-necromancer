<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Livewire;

use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class NecromancerIssueForm extends Component
{
    public string $title = '';

    public int $count = 0;

    public function save(): void {}

    #[On('issue-updated')]
    public function refresh(): void {}

    public function render(): View
    {
        return view('livewire.necromancer-issue-form');
    }
}
