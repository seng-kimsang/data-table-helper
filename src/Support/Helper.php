<?php

namespace DataTableHelper\Kimsang\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ColumnHelper
{
    public static function dataHelper(
        Request $request,
        $model,
        array $searchableCols = [],
        bool $custom = true,
        array $searchRemoval = []
    ) {
        $perPage = $request->per_page ?: 25;
        $searchQuery = $request->filled('search_query')
            ? strip_tags(trim($request->search_query))
            : null;

        // Normalize model input
        if (is_string($model) && is_subclass_of($model, Model::class)) {
            $data = $model::query();
        } elseif ($model instanceof Builder) {
            $data = $model;
        } elseif ($model instanceof Model) {
            $data = $model->newQuery();
        } else {
            throw new InvalidArgumentException(
                'Second argument must be Eloquent Model class, instance, or Builder'
            );
        }

        /* ================= SEARCH ================= */
        if ($searchQuery && $searchableCols) {
            $rgx = array_merge($searchRemoval, ['\s+']);
            $rgx = '/(' . implode('|', $rgx) . ')/';

            $searchQuery = preg_split($rgx, $searchQuery, -1, PREG_SPLIT_NO_EMPTY);

            $data->where(function ($query) use ($searchableCols, $searchQuery) {
                foreach ($searchableCols as $index => $config) {
                    $column   = $config['col'];
                    $operator = strtolower($config['operator'] ?? 'cn');
                    $method   = $index === 0 ? 'where' : 'orWhere';

                    $query->$method(function ($q) use ($column, $operator, $searchQuery) {
                        foreach ($searchQuery as $sq) {
                            self::applySearch($q, $column, $operator, $sq);
                        }
                    });
                }
            });
        }

        /* ================= SORT ================= */
        if ($request->field && $request->direction) {
            $direction = strtolower($request->direction);
            if (in_array($direction, ['asc', 'desc'], true)) {
                $data->orderBy($request->field, $direction);
            }
        } else {
            $data->orderBy('id', 'desc');
        }

        return $custom
            ? self::customPage($data, $request)
            : $data->paginate($perPage);
    }

    /* ================= COLUMN WRAPPER ================= */

    public static function wrap(string $column): string
    {
        if (str_contains($column, '.')) {
            return implode('.', array_map(
                fn($c) => "\"$c\"",
                explode('.', $column)
            ));
        }

        return "\"$column\"";
    }

    /* ================= SEARCH APPLY ================= */

    /**
     * Apply a search condition to the query based on a given operator.
     *
     * This method supports case-insensitive searching across both text and
     * numeric database columns by safely casting values to TEXT when required.
     *
     * Supported operators:
     * - cn (contains): Matches values containing the search text anywhere
     * - sw (starts with): Matches values starting with the search text
     * - ew (ends with): Matches values ending with the search text
     * - eq (equals): Matches values exactly (recommended for numeric columns)
     *
     * PostgreSQL-safe:
     * - Uses ILIKE for case-insensitive matching
     * - Casts numeric columns to TEXT to avoid operator errors
     * - Uses parameter binding to prevent SQL injection
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $query
     * @param string $column   Database column name
     * @param string $operator Search operator (cn, sw, ew, eq)
     * @param string $value    Search value
     *
     * @return void
     */
    private static function applySearch($query, string $column, string $operator, string $value): void
    {
        $col = self::wrap($column);

        match ($operator) {
            'cn' => $query->whereRaw("CAST($col AS TEXT) ILIKE ?", ["%{$value}%"]),
            'sw' => $query->whereRaw("CAST($col AS TEXT) ILIKE ?", ["{$value}%"]),
            'ew' => $query->whereRaw("CAST($col AS TEXT) ILIKE ?", ["%{$value}"]),
            'eq' => $query->whereRaw("$col = ?", [$value]),
            default => null,
        };
    }

    /* ================= PAGINATION ================= */
    private static function customPage($model, $request = null)
    {
        $currentPage = optional($request)->pageNumber ?? 1;
        $perPage = optional($request)->pageSize ?? 25;
        $offset = ($currentPage - 1) * $perPage;
        $total = $model->count();

        $data = $model
            ->offset($offset)
            ->limit($perPage)
            ->get();

        $totalPages = ceil($total / $perPage);
        $prevPage = $currentPage > 1 ? $currentPage - 1 : null;
        $nextPage = $currentPage < $totalPages ? $currentPage + 1 : null;

        return response()->json([
            'data' => $data,
            'pagination' => [
                'total' => (int) $total,
                'currentPage' => (int) $currentPage,
                'perPage' => (int) $perPage,
                'totalPages' => (int) $totalPages,
                'prevPage' => $prevPage,
                'nextPage' => $nextPage,
            ]
        ]);
    }
}
