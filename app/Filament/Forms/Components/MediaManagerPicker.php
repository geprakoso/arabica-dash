<?php

namespace App\Filament\Forms\Components;

use Closure;
use Filament\Forms\Components\BaseFileUpload;
use TomatoPHP\FilamentMediaManager\Form\MediaManagerInput;

class MediaManagerPicker extends MediaManagerInput
{
    protected const PREVIEWABLE_FILE_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->schema([])
            ->acceptedFileTypes(static::PREVIEWABLE_FILE_TYPES)
            ->defaultItems(0)
            ->maxItems(1)
            ->dehydrated(false);
    }

    public function acceptedFileTypes(array | Closure $types): static
    {
        foreach ($this->getChildComponents() as $component) {
            if ($component instanceof BaseFileUpload) {
                $component->acceptedFileTypes($types);
            }
        }

        return $this;
    }
}
