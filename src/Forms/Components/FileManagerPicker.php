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
     * 静态或动态提供的预览数据。可以是：
     * - null: 使用默认行为（通过 file-preview 路由/previewBase 构造预览 URL）
     * - array: 直接作为前端的 previewData
     * - callable: 接受 record 参数并返回数组
     */
    protected $previewData = null;

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
        $this->isMultiple = $condition;
        return $this;
    }

    public function isMultiple(): bool
    {
        return $this->isMultiple;
    }

    /**
     * 设置 previewData，可以传入数组或 Closure
     */
    public function previewData($data): static
    {
        $this->previewData = $data;
        return $this;
    }

    /**
     * 在视图中获取已解析的 previewData。如果是 callable，会传入当前 record
     */
    public function getPreviewData()
    {
        if (is_callable($this->previewData)) {
            try {
                return call_user_func($this->previewData, $this->getRecord());
            } catch (\Throwable $e) {
                // 避免在表单渲染阶段抛出异常，记录并回退为 null
                Log::warning('FileManagerPicker previewData resolver failed: ' . $e->getMessage());
                return null;
            }
        }
        return $this->previewData;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // 当启用多选时：
        // - afterStateHydrated: 如果后端给的是数组，则转成 JSON 字符串给前端隐藏 input 使用
        // - dehydrateStateUsing: 将前端 JSON 字符串解码为数组回传给后端
        $this->afterStateHydrated(function (self $component, $state) {
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
