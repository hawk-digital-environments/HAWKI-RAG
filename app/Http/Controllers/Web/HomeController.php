<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Embedding;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * Display the main search page
     */
    public function index()
    {
        $count = Embedding::count();
        // Using the withoutBinary scope to exclude the large embedding vector field for better performance
        $embeddings = Embedding::withoutBinary()->take(500)->get();

        return view('search', compact('count', 'embeddings'));
    }

    /**
     * Load more embeddings for pagination (AJAX endpoint for the view)
     */
    public function loadMore(Request $request)
    {
        $page = $request->query('page', 1);
        $embeddings = Embedding::withoutBinary()
            ->skip(($page - 1) * 500)
            ->take(500)
            ->get();

        return response()->json(['data' => $embeddings]);
    }

    public function qdrant()
    {
        $count = \App\Models\Embedding::count();
        $embeddings = \App\Models\Embedding::withoutBinary()->take(500)->get();

        return view('qdrant.index', compact('count', 'embeddings'));
    }
}
