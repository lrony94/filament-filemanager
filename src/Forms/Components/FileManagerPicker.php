<?php

namespace Lrony94\FilamentFileManager\Forms\Components;

use Filament\Forms\Components\Field;
use Illuminate\Support\Facades\Log;

class FileManagerPicker extends Field
{
    // Switch to new FileUpload-style blade view
    protected string $view = 'filament-filemanager::forms.file-manager-picker-upload';

    protected bool $isMultiple = false;

    /**
     * 启用多选模式。
     */
    public function multiple(bool $condition = true): static
    {
        $this->isMultiple = $condition;
        return $this;
    }

    // For debugging: log when multiple() is explicitly called
    public function setMultipleDebug(bool $condition = true): static
    {
        Log::debug('FileManagerPicker::setMultipleDebug called', [
            'name' => $this->getName() ?? null,
            'condition' => $condition,
        ]);
        $this->isMultiple = $condition;
        return $this;
    }

    public function isMultiple(): bool
    {
        return $this->isMultiple;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // 当启用多选时：
        // - afterStateHydrated: 如果后端给的是数组，则转成 JSON 字符串给前端隐藏 input 使用
        // - dehydrateStateUsing: 将前端 JSON 字符串解码为数组回传给后端
        $this->afterStateHydrated(function (self $component, $state) {
            // Log hydration state for debugging
            try {
                Log::debug('FileManagerPicker afterStateHydrated', [
                    'name' => $component->getName() ?? null,
                    'isMultiple' => $component->isMultiple ?? false,
                    'state_type' => is_array($state) ? 'array' : gettype($state),
                    'state_preview' => is_scalar($state) ? (string) $state : (is_array($state) ? array_slice($state, 0, 5) : null),
                ]);
            } catch (\Throwable $e) {
                // ignore logging errors
            }

            if ($component->isMultiple && is_array($state)) {
                // 规范为去重、重建索引
                $unique = array_values(array_unique(array_map('strval', $state)));
                $component->state(json_encode($unique));
            }
        });

        $this->dehydrateStateUsing(function ($state) {
            if ($this->isMultiple) {
                if (is_array($state)) return array_values($state);
                if (is_string($state) && str_starts_with(trim($state), '[')) {
                    $arr = json_decode($state, true);
                    return is_array($arr) ? array_values($arr) : [];
                }
                return $state ? [ (string) $state ] : [];
            }
            return $state;
        });
    }
}
