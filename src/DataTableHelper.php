<?php

namespace DataTableHelper\Kimsang;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

use InvalidArgumentException;

class DataTableHelper
{
    public static function getData(
        Request $request,
        $model,
        array $searchableCols = [],
        $custom = true,
        array $searchRemoval = [],
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

        if ($custom) {
            return self::customPage($data, $request);
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

    public static function  mergeResponseData(
        mixed $data,
        string $field,
        string $path,
        string $returnKey = 'sub_query',
        string $keyBy = "user_id"
    ) {
        if ($data instanceof \Illuminate\Http\JsonResponse) {
            $pageArray = $data->getData(true);
        } elseif ($data instanceof \Illuminate\Pagination\AbstractPaginator) {
            $pageArray = $data->toArray();
        } elseif (is_array($data)) {
            $pageArray = $data;
        } else {
            return $data;
        }

        if (empty($pageArray) || empty($field) || empty($pageArray['data'])) {
            return $pageArray;
        }

        $getData = $pageArray['data'];

        // 🔧 faster pure-PHP unique collection
        $userIds = [];
        foreach ($getData as $row) {
            $userIds[$row[$field]] = true;
        }

        $userIds = array_keys($userIds);

        $remoteMap = [];
        if (!empty($userIds)) {
            $response = Http::withHeaders(userHeaders())
                ->get($path, ['ids' => $userIds]);

            if ($response->failed()) {
                return $pageArray;
            }

            $remoteData = $response->json();

            if (!is_array($remoteData)) {
                return $pageArray;
            }

            $remoteMap = collect($remoteData)
                ->filter(fn($item) => is_array($item) && isset($item[$keyBy]))
                ->keyBy($keyBy)
                ->toArray();
        }

        foreach ($getData as &$row) {
            $tpId = $row[$field] ?? null;

            $row[$returnKey] = ($tpId && isset($remoteMap[$tpId]))
                ? $remoteMap[$tpId]
                : null;
        }
        unset($row);

        $pageArray['data'] = $getData;
        return $pageArray;
    }
}
