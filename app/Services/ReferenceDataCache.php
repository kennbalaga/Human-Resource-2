<?php

namespace App\Services;

use App\Models\Department;
use App\Models\LeaveType;
use App\Models\OfficeLocation;
use App\Models\Position;
use App\Models\Room;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * The small lookup tables -- departments, positions, leave types -- held in the
 * cache and handed out keyed by id.
 *
 * Between them they hold well under a hundred rows and change perhaps a few
 * times a term, yet almost every screen reads them: an eager load of
 * `employee.department` is a whole round trip to fetch one or two names. That
 * cost is invisible against a local database and dominant against a remote one,
 * where a query is ~58ms of network no matter how little it returns.
 *
 * Writes clear the entry (see each model's `booted`), so an edited department
 * name is visible on the next request rather than after the TTL. The TTL is
 * only a backstop for changes that bypass Eloquent -- a seeder, a migration, or
 * somebody editing rows in the database directly.
 */
class ReferenceDataCache
{
    private const CACHE_TTL_SECONDS = 900;

    /** @var array<class-string<Model>, string> */
    private const CACHE_KEYS = [
        Department::class => 'reference.departments.v1',
        Position::class => 'reference.positions.v1',
        LeaveType::class => 'reference.leave-types.v1',
        Shift::class => 'reference.shifts.v1',
        OfficeLocation::class => 'reference.office-locations.v1',
        Room::class => 'reference.rooms.v1',
    ];

    /**
     * Read once per request even when several panels ask for the same table.
     *
     * @var array<class-string<Model>, Collection<int, Model>>
     */
    private array $memo = [];

    /** @return Collection<int, Department> */
    public function departments(): Collection
    {
        return $this->table(Department::class);
    }

    /** @return Collection<int, Position> */
    public function positions(): Collection
    {
        return $this->table(Position::class);
    }

    /** @return Collection<int, LeaveType> */
    public function leaveTypes(): Collection
    {
        return $this->table(LeaveType::class);
    }

    /** @return Collection<int, Shift> */
    public function shifts(): Collection
    {
        return $this->table(Shift::class);
    }

    /** @return Collection<int, OfficeLocation> */
    public function officeLocations(): Collection
    {
        return $this->table(OfficeLocation::class);
    }

    /** @return Collection<int, Room> */
    public function rooms(): Collection
    {
        return $this->table(Room::class);
    }

    /**
     * Every row of a reference table, keyed by id.
     *
     * Rows go into the cache as plain attribute arrays and come back out through
     * `hydrate()`, rather than caching the models themselves: `cache.php` sets
     * `serializable_classes` to false, so the store refuses to rebuild any object
     * at all -- a deliberate guard against gadget chains should APP_KEY ever
     * leak. Hydrating is free anyway; it is the round trip we came here to avoid.
     *
     * @param  class-string<Model>  $model
     * @return Collection<int, Model>
     */
    public function table(string $model): Collection
    {
        return $this->memo[$model] ??= $model::hydrate(
            Cache::remember(
                self::CACHE_KEYS[$model],
                self::CACHE_TTL_SECONDS,
                // Soft-deleted rows are excluded by the model's own global scope,
                // which is what an eager load would have done too: a row pointing
                // at a deleted department resolves to null either way.
                fn (): array => $model::query()->get()
                    ->map(fn (Model $row): array => $row->getAttributes())
                    ->all(),
            )
        )->keyBy('id');
    }

    /**
     * Fill a belongsTo relation from the cache instead of eager loading it.
     *
     * The view still walks `$employee->department->name`; it just no longer
     * costs a query to get there. Rows whose foreign key is null, or points at a
     * deleted parent, get null -- the same answer `with()` gives.
     *
     * @param  iterable<Model>  $models
     * @param  class-string<Model>  $reference
     */
    public function attach(iterable $models, string $relation, string $foreignKey, string $reference): void
    {
        $lookup = $this->table($reference);

        foreach ($models as $model) {
            $key = $model->getAttribute($foreignKey);

            $model->setRelation($relation, $key === null ? null : $lookup->get($key));
        }
    }

    /**
     * Drop a table's entry after a write. Called from the models themselves, so
     * no caller has to remember to do it.
     *
     * @param  class-string<Model>  $model
     */
    public static function forget(string $model): void
    {
        if (isset(self::CACHE_KEYS[$model])) {
            Cache::forget(self::CACHE_KEYS[$model]);
        }
    }
}
