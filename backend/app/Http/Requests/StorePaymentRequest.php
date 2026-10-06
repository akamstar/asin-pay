<?php

namespace App\Http\Requests;

use App\Enums\PaymentOperator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    public const PHONE_NUMBER_PATTERN = '/^01\d{8}$/';

    /**
     * Le service est public : aucune authentification n'est requise.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Tolère les séparateurs usuels de saisie (01 02 03 04 05, 01.02.03.04.05).
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone_number'))) {
            $this->merge(['phone_number' => preg_replace('/[\s.\-]/', '', $this->input('phone_number'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone_number' => ['required', 'string', 'regex:'.self::PHONE_NUMBER_PATTERN],
            'operator' => ['required', Rule::enum(PaymentOperator::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone_number.required' => 'Veuillez saisir votre numéro de téléphone.',
            'phone_number.string' => 'Le numéro de téléphone est invalide.',
            'phone_number.regex' => 'Le numéro doit contenir 10 chiffres et commencer par 01.',
            'operator.required' => 'Veuillez choisir votre opérateur.',
            'operator.enum' => "Cet opérateur n'est pas pris en charge.",
        ];
    }
}
