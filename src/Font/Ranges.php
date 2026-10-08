<?php

declare(strict_types=1);

namespace YetiPdf\Font;

/**
 * Code ranges that may overlap, flattened once into sorted pieces that cannot, so finding the range
 * that holds a code is a binary search instead of a walk through every range.
 *
 * Building takes time proportional to the number of ranges however wide they are, which is the point:
 * a range such as <00000000> <FFFFFFFF> costs the same as <0041> <0042>.
 */
final class Ranges
{
    /** @var list<int> */
    private array $starts = [];
    /** @var list<int> */
    private array $ends = [];
    /** @var list<int> */
    private array $ids = [];

    /**
     * @param list<array{0: int, 1: int}> $ranges [low, high] pairs. Where ranges overlap, the one earlier in the list wins.
     */
    public function __construct(array $ranges)
    {
        $lows = array_column($ranges, 0);
        $order = array_keys($ranges);
        // Sort by low end; equal low ends keep their order in the list.
        array_multisort($lows, SORT_ASC, SORT_NUMERIC, $order, SORT_ASC, SORT_NUMERIC);

        $n = count($order);
        $heap = new \SplMinHeap();
        $i = 0;
        $pos = 0;
        while ($i < $n || !$heap->isEmpty()) {
            if ($heap->isEmpty()) {
                $pos = $lows[$i];
            }
            while ($i < $n && $lows[$i] <= $pos) {
                $id = $order[$i];
                if ($ranges[$id][1] >= $pos) {
                    $heap->insert([$id, $ranges[$id][1]]);
                }
                $i++;
            }
            while (!$heap->isEmpty() && $heap->top()[1] < $pos) {
                $heap->extract();
            }
            if ($heap->isEmpty()) {
                continue;
            }
            [$id, $high] = $heap->top();
            // The winner holds until its own end, or until a range that starts later (and might outrank it) begins.
            $end = ($i < $n && $lows[$i] - 1 < $high) ? $lows[$i] - 1 : $high;
            $last = count($this->ids) - 1;
            if ($last >= 0 && $this->ids[$last] === $id && $this->ends[$last] + 1 === $pos) {
                $this->ends[$last] = $end;
            } else {
                $this->starts[] = $pos;
                $this->ends[] = $end;
                $this->ids[] = $id;
            }
            $pos = $end + 1;
        }
    }

    /** Position in the original list of the range that holds $code, or null. */
    public function find(int $code): ?int
    {
        $low = 0;
        $high = count($this->starts) - 1;
        while ($low <= $high) {
            $mid = ($low + $high) >> 1;
            if ($this->starts[$mid] > $code) {
                $high = $mid - 1;
            } elseif ($this->ends[$mid] < $code) {
                $low = $mid + 1;
            } else {
                return $this->ids[$mid];
            }
        }
        return null;
    }
}
