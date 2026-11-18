<?php

namespace App\Http\Controllers;

use App\Http\Requests\NationalizeRequest;
use App\Http\Resources\NationalityResource;
use App\Services\NationalityLookup;
use Illuminate\Http\JsonResponse;

class NationalizeController extends Controller
{
    public function __invoke(NationalizeRequest $request, NationalityLookup $lookup): JsonResponse
    {
        $records = $lookup->lookup($request->normalizedNames());

        $items = [];
        foreach ($request->displayNames() as $i => $name) {
            $items[] = ['name' => $name, 'record' => $records[$i]];
        }

        return response()->json([
            'results' => NationalityResource::collection($items)->resolve($request),
        ]);
    }
}
