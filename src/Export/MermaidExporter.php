<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Export;

use Dskripchenko\ProcessEngine\Definition\Definition;
use Dskripchenko\ProcessEngine\Definition\StateNode;

/**
 * A Mermaid `stateDiagram-v2` of a definition: for documentation, reviews
 * and @dskripchenko/diagrams.
 */
final class MermaidExporter
{
    public function export(Definition $definition): string
    {
        $lines = ['stateDiagram-v2'];
        $this->level($definition, $definition->root, $lines, 1);

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<string>  $lines
     */
    private function level(Definition $definition, StateNode $level, array &$lines, int $depth): void
    {
        $pad = str_repeat('    ', $depth);
        if ($level->isParallel()) {
            $regions = $level->stateChildren();
            foreach ($regions as $i => $region) {
                $lines[] = $pad.'state '.self::id($region->id).' {';
                $this->level($definition, $region, $lines, $depth + 1);
                $lines[] = $pad.'}';
                if ($i < count($regions) - 1) {
                    $lines[] = $pad.'--';
                }
            }

            return;
        }
        $children = $level->stateChildren();
        if ($children === []) {
            return;
        }
        $initial = $level->initial !== null ? $definition->node($level->initial) : $children[0];
        $lines[] = $pad.'[*] --> '.self::id($initial->id);
        foreach ($children as $child) {
            if ($child->isFinal()) {
                $lines[] = $pad.self::id($child->id).' --> [*]';
            }
            if ($child->type->value === 'choice') {
                $lines[] = $pad.'state '.self::id($child->id).' <<choice>>';
            }
            if ($child->isCompound() || $child->isParallel()) {
                $lines[] = $pad.'state '.self::id($child->id).' {';
                $this->level($definition, $child, $lines, $depth + 1);
                $lines[] = $pad.'}';
            }
            if ($child->label() !== null) {
                $lines[] = $pad.self::id($child->id).' : '.self::text($child->label());
            }
            foreach ([...$child->on, ...$child->always] as $transition) {
                if ($transition->target === null) {
                    continue;
                }
                $label = $transition->label ?? $transition->event ?? '';
                if (str_starts_with($label, 'after.')) {
                    $label = 'timer';
                } elseif (str_starts_with($label, 'done.')) {
                    $label = 'done';
                } elseif (str_starts_with($label, 'error.')) {
                    $label = 'error';
                } elseif (class_exists($label)) {
                    $label = substr((string) strrchr('\\'.$label, '\\'), 1);
                }
                $lines[] = $pad.self::id($child->id).' --> '.self::id($transition->target).($label !== '' ? ' : '.self::text($label) : '');
            }
        }
    }

    private static function id(string $id): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_]/', '_', $id);
    }

    private static function text(string $text): string
    {
        return str_replace([':', "\n"], [' ', ' '], $text);
    }
}
