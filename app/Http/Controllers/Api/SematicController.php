<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Component;
use App\Models\Concept;
use App\Models\HistorySearch;
use App\Models\Keyphrase;
use App\Models\Method;
use App\Models\Node;
use App\Models\RelationNode;
use App\Models\TypeComponent;
use App\Models\Weight;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

class SematicController extends Controller
{
    public function index(Request $request)
    {
        $q = $request->input('q');

        $user = auth('sanctum')->user();
        if ($user) {
            $check_his = HistorySearch::where('content', $q)->where('type', 'KEYPHRASE')->where('id_user', $user->id)->first();
            if ($check_his) {
                HistorySearch::where('id', $check_his->id)->update([
                    'created_at' => now()
                ]);
            } else {
                HistorySearch::create([
                    'content' => $q,
                    'type' => 'KEYPHRASE',
                    'id_user' => $user->id
                ]);
            }
        }

        $handle_query = $this->query($q);
        // if ($handle_query['type'] === 'keyphrase') {
        //     $define = Component::where('name', 'like', '%' . $handle_query['keyphrase'] . '%')->get();
        //     return response()->json(["ok" => true, "result" => $define], 200);
        // }

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
        $pairing = [];
        if ($handle_query['type'] === 'keyphrase') {
            $keyphrase_query[] = $handle_query['keyphrase'];
        } else {
            // foreach ($handle_query['graph'] as $key => $value) {
            //     $keyphrase_query[] = key((array)$value);
            // }
            $keyphrase_query = $handle_query['graph'];
            for ($i = 0; $i < count($keyphrase_query) - 1; $i++) {
                for ($j = $i + 1; $j < count($keyphrase_query); $j++) {
                    $pairing[] = [$keyphrase_query[$i], $keyphrase_query[$j]];
                }
            }
        }

        $result = [];

        foreach ($graphs as $graph) {
            $weight = 1;
            $check = $this->checkExistKeyphraseinGraph($graph, $keyphrase_query);
            $graph["is_k"] = $check;
            if ($handle_query['type'] === 'graph') {
                $weight = 0;
                foreach ($pairing as $keyphrases) {
                    $shortestPathTree = $this->shortestPathTree($graph["graph"], $keyphrases[0], $keyphrases[1]);
                    if (!$shortestPathTree) {
                        continue;
                    }
                    $weight += $shortestPathTree["weight"];
                }
            }

            $get_weight_db = Weight::with("Keyphrase")->where("id_component", $graph["id_component"])->get();
            $sum = 0;
            $count = 0;
            foreach ($get_weight_db as $value) {
                if (in_array(mb_strtolower($value->keyphrase->text), $keyphrase_query)) {
                    $sum += $value->tf * $value->ip;
                    $count++;
                }
            }

            if ($count <= 0) {
                continue;
            }

            $total = $sum / $count;
            $count_pairing = count($pairing) <= 0 ? 1 : count($pairing);

            array_push($result, [
                "id_component" => $graph["id_component"],
                "weight" => (($weight / $count_pairing) + $total) / 2,
                "is_k" => $graph["is_k"]
            ]);
        }

        $sortedArray = collect($result)->sortByDesc('is_k')->sortByDesc('weight')->values()->all();

        if ($request->suggest === 'true') {
            $suggests = [];
            $suggest_count = 0;
            foreach ($sortedArray as $value) {

                if ($suggest_count >= 2) {
                    break;
                }

                $suggests_component = Component::where("id", $value["id_component"])->first();
                array_push($suggests, [
                    "type" => $suggests_component->TypeComponent->name,
                    "concept" => [
                        "id" => $suggests_component->id,
                        "name" => $suggests_component->name,
                        "content" => $suggests_component->content,
                        "weight" => $value['weight']
                    ]
                ]);
                $suggest_count++;
            }
            return response()->json(["ok" => true, "result" => $suggests], 200);
        }

        $max_weight = array_shift($sortedArray);
        $relate_components = [];
        foreach ($sortedArray as $value) {
            $component = Component::where("id", $value["id_component"])->first();
            array_push($relate_components, [
                "type" => $component->TypeComponent->name,
                "concept" => [
                    "id" => $component->id,
                    "name" => $component->name,
                    "content" => $component->content,
                    "weight" => $value['weight']
                ]
            ]);
        }
        $groupedData = collect($relate_components)->groupBy('type')->map(function ($items, $type) {
            return [
                'type' => $type,
                'array' => $items->all(),
            ];
        })->values()->all();

        $main = Component::where("id", $max_weight["id_component"])->first();
        if ($main->TypeComponent->name === "Định nghĩa") {
            $main_more = Component::query()->whereHas("TypeComponent", function ($query) {
                return $query->where("name", "Ví dụ");
            })->first();
        }

        if (isset($main_more)) {
            $main_all = [
                [
                    "id" => $main->id,
                    "name" => $main->name,
                    "content" => $main->content,
                    "type" => $main->TypeComponent->name,
                    "weight" => $max_weight["weight"]
                ],
                [
                    "id" => $main_more->id,
                    "name" => $main_more->name,
                    "content" => $main_more->content,
                    "type" => $main_more->TypeComponent->name,
                ],
            ];
        } else {
            $main_all = [
                [
                    "id" => $main->id,
                    "name" => $main->name,
                    "content" => $main->content,
                    "type" => $main->TypeComponent->name,
                ],
            ];
        }
        return response()->json(["ok" => true, "result" => [
            "main" => $main_all,
            "relate" => $groupedData
        ]], 200);
    }

    function shortestPathTree($graph, $start, $end)
    {
        try {
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
        } catch (\Throwable $th) {
            // Nếu không tìm thấy đường đi từ đỉnh bắt đầu đến đỉnh kết thúc
            return null;
        }
    }

    function checkExistKeyphraseinGraph($graph, $keyphrase_query)
    {
        $checkK = [];
        foreach ($graph["graph"] as $key => $value) {
            array_push($checkK, $key);
        }
        $checkIn = 0;
        foreach ($keyphrase_query as $value) {
            if (in_array($value, $checkK)) {
                $checkIn++;
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
        } else {
            return [
                "type" => "graph",
                "graph" => $keyphrase_query
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
            "có"
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
        $filterType = explode('|', $request->type);

        $user = auth('sanctum')->user();
        if ($user) {
            $check_his = HistorySearch::where('content', $q)->where('type', 'KEYWORD')->where('id_user', $user->id)->first();
            if ($check_his) {
                HistorySearch::where('id', $check_his->id)->update([
                    'created_at' => now()
                ]);
            } else {
                HistorySearch::create([
                    'content' => $q,
                    'type' => 'KEYWORD',
                    'id_user' => $user->id
                ]);
            }
        }
        
        $known = [];
        $related_keyword = [];
        $r_known = [];

        foreach ($filterType as $type) {
            $concepts_know = [];
            if ($type === 'concept') {
                $concepts = Concept::has("Components")->with("Components")->get();
                $concepts_know = $this->xu_ly_search_kw($concepts, $q);
            }

            $methods_know = [];
            if ($type === 'method') {
                $methods = Method::has("Components")->with("Components")->get();
                $methods_know = $this->xu_ly_search_kw($methods, $q);
            }

            $known = [...$concepts_know, ...$methods_know];
        }

        if (count($known) > 0) {
            array_multisort(array_column($known, 'num'), SORT_DESC, $known);

            $max_weight = array_shift($known);
            $relate_components = [];
            foreach ($known as $value) {
                foreach ($value['concept']['components'] as  $component) {
                    $type_com = TypeComponent::where('id', $component['component']['id_type_component'])->first();
                    array_push($relate_components, [
                        "type" => $type_com->name,
                        "concept" => [
                            "id" => $component['component']['id'],
                            "name" => $component['component']['name'],
                            "content" => $component['component']['content'],
                        ]
                    ]);
                }
            }
            $groupedData = collect($relate_components)->groupBy('type')->map(function ($items, $type) {
                return [
                    'type' => $type,
                    'array' => $items->all(),
                ];
            })->values()->all();


            $main_all = [];
            foreach ($max_weight['concept']['components'] as $value) {
                $type_com = TypeComponent::where('id', $value['component']['id_type_component'])->first();
                $main_all[] = [
                    "id" => $value['component']['id'],
                    "name" => $value['component']['name'],
                    "content" => $value['component']['content'],
                    "type" => $type_com->name,
                ];
            }

            return response()->json(["ok" => true, "result" => [
                "main" => $main_all,
                "relate" => $groupedData
            ]], 200);
        }
        return response()->json(["ok" => false], 400);
    }

    function xu_ly_search_kw($concepts, $q)
    {
        $known = [];
        foreach ($concepts as $concept) {
            $num_c = 0;
            if (strpos(trim(mb_strtolower($concept->name)), trim(mb_strtolower($q)))  !== false) {
                $num_c++;
            }

            if (count($concept->components) > 0) {
                $def = [];
                foreach ($concept->components as $component) {
                    $num_cp = 0;
                    if (strpos(trim(mb_strtolower($component->name)), trim(mb_strtolower($q)))  !== false) {
                        $num_cp++;
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

        return $known;
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

    public function test()
    {
        $semantics = Component::with('Graph')->doesntHave('Weight')->get();

        $graphs = [];

        foreach ($semantics as $semantic) {
            $keyphrase = [];

            foreach ($semantic->graph as $graph) {
                array_push($keyphrase, $graph->KeyphraseFirst->text);
                array_push($keyphrase, $graph->KeyphraseSecond->text);
            }

            $keyphrase = array_values(array_unique($keyphrase));
            if (count($keyphrase) <= 0) {
                continue;
            }

            array_push($graphs, [
                "id_component" => $semantic->id,
                "concept_name" => $semantic->Concept->name ?? $semantic->RelationCC->name ?? $semantic->Rule->name ?? $semantic->Method->name ?? $semantic->Function->name ?? $semantic->Operator->name,
                "component_name" => $semantic->name,
                "content" => $semantic->content,
                "graph" => $keyphrase
            ]);
        }

        // ==== Lấy và sắp xếp keyphrase từ csdl
        $keyphrasesDB = Keyphrase::all();
        $keyphrases = [];
        foreach ($keyphrasesDB as $value) {
            $arr = explode(" ", $value->text);
            array_push($keyphrases, [
                "id" => $value->id,
                "text" => $value->text,
                "length" => count($arr),
            ]);
        }

        usort($keyphrases, function ($a, $b) {
            return $b['length'] - $a['length'];
        });

        $result = [];

        foreach ($graphs as $graph) {
            $concept_name = $this->rut_trich_keyphrase_query_new($graph['concept_name'], $keyphrases, 0.9);
            $component_name = $this->rut_trich_keyphrase_query_new($graph['component_name'], $keyphrases, 0.8);
            $content = $this->rut_trich_keyphrase_query_new($graph['content'], $keyphrases, 0.7);
            $mergedArray = array_merge($concept_name, $component_name, $content);

            // Tính số lượng keyphrase trùng lặp
            $keyphraseCounts = [];
            $keyphraseWeights = [];
            $keyphraseAllWeights = [];

            foreach ($mergedArray as $keyphrase) {
                $phrase = $keyphrase['keyphrase'];
                $weight = $keyphrase['weight'];

                if (array_key_exists($phrase, $keyphraseCounts)) {
                    $keyphraseCounts[$phrase][0]++;

                    if ($weight > $keyphraseWeights[$phrase]) {
                        $keyphraseWeights[$phrase] = $weight;
                    }
                } else {
                    $keyphraseCounts[$phrase][0] = 1;
                    $keyphraseWeights[$phrase] = $weight;
                }
                if (array_key_exists($phrase, $keyphraseAllWeights)) {
                    array_push($keyphraseAllWeights[$phrase], $weight);
                } else {
                    $keyphraseAllWeights[$phrase] = [$weight];
                }
                $keyphraseCounts[$phrase][1] = $keyphrase['id'];
            }
            $keyphraseAllWeights = array_map('array_unique', $keyphraseAllWeights);

            $cal_keyphrases = [];
            foreach ($keyphraseCounts as $phrase => $count) {
                $maxWeight = $keyphraseWeights[$phrase];
                $cal_keyphrases[] = [
                    "id" => $count[1],
                    "keyphrase" => $phrase,
                    "num" => $count[0],
                    "weight" => $maxWeight,
                    "all_weight" => $keyphraseAllWeights[$phrase]
                ];
            }

            $collection = collect($cal_keyphrases);
            $sorted = $collection->sortByDesc('num');
            $largestNumElement = $sorted->first();

            $end_cal = [];

            foreach ($cal_keyphrases as $value) {
                $tf = 0.5 + (1 - 0.5) * ($value['num'] / $largestNumElement['num']);

                $ip = $value['weight'] + (1 - $value['weight']) * (array_sum($value['all_weight']) / (0.9 + 0.8 + 0.7));

                $end_cal[] = [
                    "id" => $value['id'],
                    "keyphrase" => $value['keyphrase'],
                    "num" => $value['num'],
                    "weight" => $value['weight'],
                    "all_weight" => $value['all_weight'],
                    "tf" => $tf,
                    "ip" => $ip,
                ];
            }

            // ==== Result
            $result[] = [
                "graph" => $graph,
                "cal_keyphrases" => $end_cal,
            ];
        }

        // Insert data
        foreach ($result as $value) {
            foreach ($value['cal_keyphrases'] as $key) {
                Weight::create([
                    "id_keyphrase" => $key['id'],
                    "id_component" => $value['graph']['id_component'],
                    "tf" => $key['tf'],
                    "ip" => $key['ip'],
                ]);
            }
        }

        return response()->json(["ok" => true, "result" => $result], 200);
    }

    function rut_trich_keyphrase_query_new($input, $keyphrases, $weight)
    {
        $keyphrase_query = [];
        $input = mb_strtolower($input);

        foreach ($keyphrases as $value) {
            for ($i = 0; $i < 10; $i++) {
                $value_text = mb_strtolower($value['text']);
                if (strpos($input, $value_text) !== false) {
                    array_push($keyphrase_query, [
                        "id" => $value['id'],
                        "keyphrase" => $value_text,
                        "weight" => $weight
                    ]);
                    $value_edit = "/$value_text/";
                    $input = preg_replace($value_edit, "", $input, 1);
                }
            }
        }

        return $keyphrase_query;
    }

    function get_parent_node()
    {
        $nodes = Node::query()->has('NodeFather')->orderBy('z_index', 'ASC')->get();
        return response()->json(["ok" => true, "result" => $nodes], 200);
    }

    function add_node(Request $request)
    {
        $created = Node::create([
            "label" => $request->label,
            "content" => $request->content,
            "status" => 1,
            "z_index" => $request->z_index,
        ]);

        if ($created && $request->node_parent) {
            RelationNode::create([
                "id_node_father" => $request->node_parent,
                "id_node_children" => $created->id
            ]);
        }
        return response()->json(["ok" => true, "message" => "Thành công!"], 200);
    }
}
