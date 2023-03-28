<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HelloController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $text = 'Hello Cruel World';
        cache()->forever('opentelemetry', 'opentelemetry');
        Log::info('Log info');
        cache()->get('opentelemetry.io', 'php');
        cache()->get('opentelemetry', 'php');
        cache()->forget('opentelemetry');
        $users = DB::connection('sqlite_testing')->select('select 1');

        return view('hello_index', ['text' => $text]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
