<?php

namespace App\Http\Controllers;

use App\Http\Resources\TagResource;
use App\Models\Tag;
use Illuminate\Http\Resources\Json\JsonResource;

class TagController extends Controller
{
    public function __invoke(): JsonResource
    {
        return TagResource::collection(
            Tag::all()
        );
    }
}
