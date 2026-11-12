<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\ThinkTagFilter;
use PHPUnit\Framework\TestCase;

class ThinkTagFilterTest extends TestCase
{
    private function stream(array $chunks): string
    {
        $f = new ThinkTagFilter;
        $out = '';
        foreach ($chunks as $i => $c) {
            $out .= $f->push($c, $i === array_key_last($chunks));
        }

        return $out;
    }

    public function test_split_tags_never_leak(): void
    {
        $this->assertSame('Bok Ana.', $this->stream(['<', 'think', '>', 'razmišljam ', 'o ovome', '</', 'think>', 'Bok ', 'Ana.']));
        $this->assertSame("\nBok Ana.", $this->stream(["<think>\nhmm\n</think>\nBok Ana."]));
    }

    public function test_plain_text_with_angle_brackets_passes_through(): void
    {
        $this->assertSame('a < b and <b>bold</b> <thin', $this->stream(['a < b and <b>bo', 'ld</b> <thin']));
        $this->assertSame('', ThinkTagFilter::strip('<think>never closed x'));
    }
}
