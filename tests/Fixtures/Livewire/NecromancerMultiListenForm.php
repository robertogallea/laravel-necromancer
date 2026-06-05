<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Livewire;

use Livewire\Attributes\On;
use Livewire\Component;

final class NecromancerMultiListenForm extends Component
{
    #[On(['event-a', 'event-b'])]
    public function refresh(): void {}

    public function render(): \Illuminate\View\View
    {
        return view('livewire.necromancer-multi-listen-form');
    }
}
