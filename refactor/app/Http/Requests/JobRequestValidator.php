<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JobRequestValidator extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        // Add authorization logic if needed
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'from_language_id' => 'required',
            'due_date' => 'required_unless:immediate,yes',
            'due_time' => 'required_unless:immediate,yes',
            'customer_phone_type' => 'required_without_all:customer_physical_type',
            'duration' => 'required',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'from_language_id.required' => 'Du måste fylla in alla fält',
            'due_date.required_unless' => 'Du måste fylla in alla fält',
            'due_time.required_unless' => 'Du måste fylla in alla fält',
            'customer_phone_type.required_without_all' => 'Du måste göra ett val här',
            'duration.required' => 'Du måste fylla in alla fält',
        ];
    }
}
