<?php
declare(strict_types=1);

namespace ZztestWeb\Web;

/**
 * Fixture web handler returning a JSON result instead of a template — the
 * options-refresh contract of UiKit reference fields (MODULE_UI.md): the
 * browser re-fetches `{options: [{value, label}]}` and rebuilds the select.
 */
final class OptionsPage
{
    /**
     * @param array<string, mixed> $request
     * @return array{json: array{options: list<array{value:string,label:string}>}}
     */
    public function handle(array $request): array
    {
        $query = is_array($request['query'] ?? null) ? $request['query'] : [];

        return ['json' => ['options' => [
            ['value' => 'a1', 'label' => 'Alpha'],
            ['value' => 'b2', 'label' => 'Beta ' . (string)($query['q'] ?? '')],
        ]]];
    }
}
