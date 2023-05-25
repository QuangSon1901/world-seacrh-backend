<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Component;
use App\Models\Concept;
use App\Models\Define;
use App\Models\Keyphrase;
use App\Models\Node;
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
            $define = Component::where('name', 'like', '%' . $handle_query['keyphrase'] . '%')->get();
            return response()->json(["ok" => true, "result" => $define], 200);
        }

        $semantics = Component::with('Graph')->get();

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
                "id_component" => $semantic->id,
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
                "id_component" => $graph["id_component"],
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

        // $getDefine = Define::query()->whereHas("Semantic", function ($query) use ($max_weight_element) {
        //     return $query->where("id", $max_weight_element["id_component"]);
        // })->get();
        $getDefine = Component::where("id", $max_weight_element["id_component"])->get();

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
            "biểu diễn",
            "liên quan",
            "của",
            "bằng",
            "trong",
            "về",
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
            return $a['position'] - $b['position'];
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

    public function check_query(Request $request)
    {
        $q = $request->input('q');


        $handle_query = $this->query($q);
        return response()->json(["ok" => true, "result" => $handle_query], 200);
    }

    public function t_node()
    {
        $nodes = Node::query()->has('NodeFather')->orderBy('z_index', 'ASC')->get();
        $result = [];
        foreach ($nodes as $value) {
            $childrens = Node::query()->select('id', 'label', 'content')->whereHas("NodeChildren", function ($query) use ($value) {
                return $query->where('id_node_father', $value->id);
            })->orderBy('z_index', 'ASC')->get();
            array_push($result, [
                "id" => $value->id,
                "label" => $value->label,
                "childrens" => $childrens
            ]);
        }
        return response()->json(["ok" => true, "result" => $result], 200);
    }

    public function search_keyword(Request $request)
    {
        $q = $request->input('q');
        $keywords = explode(",", $q);

        $known = [];
        $related_keyword = [];
        $r_known = [];

        $concepts = Concept::with("Components")->get();

        foreach ($concepts as $concept) {
            $num_c = 0;
            foreach ($keywords as $keyword) {
                if (strpos(trim(mb_strtolower($concept->name)), trim(mb_strtolower($keyword)))  !== false) {
                    $num_c++;
                }
            }

            $result = [];

            if (count($concept->components) > 0) {
                $def = [];
                foreach ($concept->components as $component) {
                    $num_cp = 0;
                    foreach ($keywords as $keyword) {
                        if (strpos(trim(mb_strtolower($component->name)), trim(mb_strtolower($keyword)))  !== false) {
                            $num_cp++;
                        }
                    }
                    if ($num_cp > 0) {
                        $def[] = [
                            "component" => $component,
                            "num" => $num_cp
                        ];
                    } else {
                        $def[] = [
                            "component" => $component,
                            "num" => 0
                        ];
                    }
                }
                if (count($def) > 0) {
                    array_multisort(array_column($def, 'num'), SORT_DESC, $def);
                }
            }

            if ($num_c > 0) {
                $known[] = [
                    "concept" => [
                        "id" => $concept->id,
                        "symbol" => $concept->symbol,
                        "name" => $concept->name,
                        "components" => isset($def) && count($def) > 0 ? $def : $concept->components
                    ],
                    "num" => $num_c
                ];
            }
        }

        array_multisort(array_column($known, 'num'), SORT_DESC, $known);
        $result = [];
        foreach ($known[0]['concept']['components'] as $value) {
            $result[] = [
                "id" => $value['component']['id'],
                "name" => $value['component']['name'],
                "content" => $value['component']['content'],
            ];
        }

        return response()->json(["ok" => true, "result" => $result], 200);
    }

    public function search_syntax(Request $request)
    {
        $q = $request->input('q');
        $explode_q = explode("|", $q);

        $ks = explode(",", $explode_q[0]);
        $conditions = explode(",", $explode_q[1]);
        $es = explode(",", $explode_q[2]);
        $result = [];

        $concepts = Concept::with("Components")->get();
        foreach ($concepts as $concept) {
            $num_c = 0;
            foreach ($es as $e) {
                if (strpos(trim(mb_strtolower($concept->name)), trim(mb_strtolower($e)))  !== false) {
                    $num_c++;
                }
            }

            if ($num_c > 0) {
                $list_par = [];
                if (count($concept->components) > 0) {
                    foreach ($concept->components as $component) {
                        foreach ($ks as $k) {
                            if (strpos(trim(mb_strtolower($component->name)), trim(mb_strtolower($k)))  !== false) {
                                $list_par[] = $component;
                            }
                        }
                    }
                }

                if (count($list_par) > 0) {
                    $result[] = [
                        "concept" => $list_par
                    ];
                }
            }
        }

        $res = [];
        foreach ($result[0]['concept'] as $value) {
            $res[] = [
                "id" => $value['id'],
                "name" => $value['name'],
                "content" => $value['content'],
            ];
        }
        return response()->json(["ok" => true, "result" => $res], 200);
    }
}
