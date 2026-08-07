<?php

declare(strict_types=1);

namespace LaravelNecromancer\Diff;

use LaravelNecromancer\Manifest\ArtifactId;

final class ManifestDiffer
{
    public function __construct(private readonly ArtifactId $artifactId = new ArtifactId) {}

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
            $baseOfType = $this->artifactId->assign([$type => $baseArtifacts[$type] ?? []])[$type] ?? [];
            $headOfType = $this->artifactId->assign([$type => $headArtifacts[$type] ?? []])[$type] ?? [];

            $baseIndexed = $this->index($type, $baseOfType);
            $headIndexed = $this->index($type, $headOfType);

            // Find additions (in head but not in base)
            foreach ($headIndexed as $key => $headArtifact) {
                if (! isset($baseIndexed[$key])) {
                    $added[$type][] = $headArtifact;
                }
            }

            // Find removals (in base but not in head)
            foreach ($baseIndexed as $key => $baseArtifact) {
                if (! isset($headIndexed[$key])) {
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

        return new ManifestDiff($added, $removed, $changed);
    }

    private function canonicalKey(string $type, array $artifact): string
    {
        return (string) ($artifact['id'] ?? '');
    }

    private function index(string $type, array $artifacts): array
    {
        $indexed = [];
        foreach ($artifacts as $artifact) {
            $key = $this->canonicalKey($type, $artifact);
            if ($key === '') {
                throw new \InvalidArgumentException("Artifact of type '$type' has no Artifact ID: ".json_encode($artifact, JSON_THROW_ON_ERROR));
            }
            $indexed[$key] = $artifact;
        }

        return $indexed;
    }
}
