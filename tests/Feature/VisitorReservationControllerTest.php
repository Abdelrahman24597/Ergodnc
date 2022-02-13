<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\NewHostReservation;
use App\Notifications\NewVisitorReservation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class VisitorReservationControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @test
     */
    public function itListsReservationsThatBelongToTheUser()
    {
        $visitor = User::factory()->create()->givePermissionTo(Permission::create(['name' => 'reservation.index']));

        [$reservation] = Reservation::factory()->for($visitor)->count(2)->create();

        $image = $reservation->office->images()->create([
            'path' => 'office_image.jpg'
        ]);

        $reservation->office()->update(['featured_image_id' => $image->id]);

        Reservation::factory()->count(3)->create();

        $this->actingAs($visitor);

        $this->getJson(route('visitor.reservations.index'))
            ->assertJsonStructure(['data', 'meta', 'links'])
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => ['*' => ['id', 'office']]])
            ->assertJsonPath('data.0.office.featured_image.path', Storage::url($image->path));
    }

    /**
     * @test
     */
    public function itListsReservationFilteredByDateRange()
    {
        $visitor = User::factory()->create()->givePermissionTo(Permission::create(['name' => 'reservation.index']));

        $fromDate = '2021-03-03';
        $toDate = '2021-04-04';

        // Within the date range
        // ...
        $reservations = Reservation::factory()->for($visitor)->createMany([
            [
                'start_date' => '2021-03-01',
                'end_date' => '2021-03-15',
            ],
            [
                'start_date' => '2021-03-25',
                'end_date' => '2021-04-15',
            ],
            [
                'start_date' => '2021-03-25',
                'end_date' => '2021-03-29',
            ],
            [
                'start_date' => '2021-03-01',
                'end_date' => '2021-04-15',
            ],
        ]);

        // Within the range but belongs to a different user
        // ...
        Reservation::factory()->create([
            'start_date' => '2021-03-25',
            'end_date' => '2021-03-29',
        ]);

        // Outside the date range
        // ...
        Reservation::factory()->for($visitor)->create([
            'start_date' => '2021-02-25',
            'end_date' => '2021-03-01',
        ]);

        Reservation::factory()->for($visitor)->create([
            'start_date' => '2021-05-01',
            'end_date' => '2021-05-01',
        ]);

        $this->actingAs($visitor);

        $response = $this->getJson(route('visitor.reservations.index', [
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ]))
            ->assertJsonCount(4, 'data');

        $this->assertEquals($reservations->pluck('id')->toArray(), collect($response->json('data'))->pluck('id')->toArray());
    }

    /**
     * @test
     */
    public function itFiltersResultsByStatus()
    {
        $visitor = User::factory()->create()->givePermissionTo(Permission::create(['name' => 'reservation.index']));

        $reservation = Reservation::factory()->for($visitor)->create();

        Reservation::factory()->for($visitor)->cancelled()->create();

        $this->actingAs($visitor);

        $this->getJson(route('visitor.reservations.index', [
            'status' => Reservation::STATUS_ACTIVE,
        ]))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $reservation->id);
    }

    /**
     * @test
     */
    public function itFiltersResultsByOffice()
    {
        $visitor = User::factory()->create()->givePermissionTo(Permission::create(['name' => 'reservation.index']));

        $office = Office::factory()->create();

        $reservation = Reservation::factory()->for($office)->for($visitor)->create();

        Reservation::factory()->for($visitor)->create();

        $this->actingAs($visitor);

        $this->getJson(route('visitor.reservations.index', [
            'office_id' => $office->id,
        ]))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $reservation->id);
    }

    /**
     * @test
     */
    public function itMakesReservations()
    {
        $visitor = User::factory()->create()->givePermissionTo(Permission::create(['name' => 'reservation.store']));

        $office = Office::factory()->create([
            'price_per_day' => 1_000,
            'monthly_discount' => 10,
        ]);

        $this->actingAs($visitor);

        $this->postJson(route('visitor.reservations.store', [
            'office_id' => $office->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(40)->toDateString(),
        ]))
            ->assertCreated()
            ->assertJsonPath('data.price', 36000)
            ->assertJsonPath('data.user_id', $visitor->id)
            ->assertJsonPath('data.office_id', $office->id)
            ->assertJsonPath('data.status', Reservation::STATUS_ACTIVE);
    }

    /**
     * @test
     */
    public function itCannotMakeReservationOnNonExistingOffice()
    {
        $visitor = User::factory()->create()->givePermissionTo(Permission::create(['name' => 'reservation.store']));

        $this->actingAs($visitor);

        $this->postJson(route('visitor.reservations.store', [
            'office_id' => 10000,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(40)->toDateString(),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['office_id'])
            ->assertJsonCount(1, 'errors');

    }

    /**
     * @test
     */
    public function itCannotMakeReservationOnOfficeThatBelongsToTheUser()
    {
        $visitor = User::factory()->create()->givePermissionTo(Permission::create(['name' => 'reservation.store']));

        $office = Office::factory()->for($visitor)->create();

        $this->actingAs($visitor);

        $this->postJson(route('visitor.reservations.store', [
            'office_id' => $office->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(40)->toDateString(),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['office_id'])
            ->assertJsonCount(1, 'errors');
    }

    /**
     * @test
     */
    public function itCannotMakeReservationOnOfficeThatIsPendingOrHidden()
    {
        $visitor = User::factory()->create()->givePermissionTo(Permission::create(['name' => 'reservation.store']));

        $pendingOffice = Office::factory()->create([
            'approval_status' => Office::APPROVAL_PENDING,
        ]);

        $hiddenOffice = Office::factory()->create([
            'is_hidden' => true,
        ]);

        $this->actingAs($visitor);

        $this->postJson(route('visitor.reservations.store', [
            'office_id' => $pendingOffice->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(40)->toDateString(),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['office_id'])
            ->assertJsonCount(1, 'errors');

        $this->postJson(route('visitor.reservations.store', [
            'office_id' => $hiddenOffice->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(40)->toDateString(),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['office_id'])
            ->assertJsonCount(1, 'errors');
    }

    /**
     * @test
     */
    public function itCannotMakeReservationLessThan2Days()
    {
        $visitor = User::factory()->create()->givePermissionTo(Permission::create(['name' => 'reservation.store']));

        $office = Office::factory()->create();

        $this->actingAs($visitor);

        $this->postJson(route('visitor.reservations.store', [
            'office_id' => $office->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_date'])
            ->assertJsonCount(1, 'errors');
    }

    /**
     * @test
     */
    public function itCannotMakeReservationOnSameDay()
    {
        $visitor = User::factory()->create()->givePermissionTo(Permission::create(['name' => 'reservation.store']));

        $office = Office::factory()->create();

        $this->actingAs($visitor);

        $this->postJson(route('visitor.reservations.store', [
            'office_id' => $office->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date'])
            ->assertJsonCount(1, 'errors');
    }

    /**
     * @test
     */
    public function itMakeReservationFor2Days()
    {
        $visitor = User::factory()->create()->givePermissionTo(Permission::create(['name' => 'reservation.store']));

        $office = Office::factory()->create();

        $this->actingAs($visitor);

        $this->postJson(route('visitor.reservations.store', [
            'office_id' => $office->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
        ]))
            ->assertCreated();
    }

    /**
     * @test
     */
    public function itCannotMakeReservationThatsConflicting()
    {
        $visitor = User::factory()->create()->givePermissionTo(Permission::create(['name' => 'reservation.store']));

        $fromDate = now()->addDays(2)->toDateString();
        $toDate = now()->addDay(15)->toDateString();

        $office = Office::factory()->create();

        Reservation::factory()->for($office)->create([
            'start_date' => now()->addDay(2)->toDateString(),
            'end_date' => $toDate,
        ]);

        $this->actingAs($visitor);

        $this->postJson(route('visitor.reservations.store', [
            'office_id' => $office->id,
            'start_date' => $fromDate,
            'end_date' => $toDate,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['office_id'])
            ->assertJsonCount(1, 'errors');
    }

    /**
     * @test
     */
    public function itSendsNotificationsOnNewReservations()
    {
        Notification::fake();

        $visitor = User::factory()->create()->givePermissionTo(Permission::create(['name' => 'reservation.store']));

        $office = Office::factory()->create();

        $this->actingAs($visitor);

        $this->postJson(route('visitor.reservations.store', [
            'office_id' => $office->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
        ]))
            ->assertCreated();

        Notification::assertSentTo($visitor, NewVisitorReservation::class);
        Notification::assertSentTo($office->user, NewHostReservation::class);
    }
}
