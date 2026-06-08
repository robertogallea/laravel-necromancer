<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Livewire\Forms;

use Illuminate\View\View;
use Livewire\Component;

final class NecromancerContactForm extends Component
{
    public string $email = '';

    public function submit(): void {}

    public function render(): View
    {
        return view('livewire.forms.necromancer-contact-form');
    }
}
