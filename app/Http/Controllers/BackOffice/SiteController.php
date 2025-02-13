<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SiteController extends Controller
{
    /**
     * Display a listing of sites.
     */
    public function index()
    {
        return response()->json(Site::get(), Response::HTTP_OK);
    }

    /**
     * Store a newly created site.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|unique:sites,name|max:255',
            'url' => 'required|url|unique:sites,url|max:255',
        ]);

        $site = Site::create($request->only('name', 'url'));

        return response()->json($site, Response::HTTP_CREATED);
    }

    /**
     * Display the specified site.
     */
    public function show(Site $site)
    {
        return response()->json($site, Response::HTTP_OK);
    }

    /**
     * Update the specified site.
     */
    public function update(Request $request, Site $site)
    {
        $request->validate([
            'name' => 'required|string|unique:sites,name,' . $site->id . '|max:255',
            'url' => 'required|url|unique:sites,url,' . $site->id . '|max:255',
        ]);

        $site->update($request->only('name', 'url'));

        return response()->json($site, Response::HTTP_OK);
    }

    /**
     * Remove the specified site.
     */
    public function destroy(Site $site)
    {
        $site->delete();

        return response()->json(['message' => 'Site deleted successfully'], Response::HTTP_NO_CONTENT);
    }
}
