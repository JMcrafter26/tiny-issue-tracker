<?php

function fuzzySearch($query, $array) {
    $start_time = microtime(true); // Start the timer
    
    $results = [];
    $query = strtolower($query); // Convert query to lowercase
    $queryLength = strlen($query); // Pre-calculate the length of the query
    
    // Define a function to calculate match percentage using Levenshtein distance
    function getMatchPercentage($query, $item, $queryLength) {
        $itemLength = strlen($item); // Pre-calculate the length of the item
        $levDistance = levenshtein($query, $item);
        $maxLen = max($queryLength, $itemLength);
        if ($maxLen == 0) {
            return 1; // both strings are empty
        }
        return 1 * (1 - ($levDistance / $maxLen));
    }
    
    // Loop through each item in the array and calculate the match percentage
    foreach ($array as $item) {
        $lowerItem = strtolower($item); // Convert item to lowercase
        
        // Early exit for exact matches
        if ($query === $lowerItem) {
            $results[] = [
                'item' => $item,
                'match_percentage' => 1
            ];
            continue;
        }
        
        // Check if the item contains spaces
        if (strpos($lowerItem, ' ') !== false) {
            // Split the item into individual words
            $words = explode(' ', $lowerItem);
            $totalPercentage = 0;
            $wordCount = count($words);
            
            // Calculate the match percentage for each word and sum them up
            foreach ($words as $word) {
                $percentage = getMatchPercentage($query, $word, $queryLength);
                $totalPercentage += $percentage;
            }
            
            // Calculate the average match percentage for the item
            $averagePercentage = $totalPercentage / $wordCount;
            
            $results[] = [
                'item' => $item,
                'match_percentage' => $averagePercentage
            ];
        } else {
            // Calculate the match percentage for the item as usual
            $percentage = getMatchPercentage($query, $lowerItem, $queryLength);
            
            $results[] = [
                'item' => $item,
                'match_percentage' => $percentage
            ];
        }
    }
    
    // Sort the results by match percentage in descending order
    usort($results, function($a, $b) {
        return $b['match_percentage'] <=> $a['match_percentage'];
    });
    
    $end_time = microtime(true); // End the timer
    
    $execution_time = ($end_time - $start_time) * 1000000; // Convert to microseconds
    
    return [
        'results' => $results,
        'execution_time_microseconds' => $execution_time
    ];
}

// Example usage
$query = "kjhglkdhagkjhg";
$array = ["apple", "Pineapple", "banana", "grape", "aple", "Appl"];
$response = fuzzySearch($query, $array);

// Print the results
foreach ($response['results'] as $result) {
    echo "Item: " . $result['item'] . " - Match: " . $result['match_percentage'] . "\n";
}

echo "Execution Time: " . $response['execution_time_microseconds'] . " microseconds\n";
?>