<?php

declare(strict_types=1);

namespace App\Http\Requests\Scrape;

use Illuminate\Foundation\Http\FormRequest;

class StartCrawlerTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'taskId' => [
                'required',
                'string',
                'max:191',
                'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,190}\z/',
            ],
            'options' => ['nullable', 'array'],
        ];
    }

    public function taskId(): string
    {
        return (string) $this->validated('taskId');
    }

    /** @return array<string, mixed> */
    public function options(): array
    {
        $options = $this->validated('options');

        return is_array($options) ? $options : [];
    }
}
