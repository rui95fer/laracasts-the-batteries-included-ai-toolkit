<?php

use App\Support\Vector;

test('cosine similarity returns one for identical vectors', function () {
    $vector = [1.0, 2.0, 3.0];

    expect(Vector::cosineSimilarity($vector, $vector))->toBe(1.0);
});

test('cosine similarity returns zero for orthogonal vectors', function () {
    $a = [1.0, 0.0];
    $b = [0.0, 1.0];

    expect(Vector::cosineSimilarity($a, $b))->toBe(0.0);
});

test('cosine similarity returns zero for mismatched dimensions', function () {
    expect(Vector::cosineSimilarity([1.0, 2.0], [1.0]))->toBe(0.0);
});

test('cosine similarity returns zero for empty vectors', function () {
    expect(Vector::cosineSimilarity([], []))->toBe(0.0);
});

test('cosine similarity returns zero when either vector is zero magnitude', function () {
    expect(Vector::cosineSimilarity([0.0, 0.0], [1.0, 1.0]))->toBe(0.0);
    expect(Vector::cosineSimilarity([1.0, 1.0], [0.0, 0.0]))->toBe(0.0);
});
