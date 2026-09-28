<?php

declare(strict_types=1);

final class MustacheRenderer {
    public function __construct(private readonly string $templatePath) {
    }

    public function render(string $template, array $context = []): string {
        return $this->renderString($this->loadTemplate($template), $context);
    }

    private function loadTemplate(string $template): string {
        if ($template === '' || str_contains($template, '..') || !preg_match('~^[A-Za-z0-9_./-]+$~', $template)) {
            throw new InvalidArgumentException('Nome de template inválido.');
        }

        $file = rtrim($this->templatePath, '/\\') . '/' . ltrim($template, '/');
        if (!str_ends_with($file, '.mustache')) {
            $file .= '.mustache';
        }
        if (!is_file($file)) {
            throw new RuntimeException('Template não encontrado: ' . $template);
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException('Não foi possível ler o template: ' . $template);
        }
        return $contents;
    }

    private function renderString(string $template, array $context): string {
        $template = preg_replace_callback(
            '/{{>\s*([A-Za-z0-9_.\/-]+)\s*}}/',
            fn(array $match): string => $this->renderString($this->loadTemplate($match[1]), $context),
            $template
        ) ?? $template;

        $sectionPattern = '/{{\s*([#^])\s*([A-Za-z0-9_.]+)\s*}}(.*?){{\s*\/\s*\2\s*}}/s';
        while (preg_match($sectionPattern, $template)) {
            $template = preg_replace_callback($sectionPattern, function(array $match) use ($context): string {
                $inverted = $match[1] === '^';
                $name = $match[2];
                $body = $match[3];
                $value = $this->resolve($context, $name);
                $truthy = $this->isTruthy($value);

                if ($inverted) {
                    return $truthy ? '' : $this->renderString($body, $context);
                }
                if (!$truthy) {
                    return '';
                }
                if (is_array($value) && array_is_list($value)) {
                    $output = '';
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $output .= $this->renderString($body, array_merge($context, $item));
                        } else {
                            $output .= $this->renderString($body, array_merge($context, ['.' => $item]));
                        }
                    }
                    return $output;
                }
                if (is_array($value)) {
                    return $this->renderString($body, array_merge($context, $value));
                }
                return $this->renderString($body, $context);
            }, $template) ?? $template;
        }

        $template = preg_replace('/{{!.*?}}/s', '', $template) ?? $template;
        $template = preg_replace_callback('/{{{\s*([A-Za-z0-9_.]+)\s*}}}/', fn(array $match): string => $this->stringify($this->resolve($context, $match[1])), $template) ?? $template;
        $template = preg_replace_callback('/{{&\s*([A-Za-z0-9_.]+)\s*}}/', fn(array $match): string => $this->stringify($this->resolve($context, $match[1])), $template) ?? $template;
        $template = preg_replace_callback('/{{\s*([A-Za-z0-9_.]+)\s*}}/', fn(array $match): string => htmlspecialchars($this->stringify($this->resolve($context, $match[1])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $template) ?? $template;

        return $template;
    }

    private function resolve(array $context, string $name): mixed {
        if ($name === '.') {
            return $context['.'] ?? null;
        }
        $value = $context;
        foreach (explode('.', $name) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }
        return $value;
    }

    private function isTruthy(mixed $value): bool {
        if ($value === null || $value === false) {
            return false;
        }
        if (is_array($value)) {
            return $value !== [];
        }
        if (is_string($value)) {
            return $value !== '';
        }
        return true;
    }

    private function stringify(mixed $value): string {
        if ($value === null || $value === false) {
            return '';
        }
        if ($value === true) {
            return '1';
        }
        if (is_scalar($value)) {
            return (string)$value;
        }
        return '';
    }
}
