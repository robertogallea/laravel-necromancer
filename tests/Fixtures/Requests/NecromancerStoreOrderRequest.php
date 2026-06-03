<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class NecromancerStoreOrderRequest extends FormRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => 'required|integer',
            'total' => 'required|numeric',
        ];
    }
}
