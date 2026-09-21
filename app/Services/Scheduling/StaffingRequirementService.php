<?php

namespace App\Services\Scheduling;

use App\Models\Department;
use App\Models\Room;
use App\Models\Shift;
use Illuminate\Support\Collection;

/**
 * Answers "how many people, and how many of them senior, does this shift of this
 * unit need on duty?".
 *
 * The answer is looked up rather than typed, so every roster for a unit is held
 * to the same standard. Three sources are consulted in order: a requirement
 * recorded for that exact unit and shift, the figure derived from the unit's beds
 * and nurse-to-patient ratio, and finally a bare minimum of one.
 */
class StaffingRequirementService
{
    public const FALLBACK_MINIMUM_STAFF = 1;

    /**
     * @param  Collection<int, Shift>  $shifts
     * @return Collection<int, array{staff: int, senior: int, source: string}>
     */
    public function forShifts(Department $department, Collection $shifts): Collection
    {
        $recorded = $department->relationLoaded('shiftRequirements')
            ? $department->shiftRequirements
            : $department->shiftRequirements()->get();

        $recorded = $recorded->keyBy('shift_id');
        $derived = $department->derivedMinimumStaffPerShift();

        return $shifts->mapWithKeys(function (Shift $shift) use ($recorded, $derived): array {
            $requirement = $recorded->get($shift->id);

            $staff = $requirement?->minimum_staff
                ?? $derived
                ?? self::FALLBACK_MINIMUM_STAFF;

            $source = match (true) {
                $requirement?->minimum_staff !== null => 'unit shift standard',
                $derived !== null => 'beds and ratio',
                default => 'default minimum',
            };

            return [$shift->id => [
                'staff' => max(1, (int) $staff),
                'senior' => (int) ($requirement?->minimum_senior ?? 0),
                'source' => $source,
            ]];
        });
    }

    /**
     * @return array{staff: int, senior: int, source: string}
     */
    public function forShift(Department $department, Shift $shift): array
    {
        return $this->forShifts($department, collect([$shift]))->get($shift->id);
    }

    /**
     * The same question asked of a single room rather than a whole unit.
     *
     * The ladder is deliberately shorter than the unit's. A recorded room
     * standard wins; failing that, a bedded room derives from its own beds and
     * its unit's ratio; failing that, one. What it must never do is fall through
     * to the *department's* figure — that number is the whole ward's cover, and
     * copying it into each of six rooms would demand six wards' worth of nurses
     * for one ward.
     *
     * @param  Collection<int, Shift>  $shifts
     * @return Collection<int, array{staff: int, senior: int, source: string, operates: bool}>
     */
    public function forRoomShifts(Room $room, Collection $shifts): Collection
    {
        $recorded = ($room->relationLoaded('shiftRequirements')
            ? $room->shiftRequirements
            : $room->shiftRequirements()->get())
            ->keyBy('shift_id');

        $derived = $room->derivedMinimumStaffPerShift();

        return $shifts->mapWithKeys(function (Shift $shift) use ($room, $recorded, $derived): array {
            $requirement = $recorded->get($shift->id);

            $staff = $requirement?->minimum_staff
                ?? $derived
                ?? self::FALLBACK_MINIMUM_STAFF;

            $source = match (true) {
                $requirement?->minimum_staff !== null => 'room shift standard',
                $derived !== null => 'room beds and unit ratio',
                default => 'default minimum',
            };

            return [$shift->id => [
                'staff' => max(1, (int) $staff),
                // A room that records no charge-cover figure still inherits the
                // one on the room itself, which is what makes a theatre refuse
                // an all-entry-level list without anybody filling in a grid.
                'senior' => (int) ($requirement?->minimum_senior ?: ($room->min_seniority_rank > 1 ? 1 : 0)),
                'source' => $source,
                'operates' => $requirement?->operates ?? true,
            ]];
        });
    }

    /**
     * @return array{staff: int, senior: int, source: string, operates: bool}
     */
    public function forRoomShift(Room $room, Shift $shift): array
    {
        return $this->forRoomShifts($room, collect([$shift]))->get($shift->id);
    }

    /**
     * How the derived figure was reached, for showing beside a roster so a
     * reviewer can see the standard rather than take the number on trust.
     */
    public function roomDerivationSummary(Room $room): ?string
    {
        $derived = $room->derivedMinimumStaffPerShift();

        if ($derived === null) {
            return null;
        }

        $ratio = $room->department?->nurse_patient_ratio ?: Department::DEFAULT_NURSE_PATIENT_RATIO;

        return sprintf(
            '%d beds at a 1:%d nurse-to-patient ratio needs %d in this room per shift.',
            $room->bed_capacity,
            $ratio,
            $derived,
        );
    }

    /**
     * How the derived figure was reached, for showing beside a roster so a
     * reviewer can see the standard rather than take the number on trust.
     */
    public function derivationSummary(Department $department): ?string
    {
        $derived = $department->derivedMinimumStaffPerShift();

        if ($derived === null) {
            return null;
        }

        $ratio = $department->nurse_patient_ratio ?: Department::DEFAULT_NURSE_PATIENT_RATIO;

        return sprintf(
            '%d beds at a 1:%d nurse-to-patient ratio needs %d on duty per shift.',
            $department->bed_capacity,
            $ratio,
            $derived,
        );
    }
}
