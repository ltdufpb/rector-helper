<?php

declare(strict_types=1);

namespace EduDeps\Parser;

/**
 * Carrega arquivos PHP do e-cidade convertendo ISO-8859-1 para UTF-8 quando
 * necessario (a maioria dos arquivos legados nao e UTF-8 e o php-parser
 * espera UTF-8).
 *
 * Cacheia o conteudo convertido em disco por sha1 para acelerar reexecucoes.
 */
final class EncodingLoader
{
    private ?string $cacheDir;

    public function __construct(?string $cacheDir = null)
    {
        $this->cacheDir = $cacheDir;
        if ($this->cacheDir !== null) {
            $this->cacheDir = rtrim(str_replace('\\', '/', $this->cacheDir), '/') . '/utf8';
            if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0777, true) && !is_dir($this->cacheDir)) {
                throw new \RuntimeException(sprintf('Falha ao criar cache dir: %s', $this->cacheDir));
            }
        }
    }

    /**
     * @return array{source:string,encoding:string,fromCache:bool}
     */
    public function load(string $absolutePath): array
    {
        $raw = @file_get_contents($absolutePath);
        if ($raw === false) {
            throw new \RuntimeException(sprintf('Falha ao ler %s', $absolutePath));
        }

        $hash = sha1($raw);
        $cacheFile = $this->cacheFileFor($hash);
        if ($cacheFile !== null && is_file($cacheFile)) {
            $cached = @file_get_contents($cacheFile);
            if ($cached !== false) {
                return [
                    'source' => $cached,
                    'encoding' => 'cached',
                    'fromCache' => true,
                ];
            }
        }

        $encoding = mb_detect_encoding($raw, ['UTF-8', 'ISO-8859-1'], true);
        $utf8 = $raw;
        if ($encoding === 'ISO-8859-1') {
            $converted = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $raw);
            if ($converted !== false) {
                $utf8 = $converted;
            }
        }

        if ($cacheFile !== null) {
            @file_put_contents($cacheFile, $utf8);
        }

        return [
            'source' => $utf8,
            'encoding' => $encoding ?: 'unknown',
            'fromCache' => false,
        ];
    }

    private function cacheFileFor(string $hash): ?string
    {
        if ($this->cacheDir === null) {
            return null;
        }
        return $this->cacheDir . '/' . $hash . '.php';
    }
}
