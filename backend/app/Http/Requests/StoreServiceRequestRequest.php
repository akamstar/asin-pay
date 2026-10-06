<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceRequestRequest extends FormRequest
{
    /**
     * Le service est public : aucune authentification n'est requise.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'service_code' => [
                'required',
                'string',
                Rule::exists('services', 'code')->where('is_active', true),
            ],
            'quantity' => [
                'required',
                'integer',
                'min:1',
                'max:'.config('service_requests.max_quantity'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'service_code.required' => 'Veuillez choisir un service.',
            'service_code.string' => 'Le service choisi est invalide.',
            'service_code.exists' => "Ce service n'existe pas ou n'est plus disponible.",
            'quantity.required' => 'Veuillez indiquer une quantité.',
            'quantity.integer' => 'La quantité doit être un nombre entier.',
            'quantity.min' => 'La quantité doit être au moins :min.',
            'quantity.max' => 'La quantité ne peut pas dépasser :max.',
        ];
    }
}
