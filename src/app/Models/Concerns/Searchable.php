<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Case-insensitive prefix search shared by listing endpoints.
 */
trait Searchable
{
    /**
     * Keep rows where any of the columns starts with the term, case-insensitively; no-op without a term.
     *
     * @param  list<string>  $columns
     */
    public function scopeSearch(Builder $query, array $columns, ?string $term): void
    {
        if (empty($term)) {
            return;
        }

        $pattern = addcslashes($term, '\\%_').'%';

        $query->where(function (Builder $query) use ($columns, $pattern) {
            foreach ($columns as $column) {
                // lower() LIKE, not ILIKE, so a text_pattern_ops expression index can serve it (database.md §5).
                $query->orWhereRaw('lower('.$query->getGrammar()->wrap($column).') like lower(?)', [$pattern]);
            }
        });
    }
}
