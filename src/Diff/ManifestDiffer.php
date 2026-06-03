<?php

declare(strict_types=1);

namespace LaravelNecromancer\Diff;

final class ManifestDiffer
{
    public function diff(array $baseArtifacts, array $headArtifacts): ManifestDiff
    {
        $allTypes = array_unique(array_merge(
            array_keys($baseArtifacts),
            array_keys($headArtifacts)
        ));

        $added = [];
        $removed = [];
        $changed = [];

        foreach ($allTypes as $type) {
            $baseOfType = $baseArtifacts[$type] ?? [];
            $headOfType = $headArtifacts[$type] ?? [];

            $baseIndexed = $this->index($type, $baseOfType);
            $headIndexed = $this->index($type, $headOfType);

            // Find additions (in head but not in base)
            foreach ($headIndexed as $key => $headArtifact) {
                if (!isset($baseIndexed[$key])) {
                    $added[$type][] = $headArtifact;
                }
            }

            // Find removals (in base but not in head)
            foreach ($baseIndexed as $key => $baseArtifact) {
                if (!isset($headIndexed[$key])) {
                    $removed[$type][] = $baseArtifact;
                }
            }

            // Find changes (in both but different)
            foreach ($baseIndexed as $key => $baseArtifact) {
                if (isset($headIndexed[$key])) {
                    if (json_encode($baseArtifact, JSON_THROW_ON_ERROR) !== json_encode($headIndexed[$key], JSON_THROW_ON_ERROR)) {
                        $changed[$type][] = [
                            'from' => $baseArtifact,
                            'to' => $headIndexed[$key],
                        ];
                    }
                }
            }
        }

        return new ManifestDiff(
            array_filter($added, fn ($items) => !empty($items)),
            array_filter($removed, fn ($items) => !empty($items)),
            array_filter($changed, fn ($items) => !empty($items)),
        );
    }

    private function canonicalKey(string $type, array $artifact): string
    {
        return match ($type) {
            'routes' => ($artifact['method'] ?? '') . ':' . ($artifact['uri'] ?? ''),
            default  => $artifact['class'] ?? $artifact['signature'] ?? '',
        };
    }

    private function index(string $type, array $artifacts): array
    {
        $indexed = [];
        foreach ($artifacts as $artifact) {
            $key = $this->canonicalKey($type, $artifact);
            if ($key === '') {
                throw new \InvalidArgumentException("Artifact of type '$type' has no canonical key (missing 'class', 'signature', 'method', or 'uri'): " . json_encode($artifact, JSON_THROW_ON_ERROR));
            }
            $indexed[$key] = $artifact;
        }
        return $indexed;
    }
}
