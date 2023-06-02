<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HistorySearch;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function getHistorySearch(Request $request) {

        $historySearch = HistorySearch::query();
        $historySearch->where('type', $request->type)->where('id_user', auth()->user()->id);
        if ($request->limit === 'true') {
            $historySearch->limit(3);
        }
        $historySearch->orderByDesc('created_at');
        $history = $historySearch->get();

        $historyCount = HistorySearch::count();

        return response()->json(['ok' => true, 'result' => [
            'history' => $history,
            'historyCount' => $historyCount,
        ]]);
    }

    public function deleteAllHistory(Request $request) {

        $historySearch = HistorySearch::where('id_user', auth()->user()->id)->delete();

        return response()->json(['ok' => true]);
    }
}
