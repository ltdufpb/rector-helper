<?php

declare(strict_types=1);

namespace EduDeps\Menu;

use EduDeps\Resolver\PathResolver;

final class MenuMapSeedCollector
{
    /**
     * @return list<string>
     */
    public function collect(
        string $htmlFile,
        string $projectRoot,
        ?string $areaFilter = null,
        ?string $moduleFilter = null,
        ?string $menuPathContains = null
    ): array {
        $data = $this->readMapData($htmlFile);
        if ($data === null) {
            return [];
        }

        $files = [];
        foreach (($data['areas'] ?? []) as $area) {
            if (!is_array($area) || !$this->matches((string) ($area['nome'] ?? ''), $areaFilter)) {
                continue;
            }

            foreach (($area['modules'] ?? []) as $module) {
                if (!is_array($module) || !$this->matches((string) ($module['nome'] ?? ''), $moduleFilter)) {
                    continue;
                }

                $this->collectFromNodes($module['children'] ?? [], $projectRoot, $menuPathContains, $files);
            }
        }

        ksort($files);
        return array_values($files);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readMapData(string $htmlFile): ?array
    {
        if (!is_file($htmlFile)) {
            return null;
        }

        $html = file_get_contents($htmlFile);
        if ($html === false) {
            return null;
        }

        $json = $this->extractJsonScript($html);
        if ($json === null) {
            return null;
        }

        $data = json_decode($json, true, 4096, JSON_INVALID_UTF8_SUBSTITUTE);
        return is_array($data) ? $data : null;
    }

    private function extractJsonScript(string $html): ?string
    {
        $start = strpos($html, '<script id="dados-json"');
        if ($start === false) {
            return null;
        }

        $openEnd = strpos($html, '>', $start);
        if ($openEnd === false) {
            return null;
        }

        $close = strpos($html, '</script>', $openEnd);
        if ($close === false) {
            return null;
        }

        return substr($html, $openEnd + 1, $close - $openEnd - 1);
    }

    /**
     * @param mixed $nodes
     * @param array<string, string> $files
     */
    private function collectFromNodes($nodes, string $projectRoot, ?string $menuPathContains, array &$files): void
    {
        if (!is_array($nodes)) {
            return;
        }

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $detail = $node['detail'] ?? null;
            if (is_array($detail)) {
                $menuPath = (string) ($detail['caminho_menu'] ?? '');
                if ($this->matches($menuPath, $menuPathContains)) {
                    $file = (string) ($detail['arquivo_limpo'] ?? $detail['arquivo_base'] ?? '');
                    if ($file !== '' && preg_match('/\.php$/i', $file) === 1) {
                        $absolute = $projectRoot . '/' . ltrim(PathResolver::normalize($file), '/');
                        $real = realpath($absolute);
                        if ($real !== false && is_file($real)) {
                            $files[PathResolver::normalize($real)] = PathResolver::normalize($real);
                        }
                    }
                }
            }

            $this->collectFromNodes($node['children'] ?? [], $projectRoot, $menuPathContains, $files);
        }
    }

    private function matches(string $value, ?string $filter): bool
    {
        if ($filter === null || trim($filter) === '') {
            return true;
        }

        return strpos($this->normalize($value), $this->normalize($filter)) !== false;
    }

    private function normalize(string $value): string
    {
        $value = trim($value);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii !== false) {
            $value = $ascii;
        }

        return mb_strtolower($value);
    }
}
