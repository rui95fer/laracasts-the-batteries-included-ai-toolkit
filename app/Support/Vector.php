<?php

namespace App\Support;

class Vector
{
    /**
     * Compute the cosine similarity between two numeric vectors.
     *
     * Returns a value between 0.0 (orthogonal) and 1.0 (identical) for
     * non-empty vectors with matching dimensions. Returns 0.0 for any
     * input that is empty, mismatched, or has zero magnitude, so callers
     * can treat the result as a safe similarity score to threshold on.
     *
     * @param  array<int, float|int>  $a
     * @param  array<int, float|int>  $b
     */
    public static function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) === 0 || count($a) !== count($b)) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $magnitudeA = 0.0;
        $magnitudeB = 0.0;

        foreach ($a as $index => $valueA) {
            $valueB = $b[$index];

            $dotProduct += $valueA * $valueB;
            $magnitudeA += $valueA ** 2;
            $magnitudeB += $valueB ** 2;
        }

        if ($magnitudeA === 0.0 || $magnitudeB === 0.0) {
            return 0.0;
        }

        return $dotProduct / (sqrt($magnitudeA) * sqrt($magnitudeB));
    }
}
