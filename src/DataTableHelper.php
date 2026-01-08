<?php

namespace DataTableHelper\Kimsang;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Carbon\Carbon;
use InvalidArgumentException;

class DataTableHelper
{
    public static function getData(
        Request $request,
        $model,
        array $searchableCols = [],
        array $searchRemoval = []
    ) {


        $perPage = $request->per_page ?: 25;
        $data = null;
        $searchQuery = null;

        // 🔒 NORMALIZE MODEL INPUT
        if (is_string($model) && is_subclass_of($model, Model::class)) {
            // App\Models\User::class
            $data = $model::query();
        } elseif ($model instanceof Builder) {
            // User::query()
            $data = $model;
        } elseif ($model instanceof Model) {
            // new User()
            $data = $model->newQuery();
        } else {
            throw new InvalidArgumentException(
                'Second argument must be Eloquent Model class, instance, or Builder'
            );
        }

        if (!empty($request->search_query)) {
            $searchQuery = strip_tags(trim($request->search_query));
        }

        /* ================= SEARCH ================= */
        if ($searchQuery) {
            $rgx = array_merge($searchRemoval, ['s+']);
            foreach ($rgx as $i => $c) {
                $rgx[$i] = "\\" . $c;
            }

            $rgx = '/(' . join('|', $rgx) . ')/';
            $searchQuery = preg_split($rgx, $searchQuery, -1, PREG_SPLIT_NO_EMPTY);

            $data->where(function ($query) use ($searchableCols, $searchQuery) {
                foreach ($searchableCols as $index => $column) {
                    $method = $index === 0 ? 'where' : 'orWhere';

                    $query->$method(function ($q) use ($searchQuery, $column) {
                        foreach ($searchQuery as $sq) {
                            $q->whereRaw("LOWER($column) LIKE LOWER(?)", ["%$sq%"]);
                        }
                    });
                }
            });
        }

        /* ================= SORT ================= */
        if ($request->field && $request->direction) {
            $direction = strtolower($request->direction);
            if (in_array($direction, ['asc', 'desc'])) {
                $data->orderBy($request->field, $direction);
            }
        } else {
            $data->orderBy('id', 'desc');
        }

        /* ================= FILTER ================= */
        if ($filters = $request->filters) {
            $filters = json_decode($filters);

            foreach (get_object_vars($filters) as $key => $filter) {
                $constraints = $filter->constraints ?? null;

                if ($constraints) {
                    $data->where(function ($query) use ($constraints, $filter, $key) {
                        foreach ($constraints as $index => $model) {
                            if (!$model->value) continue;

                            $sql = self::getQuery($key, $model->matchMode, $model->value);
                            if (!$sql) continue;

                            if ($filter->operator === 'or' && $index > 0) {
                                $query->orWhereRaw($sql);
                            } else {
                                $query->whereRaw($sql);
                            }
                        }
                    });
                } else {
                    if (!$filter->value) continue;
                    $sql = self::getQuery($key, $filter->matchMode, $filter->value);
                    if ($sql) {
                        $data->whereRaw($sql);
                    }
                }
            }
        }

        return $data->paginate($perPage);
    }

    /* ================= QUERY BUILDER ================= */

    private static function getQuery($column, $operator, $value)
    {
        return match ($operator) {
            'startsWith' => "$column LIKE '$value%'",
            'endsWith' => "$column LIKE '%$value'",
            'contains' => "$column LIKE '%$value%'",
            'notContains' => "$column NOT LIKE '%$value%'",
            'equals' => "$column = '$value'",
            'notEquals' => "$column <> '$value'",
            'in' => "$column IN ('" . join("','", (array) $value) . "')",
            'lt' => "$column < '$value'",
            'lte' => "$column <= '$value'",
            'gt' => "$column > '$value'",
            'gte' => "$column >= '$value'",
            'dateAfter' => self::dateCompare($column, $value, '>'),
            'dateBefore' => self::dateCompare($column, $value, '<'),
            'dateIs' => self::dateBetween($column, $value),
            'dateIsNot' => self::dateNotBetween($column, $value),
            default => null
        };
    }

    private static function dateCompare($column, $value, $op)
    {
        $date = Carbon::createFromFormat('Y-m-d', $value)->toDateString();
        return "$column $op date '$date'";
    }

    private static function dateBetween($column, $value)
    {
        $from = Carbon::createFromFormat('Y-m-d', $value);
        $to = $from->copy()->addDay();
        return "$column >= date '{$from->toDateString()}' AND $column < date '{$to->toDateString()}'";
    }

    private static function dateNotBetween($column, $value)
    {
        $from = Carbon::createFromFormat('Y-m-d', $value);
        $to = $from->copy()->addDay();
        return "$column < date '{$from->toDateString()}' OR $column >= date '{$to->toDateString()}'";
    }
}
