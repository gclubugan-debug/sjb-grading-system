<?php

function component_weight($component)
{
    return [
        "Exam"       => 0.40,
        "Project"    => 0.30,
        "Quiz"       => 0.20,
        "Attendance" => 0.10
    ][$component] ?? 0;
}

function college_equivalent($p)
{
    if ($p >= 97 && $p <= 100) return "1.00";
    if ($p >= 94) return "1.25";
    if ($p >= 91) return "1.50";
    if ($p >= 88) return "1.75";
    if ($p >= 85) return "2.00";
    if ($p >= 82) return "2.25";
    if ($p >= 79) return "2.50";
    if ($p >= 76) return "2.75";
    if ($p >= 75) return "3.00";

    return "5.00";
}

function remarks($p)
{
    if ($p <= 0) {
        return "No Data";
    }

    return $p >= 75 ? "Passed" : "Failed";
}

function calculate_period_grade(
    $conn,
    $student_id,
    $subject_id,
    $academic_term,
    $period
) {
    $components = [
        "Exam",
        "Project",
        "Quiz",
        "Attendance"
    ];

    $total_grade = 0;
    $details = [];
    $has_data = false;

    foreach ($components as $component) {

        $stmt = $conn->prepare(
            "SELECT
                SUM(score) AS score_sum,
                SUM(total_score) AS total_sum
             FROM grade_items
             WHERE student_id = ?
             AND subject_id = ?
             AND academic_term = ?
             AND grading_period = ?
             AND component = ?"
        );

        $stmt->bind_param(
            "iisss",
            $student_id,
            $subject_id,
            $academic_term,
            $period,
            $component
        );

        $stmt->execute();

        $r = $stmt->get_result()->fetch_assoc();

        $score_sum = $r['score_sum'] ?? 0;
        $total_sum = $r['total_sum'] ?? 0;

        if ($total_sum > 0) {
            $has_data = true;

            $cp = ($score_sum / $total_sum) * 100;
            $ws = $cp * component_weight($component);
        } else {
            $cp = 0;
            $ws = 0;
        }

        $details[$component] = [
            "percentage" => round($cp, 2),
            "weighted"   => round($ws, 2),
            "has_data"   => $total_sum > 0
        ];

        $total_grade += $ws;
    }

    $total_grade = $has_data
        ? round($total_grade, 2)
        : 0;

    return [
        "percentage" => $total_grade,
        "equivalent" => $has_data
            ? college_equivalent($total_grade)
            : "No Grade",
        "remarks"    => $has_data
            ? remarks($total_grade)
            : "No Data",
        "details"    => $details,
        "has_data"   => $has_data
    ];
}

function calculate_term_grade(
    $conn,
    $student_id,
    $subject_id,
    $academic_term
) {
    $periods = [
        "Prelim",
        "Midterm",
        "Finals"
    ];

    $sum = 0;
    $count = 0;
    $period_results = [];

    foreach ($periods as $period) {

        $g = calculate_period_grade(
            $conn,
            $student_id,
            $subject_id,
            $academic_term,
            $period
        );

        $period_results[$period] = $g;

        if ($g['has_data']) {
            $sum += $g['percentage'];
            $count++;
        }
    }

    if ($count == 0) {
        return [
            "percentage" => 0,
            "equivalent" => "No Grade",
            "remarks"    => "No Data",
            "periods"    => $period_results,
            "has_data"   => false
        ];
    }

    $tg = round($sum / $count, 2);

    return [
        "percentage" => $tg,
        "equivalent" => college_equivalent($tg),
        "remarks"    => remarks($tg),
        "periods"    => $period_results,
        "has_data"   => true
    ];
}

function needed_average_for_remaining_periods(
    $completed,
    $passing = 75
) {
    $remaining = 3 - count($completed);

    if ($remaining <= 0) {
        return null;
    }

    return round(
        max(
            0,
            (($passing * 3) - array_sum($completed)) / $remaining
        ),
        2
    );
}

function ai_period_feedback(
    $period_name,
    $period_result
) {
    if (!$period_result['has_data']) {
        return [
            "status"     => "No Data",
            "feedback"   => "No grades have been encoded for {$period_name} yet.",
            "suggestion" => "Encode quiz, exam, project, or attendance scores to generate feedback.",
            "risk"       => false
        ];
    }

    $g = $period_result['percentage'];

    if ($g < 75) {

        $need = round(75 - $g, 2);

        return [
            "status"     => "At Risk",
            "feedback"   => "The student is currently below passing for {$period_name}.",
            "suggestion" => "The student needs to improve by at least {$need}% in {$period_name} components or perform better in the remaining grading periods.",
            "risk"       => true
        ];
    }

    if ($g < 80) {
        return [
            "status"     => "Needs Monitoring",
            "feedback"   => "The student is passing {$period_name}, but the grade is close to the failing mark.",
            "suggestion" => "Aim for at least 80% or higher in upcoming activities to stay safe.",
            "risk"       => false
        ];
    }

    return [
        "status"     => "Good Standing",
        "feedback"   => "The student is currently passing {$period_name}.",
        "suggestion" => "Maintain the current performance and continue submitting activities on time.",
        "risk"       => false
    ];
}

function ai_term_feedback($term_result)
{
    $completed = [];

    foreach (["Prelim", "Midterm", "Finals"] as $p) {
        if ($term_result['periods'][$p]['has_data']) {
            $completed[$p] = $term_result['periods'][$p]['percentage'];
        }
    }

    if (count($completed) == 0) {
        return [
            "status"     => "No Data",
            "feedback"   => "No grades have been encoded yet.",
            "suggestion" => "Encode at least one grade item to generate AI feedback.",
            "risk"       => false
        ];
    }

    $values = array_values($completed);
    $avg = array_sum($values) / count($values);

    $first = $values[0];
    $last = $values[count($values) - 1];

    $needed = needed_average_for_remaining_periods(
        $values,
        75
    );

    $trend = "Consistent";

    if (count($values) >= 2) {
        if ($last > $first + 2) {
            $trend = "Improving";
        } elseif ($last < $first - 2) {
            $trend = "Declining";
        }
    }

    if ($avg < 75) {

        $suggestion = $needed === null
            ? "The term is complete and the computed term grade is below passing."
            : (
                $needed > 100
                    ? "The student needs more than 100% average in the remaining period/s, so immediate teacher intervention is recommended."
                    : "The student should aim for at least {$needed}% average in the remaining grading period/s to pass the whole term."
            );

        return [
            "status"     => "At Risk",
            "feedback"   => "The student is at risk of failing the term because the current average is below 75.",
            "suggestion" => $suggestion,
            "risk"       => true
        ];
    }

    if ($trend === "Declining") {
        return [
            "status"     => "Declining",
            "feedback"   => "The student is currently passing, but performance is declining.",
            "suggestion" => "The student should aim for 80% or higher in the next grading period to avoid becoming at risk.",
            "risk"       => false
        ];
    }

    if ($trend === "Improving") {
        return [
            "status"     => "Improving",
            "feedback"   => "The student performance is improving.",
            "suggestion" => "Continue the current study habits and maintain passing scores.",
            "risk"       => false
        ];
    }

    return [
        "status"     => "Consistent",
        "feedback"   => "The student performance is consistent.",
        "suggestion" => "Maintain at least 75% in succeeding activities and examinations.",
        "risk"       => false
    ];
}

?>