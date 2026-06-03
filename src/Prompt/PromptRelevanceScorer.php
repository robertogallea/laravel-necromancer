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

            $weight = match (true) {
                in_array($field, self::HIGH_WEIGHT_FIELDS, true) => 3,
                in_array($field, self::MID_WEIGHT_FIELDS, true) => 2,
                default => 1,
            };

            $text = is_array($value) ? strtolower(json_encode($value) ?: '') : strtolower((string) $value);

            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    $score += $weight;
                }
            }
        }

        return $score;
    }
}
