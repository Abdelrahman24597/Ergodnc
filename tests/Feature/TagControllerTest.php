<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TagControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @test
     */
    public function itListsTags()
    {
        $response = $this->getJson(route('tags.index'))
            ->assertOk()
            ->assertJsonStructure(['data' => ['*' => ['id']]]);
    }
}
