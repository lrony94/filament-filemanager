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
     * Optional alt field name to bind as a sibling of the current state path.
     * For example, if statePath is "overview.0.image" and altFieldName is "alt",
     * then the alt state path will be "overview.0.alt". When null, defaults to
     * using a suffix on the current state path: statePath + "_alt".
     */
    protected ?string $altFieldName = null;
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
     * Configure the sibling field name to use for alt text.
     * e.g. altField('alt') -> overview.0.alt
     */
    public function altField(string $name): static
    {
        $this->altFieldName = $name;
        return $this;
    }

    public function getAltFieldName(): ?string
    {
        return $this->altFieldName;
    }

    /**
     * Compute the Livewire state path for the alt field.
     */
    public function getAltStatePath(): string
    {
        $path = (string) $this->getStatePath();
        if (!empty($this->altFieldName)) {
            $parts = explode('.', $path);
            if (count($parts) > 0) {
                // replace the last segment with the alt field name
                $parts[count($parts) - 1] = $this->altFieldName;
                return implode('.', $parts);
            }
            return $this->altFieldName;
        }
        return $path . '_alt';
    }

    /**
     * Compute the HTML name attribute for the alt field.
     */
    public function getAltName(): string
    {
        $name = (string) $this->getName();
        if (!empty($this->altFieldName)) {
            // Try bracket notation replacement if present: foo[bar][image] -> foo[bar][alt]
            if (str_contains($name, ']')) {
                // Replace the last bracket segment
                $pos = strrpos($name, '[');
                if ($pos !== false) {
                    $prefix = substr($name, 0, $pos);
                    return $prefix . '[' . $this->altFieldName . ']';
                }
            }
            // Fallback: dot notation
            $parts = explode('.', $name);
            if (count($parts) > 0) {
                $parts[count($parts) - 1] = $this->altFieldName;
                return implode('.', $parts);
            }
            return $this->altFieldName;
        }
        return $name . '_alt';
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
     * 在视图中获取已解析的 previewData。
     * 如果是 callable，使用 Filament 的 evaluate 以便注入 $get，并同时提供 record。
     */
    public function getPreviewData()
    {
        if (is_callable($this->previewData)) {
            try {
                // 优先使用 evaluate 以支持 $get 注入；同时提供 record 变量
                if (method_exists($this, 'evaluate')) {
                    return $this->evaluate($this->previewData, ['record' => $this->getRecord()]);
                }
                // 回退：保持兼容仅接受 $record 的闭包
                return call_user_func($this->previewData, $this->getRecord());
            } catch (\Throwable $e) {
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
                // 保留原有结构（字符串或对象），避免将对象强制转为字符串导致 alt 丢失
                // 同时重建索引，保持顺序一致
                $normalized = array_values($state);

                // 服务端预回填 alt：避免首次渲染仅在前端写入导致 Livewire 状态不含 alt，后续交互被覆盖清空
                try {
                    $previewData = null;
                    if (method_exists($component, 'getPreviewData')) {
                        $previewData = $component->getPreviewData();
                    }

                    $normalizePath = function ($p) {
                        $s = (string) $p;
                        // 去掉协议+域名
                        $s = preg_replace('/^https?:\/\/[^\/]+/i', '', $s);
                        // 统一前导斜杠
                        $s = preg_replace('/^\/+/', '/', $s);
                        // 去除 storage/public 前缀
                        $s = preg_replace('/^\/(storage|public)\//i', '', $s);
                        return $s;
                    };

                    $getAlt = function ($path) use ($previewData, $normalizePath) {
                        if (empty($previewData)) return '';
                        $nPath = $normalizePath($path);

                        // 结构：thumb/origin/alt 为数组
                        if (is_array($previewData) && array_key_exists('thumb', $previewData) && array_key_exists('origin', $previewData)) {
                            $origins = is_array($previewData['origin']) ? $previewData['origin'] : [$previewData['origin']];
                            $thumbs = is_array($previewData['thumb']) ? $previewData['thumb'] : [$previewData['thumb']];
                            $alts   = isset($previewData['alt']) ? (is_array($previewData['alt']) ? $previewData['alt'] : [$previewData['alt']]) : [];

                            $idx = null;
                            // exact origin
                            foreach ($origins as $i => $o) {
                                if ((string) $o === $path) { $idx = $i; break; }
                            }
                            // normalized origin
                            if ($idx === null) {
                                foreach ($origins as $i => $o) {
                                    if ($normalizePath($o) === $nPath) { $idx = $i; break; }
                                }
                            }
                            // basename origin
                            if ($idx === null) {
                                $base = basename($nPath);
                                foreach ($origins as $i => $o) {
                                    if (basename($normalizePath($o)) === $base) { $idx = $i; break; }
                                }
                            }
                            // fallback via thumbs
                            if ($idx === null) {
                                foreach ($thumbs as $i => $t) {
                                    if ((string) $t === $path || $normalizePath($t) === $nPath || basename($normalizePath($t)) === basename($nPath)) { $idx = $i; break; }
                                }
                            }

                            if ($idx !== null && isset($alts[$idx])) {
                                return (string) ($alts[$idx] ?? '');
                            }
                            // 兜底：位置对齐
                            if (!empty($alts)) {
                                $first = $alts[0] ?? '';
                                return (string) $first;
                            }
                            return '';
                        }

                        // 结构：以路径为键的关联数组（含 thumb/origin/alt）
                        if (is_array($previewData)) {
                            $entry = $previewData[$path] ?? null;
                            if (!$entry) {
                                // normalized key 匹配
                                foreach ($previewData as $k => $v) {
                                    if ($normalizePath($k) === $nPath) { $entry = $v; break; }
                                }
                                // basename 匹配
                                if (!$entry) {
                                    $base = basename($nPath);
                                    foreach ($previewData as $k => $v) {
                                        if (basename($normalizePath($k)) === $base) { $entry = $v; break; }
                                    }
                                }
                            }
                            if (is_array($entry)) {
                                $alt = $entry['alt'] ?? '';
                                if (is_array($alt)) return (string) ($alt[0] ?? '');
                                return (string) ($alt ?? '');
                            }
                        }

                        return '';
                    };

                    foreach ($normalized as $i => $item) {
                        if (is_string($item)) {
                            $normalized[$i] = [ 'path' => $item, 'alt' => $getAlt($item) ];
                        } elseif (is_array($item)) {
                            $hasAltKey = array_key_exists('alt', $item);
                            if (! $hasAltKey) {
                                $p = $item['path'] ?? ($item['url'] ?? '');
                                $item['alt'] = $getAlt($p);
                                $normalized[$i] = $item;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // 预回填失败则保持原逻辑
                }

                $component->state(json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
