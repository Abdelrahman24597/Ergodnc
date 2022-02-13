<?php

namespace App\Http\Controllers;

use App\Http\Resources\OfficeResource;
use App\Models\Office;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\OfficePendingApproval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class OfficeController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'verified'])->except(['index', 'show']);
        $this->authorizeResource(Office::class);
    }

    public function index(): JsonResource
    {
        $offices = Office::query()
            ->when(
                request('host_id') && auth()->check() && auth()->id() == request('host_id'),
                fn($builder) => $builder,
                fn($builder) => $builder->whereApprovalStatus(Office::APPROVAL_APPROVED)->whereIsHidden(false)
            )
            ->when(request('host_id'), fn(Builder $builder) => $builder->whereUserId(request('host_id')))
            ->when(request('visitor_id'),
                fn(Builder $builder) => $builder->whereRelation('reservations', 'user_id', '=', request('visitor_id')))
            ->when(
                request('lat') && request('lng'),
                fn($builder) => $builder->nearestTo(request('lat'), request('lng')),
                fn($builder) => $builder->orderBy('id', 'ASC')
            )
            ->when(request('tags'),
                fn($builder) => $builder->whereHas(
                    'tags',
                    fn($builder) => $builder->whereIn('id', request('tags')),
                    '=',
                    count(request('tags'))
                )
            )
            ->with(['images', 'tags', 'user'])
            ->withCount(['reservations' => fn($builder) => $builder->whereStatus(Reservation::STATUS_ACTIVE)])
            ->paginate(20);

        return OfficeResource::collection($offices);
    }

    public function show(Office $office): JsonResource
    {
        abort_if(
            !auth()->check() && ($office->approval_status == Office::APPROVAL_PENDING || $office->is_hidden == true),
            Response::HTTP_NOT_FOUND
        );

        $office->load(['images', 'tags', 'user'])
            ->loadCount(['reservations' => fn(Builder $builder) => $builder->whereStatus(Reservation::STATUS_ACTIVE)]);

        return OfficeResource::make($office);
    }

    public function store(): JsonResource
    {
        $attributes = request()->validate([
            'title' => 'required|string',
            'description' => 'required|string',
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
            'address_line1' => 'required|string',
            'address_line2' => 'string',
            'hidden' => 'bool',
            'price_per_day' => 'required|integer|min:100',
            'monthly_discount' => 'integer|min:0|max:90',
            'tags' => 'array',
            'tags.*' => 'integer|exists:tags,id',
        ]);

        $attributes['approval_status'] = Office::APPROVAL_PENDING;
        $attributes['user_id'] = auth()->id();

        $office = DB::transaction(function () use ($attributes) {
            $office = Office::create(Arr::except($attributes, ['tags']));

            if (isset($attributes['tags'])) {
                $office->tags()->attach($attributes['tags']);
            }

            return $office;
        });

        $this->notifyAdmins($office);

        return OfficeResource::make(
            $office->load(['user', 'images', 'featuredImage', 'tags',])
        );
    }

    public function update(Office $office): JsonResource
    {
        $attributes = request()->validate([
            'title' => 'sometimes|required|string',
            'description' => 'sometimes|required|string',
            'lat' => 'sometimes|required|numeric',
            'lng' => 'sometimes|required|numeric',
            'address_line1' => 'sometimes|required|string',
            'address_line2' => 'string',
            'hidden' => 'bool',
            'price_per_day' => 'sometimes|required|integer|min:100',
            'monthly_discount' => 'integer|min:0|max:90',
            'tags' => 'array',
            'tags.*' => 'integer|exists:tags,id',
            'featured_image_id' => 'integer|exists:images,id,resource_type,' . Office::class . ',resource_id,' . $office->id,
        ]);

        $office->fill(Arr::except($attributes, ['tags']));

        if ($requiresReview = $office->isDirty(['lat', 'lng', 'price_per_day'])) {
            $office->fill(['approval_status' => Office::APPROVAL_PENDING]);
        }

        DB::transaction(function () use ($office, $attributes) {
            $office->save();

            if (isset($attributes['tags'])) {
                $office->tags()->sync($attributes['tags']);
            }
        });

        if ($requiresReview) {
            $this->notifyAdmins($office);
        }

        return OfficeResource::make(
            $office->load(['images', 'featuredImage', 'tags', 'user'])
        );
    }

    public function destroy(Office $office)
    {
        throw_if(
            $office->reservations()->whereStatus(Reservation::STATUS_ACTIVE)->exists(),
            ValidationException::withMessages(['office' => 'Cannot delete this office!'])
        );

        $office->images()->each(function ($image) {
            Storage::delete($image->path);

            $image->delete();
        });

        $office->delete();
    }

    protected function notifyAdmins(Office $office): void
    {
        $adminRoles = ['super admin',];
        $admins = User::role($adminRoles)->get();

        Notification::send($admins, new OfficePendingApproval($office));
    }
}
