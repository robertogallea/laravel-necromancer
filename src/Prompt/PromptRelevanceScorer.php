<?php

declare(strict_types=1);

namespace LaravelNecromancer\Prompt;

final class PromptRelevanceScorer
{
    /**
     * @var list<string>
     */
    private const HIGH_WEIGHT_FIELDS = ['class', 'name', 'signature'];

    /**
     * @var list<string>
     */
    private const MID_WEIGHT_FIELDS = ['description', 'uri', 'action', 'controller'];

    /**
     * @param  array<string, list<array<string, mixed>>>  $artifacts  The manifest artifacts array (e.g. $manifest['artifacts'])
     * @param  string  $query  The raw user query
     * @param  int  $top  Maximum number of results to return
     * @return list<array{type: string, artifact: array<string, mixed>, score: int}>
     */
    public function score(array $artifacts, string $query, int $top): array
    {
        $keywords = array_filter(
            array_map('trim', str_getcsv($query, ' ')),
            fn ($k) => $k !== ''
        );

        if (empty($keywords)) {
            return [];
        }

        $keywords = array_map('strtolower', $keywords);
        $results = [];

        foreach ($artifacts as $type => $items) {
            foreach ($items as $artifact) {
                $score = $this->scoreItem($artifact, $keywords);

                if ($score > 0) {
                    $results[] = [
                        'type' => $type,
                        'artifact' => $artifact,
                        'score' => $score,
                    ];
                }
            }
        }

        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, $top);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $keywords
     */
    private function scoreItem(array $item, array $keywords): int
    {
        $score = 0;

        foreach ($item as $field => $value) {
            if ($field === 'source') {
                continue;
            }

            if ($field === 'route_metadata') {
                $score += $this->scoreRouteMetadata($value, $keywords);

                continue;
            }

            if ($field === 'annotations') {
                $score += $this->scoreAnnotations($value, $keywords);

                continue;
            }

            $weight = match (true) {
                in_array($field, self::HIGH_WEIGHT_FIELDS, true) => 3,
                in_array($field, self::MID_WEIGHT_FIELDS, true) => 2,
                default => 1,
            };

            $text = is_array($value) ? (json_encode($value) ?: '') : (string) $value;

            $score += $this->matchWeight($text, $keywords, $weight);
        }

        return $score;
    }

    /**
     * Resolved Artifact Annotations are a stronger relevance signal than an
     * inferred/observed field, since they're the developer explicitly naming
     * the domain/flow/capability an artifact belongs to (AN-SEARCH-002) —
     * scored the same way for every artifact type, not just routes.
     *
     * @param  list<string>  $keywords
     */
    private function scoreAnnotations(mixed $value, array $keywords): int
    {
        if (! is_array($value)) {
            return 0;
        }

        $score = 0;

        foreach (['domain', 'flow', 'capability'] as $field) {
            $score += $this->matchWeight((string) ($value[$field] ?? ''), $keywords, 3);
        }

        $score += $this->matchWeight((string) ($value['summary'] ?? ''), $keywords, 2);
        $score += $this->matchWeight((string) ($value['risk'] ?? ''), $keywords, 1);
        $score += $this->matchWeight(implode(',', (array) ($value['external_services'] ?? [])), $keywords, 1);
        $score += $this->matchWeight(implode(',', (array) ($value['adrs'] ?? [])), $keywords, 1);

        return $score;
    }

    /**
     * Raw route metadata stays searchable at the default weight (AN-SEARCH-003),
     * but its reserved `necromancer` namespace is excluded here — that data is
     * already scored, at differentiated weights, via the resolved `annotations`
     * field, and must not be counted a second time.
     *
     * @param  list<string>  $keywords
     */
    private function scoreRouteMetadata(mixed $value, array $keywords): int
    {
        if (! is_array($value)) {
            return 0;
        }

        $raw = is_array($value['raw'] ?? null) ? $value['raw'] : [];
        unset($raw['necromancer']);

        return $this->matchWeight(json_encode($raw) ?: '', $keywords, 1);
    }

    /**
     * @param  list<string>  $keywords
     */
    private function matchWeight(string $text, array $keywords, int $weight): int
    {
        if ($text === '') {
            return 0;
        }

        $text = strtolower($text);
        $score = 0;

        foreach ($keywords as $keyword) {
            if (str_contains($text, $keyword)) {
                $score += $weight;
            }
        }

        return $score;
    }
}
