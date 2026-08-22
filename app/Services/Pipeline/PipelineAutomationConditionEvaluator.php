<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineLead;

/**
 * Evaluates a rule's conditions against a lead. Conditions are a list of
 * {field, operator, value, meta_field_id?} objects combined with AND by
 * default, OR a nested group: {logic: 'and'|'or', conditions: [...]} to mix
 * AND/OR at the top level.
 */
class PipelineAutomationConditionEvaluator
{
    public function passes(PipelineLead $lead, ?array $conditions): bool
    {
        if (empty($conditions)) {
            return true;
        }

        return $this->evaluateGroup($lead, $conditions);
    }

    /**
     * Evaluate a condition group. A group is either a flat list (AND) or a
     * {logic, conditions} wrapper. Nested groups are supported recursively.
     *
     * @param  array<int, mixed>|array{logic?: string, conditions?: array<mixed>}  $group
     */
    protected function evaluateGroup(PipelineLead $lead, array $group): bool
    {
        $logic = strtolower((string) ($group['logic'] ?? 'and'));

        if (isset($group['logic']) && isset($group['conditions'])) {
            $items = $group['conditions'] ?? [];
            $results = array_map(fn ($item) => $this->evaluateItem($lead, $item), $items);

            return $logic === 'or' ? in_array(true, $results, true) : ! in_array(false, $results, true);
        }

        // Flat list: AND.
        foreach ($group as $condition) {
            if (! $this->evaluateItem($lead, $condition)) {
                return false;
            }
        }

        return true;
    }

    /** @param  array<string, mixed>  $item */
    protected function evaluateItem(PipelineLead $lead, array $item): bool
    {
        if (isset($item['logic']) && isset($item['conditions'])) {
            return $this->evaluateGroup($lead, $item);
        }

        return $this->passesCondition($lead, $item);
    }

    /** @param  array<string, mixed>  $condition */
    protected function passesCondition(PipelineLead $lead, array $condition): bool
    {
        $field = (string) ($condition['field'] ?? '');
        $operator = (string) ($condition['operator'] ?? 'is');
        $expected = $condition['value'] ?? null;

        return match ($field) {
            'stage_id' => $this->compare($lead->stage_id, $operator, $expected),
            'status' => $this->compare($lead->status, $operator, $expected),
            'priority' => $this->compare($lead->priority, $operator, $expected),
            'card_type' => $this->compare($lead->card_type ?? 'lead', $operator, $expected),
            'assigned_to' => $this->compare($lead->assigned_to, $operator, $expected),
            'estimated_value' => $this->compareNumber((float) ($lead->estimated_value ?? 0), $operator, $expected),
            'due_date' => $this->compareDate($lead->due_date, $operator, $expected),
            'start_date' => $this->compareDate($lead->start_date, $operator, $expected),
            'created_at' => $this->compareDate($lead->created_at, $operator, $expected),
            'has_label' => $this->hasLabel($lead, $expected),
            'meta' => $this->compareMeta($lead, $condition),
            default => true,
        };
    }

    protected function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        if ($operator === 'is') {
            return (string) $actual === (string) $expected;
        }
        if ($operator === 'is_not') {
            return (string) $actual !== (string) $expected;
        }
        if ($operator === 'is_empty') {
            return $actual === null || $actual === '' || $actual === 0;
        }
        if ($operator === 'is_not_empty') {
            return $actual !== null && $actual !== '' && $actual !== 0;
        }
        if ($operator === 'contains') {
            return is_string($actual) && mb_strpos($actual, (string) $expected) !== false;
        }
        if ($operator === 'in') {
            return is_array($expected) && in_array((string) $actual, array_map('strval', $expected), true);
        }
        if ($operator === 'not_in') {
            return is_array($expected) && ! in_array((string) $actual, array_map('strval', $expected), true);
        }

        return false;
    }

    protected function compareNumber(float $actual, string $operator, mixed $expected): bool
    {
        $expectedNum = (float) $expected;
        if ($operator === 'greater_than') {
            return $actual > $expectedNum;
        }
        if ($operator === 'less_than') {
            return $actual < $expectedNum;
        }
        if ($operator === 'is') {
            return $actual === $expectedNum;
        }

        return $this->compare($actual, $operator, $expected);
    }

    protected function compareDate(mixed $actual, string $operator, mixed $expected): bool
    {
        if ($operator === 'is_before') {
            return $actual !== null && strtotime((string) $actual) < strtotime((string) $expected);
        }
        if ($operator === 'is_after') {
            return $actual !== null && strtotime((string) $actual) > strtotime((string) $expected);
        }
        if ($operator === 'is') {
            return $actual !== null && date('Y-m-d', strtotime((string) $actual)) === (string) $expected;
        }

        return $this->compare($actual, $operator, $expected);
    }

    protected function hasLabel(PipelineLead $lead, mixed $expected): bool
    {
        $labelIds = $lead->labels()->pluck('pipeline_labels.id')->map(fn ($id) => (int) $id)->all();
        if (is_array($expected)) {
            return count(array_intersect($labelIds, array_map('intval', $expected))) > 0;
        }

        return in_array((int) $expected, $labelIds, true);
    }

    /** @param  array<string, mixed>  $condition */
    protected function compareMeta(PipelineLead $lead, array $condition): bool
    {
        $metaFieldId = (int) ($condition['meta_field_id'] ?? 0);
        if ($metaFieldId <= 0) {
            return true;
        }

        $value = \App\Models\PipelineLeadMetaValue::query()
            ->where('lead_id', $lead->id)
            ->where('meta_field_id', $metaFieldId)
            ->value('value');

        return $this->compare($value, (string) ($condition['operator'] ?? 'is'), $condition['value'] ?? null);
    }
}