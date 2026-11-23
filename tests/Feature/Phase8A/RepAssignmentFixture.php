<?php

namespace Tests\Feature\Phase8A;

use App\Enums\Role;
use App\Models\RepSupervisorAssignment;
use App\Models\Setting;
use App\Models\User;
use App\Services\Reps\RepScope;
use App\Support\ArabicNormalizer;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Phase6\RepFixture;

/**
 * سيناريو المرحلة 8A فوق سيناريو المندوب (RepFixture):
 * - المندوب كرم (rep1) مشترك: «منطقة الشمال» عند المشرف 1 (repSupervisor)، و«منطقة الجنوب» عند المشرف 2 (sup2).
 * - سامر (rep2) بلا مشرف. كل الصيدليات في «كتلة».
 * - الكشف A: شمال G:11/G:12 [كرم] وA:13 [كول سنتر]، جنوب B:21 [كرم]، شرق G:22 [سامر]. الكشف B: شمال G:31 [كرم].
 */
trait RepAssignmentFixture
{
    use RepFixture;

    protected User $sup2;

    protected function setUpAssignments(bool $assign = true): void
    {
        $this->setUpReps();
        $this->repSupervisor->update(['name' => 'المشرف أحمد']);
        $this->sup2 = User::factory()->role(Role::RepSupervisor)->create(['name' => 'المشرف باسل']);
        if ($assign) {
            $this->assign($this->rep1, $this->repSupervisor, 'area', ['منطقة الشمال'])->assertSessionHasNoErrors();
            $this->assign($this->rep1, $this->sup2, 'area', ['منطقة الجنوب'])->assertSessionHasNoErrors();
        }
    }

    protected function assign(User $rep, User $supervisor, string $type, array $values = [], ?User $by = null): TestResponse
    {
        return $this->actingAs($by ?? $this->admin)->from(route('admin.rep-assignments.index'))->post(route('admin.rep-assignments.store'), [
            'rep_id' => $rep->id,
            'supervisor_id' => $supervisor->id,
            'scope_type' => $type,
            'scope_values' => $values,
        ]);
    }

    protected function enableScoping(): void
    {
        Setting::set(RepScope::SETTING, true);
    }

    protected function active(): \Illuminate\Support\Collection
    {
        return RepSupervisorAssignment::active()->orderBy('id')->get();
    }

    protected function norm(string $text): string
    {
        return ArabicNormalizer::normalize($text);
    }
}
