<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OfficeImageControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @test
     */
    public function itUploadsAnImageAndStoresItUnderTheOffice()
    {
        Storage::fake();

        $host = User::factory()->create();
        $office = Office::factory()->for($host)->create();

        $this->actingAs($host);

        $image = UploadedFile::fake()->image('image.jpg');

        $this->postJson(route('offices.images.store', $office->id), [
            'image' => $image,
        ])
            ->assertCreated();

        Storage::assertExists($image->hashName());
    }

    /**
     * @test
     */
    public function itDeletesAnImage()
    {
        Storage::fake();

        $host = User::factory()->create();
        $office = Office::factory()->for($host)->create();

        $office->images()->create([
            'path' => 'image.jpg'
        ]);

        $imageFile = UploadedFile::fake()->image('office_image.jpg');

        $image = $office->images()->create([
            'path' => $imageFile->hashName()
        ]);

        $this->actingAs($host);

        $this->deleteJson(route('offices.images.delete', [
            'office' => $office->id,
            'image' => $image->id,
        ]))
            ->assertOk();

        $this->assertModelMissing($image);

        Storage::assertMissing($imageFile->hashName());
    }

    /**
     * @test
     */
    public function itDoesntDeleteImageThatBelongsToAnotherResource()
    {
        $host = User::factory()->create();
        $office = Office::factory()->for($host)->create();
        $anotherOffice = Office::factory()->for($host)->create();

        $image = $anotherOffice->images()->create([
            'path' => 'office_image.jpg'
        ]);

        $this->actingAs($host);

        $this->deleteJson(route('offices.images.delete', [
            'office' => $office->id,
            'image' => $image->id,
        ]))
            ->assertNotFound();
    }

    /**
     * @test
     */
    public function itDoesntDeleteTheOnlyImage()
    {
        $user = User::factory()->create();
        $office = Office::factory()->for($user)->create();

        $image = $office->images()->create([
            'path' => 'office_image.jpg'
        ]);

        $this->actingAs($user);

        $this->deleteJson(route('offices.images.delete', [
            'office' => $office->id,
            'image' => $image->id,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image' => 'Cannot delete the only image.']);
    }

    /**
     * @test
     */
    public function itDoesntDeleteTheFeaturedImage()
    {
        $host = User::factory()->create();
        $office = Office::factory()->for($host)->create();

        $office->images()->create([
            'path' => 'image.jpg'
        ]);

        $image = $office->images()->create([
            'path' => 'office_image.jpg'
        ]);

        $office->update(['featured_image_id' => $image->id]);

        $this->actingAs($host);

        $this->deleteJson(route('offices.images.delete', [
            'office' => $office->id,
            'image' => $image->id,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image' => 'Cannot delete the featured image.']);
    }
}
