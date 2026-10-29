<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\NonNegativeAmount;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class PostbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        // Postbacks are defined by URL query parameters, never by a GET body.
        return $this->query();
    }

    public function rules(): array
    {
        return [
            'click_id' => $this->identityRules(),
            'event' => $this->identityRules(),
            'amount' => ['bail', 'required', 'string', new NonNegativeAmount],
        ];
    }

    private function identityRules(): array
    {
        return ['bail', 'required', 'string', 'max:255', 'not_regex:/[\x00-\x1f\x7f]/', 'regex://u'];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'message' => 'Invalid parameters',
        ], 422));
    }
}
