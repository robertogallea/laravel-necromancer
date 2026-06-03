<?php

namespace LaravelNecromancer\Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\Attributes\ErrorBag;
use Illuminate\Foundation\Http\Attributes\StopOnFirstFailure;
use Illuminate\Foundation\Http\FormRequest;

#[StopOnFirstFailure]
#[ErrorBag('orderForm')]
class RequestWithValidationAttributes extends FormRequest
{
    public function rules(): array
    {
        return ['name' => 'required|string'];
    }
}
