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
        $keyphrasesDB = Keyphrase::all();
        $keyphrases = [];
        foreach ($keyphrasesDB as $value) {
            array_push($keyphrases, $value->text);
        }

        $keyphrase_query = $this->query($q, $keyphrases);

        $semantics = Semantic::with('Graph')->get();
        $graphs = [];

        foreach ($semantics as $semantic) {
            $keyphrase = [];

            foreach ($semantic->graph as $graph) {
                array_push($keyphrase, $graph->KeyphraseFirst->text);
                array_push($keyphrase, $graph->KeyphraseSecond->text);
            }

            $keyphrase = array_unique($keyphrase);
            $k_result = [];
            foreach ($keyphrase as $value) {
                $edge = [];
                foreach ($semantic->graph as $graph) {
                    if ($value === $graph->KeyphraseFirst->text) {
                        $edge[$graph->KeyphraseSecond->text] = $graph->weight;
                    } else if ($value === $graph->KeyphraseSecond->text) {
                        $edge[$graph->KeyphraseFirst->text] = $graph->weight;
                    }
                }
                $k_result[$value] = $edge;
            }

            array_push($graphs, [
                "semantic_id" => $semantic->id,
                "graph" => $k_result
            ]);
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

    function query($input, $keyphrases) {
        $keyphrase_query = [];
        
        
        foreach ($keyphrases as $value) {
            if (strpos(mb_strtolower($input), mb_strtolower($value)) !== false) {
                array_push($keyphrase_query, $value);
            }
        }

        return $keyphrase_query;
    }
}
