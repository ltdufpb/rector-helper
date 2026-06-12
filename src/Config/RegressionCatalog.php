<?php

declare(strict_types=1);

namespace EduDeps\Config;

use Symfony\Component\Yaml\Yaml;

/**
 * Catalogo de regressoes conhecidas (config/regressions.yaml).
 *
 * Fonte unica de verdade para os comandos lint-php8 (deteccao previa) e
 * fix-regressions (correcao em massa). Adicionar uma regressao nova ao
 * catalogo nao exige mexer em codigo — apenas no YAML.
 */
final class RegressionCatalog
{
    private const REQUIRED_FIELDS = ['id', 'titulo', 'origem', 'sintoma', 'causa_raiz', 'deteccao', 'fix', 'prevencao', 'volume_ecidade', 'commits'];
    private const VALID_ORIGENS = ['php8', 'rector', 'hotfix'];
    private const VALID_DETECCAO_TIPOS = ['regex', 'ast', 'config'];

    /** @var list<array<string, mixed>> */
    private array $regressions;

    /**
     * @param list<array<string, mixed>> $regressions
     */
    private function __construct(array $regressions)
    {
        $this->regressions = $regressions;
    }

    public static function fromFile(string $yamlPath): self
    {
        if (!is_file($yamlPath)) {
            throw new \RuntimeException(sprintf('Catalogo de regressoes nao encontrado: %s', $yamlPath));
        }
        $data = Yaml::parseFile($yamlPath);
        if (!is_array($data) || !isset($data['regressions']) || !is_array($data['regressions'])) {
            throw new \RuntimeException(sprintf('Catalogo invalido (chave "regressions" ausente): %s', $yamlPath));
        }

        $seen = [];
        foreach ($data['regressions'] as $i => $entry) {
            foreach (self::REQUIRED_FIELDS as $field) {
                if (!isset($entry[$field])) {
                    throw new \RuntimeException(sprintf('Regressao #%d sem campo obrigatorio "%s"', $i, $field));
                }
            }
            if (!in_array($entry['origem'], self::VALID_ORIGENS, true)) {
                throw new \RuntimeException(sprintf('Regressao "%s": origem invalida "%s"', $entry['id'], $entry['origem']));
            }
            if (!isset($entry['deteccao']['tipo']) || !in_array($entry['deteccao']['tipo'], self::VALID_DETECCAO_TIPOS, true)) {
                throw new \RuntimeException(sprintf('Regressao "%s": deteccao.tipo invalido', $entry['id']));
            }
            if (isset($seen[$entry['id']])) {
                throw new \RuntimeException(sprintf('Regressao com id duplicado: "%s"', $entry['id']));
            }
            $seen[$entry['id']] = true;
        }

        return new self(array_values($data['regressions']));
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->regressions;
    }

    /** @return array<string, mixed>|null */
    public function findById(string $id): ?array
    {
        foreach ($this->regressions as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * @return list<array<string, mixed>> regressoes com padrao regex (para lint-php8)
     */
    public function withRegexDetection(): array
    {
        return array_values(array_filter(
            $this->regressions,
            static fn (array $entry): bool => $entry['deteccao']['tipo'] === 'regex'
        ));
    }

    public function count(): int
    {
        return count($this->regressions);
    }
}
