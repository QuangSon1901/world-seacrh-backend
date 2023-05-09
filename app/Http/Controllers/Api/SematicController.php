<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Define;
use App\Models\Keyphrase;
use App\Models\OntoKeyphraseRelationship;
use App\Models\Relationship;
use App\Models\Semantic;
use App\Models\SemanticKeyphraseRelationship;
use Illuminate\Http\Request;
use SplPriorityQueue;

class SematicController extends Controller
{
    public function index(Request $request)
    {
        $q = $request->input('q');


        $handle_query = $this->query($q);
        if ($handle_query['type'] === 'keyphrase') {
            $define = Define::where('name', 'like', '%' . $handle_query['keyphrase'] . '%')->first();
            return response()->json(["ok" => true, "result" => $define], 200);
        }

        $semantics = Semantic::with('Graph')->get();
        $graphs = [];

        foreach ($semantics as $semantic) {
            $keyphrase = [];

            foreach ($semantic->graph as $graph) {
                array_push($keyphrase, mb_strtolower($graph->KeyphraseFirst->text));
                array_push($keyphrase, mb_strtolower($graph->KeyphraseSecond->text));
            }

            $keyphrase = array_unique($keyphrase);
            $k_result = [];
            foreach ($keyphrase as $value) {
                $edge = [];
                foreach ($semantic->graph as $graph) {
                    if ($value === mb_strtolower($graph->KeyphraseFirst->text)) {
                        $edge[mb_strtolower($graph->KeyphraseSecond->text)] = $graph->weight;
                    } else if ($value === mb_strtolower($graph->KeyphraseSecond->text)) {
                        $edge[mb_strtolower($graph->KeyphraseFirst->text)] = $graph->weight;
                    }
                }
                $k_result[$value] = $edge;
            }

            array_push($graphs, [
                "semantic_id" => $semantic->id,
                "graph" => $k_result
            ]);
        }

        $keyphrase_query = [];
        foreach ($handle_query['graph'] as $key => $value) {
            $keyphrase_query[] = key((array)$value);
        }

        $pairing = [];
        for ($i = 0; $i < count($keyphrase_query) - 1; $i++) {
            for ($j = $i + 1; $j < count($keyphrase_query); $j++) {
                $pairing[] = [$keyphrase_query[$i], $keyphrase_query[$j]];
            }
        }

        $result = [];
        foreach ($graphs as $graph) {
            $check = $this->checkExistKeyphraseinGraph($graph, $keyphrase_query);
            if (!$check) {
                continue;
            }
            $weight = 0;
            foreach ($pairing as $keyphrases) {
                $shortestPathTree = $this->shortestPathTree($graph["graph"], $keyphrases[0], $keyphrases[1]);
                $weight += $shortestPathTree["weight"];
            }
            array_push($result, [
                "semantic_id" => $graph["semantic_id"],
                "weight" => $weight / count($pairing)
            ]);
        }

        $max_weight = 0;
        $max_weight_element = null;

        foreach ($result as $element) {
            if ($element['weight'] > $max_weight) {
                $max_weight = $element['weight'];
                $max_weight_element = $element;
            }
        }

        $getDefine = Define::query()->whereHas("Semantic", function ($query) use ($max_weight_element) {
            return $query->where("id", $max_weight_element["semantic_id"]);
        })->first();

        return response()->json(["ok" => true, "result" => $getDefine], 200);
    }

    function shortestPathTree($graph, $start, $end)
    {
        $queue = [];
        $visited = [];
        $distances = [];
        $previous = [];

        // Khởi tạo khoảng cách ban đầu là vô cùng cho tất cả các đỉnh
        foreach ($graph as $vertex => $edges) {
            $distances[$vertex] = INF;
        }

        // Bắt đầu từ đỉnh bắt đầu và đưa nó vào queue
        $queue[] = [$start, 0];
        $distances[$start] = 0;

        // Duyệt qua đồ thị
        while (!empty($queue)) {
            // Lấy ra đỉnh đầu tiên trong queue
            list($current, $distance) = array_shift($queue);

            // Đánh dấu đỉnh hiện tại đã được thăm
            $visited[$current] = true;

            // Nếu đến được đỉnh kết thúc, trả về khoảng cách và đường đi
            if ($current === $end) {
                $path = [$current];
                while ($previous[$current] !== $start) {
                    array_unshift($path, $previous[$current]);
                    $current = $previous[$current];
                }
                array_unshift($path, $start);
                return ["distance" => $distance, "weight" => $distance / (count($path) - 1), "path" => $path];
            }

            // Duyệt qua tất cả các đỉnh kề với đỉnh hiện tại
            foreach ($graph[$current] as $neighbor => $weight) {
                if (!isset($visited[$neighbor])) {
                    // Đưa đỉnh kề vào queue
                    $queue[] = [$neighbor, $distance + $weight];

                    // Cập nhật trọng số của cạnh để đến được đỉnh kề
                    if ($distance + $weight < $distances[$neighbor]) {
                        $distances[$neighbor] = $distance + $weight;
                        $previous[$neighbor] = $current;
                    }
                }
            }
        }

        // Nếu không tìm thấy đường đi từ đỉnh bắt đầu đến đỉnh kết thúc
        return null;
    }

    function checkExistKeyphraseinGraph($graph, $keyphrase_query)
    {
        $checkK = [];
        foreach ($graph["graph"] as $key => $value) {
            array_push($checkK, $key);
        }
        $checkIn = true;
        foreach ($keyphrase_query as $value) {
            if (!in_array($value, $checkK)) {
                $checkIn = false;
            }
        }

        return $checkIn;
    }

    function query($input)
    {
        // ==== Lấy và sắp xếp keyphrase từ csdl
        $keyphrasesDB = Keyphrase::all();
        $keyphrases = [];
        foreach ($keyphrasesDB as $value) {
            $arr = explode(" ", $value->text);
            array_push($keyphrases, [
                "text" => $value->text,
                "length" => count($arr),
            ]);
        }

        usort($keyphrases, function ($a, $b) {
            return $b['length'] - $a['length'];
        });

        $done_keyphrases = [];
        foreach ($keyphrases as $value) {
            array_push($done_keyphrases, $value['text']);
        }
        // ===

        // === Rút các keyphrase
        $keyphrase_query = $this->rut_trich_keyphrase_query($input, $done_keyphrases);
        // ===

        if (count($keyphrase_query) <= 1) {
            return [
                "type" => "keyphrase",
                "keyphrase" => $keyphrase_query[0]
            ];
        }

        // === Rút các relation
        $relations = [
            "của",
            "liên quan",
            "trong"
        ];
        $relation_query = $this->rut_trich_relation_query($input, $relations);
        // ===

        // === Xây dựng đồ thị cho cấu truy vấn
        $graph_query = $this->bien_truy_van_sang_do_thi($input, $relation_query, $keyphrase_query);
        return [
            "type" => "graph",
            "graph" => $graph_query
        ];
        // ===
    }

    function rut_trich_keyphrase_query($input, $keyphrases)
    {
        $keyphrase_query = [];
        $input = mb_strtolower($input);

        foreach ($keyphrases as $value) {
            $value = mb_strtolower($value);
            if (strpos($input, $value) !== false) {
                array_push($keyphrase_query, $value);
                $value = "/$value/";
                $input = preg_replace($value, "", $input, 1);
            }
        }

        return $keyphrase_query;
    }

    function rut_trich_relation_query($input, $relations)
    {
        $before_input = $input;
        $relation_query = [];
        $input = mb_strtolower($input);

        foreach ($relations as $value) {
            $value = mb_strtolower($value);
            if (strpos($input, $value) !== false) {
                array_push($relation_query, [
                    "text" => $value,
                    "position" => strpos($before_input, $value)
                ]);
                $value = "/$value/";
                $input = preg_replace($value, "", $input, 1);
            }
        }

        usort($relation_query, function ($a, $b) {
            return $b['position'] + $a['position'];
        });

        $done_relation = [];
        foreach ($relation_query as $value) {
            array_push($done_relation, $value['text']);
        }

        return $done_relation;
    }

    function bien_truy_van_sang_do_thi($str, $relations, $keyphrases)
    {
        $graph_query = [];
        for ($i = 0; $i < count($relations); $i++) {
            if ($i == 0) {
                if (isset($relations[$i + 1])) {
                    $substring = trim(strstr($str, $relations[$i + 1], true));
                    $before = $this->rut_trich_keyphrase_query(trim(strstr($substring, $relations[$i], true)), $keyphrases);
                    $after = $this->rut_trich_keyphrase_query(trim(strstr($substring, $relations[$i], false)), $keyphrases);
                    foreach ($before as $valueB) {
                        $arr_after = [];
                        foreach ($after as $valueA) {
                            $arr_after[] = [$valueA, $relations[$i]];
                        }
                        array_push($graph_query, [
                            $valueB => $arr_after
                        ]);
                    }
                } else {
                    $before = $this->rut_trich_keyphrase_query(trim(strstr($str, $relations[$i], true)), $keyphrases);
                    $after = $this->rut_trich_keyphrase_query(trim(strstr($str, $relations[$i], false)), $keyphrases);
                    foreach ($before as $valueB) {
                        $arr_after = [];
                        foreach ($after as $valueA) {
                            $arr_after[] = [$valueA, $relations[$i]];
                        }
                        array_push($graph_query, [
                            $valueB => $arr_after
                        ]);
                    }
                }
            } else {
                $minustring = trim(strstr($str, $relations[$i - 1], false));
                $before = $this->rut_trich_keyphrase_query(trim(strstr($minustring, $relations[$i], true)), $keyphrases);
                $after = $this->rut_trich_keyphrase_query(trim(strstr($minustring, $relations[$i], false)), $keyphrases);

                if (isset($relations[$i - 2])) {
                    $substring = trim(strstr($str, $relations[$i - 1], true));
                    return $substring;
                } else {
                    $substring = trim(strstr($str, $relations[$i - 1], true));
                    $before2 = $this->rut_trich_keyphrase_query(trim($substring), $keyphrases);
                }

                foreach ($before as $valueB) {
                    $arr_after = [];
                    if (isset($before2)) {
                        foreach ($before2 as $valueA) {
                            $arr_after[] = [$valueA, $relations[$i - 1]];
                        }
                    }
                    foreach ($after as $valueA) {
                        $arr_after[] = [$valueA, $relations[$i]];
                    }
                    array_push($graph_query, [
                        $valueB => $arr_after
                    ]);
                }
            }

            if ($i == count($relations) - 1) {
                $minustring = $str;
                if (isset($relations[$i - 1])) {
                    $minustring = trim(strstr($str, $relations[$i - 1], false));
                }

                $before = $this->rut_trich_keyphrase_query(trim(strstr($minustring, $relations[$i], true)), $keyphrases);
                $after = $this->rut_trich_keyphrase_query(trim(strstr($minustring, $relations[$i], false)), $keyphrases);
                foreach ($after as $valueA) {
                    $arr_after = [];
                    foreach ($before as $valueB) {
                        $arr_after[] = [$valueB, $relations[$i]];
                    }
                    array_push($graph_query, [
                        $valueA => $arr_after
                    ]);
                }
            }
        }
        return $graph_query;
    }

    public function check_query(Request $request) {
        $q = $request->input('q');


        $handle_query = $this->query($q);
        return response()->json(["ok" => true, "result" => $handle_query], 200);

    }
}
