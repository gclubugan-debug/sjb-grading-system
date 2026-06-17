<?php
require_once 'ai_config.php';

function calculate_target_to_pass($periods)
{
    $passing_grade = 75;
    $period_names = ['Prelim', 'Midterm', 'Finals'];
    $completed_sum = 0;
    $completed_count = 0;
    $remaining = [];

    foreach ($period_names as $period) {
        $has_data = $periods[$period]['has_data'] ?? false;
        $percentage = floatval($periods[$period]['percentage'] ?? 0);

        if ($has_data) {
            $completed_sum += $percentage;
            $completed_count++;
        } else {
            $remaining[] = $period;
        }
    }

    $remaining_count = count($remaining);

    if ($remaining_count > 0) {
        $needed_total = ($passing_grade * 3) - $completed_sum;
        $needed_average = $needed_total / $remaining_count;
        $needed_average = round($needed_average, 2);

        if ($needed_average <= 0) {
            return [
                "possible" => true,
                "message" => "The student already has enough standing to pass if performance is maintained.",
                "target_grade" => 75,
                "remaining_periods" => $remaining
            ];
        }

        if ($needed_average > 100) {
            return [
                "possible" => false,
                "message" => "Based on the current grades, the student cannot mathematically reach 75 through the remaining grading period alone. The student may need remediation, extra academic support, or teacher-approved recovery activities.",
                "target_grade" => $needed_average,
                "remaining_periods" => $remaining
            ];
        }

        return [
            "possible" => true,
            "message" => "The student should aim for at least {$needed_average}% average in the remaining grading period(s): " . implode(", ", $remaining) . " to reach the passing grade of 75.",
            "target_grade" => $needed_average,
            "remaining_periods" => $remaining
        ];
    }

    $final_average = round($completed_sum / 3, 2);

    if ($final_average >= $passing_grade) {
        return [
            "possible" => true,
            "message" => "The student has already reached the passing grade.",
            "target_grade" => 75,
            "remaining_periods" => []
        ];
    }

    return [
        "possible" => false,
        "message" => "All grading periods already have data and the final term grade is below 75. The student needs remediation, grade recovery, or teacher-approved intervention.",
        "target_grade" => 75,
        "remaining_periods" => []
    ];
}

function fallback_ai_feedback($grades)
{
    $average = floatval($grades['term_grade'] ?? 0);
    if ($average <= 0) {
        return [
            "status" => "No Data",
            "feedback" => "There is not enough grade data available yet.",
            "suggestion" => "Encode grades first before generating a complete analysis."
        ];
    }

    if ($average < 75) {
        return [
            "status" => "At Risk",
            "feedback" => "The student's current term grade is below the passing mark. Immediate monitoring is needed because the student may fail the term if performance does not improve.",
            "suggestion" => "The student should focus first on the lowest grading components, especially quizzes, exams, projects, or attendance where scores are weakest. The student should create a study schedule, complete all remaining requirements, and consult the teacher for possible intervention or guidance."
        ];
    }

    if ($average < 80) {
        return [
            "status" => "Needs Monitoring",
            "feedback" => "The student is currently passing but close to the failing mark. The student should still be monitored because a low score in the next activities may affect the final term grade.",
            "suggestion" => "The student should continue submitting requirements on time, review weak lessons before the next assessment, and maintain consistent attendance. The student should avoid missing activities because even one low score can affect the final term grade."
        ];
    }

    return [
        "status" => "Good Standing",
        "feedback" => "The student is currently performing at a passing level. The student shows acceptable academic standing for the selected term.",
        "suggestion" => "The student should maintain consistent performance, complete requirements on time, and continue aiming for higher scores."
    ];
}

function generate_ai_feedback_api($student_name, $subject_name, $academic_term, $grades, $mode = "short")
{
    if (!defined("GEMINI_API_KEY") || trim(GEMINI_API_KEY) === "") {
        return fallback_ai_feedback($grades);
    }

    $instruction = $mode === "long"
        ? "Give detailed academic feedback. Do NOT repeat the target grade calculation because it is already displayed separately. Feedback must explain the student's current academic standing in 3 to 5 sentences. Recommendation must give 3 to 5 concrete actions the student can do to improve, such as improving quizzes, exams, projects, attendance, time management, study habits, and consultation with the teacher."
        : "Give a short academic analysis. Do NOT repeat the target grade calculation. Give one useful improvement recommendation.";

    $prompt = "Analyze this student's academic performance and return only valid JSON with no markdown.

$instruction

Student: $student_name
Subject: $subject_name
Academic Term: $academic_term
Grade Data: " . json_encode($grades) . "

Return this JSON format:
{
  \"status\": \"At Risk, Needs Monitoring, Good Standing, Improving, Declining, or No Data\",
  \"feedback\": \"academic analysis\",
  \"suggestion\": \"specific action plan without repeating the target grade calculation\"
}";

    $url = "https://generativelanguage.googleapis.com/v1beta/models/" . GEMINI_MODEL . ":generateContent?key=" . GEMINI_API_KEY;

    $payload = [
        "contents" => [[ "parts" => [[ "text" => $prompt ]] ]],
        "generationConfig" => [
            "temperature" => 0.3,
            "response_mime_type" => "application/json"
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        curl_close($ch);
        return fallback_ai_feedback($grades);
    }
    curl_close($ch);

    $data = json_decode($response, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $text = trim(str_replace(["```json", "```"], "", $text));
    $json = json_decode($text, true);

    if (!$json || !is_array($json)) {
        return fallback_ai_feedback($grades);
    }

    return [
        "status" => $json['status'] ?? 'Generated',
        "feedback" => $json['feedback'] ?? 'AI feedback generated.',
        "suggestion" => $json['suggestion'] ?? 'Continue monitoring student performance.'
    ];
}
?>