<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\Reservation;
use App\Models\Tag;
use App\Models\User;
use App\Notifications\OfficePendingApproval;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OfficeControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @test
     */
    public function itListsAllOfficesInPaginatedWay()
    {
        Office::factory(3)->create();

        $this->getJson(route('offices.index'))
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data', 'meta', 'links'])
            ->assertJsonStructure(['data' => ['*' => ['id', 'title']]]);
    }

    /**
     * @test
     */
    public function itOnlyListsOfficesThatAreNotHiddenAndApproved()
    {
        Office::factory(3)->create();

        Office::factory()->hidden()->create();
        Office::factory()->pending()->create();

        $this->getJson(route('offices.index'))
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    /**
     * @test
     */
    public function itListsOfficesIncludingHiddenAndUnApprovedIfFilteringForTheCurrentLoggedInUser()
    {
        $host = User::factory()->create();

        Office::factory(3)->for($host)->create();

        Office::factory()->hidden()->for($host)->create();
        Office::factory()->pending()->for($host)->create();

        $this->actingAs($host);

        $this->getJson(route('offices.index', [
            'host_id' => $host->id,
        ]))
            ->assertOk()
            ->assertJsonCount(5, 'data');
    }

    /**
     * @test
     */
    public function itFiltersByHostId()
    {
        Office::factory(3)->create();

        $host = User::factory()->create();
        $office = Office::factory()->for($host)->create();

        $this->getJson(route('offices.index', ['host_id' => $host->id]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $office->id);
    }

    /**
     * @test
     */
    public function itFiltersByVisitorId()
    {
        Office::factory(3)->create();
        Reservation::factory()->for(Office::factory())->create();

        $visitor = User::factory()->create();
        $office = Office::factory()->has(Reservation::factory()->for($visitor))->create();

        $this->getJson(route('offices.index', ['visitor_id' => $visitor->id]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $office->id);
    }

    /**
     * @test
     */
    public function itFiltersByTags()
    {
        $tags = Tag::factory(2)->create();
        $office = Office::factory()->hasAttached($tags)->create();
        Office::factory()->hasAttached($tags->first())->create();
        Office::factory()->create();

        $this->getJson(route('offices.index', ['tags' => $tags->pluck('id')->toArray()]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $office->id);
    }

    /**
     * @test
     */
    public function itIncludesImagesTagsAndUser()
    {
        $host = User::factory()->create();
        Office::factory()->for($host)->hasTags(1)->hasImages(1)->create();

        $this->getJson(route('offices.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data.0.tags')
            ->assertJsonCount(1, 'data.0.images')
            ->assertJsonPath('data.0.user.id', $host->id);
    }

    /**
     * @test
     */
    public function itReturnsTheNumberOfActiveReservations()
    {
        $office = Office::factory()->create();

        Reservation::factory()->for($office)->create();
        Reservation::factory()->for($office)->cancelled()->create();

        $this->getJson(route('offices.index'))
            ->assertOk()
            ->assertJsonPath('data.0.reservations_count', 1);
    }

    /**
     * @test
     */
    public function itOrdersByDistanceWhenCoordinatesAreProvided()
    {
        Office::factory()->create([
            'lat' => '39.74051727562952',
            'lng' => '-8.770375324893696',
            'title' => 'Leiria'
        ]);

        Office::factory()->create([
            'lat' => '39.07753883078113',
            'lng' => '-9.281266331143293',
            'title' => 'Torres Vedras'
        ]);

        $this->getJson(route('offices.index', [
            'lat' => '38.720661384644046',
            'lng' => '-9.16044783453807',
        ]))
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Torres Vedras')
            ->assertJsonPath('data.1.title', 'Leiria');

        $this->getJson(route('offices.index'))
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Leiria')
            ->assertJsonPath('data.1.title', 'Torres Vedras');
    }

    /**
     * @test
     */
    public function itShowsTheOffice()
    {
        $user = User::factory()->create();
        $office = Office::factory()->for($user)->hasTags(1)->hasImages(1)->create();

        Reservation::factory()->for($office)->create();
        Reservation::factory()->for($office)->cancelled()->create();

        $this->getJson(route('offices.show', $office->id))
            ->assertOk()
            ->assertJsonPath('data.reservations_count', 1)
            ->assertJsonCount(1, 'data.tags')
            ->assertJsonCount(1, 'data.images')
            ->assertJsonPath('data.user.id', $user->id);
    }

    /**
     * @test
     */
    public function itCreatesAnOffice()
    {
        Notification::fake();

        $admins = User::factory(5)->create()->each(fn($user) => $user->assignRole('super admin'));
        $host = User::factory()->create()->givePermissionTo(Permission::create(['name' => 'office.create']));
        $tags = Tag::factory(2)->create();

        $this->actingAs($host);

        $this->postJson(route('offices.store'), Office::factory()->raw([
            'tags' => $tags->pluck('id')->toArray()
        ]))
            ->assertCreated()
            ->assertJsonPath('data.approval_status', Office::APPROVAL_PENDING)
            ->assertJsonPath('data.reservations_count', 0)
            ->assertJsonPath('data.user.id', $host->id)
            ->assertJsonCount(2, 'data.tags');

        Notification::assertSentTo($admins, OfficePendingApproval::class);
    }

    /**
     * @test
     */
    public function itDoesntAllowCreatingIfScopeIsNotProvided()
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $this->postJson(route('offices.store'))
            ->assertForbidden();
    }

    /**
     * @test
     */
    public function itUpdatesAnOffice()
    {
        $user = User::factory()->create();
        $tags = Tag::factory(3)->create();
        $office = Office::factory()->for($user)->create();

        $office->tags()->attach($tags);

        $this->actingAs($user);

        $anotherTag = Tag::factory()->create();

        $this->putJson(route('offices.update', [
            $office->id,
            'title' => 'Amazing Office',
            'tags' => [$tags[0]->id, $anotherTag->id],
        ]))
            ->assertOk()
            ->assertJsonCount(2, 'data.tags')
            ->assertJsonPath('data.tags.0.id', $tags[0]->id)
            ->assertJsonPath('data.tags.1.id', $anotherTag->id)
            ->assertJsonPath('data.title', 'Amazing Office');
    }

    /**
     * @test
     */
    public function itDoesntUpdateOfficeThatDoesntBelongToUser()
    {
        $user = User::factory()->create();
        $anotherUser = User::factory()->create();
        $office = Office::factory()->for($anotherUser)->create();

        $this->actingAs($user);

        $this->putJson(route('offices.update', [
            $office->id,
            'title' => 'Amazing Office',
        ]))->assertForbidden();
    }

    /**
     * @test
     */
    public function itMarksTheOfficeAsPendingIfDirty()
    {
        Notification::fake();

        $admins = User::factory(5)->create()->each(fn($user) => $user->assignRole('super admin'));

        $user = User::factory()->create();
        $office = Office::factory()->for($user)->create();

        $this->actingAs($user);

        $this->putJson(route('offices.update', [
            $office->id,
            'lat' => 40.74051727562952,
        ]))
            ->assertOk()
            ->assertJsonPath('data.approval_status', Office::APPROVAL_PENDING);

        Notification::assertSentTo($admins, OfficePendingApproval::class);
    }

    /**
     * @test
     */
    public function itUpdatedTheFeaturedImageOfAnOffice()
    {
        $host = User::factory()->create();
        $office = Office::factory()->for($host)->create();

        $image = $office->images()->create([
            'path' => 'image.jpg'
        ]);

        $this->actingAs($host);

        $this->putJson(route('offices.update', $office->id), [
            'featured_image_id' => $image->id
        ])
            ->assertOk()
            ->assertJsonPath('data.featured_image.path', Storage::url($image->path));
    }

    /**
     * @test
     */
    public function itDoesntUpdateFeaturedImageThatBelongsToAnotherOffice()
    {
        $host = User::factory()->create();
        $office = Office::factory()->for($host)->create();
        $anotherOffice = Office::factory()->for($host)->create();

        $image = $anotherOffice->images()->create([
            'path' => 'image.jpg'
        ]);

        $this->actingAs($host);

        $this->putJson(route('offices.update', $office->id), [
            'featured_image_id' => $image->id,
        ])
            ->assertUnprocessable()
            ->assertInvalid('featured_image_id');
    }

    /**
     * @test
     */
    public function itCanDeleteOffices()
    {
        Storage::fake()->put('/office_image.jpg', 'empty');

        $user = User::factory()->create();
        $office = Office::factory()->for($user)->create();

        $image = $office->images()->create([
            'path' => 'office_image.jpg'
        ]);

        $this->actingAs($user);

        $this->deleteJson(route('offices.destroy', $office->id))
            ->assertOk();

        $this->assertSoftDeleted($office)
            ->assertModelMissing($image);

        Storage::assertMissing('office_image.jpg');
    }

    /**
     * @test
     */
    public function itCannotDeleteAnOfficeThatHasReservations()
    {
        $user = User::factory()->create();
        $office = Office::factory()->for($user)->create();

        Reservation::factory(3)->for($office)->create();

        $this->actingAs($user);

        $this->deleteJson(route('offices.destroy', $office->id))
            ->assertUnprocessable();

        $this->assertNotSoftDeleted($office);
    }
}
