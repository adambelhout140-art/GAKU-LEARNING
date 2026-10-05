<?php
function calculateCombinedAssessmentResult(array $quizScores, float $examPercentage, float $passingScore): array
{
    $quizAverage = null;
    $combinedPercentage = $examPercentage;

    if ($quizScores) {
        $quizAverage = array_sum(array_map("floatval", $quizScores)) / count($quizScores);
        $combinedPercentage = ($quizAverage + $examPercentage) / 2;
    }

    $combinedPercentage = round($combinedPercentage, 2);

    return [
        "quiz_average_percentage" => $quizAverage === null ? null : round($quizAverage, 2),
        "quiz_count" => count($quizScores),
        "combined_percentage" => $combinedPercentage,
        "overall_passed" => $examPercentage >= $passingScore && $combinedPercentage >= $passingScore,
    ];
}
